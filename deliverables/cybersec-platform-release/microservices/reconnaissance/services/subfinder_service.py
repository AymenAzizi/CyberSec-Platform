"""Subfinder service — passive subdomain enumeration."""

from __future__ import annotations

import shutil
from typing import Any, Dict, List

from .base_service import BaseScannerService, ScanProfile


class SubfinderService(BaseScannerService):
    """Wrapper around ProjectDiscovery's ``subfinder``."""

    tool_name = "subfinder"
    binary = "subfinder"

    def __init__(self, config: Dict[str, Any] | None = None) -> None:
        super().__init__(config)
        binary = shutil.which("subfinder")
        if binary:
            self.binary = binary

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        # subfinder is passive, so profile only affects timeouts/sources.
        cmd: List[str] = [
            self.binary,
            "-d", target,
            "-silent",            # No banner.
            "-timeout", "30",
            "-t", "10",           # Concurrent HTTP requests to source APIs.
        ]

        # Optional API config file (improves coverage).
        provider_config = config.get(
            "provider_config",
            "/etc/subfinder/provider-config.yaml",
        )
        if provider_config and isinstance(provider_config, str) and "/" in provider_config:
            cmd.extend(["-pc", provider_config])

        # Optional source filter.
        sources = config.get("sources")
        if sources:
            cmd.extend(["-s", str(sources)])

        # Aggressive profile enables active verification (HTTP probe).
        if profile.name == "aggressive" and config.get("active_verify", True):
            cmd.append("-active")  # Probe each discovered subdomain.

        return cmd


__all__ = ["SubfinderService"]
