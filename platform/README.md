# CyberSec Platform — Attack Surface Reconnaissance with AI-Assisted Report Generation

A web platform for security reconnaissance that orchestrates 4 installed scanners (nmap, nuclei, gobuster, subfinder — plus a wpscan wrapper that activates when the binary is present), builds a knowledge graph of the attack surface, and generates AI-assisted remediation reports — all behind an event-driven worker queue with a strict DevSecOps pipeline.

> **One-shot install** (skip to [INSTALL.md](./INSTALL.md) for prerequisites + step-by-step):
> ```bash
> bash scripts/setup.sh
> ```
> The first build takes ~15 minutes (mostly the Ollama model download — 4.7 GB for the default `qwen2.5-coder:7b`, or ~1 GB if you switch to `qwen2.5-coder:1.5b`).
> Once it finishes, the platform is live at http://localhost:3000 with 4 default users.

---

## Architecture in one paragraph

Laravel 13 (PHP 8.4) serves the web UI and the REST API. Five Python Flask microservices do the actual work: `reconnaissance` (port 5000, runs nmap/nuclei/gobuster/subfinder + optional wpscan), `security` (5001, attack detection + Docker sandbox), `osint` (5002, whois/dns/ssl/subdomains/tech-stack), `ai` (5003, calls Ollama), and `api-gateway` (8080, single entrypoint for the Laravel app). A Python `worker` consumes the Redis Streams queue (`scan:requests`) and dispatches scans. PostgreSQL 16 stores normalized findings + the knowledge graph (relational `assets` / `asset_relations` tables, traversed by a NetworkX-backed BFS in PHP and Python). Redis 7 holds the queue, cache, and session store. Ollama runs `qwen2.5-coder` locally for AI analysis + remediation generation (`7b` by default; the deployed instance may use the lighter `1.5b`). Nginx terminates TLS and reverse-proxies everything.

```
                                    ┌───────────────────────────────────────┐
                                    │              NGINX :80/:443            │
                                    │  (TLS, security headers, rate-limit)  │
                                    └───────────────┬───────────────────────┘
                                                    │
                          ┌─────────────────────────┴────────────────────────┐
                          │                                                  │
                  ┌───────▼────────┐                                ┌──────────▼──────────┐
                  │  backend (PHP) │  ─── HTTP ──►                 │  api-gateway :8080  │
                  │  Laravel 13     │  ─────────────►               │  Flask, CORS, rate  │
                  │  Session auth   │  ─────────────►               │  limit, mesh token  │
                  │  Spatie RBAC    │                               └──────────┬──────────┘
                  └───────┬────────┘                                          │
                          │                       ┌───────────────────────────┼───────────────────────────┐
                          │                       │             │              │              │            │
                  ┌───────▼──────┐      ┌──────────▼─────┐ ┌───────▼────┐ ┌──────▼─────┐ ┌───────▼────┐
                  │  postgres:5432│      │ recon :5000     │ │ security  │ │  osint     │ │  ai :5003  │
                  │  assets +     │      │ nmap nuclei ... │ │  :5001    │ │  :5002     │ │  → ollama  │
                  │  relations    │      └────────────────┘ │  + sandbox │ │ whois/dns  │ │  :11434    │
                  └───────┬───────┘                         └─────┬──────┘ └────────────┘ └──────┬─────┘
                          │                                       │                              │
                  ┌───────▼──────┐                        ┌─────▼──────┐                 ┌─────▼──────┐
                  │  redis :6379 │ ◄── streams ──────────► │  worker    │                 │  ollama    │
                  │  + AOF       │     scan:requests        │  (Python)  │                 │  qwen2.5-  │
                  └──────────────┘                         └────────────┘                 │  coder     │
                                                                                          └────────────┘
```

---

## Default credentials (after `bash scripts/setup.sh`)

All four accounts share password `password`:

| Email                       | Role      | Permissions |
|-----------------------------|-----------|-------------|
| `admin@cybersec.local`     | admin     | Full access — user mgmt, audit logs, system health, all scans |
| `analyst@cybersec.local`   | analyst   | Create projects, launch scans, view findings, generate reports |
| `client@cybersec.local`    | client    | Read-only access to reports for projects they own |
| `auditor@cybersec.local`   | auditor   | Compliance dashboard, audit log viewer (no scan execution) |

> Change these passwords immediately after install in any non-demo environment.

---

## Services inventory

| Service | Container | Port | Build context | Purpose |
|---------|-----------|------|---------------|---------|
| nginx | `cybersec-nginx` | 3000 (HTTP), 443 (host) | image only | TLS termination, security headers, rate-limit |
| backend | `cybersec-backend` | 9000 (expose), 8000 (host dev) | `./docker/backend.Dockerfile` | Laravel PHP-FPM, Spatie RBAC |
| backend-http | `cybersec-backend-http` | 8000 (internal) | reuses backend image | `php artisan serve` — HTTP face for api-gateway |
| reconnaissance | `cybersec-recon` | 5000 | `./microservices/reconnaissance` | nmap, nuclei, gobuster, subfinder (+ wpscan wrapper) |
| security | `cybersec-security` | 5001 | `./microservices/security` | Attack detection, sandbox (via socket-proxy) |
| osint | `cybersec-osint` | 5002 | `./microservices/osint` | whois, dns, ssl, subdomains, tech-stack |
| ai | `cybersec-ai` | 5003 | `./microservices/ai` | LLM analysis, remediation scripts |
| api-gateway | `cybersec-api-gateway` | 8080 | `./microservices/api-gateway` | Single mesh entrypoint, CORS, rate limit |
| worker | `cybersec-worker` | — | `./microservices/worker` | Redis Streams consumer (no HTTP) |
| postgres | `cybersec-postgres` | 5432 | image only | Primary datastore (assets + asset_relations graph tables) |
| redis | `cybersec-redis` | 6379 | image only | Queue, cache, session store |
| ollama | `cybersec-ollama` | 11434 | image only | Local LLM host |
| socket-proxy | `cybersec-socket-proxy` | 2375 | image only | Restricted Docker API for sandbox |

---

## Quick commands

```bash
# First-time install (15-20 min including model download)
bash scripts/setup.sh

# Skip the Ollama model download (AI features will be unavailable)
SKIP_MODEL_PULL=1 bash scripts/setup.sh

# Use the lighter 1.5b model instead of the 4.7 GB 7b default
OLLAMA_MODEL=qwen2.5-coder:1.5b bash scripts/setup.sh

# Force rebuild all images (after code changes)
REBUILD=1 bash scripts/setup.sh

# Stop the stack (keep data)
bash scripts/stop.sh

# Stop + wipe all data (start fresh)
bash scripts/stop.sh --purge

# Populate demo data + run sample scans against scanme.nmap.org
bash scripts/seed-demo.sh

# Tail all logs
docker compose logs -f

# Tail one service
docker compose logs -f reconnaissance

# Open a shell in the backend container
docker compose exec backend bash

# Run artisan tinker
docker compose exec backend php artisan tinker

# Verify health
curl http://localhost:3000/api/health
docker compose exec api-gateway python -c "import urllib.request;print(urllib.request.urlopen('http://localhost:8080/health/all',timeout=20).read().decode())"    # probes all downstreams
```

---

## Project structure

```
platform/
├── README.md                    ← this file
├── INSTALL.md                   ← prerequisites + step-by-step install
├── AGENTS.md                    ← machine-readable brief for AI coding assistants
├── docker-compose.yml           ← 12 services, networks, volumes
├── .env.docker.example          ← template (committed) — copy to .env.docker
├── .env.docker                  ← runtime (gitignored) — generated by setup.sh
├── composer.json                ← Laravel 13 + Spatie permission
├── package.json                 ← Vite + Tailwind 4 + Playwright
├── artisan                      ← Laravel CLI
├── app/                         ← Laravel app code
│   ├── Http/Controllers/         ← 25 controllers + Auth/Api/Admin subdirs
│   ├── Models/                  ← 15 Eloquent models
│   ├── Jobs/                    ← ExecuteScan, GenerateReport, GenerateRemediation
│   ├── Services/                ← MicroserviceClient, GraphBuilder, AuditLogger
│   └── Traits/ProcessesScanResults.php
├── bootstrap/                   ← Laravel boot (app.php, providers.php)
├── config/                      ← Laravel config (app, auth, db, queue, services, ...)
├── database/
│   ├── migrations/              ← 14 migrations (users, projects, scans, findings, ...)
│   ├── seeders/                 ← 12 seeders (Role, User, ScanProfile, Demo, ...)
│   └── factories/               ← UserFactory
├── docker/                      ← Dockerfile + nginx + php + postgres configs
│   ├── backend.Dockerfile
│   ├── nginx.conf               ← rate-limit zones, gzip, TLS defaults, CSP map
│   ├── nginx/conf.d/            ← default.conf + ssl.conf.example
│   ├── nginx/snippets/          ← proxy + security-headers + routes
│   ├── php/php.ini
│   └── postgres/init/01-init.sql
├── microservices/              ← 6 Python Flask services + worker
│   ├── reconnaissance/         ← 5 scanners, knowledge graph builder
│   ├── security/               ← attack detection, sandbox
│   ├── osint/                  ← 5 OSINT modules
│   ├── ai/                     ← Ollama client, JSON schema enforcement
│   ├── api-gateway/            ← proxy + CORS + rate limit
│   └── worker/                 ← Redis Streams consumer
├── public/                     ← Laravel public entry (index.php)
├── resources/
│   ├── views/                  ← 15 view namespaces (admin, scans, reports, ...)
│   ├── css/                    ← Tailwind entry
│   └── js/                     ← app.js, chat.js, graph.js (vis-network)
├── routes/
│   ├── web.php                 ← UI routes (auth, dashboard, scans, ...)
│   ├── api.php                 ← REST API + CI/CD webhook (scan callbacks)
│   └── console.php             ← artisan commands
├── scripts/                    ← setup.sh, stop.sh, seed-demo.sh, scan-tools.sh
├── storage/                    ← gitignored (logs, framework cache)
├── tests/                      ← PHPUnit feature/unit tests
├── workers/                    ← scan_worker.py (inline fallback when no microservice)
├── .github/workflows/          ← ci.yml (SAST/SCA/SBOM/Trivy/Cosign), deploy.yml
└── Caddyfile                   ← dev-only alternative to nginx
```

---

## Tech stack

**Backend** — Laravel 13 (PHP 8.4), Spatie permission (RBAC), session-based auth, Eloquent + PostgreSQL 16 (knowledge graph in relational `assets` / `asset_relations` tables, BFS traversal in `app/Services/GraphBuilder.php`).

**Microservices** — Python 3.11, Flask 3, gunicorn, Redis Streams consumer (custom), networkx (blast-radius BFS).

**Frontend** — Blade templates, Tailwind CSS 4, Alpine.js (via Blade directives), vis-network (graph visualization), Material Symbols Outlined icons, JetBrains Mono / Space Grotesk / Inter fonts.

**AI** — Ollama (qwen2.5-coder — 7b default, 1.5b on RAM-constrained hosts), strict JSON schema enforcement, anti-hallucination citation requirements.

**DevSecOps pipeline** (`.github/workflows/ci.yml`) — Composer audit, npm audit, Pint (formatter), gitleaks (secrets), Psalm (PHP static analysis), Bandit (Python static analysis), Trivy (container scan), Syft SBOM (CycloneDX), Cosign signing.

---

## What you can do with it

- **Defensive day demo** — `bash scripts/seed-demo.sh` populates a project, scans `scanme.nmap.org`, generates reports end-to-end
- **Real engagements** — register a Client account, upload authorization doc, analyst launches scans, AI generates remediation scripts per finding
- **CI/CD integration** — POST scans and receive callback results through the REST API (`/api/scans`, `/api/scans/{id}`, `/api/scans/{scan}/callback`) from your pipeline to scan staging on every PR
- **Compliance auditing** — Auditor role sees immutable audit log, RBAC matrix, system health dashboard

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `docker compose build` fails with "context not found" | Old clone — build contexts should point to `./microservices/*` | `git pull` then re-run setup.sh |
| All services unhealthy | `.env.docker` not generated | `bash scripts/setup.sh` (step 1 generates it) |
| Login returns 200 (still on /login page) | Seeders didn't run | `docker compose exec backend php artisan db:seed --force` |
| AI chat returns 500 | Ollama model not pulled | `docker compose exec ollama ollama pull qwen2.5-coder:7b` (or the `1.5b` tag) |
| Scans stuck in "queued" | Worker crashed or Redis down | `docker compose logs worker redis` |
| 502 on /api/* | backend-http (artisan serve) or api-gateway down | `docker compose up -d backend-http api-gateway` then check `docker compose logs api-gateway` |
| Subfinder returns empty | No API keys configured | Edit `.env.docker` and add virustotal/shodan API keys |

---

## License

This project is the graduation work of **Aymen AZIZI** (PFE 2025-2026, Private Higher School of Technologies and Engineering — TEK-UP). All rights reserved; not for redistribution without permission.

## Supervisors

- Professional: Mr Ali DORBOZ (CTO)
- Academic: Mme Sonia BEN AISSA
