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

    import concurrent.futures

    tasks = {
        "whois": lambda: whois_svc.lookup(domain),
        "dns": lambda: dns_svc.resolve_all(domain),
        "ssl": lambda: ssl_svc.inspect(domain, 443),
        "subdomains": lambda: crtsh_svc.enumerate(domain),
        "tech_stack": lambda: tech_svc.detect(url),
    }

    results = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
        future_to_name = {executor.submit(fn): name for name, fn in tasks.items()}
        for future in concurrent.futures.as_completed(future_to_name):
            name = future_to_name[future]
            try:
                results[name] = future.result()
            except Exception as exc:  # noqa: BLE001
                logger.warning("passive module %s failed: %s", name, exc)
    findings = _normalize_osint_findings(domain, results)

    return jsonify({
        "status": "completed",
        "target": domain,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "modules": list(tasks.keys()),
        "findings": findings,
        "results": results,
    })


def _normalize_osint_findings(domain: str, results: Dict[str, Any]) -> List[Dict[str, Any]]:
    findings: List[Dict[str, Any]] = []

    # 1. Tech Stack
    tech = results.get("tech_stack") or {}
    technologies = tech.get("technologies") or []
    if technologies:
        tech_list_str = ", ".join(technologies)
        findings.append({
            "title": f"Technologies Detected: {tech_list_str}",
            "severity": "info",
            "description": f"Passive fingerprinting identified software components on {domain}: {tech_list_str}.",
            "evidence": json.dumps(tech.get("headers") or {}, indent=2)[:500],
            "endpoint": tech.get("target") or f"https://{domain}",
            "source_tool": "osint",
            "cve_id": None,
            "citations": [],
        })

        if any("wordpress" in str(t).lower() for t in technologies):
            findings.append({
                "title": "WordPress CMS Identified",
                "severity": "low",
                "description": f"Target {domain} is running WordPress CMS. REST API endpoints and plugins may be enumerated.",
                "evidence": str(tech.get("headers", {}).get("Link", "WordPress REST API Header Detected")),
                "endpoint": f"https://{domain}/wp-json/",
                "source_tool": "osint",
                "cve_id": None,
                "citations": [],
            })

    # 2. SSL/TLS Configuration
    ssl_data = results.get("ssl") or {}
    if ssl_data and "cipher" in ssl_data and not ssl_data.get("error"):
        cipher = ssl_data.get("cipher") or {}
        tls_ver = ssl_data.get("tls_version") or "TLS"
        findings.append({
            "title": f"SSL/TLS Configuration: {tls_ver} ({cipher.get('name', 'Active')})",
            "severity": "info",
            "description": f"Host {domain} exposes HTTPS on port {ssl_data.get('port', 443)} with SHA-256 fingerprint {str(ssl_data.get('cert_sha256_fingerprint', ''))[:24]}...",
            "evidence": f"Cipher: {cipher.get('name')} | Bits: {cipher.get('secret_bits')} | Protocol: {tls_ver}",
            "endpoint": f"https://{domain}:{ssl_data.get('port', 443)}",
            "source_tool": "osint",
            "cve_id": None,
            "citations": [],
        })

    # 3. DNS Infrastructure
    dns_data = results.get("dns") or {}
    records = dns_data.get("records") or {}
    if records:
        a_records = records.get("A", {}).get("values", [])
        mx_records = records.get("MX", {}).get("values", [])
        ns_records = records.get("NS", {}).get("values", [])
        evidence_parts = []
        if a_records:
            evidence_parts.append(f"A: {', '.join(a_records)}")
        if mx_records:
            evidence_parts.append(f"MX: {', '.join(mx_records)}")
        if ns_records:
            evidence_parts.append(f"NS: {', '.join(ns_records)}")

        findings.append({
            "title": f"DNS Infrastructure & Records ({len(a_records)} IP, {len(mx_records)} MX)",
            "severity": "info",
            "description": f"DNS mapping for {domain}. Primary A records point to {', '.join(a_records)}.",
            "evidence": " | ".join(evidence_parts),
            "endpoint": a_records[0] if a_records else domain,
            "source_tool": "osint",
            "cve_id": None,
            "citations": [],
        })

    # 4. WHOIS Registration
    whois_data = results.get("whois") or {}
    if whois_data and whois_data.get("registrar") and not whois_data.get("error"):
        findings.append({
            "title": f"WHOIS Registration: Registrar {whois_data.get('registrar')}",
            "severity": "info",
            "description": f"Domain {domain} registered with {whois_data.get('registrar')} since {whois_data.get('creation_date', 'N/A')}. Status: {whois_data.get('status', 'Active')}.",
            "evidence": f"Registrar: {whois_data.get('registrar')} | Nameservers: {', '.join(whois_data.get('name_servers') or [])}",
            "endpoint": domain,
            "source_tool": "osint",
            "cve_id": None,
            "citations": [],
        })

    # 5. Discovered Subdomains
    sub_data = results.get("subdomains") or {}
    subdomains = sub_data.get("subdomains") or []
    if isinstance(subdomains, list) and subdomains:
        for sub in subdomains[:20]:
            sub_name = sub if isinstance(sub, str) else sub.get("name", "")
            if sub_name:
                findings.append({
                    "title": f"Passively Discovered Subdomain: {sub_name}",
                    "severity": "info",
                    "description": f"Certificate Transparency log reconnaissance identified active subdomain {sub_name}.",
                    "evidence": f"crt.sh CT Log entry for {domain}",
                    "endpoint": f"https://{sub_name}",
                    "source_tool": "osint",
                    "cve_id": None,
                    "citations": [],
                })

    return findings


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
    app.run(host="0.0.0.0", port=port, debug=False)  # nosec B104
