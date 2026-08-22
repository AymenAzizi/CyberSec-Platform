# CyberSec Platform — Full Workspace Archive

This archive contains the complete CyberSec reconnaissance platform, the PFE
rapport PDF, install scripts, and all supporting artifacts.

## Folder Layout

| Folder | Contents |
|---|---|
| `platform/` | Laravel 11 source code for the CyberSec Platform (revised for clean install) |
| `rapport/` | LaTeX source of the PFE report (Tectonic-compilable) |
| `scripts/` | Helper scripts: PDF rebuild, screenshot rendering, diagrams |
| `deliverables/` | Final artifacts: rapport PDF, fix-phase scripts, screenshots, demo video |
| `worklog.md` | Full multi-agent work log from this session |

## Quick Start

1. Read `platform/INSTALL.md` for step-by-step install instructions.
2. Read `platform/README.md` for the project overview.
3. Read `platform/AGENTS.md` for the AI agent handoff brief (for Antigravity).

## Tech Stack

- Laravel 11 + Sanctum + Spatie RBAC
- PHP 8.4 / FrankenPHP / Caddy
- PostgreSQL 16 + Apache AGE (or SQLite for dev)
- 5 Python Flask microservices (recon, security, osint, ai, gateway)
- Ollama qwen2.5-coder:7b for local AI inference
- Docker Compose (12 services)
- Tailwind CSS + Vite + Cytoscape.js + ECharts

## Default Accounts (4 RBAC roles)

| Email | Password | Role |
|---|---|---|
| admin@cybersec.local | password | Admin |
| analyst@cybersec.local | password | Analyst |
| client@cybersec.local | password | Client |
| auditor@cybersec.local | password | Auditor |

## Production URL (live demo)

https://aymenazizi.dijaly.com/

## Report PDF

`deliverables/rapport_pfe.pdf` — 120 pages, 18 MB
