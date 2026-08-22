"""Nmap service — builds nmap commands per CDC scan profile."""

from __future__ import annotations

import os
import shutil
from typing import Any, Dict, List

from .base_service import BaseScannerService, ScanProfile


class NmapService(BaseScannerService):
    """Service wrapper around the ``nmap`` binary.

    Profile flag mapping (Final CDC):
      * Silent     -> ``-T2 --scan-delay 1s --max-rate 10``
      * Balanced   -> ``-T3 --max-rate 50``
      * Aggressive -> ``-T4 --max-rate 200``
    """

    tool_name = "nmap"
    binary = "nmap"

    @classmethod
    def _is_root(cls) -> bool:
        return hasattr(os, "geteuid") and os.geteuid() == 0

    def _get_base_flags(self) -> List[str]:
        is_root = self._is_root()
        flags = [
            "-Pn",                                    # Don't ping (some hosts block ICMP).
            "-sS" if is_root else "-sT",              # SYN if root, else TCP connect().
            "-sV",                                    # Service detection.
            "--version-light",                        # Light version detection for high performance
            "--top-ports",
            "100",                                    # Scan top 100 ports by default for fast, reliable recon
            "--host-timeout",
            "120s",                                   # Maximum 120s per host
            "-oN",
            "-",                                      # Emit normal output to stdout.
        ]
        if is_root:
            flags.append("-O")                        # OS detection requires raw sockets/root.
        return flags

    _PROFILE_FLAGS: Dict[str, List[str]] = {
        "silent": [
            "-T2",
            "--scan-delay",
            "500ms",
            "--max-rate",
            "20",
            "--max-retries",
            "1",
        ],
        "balanced": [
            "-T3",
            "--max-rate",
            "100",
            "--max-retries",
            "1",
        ],
        "aggressive": [
            "-T4",
            "--max-rate",
            "300",
            "--max-retries",
            "2",
        ],
    }

    def __init__(self, config: Dict[str, Any] | None = None) -> None:
        super().__init__(config)
        binary = shutil.which("nmap")
        if binary:
            self.binary = binary

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        profile_flags = self._PROFILE_FLAGS.get(profile.name, self._PROFILE_FLAGS["balanced"])

        cmd: List[str] = [self.binary]
        cmd.extend(self._get_base_flags())
        cmd.extend(profile_flags)

        # Optional extra ports from caller config.
        extra_ports = config.get("ports")
        if extra_ports:
            cmd.extend(["-p", str(extra_ports)])

        # Optional NSE scripts.
        scripts = config.get("scripts")
        if scripts:
            cmd.extend(["--script", str(scripts)])

        # Optional timing override (ms).
        if "initial_rtt" in config:
            cmd.extend(["--initial-rtt-timeout", str(config["initial_rtt"])])

        cmd.append(target)
        return cmd


__all__ = ["NmapService"]
