"""API Gateway — rate limiting, routing, CORS and downstream health checks.

Routing table:
    /api/recon/*     -> http://recon:5000
    /api/security/*  -> http://security:5001
    /api/osint/*     -> http://osint:5002
    /api/ai/*        -> http://ai:5003
    /api/*           -> http://laravel:8000

The gateway never re-implements business logic — it forwards the request
verbatim (method, headers, body, query string) and returns the downstream
response with rate-limit headers attached.
"""

from __future__ import annotations

import json
import logging
import logging.config
import os
import threading
import time
from datetime import datetime, timezone
from typing import Any, Dict, Tuple

import requests
from dotenv import load_dotenv
from flask import Flask, Response, jsonify, request

from rate_limiter import RateLimiter, RateLimitConfig  # type: ignore  # noqa: E402

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
logger = logging.getLogger("gateway.app")

app = Flask(__name__)

# Downstream service URLs (overridable via env).
DOWNSTREAM = {
    "recon": os.environ.get("RECON_SERVICE_URL", "http://recon:5000"),
    "security": os.environ.get("SECURITY_SERVICE_URL", "http://security:5001"),
    "osint": os.environ.get("OSINT_SERVICE_URL", "http://osint:5002"),
    "ai": os.environ.get("AI_SERVICE_URL", "http://ai:5003"),
    "laravel": os.environ.get("LARAVEL_URL", "http://laravel:8000"),
}

# Route prefix -> downstream service name.
ROUTE_TABLE = [
    ("/api/recon", "recon"),
    ("/api/security", "security"),
    ("/api/osint", "osint"),
    ("/api/ai", "ai"),
    ("/api", "laravel"),
]

# Hop-by-hop headers we must not forward (RFC 7230 §6.1).
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
}

# CORS configuration.
CORS_ORIGINS = os.environ.get("CORS_ORIGINS", "*").split(",")
CORS_METHODS = "GET, POST, PUT, PATCH, DELETE, OPTIONS"
CORS_HEADERS = "Content-Type, Authorization, X-Requested-With, X-Request-Id"

# Rate limiter instance (30 req/min + burst 10 — CDC).
rate_limiter = RateLimiter(RateLimitConfig(
    window_seconds=60,
    max_requests_per_window=30,
    burst_window_seconds=1,
    max_burst=10,
))

# Downstream health cache.
_health_cache: Dict[str, Dict[str, Any]] = {}
_health_lock = threading.Lock()
_health_last_refresh = 0.0


def _client_ip() -> str:
    # Trust X-Forwarded-For only if behind a known proxy (env-configurable).
    if os.environ.get("TRUST_PROXY", "false").lower() == "true":
        xff = request.headers.get("X-Forwarded-For")
        if xff:
            return xff.split(",")[0].strip()
    return request.remote_addr or "unknown"


def _cors_headers() -> Dict[str, str]:
    origin = request.headers.get("Origin", "")
    allowed = "*" if "*" in CORS_ORIGINS else (origin if origin in CORS_ORIGINS else "")
    return {
        "Access-Control-Allow-Origin": allowed,
        "Access-Control-Allow-Methods": CORS_METHODS,
        "Access-Control-Allow-Headers": CORS_HEADERS,
        "Access-Control-Max-Age": "600",
    }


@app.before_request
def enforce_rate_limit() -> Tuple[Response, int] | None:
    if request.method == "OPTIONS":
        return None  # Preflight handled in the route.
    ip = _client_ip()
    allowed, headers = rate_limiter.check(ip)
    if not allowed:
        resp = jsonify({
            "error": "rate limit exceeded",
            "retry_after": headers.get("Retry-After"),
            "reason": headers.get("X-RateLimit-Reason"),
        })
        for k, v in headers.items():
            resp.headers[k] = v
        for k, v in _cors_headers().items():
            resp.headers[k] = v
        return resp, 429
    # Stash headers for later attachment to the response.
    request._rate_headers = headers  # type: ignore[attr-defined]
    return None


@app.after_request
def attach_headers(resp: Response) -> Response:
    headers = getattr(request, "_rate_headers", None)
    if headers:
        for k, v in headers.items():
            resp.headers.setdefault(k, v)
    for k, v in _cors_headers().items():
        resp.headers.setdefault(k, v)
    return resp


@app.route("/<path:path>", methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"])
@app.route("/", methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"])
def proxy(path: str = "") -> Any:
    if request.method == "OPTIONS":
        # CORS preflight — return 204 with CORS headers.
        return ("", 204)

    full_path = request.full_path  # includes query string
    downstream_name, target_url, matched_prefix = _resolve_downstream(full_path)
    if not target_url:
        return jsonify({"error": f"no route for {request.path}"}), 404

    # Build the upstream URL by stripping the matched gateway prefix
    req_path = "/" + path.lstrip("/")
    if matched_prefix and req_path.startswith(matched_prefix):
        subpath = req_path[len(matched_prefix):].lstrip("/")
    else:
        subpath = path.lstrip("/")

    upstream = target_url.rstrip("/") + "/" + subpath
    if request.query_string:
        upstream += "?" + request.query_string.decode("utf-8", "replace")

    # Forward body + headers.
    body = request.get_data()
    fwd_headers = {
        k: v for k, v in request.headers.items()
        if k.lower() not in HOP_BY_HOP and k.lower() != "host"
    }
    fwd_headers["X-Forwarded-For"] = _client_ip()
    fwd_headers["X-Forwarded-Proto"] = request.scheme
    fwd_headers["X-Forwarded-Host"] = request.host

    try:
        resp = requests.request(
            method=request.method,
            url=upstream,
            headers=fwd_headers,
            data=body,
            timeout=int(os.environ.get("PROXY_TIMEOUT", "120")),
            allow_redirects=False,
            stream=True,
        )
    except requests.ConnectionError as exc:
        logger.warning("downstream %s unreachable: %s", downstream_name, exc)
        return jsonify({
            "error": f"downstream {downstream_name} unreachable",
            "detail": str(exc),
        }), 502
    except requests.Timeout:
        return jsonify({"error": f"downstream {downstream_name} timed out"}), 504

    excluded = HOP_BY_HOP | {"content-encoding", "content-length"}
    out_headers = {
        k: v for k, v in resp.headers.items()
        if k.lower() not in excluded
    }
    return Response(resp.content, status=resp.status_code, headers=out_headers)


def _resolve_downstream(full_path: str) -> Tuple[str | None, str | None, str | None]:
    """Match the request path against the route table."""
    # request.full_path looks like "/api/recon/scan?tool=nmap&..."
    path_only = full_path.split("?", 1)[0]
    for prefix, name in ROUTE_TABLE:
        if path_only == prefix.rstrip("/") or path_only.startswith(prefix + "/") or path_only == prefix:
            return name, DOWNSTREAM[name], prefix
    return None, None, None


@app.get("/health")
def health() -> Any:
    return jsonify({
        "status": "ok",
        "service": "api-gateway",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "downstream": DOWNSTREAM,
    })


@app.get("/health/all")
def health_all() -> Any:
    """Probe every downstream service concurrently and return aggregated status."""
    global _health_last_refresh
    now = time.monotonic()
    cache_ttl = int(os.environ.get("HEALTH_CACHE_TTL", "5"))
    if now - _health_last_refresh < cache_ttl:
        with _health_lock:
            return jsonify({"services": dict(_health_cache), "cached": True})

    results: Dict[str, Dict[str, Any]] = {}
    threads = []

    def probe(name: str, url: str) -> None:
        try:
            resp = requests.get(
                url.rstrip("/") + "/health",
                timeout=5,
            )
            results[name] = {
                "status": "up" if resp.status_code < 500 else "degraded",
                "http_status": resp.status_code,
                "url": url,
            }
        except requests.RequestException as exc:
            results[name] = {"status": "down", "error": str(exc), "url": url}

    for name, url in DOWNSTREAM.items():
        t = threading.Thread(target=probe, args=(name, url), daemon=True)
        t.start()
        threads.append(t)
    for t in threads:
        t.join(timeout=10)

    with _health_lock:
        _health_cache.clear()
        _health_cache.update(results)
        _health_last_refresh = now

    all_up = all(r.get("status") == "up" for r in results.values())
    return jsonify({
        "services": results,
        "overall": "ok" if all_up else "degraded",
        "cached": False,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


@app.errorhandler(404)
def not_found(err: Any) -> Any:
    return jsonify({"error": "not found", "path": request.path}), 404


@app.errorhandler(500)
def internal_error(err: Any) -> Any:
    logger.error("gateway internal error: %s", err)
    return jsonify({"error": "internal server error"}), 500


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    app.run(host="0.0.0.0", port=port, debug=False)
