# AGENTS.md — Brief for AI coding assistants (Antigravity, Cursor, Claude Code, etc.)

> **You are an AI coding assistant picking up this codebase. Read this file first.**

This document tells you what the codebase is, how it's organized, what's safe to change, and where the sharp edges are. Skim the table of contents, then read the sections relevant to your task.

---

## TL;DR for any task

1. **This is a Laravel 13 + 5 Python microservices + Docker project.**
2. **One command to bootstrap:** `bash scripts/setup.sh` (15-20 min, mostly the Ollama model download).
3. **All secrets are auto-generated** — never hardcode passwords, API keys, or paths.
4. **The 4 default users** all use password `password` (see [Default credentials](#default-credentials)).
5. **Code lives in `/home/z/my-project/platform/`** if you're in the dev sandbox. Paths inside containers are `/var/www/html/` (backend) and `/app/` (Python services).

---

## 1. What this project is

A graduation project (PFE 2025-2026) by Aymen AZIZI for the National Engineering Diploma in Computer and Network Security Systems at TEK-UP (Tunis). It is a web platform that orchestrates the security tools nmap, nuclei, gobuster and subfinder (plus a wpscan wrapper that degrades gracefully when the binary is absent), builds a knowledge graph of the attack surface, and generates AI-assisted remediation reports. The whole thing runs in 12 Docker containers (+1 backend-http override service) behind a hardened Nginx reverse proxy.

The compliance target is the project's "Cahier des Charges" (CDC) document, which is ~90% satisfied as of the last commit. Known gaps: DAST in CI, Policy-as-Code, NFR performance targets, RGPD retention enforcement, expirable share links.

---

## 2. Architecture (read this before touching anything)

```
Nginx (:3000/:443, host-exposed)
   ├── Laravel backend (:9000, PHP-FPM 8.4, exposes host :8000 for dev)
   │     ├── Session auth (web UI)
   │     ├── Spatie permission (RBAC: admin/analyst/client/auditor)
   │     └── Talks to api-gateway for scans, OSINT, AI chat
   ├── backend-http (:8000, artisan serve via docker-compose.override.yml) — HTTP face of Laravel for the gateway
   ├── api-gateway (:8080, Flask)
   │     └── Routes /api/recon/* → :5000, /api/security/* → :5001,
   │                  /api/osint/*   → :5002, /api/ai/*       → :5003
   ├── reconnaissance (:5000, Flask) — nmap, nuclei, gobuster, subfinder (+ wpscan wrapper)
   ├── security (:5001, Flask) — attack detection + Docker sandbox (via socket-proxy)
   ├── osint (:5002, Flask) — whois, dns, ssl, subdomains, tech-stack
   ├── ai (:5003, Flask) — Ollama client (qwen2.5-coder)
   ├── worker (no port, Python) — Redis Streams consumer
   ├── postgres (:5432, Postgres 16 — graph in assets/asset_relations tables)
   ├── redis (:6379, Redis 7 + AOF + password)
   ├── ollama (:11434, qwen2.5-coder — 7b default / 1.5b on light hosts)
   └── socket-proxy (:2375, tecnativa/docker-socket-proxy — restricted Docker API)
```

**Two networks**: `cybersec-external` (nginx only, has egress) and `cybersec-internal` (everything else, `internal: true`, no egress). Workers run `read_only: true` with `cap_drop: [ALL]` and `no-new-privileges`. **Do not weaken this without a security review.**

---

## 3. Repository layout (what lives where)

```
platform/
├── app/                       # Laravel app code (PHP 8.4)
│   ├── Http/Controllers/      # 25 controllers across root + Auth/ + Api/ + Admin/
│   ├── Models/                # 15 Eloquent models
│   ├── Jobs/                  # ExecuteScan, GenerateReport, GenerateRemediation
│   ├── Services/              # MicroserviceClient, GraphBuilder, AuditLogger
│   └── Traits/
├── bootstrap/                 # Laravel 13 boot config
├── config/                    # Laravel config files (services.php is the big one)
├── database/
│   ├── migrations/            # 14 migrations — version-controlled schema
│   ├── seeders/               # 12 seeders (Role, User, ScanProfile, Project, Scan, ...)
│   └── factories/
├── docker/                   # Dockerfiles + nginx + php + postgres configs
├── microservices/            # 6 Python services (Flask + worker)
│   ├── reconnaissance/       # 5 scanners + knowledge graph builder
│   ├── security/             # Attack detection + sandbox
│   ├── osint/                # 5 OSINT modules
│   ├── ai/                   # Ollama client
│   ├── api-gateway/          # Proxy + CORS + rate limit
│   └── worker/               # Redis Streams consumer (no HTTP)
├── public/                   # Laravel public entry
├── resources/
│   ├── views/                # 15 view namespaces (admin, auth, chat, scans, reports, ...)
│   ├── css/                  # Tailwind 4 entry
│   └── js/                   # app.js, chat.js, graph.js (vis-network)
├── routes/
│   ├── web.php               # UI routes — login, dashboard, projects, scans, reports
│   ├── api.php               # REST API + CI/CD webhook (scan callbacks)
│   └── console.php           # artisan commands
├── scripts/                  # setup.sh, stop.sh, seed-demo.sh, scan-tools.sh
├── tests/                    # PHPUnit tests (currently minimal)
├── workers/                  # scan_worker.py (inline fallback when no microservice)
├── .github/workflows/        # ci.yml (SAST/SCA/SBOM/Trivy/Cosign), deploy.yml
├── .env.docker.example       # Template (committed) — copy to .env.docker
├── .env.docker                # Runtime secrets (gitignored, generated by setup.sh)
├── docker-compose.yml        # 12 services, networks, volumes
├── composer.json             # Laravel 13 + Spatie permission
├── package.json              # Vite + Tailwind 4 + Playwright
└── README.md / INSTALL.md / AGENTS.md (this file)
```

---

## 4. Default credentials (after `bash scripts/setup.sh`)

| Email | Role | Password | What they can do |
|---|---|---|---|
| `admin@cybersec.local` | admin | `password` | Full access — user mgmt, audit logs, system health, all scans |
| `analyst@cybersec.local` | analyst | `password` | Create projects, launch scans, view findings, generate reports |
| `client@cybersec.local` | client | `password` | Read-only access to reports for projects they own |
| `auditor@cybersec.local` | auditor | `password` | Compliance dashboard, audit log viewer (no scan execution) |

The seeder is `database/seeders/UserSeeder.php` — change passwords there for production.

---

## 5. Critical conventions (do not violate)

### 5a. No hardcoded absolute paths

Every path must come from `config(...)` or `env(...)`. Examples:

```php
// ❌ FORBIDDEN
$pythonBin = '/usr/local/bin/python3';
$scriptPath = '/home/z/my-project/platform/workers/scan_worker.py';

// ✅ CORRECT
$pythonBin = config('services.python.binary', 'python3');
$scriptPath = base_path('workers/scan_worker.py');
```

The current codebase enforces this. **If you add new code, follow the pattern.**

### 5b. All env vars must have defaults in `config/services.php`

When you add a new env-var-driven config, register it in `config/services.php` so `config:cache` picks it up and so Antigravity (or another agent) can discover it.

```php
// config/services.php
'my_feature' => [
    'url'     => env('MY_FEATURE_URL', 'http://my-feature:8080'),
    'timeout' => (int) env('MY_FEATURE_TIMEOUT', 30),
],
```

And reference the env var in `.env.docker.example` so users see it:

```bash
# .env.docker.example
MY_FEATURE_URL=http://my-feature:8080
MY_FEATURE_TIMEOUT=30
```

### 5c. Healthchecks use `/health` (no `z`)

Every Flask service exposes `GET /health` returning `{"status":"ok",...}`. Healthchecks in `docker-compose.yml` MUST use `/health`, not `/healthz`.

### 5d. The api-gateway listens on port 8080 (not 5000)

If you see `api-gateway:5000` anywhere in PHP code, that's a bug. Fix it to `api-gateway:8080`.

### 5e. Build contexts point to `./microservices/*` (not `./services/*`)

If you see `context: ./services/reconnaissance` in `docker-compose.yml` or `.github/workflows/*.yml`, that's a bug. Fix to `./microservices/reconnaissance`.

### 5f. The worker has no HTTP port

The worker is a Python process that consumes Redis Streams. It does not expose a port. Its healthcheck uses `os.kill(1, 0)` (PID 1 liveness only — weak but that's the design).

### 5g. The security service sandbox goes through `socket-proxy`, not the Docker socket

The `security` Flask service uses `DOCKER_HOST=tcp://socket-proxy:2375`. The socket-proxy whitelists only container list/create/stop endpoints. **Do not** mount `/var/run/docker.sock` directly into the security container — that defeats the entire security model.

---

## 6. Common tasks you might be asked to do

### "Add a new microservice"

1. Create `microservices/<name>/` with:
   - `app.py` (Flask app with `GET /health` returning `{"status":"ok"}`)
   - `requirements.txt`
   - `Dockerfile` (UID 10001-10099 range, `read_only: true`, `cap_drop: [ALL]`)
2. Add the service to `docker-compose.yml`:
   - `build: context: ./microservices/<name>`
   - `networks: [cybersec-internal]`
   - `<<: *worker-security` (YAML merge key for security defaults)
   - `env_file: [.env.docker]`
3. Add the routing rule in `microservices/api-gateway/app.py`:
   ```python
   ROUTE_MAP = {
       '/api/recon/':     ('reconnaissance', 5000),
       '/api/security/':  ('security',       5001),
       '/api/osint/':     ('osint',           5002),
       '/api/ai/':        ('ai',              5003),
       '/api/<your>/':    ('<your-service>',  <port>),  # ← add this
   }
   ```
4. Add env vars to `.env.docker.example`:
   ```bash
   YOUR_SERVICE_URL=http://<your-service>:<port>
   ```
5. Add a Laravel client in `app/Services/MicroserviceClient.php` if Laravel needs to call it directly.

### "Add a new Laravel route"

1. Add to `routes/web.php` (UI) or `routes/api.php` (REST):
   ```php
   Route::get('/my-route', [MyController::class, 'index'])->name('my-route.index');
   ```
2. Add `MyController` in `app/Http/Controllers/`. Follow the pattern of an existing controller (e.g., `ProjectController`).
3. Add the view in `resources/views/my-controller/`.
4. **Rebuild the route cache**: `docker compose exec backend php artisan route:cache`

### "Add a new DB migration"

```bash
docker compose exec backend php artisan make:migration create_my_table
# Edit the generated file in database/migrations/
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan route:cache  # if a model is added
```

### "Add a new env var"

1. Add the var to `.env.docker.example` with a sane default
2. Read it in `config/services.php` (or another config file):
   ```php
   'my_section' => ['my_var' => env('MY_VAR', 'default-value')],
   ```
3. Reference it via `config('services.my_section.my_var')` — never `env()` directly in non-config files (it breaks `config:cache`)

### "Rebuild after code changes"

```bash
# Rebuild everything
REBUILD=1 bash scripts/setup.sh

# Rebuild only one service
docker compose build reconnaissance
docker compose up -d reconnaissance

# Backend PHP code does NOT require rebuild — Laravel reads files at runtime
# (unless opcache is on; clear it with: docker compose exec backend php artisan optimize:clear)
```

---

## 7. Testing

The test suite is currently minimal (only `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php`). To add tests:

```bash
docker compose exec backend php artisan make:test MyFeatureTest
docker compose exec backend php artisan test
```

For Python microservices, no test framework is set up. If you add tests, use pytest and add a `tests/` dir in each microservice folder.

---

## 8. CI/CD pipeline (`.github/workflows/ci.yml`)

Every PR triggers:

1. PHP: `composer install`, `php artisan test`, Psalm static analysis, Larastan
2. JS: `npm ci`, `npm run build`
3. Security: Composer audit, npm audit, Trivy container scan, Syft SBOM
4. Build: All 6 Docker images built, tagged with SHA
5. Sign: Cosign signs each image
6. Attest: GitHub artifact attestation for provenance

**Do not** disable the security gates. If a gate fails, fix the issue — don't bypass.

---

## 9. Sharp edges / known issues

- **`tests/` is bare** — adding meaningful feature tests is welcome
- **Dual auth controllers** in `app/Http/Controllers/Auth/` — `RegisterController` is the one wired in `routes/web.php`. The Fortify-style ones (`AuthenticatedSessionController`, `RegisteredUserController`, etc.) are dead code from an earlier scaffold; safe to remove if you do an auth refactor
- **`workers/scan_worker.py` is a 1200-line monolith** — it's the inline fallback when the recon microservice is unreachable. It does NOT use Redis (direct SQLite writes via `DB_PATH` env var)
- **`Caddyfile` is dev-only** — don't use Caddy in production; the nginx config in `docker/nginx/` is the production path
- **`screenshot.cjs` and `dash_shot.cjs` use Playwright** to capture PNGs from a running instance; they write to `~/Pictures/cybersec-platform/` by default (override with `OUTPUT_DIR=...`)

---

## 10. How to verify your changes didn't break anything

After editing, run this checklist:

```bash
# 1. PHP syntax (run inside backend container or with php installed)
docker compose exec backend php -l app/Http/Controllers/MyController.php

# 2. Python syntax (run inside any Python service container)
docker compose exec reconnaissance python -m py_compile app.py

# 3. Bash script syntax
bash -n scripts/my-script.sh

# 4. YAML syntax
python3 -c "import yaml; yaml.safe_load(open('docker-compose.yml'))"

# 5. Laravel config cache rebuild (catches missing env vars)
docker compose exec backend php artisan config:cache

# 6. Route cache rebuild (catches route syntax errors)
docker compose exec backend php artisan route:cache

# 7. Health endpoints
curl http://localhost:3000/api/health
docker compose exec api-gateway python -c "import urllib.request;print(urllib.request.urlopen('http://localhost:8080/health/all',timeout=20).read().decode())"

# 8. Login still works
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost/login \
    -d 'email=admin@cybersec.local&password=password'
# Expected: 302
```

If all 8 pass, your changes are safe to commit.

---

## 11. When in doubt

- Read `README.md` for the architecture overview
- Read `INSTALL.md` for setup troubleshooting
- Read `docker-compose.yml` for the source of truth on service wiring
- Read `config/services.php` for the source of truth on env-var-driven config
- Read `routes/web.php` for the source of truth on URL → controller mapping
- Read `database/migrations/` for the source of truth on the DB schema
- Read `microservices/*/app.py` for the source of truth on Flask endpoints

The codebase is small enough to read end-to-end in an afternoon. Do that before making non-trivial changes.

---

## 12. File/line numbers you'll likely touch

| Task | Files |
|------|-------|
| Add a new RBAC role | `database/seeders/RoleSeeder.php`, `database/seeders/UserSeeder.php`, `config/services.php` (rbac.roles), `app/Http/Controllers/Auth/RegisterController.php` |
| Add a new scan tool | `microservices/reconnaissance/services/<Tool>Service.py`, `app/Services/MicroserviceClient.php` (SCAN_TYPE_ROUTES), `database/seeders/ScanProfileSeeder.php` |
| Add a new finding type | `database/migrations/2026_01_01_000007_create_findings_table.php`, `app/Models/Finding.php`, `microservices/reconnaissance/utils/result_parser.py` |
| Change the dashboard layout | `resources/views/dashboard/index.blade.php`, `resources/views/layouts/app.blade.php` |
| Add a new AI prompt template | `microservices/ai/services/ai_analyzer.py`, `app/Jobs/GenerateRemediation.php` |
| Add a new audit log action | `app/Services/AuditLogger.php`, `app/Http/Middleware/AuditMiddleware.php` |

---

**End of AGENTS.md. You should now have enough context to make safe changes.**
