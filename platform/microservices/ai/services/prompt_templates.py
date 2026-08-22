"""Structured prompt templates for the AI microservice.

Each prompt is a *function* returning the formatted prompt string so that
caller-supplied data is interpolated safely. Prompts require JSON output
and explicitly forbid markdown fences / prose.
"""

from __future__ import annotations

import json
from typing import Any, Dict, List

# ---------------------------------------------------------------------------
# Analysis prompt — full structured analysis of a scan's raw output.
# ---------------------------------------------------------------------------
ANALYSIS_PROMPT_TEMPLATE = """You are a senior penetration tester reviewing output from a security scan.

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
{findings_json}

RAW OUTPUT (last line number = {max_line}):
{raw_output_numbered}
"""


def ANALYSIS_PROMPT(
    tool: str,
    target: str,
    raw_output: str,
    findings: List[Dict[str, Any]],
) -> str:
    """Render the structured analysis prompt."""
    truncated, max_line = _truncate_with_lines(raw_output, max_chars=12000)
    findings_summary = [
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
    ]
    return ANALYSIS_PROMPT_TEMPLATE.format(
        tool=tool,
        target=target,
        findings_json=json.dumps(findings_summary, indent=2),
        max_line=max_line,
        raw_output_numbered=truncated,
    )


# ---------------------------------------------------------------------------
# Remediation prompt — generate Remediation-as-Code for a single finding.
# ---------------------------------------------------------------------------
REMEDIATION_PROMPT_TEMPLATE = """You are a DevSecOps engineer producing Remediation-as-Code.

FINDING:
- Title: {title}
- Severity: {severity}
- Description: {description}
- Evidence: {evidence}
- Endpoint: {endpoint}
- CVE: {cve}

TASK: Produce 1-3 runnable remediation scripts that harden the affected system.
Each script MUST be tagged with a language in {{bash, ansible, dockerfile, terraform, python}}.

Output STRICT JSON (no markdown fences, no prose) using this schema:

[
  {{
    "language": "bash|ansible|dockerfile|terraform|python",
    "code": "<runnable snippet>",
    "explanation": "<one-sentence rationale>"
  }}
]

RULES:
1. Output ONLY the JSON array.
2. Code must be production-ready: idempotent, no destructive operations, no disabling security controls.
3. Prefer configuration management (ansible/terraform) over one-off shell commands when applicable.
"""


def REMEDIATION_PROMPT(finding: Dict[str, Any]) -> str:
    return REMEDIATION_PROMPT_TEMPLATE.format(
        title=finding.get("title", "unknown"),
        severity=finding.get("severity", "unknown"),
        description=finding.get("description", ""),
        evidence=(finding.get("evidence") or "")[:400],
        endpoint=finding.get("endpoint") or "n/a",
        cve=finding.get("cve_id") or "n/a",
    )


# ---------------------------------------------------------------------------
# Chat system prompt — cybersecurity assistant with citation requirement.
# ---------------------------------------------------------------------------
CHAT_SYSTEM_PROMPT = """You are a cybersecurity assistant integrated into a penetration-testing platform.

Your role:
- Help analysts interpret scan findings, CVEs, and attack surfaces.
- Recommend remediation steps and explain exploitation paths.
- Cite evidence by line number whenever the user pastes numbered tool output.
- Refuse to write offensive exploit code for targets the user has not been authorized to test.

When responding to user messages that include scan output, you MUST:
1. Reference specific line numbers (e.g. "line 42 shows...").
2. Map each recommendation to the finding it addresses.
3. Avoid speculation; if you don't know, say so.

Keep responses concise and technical. Use Markdown sparingly (headings + code blocks only).
"""


# ---------------------------------------------------------------------------
# Summary prompt — executive summary for non-technical readers.
# ---------------------------------------------------------------------------
SUMMARY_PROMPT_TEMPLATE = """You are a CISO preparing an executive summary of a penetration test.

TARGET: {target}
SCAN PROFILE: {profile}
SCAN DATE: {scan_date}

FINDINGS SUMMARY:
{findings_summary}

RISK DISTRIBUTION:
{risk_distribution}

TASK: Write an executive summary in Markdown (no more than 400 words) covering:
1. Overall risk posture (one paragraph).
2. Top 3 critical risks with business impact.
3. Recommended next steps (prioritized, actionable).

Audience: senior management with limited technical background. Avoid jargon and CVE numbers
in the body — list them in a single bullet list at the end labelled "Technical references".
"""


def SUMMARY_PROMPT(
    target: str,
    profile: str,
    scan_date: str,
    findings: List[Dict[str, Any]],
) -> str:
    # Aggregate risk distribution.
    severity_counts: Dict[str, int] = {}
    for f in findings:
        sev = (f.get("severity") or "info").lower()
        severity_counts[sev] = severity_counts.get(sev, 0) + 1
    risk_distribution = "\n".join(
        f"- {k}: {v}" for k, v in sorted(severity_counts.items())
    )
    findings_summary = "\n".join(
        f"- [{f.get('severity', 'info').upper()}] {f.get('title', 'unknown')}"
        + (f" (CVE: {f.get('cve_id')})" if f.get("cve_id") else "")
        for f in findings[:50]
    )
    return SUMMARY_PROMPT_TEMPLATE.format(
        target=target,
        profile=profile,
        scan_date=scan_date,
        findings_summary=findings_summary or "(no findings)",
        risk_distribution=risk_distribution or "(no findings)",
    )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def _truncate_with_lines(raw: str, max_chars: int) -> tuple[str, int]:
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


__all__ = [
    "ANALYSIS_PROMPT",
    "REMEDIATION_PROMPT",
    "CHAT_SYSTEM_PROMPT",
    "SUMMARY_PROMPT",
]
