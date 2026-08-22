# Cybersec Platform Runbook (For AI and Developers)

This document contains critical instructions for running and debugging the Cybersec platform locally. **Please read this before modifying Docker commands or restarting services.**

## 1. Environment Constraints
* **WampServer Conflict:** The host machine runs WampServer on Windows, which already binds to port 80 and 3306. 
* **Port Mapping:** The Nginx container is explicitly mapped to **port 3000** (`NGINX_HTTP_PORT=3000` in `.env.docker`) to avoid conflicts with WAMP. 
* **UI URL:** Always access the platform at **http://localhost:3000** (or `http://localhost:3000/dashboard`). Do not attempt to bind Docker to port 80.

## 2. Startup Instructions

### The Easy Way (Git Bash / WSL2)
The `scripts/setup.sh` script handles everything (key generation, password randomization, DB migrations, building images, etc.).

Open a Git Bash or WSL2 terminal and run:
```bash
cd c:/wamp64/www/cybersec-workspace-full/cybersec-workspace/platform

# Standard startup (downloads 4.7GB Ollama model)
bash scripts/setup.sh

# Fast startup (skips AI model download for faster feedback loops)
SKIP_MODEL_PULL=1 bash scripts/setup.sh
```

### The Manual Way (PowerShell)
If `.env.docker` is already generated and you just need to bring the stack up or restart a container, **you must use the `--env-file` flag**.

```powershell
cd c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\platform

# Bring up the whole stack
docker compose --env-file .env.docker up -d

# Restart a specific container (e.g., nginx)
docker compose --env-file .env.docker up -d --force-recreate nginx

# Bring down the stack
docker compose --env-file .env.docker down
```

## 3. ⚠️ CRITICAL DANGER: The Redis Password Trap ⚠️

**NEVER** run `docker compose up -d` without the `--env-file .env.docker` flag.

**Why?** 
The `docker-compose.yml` file references `${REDIS_PASSWORD}` in the `REDIS_URL` environment variables for all microservices. 
By default, Docker Compose looks for a `.env` file for variable interpolation, NOT `.env.docker`. If you forget the `--env-file` flag, Compose evaluates `${REDIS_PASSWORD}` as an empty string.
1. The Redis container will fail its healthcheck.
2. Because all microservices (`backend`, `worker`, `ai`, `osint`, etc.) depend on Redis being `service_healthy`, the **entire stack will fail to start** and containers will be destroyed.

*Bad:* `source .env.docker && docker compose up -d` (Fails because `source` doesn't export variables to child processes without `set -a`).
*Good:* `docker compose --env-file .env.docker up -d`

## 4. Architecture Notes
* **Nginx Volumes:** Nginx relies on the `./public` directory being mounted to serve static Vite assets and resolve `try_files` for Laravel. This is mapped in `docker-compose.yml`.
* **Worker Healthcheck:** The Python `worker` container shares the `backend` container's network and PID namespace for security hardening. Docker-level healthchecks are disabled for it (`disable: true`) because it doesn't have its own PID 1 to probe. It is perfectly normal for it to not have a health status in `docker compose ps`.
* **Proxy Configuration:** Nginx is configured to pass `$http_host` (not `$host`) to FastCGI so that Laravel accurately detects the `3000` port and generates correct redirect URLs.
