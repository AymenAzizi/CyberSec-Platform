"""HTTP polling fallback when Redis Streams are unavailable.

The worker periodically calls the Laravel queue API to fetch pending scan
jobs. This guarantees the platform still functions without Redis (CDC:
graceful degradation).
"""

from __future__ import annotations

import logging
import os
import time
from typing import Any, Dict, Optional

import requests

logger = logging.getLogger(__name__)


class HttpQueuePoller:
    """Polls a Laravel endpoint for the next scan job."""

    def __init__(
        self,
        base_url: Optional[str] = None,
        poll_interval: float = 5.0,
        timeout: int = 15,
    ) -> None:
        self.base_url = (base_url or os.environ.get("LARAVEL_URL") or "http://laravel:8000").rstrip("/")
        self.poll_interval = poll_interval
        self.timeout = timeout
        self._token = os.environ.get("WORKER_API_TOKEN", "")

    # ------------------------------------------------------------------
    def poll(self) -> Optional[Dict[str, Any]]:
        """Fetch the next available job. Returns ``None`` if the queue is empty."""
        url = f"{self.base_url}/api/queue/next"
        headers = self._headers()
        try:
            resp = requests.get(url, headers=headers, timeout=self.timeout)
        except requests.RequestException as exc:
            logger.warning("queue poll failed: %s", exc)
            time.sleep(self.poll_interval)
            return None

        if resp.status_code == 204:
            return None
        if resp.status_code != 200:
            logger.warning("queue poll returned %d", resp.status_code)
            time.sleep(self.poll_interval)
            return None
        try:
            data = resp.json()
        except ValueError:
            logger.warning("queue poll returned non-JSON response")
            return None
        if not data or not data.get("job_id"):
            return None
        return data

    # ------------------------------------------------------------------
    def update_status(
        self,
        job_id: str,
        status: str,
        result: Optional[Dict[str, Any]] = None,
        error: Optional[str] = None,
    ) -> bool:
        url = f"{self.base_url}/api/queue/{job_id}/status"
        payload: Dict[str, Any] = {"status": status}
        if result is not None:
            payload["result"] = result
        if error is not None:
            payload["error"] = error
        try:
            resp = requests.patch(url, json=payload, headers=self._headers(), timeout=self.timeout)
            return resp.status_code in {200, 202}
        except requests.RequestException as exc:
            logger.warning("status update failed for %s: %s", job_id, exc)
            return False

    # ------------------------------------------------------------------
    def _headers(self) -> Dict[str, str]:
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "PFE-CyberSec/1.0 (worker)",
        }
        if self._token:
            headers["Authorization"] = f"Bearer {self._token}"
        return headers


__all__ = ["HttpQueuePoller"]
