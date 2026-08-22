"""Base scanner service with profile-based throttling, retries and subprocess safety.

Security critical:
- Targets are validated against a strict regex whitelist (URL / domain / IP).
- All subprocess calls use ``shell=False`` with list arguments to prevent
  command injection through user-supplied ``target`` values.
- Profile-based flag selection enforces CDC-mandated rate limits.
"""

from __future__ import annotations

import functools
import ipaddress
import logging
import os
import random
import re
import subprocess
import time
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, List, Optional, Tuple

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
# Restrictive character set for hostnames (RFC 1035 subset) + IPv4 + IPv6.
_DOMAIN_LABEL = r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
_DOMAIN_RE = re.compile(
    rf"^(?:{_DOMAIN_LABEL}\.)+[A-Za-z]{{2,}}$",
)
_IPV4_RE = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")
# Strip a scheme + optional port + path before validating host part.
_URL_RE = re.compile(
    r"^(?P<scheme>https?://)?(?P<host>[A-Za-z0-9.\-:]+)(?P<path>/[^\s]*)?$",
    re.IGNORECASE,
)
# Allowlist of characters permitted in the final host passed to subprocesses.
# Anything outside this set is rejected before the subprocess call.
_SAFE_HOST_RE = re.compile(r"^[A-Za-z0-9.\-:]+$")


def validate_target(target: str) -> str:
    """Validate a target string and return the cleaned host portion.

    Accepts:
      - IPv4 / IPv6 addresses
      - DNS hostnames
      - HTTP(S) URLs (scheme is stripped before passing to tools)

    Raises ``ValueError`` if the target does not match the whitelist.
    """
    if not target or not isinstance(target, str):
        raise ValueError("target must be a non-empty string")

    cleaned = target.strip().rstrip("/")
    match = _URL_RE.match(cleaned)
    if not match:
        raise ValueError(f"target does not match URL/host/IP whitelist: {target!r}")

    host = match.group("host")
    # Strip an optional :port suffix for the validation step (we keep it in
    # the returned value because some tools accept host:port).
    host_no_port = host.split(":", 1)[0] if ":" in host and not host.count(":") > 1 else host

    # IPv6 contains multiple colons; validate via stdlib.
    is_ipv6 = ":" in host and host.count(":") >= 2
    if is_ipv6:
        try:
            ipaddress.IPv6Address(host.strip("[]"))
        except ValueError as exc:
            raise ValueError(f"invalid IPv6 target: {target!r}") from exc
    elif _IPV4_RE.match(host_no_port):
        try:
            ipaddress.IPv4Address(host_no_port)
        except ValueError as exc:
            raise ValueError(f"invalid IPv4 target: {target!r}") from exc
    elif not _DOMAIN_RE.match(host_no_port):
        raise ValueError(f"target host failed regex whitelist: {host_no_port!r}")

    if not _SAFE_HOST_RE.match(host):
        raise ValueError(f"target contains disallowed characters: {host!r}")

    # Never allow shell metacharacters through even after validation.
    if any(ch in host for ch in (" ", ";", "|", "&", "`", "$", "(", ")", "{", "}", "<", ">", "\n", "\r", "\t")):
        raise ValueError(f"target contains shell metacharacters: {host!r}")

    # Return the host (without scheme) — tools receive a clean host.
    return host


# ---------------------------------------------------------------------------
# Profile definitions (per Final CDC)
# ---------------------------------------------------------------------------
@dataclass(frozen=True)
class ScanProfile:
    """Static configuration for one CDC scan profile."""

    name: str
    rate_limit_qps: int
    jitter_ms_min: int
    jitter_ms_max: int
    timeout_seconds: int
    description: str


PROFILES: Dict[str, ScanProfile] = {
    "silent": ScanProfile(
        name="silent",
        rate_limit_qps=2,
        jitter_ms_min=500,
        jitter_ms_max=2000,
        timeout_seconds=1200,
        description="Stealthy: 1-2 qps, high jitter, T2 timing",
    ),
    "balanced": ScanProfile(
        name="balanced",
        rate_limit_qps=8,
        jitter_ms_min=100,
        jitter_ms_max=500,
        timeout_seconds=600,
        description="Balanced: 5-10 qps, moderate jitter, T3 timing",
    ),
    "aggressive": ScanProfile(
        name="aggressive",
        rate_limit_qps=25,
        jitter_ms_min=0,
        jitter_ms_max=100,
        timeout_seconds=300,
        description="Aggressive: 20-30 qps, low jitter, T4 timing (internal only)",
    ),
}


def get_profile(name: str, overrides: Optional[Dict[str, Any]] = None) -> ScanProfile:
    """Return a profile, optionally overriding rate limit / jitter."""
    key = (name or "balanced").lower()
    if key not in PROFILES:
        raise ValueError(
            f"unknown profile {name!r}; allowed: {sorted(PROFILES.keys())}"
        )
    profile = PROFILES[key]
    if not overrides:
        return profile

    # Apply caller-provided overrides (jitter_ms, rate_limit_qps).
    rate = overrides.get("rate_limit_qps", profile.rate_limit_qps)
    jitter = overrides.get("jitter_ms")
    if isinstance(jitter, int) and jitter > 0:
        return ScanProfile(
            name=profile.name,
            rate_limit_qps=rate,
            jitter_ms_min=max(0, jitter // 2),
            jitter_ms_max=jitter,
            timeout_seconds=profile.timeout_seconds,
            description=profile.description,
        )
    return profile


# ---------------------------------------------------------------------------
# Retry decorator
# ---------------------------------------------------------------------------
def with_retry(max_attempts: int = 3, base_backoff: float = 1.0) -> Callable:
    """Retry a callable with exponential backoff (``2 ** attempt`` seconds)."""

    def decorator(func: Callable) -> Callable:
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            last_exc: Optional[BaseException] = None
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except subprocess.TimeoutExpired as exc:
                    last_exc = exc
                    logger.warning(
                        "timeout on attempt %d/%d for %s",
                        attempt, max_attempts, func.__name__,
                    )
                except subprocess.CalledProcessError as exc:
                    last_exc = exc
                    logger.warning(
                        "subprocess error on attempt %d/%d for %s: rc=%d",
                        attempt, max_attempts, func.__name__, exc.returncode,
                    )
                except Exception as exc:  # noqa: BLE001
                    last_exc = exc
                    logger.warning(
                        "error on attempt %d/%d for %s: %s",
                        attempt, max_attempts, func.__name__, exc,
                    )
                if attempt < max_attempts:
                    delay = base_backoff * (2 ** (attempt - 1))
                    time.sleep(delay)
            raise last_exc  # type: ignore[misc]

        return wrapper

    return decorator


# ---------------------------------------------------------------------------
# Base service
# ---------------------------------------------------------------------------
@dataclass
class ScanResult:
    """Normalized output of a single tool invocation."""

    tool: str
    target: str
    profile: str
    returncode: int
    stdout: str
    stderr: str
    duration_seconds: float
    command: List[str] = field(default_factory=list)
    error: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "tool": self.tool,
            "target": self.target,
            "profile": self.profile,
            "returncode": self.returncode,
            "stdout": self.stdout,
            "stderr": self.stderr,
            "duration_seconds": round(self.duration_seconds, 3),
            "command": self.command,
            "error": self.error,
        }


class BaseScannerService:
    """Common functionality for all tool services."""

    #: Tool identifier, overridden by subclasses.
    tool_name: str = "base"
    #: Absolute path to the binary; subclasses may override or rely on PATH.
    binary: str = ""
    #: Whether the tool requires network reachability to the target.
    requires_target: bool = True

    def __init__(self, config: Optional[Dict[str, Any]] = None) -> None:
        self.config = config or {}
        self.logger = logging.getLogger(f"recon.{self.tool_name}")

    # -- public API --------------------------------------------------------
    def scan(
        self,
        target: str,
        profile: str = "balanced",
        config: Optional[Dict[str, Any]] = None,
    ) -> ScanResult:
        """Run the tool against ``target`` using the requested profile."""
        cfg = {**self.config, **(config or {})}
        host = validate_target(target)
        prof = get_profile(profile, cfg)
        command = self.build_command(host, prof, cfg)
        self.logger.info(
            "starting scan tool=%s target=%s profile=%s args=%s",
            self.tool_name, host, prof.name, command,
        )
        return self._execute(command, host, prof, cfg)

    def build_command(
        self,
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> List[str]:
        """Build the argument list. Must be implemented by subclasses."""
        raise NotImplementedError

    # -- internals ---------------------------------------------------------
    @with_retry(max_attempts=3, base_backoff=1.0)
    def _execute(
        self,
        command: List[str],
        target: str,
        profile: ScanProfile,
        config: Dict[str, Any],
    ) -> ScanResult:
        """Execute the subprocess safely (no shell, list args, UTF-8)."""
        # Pre-flight: every argument must be a string (defensive).
        safe_command = [str(arg) for arg in command]
        start = time.monotonic()
        try:
            proc = subprocess.run(  # noqa: S603
                safe_command,
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=profile.timeout_seconds,
                check=False,
                env=self._subprocess_env(),
            )
            duration = time.monotonic() - start
            # Apply jitter *after* the call so that consecutive scans are
            # spaced (CDC: jitter between subprocess calls).
            self._apply_jitter(profile)
            return ScanResult(
                tool=self.tool_name,
                target=target,
                profile=profile.name,
                returncode=proc.returncode,
                stdout=proc.stdout or "",
                stderr=proc.stderr or "",
                duration_seconds=duration,
                command=safe_command,
                error=None if proc.returncode == 0 else f"exit {proc.returncode}",
            )
        except subprocess.TimeoutExpired as exc:
            duration = time.monotonic() - start
            self.logger.error(
                "scan timed out tool=%s target=%s after %ss",
                self.tool_name, target, profile.timeout_seconds,
            )
            return ScanResult(
                tool=self.tool_name,
                target=target,
                profile=profile.name,
                returncode=-1,
                stdout=exc.stdout.decode("utf-8", "replace") if isinstance(exc.stdout, bytes) else (exc.stdout or ""),
                stderr=exc.stderr.decode("utf-8", "replace") if isinstance(exc.stderr, bytes) else (exc.stderr or ""),
                duration_seconds=duration,
                command=safe_command,
                error=f"timeout after {profile.timeout_seconds}s",
            )

    def _subprocess_env(self) -> Dict[str, str]:
        """Minimal environment for subprocesses — strips sensitive vars."""
        env = {
            "PATH": os.environ.get("PATH", "/usr/local/bin:/usr/bin:/bin"),
            "LANG": "C.UTF-8",
            "LC_ALL": "C.UTF-8",
            "HOME": os.environ.get("HOME", "/tmp"),
        }
        # Allow tools that need HOME (e.g. nuclei config dir) to find it.
        if "NUCLEI_HOME" in os.environ:
            env["NUCLEI_HOME"] = os.environ["NUCLEI_HOME"]
        return env

    def _apply_jitter(self, profile: ScanProfile) -> None:
        """Sleep for a randomized jitter to satisfy profile requirements."""
        if profile.jitter_ms_max <= 0:
            return
        lo = profile.jitter_ms_min / 1000.0
        hi = profile.jitter_ms_max / 1000.0
        delay = random.uniform(lo, hi) if hi > lo else hi
        if delay > 0:
            time.sleep(delay)


__all__ = [
    "BaseScannerService",
    "ScanProfile",
    "ScanResult",
    "PROFILES",
    "get_profile",
    "validate_target",
    "with_retry",
]
