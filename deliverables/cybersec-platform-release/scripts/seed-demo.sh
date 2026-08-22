#!/usr/bin/env bash
# =============================================================================
# seed-demo.sh — populate demo data + trigger sample scans
# =============================================================================
# Creates a demo project with an AUTHORIZED target, kicks off representative
# scans (nmap, nuclei, osint) via the RECONNAISSANCE service (the security
# service does NOT run scans — it does attack detection / sandboxing), and
# verifies reports are generated.
#
# Useful for defense-day demo: proves the full pipeline works end-to-end.
#
# WARNING: Only scan targets you own or have written authorization for.
# The demo target defaults to scanme.nmap.org (explicitly authorized by nmap).
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLATFORM_DIR="$(dirname "${SCRIPT_DIR}")"
cd "${PLATFORM_DIR}"

COMPOSE="docker compose"
command -v docker >/dev/null 2>&1 || { echo "docker not found" >&2; exit 1; }
if ! ${COMPOSE} version >/dev/null 2>&1; then COMPOSE="docker-compose"; fi

ENV_FILE=".env.docker"
[[ -f "${ENV_FILE}" ]] || ENV_FILE=".env"

DEMO_TARGET="${DEMO_TARGET:-scanme.nmap.org}"
DEMO_PROJECT="${DEMO_PROJECT:-Defense Demo}"

log()  { printf '\033[1;34m[seed-demo]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m  ✗\033[0m %s\n' "$*" >&2; }

trap 'err "seed-demo failed on line $LINENO"' ERR

# ---------------------------------------------------------------------------
# Ensure stack is up
# ---------------------------------------------------------------------------
log "Verifying stack is running"
for svc in backend reconnaissance osint api-gateway; do
    if ! ${COMPOSE} ps "${svc}" 2>/dev/null | grep -q "healthy\|running\|up"; then
        err "Service ${svc} is not running — start the stack first: bash scripts/setup.sh"
        exit 1
    fi
done
ok "Required services are up"

# ---------------------------------------------------------------------------
# 1) Authorize the demo target via backend (creates authorization scope)
# ---------------------------------------------------------------------------
log "Authorizing demo target: ${DEMO_TARGET}"
TARGET_ID="$(${COMPOSE} exec -T backend php artisan tinker --no-ansi <<PHP 2>/dev/null | tail -n1
echo App\Models\Target::firstOrCreate(
    ['host' => '${DEMO_TARGET}'],
    [
        'name'        => 'Demo target — ${DEMO_TARGET}',
        'description' => 'Explicitly authorized by nmap.org for educational use',
        'authorized'  => true,
        'scope'       => 'tcp:22,80,443;icmp',
    ]
)->id;
PHP
)"
TARGET_ID="${TARGET_ID//[$'\t\r\n ']}"
[[ -z "${TARGET_ID}" ]] && TARGET_ID="unknown"
ok "Target authorized (id=${TARGET_ID})"

# ---------------------------------------------------------------------------
# 2) Create demo project + attach target
# ---------------------------------------------------------------------------
log "Creating demo project: ${DEMO_PROJECT}"
PROJECT_ID="$(${COMPOSE} exec -T backend php artisan tinker --no-ansi <<PHP 2>/dev/null | tail -n1
\$p = App\Models\Project::firstOrCreate(
    ['name' => '${DEMO_PROJECT}'],
    ['description' => 'End-to-end pipeline demo for defense day']
);
if (App\Models\Target::where('project_id', \$p->id)->count() === 0) {
    App\Models\Target::where('id', ${TARGET_ID})->update(['project_id' => \$p->id]);
}
echo \$p->id;
PHP
)"
PROJECT_ID="${PROJECT_ID//[$'\t\r\n ']}"
ok "Project ready (id=${PROJECT_ID:-unknown})"

# ---------------------------------------------------------------------------
# 3) Trigger nmap scan via the RECONNAISSANCE service
#    Correct endpoint: POST /scan (NOT /api/v1/scan — the gateway owns /api/*)
# ---------------------------------------------------------------------------
log "Triggering nmap scan via reconnaissance service (POST /scan)"
SCAN_RESULT="$(${COMPOSE} exec -T reconnaissance curl -sS -X POST \
    http://127.0.0.1:5000/scan \
    -H 'Content-Type: application/json' \
    -d "{\"tool\":\"nmap\",\"target\":\"${DEMO_TARGET}\",\"profile\":\"balanced\",\"config\":{\"ports\":\"22,80,443\"}}" \
    2>/dev/null || echo '{}')"
SCAN_ID="$(echo "${SCAN_RESULT}" | python3 -c 'import sys,json
try:
    d = json.load(sys.stdin)
    print(d.get("scan_id") or d.get("id") or "")
except: print("")' 2>/dev/null || echo "")"
if [[ -n "${SCAN_ID}" ]]; then
    ok "nmap scan queued (id=${SCAN_ID})"
else
    warn "Could not parse scan_id; payload: ${SCAN_RESULT:0:200}"
fi

# ---------------------------------------------------------------------------
# 4) Trigger nuclei scan — also via reconnaissance (nuclei lives there)
# ---------------------------------------------------------------------------
log "Triggering nuclei scan via reconnaissance service"
NUCLEI_RESULT="$(${COMPOSE} exec -T reconnaissance curl -sS -X POST \
    http://127.0.0.1:5000/scan/nuclei \
    -H 'Content-Type: application/json' \
    -d "{\"target\":\"${DEMO_TARGET}\",\"profile\":\"silent\",\"config\":{\"severity\":\"medium,high,critical\"}}" \
    2>/dev/null || echo '{}')"
NUCLEI_ID="$(echo "${NUCLEI_RESULT}" | python3 -c 'import sys,json
try:
    d = json.load(sys.stdin)
    print(d.get("scan_id") or d.get("id") or "")
except: print("")' 2>/dev/null || echo "")"
[[ -n "${NUCLEI_ID}" ]] && ok "nuclei scan queued (id=${NUCLEI_ID})" || warn "nuclei trigger failed: ${NUCLEI_RESULT:0:200}"

# ---------------------------------------------------------------------------
# 5) Trigger OSINT scan via the OSINT service
#    Correct endpoint: POST /passive (runs all modules with graceful degradation)
# ---------------------------------------------------------------------------
log "Triggering OSINT scan via osint service (POST /passive)"
OSINT_RESULT="$(${COMPOSE} exec -T osint curl -sS -X POST \
    http://127.0.0.1:5002/passive \
    -H 'Content-Type: application/json' \
    -d "{\"target\":\"${DEMO_TARGET}\"}" \
    2>/dev/null || echo '{}')"
OSINT_OK="$(echo "${OSINT_RESULT}" | python3 -c 'import sys,json
try:
    d = json.load(sys.stdin)
    print("ok" if d.get("data") or d.get("results") else "")
except: print("")' 2>/dev/null || echo "")"
[[ -n "${OSINT_OK}" ]] && ok "OSINT scan complete" || warn "OSINT trigger failed: ${OSINT_RESULT:0:200}"

# ---------------------------------------------------------------------------
# 6) Wait + verify reports generated for the target's project
# ---------------------------------------------------------------------------
log "Waiting for reports to be generated (up to 5 minutes)"
WAIT_SECONDS=300
POLL_INTERVAL=10
ELAPSED=0
REPORTS_FOUND=0
while [[ ${ELAPSED} -lt ${WAIT_SECONDS} ]]; do
    # Reports are keyed by scan_id, not target_id. Look up scans for our target,
    # then count their reports.
    REPORTS_FOUND="$(${COMPOSE} exec -T backend php artisan tinker --no-ansi <<PHP 2>/dev/null | tail -n1
echo App\Models\Report::whereIn('scan_id',
    App\Models\Scan::where('target_id', ${TARGET_ID})->pluck('id')
)->count();
PHP
)"
    REPORTS_FOUND="${REPORTS_FOUND//[$'\t\r\n ']}"
    REPORTS_FOUND="${REPORTS_FOUND:-0}"
    if [[ "${REPORTS_FOUND}" -ge 1 ]]; then
        ok "Reports detected: ${REPORTS_FOUND}"
        break
    fi
    printf '\033[2m    ...waiting (%ds elapsed)\033[0m\r' "${ELAPSED}"
    sleep "${POLL_INTERVAL}"
    ELAPSED=$((ELAPSED + POLL_INTERVAL))
done
echo

# ---------------------------------------------------------------------------
# 7) Inspect reports on disk
# ---------------------------------------------------------------------------
log "Scan result files on disk:"
${COMPOSE} exec -T reconnaissance sh -c 'ls -la /app/results/ 2>/dev/null | head -20' || warn "no /app/results dir"

# ---------------------------------------------------------------------------
# 8) Summary
# ---------------------------------------------------------------------------
echo
printf '\033[1;36m=== Demo seed complete ===\033[0m\n'
printf '  Target          : %s\n' "${DEMO_TARGET}"
printf '  Project         : %s\n' "${DEMO_PROJECT}"
printf '  Scans triggered : nmap + nuclei + osint\n'
printf '  Reports found   : %s\n' "${REPORTS_FOUND:-0}"
echo
printf 'View reports at: %s/reports\n' "$(grep '^APP_URL=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2-)"
echo

if [[ "${REPORTS_FOUND:-0}" -ge 1 ]]; then
    ok "Demo pipeline verified end-to-end"
    exit 0
else
    warn "No reports yet — scans may still be running"
    warn "Check: ${COMPOSE} logs -f reconnaissance osint worker"
    exit 0  # non-fatal for demo
fi
