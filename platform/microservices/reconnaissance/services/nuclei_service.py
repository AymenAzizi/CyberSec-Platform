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
        "silent": 15,
        "balanced": 60,
        "aggressive": 150,
    }

    _PROFILE_CONCURRENCY: Dict[str, int] = {
        "silent": 10,
        "balanced": 30,
        "aggressive": 60,
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
        # Ensure target is a valid URL (default http; nuclei upgrades/follows redirects to https)
        if not target.startswith(("http://", "https://")):
            url = f"http://{target}"
        else:
            url = target

        rate = self._PROFILE_RATE.get(profile.name, 60)
        concurrency = self._PROFILE_CONCURRENCY.get(profile.name, 30)
        if "rate_limit_qps" in config:
            rate = int(config["rate_limit_qps"])

        cmd: List[str] = [
            self.binary,
            "-u", url,
            "-jsonl",                  # JSON Lines output to stdout.
            "-silent",                 # No banner / progress bars.
            "-duc",                    # Disable automatic update check for speed.
            "-rate-limit", str(rate),
            "-c", str(concurrency),
            "-timeout", "5",
            "-retries", "1",
        ]

        # Severity filter — default to low+ unless overridden.
        min_severity = config.get("min_severity", "low")
        if min_severity in self._SEVERITY_ORDER:
            idx = self._SEVERITY_ORDER.index(min_severity)
            severities = self._SEVERITY_ORDER[idx:]
            cmd.extend(["-severity", ",".join(severities)])

        # Template directory or smart tag filtering
        templates_dir = config.get(
            "templates_dir",
            os.environ.get("NUCLEI_TEMPLATES_DIR"),
        )
        if templates_dir and os.path.isdir(templates_dir):
            cmd.extend(["-t", templates_dir])
        elif config.get("templates"):
            cmd.extend(["-t", str(config["templates"])])
        elif config.get("template"):
            cmd.extend(["-t", str(config["template"])])
        else:
            # Targeted template tags to ensure fast, high-value findings without timeouts
            tags = config.get("tags", "cve,exposure,misconfig,tech,ssl,dns")
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
