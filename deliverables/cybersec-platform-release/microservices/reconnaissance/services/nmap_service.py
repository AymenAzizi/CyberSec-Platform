"""Nmap service — builds nmap commands per CDC scan profile."""

from __future__ import annotations

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

    # Common nmap flags shared across profiles.
    _BASE_FLAGS: List[str] = [
        "-Pn",                # Don't ping (some hosts block ICMP).
        "-sS" if shutil.which("nmap") else "-sT",  # SYN if root, else connect().
        "-sV",                # Service detection.
        "--version-intensity",
        "5",
        "-O",                 # OS detection (best-effort).
        "--top-ports",
        "1000",
        "-oN",
        "-",                  # Emit normal output to stdout.
    ]

    _PROFILE_FLAGS: Dict[str, List[str]] = {
        "silent": [
            "-T2",
            "--scan-delay",
            "1s",
            "--max-rate",
            "10",
            "--max-retries",
            "1",
        ],
        "balanced": [
            "-T3",
            "--max-rate",
            "50",
            "--max-retries",
            "2",
        ],
        "aggressive": [
            "-T4",
            "--max-rate",
            "200",
            "--max-retries",
            "3",
            "-A",  # Aggressive: scripts + traceroute.
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
        # Aggressive profile is internal-only; enforce here as a safety net.
        if profile.name == "aggressive" and not config.get("allow_aggressive", False):
            # Default to balanced flags if caller did not explicitly authorize.
            profile_flags = self._PROFILE_FLAGS["balanced"]
        else:
            profile_flags = self._PROFILE_FLAGS.get(profile.name, self._PROFILE_FLAGS["balanced"])

        cmd: List[str] = [self.binary]
        cmd.extend(self._BASE_FLAGS)
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
