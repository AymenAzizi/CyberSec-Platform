# INSTALL.md — CyberSec Platform

Step-by-step install guide. Pick the section that matches your OS.

## Table of contents

1. [Prerequisites (all OS)](#1-prerequisites-all-os)
2. [Linux / macOS install](#2-linux--macos-install)
3. [Windows / WSL2 install](#3-windows--wsl2-install)
4. [Verify the install](#4-verify-the-install)
5. [Production deployment](#5-production-deployment)
6. [Common issues](#6-common-issues)

---

## 1. Prerequisites (all OS)

| Tool | Version | Why | Verify |
|------|---------|-----|--------|
| Docker Engine | 24.0+ | runs the 12-service stack | `docker --version` |
| Docker Compose | v2.20+ | service orchestration | `docker compose version` |
| Git | 2.30+ | clone the repo | `git --version` |
| 6 GB free RAM | — | backend 1 GB + recon 1 GB + ai 1 GB + ollama 4 GB ceiling | — |
| 25 GB free disk | — | postgres + redis + ollama model + scan results | `df -h .` |
| Outbound internet | — | Ollama model download, Docker image pulls | — |

**No PHP, Composer, Node, or Python required on the host** — everything runs inside containers. You only need Docker.

> **Why so much RAM?** The Ollama container has a 4 GB ceiling (qwen2.5-coder:7b inferences). If you skip the model pull with `SKIP_MODEL_PULL=1 bash scripts/setup.sh`, you can get away with 4 GB total.

---

## 2. Linux / macOS install

```bash
# 1. Clone the repository
git clone https://github.com/aymenazizi/cybersec-platform.git
cd cybersec-platform

# 2. Make scripts executable
chmod +x scripts/*.sh

# 3. Run the one-shot bootstrap (15-20 minutes the first time)
bash scripts/setup.sh
```

What happens during setup.sh:

1. ✅ Pre-flight: Docker + Compose version check
2. 🔑 Generates `.env.docker` from `.env.docker.example` with random 32-byte secrets for `DB_PASSWORD`, `REDIS_PASSWORD`, `SERVICE_MESH_TOKEN`, `API_GATEWAY_TOKEN`
3. 🔑 Generates Laravel `APP_KEY` (base64:random)
4. 🏗 Builds all 6 custom Docker images in parallel (5-10 min)
5. 🐘 Starts postgres + redis + socket-proxy, waits for healthy
6. 🚀 Starts backend + ollama, waits for healthy
7. 📦 Runs `php artisan migrate --force` (creates 14 tables)
8. 🌱 Runs `RoleSeeder` (creates admin/analyst/client/auditor roles) + `UserSeeder` (creates 4 users with password = 'password')
9. ⚡ Rebuilds config/route/view/event caches
10. 🤖 Pulls `qwen2.5-coder:7b` model into the ollama container (4.7 GB, 5-15 min — skipped if `SKIP_MODEL_PULL=1`)
11. 🌐 Starts all 12 services
12. 🩺 Runs healthcheck loop, prints final URLs + credentials

---

## 3. Windows / WSL2 install

**You must use WSL2.** Docker Desktop on Windows native does not support the network segmentation required by this stack.

### Step 3a. Install WSL2 + Docker Desktop

```powershell
# In PowerShell as Administrator
wsl --install -d Ubuntu-22.04
# Reboot when prompted

# After reboot, install Docker Desktop from:
# https://www.docker.com/products/docker-desktop
# In Docker Desktop settings, enable "Use the WSL 2 based engine"
#   and under "Resources → WSL Integration", enable Ubuntu-22.04
```

### Step 3b. Inside WSL2 (Ubuntu)

```bash
# Open the Ubuntu terminal (Start → type "Ubuntu")
# Then:
sudo apt update && sudo apt install -y git curl

git clone https://github.com/aymenazizi/cybersec-platform.git
cd cybersec-platform

chmod +x scripts/*.sh
bash scripts/setup.sh
```

### Step 3c. Access from Windows

Open in your Windows browser: `http://localhost` (or `http://localhost:80` if port 80 is taken).

---

## 4. Verify the install

After `bash scripts/setup.sh` finishes, run these checks:

```bash
# 1. All 12 containers should be "running" or "healthy"
docker compose ps

# Expected output (12 services):
# NAME                     STATUS                   PORTS
# cybersec-nginx           Up (healthy)             80, 443
# cybersec-backend         Up (healthy)             9000
# cybersec-recon           Up (healthy)             5000
# cybersec-security        Up (healthy)             5001
# cybersec-osint           Up (healthy)             5002
# cybersec-ai              Up (healthy)             5003
# cybersec-api-gateway     Up (healthy)             8080
# cybersec-worker          Up
# cybersec-postgres        Up (healthy)             5432
# cybersec-redis           Up (healthy)             6379
# cybersec-ollama          Up (healthy)             11434
# cybersec-socket-proxy    Up                       2375

# 2. Laravel health endpoint
curl http://localhost/api/health
# Expected: {"status":"ok","services":{"reconnaissance":"ok",...}}

# 3. Aggregated health (api-gateway probes all downstreams)
curl http://localhost/api/health/all
# Expected: JSON with every service marked "ok"

# 4. Login as admin
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost/login \
    -d 'email=admin@cybersec.local&password=password'
# Expected: 302  (302 = redirect to /dashboard = login succeeded)

# 5. Open the UI
# In your browser: http://localhost
# Login: admin@cybersec.local / password
```

---

## 5. Production deployment

This stack is ready for production with three changes:

### 5a. Generate real secrets (the ones in `.env.docker.example` are placeholders)

```bash
# Inside .env.docker, replace:
DB_PASSWORD=changeme-strong-password          # already replaced by setup.sh
REDIS_PASSWORD=changeme-redis-password        # already replaced by setup.sh
SERVICE_MESH_TOKEN=changeme-service-mesh-token # already replaced by setup.sh

# Add your own:
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
CYBERSEC_DOMAIN=your-domain.com
LETSENCRYPT_EMAIL=you@your-domain.com
LETSENCRYPT_STAGING=false    # IMPORTANT: set to false for real certs
NGINX_HTTPS_PORT=443

# Add API keys for better subfinder coverage (optional):
VT_API_KEY=your-virustotal-key
SHODAN_API_KEY=your-shodan-key
```

### 5b. Enable TLS

Edit `docker/nginx/conf.d/default.conf` and uncomment the HTTPS server block, or copy `ssl.conf.example` → `ssl.conf` and add your cert paths.

For Let's Encrypt automated cert renewal, uncomment the `certbot` service in `docker-compose.yml` (not enabled by default to avoid rate-limit hits during dev).

### 5c. Run the deployment

```bash
bash scripts/setup.sh    # idempotent — safe to re-run on production
```

---

## 6. Common issues

### "Cannot connect to the Docker daemon"

Docker isn't running. Start it:
- Linux: `sudo systemctl start docker`
- macOS: open Docker Desktop
- Windows WSL2: open Docker Desktop

### "Permission denied" when running docker

Add yourself to the docker group (Linux only):
```bash
sudo usermod -aG docker $USER
# Log out and back in for the change to take effect
```

### Build fails on `reconnaissance` image with "package not found"

You're probably on an old apt mirror. Rebuild with:
```bash
REBUILD=1 bash scripts/setup.sh
```

If it still fails, check your outbound internet from inside Docker:
```bash
docker run --rm alpine ping -c 3 deb.debian.org
```

### Login returns 200 instead of 302

The seeders didn't run. Re-run them:
```bash
docker compose exec backend php artisan db:seed --class=RoleSeeder --force
docker compose exec backend php artisan db:seed --class=UserSeeder --force
```

### Scans stay in "queued" forever

The worker is not consuming the queue. Check:
```bash
docker compose logs worker
# Should show: "Waiting for scan:requests stream..."
```

If you see connection errors, restart the worker:
```bash
docker compose restart worker
```

### Ollama times out on first inference

The model isn't pulled yet. Pull it:
```bash
docker compose exec ollama ollama pull qwen2.5-coder:7b
# 4.7 GB — takes 5-15 min depending on bandwidth
```

### "Port 80 is already in use"

Something else is using port 80 on your host. Change it:
```bash
# In .env.docker, change:
NGINX_HTTP_PORT=8080
# Then restart nginx:
docker compose up -d nginx
```

Now access the platform at `http://localhost:8080`.

---

## Stop / restart / wipe

```bash
# Stop (keep all data)
bash scripts/stop.sh

# Restart
bash scripts/stop.sh   # stop
bash scripts/setup.sh  # restart (idempotent)

# Wipe all data (postgres, redis, ollama model, scan results)
bash scripts/stop.sh --purge
# Type "yes, wipe everything" when prompted
```

---

## Where to go next

- Read [README.md](./README.md) for the architecture overview and feature inventory
- Read [AGENTS.md](./AGENTS.md) if you're an AI coding assistant picking up this codebase
- Run `bash scripts/seed-demo.sh` to populate demo data and verify the full scan → report pipeline
- Check `docker compose logs -f` to see live activity
