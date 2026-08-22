"""crt.sh service — query the public certificate transparency log for subdomains."""

from __future__ import annotations

import json
import logging
from typing import Any, Dict, List

import requests

logger = logging.getLogger(__name__)

CRTSH_URL = "https://crt.sh/?q=%25.{domain}&output=json"


class CrtshService:
    """Enumerate subdomains via the crt.sh CT log API."""

    def __init__(self, timeout: int = 30) -> None:
        self.timeout = timeout

    def enumerate(self, domain: str) -> Dict[str, Any]:
        url = CRTSH_URL.format(domain=domain)
        try:
            resp = requests.get(
                url,
                headers={"User-Agent": "PFE-CyberSec/1.0 (osint)"},
                timeout=self.timeout,
            )
            resp.raise_for_status()
        except requests.Timeout:
            return {"domain": domain, "error": "crt.sh request timed out", "subdomains": []}
        except requests.RequestException as exc:
            return {"domain": domain, "error": str(exc), "subdomains": []}

        # crt.sh may return JSON array or HTML on rate-limit.
        try:
            data = resp.json()
        except json.JSONDecodeError:
            return {
                "domain": domain,
                "error": "crt.sh returned non-JSON response (likely rate-limited)",
                "subdomains": [],
            }

        subdomains: set[str] = set()
        certificates: List[Dict[str, Any]] = []
        for entry in data:
            if not isinstance(entry, dict):
                continue
            name_value = entry.get("name_value", "")
            # name_value may contain multiple newline-separated names.
            for line in name_value.splitlines():
                line = line.strip().lower()
                if not line or "*" in line:
                    continue
                if line.endswith(domain.lower()):
                    subdomains.add(line)
            certificates.append({
                "id": entry.get("id"),
                "not_before": entry.get("not_before"),
                "not_after": entry.get("not_after"),
                "issuer_name": entry.get("issuer_name"),
                "common_name": entry.get("common_name"),
            })

        return {
            "domain": domain,
            "subdomains": sorted(subdomains),
            "count": len(subdomains),
            "certificates": certificates[:100],  # Cap output size.
        }


__all__ = ["CrtshService", "CRTSH_URL"]
