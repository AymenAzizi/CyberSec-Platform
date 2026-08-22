#!/bin/bash
# Persistent Laravel runner for the PFE CyberSec Platform
# Starts: FrankenPHP (Caddy) on :8000 + queue worker + scan worker

export PATH="/home/z/.local/bin:/home/z/.venv/bin:$PATH"
LARAVEL_DIR="/home/z/my-project/platform"
FRANKENPHP_BIN="/home/z/.local/bin/frankenphp"
PHP_BIN="/home/z/.local/bin/php"
PYTHON_BIN="/home/z/.venv/bin/python3"

# Stop existing instances
for pidfile in /home/z/my-project/caddy.pid /home/z/my-project/queue.pid /home/z/my-project/scan_worker.pid; do
  if [ -f "$pidfile" ]; then
    start-stop-daemon --stop --pidfile "$pidfile" 2>/dev/null
    rm -f "$pidfile"
    sleep 1
  fi
done

# 1. Start FrankenPHP (Caddy-based PHP server) on port 8000
start-stop-daemon --start --background --make-pidfile \
  --pidfile /home/z/my-project/caddy.pid \
  --chdir "$LARAVEL_DIR" \
  --exec "$FRANKENPHP_BIN" \
  -- run --config Caddyfile --adapter caddyfile
sleep 3
echo "FrankenPHP: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/login) (PID $(cat /home/z/my-project/caddy.pid))"

# 2. Start Laravel queue worker (processes remediation jobs)
start-stop-daemon --start --background --make-pidfile \
  --pidfile /home/z/my-project/queue.pid \
  --chdir "$LARAVEL_DIR" \
  --exec "$PHP_BIN" \
  -- artisan queue:work --queue=remediation,default --tries=3 --delay=5 --timeout=180
sleep 2
echo "Queue worker started (PID $(cat /home/z/my-project/queue.pid))"

# 3. Start Python scan worker (processes queued scans)
start-stop-daemon --start --background --make-pidfile \
  --pidfile /home/z/my-project/scan_worker.pid \
  --exec "$PYTHON_BIN" \
  -- /home/z/my-project/platform/workers/scan_worker.py
sleep 2
echo "Scan worker started (PID $(cat /home/z/my-project/scan_worker.pid))"

echo ""
echo "All services running:"
echo "  - Laravel/FrankenPHP: http://127.0.0.1:8000 (proxied via Next.js on :3000)"
echo "  - Queue worker: processing remediation & AI jobs"
echo "  - Scan worker: polling for queued scans"
echo ""
echo "Login: admin@cybersec.local / password"
