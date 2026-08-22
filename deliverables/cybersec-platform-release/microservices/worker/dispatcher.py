"""Scan dispatcher — translates a job payload into a microservice HTTP call.

The dispatcher is the only component that knows which microservice handles
which ``tool``. It performs input validation and graceful degradation:
if the requested tool is unavailable, it returns a structured error and the
worker can still mark the job as failed without crashing.
"""

from __future__ import annotations

import logging
import os
from typing import Any, Dict, Optional

import requests

logger = logging.getLogger(__name__)


# Tool -> (microservice base URL env var, endpoint path).
TOOL_ROUTES: Dict[str, tuple[str, str]] = {
    "nmap": ("RECON_SERVICE_URL", "/scan/nmap"),
    "nuclei": ("RECON_SERVICE_URL", "/scan/nuclei"),
    "gobuster": ("RECON_SERVICE_URL", "/scan/gobuster"),
    "subfinder": ("RECON_SERVICE_URL", "/scan/subfinder"),
    "wpscan": ("RECON_SERVICE_URL", "/scan/wpscan"),
    "attack-detect": ("SECURITY_SERVICE_URL", "/detect"),
    "injection": ("SECURITY_SERVICE_URL", "/injection"),
    "waf-detect": ("SECURITY_SERVICE_URL", "/waf-detect"),
    "prevention-check": ("SECURITY_SERVICE_URL", "/prevention-check"),
    "whois": ("OSINT_SERVICE_URL", "/whois"),
    "dns": ("OSINT_SERVICE_URL", "/dns"),
    "ssl": ("OSINT_SERVICE_URL", "/ssl"),
    "subdomains": ("OSINT_SERVICE_URL", "/subdomains"),
    "tech-stack": ("OSINT_SERVICE_URL", "/tech-stack"),
    "passive": ("OSINT_SERVICE_URL", "/passive"),
    "ai-analyze": ("AI_SERVICE_URL", "/analyze"),
    "ai-remediation": ("AI_SERVICE_URL", "/remediation"),
    "ai-summary": ("AI_SERVICE_URL", "/summary"),
}


class ScanDispatcher:
    """Dispatch scan jobs to the appropriate microservice."""

    def __init__(self, timeout: int = 600) -> None:
        self.timeout = timeout

    # ------------------------------------------------------------------
    def dispatch(self, job: Dict[str, Any]) -> Dict[str, Any]:
        """Call the downstream microservice and return its response (or an error dict)."""
        tool = (job.get("tool") or "").strip().lower()
        if not tool:
            return {"error": "missing 'tool' in job payload", "http_status": 400}

        if tool not in TOOL_ROUTES:
            return {
                "error": f"unknown tool {tool!r}",
                "available": list(TOOL_ROUTES.keys()),
                "http_status": 404,
            }

        env_var, endpoint = TOOL_ROUTES[tool]
        base_url = os.environ.get(env_var)
        if not base_url:
            return {"error": f"{env_var} not configured", "http_status": 503}
        url = base_url.rstrip("/") + endpoint

        # Build the downstream payload.
        payload = self._build_payload(tool, job)
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "PFE-CyberSec/1.0 (worker)",
        }

        try:
            resp = requests.post(url, json=payload, headers=headers, timeout=self.timeout)
        except requests.ConnectionError as exc:
            return {"error": f"downstream unreachable: {exc}", "http_status": 502}
        except requests.Timeout:
            return {"error": "downstream timeout", "http_status": 504}
        except requests.RequestException as exc:
            return {"error": f"downstream error: {exc}", "http_status": 502}

        try:
            body = resp.json()
        except ValueError:
            body = {"raw": resp.text[:2000]}

        return {
            "http_status": resp.status_code,
            "tool": tool,
            "target": payload.get("target"),
            "result": body,
            "success": resp.status_code < 400,
        }

    # ------------------------------------------------------------------
    @staticmethod
    def _build_payload(tool: str, job: Dict[str, Any]) -> Dict[str, Any]:
        """Translate job fields into the downstream service's expected payload."""
        target = job.get("target")
        profile = job.get("profile", "balanced")
        config = job.get("config") or {}

        payload: Dict[str, Any] = {
            "target": target,
            "profile": profile,
            "config": config,
        }
        # Tool-specific extras.
        if tool == "injection":
            payload["type"] = job.get("injection_type", "full")
            if job.get("param"):
                payload["param"] = job["param"]
        elif tool in {"whois", "dns", "ssl", "subdomains", "tech-stack", "passive"}:
            # OSINT endpoints accept target only.
            pass
        elif tool == "ai-analyze":
            payload["tool"] = job.get("source_tool", "nmap")
            payload["raw_output"] = job.get("raw_output", "")
            payload["findings"] = job.get("findings", [])
        elif tool == "ai-remediation":
            payload["finding"] = job.get("finding", {})
        elif tool == "ai-summary":
            payload["findings"] = job.get("findings", [])
            payload["scan_date"] = job.get("scan_date")
        return payload


__all__ = ["ScanDispatcher", "TOOL_ROUTES"]
