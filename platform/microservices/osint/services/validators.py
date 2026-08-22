"""OSINT validators — re-uses the strict domain/IP whitelist."""

from __future__ import annotations

import ipaddress
import re

_DOMAIN_LABEL = r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
_DOMAIN_RE = re.compile(rf"^(?:{_DOMAIN_LABEL}\.)+[A-Za-z]{{2,}}$")
_IPV4_RE = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")
_HOST_RE = re.compile(r"^[A-Za-z0-9.\-]+$")


def validate_domain(target: str) -> str:
    """Validate a domain or IP target. Returns the bare host (no scheme/port)."""
    if not target or not isinstance(target, str):
        raise ValueError("target must be a non-empty string")
    cleaned = target.strip().rstrip("/")
    if "://" in cleaned:
        cleaned = cleaned.split("://", 1)[1]
    cleaned = cleaned.split("/", 1)[0]
    # Strip port.
    if ":" in cleaned and cleaned.count(":") == 1:
        cleaned = cleaned.split(":", 1)[0]
    elif cleaned.startswith("[") and "]" in cleaned:
        cleaned = cleaned[1:cleaned.index("]")]
    # Validate.
    if _IPV4_RE.match(cleaned):
        try:
            ipaddress.IPv4Address(cleaned)
        except ValueError as exc:
            raise ValueError(f"invalid IPv4 target: {target!r}") from exc
    elif ":" in cleaned:
        try:
            ipaddress.IPv6Address(cleaned)
        except ValueError as exc:
            raise ValueError(f"invalid IPv6 target: {target!r}") from exc
    elif not _DOMAIN_RE.match(cleaned):
        raise ValueError(f"target host failed regex whitelist: {cleaned!r}")
    if not _HOST_RE.match(cleaned):
        raise ValueError(f"target contains disallowed characters: {cleaned!r}")
    if any(ch in cleaned for ch in (" ", ";", "|", "&", "`", "$", "(", ")", "{", "}", "<", ">", "\n", "\r", "\t")):
        raise ValueError(f"target contains shell metacharacters: {cleaned!r}")
    return cleaned


__all__ = ["validate_domain"]
