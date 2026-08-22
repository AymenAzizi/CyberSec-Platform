# CyberSec Platform — Attack Surface Reconnaissance with AI-Assisted Report Generation

A web platform for security reconnaissance that orchestrates 5 tools (nmap, nuclei, gobuster, subfinder, wpscan), builds a knowledge graph of the attack surface, and generates AI-assisted remediation reports — all behind an event-driven worker queue with a strict DevSecOps pipeline.

> **One-shot install** (skip to [INSTALL.md](./INSTALL.md) for prerequisites + step-by-step):
> ```bash
> bash scripts/setup.sh
> ```
> The first build takes ~15 minutes (mostly the 4.7 GB Ollama model download).
> Once it finishes, the platform is live at http://localhost with 4 default users.

---

## Architecture in one paragraph

Laravel 11 (PHP 8.3) serves the web UI and the REST API. Five Python Flask microservices do the actual work: `reconnaissance` (port 5000, runs nmap/nuclei/gobuster/subfinder/wpscan), `security` (5001, attack detection + Docker sandbox), `osint` (5002, whois/dns/ssl/subdomains/tech-stack), `ai` (5003, calls Ollama), and `api-gateway` (8080, single entrypoint for the Laravel app). A Python `worker` consumes the Redis Streams queue (`scan:requests`) and dispatches scans. PostgreSQL 16 stores normalized findings + the knowledge graph (assets + asset_relations). Redis 7 holds the queue, cache, and session store. Ollama runs `qwen2.5-coder:7b` locally for AI analysis + remediation generation. Nginx terminates TLS and reverse-proxies everything.

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
                  │  Laravel 11     │  ─────────────►               │  Flask, CORS, rate  │
                  │  Sanctum auth   │  ─────────────►               │  limit, mesh token  │
                  │  Spatie RBAC    │                               └──────────┬──────────┘
                  └───────┬────────┘                                          │
                          │                       ┌───────────────────────────┼───────────────────────────┐
                          │                       │             │              │              │            │
                  ┌───────▼──────┐      ┌──────────▼─────┐ ┌───────▼────┐ ┌──────▼─────┐ ┌───────▼────┐
                  │  postgres:5432│      │ recon :5000     │ │ security  │ │  osint     │ │  ai :5003  │
                  │  + Apache AGE │      │ nmap nuclei ... │ │  :5001    │ │  :5002     │ │  → ollama  │
                  └───────────────┘      └────────────────┘ │  + sandbox │ │ whois/dns  │ │  :11434    │
                          │                                └─────┬──────┘ └────────────┘ └──────┬─────┘
                          │                                      │                              │
                  ┌───────▼──────┐                        ┌─────▼──────┐                 ┌─────▼──────┐
                  │  redis :6379 │ ◄── streams ──────────► │  worker    │                 │  ollama    │
                  │  + AOF       │     scan:requests        │  (Python)  │                 │  qwen2.5-  │
                  └──────────────┘                         └────────────┘                 │  coder:7b  │
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
| nginx | `cybersec-nginx` | 80, 443 (host) | image only | TLS termination, security headers, rate-limit |
| backend | `cybersec-backend` | 9000 (expose), 8000 (host dev) | `./docker/backend.Dockerfile` | Laravel PHP-FPM, Sanctum, Spatie RBAC |
| reconnaissance | `cybersec-recon` | 5000 | `./microservices/reconnaissance` | nmap, nuclei, gobuster, subfinder, wpscan |
| security | `cybersec-security` | 5001 | `./microservices/security` | Attack detection, sandbox (via socket-proxy) |
| osint | `cybersec-osint` | 5002 | `./microservices/osint` | whois, dns, ssl, subdomains, tech-stack |
| ai | `cybersec-ai` | 5003 | `./microservices/ai` | LLM analysis, remediation scripts |
| api-gateway | `cybersec-api-gateway` | 8080 | `./microservices/api-gateway` | Single mesh entrypoint, CORS, rate limit |
| worker | `cybersec-worker` | — | `./microservices/worker` | Redis Streams consumer (no HTTP) |
| postgres | `cybersec-postgres` | 5432 | image only | Primary datastore, Apache AGE |
| redis | `cybersec-redis` | 6379 | image only | Queue, cache, session store |
| ollama | `cybersec-ollama` | 11434 | image only | Local LLM host |
| socket-proxy | `cybersec-socket-proxy` | 2375 | image only | Restricted Docker API for sandbox |

---

## Quick commands

```bash
# First-time install (15-20 min including model download)
bash scripts/setup.sh

# Skip the 4.7 GB Ollama download (AI features will be unavailable)
SKIP_MODEL_PULL=1 bash scripts/setup.sh

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
curl http://localhost/api/health
curl http://localhost/api/health/all    # all downstream services
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
├── composer.json                ← Laravel 11 + Sanctum + Spatie permission
├── package.json                 ← Vite + Tailwind 4 + Playwright
├── artisan                      ← Laravel CLI
├── app/                         ← Laravel app code
│   ├── Http/Controllers/         ← 12 controllers + Auth/Api/Admin subdirs
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
│   ├── api.php                 ← Sanctum-authenticated REST + CI/CD webhook
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

**Backend** — Laravel 11 (PHP 8.3), Sanctum (token auth), Spatie permission (RBAC), Eloquent + PostgreSQL 16 + Apache AGE (graph extension).

**Microservices** — Python 3.11, Flask 3, gunicorn, Redis Streams consumer (custom), networkx (blast-radius BFS).

**Frontend** — Blade templates, Tailwind CSS 4, Alpine.js (via Blade directives), vis-network (graph visualization), Material Symbols Outlined icons, JetBrains Mono / Space Grotesk / Inter fonts.

**AI** — Ollama (qwen2.5-coder:7b, 4.7 GB, 24h keep-alive), strict JSON schema enforcement, anti-hallucination citation requirements.

**DevSecOps pipeline** (`.github/workflows/ci.yml`) — Composer audit, npm audit, Psalm (static analysis), PHPStan, Larastan, Trivy (container scan), Syft SBOM, Cosign signing, GitHub Actions artifact attestation.

---

## What you can do with it

- **Defensive day demo** — `bash scripts/seed-demo.sh` populates a project, scans `scanme.nmap.org`, generates reports end-to-end
- **Real engagements** — register a Client account, upload authorization doc, analyst launches scans, AI generates remediation scripts per finding
- **CI/CD integration** — call the Sanctum-authenticated API from your pipeline to scan staging on every PR
- **Compliance auditing** — Auditor role sees immutable audit log, RBAC matrix, system health dashboard

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `docker compose build` fails with "context not found" | Old clone — build contexts should point to `./microservices/*` | `git pull` then re-run setup.sh |
| All services unhealthy | `.env.docker` not generated | `bash scripts/setup.sh` (step 1 generates it) |
| Login returns 200 (still on /login page) | Seeders didn't run | `docker compose exec backend php artisan db:seed --force` |
| AI chat returns 500 | Ollama model not pulled | `docker compose exec ollama ollama pull qwen2.5-coder:7b` |
| Scans stuck in "queued" | Worker crashed or Redis down | `docker compose logs worker redis` |
| 502 on /api/* | api-gateway unhealthy | `docker compose logs api-gateway` |
| Subfinder returns empty | No API keys configured | Edit `.env.docker` and add virustotal/shodan API keys |

---

## License

This project is the graduation work of **Aymen AZIZI** (PFE 2025-2026, Private Higher School of Technologies and Engineering — TEK-UP). All rights reserved; not for redistribution without permission.

## Supervisors

- Professional: Mr Ali DORBOZ (CTO)
- Academic: Mme Sonia BEN AISSA
