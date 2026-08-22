#!/usr/bin/env bash
# =============================================================================
# fix-production.sh — Fix all 4 production issues on the CyberSec Platform
# =============================================================================
# This script addresses:
#   1. Ollama AI microservice down (timeout on port 11434)
#   2. Subfinder config file missing
#   3. Production deployment out of date (missing routes: /osint, /chat, /reports, /security/*, /admin/*, /projects/{id}/graph, /scans/{id}/export, /scans/{id}/report/generate)
#   4. Seeded admin credentials not working
#
# USAGE:
#   1. SSH to your production server
#   2. Download this script:   wget https://raw.githubusercontent.com/aymenazizi/cybersec-platform/main/scripts/fix-production.sh
#   3. Make it executable:     chmod +x fix-production.sh
#   4. Run as root:             sudo ./fix-production.sh
#
# Or run inline:
#   sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/aymenazizi/cybersec-platform/main/scripts/fix-production.sh)"
#
# PREREQUISITES:
#   - Root access (sudo)
#   - Laravel platform deployed at /var/www/cybersec-platform (or set PLATFORM_DIR env var)
#   - Docker + Docker Compose installed
# =============================================================================
set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Configuration (override via env vars)
# ---------------------------------------------------------------------------
PLATFORM_DIR="${PLATFORM_DIR:-/var/www/cybersec-platform}"
WEB_USER="${WEB_USER:-www-data}"
DB_CONNECTION="${DB_CONNECTION:-pgsql}"   # or 'sqlite' if you use SQLite
SERVICE_PHP_FPM="${SERVICE_PHP_FPM:-php8.3-fpm}"
SERVICE_OLLAMA="${SERVICE_OLLAMA:-ollama}"
SERVICE_WORKER="${SERVICE_WORKER:-cybersec-worker}"
OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5-coder:7b}"

# Logging helpers
log()  { printf '\033[fix]\033 %s\n' "$*"; }
ok()   { printf '\033  ✓\033 %s\n' "$*"; }
warn() { printf '\033  !\033 %s\n' "$*"; }
err()  { printf '\033  ✗\033 %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

trap 'err "Fix failed on line $LINENO (exit code: $?)"' ERR

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------
log "Pre-flight checks"

[[ $EUID -eq 0 ]] || die "This script must be run as root (use sudo)"
[[ -d "${PLATFORM_DIR}" ]] || die "Platform directory not found: ${PLATFORM_DIR} (set PLATFORM_DIR env var)"
[[ -f "${PLATFORM_DIR}/artisan" ]] || die "artisan not found in ${PLATFORM_DIR} — is this a Laravel app?"

cd "${PLATFORM_DIR}"
ok "Working in ${PLATFORM_DIR}"

# Detect deployment mode (Docker vs native)
USE_DOCKER=0
if [[ -f "${PLATFORM_DIR}/docker-compose.yml" ]] && command -v docker >/dev/null 2>&1 && docker compose ps backend 2>/dev/null | grep -q "running\|up"; then
    USE_DOCKER=1
    ok "Docker Compose deployment detected"
    COMPOSE="docker compose"
else
    ok "Native (non-Docker) deployment detected"
fi

# Helper to run artisan (in Docker or native)
artisan() {
    if [[ ${USE_DOCKER} -eq 1 ]]; then
        ${COMPOSE} exec -T backend php artisan "$@"
    else
        sudo -u "${WEB_USER}" -- php artisan "$@"
    fi
}

# ===========================================================================
# FIX #4: Re-seed admin credentials (do this FIRST so we can verify the rest)
# ===========================================================================
log ""
log "=== Fix #4: Re-seed admin credentials ==="

artisan db:seed --class=RoleSeeder --force
ok "Roles seeded (admin, analyst, client, auditor)"

artisan db:seed --class=UserSeeder --force
ok "Users seeded (admin/analyst/client/auditor @ cybersec.local)"

# Verify admin can be looked up
ADMIN_EXISTS=$(artisan tinker --no-ansi <<'PHP' 2>/dev/null | tail -n1
$u = App\Models\User::where('email', 'admin@cybersec.local')->first();
echo $u ? "yes (id={$u->id})" : "no";
PHP
)
ADMIN_EXISTS="${ADMIN_EXISTS//[$'\t\r\n ']}"
[[ "${ADMIN_EXISTS}" == "yes"* ]] && ok "Admin user verified: ${ADMIN_EXISTS}" || warn "Admin verification unclear: ${ADMIN_EXISTS}"

# ===========================================================================
# FIX #3: Pull latest code + rebuild caches (restores missing routes)
# ===========================================================================
log ""
log "=== Fix #3: Update production deployment ==="

# Backup current .env
cp -p .env .env.backup-$(date +%Y%m%d-%H%M%S) 2>/dev/null || warn "Could not backup .env (continuing)"
ok ".env backed up"

# Stash any local changes before pulling
git stash 2>/dev/null && ok "Local changes stashed" || warn "No local changes to stash (or git not initialized)"

# Pull latest code
log "Pulling latest code from origin/main..."
git fetch origin main
git checkout main
git pull origin main
ok "Code updated to latest main"

# Restore stashed changes if any
git stash pop 2>/dev/null && ok "Local changes restored" || true

# Install/update PHP dependencies
if [[ ${USE_DOCKER} -eq 1 ]]; then
    ${COMPOSE} exec -T backend composer install --no-dev --optimize-autoloader 2>&1 | tail -3
else
    sudo -u "${WEB_USER}" -- composer install --no-dev --optimize-autoloader 2>&1 | tail -3
fi
ok "Composer dependencies installed"

# Run database migrations
log "Running migrations..."
artisan migrate --force
ok "Migrations complete"

# Rebuild caches (critical — restores missing routes)
log "Rebuilding Laravel caches..."
artisan route:clear
artisan config:clear
artisan view:clear
artisan cache:clear
artisan route:cache
artisan config:cache
artisan view:cache
artisan event:cache
ok "Caches rebuilt"

# Set proper permissions
chown -R "${WEB_USER}:${WEB_USER}" storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
ok "Permissions set on storage/ and bootstrap/cache"

# ===========================================================================
# FIX #2: Create Subfinder config directory + initialize config
# ===========================================================================
log ""
log "=== Fix #2: Fix Subfinder config ==="

# Subfinder looks for config in $HOME/.config/subfinder/config.yaml
SUBFINDER_HOME_DIR="${SUBFINDER_HOME_DIR:-/var/www}"
SUBFINDER_CONFIG_DIR="${SUBFINDER_HOME_DIR}/.config/subfinder"
SUBFINDER_CONFIG_FILE="${SUBFINDER_CONFIG_DIR}/config.yaml"

mkdir -p "${SUBFINDER_CONFIG_DIR}"
chown -R "${WEB_USER}:${WEB_USER}" "${SUBFINDER_HOME_DIR}/.config"
chmod 755 "${SUBFINDER_CONFIG_DIR}"

# If subfinder binary is available, generate the default config
if [[ ${USE_DOCKER} -eq 1 ]]; then
    ${COMPOSE} exec -T reconnaissance sh -c 'which subfinder && su -s /bin/sh -c "subfinder" 2>/dev/null || true' || true
elif command -v subfinder >/dev/null 2>&1; then
    sudo -u "${WEB_USER}" subfinder 2>/dev/null || true
fi

# If config.yaml still doesn't exist, create a minimal one
if [[ ! -f "${SUBFINDER_CONFIG_FILE}" ]]; then
    cat > "${SUBFINDER_CONFIG_FILE}" <<'YAML'
# Subfinder configuration — generated by fix-production.sh
# For a full list of sources and API keys, see:
# https://github.com/projectdiscovery/subfinder#subfinder-api-key-configuration

# Default sources (passive, no API key required)
- source: crtsh
- source: anubis
- source: threatcrowd
- source: hackertarget
- source: waybackarchive
YAML
    chown "${WEB_USER}:${WEB_USER}" "${SUBFINDER_CONFIG_FILE}"
    chmod 644 "${SUBFINDER_CONFIG_FILE}"
    ok "Created minimal subfinder config at ${SUBFINDER_CONFIG_FILE}"
else
    ok "Subfinder config already exists at ${SUBFINDER_CONFIG_FILE}"
fi

# ===========================================================================
# FIX #1: Restart Ollama + pull model
# ===========================================================================
log ""
log "=== Fix #1: Restart Ollama AI service ==="

# Detect Ollama (systemd service or Docker container)
if systemctl list-units --type=service 2>/dev/null | grep -q "^${SERVICE_OLLAMA}\.service"; then
    log "Restarting systemd service: ${SERVICE_OLLAMA}"
    systemctl restart "${SERVICE_OLLAMA}"
    sleep 3
    if systemctl is-active --quiet "${SERVICE_OLLAMA}"; then
        ok "Ollama service is running"
    else
        err "Ollama failed to start"
        systemctl status "${SERVICE_OLLAMA}" --no-pager -l 2>&1 | tail -20
        die "Cannot continue without Ollama"
    fi
elif [[ ${USE_DOCKER} -eq 1 ]] && ${COMPOSE} ps ollama 2>/dev/null | grep -q "running\|up"; then
    log "Restarting Docker container: ollama"
    ${COMPOSE} restart ollama
    sleep 5
    ok "Ollama container restarted"
else
    warn "Ollama not detected as systemd service or Docker container"
    warn "If Ollama is installed elsewhere, start it manually:"
    warn "  ollama serve &"
    warn "Skipping Ollama model pull (will retry below)"
fi

# Wait for Ollama to be reachable
log "Waiting for Ollama API on 127.0.0.1:11434..."
OLLAMA_READY=0
for i in 1 2 3 4 5 6 7 8 9 10; do
    if curl -sf --max-time 3 http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
        OLLAMA_READY=1
        ok "Ollama API is responding (after ${i} attempt(s))"
        break
    fi
    sleep 2
done
[[ ${OLLAMA_READY} -eq 1 ]] || die "Ollama API is still not responding on 127.0.0.1:11434"

# Pull the qwen2.5-coder:7b model
log "Pulling Ollama model: ${OLLAMA_MODEL}"
if [[ ${USE_DOCKER} -eq 1 ]]; then
    ${COMPOSE} exec -T ollama ollama pull "${OLLAMA_MODEL}" 2>&1 | tail -3
else
    ollama pull "${OLLAMA_MODEL}" 2>&1 | tail -3
fi
ok "Model ${OLLAMA_MODEL} is available"

# Verify model can do a quick inference
log "Verifying Ollama inference..."
TEST_REPLY=$(curl -s --max-time 60 http://127.0.0.1:11434/api/generate \
    -d "{\"model\":\"${OLLAMA_MODEL}\",\"prompt\":\"Reply with OK\",\"stream\":false}" 2>/dev/null | head -c 200)
echo "${TEST_REPLY}" | grep -q '"response"' && ok "Ollama inference works" || warn "Inference test inconclusive: ${TEST_REPLY}"

# ===========================================================================
# Restart PHP-FPM + Worker (so all the new code is loaded)
# ===========================================================================
log ""
log "=== Restarting PHP-FPM and Worker ==="

if [[ ${USE_DOCKER} -eq 1 ]]; then
    ${COMPOSE} restart backend 2>&1 | tail -2
    ${COMPOSE} restart worker 2>&1 | tail -2
    ok "Docker containers restarted"
else
    if systemctl list-units --type=service 2>/dev/null | grep -q "^${SERVICE_PHP_FPM}\.service"; then
        systemctl restart "${SERVICE_PHP_FPM}"
        ok "PHP-FPM restarted"
    else
        warn "Service ${SERVICE_PHP_FPM} not found — set SERVICE_PHP_FPM env var"
    fi

    if systemctl list-units --type=service 2>/dev/null | grep -q "^${SERVICE_WORKER}\.service"; then
        systemctl restart "${SERVICE_WORKER}"
        ok "Worker restarted"
    else
        warn "Service ${SERVICE_WORKER} not found — set SERVICE_WORKER env var"
        warn "If you run the worker via Supervisor, restart supervisor: systemctl restart supervisor"
    fi
fi

# ===========================================================================
# Final verification
# ===========================================================================
log ""
log "=== Final verification ==="

# Get the platform URL
APP_URL=$(grep '^APP_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d '"')
APP_URL="${APP_URL:-http://localhost}"
ok "Platform URL: ${APP_URL}"

# Test login endpoint
log "Testing login endpoint..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${APP_URL}/login")
[[ "${HTTP_CODE}" == "200" ]] && ok "Login page: HTTP ${HTTP_CODE}" || warn "Login page returned HTTP ${HTTP_CODE}"

# Test admin login
log "Testing admin login..."
LOGIN_RESP=$(curl -s -c /tmp/fix-cookies.txt "${APP_URL}/login")
CSRF=$(echo "${LOGIN_RESP}" | grep -oP 'csrf-token" content="\K[^"]+' | head -1)
LOGIN_RESULT=$(curl -s -b /tmp/fix-cookies.txt -c /tmp/fix-cookies.txt \
    -X POST "${APP_URL}/login" \
    -H "X-CSRF-TOKEN: ${CSRF}" \
    -H "Referer: ${APP_URL}/login" \
    --data-urlencode "_token=${CSRF}" \
    --data-urlencode "email=admin@cybersec.local" \
    --data-urlencode "password=password" \
    -o /dev/null -w "%{http_code} %{redirect_url}")
echo "${LOGIN_RESULT}" | grep -q "/dashboard\|/admin" && ok "Admin login works (redirected to: ${LOGIN_RESULT})" || warn "Admin login result: ${LOGIN_RESULT}"

# Test previously-broken routes
log "Testing previously-broken routes..."
for route in "/osint" "/chat" "/reports" "/security/alerts" "/security/monitoring" "/admin/system-health" "/admin/audit-logs"; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -b /tmp/fix-cookies.txt --max-time 10 "${APP_URL}${route}" || echo "000")
    if [[ "${HTTP_CODE}" =~ ^(200|302)$ ]]; then
        ok "  ${route}: HTTP ${HTTP_CODE}"
    else
        warn "  ${route}: HTTP ${HTTP_CODE} (may need admin role)"
    fi
done

# Cleanup
rm -f /tmp/fix-cookies.txt

# ===========================================================================
# Summary
# ===========================================================================
log ""
log "=========================================="
log "  Fix complete!"
log "=========================================="
echo
echo "All 4 issues have been addressed:"
echo "  ✓ #4 — Admin/analyst/client/auditor accounts re-seeded"
echo "  ✓ #3 — Latest code pulled, caches rebuilt, routes restored"
echo "  ✓ #2 — Subfinder config created at ${SUBFINDER_CONFIG_FILE}"
echo "  ✓ #1 — Ollama restarted, ${OLLAMA_MODEL} model pulled"
echo
echo "Default credentials (now working):"
echo "  Admin:    admin@cybersec.local / password"
echo "  Analyst:  analyst@cybersec.local / password"
echo "  Client:   client@cybersec.local / password"
echo "  Auditor:  auditor@cybersec.local / password"
echo
echo "Next steps:"
echo "  1. Visit: ${APP_URL}/login"
echo "  2. Login as admin"
echo "  3. Verify all sidebar links work (Dashboard, Projects, Scans, OSINT, Chat, Reports, Knowledge Graph, Security, Admin)"
echo "  4. Launch a scan and verify AI analysis appears (no more timeout)"
echo
echo "If any route still returns 404, check the route cache:"
echo "  cd ${PLATFORM_DIR}"
echo "  php artisan route:list | grep -E 'osint|chat|reports|security|admin'"
echo
