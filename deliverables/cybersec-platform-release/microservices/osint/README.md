# OSINT Microservice

The OSINT service performs passive information gathering on a target using 5 modules with graceful degradation — if one module fails (e.g., DNS timeout), the others still return data.

## Build & run

```bash
docker compose up -d osint
docker compose logs -f osint
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Service status |
| POST | `/whois` | WHOIS lookup: `{target}` |
| POST | `/dns` | DNS records: `{target, type?}` (default A) |
| POST | `/ssl` | SSL certificate info: `{target, port?}` (default 443) |
| POST | `/subdomains` | Subdomain enumeration via crt.sh: `{target}` |
| POST | `/tech-stack` | Technology fingerprinting (whatweb-style): `{target}` |
| POST | `/passive` | Run all 5 modules in sequence; partial failures don't fail the whole call |

## Dependencies

- `python-whois` — WHOIS lookups
- `dnspython` — DNS resolution
- `requests` — HTTP for crt.sh and tech-stack

No external API keys required for the free modules. For better subdomain coverage, add API keys in `.env.docker`:

```bash
VT_API_KEY=your-virustotal-key
SHODAN_API_KEY=your-shodan-key
SECURITYTRAILS_API_KEY=your-securitytrails-key
```

(These are optional; the crt.sh + hackertarget sources work without keys.)

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `REDIS_URL` | `redis://redis:6379/0` | For caching OSINT results (5 min TTL) |
| `OSINT_CACHE_TTL` | `300` | Cache TTL in seconds |
| `SERVICE_MESH_TOKEN` | (set by setup.sh) | Auth token |
