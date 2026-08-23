"""Attack detector — header analysis, sensitive path discovery,
HTTP method testing, technology detection and risk scoring.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple

import requests

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# 17 sensitive paths (CDC requirement).
# ---------------------------------------------------------------------------
SENSITIVE_PATHS: List[str] = [
    "/.env",
    "/.git/config",
    "/.htaccess",
    "/wp-admin/",
    "/wp-config.php.bak",
    "/admin/",
    "/administrator/",
    "/phpinfo.php",
    "/server-status",
    "/.svn/entries",
    "/backup/",
    "/config.php.bak",
    "/.DS_Store",
    "/web.config",
    "/.aws/credentials",
    "/robots.txt",
    "/sitemap.xml",
]

# ---------------------------------------------------------------------------
# Security headers to inspect.
# ---------------------------------------------------------------------------
SECURITY_HEADERS: Dict[str, str] = {
    "strict-transport-security": "HSTS",
    "content-security-policy": "CSP",
    "x-frame-options": "X-Frame-Options",
    "x-content-type-options": "X-Content-Type-Options",
    "x-xss-protection": "X-XSS-Protection",
    "referrer-policy": "Referrer-Policy",
    "permissions-policy": "Permissions-Policy",
    "x-permitted-cross-domain-policies": "X-Permitted-Cross-Domain-Policies",
    "cross-origin-opener-policy": "COOP",
    "cross-origin-embedder-policy": "COEP",
    "cross-origin-resource-policy": "CORP",
}

# HTTP methods to probe.
HTTP_METHODS: List[str] = ["GET", "POST", "PUT", "DELETE", "OPTIONS", "PATCH", "TRACE"]

# Server / technology fingerprints based on response headers.
TECH_SIGNATURES: Dict[str, List[str]] = {
    "Apache": ["apache"],
    "Nginx": ["nginx"],
    "IIS": ["microsoft-iis", "iis"],
    "PHP": ["php", "x-powered-by: php"],
    "ASP.NET": ["asp.net", "x-aspnet-version", "x-powered-by: asp.net"],
    "Express": ["x-powered-by: express"],
    "WordPress": ["x-pingback", "wp-content", "wp-includes"],
    "Joomla": ["x-content-powered-by: joomla"],
    "Drupal": ["x-drupal-cache", "x-generator: drupal"],
    "Cloudflare": ["cf-ray", "server: cloudflare"],
    "Varnish": ["x-varnish", "via: varnish"],
}


@dataclass
class DetectionReport:
    headers: Dict[str, Any] = field(default_factory=dict)
    missing_security_headers: List[str] = field(default_factory=list)
    sensitive_paths: List[Dict[str, Any]] = field(default_factory=list)
    allowed_methods: List[str] = field(default_factory=list)
    dangerous_methods: List[str] = field(default_factory=list)
    technologies: List[str] = field(default_factory=list)
    risk_score: int = 0
    risk_factors: List[str] = field(default_factory=list)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "headers": self.headers,
            "missing_security_headers": self.missing_security_headers,
            "sensitive_paths": self.sensitive_paths,
            "allowed_methods": self.allowed_methods,
            "dangerous_methods": self.dangerous_methods,
            "technologies": self.technologies,
            "risk_score": self.risk_score,
            "risk_factors": self.risk_factors,
        }


class AttackDetector:
    """Perform passive detection against an HTTP(S) target."""

    def __init__(
        self,
        timeout: int = 10,
        user_agent: str = "PFE-CyberSec/1.0 (security-testing)",
    ) -> None:
        self.timeout = timeout
        self.user_agent = user_agent

    # ------------------------------------------------------------------
    def detect(self, target: str, profile: str = "balanced") -> Dict[str, Any]:
        """Run all detection modules and compute a risk score (0-100)."""
        report = DetectionReport()
        try:
            base_response = self._fetch(target, "/")
        except requests.RequestException as exc:
            logger.warning("attack detection failed to reach %s: %s", target, exc)
            return {
                **report.to_dict(),
                "error": f"unreachable: {exc}",
                "target": target,
            }

        # 1. Headers
        report.headers = self._analyze_headers(base_response.headers)
        report.missing_security_headers = self._missing_headers(base_response.headers)
        # 2. Tech fingerprinting
        report.technologies = self._detect_tech(base_response.headers, base_response.text)
        # 3. Sensitive paths
        report.sensitive_paths = self._probe_sensitive_paths(target)
        # 4. HTTP methods
        report.allowed_methods, report.dangerous_methods = self._probe_methods(target, base_response)
        # 5. Risk score
        report.risk_score, report.risk_factors = self._score(report)
        return {"target": target, **report.to_dict()}

    # ------------------------------------------------------------------
    def _fetch(self, target: str, path: str) -> requests.Response:
        url = target.rstrip("/") + path
        return requests.get(
            url,
            headers={"User-Agent": self.user_agent},
            timeout=self.timeout,
            allow_redirects=False,
            verify=False,  # nosec B501
        )

    # ------------------------------------------------------------------
    def _analyze_headers(self, headers: Any) -> Dict[str, Any]:
        if not hasattr(headers, "items"):
            return {}
        result: Dict[str, Any] = {}
        for name, label in SECURITY_HEADERS.items():
            value = headers.get(name) or headers.get(name.lower())
            result[name] = {"present": bool(value), "value": value, "label": label}
        return result

    def _missing_headers(self, headers: Any) -> List[str]:
        if not hasattr(headers, "items"):
            return list(SECURITY_HEADERS.keys())
        return [
            name
            for name in SECURITY_HEADERS
            if not (headers.get(name) or headers.get(name.lower()))
        ]

    # ------------------------------------------------------------------
    def _detect_tech(self, headers: Any, body: str) -> List[str]:
        if not hasattr(headers, "items"):
            return []
        flat = "\n".join(f"{k.lower()}: {v.lower()}" for k, v in headers.items())
        flat += "\n" + (body or "").lower()[:5000]
        found: List[str] = []
        for tech, signatures in TECH_SIGNATURES.items():
            for sig in signatures:
                if sig in flat:
                    found.append(tech)
                    break
        return found

    # ------------------------------------------------------------------
    def _probe_sensitive_paths(self, target: str) -> List[Dict[str, Any]]:
        results: List[Dict[str, Any]] = []
        for path in SENSITIVE_PATHS:
            try:
                resp = self._fetch(target, path)
            except requests.RequestException as exc:
                results.append({"path": path, "error": str(exc)})
                continue
            interesting = resp.status_code in {200, 301, 302, 401, 403}
            results.append({
                "path": path,
                "status": resp.status_code,
                "length": len(resp.content),
                "interesting": interesting,
            })
        return results

    # ------------------------------------------------------------------
    def _probe_methods(self, target: str, base_response: requests.Response) -> Tuple[List[str], List[str]]:
        # OPTIONS response first.
        allowed: List[str] = []
        allow_header = base_response.headers.get("Allow") or base_response.headers.get("allow") or ""
        if allow_header:
            allowed = [m.strip().upper() for m in allow_header.split(",") if m.strip()]

        # Probe each method individually.
        dangerous: List[str] = []
        url = target.rstrip("/") + "/"
        for method in HTTP_METHODS:
            try:
                resp = requests.request(
                    method,
                    url,
                    headers={"User-Agent": self.user_agent},
                    timeout=self.timeout,
                    allow_redirects=False,
                    verify=False,
                )
            except requests.RequestException:
                continue
            # 405 = not allowed; 200/204/301/302/401/403 = method accepted.
            if resp.status_code == 405:
                continue
            if method not in allowed:
                allowed.append(method)
            if method in {"PUT", "DELETE", "TRACE", "PATCH"}:
                # TRACE reflected back is particularly dangerous (XST).
                dangerous.append(method)
        return allowed, dangerous

    # ------------------------------------------------------------------
    def _score(self, report: DetectionReport) -> Tuple[int, List[str]]:
        risk = 0
        factors: List[str] = []

        # Missing security headers: 5 points each, capped at 30.
        header_risk = min(len(report.missing_security_headers) * 5, 30)
        if header_risk:
            risk += header_risk
            factors.append(f"missing {len(report.missing_security_headers)} security headers")

        # HSTS missing specifically = bigger weight.
        if "strict-transport-security" in report.missing_security_headers:
            risk += 5
            factors.append("HSTS not enforced")

        # CSP missing specifically.
        if "content-security-policy" in report.missing_security_headers:
            risk += 5
            factors.append("CSP not defined")

        # Dangerous methods enabled.
        if report.dangerous_methods:
            risk += min(len(report.dangerous_methods) * 5, 20)
            factors.append(f"dangerous methods enabled: {','.join(report.dangerous_methods)}")

        # Sensitive paths returned 200/401/403.
        interesting_paths = [p for p in report.sensitive_paths if p.get("interesting")]
        if interesting_paths:
            risk += min(len(interesting_paths) * 3, 15)
            factors.append(f"{len(interesting_paths)} sensitive paths exposed")

        # Technology disclosure.
        if any(t in report.technologies for t in {"PHP", "ASP.NET", "IIS"}):
            risk += 5
            factors.append("server-side tech fingerprint disclosed")

        return min(risk, 100), factors


__all__ = ["AttackDetector", "DetectionReport", "SENSITIVE_PATHS", "SECURITY_HEADERS"]
