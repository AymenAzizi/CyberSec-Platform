"""DNS service — uses dnspython for record resolution."""

from __future__ import annotations

import logging
from typing import Any, Dict, List

logger = logging.getLogger(__name__)

RECORD_TYPES: List[str] = ["A", "AAAA", "MX", "NS", "TXT", "SOA", "CNAME"]


class DnsService:
    """Resolve standard DNS record types for a domain."""

    def __init__(self, timeout: float = 5.0, nameservers: List[str] | None = None) -> None:
        self.timeout = timeout
        self.nameservers = nameservers

    def resolve_all(self, domain: str) -> Dict[str, Any]:
        records: Dict[str, Any] = {"domain": domain, "records": {}}
        for rtype in RECORD_TYPES:
            records["records"][rtype] = self.resolve(domain, rtype)
        return records

    def resolve(self, domain: str, rtype: str = "A") -> Dict[str, Any]:
        try:
            import dns.resolver  # type: ignore
            import dns.exception  # type: ignore
        except ImportError:
            logger.warning("dnspython not installed")
            return {"type": rtype, "error": "dnspython library not available"}

        resolver = dns.resolver.Resolver()
        resolver.lifetime = self.timeout
        resolver.timeout = self.timeout
        if self.nameservers:
            resolver.nameservers = self.nameservers

        try:
            answer = resolver.resolve(domain, rtype)
        except dns.resolver.NoAnswer:
            return {"type": rtype, "values": []}
        except dns.resolver.NXDOMAIN:
            return {"type": rtype, "error": "NXDOMAIN", "values": []}
        except dns.resolver.NoNameservers as exc:
            return {"type": rtype, "error": f"no nameservers: {exc}", "values": []}
        except dns.exception.Timeout:
            return {"type": rtype, "error": "timeout", "values": []}
        except Exception as exc:  # noqa: BLE001
            return {"type": rtype, "error": str(exc), "values": []}

        values: List[str] = []
        for rdata in answer:
            try:
                # MX records have a preference + exchange.
                if rtype == "MX":
                    values.append(f"{rdata.preference} {rdata.exchange}")
                elif rtype == "SOA":
                    values.append({
                        "mname": str(rdata.mname),
                        "rname": str(rdata.rname),
                        "serial": rdata.serial,
                        "refresh": rdata.refresh,
                        "retry": rdata.retry,
                        "expire": rdata.expire,
                        "minimum": rdata.minimum,
                    })
                else:
                    values.append(str(rdata))
            except Exception as exc:  # noqa: BLE001
                values.append(f"<unparseable: {exc}>")
        return {"type": rtype, "values": values, "ttl": getattr(answer, "rrset", None) and answer.rrset.ttl}


__all__ = ["DnsService", "RECORD_TYPES"]
