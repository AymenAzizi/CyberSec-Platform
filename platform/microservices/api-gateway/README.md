# API Gateway Microservice

The API gateway is the single entrypoint for the Laravel backend to talk to all Python microservices. It handles CORS, rate limiting, and request routing.

## Build & run

```bash
docker compose up -d api-gateway
docker compose logs -f api-gateway
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Own status + downstream service URLs |
| GET | `/health/all` | Aggregated probe of all downstreams (cached 5s) |
| ALL | `/<path:path>` | Proxy to downstream by prefix (see below) |
| ALL | `/` | Same as `/<path:path>` with empty path |

## Routing map

The gateway inspects the path prefix and forwards to the matching downstream service. If no prefix matches, it forwards to the Laravel backend (default route).

| Path prefix | Downstream service | Downstream port |
|-------------|-------------------|-----------------|
| `/api/recon/*` | `reconnaissance` | 5000 |
| `/api/security/*` | `security` | 5001 |
| `/api/osint/*` | `osint` | 5002 |
| `/api/ai/*` | `ai` | 5003 |
| `/api/*` (fallback) | `backend` (Laravel) | 9000 |

## Rate limiting

The gateway enforces 30 requests per minute per IP on all `/api/*` endpoints with a burst of 10 requests. Configurable via env:

| Var | Default | Purpose |
|-----|---------|---------|
| `RATE_LIMIT_API_PER_MIN` | `60` | Sustained requests per minute per IP |
| `RATE_LIMIT_BURST` | `10` | Burst size |
| `HEALTH_CACHE_TTL` | `5` | Seconds to cache `/health/all` results |

## CORS

| Var | Default |
|-----|---------|
| `CORS_ALLOWED_ORIGINS` | `http://localhost,http://localhost:80,http://localhost:443` |
| `CORS_ALLOWED_METHODS` | `GET,POST,PUT,PATCH,DELETE,OPTIONS` |
| `CORS_ALLOWED_HEADERS` | `Content-Type,Authorization,X-Requested-With,X-CSRF-TOKEN` |
| `CORS_EXPOSED_HEADERS` | (empty) |
| `CORS_MAX_AGE` | `86400` |
| `CORS_SUPPORTS_CREDENTIALS` | `true` |

## Service mesh authentication

The Laravel backend sends `Authorization: Bearer ${SERVICE_MESH_TOKEN}` on every request to the gateway. The gateway validates the token against the `SERVICE_MESH_TOKEN` env var (set by `setup.sh` to a random 32-byte secret).

If the token is missing or wrong, the gateway returns HTTP 401.

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `LARAVEL_URL` | `http://backend:9000` | Where to forward `/api/*` (non-microservice) requests |
| `LARAVEL_API_TOKEN` | (set by setup.sh) | Token to authenticate to Laravel |
| `SERVICE_MESH_TOKEN` | (set by setup.sh) | Required Bearer token for incoming requests |
| `SERVICE_MESH_TIMEOUT` | `30` | Default downstream timeout (seconds) |
