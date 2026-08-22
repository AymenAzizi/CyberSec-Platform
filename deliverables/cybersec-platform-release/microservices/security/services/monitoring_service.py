"""In-memory monitoring service — event log + alert generation.

Events are stored in a bounded deque so the service does not leak memory
under load. Alert generation triggers for high/critical findings.
"""

from __future__ import annotations

import json
import logging
import threading
from collections import deque
from datetime import datetime, timezone
from typing import Any, Deque, Dict, List, Optional

logger = logging.getLogger(__name__)

_ALERT_SEVERITIES = {"high", "critical"}


class MonitoringService:
    """Thread-safe in-memory event store with alert generation."""

    def __init__(self, max_events: int = 10_000) -> None:
        self._events: Deque[Dict[str, Any]] = deque(maxlen=max_events)
        self._alerts: Deque[Dict[str, Any]] = deque(maxlen=max_events)
        self._lock = threading.Lock()
        # Aggregated counts by severity.
        self._counts: Dict[str, int] = {"info": 0, "low": 0, "medium": 0, "high": 0, "critical": 0}

    # ------------------------------------------------------------------
    def log_event(
        self,
        event_type: str,
        target: str,
        severity: str = "info",
        details: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        severity = (severity or "info").lower()
        if severity not in self._counts:
            severity = "info"
        event = {
            "id": f"evt-{len(self._events) + 1}-{int(datetime.now(timezone.utc).timestamp())}",
            "type": event_type,
            "target": target,
            "severity": severity,
            "details": details or {},
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }
        with self._lock:
            self._events.append(event)
            self._counts[severity] += 1
        if severity in _ALERT_SEVERITIES:
            self._raise_alert(event)
        return event

    # ------------------------------------------------------------------
    def _raise_alert(self, event: Dict[str, Any]) -> Dict[str, Any]:
        alert = {
            "id": f"alert-{len(self._alerts) + 1}-{int(datetime.now(timezone.utc).timestamp())}",
            "event_id": event["id"],
            "severity": event["severity"],
            "target": event["target"],
            "message": f"{event['severity'].upper()} severity {event['type']} on {event['target']}",
            "details": event["details"],
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "acknowledged": False,
        }
        with self._lock:
            self._alerts.append(alert)
        logger.warning("alert raised: %s", json.dumps(alert, default=str))
        return alert

    # ------------------------------------------------------------------
    def stats(self) -> Dict[str, Any]:
        with self._lock:
            return {
                "total_events": sum(self._counts.values()),
                "by_severity": dict(self._counts),
                "total_alerts": len(self._alerts),
                "unacknowledged_alerts": sum(1 for a in self._alerts if not a.get("acknowledged")),
                "last_updated": datetime.now(timezone.utc).isoformat(),
            }

    # ------------------------------------------------------------------
    def recent_events(self, limit: int = 100, severity: Optional[str] = None) -> List[Dict[str, Any]]:
        with self._lock:
            events = list(self._events)
        if severity:
            severity = severity.lower()
            events = [e for e in events if e["severity"] == severity]
        return events[-limit:]

    def recent_alerts(self, limit: int = 50, only_unack: bool = False) -> List[Dict[str, Any]]:
        with self._lock:
            alerts = list(self._alerts)
        if only_unack:
            alerts = [a for a in alerts if not a.get("acknowledged")]
        return alerts[-limit:]

    def acknowledge_alert(self, alert_id: str) -> bool:
        with self._lock:
            for a in self._alerts:
                if a["id"] == alert_id:
                    a["acknowledged"] = True
                    return True
        return False


__all__ = ["MonitoringService"]
