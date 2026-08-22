"""Subfinder service — passive subdomain enumeration."""

from __future__ import annotations

import os
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
        # Strip protocol if present for subdomain enumeration
        host = target.replace("https://", "").replace("http://", "").split("/")[0]

        cmd: List[str] = [
            self.binary,
            "-d", host,
            "-silent",            # No banner.
            "-timeout", "20",
            "-t", "20",           # Concurrent HTTP requests to source APIs.
        ]

        # Optional API config file (improves coverage).
        provider_config = config.get("provider_config")
        if provider_config and isinstance(provider_config, str) and os.path.isfile(provider_config):
            cmd.extend(["-pc", provider_config])

        # Optional source filter.
        sources = config.get("sources")
        if sources:
            cmd.extend(["-s", str(sources)])

        return cmd


__all__ = ["SubfinderService"]
