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

    # Default wordlist path inside the container.
    _DEFAULT_WORDLIST = "/app/wordlists/common.txt"
    _DEFAULT_EXTENSIONS = "php,html,txt,js"

    _PROFILE_RATE: Dict[str, int] = {
        "silent": 5,
        "balanced": 30,
        "aggressive": 60,
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
        # Gobuster requires a full URL; promote bare host to http:// (follows redirects to https if needed).
        if not target.startswith(("http://", "https://")):
            scheme = config.get("scheme", "http")
            url = f"{scheme}://{target}"
        else:
            url = target

        wordlist = config.get("wordlist")
        if not wordlist or not os.path.isfile(wordlist):
            for candidate in [
                "/app/resources/wordlists/common.txt",
                "/app/wordlists/common.txt",
                "/usr/share/seclists/Discovery/Web-Content/common.txt",
                "/usr/share/wordlists/dirb/common.txt",
            ]:
                if os.path.isfile(candidate):
                    wordlist = candidate
                    break
            else:
                wordlist = "/app/resources/wordlists/common.txt"

        extensions = config.get("extensions", self._DEFAULT_EXTENSIONS)
        threads = self._PROFILE_RATE.get(profile.name, 30)
        if "rate_limit_qps" in config:
            threads = min(int(config["rate_limit_qps"]), 80)

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
            "-b", "",                   # Clear default blacklist so -s can be used.
            "-s", "200,204,301,302,307,401,403",  # Interesting status codes.
            "--exclude-length", "0",     # Exclude wildcard empty length redirects
            "-k",                       # Skip TLS certificate validation
            "--timeout", "4s",
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
