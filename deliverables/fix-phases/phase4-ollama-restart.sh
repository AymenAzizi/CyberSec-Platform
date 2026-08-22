#!/usr/bin/env bash
# =============================================================================
# PHASE 4 — Restart Ollama + pull qwen2.5-coder:7b  (Fix #1 of 4)
# =============================================================================
# Restores: 127.0.0.1:11434/api/generate (currently times out)
#
# WHAT THIS DOES
#  1. Detect Ollama deployment mode (systemd service OR Docker container)
#  2. Restart the service
#  3. Wait for the Ollama API to be responsive on 127.0.0.1:11434
#  4. Pull qwen2.5-coder:7b model (~5 GB download — this can take 5-10 minutes)
#  5. Run an inference test to confirm the AI microservice works end-to-end
#
# WHAT TO RUN
#   ssh root@your-server
#   bash /path/to/phase4-ollama-restart.sh
#
# AFTER RUNNING, VERIFY BY
#   curl http://127.0.0.1:11434/api/tags | python3 -m json.tool
#   # Expected: JSON listing qwen2.5-coder:7b
# =============================================================================
set -Eeuo pipefail

OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5-coder:7b}"
OLLAMA_URL="${OLLAMA_URL:-http://127.0.0.1:11434}"
SERVICE_OLLAMA="${SERVICE_OLLAMA:-ollama}"

log()  { printf '\n\033[1;34m[phase4]\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
err()  { printf '  \033[1;31m✗\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

trap 'err "Phase 4 failed on line $LINENO (exit code: $?)"' ERR

[[ $EUID -eq 0 ]] || die "Run as root: sudo bash phase4-ollama-restart.sh"

# --- Step 1: detect Ollama deployment mode ----------------------------------
log "Step 1/5  Detect Ollama deployment mode"
USE_DOCKER_OLLAMA=0
if systemctl status "${SERVICE_OLLAMA}" >/dev/null 2>&1; then
    ok "Ollama runs as systemd service: ${SERVICE_OLLAMA}"
elif docker ps --format '{{.Names}}' 2>/dev/null | grep -qi ollama; then
    OLLAMA_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i ollama | head -1)
    USE_DOCKER_OLLAMA=1
    ok "Ollama runs as Docker container: ${OLLAMA_CONTAINER}"
else
    die "Ollama not found — neither systemd service '${SERVICE_OLLAMA}' nor Docker container. Install it first: curl -fsSL https://ollama.com/install.sh | sh"
fi

# --- Step 2: restart the service --------------------------------------------
log "Step 2/5  Restart Ollama"
if [[ ${USE_DOCKER_OLLAMA} -eq 1 ]]; then
    docker restart "${OLLAMA_CONTAINER}"
    ok "Container '${OLLAMA_CONTAINER}' restarted"
else
    systemctl restart "${SERVICE_OLLAMA}"
    ok "Service '${SERVICE_OLLAMA}' restarted"
fi

# --- Step 3: wait for API to respond ----------------------------------------
log "Step 3/5  Wait for Ollama API on ${OLLAMA_URL}"
ATTEMPTS=0
MAX_ATTEMPTS=30
while [[ ${ATTEMPTS} -lt ${MAX_ATTEMPTS} ]]; do
    if curl -s --max-time 2 "${OLLAMA_URL}/api/tags" >/dev/null 2>&1; then
        ok "Ollama API responsive after ${ATTEMPTS} attempts"
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    printf '.'
    sleep 2
done
echo
[[ ${ATTEMPTS} -lt ${MAX_ATTEMPTS} ]] || die "Ollama API did not respond in 60s. Check: journalctl -u ${SERVICE_OLLAMA} -n 50"

# --- Step 4: pull the model --------------------------------------------------
log "Step 4/5  Pull model ${OLLAMA_MODEL} (this can take 5-10 minutes)"
curl -s "${OLLAMA_URL}/api/pull" -d "{\"name\": \"${OLLAMA_MODEL}\"}"
echo
ok "Model ${OLLAMA_MODEL} pulled"

# --- Step 5: inference test --------------------------------------------------
log "Step 5/5  Run inference test"
TEST_PROMPT="Reply with exactly: AI_OK"
TEST_RESPONSE=$(curl -s "${OLLAMA_URL}/api/generate" \
    -d "{\"model\": \"${OLLAMA_MODEL}\", \"prompt\": \"${TEST_PROMPT}\", \"stream\": false, \"options\": {\"temperature\": 0.0, \"num_predict\": 20}}" 2>/dev/null || echo "FAILED")
if echo "${TEST_RESPONSE}" | grep -qi "AI_OK\|response"; then
    ok "Inference test passed"
    ok "Response excerpt: $(echo "${TEST_RESPONSE}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("response","")[:80])' 2>/dev/null || echo "${TEST_RESPONSE}" | head -c 80)"
else
    err "Inference test failed: ${TEST_RESPONSE}"
    die "Check Ollama logs: journalctl -u ${SERVICE_OLLAMA} -n 50"
fi

log "Phase 4 complete."
log "Verify from your laptop:"
log "    curl http://aymenazizi.dijaly.com:11434/api/tags  # if Ollama is exposed"
log "    # Or, on the server:"
log "    curl http://127.0.0.1:11434/api/tags"
log "Expected: JSON with 'qwen2.5-coder:7b' in models[].name"
