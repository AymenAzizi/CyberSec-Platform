"""Worker entrypoint.

Consumes scan requests from Redis Streams (or HTTP polling fallback),
dispatches them to the appropriate microservice, updates scan status in
Laravel, and publishes ``scan:completed`` / ``scan:failed`` events.

Run directly:
    python worker.py
Or via gunicorn-style supervisor (recommended in production).
"""

from __future__ import annotations

import json
import logging
import logging.config
import os
import signal
import sys
import time
import traceback
from typing import Any, Dict, Optional

from dotenv import load_dotenv

load_dotenv()

LOG_CONFIG = {
    "version": 1,
    "disable_existing_loggers": False,
    "formatters": {
        "json": {
            "format": '{"ts":"%(asctime)s","level":"%(levelname)s","logger":"%(name)s","msg":%(message)s}',
        }
    },
    "handlers": {
        "stdout": {
            "class": "logging.StreamHandler",
            "stream": "ext://sys.stdout",
            "formatter": "json",
        }
    },
    "root": {"handlers": ["stdout"], "level": os.environ.get("LOG_LEVEL", "INFO")},
}
logging.config.dictConfig(LOG_CONFIG)
logger = logging.getLogger("worker")

from redis_consumer import (  # noqa: E402
    RedisStreamConsumer,
    STREAM_COMPLETED,
    STREAM_FAILED,
)
from http_poller import HttpQueuePoller  # noqa: E402
from dispatcher import ScanDispatcher  # noqa: E402


MAX_ATTEMPTS = int(os.environ.get("MAX_ATTEMPTS", "3"))
IDLE_SLEEP = float(os.environ.get("IDLE_SLEEP", "2"))
POLL_INTERVAL = float(os.environ.get("POLL_INTERVAL", "5"))


class Worker:
    """Main worker loop with retry + graceful degradation."""

    def __init__(self) -> None:
        self.consumer = RedisStreamConsumer()
        self.poller = HttpQueuePoller(poll_interval=POLL_INTERVAL)
        self.dispatcher = ScanDispatcher()
        self._stop = False
        signal.signal(signal.SIGTERM, self._handle_signal)
        signal.signal(signal.SIGINT, self._handle_signal)

    # ------------------------------------------------------------------
    def _handle_signal(self, signum, frame) -> None:  # noqa: ANN001
        logger.info("received signal %s — shutting down", signum)
        self._stop = True

    # ------------------------------------------------------------------
    def run(self) -> None:
        """Main loop: try Redis first, fall back to HTTP polling."""
        logger.info(
            "worker starting (name=%s, max_attempts=%d)",
            self.consumer.consumer, MAX_ATTEMPTS,
        )
        redis_ok = self.consumer.connect()
        if redis_ok:
            logger.info("connected to Redis Streams — using event-driven mode")
        else:
            logger.warning("Redis unavailable — using HTTP polling fallback")

        while not self._stop:
            try:
                if redis_ok and self.consumer.connected:
                    self._consume_redis()
                else:
                    # Periodically retry Redis.
                    if not redis_ok:
                        redis_ok = self.consumer.connect()
                    self._consume_http()
            except Exception as exc:  # noqa: BLE001
                logger.error("worker loop error: %s\n%s", exc, traceback.format_exc())
                time.sleep(IDLE_SLEEP)

        logger.info("worker stopped")

    # ------------------------------------------------------------------
    def _consume_redis(self) -> None:
        message = self.consumer.poll()
        if message is None:
            return
        msg_id = message["id"]
        payload = message["payload"]
        job = self._normalize_payload(payload, msg_id)
        self._process_job(job, ack_id=msg_id)

    # ------------------------------------------------------------------
    def _consume_http(self) -> None:
        job = self.poller.poll()
        if job is None:
            time.sleep(IDLE_SLEEP)
            return
        self._process_job(job, ack_id=None)

    # ------------------------------------------------------------------
    def _normalize_payload(self, payload: Dict[str, Any], msg_id: str) -> Dict[str, Any]:
        """Normalize a Redis payload into the canonical job schema."""
        return {
            "job_id": payload.get("job_id") or payload.get("id") or f"redis-{msg_id}",
            "tool": payload.get("tool"),
            "target": payload.get("target"),
            "profile": payload.get("profile", "balanced"),
            "config": payload.get("config") or {},
            "injection_type": payload.get("injection_type"),
            "param": payload.get("param"),
            "source_tool": payload.get("source_tool"),
            "raw_output": payload.get("raw_output"),
            "findings": payload.get("findings"),
            "finding": payload.get("finding"),
            "scan_date": payload.get("scan_date"),
            "attempts": int(payload.get("attempts", 0)),
            "source": "redis",
        }

    # ------------------------------------------------------------------
    def _process_job(self, job: Dict[str, Any], ack_id: Optional[str]) -> None:
        """Run a job with retry. Publishes results and updates Laravel status."""
        job_id = job.get("job_id") or "unknown"
        attempts = int(job.get("attempts", 0))
        logger.info("processing job %s tool=%s target=%s attempt=%d",
                    job_id, job.get("tool"), job.get("target"), attempts + 1)

        result = self.dispatcher.dispatch(job)

        if result.get("success"):
            logger.info("job %s succeeded (http=%s)", job_id, result.get("http_status"))
            self._finalize_success(job, result, ack_id)
            return

        # Failure path: retry if attempts remain.
        if attempts + 1 < MAX_ATTEMPTS:
            logger.warning(
                "job %s failed (attempt %d/%d) — will retry: %s",
                job_id, attempts + 1, MAX_ATTEMPTS, result.get("error"),
            )
            self._requeue(job, attempts + 1, ack_id)
            return

        # Out of retries — mark as failed.
        logger.error("job %s failed permanently: %s", job_id, result.get("error"))
        self._finalize_failure(job, result, ack_id)

    # ------------------------------------------------------------------
    def _finalize_success(
        self,
        job: Dict[str, Any],
        result: Dict[str, Any],
        ack_id: Optional[str],
    ) -> None:
        job_id = job.get("job_id", "unknown")
        # Update Laravel status.
        self.poller.update_status(job_id, "completed", result=result)
        # Publish scan:completed event.
        self.consumer.publish(
            STREAM_COMPLETED,
            {
                "job_id": job_id,
                "tool": job.get("tool"),
                "target": job.get("target"),
                "payload": json.dumps({
                    "result": result,
                    "timestamp": self._now(),
                }),
            },
        )
        if ack_id:
            self.consumer.ack(ack_id)

    def _finalize_failure(
        self,
        job: Dict[str, Any],
        result: Dict[str, Any],
        ack_id: Optional[str],
    ) -> None:
        job_id = job.get("job_id", "unknown")
        error = result.get("error") or "unknown error"
        self.poller.update_status(job_id, "failed", error=error)
        self.consumer.publish(
            STREAM_FAILED,
            {
                "job_id": job_id,
                "tool": job.get("tool"),
                "target": job.get("target"),
                "payload": json.dumps({
                    "error": error,
                    "attempts": MAX_ATTEMPTS,
                    "timestamp": self._now(),
                }),
            },
        )
        if ack_id:
            self.consumer.ack(ack_id)

    def _requeue(self, job: Dict[str, Any], next_attempt: int, ack_id: Optional[str]) -> None:
        """Ack the current message and re-publish with attempts incremented."""
        job_id = job.get("job_id", "unknown")
        # Ack the current Redis message so we don't redeliver it via PEL.
        if ack_id:
            self.consumer.ack(ack_id)
        # Re-publish to the requests stream with incremented attempts.
        payload = {k: v for k, v in job.items() if v is not None}
        payload["attempts"] = next_attempt
        self.consumer.publish(
            "scan:requests",
            {
                "job_id": job_id,
                "payload": json.dumps(payload),
            },
        )

    @staticmethod
    def _now() -> str:
        from datetime import datetime, timezone
        return datetime.now(timezone.utc).isoformat()


def main() -> int:
    worker = Worker()
    worker.run()
    return 0


if __name__ == "__main__":
    sys.exit(main())
