"""AI analyzer — calls Ollama with a structured-output prompt.

Falls back gracefully when Ollama is unreachable (CDC: graceful degradation).
"""

from __future__ import annotations

import json
import logging
import os
import re
from typing import Any, Dict, List, Optional

import requests

logger = logging.getLogger(__name__)


class AIAnalyzer:
    """Structured-output analyzer backed by an Ollama HTTP endpoint."""

    DEFAULT_MODEL = "qwen2.5-coder:7b"
    DEFAULT_TIMEOUT = 90

    # Languages we accept from the LLM (CDC: bash/ansible/dockerfile/terraform/python).
    ALLOWED_LANGUAGES = {"bash", "ansible", "dockerfile", "terraform", "python"}

    def __init__(
        self,
        host: Optional[str] = None,
        model: Optional[str] = None,
        timeout: Optional[int] = None,
    ) -> None:
        self.host = (host or os.environ.get("OLLAMA_HOST") or "http://127.0.0.1:11434").rstrip("/")
        self.model = (model or os.environ.get("OLLAMA_MODEL") or self.DEFAULT_MODEL).strip()
        self.timeout = int(timeout or self.DEFAULT_TIMEOUT)

    # ------------------------------------------------------------------
    def analyze(
        self,
        tool: str,
        target: str,
        raw_output: str,
        findings: List[Dict[str, Any]],
    ) -> Dict[str, Any]:
        """Run the structured analysis and return a normalized dict.

        Always returns a dict with the keys:
        ``{summary, citations, remediation_scripts}``.
        """
        prompt = self._build_prompt(tool, target, raw_output, findings)
        raw_response = self._call_ollama(prompt)
        if raw_response is None:
            return self._fallback(raw_output, findings)
        parsed = self._parse_response(raw_response, raw_output)
        return parsed

    # ------------------------------------------------------------------
    def _build_prompt(
        self,
        tool: str,
        target: str,
        raw_output: str,
        findings: List[Dict[str, Any]],
    ) -> str:
        """Build the structured prompt sent to the LLM."""
        # Truncate raw output to keep prompt within model context window.
        truncated, max_line = self._truncate_with_lines(raw_output, max_chars=12000)
        findings_summary = json.dumps(
            [
                {
                    "id": idx,
                    "title": f.get("title"),
                    "severity": f.get("severity"),
                    "description": f.get("description"),
                    "endpoint": f.get("endpoint"),
                    "cve_id": f.get("cve_id"),
                    "evidence": (f.get("evidence") or "")[:200],
                }
                for idx, f in enumerate(findings)
            ],
            indent=2,
        )
        return f"""You are a senior penetration tester reviewing output from a security scan.

TASK: Analyze the raw output of the "{tool}" scanner against target "{target}"
and produce a STRICT JSON document (no markdown fences, no prose).

The raw output is numbered line-by-line. Every citation MUST reference a real
line number present in the raw output. Use ONLY the JSON schema below:

{{
  "summary": "<concise technical summary, max 300 chars>",
  "citations": [
    {{"line": <int>, "raw_excerpt": "<verbatim line text, max 240 chars>", "finding_id": <int or null>}}
  ],
  "remediation_scripts": [
    {{
      "language": "bash|ansible|dockerfile|terraform|python",
      "code": "<runnable snippet>",
      "explanation": "<one-sentence rationale>",
      "finding_id": <int or null>
    }}
  ]
}}

RULES:
1. Output ONLY the JSON object. No commentary.
2. Each citation's "line" MUST exist in the raw output (1-indexed).
3. Each remediation_script MUST be language-tagged with one of: bash, ansible, dockerfile, terraform, python.
4. Provide 1-5 citations and 0-3 remediation_scripts per finding.
5. Remediation code must be defensive (do not disable security controls).

FINDINGS (indexed):
{findings_summary}

RAW OUTPUT (last line number = {max_line}):
{truncated}
"""

    @staticmethod
    def _truncate_with_lines(raw: str, max_chars: int) -> tuple[str, int]:
        """Return numbered output truncated to ``max_chars`` plus the max line number."""
        lines = raw.splitlines()
        out_lines: List[str] = []
        total = 0
        for idx, line in enumerate(lines, start=1):
            entry = f"{idx:5d}| {line}"
            if total + len(entry) > max_chars:
                out_lines.append(f"... (truncated; {len(lines) - idx + 1} more lines)")
                break
            out_lines.append(entry)
            total += len(entry) + 1
        return "\n".join(out_lines), len(lines)

    # ------------------------------------------------------------------
    def _call_ollama(self, prompt: str) -> Optional[str]:
        """Invoke the Ollama HTTP API and return raw text or ``None`` on failure."""
        url = f"{self.host}/api/generate"
        payload = {
            "model": self.model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": 0.2,
                "top_p": 0.9,
                "num_predict": 1500,
            },
            "format": "json",
        }
        try:
            resp = requests.post(url, json=payload, timeout=self.timeout)
            resp.raise_for_status()
            data = resp.json()
            text = data.get("response") or ""
            if not text:
                logger.warning("ollama returned empty response: %s", data)
                return None
            return text
        except requests.Timeout:
            logger.warning("ollama request timed out after %ss", self.timeout)
            return None
        except requests.ConnectionError as exc:
            logger.warning("ollama unreachable: %s", exc)
            return None
        except requests.RequestException as exc:
            logger.warning("ollama request failed: %s", exc)
            return None
        except (ValueError, KeyError) as exc:
            logger.warning("ollama response parse error: %s", exc)
            return None

    # ------------------------------------------------------------------
    def _parse_response(self, raw_response: str, raw_output: str) -> Dict[str, Any]:
        """Parse the JSON returned by Ollama and normalize it."""
        # Strip potential markdown fences.
        text = raw_response.strip()
        if text.startswith("```"):
            # Remove ```json ... ``` wrapper.
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            obj = json.loads(text)
        except json.JSONDecodeError:
            logger.warning("ollama response was not valid JSON; returning fallback")
            return self._fallback(raw_output, [])

        if not isinstance(obj, dict):
            return self._fallback(raw_output, [])

        summary = str(obj.get("summary") or "AI analysis unavailable").strip()[:600]
        citations = self._normalize_citations(obj.get("citations"), raw_output)
        scripts = self._normalize_scripts(obj.get("remediation_scripts"))
        return {
            "summary": summary,
            "citations": citations,
            "remediation_scripts": scripts,
        }

    def _normalize_citations(self, raw_cites: Any, raw_output: str) -> List[Dict[str, Any]]:
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
                # Drop citations that don't reference real line numbers.
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

    def _normalize_scripts(self, raw_scripts: Any) -> List[Dict[str, Any]]:
        if not isinstance(raw_scripts, list):
            return []
        out: List[Dict[str, Any]] = []
        for s in raw_scripts:
            if not isinstance(s, dict):
                continue
            lang = str(s.get("language") or "").strip().lower()
            if lang not in self.ALLOWED_LANGUAGES:
                continue
            code = str(s.get("code") or "").strip()
            if not code:
                continue
            explanation = str(s.get("explanation") or "").strip()[:400]
            finding_id = s.get("finding_id")
            if finding_id is not None:
                try:
                    finding_id = int(finding_id)
                except (TypeError, ValueError):
                    finding_id = None
            out.append({
                "language": lang,
                "code": code,
                "explanation": explanation,
                "finding_id": finding_id,
            })
            if len(out) >= 20:
                break
        return out

    # ------------------------------------------------------------------
    def _fallback(self, raw_output: str, findings: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Return a deterministic, valid response when AI is unavailable.

        Still produces useful citations derived from the raw output so that
        downstream consumers can render evidence without AI.
        """
        lines = raw_output.splitlines()
        citations = []
        # Reference the first few non-empty lines so the report has evidence.
        for idx, line in enumerate(lines, start=1):
            stripped = line.strip()
            if not stripped:
                continue
            citations.append({"line": idx, "raw_excerpt": stripped[:240], "finding_id": None})
            if len(citations) >= 5:
                break
        summary = "AI analysis unavailable. Tool produced {0} lines of raw output with {1} parsed findings.".format(
            len(lines), len(findings)
        )
        return {
            "summary": summary,
            "citations": citations,
            "remediation_scripts": [],
        }


__all__ = ["AIAnalyzer"]
