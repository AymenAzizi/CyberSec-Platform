"""Sliding-window in-memory rate limiter.

Limits:
    30 requests / minute / IP
    Burst of 10 requests in any 1-second window
"""

from __future__ import annotations

import threading
import time
from collections import deque
from dataclasses import dataclass, field
from typing import Deque, Dict, Tuple


@dataclass
class RateLimitConfig:
    window_seconds: int = 60
    max_requests_per_window: int = 30
    burst_window_seconds: int = 1
    max_burst: int = 10


@dataclass
class _Bucket:
    minute_window: Deque[float] = field(default_factory=deque)
    burst_window: Deque[float] = field(default_factory=deque)


class RateLimiter:
    """Thread-safe sliding-window rate limiter keyed by IP."""

    def __init__(self, config: RateLimitConfig | None = None) -> None:
        self.config = config or RateLimitConfig()
        self._buckets: Dict[str, _Bucket] = {}
        self._lock = threading.Lock()

    def check(self, key: str) -> Tuple[bool, Dict[str, int]]:
        """Return ``(allowed, headers)``.

        ``headers`` contains ``X-RateLimit-Remaining``, ``X-RateLimit-Limit``
        and ``Retry-After`` (seconds) when blocked.
        """
        now = time.monotonic()
        with self._lock:
            bucket = self._buckets.setdefault(key, _Bucket())
            # Evict expired entries.
            self._evict(bucket.minute_window, now - self.config.window_seconds)
            self._evict(bucket.burst_window, now - self.config.burst_window_seconds)

            # Burst check first (1-second window).
            if len(bucket.burst_window) >= self.config.max_burst:
                retry_after = max(1, int(self.config.burst_window_seconds - (now - bucket.burst_window[0])))
                return False, {
                    "X-RateLimit-Limit": str(self.config.max_burst),
                    "X-RateLimit-Remaining": "0",
                    "X-RateLimit-Window": f"{self.config.burst_window_seconds}s",
                    "Retry-After": str(retry_after),
                    "X-RateLimit-Reason": "burst",
                }

            # Minute window check.
            if len(bucket.minute_window) >= self.config.max_requests_per_window:
                retry_after = max(1, int(self.config.window_seconds - (now - bucket.minute_window[0])))
                return False, {
                    "X-RateLimit-Limit": str(self.config.max_requests_per_window),
                    "X-RateLimit-Remaining": "0",
                    "X-RateLimit-Window": f"{self.config.window_seconds}s",
                    "Retry-After": str(retry_after),
                    "X-RateLimit-Reason": "minute",
                }

            bucket.minute_window.append(now)
            bucket.burst_window.append(now)
            remaining_minute = self.config.max_requests_per_window - len(bucket.minute_window)
            remaining_burst = self.config.max_burst - len(bucket.burst_window)
            return True, {
                "X-RateLimit-Limit": str(self.config.max_requests_per_window),
                "X-RateLimit-Remaining": str(remaining_minute),
                "X-RateLimit-Burst-Remaining": str(remaining_burst),
                "X-RateLimit-Window": f"{self.config.window_seconds}s",
            }

    @staticmethod
    def _evict(window: Deque[float], cutoff: float) -> None:
        while window and window[0] < cutoff:
            window.popleft()


__all__ = ["RateLimiter", "RateLimitConfig"]
