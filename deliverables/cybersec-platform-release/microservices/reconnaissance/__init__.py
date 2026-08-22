"""Reconnaissance orchestrator microservice.

Coordinates passive and active reconnaissance tools (nmap, nuclei, gobuster,
subfinder, wpscan) under a unified Flask API with profile-based throttling,
retries and structured result parsing.
"""

__version__ = "1.0.0"
__all__ = ["app"]
