"""Reconnaissance Flask microservice.

Endpoints:
    GET  /health
    GET  /tools
    POST /scan
    POST /scan/<tool>
    POST /analyze
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

# Ensure local imports work when run via gunicorn (cwd = service dir).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from services import (  # noqa: E402
    BaseScannerService,
    NmapService,
    NucleiService,
    GobusterService,
    SubfinderService,
    WpscanService,
)
from services.base_service import PROFILES, validate_target  # noqa: E402
from utils.ai_analyzer import AIAnalyzer  # noqa: E402
from utils.graph_builder import GraphBuilder  # noqa: E402
from utils.result_parser import ResultParser  # noqa: E402

load_dotenv()

# ---------------------------------------------------------------------------
# Logging — structured JSON to stdout (gunicorn friendly).
# ---------------------------------------------------------------------------
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
    "loggers": {
        "recon": {"level": "INFO", "handlers": ["stdout"], "propagate": False},
    },
}
logging.config.dictConfig(LOG_CONFIG)
logger = logging.getLogger("recon.app")


# ---------------------------------------------------------------------------
# Flask app
# ---------------------------------------------------------------------------
app = Flask(__name__)


def _service_registry() -> Dict[str, BaseScannerService]:
    return {
        "nmap": NmapService(),
        "nuclei": NucleiService(),
        "gobuster": GobusterService(),
        "subfinder": SubfinderService(),
        "wpscan": WpscanService(),
    }


SERVICES = _service_registry()

TOOL_DESCRIPTIONS = {
    "nmap": "Network port scanner with service and OS detection.",
    "nuclei": "Template-based vulnerability scanner (ProjectDiscovery).",
    "gobuster": "Directory/file brute-forcer using SecLists wordlists.",
    "subfinder": "Passive subdomain enumeration from public sources.",
    "wpscan": "WordPress vulnerability and enumeration scanner.",
}


def _error(message: str, status: int = 400, extra: Dict[str, Any] | None = None) -> Any:
    payload = {"error": message, "status": status}
    if extra:
        payload.update(extra)
    return jsonify(payload), status


def _json_body() -> Dict[str, Any]:
    """Safely parse the request body as JSON, returning {} on failure."""
    if not request.is_json:
        try:
            return json.loads(request.get_data(as_text=True) or "{}")
        except (ValueError, TypeError):
            return {}
    return request.get_json(silent=True) or {}


@app.get("/health")
def health() -> Any:
    available = {
        name: bool(svc.binary and shutil_which(svc.binary))
        for name, svc in SERVICES.items()
    }
    return jsonify({
        "status": "ok",
        "service": "reconnaissance",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "model": os.environ.get("OLLAMA_MODEL", AIAnalyzer.DEFAULT_MODEL),
        "profiles": {k: {"rate_qps": v.rate_limit_qps, "timeout_s": v.timeout_seconds} for k, v in PROFILES.items()},
        "tools": available,
    })


def shutil_which(binary: str) -> bool:
    import shutil
    try:
        return shutil.which(binary) is not None
    except Exception:  # noqa: BLE001
        return False


@app.get("/tools")
def list_tools() -> Any:
    return jsonify({
        "tools": [
            {"name": name, "description": desc, "binary": SERVICES[name].binary}
            for name, desc in TOOL_DESCRIPTIONS.items()
        ]
    })


def _run_tool(tool: str, body: Dict[str, Any]) -> Any:
    if tool not in SERVICES:
        return _error(f"unknown tool {tool!r}", 404, {"available": list(SERVICES.keys())})

    target = body.get("target")
    if not target:
        return _error("missing 'target' in request body", 400)

    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    profile = body.get("profile", "balanced")
    config = body.get("config") or {}
    # Top-level overrides for jitter/rate_limit propagate into the profile.
    if "jitter_ms" in body:
        config["jitter_ms"] = int(body["jitter_ms"])
    if "rate_limit_qps" in body:
        config["rate_limit_qps"] = int(body["rate_limit_qps"])

    service = SERVICES[tool]
    try:
        result = service.scan(target=validated, profile=profile, config=config)
    except ValueError as exc:
        return _error(str(exc), 400)
    except Exception as exc:  # noqa: BLE001
        logger.error("scan failed: %s\n%s", exc, traceback.format_exc())
        return _error(f"scan failed: {exc}", 500)

    parser = ResultParser()
    findings = parser.parse(tool, result.stdout)
    graph = GraphBuilder().build_from_findings(findings, validated)
    propagation = GraphBuilder().compute_impact_propagation(graph)

    return jsonify({
        "tool": tool,
        "target": validated,
        "profile": profile,
        "returncode": result.returncode,
        "duration_seconds": round(result.duration_seconds, 3),
        "command": result.command,
        "stdout": result.stdout,
        "stderr": result.stderr,
        "error": result.error,
        "findings": findings,
        "graph": GraphBuilder().to_cytoscape(graph),
        "graph_serialized": GraphBuilder().serialize(graph),
        "impact_propagation": propagation,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


@app.post("/scan")
def scan_unified() -> Any:
    body = _json_body()
    tool = body.get("tool")
    if not tool:
        return _error("missing 'tool' in request body", 400)
    return _run_tool(tool, body)


@app.post("/scan/<tool>")
def scan_tool(tool: str) -> Any:
    return _run_tool(tool, _json_body())


@app.post("/analyze")
def analyze() -> Any:
    body = _json_body()
    tool = body.get("tool")
    target = body.get("target")
    raw_output = body.get("raw_output")
    findings = body.get("findings") or []
    if not (tool and target and raw_output is not None):
        return _error("required fields: tool, target, raw_output", 400)

    try:
        validated = validate_target(target)
    except ValueError as exc:
        return _error(str(exc), 400)

    analyzer = AIAnalyzer()
    result = analyzer.analyze(tool=tool, target=validated, raw_output=raw_output, findings=findings)
    return jsonify({
        "tool": tool,
        "target": validated,
        "analysis": result,
        "timestamp": datetime.now(timezone.utc).isoformat(),
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
    port = int(os.environ.get("PORT", "5000"))
    app.run(host="0.0.0.0", port=port, debug=False)
