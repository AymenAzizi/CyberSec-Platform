# AI Microservice

The AI service wraps the local Ollama LLM (qwen2.5-coder:7b) with strict JSON schema enforcement and anti-hallucination citation requirements.

## Build & run

```bash
docker compose up -d ai ollama
docker compose logs -f ai

# Pull the model (4.7 GB — takes 5-15 minutes)
docker compose exec ollama ollama pull qwen2.5-coder:7b
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Service status + Ollama reachability |
| POST | `/analyze` | `{tool, target, raw_output, findings?}` → AI analysis with citations + remediation scripts |
| POST | `/chat` | `{messages: [{role, content}]}` → assistant response |
| POST | `/remediation` | `{finding: {...}}` → remediation scripts in bash/ansible/dockerfile/terraform/python |
| POST | `/summary` | `{target, profile?, scan_date?, findings?}` → executive summary (Markdown) |

## Anti-hallucination

The AI service enforces two strict rules:

1. **Citations required** — every claim in an AI response must cite a specific CVE ID, CWE ID, or NIST/CIS reference. If the model can't cite, it must say "I don't have a citation for this claim".
2. **JSON schema enforcement** — every response is parsed against a strict JSON schema. If parsing fails, the request fails with HTTP 502 and the raw text is logged for debugging.

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `OLLAMA_HOST` | `http://ollama:11434` | Ollama API endpoint |
| `OLLAMA_MODEL` | `qwen2.5-coder:7b` | Model name |
| `OLLAMA_KEEP_ALIVE` | `24h` | Keep model loaded for 24h (faster inference) |
| `OLLAMA_TIMEOUT` | `120` | Per-request timeout (seconds) |
| `FEATURE_AI_ENABLED` | `true` | Feature flag — when false, AI routes return 503 |
