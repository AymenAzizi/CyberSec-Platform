#!/bin/bash
# Persistent Laravel server runner — respawns if it dies
LARAVEL_DIR="/home/z/my-project/platform"
PHP_BIN="/home/z/.local/share/mamba/envs/pfe/bin/php"
LOG_FILE="/home/z/my-project/laravel.log"
PID_FILE="/home/z/my-project/laravel.pid"

cd "$LARAVEL_DIR"

while true; do
  echo "[$(date)] Starting Laravel server..." >> "$LOG_FILE"
  "$PHP_BIN" artisan serve --host=127.0.0.1 --port=8000 >> "$LOG_FILE" 2>&1 &
  SERVER_PID=$!
  echo "$SERVER_PID" > "$PID_FILE"
  wait "$SERVER_PID"
  EXIT_CODE=$?
  echo "[$(date)] Laravel exited with code $EXIT_CODE, restarting in 2s..." >> "$LOG_FILE"
  sleep 2
done
