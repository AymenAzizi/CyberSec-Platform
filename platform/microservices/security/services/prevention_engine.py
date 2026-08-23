"""Prevention engine — WAF detection, security posture, recommendations."""

from __future__ import annotations

import logging
from typing import Any, Dict, List, Tuple

import requests

logger = logging.getLogger(__name__)

# WAF vendor signatures (CDC: Cloudflare, AWS WAF, ModSecurity, Sucuri, Imperva, Akamai).
WAF_SIGNATURES: Dict[str, List[str]] = {
    "Cloudflare": ["cf-ray", "__cf_bm", "server: cloudflare", "cf-cache-status"],
    "AWS WAF": ["x-amzn-waf", "x-amz-cf-id", "awselb"],
    "ModSecurity": ["mod_security", "modsecurity", "nginx-mod-security"],
    "Sucuri": ["x-sucuri-id", "server: sucuri"],
    "Imperva": ["incap_ses", "visid_incap", "x-iinfo", "server: imperva"],
    "Akamai": ["akamai", "x-akamai-transformed", "server: akamaighost"],
    "F5 BIG-IP ASM": ["tsession", "bigipserver", "x-cnection"],
    "Barracuda": ["barra_counter_session"],
}

# Test payloads designed to trigger WAF blocks.
WAF_TEST_PAYLOADS: List[str] = [
    "?id=1' OR '1'='1",
    "?q=<script>alert(1)</script>",
    "?cmd=;cat /etc/passwd",
    "?path=../../etc/passwd",
]


class PreventionEngine:
    """WAF detection + security posture recommendations."""

    def __init__(self, timeout: int = 10) -> None:
        self.timeout = timeout

    # ------------------------------------------------------------------
    def detect_waf(self, target: str) -> Dict[str, Any]:
        """Detect whether a WAF is in front of the target."""
        wafs_detected: List[str] = []
        evidence: List[Dict[str, Any]] = []
        blocked_payloads: List[str] = []

        # 1. Baseline header inspection.
        try:
            base = requests.get(
                target,
                headers={"User-Agent": "PFE-CyberSec/1.0 (waf-detect)"},
                timeout=self.timeout,
                verify=False,  # nosec B501
                allow_redirects=False,
            )
        except requests.RequestException as exc:
            return {"target": target, "waf_detected": False, "error": str(exc)}

        header_blob = "\n".join(f"{k.lower()}: {v.lower()}" for k, v in base.headers.items())
        cookie_blob = " ".join(c.lower() for c in base.headers.get("Set-Cookie", "").split(","))
        combined = f"{header_blob}\n{cookie_blob}"

        for vendor, sigs in WAF_SIGNATURES.items():
            for sig in sigs:
                if sig in combined:
                    wafs_detected.append(vendor)
                    evidence.append({"vendor": vendor, "signature": sig, "source": "baseline headers"})
                    break

        # 2. Send attack payloads; WAF typically responds 403/406/429/503.
        for payload in WAF_TEST_PAYLOADS:
            url = target.rstrip("/") + payload if "?" not in target else target + "&" + payload.lstrip("?")
            try:
                resp = requests.get(
                    url,
                    headers={"User-Agent": "PFE-CyberSec/1.0 (waf-detect)"},
                    timeout=self.timeout,
                    verify=False,  # nosec B501
                    allow_redirects=False,
                )
            except requests.RequestException:
                continue
            if resp.status_code in {403, 406, 418, 429, 503}:
                blocked_payloads.append(payload)
                # Re-inspect response headers for vendor signature.
                resp_blob = "\n".join(f"{k.lower()}: {v.lower()}" for k, v in resp.headers.items())
                for vendor, sigs in WAF_SIGNATURES.items():
                    if vendor in wafs_detected:
                        continue
                    for sig in sigs:
                        if sig in resp_blob:
                            wafs_detected.append(vendor)
                            evidence.append({"vendor": vendor, "signature": sig, "source": f"blocked {payload}"})
                            break

        return {
            "target": target,
            "waf_detected": bool(wafs_detected),
            "waf_vendors": wafs_detected,
            "blocked_payloads": blocked_payloads,
            "evidence": evidence,
            "baseline_status": base.status_code,
        }

    # ------------------------------------------------------------------
    def posture_check(self, target: str, detection_report: Dict[str, Any] | None = None) -> Dict[str, Any]:
        """Aggregate security posture assessment + actionable recommendations."""
        detection = detection_report or {}
        missing_headers: List[str] = detection.get("missing_security_headers", [])
        dangerous_methods: List[str] = detection.get("dangerous_methods", [])
        sensitive_paths = [p for p in detection.get("sensitive_paths", []) if p.get("interesting")]
        risk_score = int(detection.get("risk_score", 0))

        waf = self.detect_waf(target)

        findings: List[Dict[str, Any]] = []
        recommendations: List[Dict[str, Any]] = []

        if not waf.get("waf_detected"):
            findings.append({"category": "waf", "severity": "medium", "message": "No WAF detected in front of the application."})
            recommendations.append({
                "category": "waf",
                "priority": "high",
                "recommendation": "Deploy a WAF (Cloudflare, ModSecurity, AWS WAF) to filter malicious traffic.",
            })

        for header in missing_headers:
            severity = "high" if header in {"strict-transport-security", "content-security-policy"} else "medium"
            findings.append({"category": "headers", "severity": severity, "message": f"Missing security header: {header}"})
            recommendations.append({
                "category": "headers",
                "priority": severity,
                "recommendation": self._header_recommendation(header),
            })

        if dangerous_methods:
            findings.append({"category": "methods", "severity": "high", "message": f"Dangerous HTTP methods enabled: {', '.join(dangerous_methods)}"})
            recommendations.append({
                "category": "methods",
                "priority": "high",
                "recommendation": "Disable unnecessary HTTP methods (PUT, DELETE, TRACE) at the web server or WAF.",
            })

        if sensitive_paths:
            findings.append({"category": "exposure", "severity": "medium", "message": f"{len(sensitive_paths)} sensitive paths accessible."})
            recommendations.append({
                "category": "exposure",
                "priority": "medium",
                "recommendation": "Restrict access to sensitive paths (.env, .git, backup files) via server configuration or WAF rules.",
            })

        posture = self._posture_label(risk_score, bool(waf.get("waf_detected")))
        return {
            "target": target,
            "risk_score": risk_score,
            "posture": posture,
            "waf": waf,
            "findings": findings,
            "recommendations": recommendations,
        }

    # ------------------------------------------------------------------
    @staticmethod
    def _header_recommendation(header: str) -> str:
        return {
            "strict-transport-security": "Add 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload'.",
            "content-security-policy": "Define a restrictive Content-Security-Policy with default-src 'self' and explicit allowlists.",
            "x-frame-options": "Set 'X-Frame-Options: DENY' (or use CSP frame-ancestors).",
            "x-content-type-options": "Add 'X-Content-Type-Options: nosniff'.",
            "x-xss-protection": "Set 'X-XSS-Protection: 1; mode=block' (legacy browsers).",
            "referrer-policy": "Add 'Referrer-Policy: strict-origin-when-cross-origin'.",
            "permissions-policy": "Define a Permissions-Policy restricting camera, microphone, geolocation.",
            "x-permitted-cross-domain-policies": "Set 'X-Permitted-Cross-Domain-Policies: none'.",
            "cross-origin-opener-policy": "Set 'Cross-Origin-Opener-Policy: same-origin'.",
            "cross-origin-embedder-policy": "Set 'Cross-Origin-Embedder-Policy: require-corp'.",
            "cross-origin-resource-policy": "Set 'Cross-Origin-Resource-Policy: same-site'.",
        }.get(header, f"Add the {header} security header.")

    @staticmethod
    def _posture_label(risk_score: int, waf: bool) -> str:
        if risk_score <= 20 and waf:
            return "strong"
        if risk_score <= 40:
            return "moderate"
        if risk_score <= 70:
            return "weak"
        return "critical"


__all__ = ["PreventionEngine", "WAF_SIGNATURES"]
