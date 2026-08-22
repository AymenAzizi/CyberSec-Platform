"""AI microservice — chat, analysis, Remediation-as-Code, executive summaries.

Endpoints:
    GET  /health
    POST /analyze
    POST /chat
    POST /remediation
    POST /summary
"""

from __future__ import annotations

import json
import logging
import logging.config
import os
import re
import sys
import traceback
from datetime import datetime, timezone
from typing import Any, Dict, List

from dotenv import load_dotenv
from flask import Flask, jsonify, request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from services import (  # noqa: E402
    OllamaClient,
    ANALYSIS_PROMPT,
    REMEDIATION_PROMPT,
    CHAT_SYSTEM_PROMPT,
    SUMMARY_PROMPT,
)

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
logger = logging.getLogger("ai.app")

app = Flask(__name__)
app.config["JSON_SORT_KEYS"] = False

ALLOWED_LANGUAGES = {"bash", "ansible", "dockerfile", "terraform", "python"}


def _json_body() -> Dict[str, Any]:
    if not request.is_json:
        try:
            return json.loads(request.get_data(as_text=True) or "{}")
        except (ValueError, TypeError):
            return {}
    return request.get_json(silent=True) or {}


def _error(message: str, status: int = 400, extra: Dict[str, Any] | None = None) -> Any:
    payload = {"error": message, "status": status}
    if extra:
        payload.update(extra)
    return jsonify(payload), status


def _strip_fences(text: str) -> str:
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json|bash|python|ansible|terraform|dockerfile)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    return text


def _parse_json_loose(text: str) -> Any:
    """Best-effort JSON parse: handles fences, leading whitespace, trailing commas."""
    cleaned = _strip_fences(text)
    try:
        return json.loads(cleaned)
    except json.JSONDecodeError:
        # Try to extract the outermost JSON object/array.
        for start_char, end_char in (("{", "}"), ("[", "]")):
            start = cleaned.find(start_char)
            end = cleaned.rfind(end_char)
            if start >= 0 and end > start:
                try:
                    return json.loads(cleaned[start : end + 1])
                except json.JSONDecodeError:
                    continue
        return None


@app.get("/health")
def health() -> Any:
    client = OllamaClient()
    return jsonify({
        "status": "ok",
        "service": "ai",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "ollama_host": client.host,
        "ollama_model": client.model,
        "ollama_reachable": client.health(),
    })


@app.post("/analyze")
def analyze() -> Any:
    body = _json_body()
    tool = body.get("tool")
    target = body.get("target")
    raw_output = body.get("raw_output")
    findings = body.get("findings") or []
    if not (tool and target and raw_output is not None):
        return _error("required fields: tool, target, raw_output", 400)

    prompt = ANALYSIS_PROMPT(tool=tool, target=target, raw_output=raw_output, findings=findings)
    client = OllamaClient()
    raw_response = client.generate(prompt, json_mode=True)
    if raw_response is None:
        # Graceful fallback.
        return jsonify({
            "tool": tool,
            "target": target,
            "analysis": {
                "summary": "AI analysis unavailable. Please verify Ollama is running.",
                "citations": [],
                "remediation_scripts": [],
            },
            "ai_available": False,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        })

    parsed = _parse_json_loose(raw_response)
    if not isinstance(parsed, dict):
        return jsonify({
            "tool": tool,
            "target": target,
            "analysis": {
                "summary": "AI returned a non-JSON response.",
                "raw_response": raw_response[:1000],
                "citations": [],
                "remediation_scripts": [],
            },
            "ai_available": True,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        })

    summary = str(parsed.get("summary") or "")[:600]
    citations = _normalize_citations(parsed.get("citations"), raw_output)
    scripts = _normalize_scripts(parsed.get("remediation_scripts"))
    return jsonify({
        "tool": tool,
        "target": target,
        "analysis": {
            "summary": summary,
            "citations": citations,
            "remediation_scripts": scripts,
        },
        "ai_available": True,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


@app.post("/chat")
def chat() -> Any:
    body = _json_body()
    messages = body.get("messages")
    if not isinstance(messages, list) or not messages:
        return _error("required field 'messages' must be a non-empty list", 400)

    # Validate and normalize message structure.
    normalized: List[Dict[str, str]] = []
    for m in messages:
        if not isinstance(m, dict):
            continue
        role = str(m.get("role") or "user").lower()
        content = str(m.get("content") or "")
        if role not in {"system", "user", "assistant"}:
            role = "user"
        if content:
            normalized.append({"role": role, "content": content})

    if not normalized:
        return _error("messages list contained no valid entries", 400)

    # Prepend the system prompt if the caller didn't provide one.
    if not any(m["role"] == "system" for m in normalized):
        normalized.insert(0, {"role": "system", "content": CHAT_SYSTEM_PROMPT})

    client = OllamaClient()
    response = client.chat(normalized, temperature=0.3, max_tokens=1500)
    if response is None:
        return jsonify({
            "response": "AI assistant is currently unavailable. Please retry later.",
            "ai_available": False,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        })

    return jsonify({
        "response": response,
        "role": "assistant",
        "model": client.model,
        "ai_available": True,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


@app.post("/remediation")
def remediation() -> Any:
    body = _json_body()
    finding = body.get("finding")
    if not isinstance(finding, dict):
        return _error("required field 'finding' must be an object", 400)

    prompt = REMEDIATION_PROMPT(finding)
    client = OllamaClient()
    raw_response = client.generate(prompt, json_mode=True)
    if raw_response is None:
        return jsonify({
            "finding": finding.get("title"),
            "remediation_scripts": [],
            "ai_available": False,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        })

    parsed = _parse_json_loose(raw_response)
    if isinstance(parsed, dict):
        # Some models wrap the array in an object.
        parsed = parsed.get("remediation_scripts") or parsed.get("scripts") or []
    if not isinstance(parsed, list):
        parsed = []

    scripts = _normalize_scripts(parsed)
    return jsonify({
        "finding": finding.get("title"),
        "remediation_scripts": scripts,
        "ai_available": True,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


@app.post("/summary")
def summary() -> Any:
    body = _json_body()
    target = body.get("target")
    profile = body.get("profile", "balanced")
    scan_date = body.get("scan_date") or datetime.now(timezone.utc).isoformat()
    findings = body.get("findings") or []
    if not target:
        return _error("required field 'target'", 400)

    prompt = SUMMARY_PROMPT(
        target=target,
        profile=profile,
        scan_date=scan_date,
        findings=findings,
    )
    client = OllamaClient()
    response = client.generate(
        prompt,
        json_mode=False,  # Executive summary is Markdown, not JSON.
        temperature=0.4,
        max_tokens=1200,
    )
    if response is None:
        return jsonify({
            "target": target,
            "summary": "AI summary unavailable. Please verify Ollama is running.",
            "ai_available": False,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        })

    return jsonify({
        "target": target,
        "summary": response,
        "ai_available": True,
        "timestamp": datetime.now(timezone.utc).isoformat(),
    })


# ---------------------------------------------------------------------------
# Normalizers (shared with the reconnaissance ai_analyzer).
# ---------------------------------------------------------------------------
def _normalize_citations(raw_cites: Any, raw_output: str) -> List[Dict[str, Any]]:
    if not isinstance(raw_cites, list):
        return []
    max_line = len(raw_output.splitlines())
    out: List[Dict[str, Any]] = []
    for c in raw_cites:
        if not isinstance(c, dict):
            continue
        try:
            line = int(c.get("line", 0))
        except (TypeError, ValueError):
            continue
        if line < 1 or (max_line and line > max_line):
            continue
        excerpt = str(c.get("raw_excerpt") or "")[:240]
        finding_id = c.get("finding_id")
        if finding_id is not None:
            try:
                finding_id = int(finding_id)
            except (TypeError, ValueError):
                finding_id = None
        out.append({
            "line": line,
            "raw_excerpt": excerpt,
            "finding_id": finding_id,
        })
        if len(out) >= 50:
            break
    return out


def _normalize_scripts(raw_scripts: Any) -> List[Dict[str, Any]]:
    if not isinstance(raw_scripts, list):
        return []
    out: List[Dict[str, Any]] = []
    for s in raw_scripts:
        if not isinstance(s, dict):
            continue
        lang = str(s.get("language") or "").strip().lower()
        if lang not in ALLOWED_LANGUAGES:
            continue
        code = str(s.get("code") or "").strip()
        if not code:
            continue
        explanation = str(s.get("explanation") or "").strip()[:400]
        out.append({
            "language": lang,
            "code": code,
            "explanation": explanation,
        })
        if len(out) >= 20:
            break
    return out


@app.errorhandler(404)
def not_found(err: Any) -> Any:
    return _error("not found", 404, {"path": request.path})


@app.errorhandler(405)
def method_not_allowed(err: Any) -> Any:
    return _error("method not allowed", 405, {"method": request.method, "path": request.path})


@app.errorhandler(500)
def internal_error(err: Any) -> Any:
    logger.error("internal server error: %s\n%s", err, traceback.format_exc())
    return _error("internal server error", 500)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "5003"))
    app.run(host="0.0.0.0", port=port, debug=False)
