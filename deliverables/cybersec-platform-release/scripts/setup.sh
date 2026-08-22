#!/usr/bin/env bash
# =============================================================================
# setup.sh — One-shot bootstrap for the CyberSec Platform
# =============================================================================
# This script:
#  1. Verifies prerequisites (Docker, Docker Compose v2)
#  2. Generates a real .env.docker from .env.docker.example (with random
#     secrets — never leaves "changeme-" placeholders in place)
#  3. Generates Laravel APP_KEY
#  4. Builds all Docker images
#  5. Starts postgres + redis, waits for healthy
#  6. Runs migrations + seeders (creates admin/analyst/client/auditor users)
#  7. Caches routes, config, views, events
#  8. Pulls qwen2.5-coder:7b model into the ollama container (4.7 GB — slow)
#  9. Starts all services
# 10. Runs healthcheck loop, prints final URL + credentials
#
# USAGE
#   bash scripts/setup.sh              # full bootstrap
#   REBUILD=1 bash scripts/setup.sh    # force rebuild images
#   SKIP_MODEL_PULL=1 bash scripts/setup.sh  # skip the 4.7 GB ollama pull
# =============================================================================
set -Eeuo pipefail

# --- Configuration ----------------------------------------------------------
PLATFORM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_TEMPLATE="${PLATFORM_DIR}/.env.docker.example"
ENV_FILE="${PLATFORM_DIR}/.env.docker"
COMPOSE="docker compose"
REBUILD="${REBUILD:-0}"
SKIP_MODEL_PULL="${SKIP_MODEL_PULL:-0}"
OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5-coder:7b}"

cd "${PLATFORM_DIR}"

# --- Logging helpers --------------------------------------------------------
log()  { printf '\n\033[1;34m[setup]\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
err()  { printf '  \033[1;31m✗\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

trap 'err "Setup failed on line $LINENO (exit code: $?)"' ERR

# --- Step 0: Pre-flight checks ----------------------------------------------
log "Step 0/9  Pre-flight checks"

command -v docker >/dev/null 2>&1 || die "Docker not installed. Get it from https://docs.docker.com/get-docker/"
${COMPOSE} version >/dev/null 2>&1 || die "Docker Compose v2 not available. Install: https://docs.docker.com/compose/install/"
ok "Docker + Compose v2 detected"

[[ -f "${ENV_TEMPLATE}" ]] || die "Template not found: ${ENV_TEMPLATE}"
[[ -f "${PLATFORM_DIR}/docker-compose.yml" ]] || die "docker-compose.yml not found in ${PLATFORM_DIR}"
[[ -f "${PLATFORM_DIR}/artisan" ]] || die "artisan not found — wrong directory?"
ok "Project files present"

# --- Step 1: Generate .env.docker with real secrets -------------------------
log "Step 1/9  Generate .env.docker with real secrets"

gen_secret() {
    # 32-byte URL-safe base64 secret
    docker run --rm alpine:3.20 sh -c 'head -c 32 /dev/urandom | base64 | tr -d "/+" | head -c 32' 2>/dev/null || \
    head -c 32 /dev/urandom | base64 | tr -d "/+" | head -c 32
}

if [[ ! -f "${ENV_FILE}" ]]; then
    cp "${ENV_TEMPLATE}" "${ENV_FILE}"
    ok "Copied ${ENV_TEMPLATE} → ${ENV_FILE}"
else
    warn "${ENV_FILE} already exists — preserving existing values"
fi

# Replace placeholders with real secrets only if they still say "changeme-"
ensure_secret() {
    local key="$1" placeholder="$2"
    local current
    current=$(grep -E "^${key}=" "${ENV_FILE}" | head -1 | cut -d= -f2-)
    if [[ "${current}" == "${placeholder}"* || -z "${current}" ]]; then
        local new_val
        new_val=$(gen_secret)
        # Use sed with | as delimiter since base64 secrets contain no |
        sed -i.bak "s|^${key}=.*|${key}=${new_val}|" "${ENV_FILE}" && rm -f "${ENV_FILE}.bak"
        ok "${key} replaced with generated secret"
    else
        ok "${key} already set (preserving)"
    fi
}

ensure_secret "DB_PASSWORD"            "changeme-strong-password"
ensure_secret "REDIS_PASSWORD"         "changeme-redis-password"
ensure_secret "SERVICE_MESH_TOKEN"     "changeme-service-mesh-token"
ensure_secret "API_GATEWAY_TOKEN"      "changeme-api-gateway-token"

# Generate Laravel APP_KEY (base64:random) only if placeholder
APP_KEY_CURRENT=$(grep -E "^APP_KEY=" "${ENV_FILE}" | head -1 | cut -d= -f2-)
if [[ "${APP_KEY_CURRENT}" == *"PLEASE_RUN_SETUP_SH"* || -z "${APP_KEY_CURRENT}" || "${APP_KEY_CURRENT}" == "base64:"* ]]; then
    APP_KEY_NEW=$(docker run --rm php:8.3-fpm-alpine php -r "echo 'base64:' . base64_encode(random_bytes(32));")
    sed -i.bak "s|^APP_KEY=.*|APP_KEY=${APP_KEY_NEW}|" "${ENV_FILE}" && rm -f "${ENV_FILE}.bak"
    ok "APP_KEY generated"
else
    ok "APP_KEY already set (preserving)"
fi

# Load env vars for subsequent compose commands
set -a
# shellcheck disable=SC1091
source "${ENV_FILE}"
set +a

# --- Step 2: Build images ---------------------------------------------------
log "Step 2/9  Build Docker images (this takes 5-15 minutes the first time)"

if [[ "${REBUILD}" == "1" ]]; then
    ${COMPOSE} --env-file "${ENV_FILE}" build --pull --parallel
else
    ${COMPOSE} --env-file "${ENV_FILE}" build --parallel
fi
ok "All images built"

# --- Step 3: Start postgres + redis -----------------------------------------
log "Step 3/9  Start postgres + redis"
${COMPOSE} --env-file "${ENV_FILE}" up -d postgres redis socket-proxy

# Wait for postgres to accept connections
log "  Waiting for postgres to be healthy..."
PG_ATTEMPTS=0
while [[ ${PG_ATTEMPTS} -lt 60 ]]; do
    if ${COMPOSE} --env-file "${ENV_FILE}" exec -T postgres pg_isready -U "${DB_USERNAME:-cybersec}" -d "${DB_DATABASE:-cybersec}" >/dev/null 2>&1; then
        ok "postgres ready"
        break
    fi
    PG_ATTEMPTS=$((PG_ATTEMPTS + 1))
    printf '.'
    sleep 2
done
echo
[[ ${PG_ATTEMPTS} -lt 60 ]] || die "postgres did not become healthy in 120s"

# Wait for redis
log "  Waiting for redis to be healthy..."
REDIS_ATTEMPTS=0
while [[ ${REDIS_ATTEMPTS} -lt 30 ]]; do
    if ${COMPOSE} --env-file "${ENV_FILE}" exec -T redis redis-cli -a "${REDIS_PASSWORD}" ping 2>/dev/null | grep -q PONG; then
        ok "redis ready"
        break
    fi
    REDIS_ATTEMPTS=$((REDIS_ATTEMPTS + 1))
    printf '.'
    sleep 1
done
echo
[[ ${REDIS_ATTEMPTS} -lt 30 ]] || die "redis did not become healthy in 30s"

# --- Step 4: Start backend + ollama -----------------------------------------
log "Step 4/9  Start backend + ollama"
${COMPOSE} --env-file "${ENV_FILE}" up -d backend ollama

# Wait for backend to be healthy
log "  Waiting for backend to be healthy..."
BE_ATTEMPTS=0
while [[ ${BE_ATTEMPTS} -lt 60 ]]; do
    HEALTH=$(${COMPOSE} --env-file "${ENV_FILE}" inspect --format='{{.State.Health.Status}}' backend 2>/dev/null || echo "starting")
    if [[ "${HEALTH}" == "healthy" ]]; then
        ok "backend healthy"
        break
    fi
    BE_ATTEMPTS=$((BE_ATTEMPTS + 1))
    printf '.'
    sleep 3
done
echo
[[ ${BE_ATTEMPTS} -lt 60 ]] || warn "backend not healthy after 180s — will try to continue anyway"

# --- Step 5: Run migrations + seeders ---------------------------------------
log "Step 5/9  Run migrations + seeders"
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan migrate --force
ok "Migrations complete"

${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan db:seed --class=RoleSeeder --force
ok "RBAC roles seeded (admin, analyst, client, auditor)"

${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan db:seed --class=UserSeeder --force
ok "Default users seeded (admin/analyst/client/auditor @ cybersec.local, password = 'password')"

# --- Step 6: Rebuild caches -------------------------------------------------
log "Step 6/9  Rebuild route/config/view/event caches"
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan config:cache
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan route:cache
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan view:cache
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan event:cache
${COMPOSE} --env-file "${ENV_FILE}" exec -T backend php artisan storage:link || true
ok "Caches rebuilt"

# --- Step 7: Pull Ollama model (skip-able) ----------------------------------
if [[ "${SKIP_MODEL_PULL}" == "1" ]]; then
    warn "SKIP_MODEL_PULL=1 — skipping ollama model pull (AI features will not work)"
else
    log "Step 7/9  Pull Ollama model ${OLLAMA_MODEL} (4.7 GB, takes 5-15 min)"
    # Wait for ollama API
    OLLAMA_ATTEMPTS=0
    while [[ ${OLLAMA_ATTEMPTS} -lt 60 ]]; do
        if ${COMPOSE} --env-file "${ENV_FILE}" exec -T ollama curl -sf http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
            ok "ollama API responsive"
            break
        fi
        OLLAMA_ATTEMPTS=$((OLLAMA_ATTEMPTS + 1))
        sleep 3
    done
    ${COMPOSE} --env-file "${ENV_FILE}" exec -T ollama ollama pull "${OLLAMA_MODEL}"
    ok "Model ${OLLAMA_MODEL} pulled"
fi

# --- Step 8: Start all services ---------------------------------------------
log "Step 8/9  Start all remaining services"
${COMPOSE} --env-file "${ENV_FILE}" up -d
ok "All services started"

# --- Step 9: Final healthcheck loop -----------------------------------------
log "Step 9/9  Final healthcheck (60s timeout)"
HC_ATTEMPTS=0
ALL_HEALTHY=0
while [[ ${HC_ATTEMPTS} -lt 20 ]]; do
    sleep 3
    HC_ATTEMPTS=$((HC_ATTEMPTS + 1))
    UNHEALTHY=$(${COMPOSE} --env-file "${ENV_FILE}" ps --format json 2>/dev/null | python3 -c '
import sys, json
data = json.load(sys.stdin)
unhealthy = []
for svc in data:
    name = svc.get("Service", "")
    health = svc.get("Health", "unknown")
    state = svc.get("State", "")
    if state == "running" and health not in ("healthy", "none", "", None):
        unhealthy.append(f"{name}:{health}")
print(" ".join(unhealthy) if unhealthy else "")
' 2>/dev/null || echo "")

    if [[ -z "${UNHEALTHY}" ]]; then
        ALL_HEALTHY=1
        ok "All services healthy"
        break
    fi
    printf '.'
done
echo
[[ ${ALL_HEALTHY} -eq 1 ]] || warn "Some services still starting up — give them 60s and run: ${COMPOSE} ps"

# --- Print summary ----------------------------------------------------------
echo
log "Setup complete!"
echo
echo "  Platform URL:    http://localhost:${NGINX_HTTP_PORT:-80}"
echo "  Backend dev URL:  http://localhost:${BACKEND_DEV_PORT:-8000}"
echo
echo "  Default users (password = 'password' for all):"
echo "    admin@cybersec.local    (full access)"
echo "    analyst@cybersec.local  (scan execution)"
echo "    client@cybersec.local   (read-only reports)"
echo "    auditor@cybersec.local  (compliance/log viewing)"
echo
echo "  Verify health:"
echo "    ${COMPOSE} ps"
echo "    curl http://localhost:${NGINX_HTTP_PORT:-80}/api/health"
echo
echo "  Stop the stack:"
echo "    bash scripts/stop.sh"
echo
echo "  Re-run this script anytime — it is idempotent."
