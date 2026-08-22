"""Queue worker — event-driven scan orchestration.

Consumes scan requests from Redis Streams (with HTTP polling fallback to the
Laravel queue API when Redis is unavailable), executes the scan via the
appropriate microservice, updates scan status in Laravel, and publishes
``scan:completed`` / ``scan:failed`` events.

The worker is intentionally dependency-light: it only needs ``redis`` and
``requests``. All business logic lives in the microservices.
"""

from __future__ import annotations

__version__ = "1.0.0"
