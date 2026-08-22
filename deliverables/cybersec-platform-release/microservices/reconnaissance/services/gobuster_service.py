"""Gobuster service — directory/file brute force using SecLists wordlist."""

from __future__ import annotations

import os
import shutil
from typing import Any, Dict, List

from .base_service import BaseScannerService, ScanProfile


class GobusterService(BaseScannerService):
    """Wrapper around ``gobuster`` directory brute-forcer."""

    tool_name = "gobuster"
    binary = "gobuster"

    # Default SecLists path inside the container — installed via Dockerfile.
    _DEFAULT_WORDLIST = "/usr/share/seclists/Discovery/Web-Content/common.txt"
    _DEFAULT_EXTENSIONS = "php,asp,aspx,jsp,html,js,txt,bak,old,zip"

    _PROFILE_RATE: Dict[str, int] = {
        "silent": 2,
        "balanced": 10,
        "aggressive": 30,
    }

    def __init__(self, config: Dict[str, Any] | None = None) -> None:
        super().__init__(config)
        binary = shutil.which("gobuster")
        if binary:
            self.binary = binary

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        # Gobuster requires a full URL; promote bare host to https://.
        if not target.startswith(("http://", "https://")):
            scheme = config.get("scheme", "https")
            url = f"{scheme}://{target}"
        else:
            url = target

        wordlist = config.get("wordlist", self._DEFAULT_WORDLIST)
        if not os.path.isfile(wordlist):
            # Fall back to a tiny built-in wordlist bundled with the image.
            fallback = "/usr/share/wordlists/dirb/common.txt"
            wordlist = fallback if os.path.isfile(fallback) else wordlist

        extensions = config.get("extensions", self._DEFAULT_EXTENSIONS)
        threads = self._PROFILE_RATE.get(profile.name, 10)
        # Caller-provided rate overrides profile default.
        if "rate_limit_qps" in config:
            threads = min(int(config["rate_limit_qps"]), 50)

        cmd: List[str] = [
            self.binary,
            "dir",
            "-u", url,
            "-w", wordlist,
            "-x", extensions,
            "-t", str(threads),
            "-q",                       # Quiet: no banner.
            "--no-error",               # Continue on connection errors.
            "-o", "-",                  # Output to stdout.
            "-s", "200,204,301,302,307,401,403",  # Interesting status codes.
            "--timeout", "10s",
        ]

        # Optional: HTTP basic auth, cookies, user-agent.
        if config.get("cookies"):
            cmd.extend(["-c", str(config["cookies"])])
        if config.get("user_agent"):
            cmd.extend(["-a", str(config["user_agent"])])
        if config.get("username") and config.get("password"):
            cmd.extend(["-U", str(config["username"]), "-P", str(config["password"])])

        return cmd


__all__ = ["GobusterService"]
