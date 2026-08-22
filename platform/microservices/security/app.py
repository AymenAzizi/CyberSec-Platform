"""Security testing Flask microservice.

Endpoints:
    GET  /health
    POST /detect
    POST /injection
    POST /waf-detect
    POST /prevention-check
    GET  /monitoring/stats
    GET  /monitoring/events
    POST /sandbox/test
"""

from __future__ import annotations

import json
import logging
import logging.config
import os
import sys
import traceback
from datetime import datetime, timezone
from typing import Any, Dict

from dotenv import load_dotenv
from flask import Flask, jsonify, request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from services import (  # noqa: E402
    AttackDetector,
    InjectionTester,
    PreventionEngine,
    MonitoringService,
    DockerSandbox,
)
from utils.validators import validate_target  # noqa: E402

load_dotenv()

LOG_CONFIG = {
    "version": 1,
    "disable_existing_loggers": False,
    "formatters": {
        "json": {
            "format": '{"ts":"%(asctime)s","level":"%(levelname)s","logger":"%(name)s","msg":%(message)s}',
        }
    },
    "handlers": {
        "stdout": {
            "class": "logging.StreamHandler",
            "stream": "ext://sys.stdout",
            "formatter": "json",
        }
    },
    "root": {"handlers": ["stdout"], "level": os.environ.get("LOG_LEVEL", "INFO")},
}
logging.config.dictConfig(LOG_CONFIG)
logger = logging.getLogger("security.app")

app = Flask(__name__)
app.config["JSON_SORT_KEYS"] = False

# Shared service instances.
monitoring = MonitoringService()
sandbox = DockerSandbox()


def _error(message: str, status: int = 400, extra: Dict[str, Any] | None = None) -> Any:
    payload = {"error": message, "status": status}
    if extra:
        payload.update(extra)
    return jsonify(payload), status


def _json_body() -> Dict[str, Any]:
    if not request.is_json:
        try:
            return json.loads(request.get_data(as_text=True) or "{}")
        except (ValueError, TypeError):
            return {}
    return request.get_json(silent=True) or {}


@app.get("/health")
def health() -> Any:
    return jsonify({
        "status": "ok",
        "service": "security",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "sandbox_available": sandbox.available,
        "supported_apps": [a["name"] for a in sandbox.list_supported()],
    })


@app.post("/detect")
def detect() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    profile = body.get("profile", "balanced")
    detector = AttackDetector()
    try:
        report = detector.detect(validated, profile=profile)
    except Exception as exc:  # noqa: BLE001
        logger.error("detect failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"detect failed: {exc}", 500)

    monitoring.log_event(
        event_type="attack-detection",
        target=validated,
        severity="high" if report.get("risk_score", 0) >= 50 else "medium",
        details={"risk_score": report.get("risk_score"), "risk_factors": report.get("risk_factors")},
    )
    return jsonify(report)


@app.post("/injection")
def injection() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    itype = body.get("type", "full")
    profile = body.get("profile", "balanced")
    param = body.get("param")

    tester = InjectionTester()
    try:
        result = tester.test(injection_type=itype, target=validated, profile=profile, param=param)
    except ValueError as exc:
        return _error(str(exc), 400)
    except Exception as exc:  # noqa: BLE001
        logger.error("injection test failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"injection test failed: {exc}", 500)

    severity = "critical" if result["is_vulnerable"] and itype in {"cmd", "full"} else (
        "high" if result["is_vulnerable"] else "info"
    )
    monitoring.log_event(
        event_type="injection-test",
        target=validated,
        severity=severity,
        details={
            "type": result["type"],
            "vulnerable": result["is_vulnerable"],
            "vulnerable_payloads": result["vulnerable_payloads"],
        },
    )
    return jsonify(result)


@app.post("/waf-detect")
def waf_detect() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    engine = PreventionEngine()
    try:
        result = engine.detect_waf(validated)
    except Exception as exc:  # noqa: BLE001
        logger.error("waf detect failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"waf detect failed: {exc}", 500)

    monitoring.log_event(
        event_type="waf-detection",
        target=validated,
        severity="info" if result.get("waf_detected") else "medium",
        details={"vendors": result.get("waf_vendors")},
    )
    return jsonify(result)


@app.post("/prevention-check")
def prevention_check() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    # Optionally run attack detection first to feed into the posture check.
    detection_report = body.get("detection_report")
    if not detection_report:
        detector = AttackDetector()
        try:
            detection_report = detector.detect(validated)
        except Exception as exc:  # noqa: BLE001
            logger.warning("attack detection failed: %s", exc)
            detection_report = {}

    engine = PreventionEngine()
    try:
        result = engine.posture_check(validated, detection_report)
    except Exception as exc:  # noqa: BLE001
        logger.error("posture check failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"posture check failed: {exc}", 500)

    monitoring.log_event(
        event_type="prevention-check",
        target=validated,
        severity="high" if result.get("risk_score", 0) >= 70 else "medium",
        details={"posture": result.get("posture"), "risk_score": result.get("risk_score")},
    )
    return jsonify(result)


@app.get("/monitoring/stats")
def monitoring_stats() -> Any:
    return jsonify(monitoring.stats())


@app.get("/monitoring/events")
def monitoring_events() -> Any:
    limit = request.args.get("limit", default=100, type=int)
    severity = request.args.get("severity")
    events = monitoring.recent_events(limit=limit, severity=severity)
    return jsonify({"events": events, "count": len(events)})


@app.post("/sandbox/test")
def sandbox_test() -> Any:
    body = _json_body()
    action = body.get("action", "start")
    if action == "start":
        app_name = body.get("target_app") or body.get("app")
        if not app_name:
            return _error("missing 'target_app'", 400)
        port = body.get("port")
        result = sandbox.start(app_name, port=port)
        monitoring.log_event(
            event_type="sandbox-start",
            target=app_name,
            severity="info",
            details=result,
        )
        return jsonify(result)
    if action == "stop":
        app_name = body.get("target_app") or body.get("app")
        if not app_name:
            return _error("missing 'target_app'", 400)
        result = sandbox.stop(app_name)
        return jsonify(result)
    if action == "status":
        return jsonify(sandbox.status())
    return _error(f"unknown action {action!r}; allowed: start, stop, status", 400)


@app.errorhandler(404)
def not_found(err: Any) -> Any:
    return _error("not found", 404, {"path": request.path})


@app.errorhandler(405)
def method_not_allowed(err: Any) -> Any:
    return _error("method not allowed", 405, {"method": request.method, "path": request.path})


@app.errorhandler(500)
def internal_error(err: Any) -> Any:
    logger.error("internal server error: %s", err)
    return _error("internal server error", 500)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "5001"))
    app.run(host="0.0.0.0", port=port, debug=False)
