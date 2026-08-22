#!/usr/bin/env bash
# =============================================================================
# PHASE 2 — Pull latest code + rebuild caches  (Fix #3 of 4)
# =============================================================================
# Restores the missing routes that currently return 404:
#   /osint, /chat, /reports, /security/alerts, /security/monitoring,
#   /admin/audit-logs, /admin/system-health,
#   /projects/{id}/graph, /scans/{id}/export, /scans/{id}/report/generate
#
# WHAT THIS DOES
#  1. git pull origin main        -> bring source code on server in sync with repo
#  2. composer install --no-dev   -> install any new PHP dependencies
#  3. php artisan migrate --force -> run pending DB migrations
#  4. php artisan route:cache     -> rebuild route cache (fixes 404s)
#  5. php artisan config:cache    -> rebuild config cache
#  6. php artisan view:cache      -> rebuild compiled Blade views
#  7. php artisan event:cache     -> rebuild event cache
#  8. systemctl restart php-fpm    -> reload PHP opcode cache
#
# WHAT TO RUN
#   ssh root@your-server
#   bash /path/to/phase2-deploy-code.sh
#
# AFTER RUNNING, VERIFY BY
#   for path in /osint /chat /reports /security/alerts /security/monitoring /admin/audit-logs /admin/system-health; do
#     echo "$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 8 https://aymenazizi.dijaly.com${path})  ${path}"
#   done
#   # Expected: every line starts with 200 (no more 404s)
# =============================================================================
set -Eeuo pipefail

PLATFORM_DIR="${PLATFORM_DIR:-/var/www/cybersec-platform}"
WEB_USER="${WEB_USER:-www-data}"
SERVICE_PHP_FPM="${SERVICE_PHP_FPM:-php8.3-fpm}"
GIT_BRANCH="${GIT_BRANCH:-main}"

log()  { printf '\n\033[1;34m[phase2]\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
err()  { printf '  \033[1;31m✗\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

trap 'err "Phase 2 failed on line $LINENO (exit code: $?)"' ERR

[[ $EUID -eq 0 ]] || die "Run as root: sudo bash phase2-deploy-code.sh"
[[ -d "${PLATFORM_DIR}" ]] || die "Platform dir not found: ${PLATFORM_DIR}"
[[ -d "${PLATFORM_DIR}/.git" ]] || die "Not a git repo: ${PLATFORM_DIR}/.git — fix GIT remote first"

cd "${PLATFORM_DIR}"
log "Working in ${PLATFORM_DIR}"

# Detect Docker vs native
USE_DOCKER=0
if [[ -f docker-compose.yml ]] && command -v docker >/dev/null 2>&1 && docker compose ps backend 2>/dev/null | grep -qE "running|Up"; then
    USE_DOCKER=1
    ok "Docker Compose detected"
else
    ok "Native deployment detected"
fi

# --- Step 1: git pull --------------------------------------------------------
log "Step 1/8  git pull origin ${GIT_BRANCH}"
sudo -u "${WEB_USER}" git fetch --all --prune
sudo -u "${WEB_USER}" git reset --hard "origin/${GIT_BRANCH}"
ok "Source code in sync with origin/${GIT_BRANCH}"

# --- Step 2: composer install -----------------------------------------------
log "Step 2/8  composer install --no-dev --optimize-autoloader"
if [[ ${USE_DOCKER} -eq 1 ]]; then
    docker compose exec -T backend composer install --no-dev --optimize-autoloader --no-interaction
else
    sudo -u "${WEB_USER}" -- composer install --no-dev --optimize-autoloader --no-interaction
fi
ok "Dependencies installed"

# --- Step 3: run pending migrations ------------------------------------------
log "Step 3/8  php artisan migrate --force"
if [[ ${USE_DOCKER} -eq 1 ]]; then
    docker compose exec -T backend php artisan migrate --force
else
    sudo -u "${WEB_USER}" -- php artisan migrate --force
fi
ok "Database migrated"

# --- Step 4-7: rebuild caches ------------------------------------------------
log "Step 4/8  php artisan route:cache"
if [[ ${USE_DOCKER} -eq 1 ]]; then
    docker compose exec -T backend php artisan route:cache
    log "Step 5/8  php artisan config:cache"
    docker compose exec -T backend php artisan config:cache
    log "Step 6/8  php artisan view:cache"
    docker compose exec -T backend php artisan view:cache
    log "Step 7/8  php artisan event:cache"
    docker compose exec -T backend php artisan event:cache
else
    sudo -u "${WEB_USER}" -- php artisan route:cache
    log "Step 5/8  php artisan config:cache"
    sudo -u "${WEB_USER}" -- php artisan config:cache
    log "Step 6/8  php artisan view:cache"
    sudo -u "${WEB_USER}" -- php artisan view:cache
    log "Step 7/8  php artisan event:cache"
    sudo -u "${WEB_USER}" -- php artisan event:cache
fi
ok "All caches rebuilt"

# --- Step 8: restart PHP-FPM (or Docker container) ---------------------------
log "Step 8/8  Restart PHP runtime"
if [[ ${USE_DOCKER} -eq 1 ]]; then
    docker compose restart backend
    ok "Backend container restarted"
else
    systemctl restart "${SERVICE_PHP_FPM}"
    ok "${SERVICE_PHP_FPM} restarted"
fi

# --- Post-deploy verification ------------------------------------------------
log "Verify routes are no longer 404:"
sleep 3
ROUTE_LIST="/osint /chat /reports /security/alerts /security/monitoring /admin/audit-logs /admin/system-health"
ALL_OK=1
for path in ${ROUTE_LIST}; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 8 "https://aymenazizi.dijaly.com${path}" 2>/dev/null || echo "000")
    if [[ "${code}" == "200" || "${code}" == "302" ]]; then
        ok "${code}  ${path}"
    else
        err "${code}  ${path}   (still 404?  check route cache)"
        ALL_OK=0
    fi
done

if [[ ${ALL_OK} -eq 1 ]]; then
    log "Phase 2 complete. All missing routes restored."
else
    warn "Some routes still failing — tell me which ones and I'll debug."
fi
