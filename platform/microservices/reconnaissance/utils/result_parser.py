"""Result parser — converts raw tool output into normalized findings.

Each finding is a dict with the keys:

    {
        "title": str,
        "severity": "info" | "low" | "medium" | "high" | "critical",
        "description": str,
        "evidence": str,
        "endpoint": str | None,
        "source_tool": str,
        "cve_id": str | None,
        "citations": [{"line": int, "raw_excerpt": str}],
    }
"""

from __future__ import annotations

import json
import re
from typing import Any, Dict, List, Optional

# Severity bucket used when a tool doesn't provide one.
_SEVERITY_DEFAULT = "info"
_SEVERITY_ORDER = ["info", "low", "medium", "high", "critical"]


def _max_severity(values: List[str]) -> str:
    """Return the highest severity among ``values``."""
    best = _SEVERITY_DEFAULT
    for v in values:
        v_lower = (v or "").strip().lower()
        if v_lower not in _SEVERITY_ORDER:
            continue
        if _SEVERITY_ORDER.index(v_lower) > _SEVERITY_ORDER.index(best):
            best = v_lower
    return best


def _citations_from_lines(raw: str, needle: str, max_excerpts: int = 3) -> List[Dict[str, Any]]:
    """Build citation objects referencing line numbers containing ``needle``."""
    citations: List[Dict[str, Any]] = []
    if not needle:
        return citations
    needle_lower = needle.lower()
    for idx, line in enumerate(raw.splitlines(), start=1):
        if needle_lower in line.lower():
            citations.append({
                "line": idx,
                "raw_excerpt": line.strip()[:240],
            })
            if len(citations) >= max_excerpts:
                break
    return citations


class ResultParser:
    """Parse raw tool output into normalized finding dicts."""

    CVE_RE = re.compile(r"CVE-\d{4}-\d{4,7}", re.IGNORECASE)
    NMAP_PORT_RE = re.compile(
        r"^(?P<port>\d+)/(tcp|udp)\s+(?P<state>open|open|filtered|closed)\s+(?P<service>\S+)(?:\s+(?P<version>.*))?$"
    )
    NMAP_HOST_RE = re.compile(
        r"Nmap scan report for\s+(?P<host>\S+)", re.IGNORECASE
    )
    NMAP_OS_RE = re.compile(
        r"OS details:\s+(?P<os>.+)$", re.IGNORECASE
    )
    GOBUSTER_RE = re.compile(
        r"^(?P<url>\S+)\s+\(Status:\s+(?P<code>\d+)\)\s*\[Size:\s*(?P<size>\d+)\]",
        re.IGNORECASE,
    )
    SUBFINDER_RE = re.compile(r"^(?P<sub>[A-Za-z0-9.\-]+)$")

    # ---- public API -------------------------------------------------------
    def parse(self, tool: str, raw_output: str) -> List[Dict[str, Any]]:
        """Dispatch to a per-tool parser. Falls back to generic parser."""
        if raw_output is None:
            return []
        parser = {
            "nmap": self._parse_nmap,
            "nuclei": self._parse_nuclei,
            "gobuster": self._parse_gobuster,
            "subfinder": self._parse_subfinder,
            "wpscan": self._parse_wpscan,
        }.get(tool, self._parse_generic)
        try:
            findings = parser(raw_output)
        except Exception as exc:  # noqa: BLE001
            findings = [{
                "title": f"{tool} parse error",
                "severity": "info",
                "description": f"Failed to parse {tool} output: {exc}",
                "evidence": "",
                "endpoint": None,
                "source_tool": tool,
                "cve_id": None,
                "citations": [],
            }]
        return findings

    # ---- per-tool parsers -------------------------------------------------
    def _parse_nmap(self, raw: str) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []
        current_host: Optional[str] = None
        for idx, line in enumerate(raw.splitlines(), start=1):
            line = line.rstrip()
            host_match = self.NMAP_HOST_RE.search(line)
            if host_match:
                current_host = host_match.group("host")
                continue
            os_match = self.NMAP_OS_RE.search(line)
            if os_match:
                findings.append({
                    "title": f"OS fingerprint: {os_match.group('os')}",
                    "severity": "info",
                    "description": f"Remote OS detected as {os_match.group('os')}",
                    "evidence": line.strip(),
                    "endpoint": current_host,
                    "source_tool": "nmap",
                    "cve_id": None,
                    "citations": [{"line": idx, "raw_excerpt": line.strip()[:240]}],
                })
                continue

            port_match = self.NMAP_PORT_RE.match(line.strip())
            if not port_match:
                continue
            port = int(port_match.group("port"))
            state = port_match.group("state")
            service = port_match.group("service")
            proto = port_match.group(2)
            version = (port_match.group("version") or "").strip()
            # Severity inference per CDC: high-risk ports are flagged higher.
            severity = self._infer_port_severity(port, service)
            title = f"Open port {port}/{proto} {service} ({state})"
            findings.append({
                "title": title,
                "severity": severity,
                "description": (
                    f"Port {port} ({service}) is {state} on {current_host or 'target'}."
                    + (f" Version: {version}." if version else "")
                ),
                "evidence": line.strip(),
                "endpoint": f"{current_host}:{port}" if current_host else str(port),
                "source_tool": "nmap",
                "cve_id": None,
                "citations": [{"line": idx, "raw_excerpt": line.strip()[:240]}],
            })
        return findings

    def _infer_port_severity(self, port: int, service: str) -> str:
        """Severity inference based on port/service (CDC requirement)."""
        service_lower = (service or "").lower()
        if port in {23, 21, 25, 69, 161, 162, 512, 513, 514}:
            # Telnet, FTP, SNMP, r-services — cleartext / legacy.
            return "high"
        if port in {445, 3389, 5900, 5901} or "rdp" in service_lower or "smb" in service_lower or "vnc" in service_lower:
            return "medium"
        if port in {22, 80, 443, 8080, 8443}:
            return "info"
        if port in {3306, 5432, 6379, 27017, 9200, 11211}:
            # Databases exposed — high.
            return "high"
        return "low"

    def _parse_nuclei(self, raw: str) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []
        lines = raw.splitlines()
        for idx, line in enumerate(lines, start=1):
            line = line.strip()
            if not line or not line.startswith("{"):
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            # Map nuclei fields onto our finding schema.
            info = obj.get("info", {}) if isinstance(obj.get("info"), dict) else {}
            severity = (info.get("severity") or obj.get("severity") or "info").lower()
            if severity not in _SEVERITY_ORDER:
                severity = "info"
            name = info.get("name") or obj.get("template-id") or obj.get("matcher-name") or "Nuclei finding"
            description = info.get("description") or name
            matched = obj.get("matched-at") or obj.get("matched") or obj.get("host") or ""
            cve_id = None
            cve_match = self.CVE_RE.search(json.dumps(obj))
            if cve_match:
                cve_id = cve_match.group(0).upper()
            tags = info.get("tags") or {}
            if isinstance(tags, dict):
                tags_list = list(tags.keys())
            elif isinstance(tags, list):
                tags_list = tags
            else:
                tags_list = []
            findings.append({
                "title": name,
                "severity": severity,
                "description": description,
                "evidence": json.dumps({
                    "template": obj.get("template-id"),
                    "matched_at": matched,
                    "tags": tags_list,
                    "type": obj.get("type"),
                }, sort_keys=True),
                "endpoint": matched,
                "source_tool": "nuclei",
                "cve_id": cve_id,
                "citations": [{"line": idx, "raw_excerpt": line[:240]}],
            })
        return findings

    def _parse_gobuster(self, raw: str) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []
        for idx, line in enumerate(raw.splitlines(), start=1):
            line = line.strip()
            match = self.GOBUSTER_RE.match(line)
            if not match:
                continue
            url = match.group("url")
            code = int(match.group("code"))
            size = int(match.group("size"))
            severity = self._infer_status_severity(code)
            findings.append({
                "title": f"{code} {url}",
                "severity": severity,
                "description": f"Discovered path {url} (HTTP {code}, {size} bytes)",
                "evidence": line,
                "endpoint": url,
                "source_tool": "gobuster",
                "cve_id": None,
                "citations": [{"line": idx, "raw_excerpt": line[:240]}],
            })
        return findings

    def _infer_status_severity(self, code: int) -> str:
        """Severity inference based on HTTP status (CDC requirement)."""
        if code in {200, 301, 302}:
            # 200 on a sensitive path (admin/login) is medium; we let the
            # caller decide based on path patterns.
            return "low" if code == 200 else "info"
        if code == 401:
            return "info"
        if code == 403:
            return "low"
        if code == 500:
            return "medium"
        return "info"

    def _parse_subfinder(self, raw: str) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []
        for idx, line in enumerate(raw.splitlines(), start=1):
            line = line.strip()
            match = self.SUBFINDER_RE.match(line)
            if not match:
                continue
            sub = match.group("sub")
            findings.append({
                "title": f"Subdomain: {sub}",
                "severity": "info",
                "description": f"Passively discovered subdomain: {sub}",
                "evidence": sub,
                "endpoint": sub,
                "source_tool": "subfinder",
                "cve_id": None,
                "citations": [{"line": idx, "raw_excerpt": sub[:240]}],
            })
        return findings

    def _parse_wpscan(self, raw: str) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []
        # WPScan --format json emits a single JSON document on stdout.
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            # Sometimes the JSON is interleaved with progress lines; try to
            # extract the first balanced JSON object.
            start = raw.find("{")
            end = raw.rfind("}")
            if start >= 0 and end > start:
                try:
                    data = json.loads(raw[start : end + 1])
                except json.JSONDecodeError:
                    return self._parse_generic(raw)
            else:
                return self._parse_generic(raw)

        if not isinstance(data, dict):
            return []

        # WordPress version finding.
        version = (data.get("version") or {}).get("number") if isinstance(data.get("version"), dict) else None
        if version:
            findings.append({
                "title": f"WordPress {version}",
                "severity": "info",
                "description": f"WordPress version detected: {version}",
                "evidence": json.dumps(data.get("version")),
                "endpoint": data.get("target_url"),
                "source_tool": "wpscan",
                "cve_id": None,
                "citations": [{"line": 1, "raw_excerpt": json.dumps(data.get("version"))[:240]}],
            })

        # Plugins.
        for slug, plugin in (data.get("plugins") or {}).items():
            findings.extend(self._wpscan_item(slug, plugin, "plugin", data.get("target_url")))
        # Themes.
        for slug, theme in (data.get("themes") or {}).items():
            findings.extend(self._wpscan_item(slug, theme, "theme", data.get("target_url")))

        # Interesting findings.
        for entry in data.get("interesting_findings") or []:
            findings.append({
                "title": entry.get("to_s") or entry.get("type") or "WordPress finding",
                "severity": (entry.get("severity") or "info").lower(),
                "description": entry.get("to_s") or entry.get("type") or "",
                "evidence": json.dumps(entry, sort_keys=True)[:240],
                "endpoint": entry.get("url") or data.get("target_url"),
                "source_tool": "wpscan",
                "cve_id": None,
                "citations": [{"line": 1, "raw_excerpt": json.dumps(entry, sort_keys=True)[:240]}],
            })

        return findings

    def _wpscan_item(self, slug: str, item: Dict[str, Any], kind: str, base_url: Optional[str]) -> List[Dict[str, Any]]:
        out: List[Dict[str, Any]] = []
        version = item.get("version", {}).get("number") if isinstance(item.get("version"), dict) else item.get("version")
        vulns = item.get("vulnerabilities") or []
        severity = "medium" if vulns else "info"
        out.append({
            "title": f"WP {kind}: {slug}" + (f" v{version}" if version else ""),
            "severity": severity,
            "description": f"WordPress {kind} '{slug}' detected" + (f" version {version}" if version else ""),
            "evidence": json.dumps({"slug": slug, "version": version, "vulns": len(vulns)}, sort_keys=True),
            "endpoint": f"{base_url}/wp-content/{kind}s/{slug}/" if base_url else None,
            "source_tool": "wpscan",
            "cve_id": None,
            "citations": [{"line": 1, "raw_excerpt": json.dumps({"slug": slug, "version": version})[:240]}],
        })
        for v in vulns:
            cve_id = None
            cve_match = self.CVE_RE.search(json.dumps(v))
            if cve_match:
                cve_id = cve_match.group(0).upper()
            out.append({
                "title": f"Vuln in {slug}: {v.get('title') or 'unnamed'}",
                "severity": "high",
                "description": v.get("title") or "Vulnerability in WordPress plugin/theme",
                "evidence": json.dumps(v, sort_keys=True)[:240],
                "endpoint": base_url,
                "source_tool": "wpscan",
                "cve_id": cve_id,
                "citations": [{"line": 1, "raw_excerpt": json.dumps(v, sort_keys=True)[:240]}],
            })
        return out

    def _parse_generic(self, raw: str) -> List[Dict[str, Any]]:
        """Fallback parser — extracts CVE references and notable lines."""
        findings: List[Dict[str, Any]] = []
        cve_lines: List[tuple[int, str, str]] = []
        for idx, line in enumerate(raw.splitlines(), start=1):
            for match in self.CVE_RE.finditer(line):
                cve_lines.append((idx, line.strip(), match.group(0).upper()))

        if not cve_lines:
            return [{
                "title": "Raw tool output",
                "severity": "info",
                "description": f"Tool produced {len(raw.splitlines())} lines of unstructured output.",
                "evidence": raw[:240],
                "endpoint": None,
                "source_tool": "generic",
                "cve_id": None,
                "citations": [{"line": 1, "raw_excerpt": raw.splitlines()[0][:240]}] if raw.splitlines() else [],
            }]

        for idx, line, cve in cve_lines:
            findings.append({
                "title": f"CVE reference: {cve}",
                "severity": "medium",
                "description": f"Tool output references {cve}.",
                "evidence": line,
                "endpoint": None,
                "source_tool": "generic",
                "cve_id": cve,
                "citations": [{"line": idx, "raw_excerpt": line[:240]}],
            })
        return findings


__all__ = ["ResultParser"]
