# Worker Microservice

The worker is a Python process that consumes scan requests from a Redis Streams queue (`scan:requests`) and dispatches them to the appropriate microservice. It has no HTTP port — it's a long-running consumer group.

## Build & run

```bash
docker compose up -d worker
docker compose logs -f worker
```

## How it works

1. Subscribes to the `scan:requests` Redis Stream as a consumer group `pfe-workers`
2. Reads each scan request (`{scan_id, tool, target, profile, config}`)
3. Looks up the destination microservice in `dispatcher.TOOL_ROUTES`:
   - `nmap` → `http://reconnaissance:5000/scan/nmap`
   - `nuclei` → `http://reconnaissance:5000/scan/nuclei`
   - `gobuster` → `http://reconnaissance:5000/scan/gobuster`
   - `subfinder` → `http://reconnaissance:5000/scan/subfinder`
   - `wpscan` → `http://reconnaissance:5000/scan/wpscan`
   - `whois` → `http://osint:5002/whois`
   - `dns` → `http://osint:5002/dns`
   - `ssl` → `http://osint:5002/ssl`
   - `subdomains` → `http://osint:5002/subdomains`
   - `tech-stack` → `http://osint:5002/tech-stack`
   - `passive` → `http://osint:5002/passive`
   - `detect` → `http://security:5001/detect`
   - `injection` → `http://security:5001/injection`
   - `waf-detect` → `http://security:5001/waf-detect`
   - `prevention-check` → `http://security:5001/prevention-check`
4. Sends the request to the microservice, awaits response
5. Posts the result back to Laravel at `/api/scans/{scan_id}/callback` (via `LARAVEL_URL`)
6. Publishes to `scan:completed` or `scan:failed` Redis Stream

## Redis-down fallback

If Redis is unavailable, the worker falls back to HTTP polling of Laravel's `/api/queue/next` endpoint (`http_poller.py`) — Laravel then queues scans in the database. This fallback is for development environments without Redis; in production, Redis must be healthy.

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `REDIS_URL` | `redis://redis:6379/0` | Redis connection URL |
| `QUEUE_NAME` | `default` | Redis Stream name to consume |
| `LARAVEL_URL` | `http://backend:9000` | Laravel API for callbacks + fallback polling |
| `LARAVEL_API_TOKEN` | (set by setup.sh) | Auth token for Laravel API |
| `WORKER_CONCURRENCY` | `2` | Parallel in-flight requests |
| `WORKER_MAX_MEMORY` | `128` | Soft memory limit (MB) — worker restarts on exceed |
| `WORKER_TIMEOUT` | `120` | Per-job timeout (seconds) |
| `QUEUE_MAX_TRIES` | `3` | Max retry attempts on failure |
| `QUEUE_RETRY_AFTER` | `90` | Seconds before a job is retried |
| `IDLE_SLEEP` | `3` | Sleep when no jobs available |
| `POLL_INTERVAL` | `5` | HTTP polling fallback interval |

## Healthcheck

The worker has no HTTP port. Its healthcheck uses `os.kill(1, 0)` to verify PID 1 is alive — this only checks that the process is running, not that it's actively consuming. For real monitoring, watch the `scan:completed` stream:

```bash
docker compose exec redis redis-cli -a "${REDIS_PASSWORD}" \
    XINFO GROUPS scan:requests
```

Look for the `pfe-workers` consumer group's `pending` count — a stuck worker shows pending count growing with no ` deliveries` shrinking.
