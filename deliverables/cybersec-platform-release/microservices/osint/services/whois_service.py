"""WHOIS service — uses the python-whois library."""

from __future__ import annotations

import logging
from typing import Any, Dict

logger = logging.getLogger(__name__)


class WhoisService:
    """Look up WHOIS records for a domain."""

    def __init__(self, timeout: int = 15) -> None:
        self.timeout = timeout

    def lookup(self, domain: str) -> Dict[str, Any]:
        try:
            import whois  # type: ignore
        except ImportError:
            logger.warning("python-whois not installed; returning error")
            return {"domain": domain, "error": "python-whois library not available"}

        try:
            # python-whois exposes a WHOIS class with a configurable timeout.
            record = whois.whois(domain)
        except Exception as exc:  # noqa: BLE001
            logger.warning("whois lookup failed for %s: %s", domain, exc)
            return {"domain": domain, "error": str(exc)}

        if record is None:
            return {"domain": domain, "error": "no WHOIS record returned"}

        # Normalize dates to ISO format strings.
        def _norm(value: Any) -> Any:
            if hasattr(value, "isoformat"):
                return value.isoformat()
            if isinstance(value, list):
                return [_norm(v) for v in value]
            return value

        result: Dict[str, Any] = {"domain": domain}
        for key in (
            "registrar",
            "whois_server",
            "referral_url",
            "creation_date",
            "expiration_date",
            "updated_date",
            "name_servers",
            "status",
            "emails",
            "dnssec",
            "name",
            "org",
            "address",
            "city",
            "state",
            "zipcode",
            "country",
        ):
            if hasattr(record, key):
                value = getattr(record, key)
                if value is not None:
                    result[key] = _norm(value)
        return result


__all__ = ["WhoisService"]
