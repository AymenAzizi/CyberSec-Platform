"""WPScan service — WordPress vulnerability scanner."""

from __future__ import annotations

import os
import shutil
from typing import Any, Dict, List

from .base_service import BaseScannerService, ScanProfile


class WpscanService(BaseScannerService):
    """Wrapper around ``wpscan`` for WordPress enumeration."""

    tool_name = "wpscan"
    binary = "wpscan"

    _PROFILE_RATE: Dict[str, int] = {
        "silent": 2,
        "balanced": 8,
        "aggressive": 25,
    }

    def __init__(self, config: Dict[str, Any] | None = None) -> None:
        super().__init__(config)
        binary = shutil.which("wpscan")
        if binary:
            self.binary = binary

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        # WPScan requires a URL.
        if not target.startswith(("http://", "https://")):
            scheme = config.get("scheme", "https")
            url = f"{scheme}://{target}"
        else:
            url = target

        rate = self._PROFILE_RATE.get(profile.name, 8)
        if "rate_limit_qps" in config:
            rate = int(config["rate_limit_qps"])

        cmd: List[str] = [
            self.binary,
            "--url", url,
            "--format", "json",
            "--output", "-",                # Emit JSON to stdout.
            "--throttle", str(rate),        # Requests/second cap.
            "--disable-tls-checks",         # Internal scans may use self-signed.
            "--random-user-agent",
            "--force",                      # Don't bail if not detected as WP.
        ]

        # Enumeration scope — default to plugins + themes + timthumbs.
        enum_scope = config.get("enumerate", "ap,at,t")
        cmd.extend(["--enumerate", str(enum_scope)])

        # Optional API token for vulnerability DB lookups.
        api_token = config.get("api_token") or os.environ.get("WPSCAN_API_TOKEN")
        if api_token:
            cmd.extend(["--api-token", str(api_token)])

        # Aggressive: enable password brute-force (only against authorized apps).
        if profile.name == "aggressive" and config.get("password_brute", False):
            cmd.extend(["--passwords", str(config.get("passwords_file", "/usr/share/wordlists/rockyou.txt"))])

        return cmd


__all__ = ["WpscanService"]
