"""Redis Streams consumer with HTTP polling fallback.

CDC requirement: event-driven orchestration via Redis Streams, with graceful
degradation to HTTP polling of the Laravel queue API when Redis is unavailable.
"""

from __future__ import annotations

import json
import logging
import os
import time
from typing import Any, Dict, Optional

logger = logging.getLogger(__name__)

# Stream names.
STREAM_REQUESTS = "scan:requests"
STREAM_COMPLETED = "scan:completed"
STREAM_FAILED = "scan:failed"

# Consumer group / name.
CONSUMER_GROUP = "pfe-workers"
CONSUMER_NAME = os.environ.get("WORKER_NAME", f"worker-{os.getpid()}")


class RedisStreamConsumer:
    """Wraps redis-py's XREADGROUP API with auto group creation."""

    def __init__(
        self,
        redis_url: Optional[str] = None,
        stream: str = STREAM_REQUESTS,
        group: str = CONSUMER_GROUP,
        consumer: str = CONSUMER_NAME,
        block_ms: int = 5000,
    ) -> None:
        self.redis_url = redis_url or os.environ.get("REDIS_URL", "redis://localhost:6379/0")
        self.stream = stream
        self.group = group
        self.consumer = consumer
        self.block_ms = block_ms
        self._client = None
        self._connected = False

    # ------------------------------------------------------------------
    def connect(self) -> bool:
        try:
            import redis  # type: ignore
        except ImportError:
            logger.warning("redis library not installed")
            return False
        try:
            self._client = redis.Redis.from_url(
                self.redis_url,
                socket_connect_timeout=5,
                socket_timeout=10,
                decode_responses=True,
            )
            self._client.ping()
            self._connected = True
            # Create the consumer group if it doesn't exist.
            try:
                self._client.xgroup_create(self.stream, self.group, id="0", mkstream=True)
                logger.info("created consumer group %s on stream %s", self.group, self.stream)
            except Exception as exc:  # noqa: BLE001
                # BUSYGROUP == group already exists.
                if "BUSYGROUP" not in str(exc):
                    logger.warning("xgroup_create: %s", exc)
            return True
        except Exception as exc:  # noqa: BLE001
            logger.warning("redis connect failed: %s — falling back to HTTP polling", exc)
            self._connected = False
            self._client = None
            return False

    @property
    def connected(self) -> bool:
        return self._connected and self._client is not None

    # ------------------------------------------------------------------
    def poll(self) -> Optional[Dict[str, Any]]:
        """Block for ``block_ms`` waiting for a new message. Returns one message or None."""
        if not self.connected:
            return None
        try:
            resp = self._client.xreadgroup(
                groupname=self.group,
                consumername=self.consumer,
                streams={self.stream: ">"},
                count=1,
                block=self.block_ms,
            )
        except Exception as exc:  # noqa: BLE001
            logger.warning("xreadgroup error: %s", exc)
            self._connected = False
            return None
        if not resp:
            return None
        _stream, messages = resp[0]
        if not messages:
            return None
        msg_id, fields = messages[0]
        return self._decode(msg_id, fields)

    # ------------------------------------------------------------------
    def ack(self, msg_id: str) -> None:
        if not self.connected:
            return
        try:
            self._client.xack(self.stream, self.group, msg_id)
        except Exception as exc:  # noqa: BLE001
            logger.warning("xack error: %s", exc)

    # ------------------------------------------------------------------
    def publish(self, stream: str, fields: Dict[str, Any]) -> None:
        if not self.connected:
            return
        try:
            self._client.xadd(stream, fields, maxlen=10_000, approximate=True)
        except Exception as exc:  # noqa: BLE001
            logger.warning("xadd error: %s", exc)

    # ------------------------------------------------------------------
    @staticmethod
    def _decode(msg_id: str, fields: Dict[str, str]) -> Dict[str, Any]:
        # Fields are bytes/strings; we store JSON in a "payload" field.
        payload_raw = fields.get("payload") or fields.get("data") or "{}"
        try:
            payload = json.loads(payload_raw)
        except json.JSONDecodeError:
            payload = {"raw": payload_raw}
        return {
            "id": msg_id,
            "payload": payload,
            "fields": fields,
        }


__all__ = [
    "RedisStreamConsumer",
    "STREAM_REQUESTS",
    "STREAM_COMPLETED",
    "STREAM_FAILED",
    "CONSUMER_GROUP",
    "CONSUMER_NAME",
]
