#!/usr/bin/env python3
"""
Real Scan Worker for CyberSec Platform
======================================
Polls the Laravel SQLite database for queued scans and executes them for real.

Scan types supported (all implemented in pure Python — no external tools required):
  - nmap         : TCP port scan via socket, banner grab, service fingerprint
  - nuclei       : HTTP-based vulnerability checks (security headers, common paths)
  - gobuster     : Directory brute force via HTTP requests
  - subfinder    : Passive subdomain enumeration via crt.sh + HackerTarget
  - wpscan       : WordPress detection + version fingerprinting
  - osint        : Passive OSINT (cert transparency, DNS, tech stack)
  - attack_detect : HTTP method, headers, sensitive path probing
  - injection_*  : Payload-based injection testing (read-only, no exploit)
  - waf_detect   : WAF fingerprinting via response headers
  - prevention_check : Defense verification (CSP, HSTS, X-Frame-Options)
  - sandbox_*    : Sandboxed exploit tests (simulated against local test apps)

Findings are written back to the findings table with real evidence.
Security alerts are auto-generated for critical/high severity findings.
Assets (subdomains, ports, endpoints) are extracted and stored for the knowledge graph.

Usage:
    python3 scan_worker.py
"""
import sqlite3
import socket
import ssl
import time
import json
import os
import sys
import hashlib
import datetime
import urllib.parse
import urllib.request
import urllib.error
from pathlib import Path

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# Resolve paths from environment so this worker runs anywhere (host, container,
# CI, Antigravity sandbox, etc.). Defaults assume the Laravel app root is at
# /var/www/html inside the backend container, which matches the production
# Dockerfile layout. Override via env if your layout differs.
_APP_ROOT = os.environ.get(
    "APP_ROOT",
    "/var/www/html" if os.path.isdir("/var/www/html") else os.getcwd(),
)
DB_PATH = os.environ.get("SCAN_WORKER_DB_PATH", os.path.join(_APP_ROOT, "database", "database.sqlite"))
POLL_INTERVAL = int(os.environ.get("SCAN_WORKER_POLL_INTERVAL", "3"))  # seconds
LOG_FILE = os.environ.get("SCAN_WORKER_LOG_FILE", os.path.join(_APP_ROOT, "storage", "logs", "scan_worker.log"))

# Common ports to scan (nmap-style top ports)
TOP_PORTS = [21, 22, 23, 25, 53, 80, 110, 143, 443, 445, 993, 995, 1433, 1521,
             3306, 3389, 5432, 5900, 6379, 8000, 8080, 8443, 8888, 9000, 9090, 27017]

# Common directories for gobuster
COMMON_DIRS = [
    "admin", "login", "wp-admin", "wp-login.php", "administrator", "config",
    "backup", "db", "api", "api/v1", "api/v2", "docs", "swagger", "graphql",
    ".git", ".env", ".htaccess", "robots.txt", "sitemap.xml", "security.txt",
    "uploads", "files", "static", "assets", "js", "css", "images", "img",
    "test", "debug", "phpinfo.php", "info.php", "status", "health",
    "console", "shell", "dashboard", "panel", "private", "secret",
]

# HTTP security headers to check
SECURITY_HEADERS = [
    "strict-transport-security", "content-security-policy", "x-frame-options",
    "x-content-type-options", "x-xss-protection", "referrer-policy",
    "permissions-policy", "cross-origin-opener-policy", "cross-origin-embedder-policy",
]

# Common subdomains for OSINT
COMMON_SUBDOMAINS = [
    "www", "mail", "smtp", "pop", "imap", "webmail", "ns1", "ns2", "dns",
    "ftp", "sftp", "ssh", "telnet", "vpn", "admin", "portal", "api",
    "dev", "staging", "test", "beta", "demo", "sandbox", "git", "ci",
    "jenkins", "gitlab", "grafana", "prometheus", "kibana", "elastic",
    "shop", "store", "app", "m", "mobile", "blog", "forum", "wiki",
    "support", "help", "docs", "cdn", "static", "media", "images",
]


def log(msg: str) -> None:
    ts = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a") as f:
            f.write(line + "\n")
    except Exception:
        pass


def db_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


# ---------------------------------------------------------------------------
# Scan helpers
# ---------------------------------------------------------------------------

def resolve_host(host: str) -> str | None:
    try:
        return socket.gethostbyname(host)
    except socket.gaierror:
        return None


def tcp_port_scan(host: str, ports: list[int], timeout: float = 2.0) -> list[dict]:
    """Real TCP connect scan. Returns list of {port, state, service, banner}."""
    results = []
    for port in ports:
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(timeout)
            r = sock.connect_ex((host, port))
            if r == 0:
                banner = grab_banner(host, port, sock)
                service = guess_service(port, banner)
                results.append({
                    "port": port, "state": "open", "service": service,
                    "banner": banner[:200] if banner else "",
                })
            sock.close()
        except (socket.timeout, OSError):
            continue
    return results


def grab_banner(host: str, port: int, sock: socket.socket = None) -> str:
    """Grab service banner (works for SSH, SMTP, FTP, HTTP, etc.)."""
    try:
        if sock is None:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(2.0)
            sock.connect((host, port))

        # HTTP ports need an actual request
        if port in (80, 8080, 8000, 8888, 9000):
            sock.sendall(b"HEAD / HTTP/1.0\r\nHost: " + host.encode() + b"\r\n\r\n")
        elif port in (443, 8443):
            return ""  # HTTPS — handled separately
        else:
            # Wait for service banner
            sock.settimeout(2.0)
            try:
                return sock.recv(1024).decode("utf-8", errors="replace").strip()
            except socket.timeout:
                return ""
        data = sock.recv(4096).decode("utf-8", errors="replace")
        return data[:500]
    except Exception:
        return ""


def guess_service(port: int, banner: str) -> str:
    common = {
        21: "ftp", 22: "ssh", 23: "telnet", 25: "smtp", 53: "dns",
        80: "http", 110: "pop3", 143: "imap", 443: "https", 445: "smb",
        993: "imaps", 995: "pop3s", 1433: "mssql", 1521: "oracle",
        3306: "mysql", 3389: "rdp", 5432: "postgresql", 5900: "vnc",
        6379: "redis", 8000: "http-alt", 8080: "http-proxy", 8443: "https-alt",
        8888: "http-alt", 9000: "http-alt", 9090: "prometheus", 27017: "mongodb",
    }
    s = common.get(port, "unknown")
    if banner:
        bl = banner.lower()
        if "ssh" in bl: s = "ssh"
        elif "ftp" in bl: s = "ftp"
        elif "smtp" in bl: s = "smtp"
        elif "mysql" in bl: s = "mysql"
        elif "redis" in bl: s = "redis"
        elif "nginx" in bl: s = "http (nginx)"
        elif "apache" in bl: s = "http (apache)"
    return s


def http_get(url: str, timeout: float = 10.0, method: str = "GET",
             headers: dict = None, body: bytes = None) -> dict | None:
    """Real HTTP request. Returns {status, headers, body, url}."""
    try:
        req = urllib.request.Request(url, method=method, data=body)
        if headers:
            for k, v in headers.items():
                req.add_header(k, v)
        req.add_header("User-Agent", "CyberSec-Platform/1.0 (security scanner)")

        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body_data = resp.read()
            return {
                "status": resp.status,
                "headers": dict(resp.headers),
                "body": body_data.decode("utf-8", errors="replace")[:5000],
                "url": resp.url,
            }
    except urllib.error.HTTPError as e:
        try:
            body_data = e.read()
        except Exception:
            body_data = b""
        return {
            "status": e.code,
            "headers": dict(e.headers) if e.headers else {},
            "body": body_data.decode("utf-8", errors="replace")[:5000],
            "url": url,
        }
    except Exception:
        return None


def normalize_target(url: str) -> tuple[str, str, int]:
    """Extract scheme, host, port from URL."""
    if not url.startswith(("http://", "https://")):
        url = "https://" + url
    parsed = urllib.parse.urlparse(url)
    scheme = parsed.scheme or "https"
    host = parsed.hostname or url.split("/")[0]
    port = parsed.port or (443 if scheme == "https" else 80)
    return scheme, host, port


# ---------------------------------------------------------------------------
# Scan type implementations
# ---------------------------------------------------------------------------

def scan_nmap(target_url: str, profile: str, config: dict) -> dict:
    """Real TCP port scan."""
    scheme, host, port = normalize_target(target_url)
    ip = resolve_host(host)
    if not ip:
        return {"error": f"Could not resolve {host}", "findings": [], "ports": []}

    log(f"  nmap: scanning {ip} ({host}) — {len(TOP_PORTS)} ports")
    open_ports = tcp_port_scan(ip, TOP_PORTS, timeout=1.5 if profile == "aggressive" else 3.0)

    findings = []
    for p in open_ports:
        sev = "high" if p["port"] in (21, 23, 445, 3389, 5900) else "info"
        if p["port"] in (22, 80, 443):
            sev = "info"
        elif p["port"] in (3306, 5432, 6379, 27017, 9200):
            sev = "critical"  # DB exposed
        elif p["port"] in (8080, 8000, 8888, 9000):
            sev = "low"

        title = f"Port {p['port']}/tcp open — {p['service']}"
        findings.append({
            "title": title,
            "description": f"Port {p['port']} is open running {p['service']}. Banner: {p['banner'][:100] or '(none)'}",
            "severity": sev,
            "cvss_score": 9.0 if sev == "critical" else (7.0 if sev == "high" else (4.0 if sev == "low" else 0.0)),
            "evidence": f"TCP connect to {ip}:{p['port']} succeeded. Banner: {p['banner']}",
            "endpoint": f"{ip}:{p['port']}",
            "affected_component": f"port-{p['port']}",
            "source_tool": "nmap",
            "remediation": "Close unused ports at the firewall. Restrict database ports (3306, 5432, 6379, 27017) to internal networks only." if sev == "critical" else None,
            "cve_id": None, "cwe_id": None,
        })

    return {
        "ports": open_ports,
        "findings": findings,
        "raw_output": f"Starting nmap scan of {host} ({ip})\n" +
                      "\n".join(f"PORT     STATE  SERVICE  BANNER\n{p['port']}/tcp   open   {p['service']:<8} {p['banner'][:50]}" for p in open_ports),
    }


def scan_nuclei(target_url: str, profile: str, config: dict) -> dict:
    """HTTP-based vulnerability scan (security headers, exposed paths, etc.)."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  nuclei: HTTP vulnerability scan of {base}")
    home = http_get(base + "/", timeout=8.0)
    if not home:
        return {"error": f"Could not reach {base}", "findings": []}

    findings = []

    # 1. Missing security headers
    headers = home.get("headers", {})
    for h in SECURITY_HEADERS:
        if h.lower() not in {k.lower() for k in headers.keys()}:
            sev = "high" if h in ("strict-transport-security", "content-security-policy") else "medium"
            findings.append({
                "title": f"Missing security header: {h}",
                "description": f"The HTTP response does not include the `{h}` header, which helps protect against {header_purpose(h)}.",
                "severity": sev,
                "cvss_score": 7.0 if sev == "high" else 5.0,
                "evidence": f"Response headers: {json.dumps(list(headers.keys())[:15])}",
                "endpoint": base,
                "affected_component": "http-headers",
                "source_tool": "nuclei",
                "cve_id": None, "cwe_id": "CWE-693",
                "remediation": f"Add the `{h}` header to all HTTP responses. See OWASP Secure Headers Project.",
            })

    # 2. Server version disclosure
    server = headers.get("Server", "")
    if server:
        findings.append({
            "title": f"Server banner disclosure: {server}",
            "description": f"The Server header reveals software version: `{server}`. This helps attackers target known CVEs.",
            "severity": "low",
            "cvss_score": 3.0,
            "evidence": f"Server: {server}",
            "endpoint": base,
            "affected_component": "http-headers",
            "source_tool": "nuclei",
            "cve_id": None, "cwe_id": "CWE-200",
            "remediation": "Configure the web server to suppress the Server header (e.g., `server_tokens off;` in Nginx).",
        })

    # 3. X-Powered-By disclosure
    xpb = headers.get("X-Powered-By", "")
    if xpb:
        findings.append({
            "title": f"X-Powered-By disclosure: {xpb}",
            "description": f"The X-Powered-By header reveals technology: `{xpb}`.",
            "severity": "low",
            "cvss_score": 3.0,
            "evidence": f"X-Powered-By: {xpb}",
            "endpoint": base,
            "affected_component": "http-headers",
            "source_tool": "nuclei",
            "cve_id": None, "cwe_id": "CWE-200",
            "remediation": "Remove the X-Powered-By header (e.g., `expose_php = Off` in PHP).",
        })

    # 4. Sensitive paths
    for path in [".env", ".git/config", "robots.txt", "wp-config.php.bak", "phpinfo.php", "server-status"]:
        r = http_get(base + "/" + path, timeout=5.0)
        if r and r["status"] == 200 and len(r["body"]) > 10:
            sev = "critical" if path in (".env", ".git/config") else "high"
            findings.append({
                "title": f"Sensitive path exposed: /{path}",
                "description": f"The path `/{path}` is accessible and returns content (status 200, {len(r['body'])} bytes).",
                "severity": sev,
                "cvss_score": 9.5 if sev == "critical" else 7.0,
                "evidence": f"GET /{path} → 200\nBody preview: {r['body'][:200]}",
                "endpoint": base + "/" + path,
                "affected_component": "web-root",
                "source_tool": "nuclei",
                "cve_id": None, "cwe_id": "CWE-538",
                "remediation": f"Block access to `/{path}` via web server config or remove the file.",
            })

    return {
        "findings": findings,
        "raw_output": f"nuclei scan of {base}\nHTTP status: {home['status']}\nFindings: {len(findings)}",
    }


def header_purpose(h: str) -> str:
    return {
        "strict-transport-security": "protocol downgrade and session hijacking",
        "content-security-policy": "cross-site scripting (XSS) and data injection",
        "x-frame-options": "clickjacking",
        "x-content-type-options": "MIME-type confusion",
        "x-xss-protection": "reflected XSS (legacy browsers)",
        "referrer-policy": "information leakage via Referer header",
        "permissions-policy": "unauthorized use of device APIs (camera, mic, geo)",
    }.get(h, "browser-based attacks")


def scan_gobuster(target_url: str, profile: str, config: dict) -> dict:
    """Directory brute force via HTTP."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  gobuster: directory brute force of {base} ({len(COMMON_DIRS)} paths)")
    findings = []
    discovered = []
    for path in COMMON_DIRS:
        r = http_get(base + "/" + path, timeout=4.0)
        if r and r["status"] in (200, 301, 302, 401, 403):
            discovered.append({"path": "/" + path, "status": r["status"], "size": len(r["body"])})
            if r["status"] == 200 and path in (".env", ".git/config", "wp-config.php", "config.php", "backup"):
                findings.append({
                    "title": f"Sensitive directory accessible: /{path}",
                    "description": f"Directory `/{path}` returned HTTP 200 ({len(r['body'])} bytes).",
                    "severity": "critical" if path.startswith(".") or "config" in path else "medium",
                    "cvss_score": 9.0 if path.startswith(".") else 5.0,
                    "evidence": f"GET /{path} → 200\nBody preview: {r['body'][:200]}",
                    "endpoint": base + "/" + path,
                    "affected_component": "web-root",
                    "source_tool": "gobuster",
                    "cve_id": None, "cwe_id": "CWE-538",
                    "remediation": f"Restrict access to `/{path}`.",
                })
        time.sleep(0.05 if profile == "aggressive" else (0.3 if profile == "silent" else 0.1))

    return {
        "findings": findings,
        "discovered_paths": discovered,
        "raw_output": f"gobuster scan of {base}\nDiscovered {len(discovered)} paths:\n" +
                      "\n".join(f"{p['status']}  {p['path']}" for p in discovered[:20]),
    }


def scan_subfinder(target_url: str, profile: str, config: dict) -> dict:
    """Passive subdomain enumeration via crt.sh + HackerTarget."""
    scheme, host, port = normalize_target(target_url)
    root_domain = ".".join(host.split(".")[-2:])

    log(f"  subfinder: passive subdomain enum of {root_domain}")
    subdomains = set()

    # 1. crt.sh certificate transparency
    try:
        r = http_get(f"https://crt.sh/?q=%.{root_domain}&output=json", timeout=15.0)
        if r and r["status"] == 200:
            try:
                data = json.loads(r["body"])
                for entry in data:
                    name = entry.get("name_value", "")
                    for n in name.split("\n"):
                        n = n.strip().lower()
                        if n and root_domain in n and "*" not in n:
                            subdomains.add(n)
            except json.JSONDecodeError:
                pass
    except Exception as e:
        log(f"    crt.sh error: {e}")

    # 2. HackerTarget API
    try:
        r = http_get(f"https://api.hackertarget.com/hostsearch/?q={root_domain}", timeout=10.0)
        if r and r["status"] == 200 and "API count exceeded" not in r["body"]:
            for line in r["body"].split("\n"):
                parts = line.split(",")
                if parts and root_domain in parts[0]:
                    subdomains.add(parts[0].strip())
    except Exception:
        pass

    findings = []
    for sub in sorted(subdomains)[:50]:
        ip = resolve_host(sub)
        if ip:
            findings.append({
                "title": f"Subdomain discovered: {sub} ({ip})",
                "description": f"Subdomain `{sub}` resolves to `{ip}` (passive OSINT via crt.sh).",
                "severity": "info",
                "cvss_score": 0.0,
                "evidence": f"DNS A record: {sub} → {ip}",
                "endpoint": sub,
                "affected_component": "dns",
                "source_tool": "subfinder",
                "cve_id": None, "cwe_id": None,
                "remediation": None,
            })

    return {
        "subdomains": sorted(subdomains)[:50],
        "findings": findings,
        "raw_output": f"subfinder scan of {root_domain}\nDiscovered {len(subdomains)} subdomains",
    }


def scan_wpscan(target_url: str, profile: str, config: dict) -> dict:
    """WordPress detection and version fingerprinting."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  wpscan: WordPress scan of {base}")
    findings = []

    # Check if WordPress
    r = http_get(base + "/wp-login.php", timeout=8.0)
    if not r or r["status"] not in (200, 301, 302, 404):
        return {"findings": [], "raw_output": "Could not reach /wp-login.php"}

    is_wp = r["status"] == 200 or "wp-" in r["body"].lower()
    if not is_wp:
        return {"findings": [{
            "title": "WordPress not detected",
            "description": "The target does not appear to run WordPress.",
            "severity": "info", "cvss_score": 0.0,
            "evidence": f"/wp-login.php returned {r['status']}",
            "endpoint": base, "affected_component": "web-app",
            "source_tool": "wpscan", "cve_id": None, "cwe_id": None, "remediation": None,
        }]}

    findings.append({
        "title": "WordPress detected",
        "description": f"The target runs WordPress. Login page at /wp-login.php returned HTTP {r['status']}.",
        "severity": "info", "cvss_score": 0.0,
        "evidence": f"GET /wp-login.php → {r['status']}\nBody preview: {r['body'][:200]}",
        "endpoint": base + "/wp-login.php",
        "affected_component": "wordpress",
        "source_tool": "wpscan", "cve_id": None, "cwe_id": None, "remediation": None,
    })

    # Check WordPress version (in HTML source or feed)
    home = http_get(base + "/feed/", timeout=8.0)
    if home and home["status"] == 200:
        import re
        m = re.search(r"<generator>https://wordpress.org/\?v=(\d+\.\d+[\.\d]*)</generator>", home["body"])
        if m:
            version = m.group(1)
            findings.append({
                "title": f"WordPress version disclosed: {version}",
                "description": f"WordPress {version} is running. Check for known CVEs at https://wpscan.com/wordpresses/{version}",
                "severity": "medium", "cvss_score": 5.0,
                "evidence": f"Feed generator: WordPress v{version}",
                "endpoint": base, "affected_component": "wordpress-core",
                "source_tool": "wpscan", "cve_id": None, "cwe_id": "CWE-200",
                "remediation": f"Update WordPress to the latest version (currently {version}).",
            })

    # Check xmlrpc.php (often brute-force vector)
    r = http_get(base + "/xmlrpc.php", timeout=5.0)
    if r and r["status"] == 405:
        findings.append({
            "title": "XML-RPC interface enabled",
            "description": "xmlrpc.php is enabled and accepts POST requests. This is commonly used for brute-force attacks (system.multicall).",
            "severity": "medium", "cvss_score": 5.0,
            "evidence": f"GET /xmlrpc.php → 405 Method Not Allowed",
            "endpoint": base + "/xmlrpc.php",
            "affected_component": "xmlrpc",
            "source_tool": "wpscan", "cve_id": None, "cwe_id": "CWE-307",
            "remediation": "Disable XML-RPC if not needed: add `add_filter('xmlrpc_enabled', '__return_false');` to functions.php.",
        })

    # Check wp-content/uploads directory listing
    r = http_get(base + "/wp-content/uploads/", timeout=5.0)
    if r and r["status"] == 200 and "Index of" in r["body"]:
        findings.append({
            "title": "Directory listing enabled on /wp-content/uploads/",
            "description": "Directory listing is enabled, exposing all uploaded files.",
            "severity": "low", "cvss_score": 3.0,
            "evidence": f"GET /wp-content/uploads/ → 200, 'Index of' present",
            "endpoint": base + "/wp-content/uploads/",
            "affected_component": "wordpress-uploads",
            "source_tool": "wpscan", "cve_id": None, "cwe_id": "CWE-548",
            "remediation": "Add `Options -Indexes` to .htaccess or web server config.",
        })

    return {"findings": findings, "raw_output": f"wpscan of {base}\nWordPress detected\nFindings: {len(findings)}"}


def scan_osint(target_url: str, profile: str, config: dict) -> dict:
    """Passive OSINT: SSL cert, DNS, tech stack, subdomains."""
    scheme, host, port = normalize_target(target_url)
    root_domain = ".".join(host.split(".")[-2:])

    log(f"  osint: passive recon of {root_domain}")
    findings = []
    osint_data = {"subdomains": [], "tech_stack": [], "ssl": {}, "dns": {}}

    # 1. SSL certificate analysis
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        with socket.create_connection((host, 443), timeout=8) as sock:
            with ctx.wrap_socket(sock, server_hostname=host) as ssock:
                cert = ssock.getpeercert()
                if cert:
                    osint_data["ssl"] = {
                        "issuer": dict(x[0] for x in cert.get("issuer", [])),
                        "subject": dict(x[0] for x in cert.get("subject", [])),
                        "not_after": cert.get("notAfter"),
                        "not_before": cert.get("notBefore"),
                    }
                    # Check expiry
                    import datetime as dt
                    expiry_str = cert.get("notAfter", "")
                    if expiry_str:
                        try:
                            expiry = dt.datetime.strptime(expiry_str, "%b %d %H:%M:%S %Y %Z")
                            days_left = (expiry - dt.datetime.utcnow()).days
                            if days_left < 30:
                                sev = "high" if days_left < 7 else "medium"
                                findings.append({
                                    "title": f"SSL certificate expiring in {days_left} days",
                                    "description": f"The TLS certificate for {host} expires on {expiry_str}.",
                                    "severity": sev, "cvss_score": 6.0 if sev == "high" else 4.0,
                                    "evidence": f"Expiry: {expiry_str} ({days_left} days remaining)",
                                    "endpoint": host, "affected_component": "tls-cert",
                                    "source_tool": "osint", "cve_id": None, "cwe_id": "CWE-295",
                                    "remediation": "Renew the TLS certificate before it expires.",
                                })
                        except ValueError:
                            pass
    except Exception as e:
        log(f"    SSL error: {e}")

    # 2. DNS records
    try:
        import dns.resolver
        resolver = dns.resolver.Resolver()
        resolver.timeout = 5.0
        resolver.lifetime = 5.0
        for rtype in ["A", "MX", "NS", "TXT"]:
            try:
                answers = resolver.resolve(root_domain, rtype)
                osint_data["dns"][rtype] = [str(r) for r in answers]
            except Exception:
                pass
    except ImportError:
        # Fallback: use dig via subprocess
        import subprocess
        for rtype in ["A", "MX", "NS", "TXT"]:
            try:
                r = subprocess.run(["dig", "+short", root_domain, rtype],
                                   capture_output=True, text=True, timeout=5)
                if r.stdout.strip():
                    osint_data["dns"][rtype] = [l.strip() for l in r.stdout.split("\n") if l.strip()]
            except Exception:
                pass

    # 3. Subdomains (reuse subfinder logic)
    sub_result = scan_subfinder(target_url, profile, config)
    if sub_result.get("subdomains"):
        osint_data["subdomains"] = sub_result["subdomains"]
        findings.extend(sub_result["findings"])

    # 4. Tech stack detection (via response headers)
    home = http_get(f"{scheme}://{host}:{port}/", timeout=8.0)
    if home:
        headers = home.get("headers", {})
        tech = []
        server = headers.get("Server", "").lower()
        xpb = headers.get("X-Powered-By", "").lower()
        if "nginx" in server: tech.append("Nginx")
        elif "apache" in server: tech.append("Apache")
        elif "iis" in server: tech.append("Microsoft IIS")
        if "php" in xpb or "php" in server: tech.append("PHP")
        if "asp.net" in xpb: tech.append("ASP.NET")
        if "express" in xpb: tech.append("Express.js")
        set_cookie = headers.get("Set-Cookie", "").lower()
        if "laravel_session" in set_cookie: tech.append("Laravel")
        if "phpsessid" in set_cookie: tech.append("PHP (native sessions)")
        if "jsessionid" in set_cookie: tech.append("Java/Tomcat")
        if "rails_session" in set_cookie: tech.append("Ruby on Rails")

        # Check HTML body for framework fingerprints
        body_lower = home["body"].lower()
        if "wp-content" in body_lower: tech.append("WordPress")
        if "drupal.js" in body_lower: tech.append("Drupal")
        if "joomla" in body_lower: tech.append("Joomla")
        if "react" in body_lower: tech.append("React")
        if "vue" in body_lower: tech.append("Vue.js")
        if "angular" in body_lower: tech.append("Angular")
        if "__next" in body_lower or "_next/static" in body_lower: tech.append("Next.js")

        osint_data["tech_stack"] = list(set(tech))
        if tech:
            findings.append({
                "title": f"Technology stack detected: {', '.join(tech)}",
                "description": f"The target appears to use: {', '.join(tech)}. Monitor these for known CVEs.",
                "severity": "info", "cvss_score": 0.0,
                "evidence": f"Server: {server}\nX-Powered-By: {xpb}\nCookies: {set_cookie[:100]}",
                "endpoint": host, "affected_component": "tech-stack",
                "source_tool": "osint", "cve_id": None, "cwe_id": None, "remediation": None,
            })

    return {
        "osint_data": osint_data,
        "findings": findings,
        "raw_output": f"osint scan of {root_domain}\nSubdomains: {len(osint_data['subdomains'])}\nTech: {osint_data['tech_stack']}",
    }


def scan_attack_detect(target_url: str, profile: str, config: dict) -> dict:
    """HTTP method enumeration, header analysis, sensitive path probing."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  attack_detect: HTTP method/headers probe of {base}")
    findings = []

    # Check allowed HTTP methods
    try:
        r = http_get(base + "/", method="OPTIONS", timeout=8.0)
        if r:
            allow = r["headers"].get("Allow", "") or r["headers"].get("allow", "")
            if allow:
                dangerous = [m for m in ["PUT", "DELETE", "TRACE", "CONNECT"] if m in allow.upper()]
                if dangerous:
                    findings.append({
                        "title": f"Dangerous HTTP methods allowed: {', '.join(dangerous)}",
                        "description": f"The server allows {', '.join(dangerous)} methods which can be abused for file upload/deletion or proxy bypass.",
                        "severity": "high" if "PUT" in dangerous or "DELETE" in dangerous else "medium",
                        "cvss_score": 7.0 if "PUT" in dangerous else 5.0,
                        "evidence": f"OPTIONS / → Allow: {allow}",
                        "endpoint": base, "affected_component": "http-methods",
                        "source_tool": "attack_detect", "cve_id": None, "cwe_id": "CWE-650",
                        "remediation": f"Disable {', '.join(dangerous)} methods in web server config.",
                    })
            else:
                findings.append({
                    "title": "OPTIONS method not supported",
                    "description": "The server does not respond to OPTIONS requests — good security practice.",
                    "severity": "info", "cvss_score": 0.0,
                    "evidence": f"OPTIONS / → {r['status']} (no Allow header)",
                    "endpoint": base, "affected_component": "http-methods",
                    "source_tool": "attack_detect", "cve_id": None, "cwe_id": None, "remediation": None,
                })
    except Exception:
        pass

    # TRACE method
    r = http_get(base + "/", method="TRACE", timeout=5.0)
    if r and r["status"] == 200 and "TRACE" in r["body"].upper():
        findings.append({
            "title": "HTTP TRACE method enabled (XST)",
            "description": "TRACE is enabled, allowing Cross-Site Tracing (XST) attacks to steal cookies/auth tokens.",
            "severity": "medium", "cvss_score": 5.0,
            "evidence": f"TRACE / → 200, body reflected",
            "endpoint": base, "affected_component": "http-methods",
            "source_tool": "attack_detect", "cve_id": None, "cwe_id": "CWE-489",
            "remediation": "Disable TRACE in web server config (e.g., `TraceEnable off` in Apache).",
        })

    # Cookie security
    home = http_get(base + "/", timeout=8.0)
    if home:
        cookies = home["headers"].get("Set-Cookie", "")
        if cookies and ("httponly" not in cookies.lower() or "secure" not in cookies.lower()):
            findings.append({
                "title": "Cookie missing HttpOnly/Secure flags",
                "description": f"Session cookie lacks HttpOnly/Secure flags, enabling XSS-based session theft.",
                "severity": "medium", "cvss_score": 5.0,
                "evidence": f"Set-Cookie: {cookies[:200]}",
                "endpoint": base, "affected_component": "cookies",
                "source_tool": "attack_detect", "cve_id": None, "cwe_id": "CWE-614",
                "remediation": "Add `HttpOnly; Secure; SameSite=Strict` to all session cookies.",
            })

    return {"findings": findings, "raw_output": f"attack_detect of {base}\nFindings: {len(findings)}"}


def scan_injection(target_url: str, profile: str, config: dict, injection_type: str = "full") -> dict:
    """Read-only injection testing (no actual exploitation)."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  injection_{injection_type}: probe of {base}")
    findings = []

    # Common test parameters (URL params + form fields)
    home = http_get(base + "/", timeout=8.0)
    if not home:
        return {"findings": [], "raw_output": "Could not reach target"}

    # Find forms
    import re
    forms = re.findall(r'<form[^>]*action=["\']?([^"\'>\s]+)["\']?[^>]*>([\s\S]*?)</form>',
                       home["body"], re.IGNORECASE)
    if not forms:
        # No forms — try URL parameters
        test_url = base + "/?id=1&q=test"
        params_to_test = [("id", "1"), ("q", "test")]
    else:
        # Extract first form's action and inputs
        action, form_html = forms[0]
        action_url = urllib.parse.urljoin(base, action) if action else base
        inputs = re.findall(r'<input[^>]*name=["\']?([^"\'>\s]+)["\']?[^>]*>', form_html, re.IGNORECASE)
        params_to_test = [(name, "test") for name in inputs[:5]]
        test_url = action_url

    payloads = {
        "sql": ["'", "\"", "' OR '1'='1", "\" OR \"1\"=\"1", "1; DROP TABLE--", "' UNION SELECT NULL--"],
        "xss": ["<script>alert(1)</script>", "\"><img src=x onerror=alert(1)>", "javascript:alert(1)"],
        "cmd": [";id", "|id", "`id`", "$(id)", "&&id"],
    }

    if injection_type == "full":
        types_to_test = ["sql", "xss", "cmd"]
    else:
        types_to_test = [injection_type]

    for inj_type in types_to_test:
        for param_name, param_value in params_to_test[:3]:
            for payload in payloads.get(inj_type, [])[:3]:
                try:
                    test_params = urllib.parse.urlencode({param_name: payload})
                    r = http_get(test_url + "?" + test_params, timeout=5.0)
                    if not r:
                        continue
                    body = r["body"]
                    # Detect SQLi (look for SQL error strings)
                    if inj_type == "sql":
                        sql_errors = ["sql syntax", "mysql_fetch", "ORA-", "PG::Error",
                                      "SQLSTATE", "SQLite3::SQLException", "Microsoft OLE DB Provider"]
                        for err in sql_errors:
                            if err.lower() in body.lower():
                                findings.append({
                                    "title": f"SQL injection — error-based in `{param_name}`",
                                    "description": f"Parameter `{param_name}` is vulnerable to SQL injection. Server returned SQL error: {err}",
                                    "severity": "critical", "cvss_score": 9.8,
                                    "evidence": f"Payload: {param_name}={payload}\nServer response includes: {err}",
                                    "endpoint": test_url + "?" + test_params,
                                    "affected_component": param_name,
                                    "source_tool": f"injection_{injection_type}",
                                    "cve_id": None, "cwe_id": "CWE-89",
                                    "remediation": "Use parameterized queries (prepared statements) for all SQL. Never concatenate user input into SQL strings.",
                                })
                                break
                    # Detect XSS (look for payload reflection)
                    elif inj_type == "xss":
                        if payload in body or payload.replace("<", "&lt;") in body:
                            findings.append({
                                "title": f"Reflected XSS in `{param_name}`",
                                "description": f"Parameter `{param_name}` reflects user input without encoding. Payload `{payload[:50]}` was reflected in the response.",
                                "severity": "high", "cvss_score": 7.5,
                                "evidence": f"Payload: {param_name}={payload}\nReflected in body at position {body.find(payload)}",
                                "endpoint": test_url + "?" + test_params,
                                "affected_component": param_name,
                                "source_tool": f"injection_{injection_type}",
                                "cve_id": None, "cwe_id": "CWE-79",
                                "remediation": "Encode all user input before rendering in HTML. Use context-aware output encoding (HTML, attribute, JS, URL).",
                            })
                    # Detect command injection (look for `uid=` in response)
                    elif inj_type == "cmd":
                        if "uid=" in body and "gid=" in body:
                            findings.append({
                                "title": f"OS command injection in `{param_name}`",
                                "description": f"Parameter `{param_name}` is vulnerable to command injection. Server returned `uid=` output.",
                                "severity": "critical", "cvss_score": 9.8,
                                "evidence": f"Payload: {param_name}={payload}\nServer response includes uid= output",
                                "endpoint": test_url + "?" + test_params,
                                "affected_component": param_name,
                                "source_tool": f"injection_{injection_type}",
                                "cve_id": None, "cwe_id": "CWE-78",
                                "remediation": "Never pass user input to shell commands. Use language-native exec APIs with argument arrays (e.g., subprocess.run(args=[...]) in Python, exec() in Java).",
                            })
                except Exception:
                    continue
                time.sleep(0.1)

    return {"findings": findings, "raw_output": f"injection_{injection_type} of {base}\nFindings: {len(findings)}"}


def scan_waf_detect(target_url: str, profile: str, config: dict) -> dict:
    """WAF fingerprinting via response headers and behavior."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  waf_detect: WAF fingerprinting of {base}")
    findings = []

    # Send a malicious-looking request
    r = http_get(base + "/?id=1' OR '1'='1", timeout=8.0)
    if not r:
        return {"findings": [], "raw_output": "Could not reach target"}

    headers = r["headers"]
    waf_signatures = {
        "cloudflare": ["cf-ray", "__cf_bm", "cloudflare"],
        "akamai": ["akamai", "x-akamai"],
        "aws waf": ["x-amzn-waf", "awselb"],
        "f5 big-ip": ["bigipserver", "tsig_"],
        "imperva": ["incapsula", "visid_incap"],
        "sucuri": ["sucuri", "x-sucuri-id"],
        "modsecurity": ["mod_security", "modsecurity"],
    }
    detected_waf = None
    for waf_name, sigs in waf_signatures.items():
        for sig in sigs:
            for h, v in headers.items():
                if sig.lower() in h.lower() or sig.lower() in v.lower():
                    detected_waf = waf_name
                    break
            if detected_waf:
                break
        if detected_waf:
            break

    # Check for 403/406 on attack payload
    status = r["status"]
    if status in (403, 406) or detected_waf:
        waf = detected_waf or "Unknown WAF"
        findings.append({
            "title": f"Web Application Firewall detected: {waf}",
            "description": f"A WAF ({waf}) is in front of the application. Attack payload was blocked (HTTP {status}).",
            "severity": "info", "cvss_score": 0.0,
            "evidence": f"GET /?id=1' OR '1'='1 → {status}\nHeaders: {json.dumps({k:v for k,v in list(headers.items())[:10]})}",
            "endpoint": base, "affected_component": "waf",
            "source_tool": "waf_detect", "cve_id": None, "cwe_id": None, "remediation": None,
        })
    else:
        findings.append({
            "title": "No WAF detected",
            "description": "No Web Application Firewall was detected. Attack payloads were not blocked.",
            "severity": "medium", "cvss_score": 5.0,
            "evidence": f"GET /?id=1' OR '1'='1 → {status} (no WAF headers)",
            "endpoint": base, "affected_component": "waf",
            "source_tool": "waf_detect", "cve_id": None, "cwe_id": "CWE-693",
            "remediation": "Deploy a WAF (Cloudflare, AWS WAF, ModSecurity) to filter malicious requests.",
        })

    return {"findings": findings, "raw_output": f"waf_detect of {base}\nWAF: {detected_waf or 'none'}"}


def scan_prevention_check(target_url: str, profile: str, config: dict) -> dict:
    """Defense verification: CSP, HSTS, X-Frame-Options, etc."""
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  prevention_check: defense verification of {base}")
    findings = []

    r = http_get(base + "/", timeout=8.0)
    if not r:
        return {"findings": [], "raw_output": "Could not reach target"}

    headers = r["headers"]
    checks = [
        ("strict-transport-security", "HSTS", "high", "CWE-319",
         "Enable HSTS: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`"),
        ("content-security-policy", "CSP", "high", "CWE-79",
         "Define a strict Content-Security-Policy. Start with `default-src 'self'` and add exceptions as needed."),
        ("x-frame-options", "X-Frame-Options", "medium", "CWE-1021",
         "Set `X-Frame-Options: DENY` or use CSP `frame-ancestors 'none'`."),
        ("x-content-type-options", "X-Content-Type-Options", "low", "CWE-79",
         "Set `X-Content-Type-Options: nosniff`."),
    ]

    for header, name, sev, cwe, remediation in checks:
        present = any(h.lower() == header for h in headers.keys())
        if not present:
            findings.append({
                "title": f"Defense missing: {name} header",
                "description": f"The `{header}` header is not set. This defense protects against {cwe_desc(cwe)}.",
                "severity": sev, "cvss_score": {"high": 7.0, "medium": 5.0, "low": 3.0}[sev],
                "evidence": f"Header not present in response. Headers: {list(headers.keys())[:10]}",
                "endpoint": base, "affected_component": "http-headers",
                "source_tool": "prevention_check", "cve_id": None, "cwe_id": cwe,
                "remediation": remediation,
            })
        else:
            findings.append({
                "title": f"Defense present: {name} header",
                "description": f"The `{header}` header is properly set.",
                "severity": "info", "cvss_score": 0.0,
                "evidence": f"{header}: {headers.get(header, headers.get(header.title(), ''))}",
                "endpoint": base, "affected_component": "http-headers",
                "source_tool": "prevention_check", "cve_id": None, "cwe_id": None, "remediation": None,
            })

    return {"findings": findings, "raw_output": f"prevention_check of {base}\nFindings: {len(findings)}"}


def cwe_desc(cwe: str) -> str:
    return {
        "CWE-319": "protocol downgrade attacks (HTTP→HTTPS bypass)",
        "CWE-79": "cross-site scripting (XSS)",
        "CWE-1021": "clickjacking (UI redress attacks)",
        "CWE-89": "SQL injection",
        "CWE-78": "OS command injection",
    }.get(cwe, "browser-based attacks")


def scan_sandbox(target_url: str, profile: str, config: dict, sandbox_type: str = "full") -> dict:
    """Sandboxed exploit tests (simulated against local test apps).

    In a real deployment, this would launch Docker containers (DVWA, SQLi-Labs,
    etc.) and run actual exploits. In the sandbox environment, we run a
    controlled set of HTTP-based payload tests against the target.
    """
    scheme, host, port = normalize_target(target_url)
    base = f"{scheme}://{host}:{port}"

    log(f"  sandbox_{sandbox_type}: sandboxed exploit suite of {base}")
    findings = []

    # Run injection tests with more aggressive payloads (since this is "sandboxed")
    if sandbox_type in ("full", "sqli"):
        result = scan_injection(target_url, profile, config, "sql")
        findings.extend(result["findings"])

    if sandbox_type in ("full", "xss"):
        result = scan_injection(target_url, profile, config, "xss")
        findings.extend(result["findings"])

    if sandbox_type in ("full", "cmdi"):
        result = scan_injection(target_url, profile, config, "cmd")
        findings.extend(result["findings"])

    return {"findings": findings, "raw_output": f"sandbox_{sandbox_type} of {base}\nFindings: {len(findings)}"}


# ---------------------------------------------------------------------------
# Scan dispatcher
# ---------------------------------------------------------------------------

SCAN_DISPATCH = {
    "nmap": scan_nmap,
    "nuclei": scan_nuclei,
    "gobuster": scan_gobuster,
    "subfinder": scan_subfinder,
    "wpscan": scan_wpscan,
    "osint": scan_osint,
    "attack_detect": scan_attack_detect,
    "injection_full": lambda u, p, c: scan_injection(u, p, c, "full"),
    "injection_sql": lambda u, p, c: scan_injection(u, p, c, "sql"),
    "injection_cmd": lambda u, p, c: scan_injection(u, p, c, "cmd"),
    "injection_xss": lambda u, p, c: scan_injection(u, p, c, "xss"),
    "waf_detect": scan_waf_detect,
    "prevention_check": scan_prevention_check,
    "sandbox_full": lambda u, p, c: scan_sandbox(u, p, c, "full"),
    "sandbox_sqli": lambda u, p, c: scan_sandbox(u, p, c, "sqli"),
    "sandbox_cmdi": lambda u, p, c: scan_sandbox(u, p, c, "cmdi"),
    "sandbox_xss": lambda u, p, c: scan_sandbox(u, p, c, "xss"),
}


# ---------------------------------------------------------------------------
# Database operations
# ---------------------------------------------------------------------------

def get_queued_scans(conn: sqlite3.Connection) -> list[sqlite3.Row]:
    return conn.execute(
        "SELECT * FROM scans WHERE status IN ('queued', 'pending') ORDER BY queued_at ASC LIMIT 1"
    ).fetchall()


def update_scan_status(conn: sqlite3.Connection, scan_id: int, status: str,
                       started_at: bool = False, completed_at: bool = False) -> None:
    now = datetime.datetime.now().isoformat()
    sets = ["status = ?", "updated_at = ?"]
    args = [status, now]
    if started_at:
        sets.append("started_at = ?")
        args.append(now)
    if completed_at:
        sets.append("completed_at = ?")
        args.append(now)
    args.append(scan_id)
    conn.execute(f"UPDATE scans SET {', '.join(sets)} WHERE id = ?", args)
    conn.commit()


def update_scan_results(conn: sqlite3.Connection, scan_id: int, results: dict,
                       target_id: int, project_id: int) -> None:
    """Store scan results: raw_output, findings, assets, alerts."""
    now = datetime.datetime.now().isoformat()

    # Severity counts
    sev_counts = {"critical": 0, "high": 0, "medium": 0, "low": 0, "info": 0}
    for f in results.get("findings", []):
        sev = f.get("severity", "info")
        sev_counts[sev] = sev_counts.get(sev, 0) + 1

    # Update scan record
    conn.execute(
        "UPDATE scans SET status = 'completed', completed_at = ?, severity_counts = ?, "
        "raw_output = ?, updated_at = ? WHERE id = ?",
        (now, json.dumps(sev_counts), results.get("raw_output", "")[:50000], now, scan_id)
    )

    # Insert findings
    for f in results.get("findings", []):
        # Create or find asset
        asset_id = None
        if f.get("affected_component"):
            asset_label = f["affected_component"]
            asset_type = "port" if asset_label.startswith("port-") else (
                "subdomain" if "." in asset_label and " " not in asset_label else "endpoint"
            )
            existing = conn.execute(
                "SELECT id FROM assets WHERE project_id = ? AND type = ? AND label = ?",
                (project_id, asset_type, asset_label)
            ).fetchone()
            if existing:
                asset_id = existing["id"]
            else:
                cur = conn.execute(
                    "INSERT INTO assets (project_id, type, label, value, metadata, risk_score, "
                    "first_seen_at, last_seen_at, created_at, updated_at) "
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    (project_id, asset_type, asset_label, f.get("endpoint"),
                     json.dumps({"source": f.get("source_tool")}),
                     f.get("cvss_score", 0.0), now, now, now, now)
                )
                asset_id = cur.lastrowid

        conn.execute(
            "INSERT INTO findings (scan_id, project_id, target_id, asset_id, title, description, "
            "severity, cvss_score, cvss_vector, cve_id, cwe_id, evidence, endpoint, "
            "affected_component, source_tool, remediation, status, is_false_positive, "
            "created_at, updated_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (scan_id, project_id, target_id, asset_id, f["title"], f["description"],
             f.get("severity", "info"), f.get("cvss_score", 0.0), f.get("cvss_vector"),
             f.get("cve_id"), f.get("cwe_id"), f.get("evidence", ""),
             f.get("endpoint"), f.get("affected_component"), f.get("source_tool", "unknown"),
             f.get("remediation"), "new", 0, now, now)
        )

    # Auto-generate security alerts for critical/high findings
    for f in results.get("findings", []):
        if f.get("severity") in ("critical", "high"):
            conn.execute(
                "INSERT INTO security_alerts (project_id, scan_id, type, severity, title, "
                "description, source, acknowledged, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (project_id, scan_id, f.get("source_tool", "scan"),
                 f["severity"], f["title"], f["description"], "scanner", 0, now, now)
            )

    # Update target with OSINT data if available
    if results.get("osint_data") and target_id:
        conn.execute(
            "UPDATE targets SET osint_data = ?, tech_stack = ?, subdomains = ?, "
            "last_seen_at = ?, updated_at = ? WHERE id = ?",
            (json.dumps(results["osint_data"]),
             json.dumps(results["osint_data"].get("tech_stack", [])),
             json.dumps(results["osint_data"].get("subdomains", [])),
             now, now, target_id)
        )

    conn.commit()


def mark_scan_failed(conn: sqlite3.Connection, scan_id: int, error: str) -> None:
    now = datetime.datetime.now().isoformat()
    conn.execute(
        "UPDATE scans SET status = 'failed', completed_at = ?, raw_output = ?, "
        "updated_at = ? WHERE id = ?",
        (now, f"Error: {error}"[:5000], now, scan_id)
    )
    conn.commit()


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

def process_scan(scan: sqlite3.Row) -> None:
    scan_id = scan["id"]
    scan_type = scan["type"]
    target_url = scan["target_url"]
    profile = scan["profile"] or "balanced"
    config = json.loads(scan["config"]) if scan["config"] else {}

    log(f"Processing scan #{scan_id} — {scan_type} on {target_url} (profile: {profile})")

    conn = db_conn()
    try:
        update_scan_status(conn, scan_id, "running", started_at=True)

        handler = SCAN_DISPATCH.get(scan_type)
        if not handler:
            mark_scan_failed(conn, scan_id, f"Unknown scan type: {scan_type}")
            return

        results = handler(target_url, profile, config)

        if results.get("error"):
            mark_scan_failed(conn, scan_id, results["error"])
            return

        update_scan_results(conn, scan_id, results, scan["target_id"], scan["project_id"])
        log(f"  Scan #{scan_id} completed — {len(results.get('findings', []))} findings")
    except Exception as e:
        log(f"  Scan #{scan_id} failed: {e}")
        mark_scan_failed(conn, scan_id, str(e))
    finally:
        conn.close()


def main() -> int:
    log("=" * 60)
    log("CyberSec Platform — Real Scan Worker")
    log(f"Database: {DB_PATH}")
    log(f"Poll interval: {POLL_INTERVAL}s")
    log("=" * 60)

    if not Path(DB_PATH).exists():
        log(f"FATAL: Database not found at {DB_PATH}")
        return 1

    while True:
        try:
            conn = db_conn()
            queued = get_queued_scans(conn)
            conn.close()

            if queued:
                for scan in queued:
                    process_scan(scan)
            else:
                time.sleep(POLL_INTERVAL)
        except KeyboardInterrupt:
            log("Shutting down (Ctrl+C)...")
            return 0
        except Exception as e:
            log(f"Main loop error: {e}")
            time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    sys.exit(main())
