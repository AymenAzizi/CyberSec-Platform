#!/usr/bin/env bash
# =============================================================================
# stop.sh — stop and remove all PFE Cybersec Platform containers
# =============================================================================
# Usage:
#   ./scripts/stop.sh            # stop + remove containers + networks
#   ./scripts/stop.sh --purge    # also remove named volumes (DESTRUCTIVE)
#   ./scripts/stop.sh --help     # show this help
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

PURGE=0
for arg in "$@"; do
    case "${arg}" in
        --purge)   PURGE=1 ;;
        -h|--help)
            sed -n '3,12p' "$0"
            exit 0
            ;;
        *) echo "unknown flag: ${arg}" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;34m[stop]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !\033[0m %s\n' "$*"; }

log "Stopping containers"
${COMPOSE} --env-file "${ENV_FILE}" stop
ok "Containers stopped"

log "Removing containers"
${COMPOSE} --env-file "${ENV_FILE}" down --remove-orphans
ok "Containers + networks removed"

if [[ "${PURGE}" -eq 1 ]]; then
    warn "Purging named volumes (DESTRUCTIVE — data will be lost)"
    read -r -p "Type 'DELETE' to confirm: " ans
    if [[ "${ans}" == "DELETE" ]]; then
        ${COMPOSE} --env-file "${ENV_FILE}" down -v
        ok "Volumes removed"
    else
        echo "Aborted."
        exit 1
    fi
else
    echo
    echo "Volumes retained. To remove them, re-run with --purge."
fi

log "Done"
