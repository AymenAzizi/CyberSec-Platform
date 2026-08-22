"""Ollama HTTP client with retry, timeout and graceful fallback."""

from __future__ import annotations

import logging
import time
from typing import Any, Dict, List, Optional

import requests

logger = logging.getLogger(__name__)


class OllamaClient:
    """Thin HTTP wrapper around the Ollama ``/api/generate`` and ``/api/chat`` endpoints."""

    DEFAULT_MODEL = "qwen2.5-coder:7b"
    DEFAULT_TIMEOUT = 120
    MAX_RETRIES = 3
    BACKOFF_BASE = 1.0  # 2^attempt seconds

    def __init__(
        self,
        host: Optional[str] = None,
        model: Optional[str] = None,
        timeout: Optional[int] = None,
    ) -> None:
        self.host = (host or os_environ("OLLAMA_HOST") or "http://127.0.0.1:11434").rstrip("/")
        self.model = (model or os_environ("OLLAMA_MODEL") or self.DEFAULT_MODEL).strip()
        self.timeout = int(timeout or int(os_environ("OLLAMA_TIMEOUT") or self.DEFAULT_TIMEOUT))

    # ------------------------------------------------------------------
    def generate(
        self,
        prompt: str,
        *,
        json_mode: bool = True,
        temperature: float = 0.2,
        max_tokens: int = 1500,
        system: Optional[str] = None,
    ) -> Optional[str]:
        """Call ``/api/generate`` and return the raw response text.

        Returns ``None`` on hard failure after retries.
        """
        url = f"{self.host}/api/generate"
        payload: Dict[str, Any] = {
            "model": self.model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": temperature,
                "top_p": 0.9,
                "num_predict": max_tokens,
            },
        }
        if json_mode:
            payload["format"] = "json"
        if system:
            payload["system"] = system

        return self._post_with_retry(url, payload, lambda data: data.get("response"))

    # ------------------------------------------------------------------
    def chat(
        self,
        messages: List[Dict[str, str]],
        *,
        temperature: float = 0.3,
        max_tokens: int = 1500,
        json_mode: bool = False,
    ) -> Optional[str]:
        """Call ``/api/chat`` and return the assistant's message content."""
        url = f"{self.host}/api/chat"
        payload: Dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            "stream": False,
            "options": {
                "temperature": temperature,
                "top_p": 0.9,
                "num_predict": max_tokens,
            },
        }
        if json_mode:
            payload["format"] = "json"
        return self._post_with_retry(
            url,
            payload,
            lambda data: (data.get("message") or {}).get("content"),
        )

    # ------------------------------------------------------------------
    def health(self) -> bool:
        """Return True if Ollama is reachable and the configured model is available."""
        try:
            resp = requests.get(f"{self.host}/api/tags", timeout=5)
            if resp.status_code != 200:
                return False
            data = resp.json()
            models = [m.get("name", "") for m in data.get("models", [])]
            # Match either exact name or name with tag prefix.
            return any(self.model == m or m.startswith(self.model.split(":")[0] + ":") for m in models)
        except requests.RequestException as exc:
            logger.warning("ollama health check failed: %s", exc)
            return False

    # ------------------------------------------------------------------
    def _post_with_retry(
        self,
        url: str,
        payload: Dict[str, Any],
        extractor,
    ) -> Optional[str]:
        last_exc: Optional[BaseException] = None
        for attempt in range(self.MAX_RETRIES):
            try:
                resp = requests.post(url, json=payload, timeout=self.timeout)
                resp.raise_for_status()
                data = resp.json()
                text = extractor(data)
                if not text:
                    logger.warning("ollama returned empty response: %s", data)
                    return None
                return text
            except requests.Timeout as exc:
                last_exc = exc
                logger.warning("ollama timeout (attempt %d/%d)", attempt + 1, self.MAX_RETRIES)
            except requests.ConnectionError as exc:
                last_exc = exc
                logger.warning("ollama connection error (attempt %d/%d): %s", attempt + 1, self.MAX_RETRIES, exc)
                # Connection errors usually mean Ollama is down — bail early.
                break
            except requests.RequestException as exc:
                last_exc = exc
                logger.warning("ollama request error (attempt %d/%d): %s", attempt + 1, self.MAX_RETRIES, exc)
            except (ValueError, KeyError) as exc:
                last_exc = exc
                logger.warning("ollama parse error (attempt %d/%d): %s", attempt + 1, self.MAX_RETRIES, exc)

            if attempt < self.MAX_RETRIES - 1:
                delay = self.BACKOFF_BASE * (2 ** attempt)
                time.sleep(delay)
        if last_exc:
            logger.warning("ollama call failed after %d attempts: %s", self.MAX_RETRIES, last_exc)
        return None


def os_environ(name: str) -> Optional[str]:
    import os
    val = os.environ.get(name)
    return val if val else None


__all__ = ["OllamaClient"]
