"""SSL/TLS certificate inspection using ssl + socket."""

from __future__ import annotations

import logging
import socket
import ssl
from datetime import datetime, timezone
from typing import Any, Dict, Optional

logger = logging.getLogger(__name__)


class SslService:
    """Fetch and parse a TLS certificate from a host:port endpoint."""

    def __init__(self, timeout: int = 10) -> None:
        self.timeout = timeout

    def inspect(self, host: str, port: int = 443) -> Dict[str, Any]:
        # Strip brackets from IPv6 literals for socket API.
        clean_host = host.strip("[]")
        context = ssl.create_default_context()
        # We accept self-signed for inspection purposes (still parse the cert).
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

        try:
            with socket.create_connection((clean_host, port), timeout=self.timeout) as sock:
                with context.wrap_socket(sock, server_hostname=clean_host) as ssock:
                    cert_bin = ssock.getpeercert(binary_form=True)
                    cert_dict = ssock.getpeercert()
                    cipher = ssock.cipher()
                    version = ssock.version()
        except socket.timeout:
            return {"host": host, "port": port, "error": "connection timed out"}
        except ConnectionRefusedError:
            return {"host": host, "port": port, "error": "connection refused"}
        except ssl.SSLError as exc:
            return {"host": host, "port": port, "error": f"SSL error: {exc}"}
        except OSError as exc:
            return {"host": host, "port": port, "error": str(exc)}

        if not cert_bin:
            return {"host": host, "port": port, "error": "no certificate presented"}

        result: Dict[str, Any] = {
            "host": host,
            "port": port,
            "tls_version": version,
            "cipher": {
                "name": cipher[0] if cipher else None,
                "version": cipher[1] if cipher else None,
                "secret_bits": cipher[2] if cipher else None,
            },
        }

        # Parse the certificate dict.
        if cert_dict:
            result["subject"] = self._flatten_rdn(cert_dict.get("subject", []))
            result["issuer"] = self._flatten_rdn(cert_dict.get("issuer", []))
            result["version"] = cert_dict.get("version")
            result["serial_number"] = cert_dict.get("serialNumber")
            result["not_before"] = cert_dict.get("notBefore")
            result["not_after"] = cert_dict.get("notAfter")
            result["subject_alt_names"] = [
                san[1] for san in cert_dict.get("subjectAltName", []) if isinstance(san, tuple) and len(san) >= 2
            ]
            result["expired"] = self._is_expired(cert_dict.get("notAfter"))
            result["days_until_expiry"] = self._days_until(cert_dict.get("notAfter"))

        # Also dump the raw DER as hex (truncated) for downstream verification.
        result["cert_sha1_fingerprint"] = self._fingerprint(cert_bin, "sha1")
        result["cert_sha256_fingerprint"] = self._fingerprint(cert_bin, "sha256")
        return result

    # ------------------------------------------------------------------
    @staticmethod
    def _flatten_rdn(rdn_sequence) -> Dict[str, str]:
        out: Dict[str, str] = {}
        for rdn in rdn_sequence or []:
            for key, value in rdn:
                out[key] = value
        return out

    @staticmethod
    def _is_expired(not_after: Optional[str]) -> Optional[bool]:
        if not not_after:
            return None
        try:
            expiry = datetime.strptime(not_after, "%b %d %H:%M:%S %Y %Z")
            return expiry < datetime.utcnow()
        except ValueError:
            return None

    @staticmethod
    def _days_until(not_after: Optional[str]) -> Optional[int]:
        if not not_after:
            return None
        try:
            expiry = datetime.strptime(not_after, "%b %d %H:%M:%S %Y %Z")
            delta = expiry - datetime.utcnow()
            return delta.days
        except ValueError:
            return None

    @staticmethod
    def _fingerprint(cert_bin: bytes, algorithm: str) -> str:
        import hashlib
        h = hashlib.new(algorithm)
        h.update(cert_bin)
        return h.hexdigest()


__all__ = ["SslService"]
