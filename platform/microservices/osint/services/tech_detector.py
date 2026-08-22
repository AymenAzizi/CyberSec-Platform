"""Technology fingerprinting — header-based (Wappalyzer-style) detection."""

from __future__ import annotations

import logging
import re
from typing import Any, Dict, List

import requests

logger = logging.getLogger(__name__)

# Header -> technology mapping. Each entry can be a substring match.
HEADER_SIGNATURES: Dict[str, List[tuple[str, str]]] = {
    "Server": [
        ("nginx", "Nginx"),
        ("apache", "Apache HTTP Server"),
        ("microsoft-iis", "Microsoft IIS"),
        ("cloudflare", "Cloudflare"),
        ("akamai", "Akamai"),
        ("sucuri", "Sucuri"),
        ("imunify360", "Imunify360"),
        ("litespeed", "LiteSpeed"),
        ("openresty", "OpenResty"),
        ("envoy", "Envoy"),
        ("caddy", "Caddy"),
        ("tomcat", "Apache Tomcat"),
        ("jetty", "Jetty"),
    ],
    "X-Powered-By": [
        ("php", "PHP"),
        ("asp.net", "ASP.NET"),
        ("express", "Express.js"),
        ("jsp", "JavaServer Pages"),
        ("servlet", "Java Servlet"),
    ],
    "X-Generator": [
        ("drupal", "Drupal"),
        ("joomla", "Joomla"),
        ("wordpress", "WordPress"),
    ],
    "X-AspNet-Version": [("any", "ASP.NET")],
    "X-Pingback": [("any", "WordPress")],
    "Set-Cookie": [
        ("sessionid", "Django"),
        ("laravel_session", "Laravel"),
        ("phpsessid", "PHP"),
        ("jsessionid", "Java Servlet"),
        ("asp.net_sessionid", "ASP.NET"),
        ("connect.sid", "Express.js"),
    ],
}

# Body-based signatures (looked at after headers).
BODY_SIGNATURES: List[tuple[str, str]] = [
    (r"wp-content/", "WordPress"),
    (r"wp-includes/", "WordPress"),
    (r"/sites/all/themes/", "Drupal"),
    (r"cdn.jsdelivr.net/npm/jquery", "jQuery (CDN)"),
    (r"bootstrap(\.min)?\.css", "Bootstrap"),
    (r"react(\.production|\.development)?\.min\.js", "React"),
    (r"vue(\.runtime|\.global)?\.min\.js", "Vue.js"),
    (r"angular(\.min)?\.js", "AngularJS"),
    (r"next\.js", "Next.js"),
    (r"__next", "Next.js"),
    (r"nuxt", "Nuxt.js"),
    (r"<meta name=\"generator\" content=\"(?P<g>[^\"]+)\"", "Generator meta tag"),
]


class TechDetector:
    """Fingerprint a web application from its HTTP response headers and body."""

    def __init__(self, timeout: int = 10) -> None:
        self.timeout = timeout

    def detect(self, target: str) -> Dict[str, Any]:
        try:
            resp = requests.get(
                target,
                headers={"User-Agent": "PFE-CyberSec/1.0 (tech-detect)"},
                timeout=self.timeout,
                verify=False,
                allow_redirects=True,
            )
        except requests.RequestException as exc:
            return {"target": target, "error": str(exc), "technologies": []}

        technologies = self._detect_from_headers(resp.headers)
        technologies.extend(self._detect_from_body(resp.text))
        # Deduplicate while preserving order.
        seen: set[str] = set()
        unique: List[str] = []
        for tech in technologies:
            if tech not in seen:
                seen.add(tech)
                unique.append(tech)

        return {
            "target": target,
            "status_code": resp.status_code,
            "headers": dict(resp.headers),
            "technologies": unique,
            "count": len(unique),
        }

    # ------------------------------------------------------------------
    def _detect_from_headers(self, headers: Any) -> List[str]:
        if not hasattr(headers, "items"):
            return []
        found: List[str] = []
        for header, sigs in HEADER_SIGNATURES.items():
            # Headers are case-insensitive.
            value = headers.get(header) or headers.get(header.lower())
            if not value:
                continue
            v_lower = value.lower()
            for sig, tech in sigs:
                if sig == "any" or sig in v_lower:
                    found.append(tech)
        return found

    def _detect_from_body(self, body: str) -> List[str]:
        if not body:
            return []
        body_lower = body.lower()[:50000]
        found: List[str] = []
        for pattern, tech in BODY_SIGNATURES:
            if re.search(pattern, body_lower):
                found.append(tech)
        return found


__all__ = ["TechDetector", "HEADER_SIGNATURES", "BODY_SIGNATURES"]
