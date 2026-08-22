"""AI service modules."""

from .ollama_client import OllamaClient
from .prompt_templates import (
    ANALYSIS_PROMPT,
    REMEDIATION_PROMPT,
    CHAT_SYSTEM_PROMPT,
    SUMMARY_PROMPT,
)

__all__ = [
    "OllamaClient",
    "ANALYSIS_PROMPT",
    "REMEDIATION_PROMPT",
    "CHAT_SYSTEM_PROMPT",
    "SUMMARY_PROMPT",
]
