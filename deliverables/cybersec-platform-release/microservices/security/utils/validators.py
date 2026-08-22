"""Shared validation helpers for the security microservice.

Re-uses the strict target whitelist from the reconnaissance base service so
that every microservice refuses to pass unsafe values to subprocesses.
"""

from __future__ import annotations

import ipaddress
import re

_URL_RE = re.compile(
    r"^(?P<scheme>https?://)?(?P<host>[A-Za-z0-9.\-:]+)(?P<path>/[^\s]*)?$",
    re.IGNORECASE,
)
_DOMAIN_LABEL = r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
_DOMAIN_RE = re.compile(rf"^(?:{_DOMAIN_LABEL}\.)+[A-Za-z]{{2,}}$")
_IPV4_RE = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")
_SAFE_HOST_RE = re.compile(r"^[A-Za-z0-9.\-:/]+$")


def validate_target(target: str) -> str:
    """Validate a URL/host/IP target. Returns the cleaned target (URL form)."""
    if not target or not isinstance(target, str):
        raise ValueError("target must be a non-empty string")
    cleaned = target.strip().rstrip("/")
    match = _URL_RE.match(cleaned)
    if not match:
        raise ValueError(f"target does not match URL/host/IP whitelist: {target!r}")

    host = match.group("host")
    host_no_port = host.split(":", 1)[0] if ":" in host and not host.count(":") > 1 else host

    is_ipv6 = ":" in host and host.count(":") >= 2
    if is_ipv6:
        try:
            ipaddress.IPv6Address(host.strip("[]"))
        except ValueError as exc:
            raise ValueError(f"invalid IPv6 target: {target!r}") from exc
    elif _IPV4_RE.match(host_no_port):
        try:
            ipaddress.IPv4Address(host_no_port)
        except ValueError as exc:
            raise ValueError(f"invalid IPv4 target: {target!r}") from exc
    elif not _DOMAIN_RE.match(host_no_port):
        raise ValueError(f"target host failed regex whitelist: {host_no_port!r}")

    if not _SAFE_HOST_RE.match(host):
        raise ValueError(f"target contains disallowed characters: {host!r}")

    if any(ch in target for ch in (";", "|", "&", "`", "$", "(", ")", "{", "}", "<", ">", "\n", "\r", "\t")):
        raise ValueError(f"target contains shell metacharacters: {target!r}")

    # For HTTP-based tools, normalize to a URL with scheme.
    if not cleaned.lower().startswith(("http://", "https://")):
        cleaned = f"https://{cleaned}"
    return cleaned


__all__ = ["validate_target"]
