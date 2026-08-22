"""Nuclei service — JSONL output + severity filtering."""

from __future__ import annotations

import json
import os
import shutil
from typing import Any, Dict, List

from .base_service import BaseScannerService, ScanProfile


class NucleiService(BaseScannerService):
    """Wrapper around ProjectDiscovery's ``nuclei`` scanner."""

    tool_name = "nuclei"
    binary = "nuclei"

    _SEVERITY_ORDER = ["info", "low", "medium", "high", "critical"]

    _PROFILE_RATE: Dict[str, int] = {
        "silent": 5,       # requests per second
        "balanced": 25,
        "aggressive": 120,
    }

    _PROFILE_CONCURRENCY: Dict[str, int] = {
        "silent": 5,
        "balanced": 20,
        "aggressive": 50,
    }

    def __init__(self, config: Dict[str, Any] | None = None) -> None:
        super().__init__(config)
        binary = shutil.which("nuclei")
        if binary:
            self.binary = binary

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        rate = self._PROFILE_RATE.get(profile.name, 25)
        concurrency = self._PROFILE_CONCURRENCY.get(profile.name, 20)
        # Per-profile rate limit override (requests/second).
        if "rate_limit_qps" in config:
            rate = int(config["rate_limit_qps"])

        cmd: List[str] = [
            self.binary,
            "-u", target,
            "-jsonl",                  # JSON Lines output to stdout.
            "-silent",                 # No banner / progress bars.
            "-rate-limit", str(rate),
            "-c", str(concurrency),
            "-timeout", "10",
            "-retries", "1",
        ]

        # Severity filter — default to low+ unless overridden.
        min_severity = config.get("min_severity", "low")
        if min_severity in self._SEVERITY_ORDER:
            idx = self._SEVERITY_ORDER.index(min_severity)
            severities = self._SEVERITY_ORDER[idx:]
            cmd.extend(["-severity", ",".join(severities)])

        # Template directory (default to user-writable location).
        templates_dir = config.get(
            "templates_dir",
            os.environ.get("NUCLEI_TEMPLATES_DIR", "/tmp/nuclei-templates"),
        )
        if templates_dir and os.path.isdir(templates_dir):
            cmd.extend(["-t", templates_dir])
        else:
            cmd.extend(["-t", "cves"])  # Fallback: built-in cves subset.

        # Optional tag filter (e.g. "cve,fuzz,dns").
        tags = config.get("tags")
        if tags:
            cmd.extend(["-tags", str(tags)])

        return cmd

    def parse_output(self, stdout: str) -> List[Dict[str, Any]]:
        """Parse nuclei JSONL output into structured findings."""
        findings: List[Dict[str, Any]] = []
        for line in stdout.splitlines():
            line = line.strip()
            if not line or not line.startswith("{"):
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            findings.append(obj)
        return findings


__all__ = ["NucleiService"]
