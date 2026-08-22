#!/usr/bin/env bash
# =============================================================================
# scan-tools.sh — verify recon container has all required CLI tools
# =============================================================================
# Final CDC: reconnaissance service must bundle nmap, nuclei, gobuster,
# subfinder, wpscan + the SecLists wordlist. This script verifies the
# deployment and reports missing tools as a non-zero exit.
# =============================================================================
# Usage:
#   ./scripts/scan-tools.sh                 # check running container
#   ./scripts/scan-tools.sh --build         # rebuild image first
#   ./scripts/scan-tools.sh --json          # emit JSON report
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

RECON_CONTAINER="cybersec-recon"

JSON=0
REBUILD=0
for arg in "$@"; do
    case "${arg}" in
        --json)   JSON=1 ;;
        --build)  REBUILD=1 ;;
        -h|--help) sed -n '3,15p' "$0"; exit 0 ;;
        *) echo "unknown flag: ${arg}" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;34m[scan-tools]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m  ✗\033[0m %s\n' "$*" >&2; }

# ---------------------------------------------------------------------------
# Tools required by Final CDC §6.2 (reconnaissance module)
# Each entry: "binary|expected_min_version_flag|expected_min_version_substr"
# ---------------------------------------------------------------------------
TOOLS=(
    "nmap|--version|Nmap version 7"
    "nuclei|-version|Nuclei Engine"
    "gobuster|version|Gobuster"
    "subfinder|-version|subfinder"
    "wpscan|--version|WordPress Security Scanner"
    "httpx|-version|httpx"
    "katana|-version|katana"
    "naabu|-version|naabu"
    "dnsx|-version|dnsx"
)

# Wordlists required (SecLists layout)
WORDLISTS=(
    "/app/wordlists/SecLists/Discovery/Web-Content/common.txt"
    "/app/wordlists/SecLists/Discovery/DNS/subdomains-top1million-5000.txt"
    "/app/wordlists/SecLists/Passwords/Common-Credentials/10-million-password-list-top-1000.txt"
    "/app/wordlists/SecLists/Usernames/top-usernames-shortlist.txt"
)

# ---------------------------------------------------------------------------
# Ensure container is running
# ---------------------------------------------------------------------------
if [[ "${REBUILD:-0}" == "1" ]]; then
    log "Rebuilding reconnaissance image"
    ${COMPOSE} --env-file "${ENV_FILE}" build reconnaissance
fi

if ! ${COMPOSE} ps "${RECON_CONTAINER}" 2>/dev/null | grep -q "running\|up"; then
    log "Starting reconnaissance container"
    ${COMPOSE} --env-file "${ENV_FILE}" up -d reconnaissance
    sleep 3
fi

# ---------------------------------------------------------------------------
# Helper: exec a command in container, return stdout
# ---------------------------------------------------------------------------
run_in_recon() {
    ${COMPOSE} exec -T "${RECON_CONTAINER}" "$@"
}

# ---------------------------------------------------------------------------
# Tool checks
# ---------------------------------------------------------------------------
declare -a RESULTS=()
MISSING=0

for entry in "${TOOLS[@]}"; do
    IFS='|' read -r binary flag expected <<< "${entry}"
    out="$(run_in_recon "${binary}" "${flag}" 2>&1 || true)"
    if [[ -z "${out}" ]]; then
        RESULTS+=("{\"tool\":\"${binary}\",\"status\":\"missing\",\"version\":null}")
        err "${binary} not found"
        MISSING=$((MISSING + 1))
    elif [[ "${out}" == *"not found"* || "${out}" == *"not executable"* || "${out}" == *"No such file"* ]]; then
        RESULTS+=("{\"tool\":\"${binary}\",\"status\":\"missing\",\"version\":null}")
        err "${binary} not found"
        MISSING=$((MISSING + 1))
    else
        version="$(echo "${out}" | head -n1 | tr -d '"' | tr -d '\' | sed 's/[[:cntrl:]]//g')"
        RESULTS+=("{\"tool\":\"${binary}\",\"status\":\"ok\",\"version\":\"${version}\"}")
        ok "${binary}: ${version}"
    fi
done

# ---------------------------------------------------------------------------
# Wordlist checks
# ---------------------------------------------------------------------------
declare -a WL_RESULTS=()
WL_MISSING=0
for wl in "${WORDLISTS[@]}"; do
    if run_in_recon test -f "${wl}" 2>/dev/null; then
        size="$(run_in_recon wc -l < "${wl}" 2>/dev/null | tr -d ' ' || echo 0)"
        WL_RESULTS+=("{\"path\":\"${wl}\",\"status\":\"ok\",\"lines\":${size:-0}}")
        ok "${wl} (${size} lines)"
    else
        WL_RESULTS+=("{\"path\":\"${wl}\",\"status\":\"missing\"}")
        err "wordlist missing: ${wl}"
        WL_MISSING=$((WL_MISSING + 1))
    fi
done

# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------
TOTAL_MISSING=$((MISSING + WL_MISSING))

if [[ "${JSON}" -eq 1 ]]; then
    printf '{"container":"%s","tools":[%s],"wordlists":[%s],"missing":%d,"status":"%s"}\n' \
        "${RECON_CONTAINER}" \
        "$(IFS=,; echo "${RESULTS[*]}")" \
        "$(IFS=,; echo "${WL_RESULTS[*]}")" \
        "${TOTAL_MISSING}" \
        "$([[ ${TOTAL_MISSING} -eq 0 ]] && echo "ok" || echo "incomplete")"
    exit $([[ ${TOTAL_MISSING} -eq 0 ]] && echo 0 || echo 1)
fi

echo
if [[ ${TOTAL_MISSING} -eq 0 ]]; then
    log "All required tools + wordlists present"
    exit 0
else
    err "${TOTAL_MISSING} item(s) missing in ${RECON_CONTAINER}"
    echo
    echo "Fix: rebuild the reconnaissance image with the missing tools:"
    echo "     ${COMPOSE} build --no-cache reconnaissance && ${COMPOSE} up -d reconnaissance"
    exit 1
fi
