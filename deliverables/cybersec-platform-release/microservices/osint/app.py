"""OSINT microservice — passive reconnaissance API.

Endpoints:
    GET  /health
    POST /passive
    POST /whois
    POST /dns
    POST /ssl
    POST /subdomains
    POST /tech-stack
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
    WhoisService,
    DnsService,
    SslService,
    CrtshService,
    TechDetector,
)
from services.validators import validate_domain  # noqa: E402

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
logger = logging.getLogger("osint.app")

app = Flask(__name__)
app.config["JSON_SORT_KEYS"] = False


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
        "service": "osint",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "modules": ["whois", "dns", "ssl", "crt.sh", "tech-stack"],
    })


@app.post("/whois")
def whois_endpoint() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)
    service = WhoisService()
    try:
        return jsonify(service.lookup(domain))
    except Exception as exc:  # noqa: BLE001
        logger.error("whois failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"whois failed: {exc}", 500)


@app.post("/dns")
def dns_endpoint() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)
    rtype = body.get("type")
    service = DnsService()
    try:
        if rtype:
            return jsonify(service.resolve(domain, rtype.upper()))
        return jsonify(service.resolve_all(domain))
    except Exception as exc:  # noqa: BLE001
        logger.error("dns failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"dns failed: {exc}", 500)


@app.post("/ssl")
def ssl_endpoint() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)
    port = int(body.get("port", 443))
    service = SslService()
    try:
        return jsonify(service.inspect(domain, port))
    except Exception as exc:  # noqa: BLE001
        logger.error("ssl failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"ssl failed: {exc}", 500)


@app.post("/subdomains")
def subdomains_endpoint() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)
    service = CrtshService()
    try:
        return jsonify(service.enumerate(domain))
    except Exception as exc:  # noqa: BLE001
        logger.error("subdomains failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"subdomains failed: {exc}", 500)


@app.post("/tech-stack")
def tech_stack_endpoint() -> Any:
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    # Tech detector wants a URL.
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)
    url = target if target.lower().startswith(("http://", "https://")) else f"https://{domain}"
    service = TechDetector()
    try:
        return jsonify(service.detect(url))
    except Exception as exc:  # noqa: BLE001
        logger.error("tech-stack failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"tech-stack failed: {exc}", 500)


@app.post("/passive")
def passive_endpoint() -> Any:
    """Run all OSINT modules in sequence against the target."""
    body = _json_body()
    target = body.get("target")
    if not target:
        return _error("missing 'target'", 400)
    try:
        domain = validate_domain(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    whois_svc = WhoisService()
    dns_svc = DnsService()
    ssl_svc = SslService()
    crtsh_svc = CrtshService()
    tech_svc = TechDetector()

    url = target if target.lower().startswith(("http://", "https://")) else f"https://{domain}"

    # Each module is wrapped so one failure does not break the others (CDC:
    # graceful degradation).
    def _safe(name: str, fn):
        try:
            return name, fn()
        except Exception as exc:  # noqa: BLE001
            logger.warning("passive module %s failed: %s", name, exc)
            return name, {"error": str(exc)}

    results = dict([
        _safe("whois", lambda: whois_svc.lookup(domain)),
        _safe("dns", lambda: dns_svc.resolve_all(domain)),
        _safe("ssl", lambda: ssl_svc.inspect(domain, 443)),
        _safe("subdomains", lambda: crtsh_svc.enumerate(domain)),
        _safe("tech_stack", lambda: tech_svc.detect(url)),
    ])
    return jsonify({
        "target": domain,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "modules": list(results.keys()),
        "results": results,
    })


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
    port = int(os.environ.get("PORT", "5002"))
    app.run(host="0.0.0.0", port=port, debug=False)
