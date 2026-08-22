#!/usr/bin/env bash
# =============================================================================
# PHASE 1 — Re-seed admin credentials  (Fix #4 of 4)
# =============================================================================
# Restores: admin@cybersec.local / password  (and analyst/client/auditor)
#
# WHAT THIS DOES
#   1. cd into the Laravel platform dir
#  2. Run RoleSeeder  -> recreates 4 RBAC roles (admin, analyst, client, auditor)
#  3. Run UserSeeder   -> recreates 4 users with password = "password"
#  4. Verify by looking up admin@cybersec.local in the DB
#
# WHAT TO RUN
#   ssh root@your-server
#   bash /path/to/phase1-admin-seed.sh
#
# AFTER RUNNING, VERIFY BY
#   curl -s -o /dev/null -w '%{http_code}\n' -X POST https://aymenazizi.dijaly.com/login \
#        -d 'email=admin@cybersec.local&password=password'
#   # Expected: 302 (redirect to /dashboard = login worked)
#   # 200 = login failed (still on /login page)
# =============================================================================
set -Eeuo pipefail

PLATFORM_DIR="${PLATFORM_DIR:-/var/www/cybersec-platform}"
WEB_USER="${WEB_USER:-www-data}"
SERVICE_PHP_FPM="${SERVICE_PHP_FPM:-php8.3-fpm}"

log()  { printf '\n\033[1;34m[phase1]\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
err()  { printf '  \033[1;31m✗\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

trap 'err "Phase 1 failed on line $LINENO (exit code: $?)"' ERR

[[ $EUID -eq 0 ]] || die "Run as root: sudo bash phase1-admin-seed.sh"
[[ -d "${PLATFORM_DIR}" ]] || die "Platform dir not found: ${PLATFORM_DIR}"
[[ -f "${PLATFORM_DIR}/artisan" ]] || die "artisan not found in ${PLATFORM_DIR}"

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

artisan() {
    if [[ ${USE_DOCKER} -eq 1 ]]; then
        docker compose exec -T backend php artisan "$@"
    else
        sudo -u "${WEB_USER}" -- php artisan "$@"
    fi
}

# --- Step 1: seed roles -----------------------------------------------------
log "Step 1/3  Seed RBAC roles (admin/analyst/client/auditor)"
artisan db:seed --class=RoleSeeder --force
ok "Roles seeded"

# --- Step 2: seed users ------------------------------------------------------
log "Step 2/3  Seed default users (password = 'password')"
artisan db:seed --class=UserSeeder --force
ok "Users seeded"

# --- Step 3: verify admin exists --------------------------------------------
log "Step 3/3  Verify admin@cybersec.local exists in DB"
ADMIN_CHECK=$(artisan tinker --no-ansi <<'PHP' 2>/dev/null | tail -n1
$u = App\Models\User::where('email', 'admin@cybersec.local')->first();
echo $u ? "OK id={$u->id} has_role=" . ($u->hasRole('admin') ? 'yes' : 'NO') : "MISSING";
PHP
)
ADMIN_CHECK="${ADMIN_CHECK//[$'\t\r\n ']}"
if [[ "${ADMIN_CHECK}" == "OK"* ]]; then
    ok "Admin user verified: ${ADMIN_CHECK}"
else
    err "Admin verification failed: ${ADMIN_CHECK}"
    die "Check the Laravel log: tail -50 storage/logs/laravel.log"
fi

log "Phase 1 complete."
log "Now verify from your laptop:"
log "    curl -s -o /dev/null -w '%{http_code}\\n' -X POST https://aymenazizi.dijaly.com/login \\"
log "         -d 'email=admin@cybersec.local&password=password'"
log "Expected output: 302   (302 = login succeeded, redirect to /dashboard)"
log "If you see 200, login still failed — tell me and we'll debug."
