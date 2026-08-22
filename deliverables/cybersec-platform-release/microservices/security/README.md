# Security Microservice

The security service performs attack detection, injection testing, and runs vulnerable-app sandboxes in isolated Docker containers.

## Build & run

```bash
docker compose up -d security
docker compose logs -f security
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Service status |
| POST | `/detect` | Attack detection on a request/response |
| POST | `/injection` | Injection tester (`type=full\|sql\|cmd\|xss`) |
| POST | `/waf-detect` | WAF fingerprint |
| POST | `/prevention-check` | Defense posture (CSP/HSTS/X-Frame-Options) |
| GET | `/monitoring/stats` | Event count by severity + type |
| GET | `/monitoring/events?limit=100&severity=critical` | Recent security events |
| POST | `/sandbox/test` | Sandbox lifecycle: `action=start\|stop\|status` |

## Sandbox architecture

The security service NEVER mounts the Docker socket directly. Instead, it talks to a **socket-proxy** container (`tecnativa/docker-socket-proxy`) at `tcp://socket-proxy:2375`, which whitelists only safe container endpoints:

| Docker API | Allowed |
|-----------|---------|
| `container list` | ✅ |
| `container create` | ✅ |
| `container start/stop` | ✅ |
| `container inspect` | ✅ |
| `image *` | ❌ |
| `volume *` | ❌ |
| `network *` | ❌ |
| `exec attach` | ❌ |
| `swarm *` | ❌ |

This means even if the security service is compromised, an attacker cannot escape to the host Docker daemon.

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `DOCKER_HOST` | `tcp://socket-proxy:2375` | Docker API endpoint (via proxy) |
| `DOCKER_PROXY_READONLY` | `true` | Restrict to read-only API surface |
| `SERVICE_MESH_TOKEN` | (set by setup.sh) | Auth token for inter-service calls |
