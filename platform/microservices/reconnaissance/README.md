# Reconnaissance Microservice

The reconnaissance service is the platform's scanner engine. It orchestrates five security tools through a unified Flask API and builds a knowledge graph from the raw output.

## Build & run

```bash
# Standalone (development)
cd microservices/reconnaissance
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python app.py    # starts on http://127.0.0.1:5000

# Inside the Docker stack (preferred)
docker compose up -d reconnaissance
docker compose logs -f reconnaissance
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Service status + per-tool availability + scan profiles |
| GET | `/tools` | List of 5 scanners with descriptions |
| POST | `/scan` | Unified scan: `{tool, target, profile?, config?}` |
| POST | `/scan/<tool>` | Same with tool in path: nmap, nuclei, gobuster, subfinder, wpscan |
| POST | `/analyze` | AI-assisted analysis of raw tool output |

## Scan profiles

| Profile | Description | nmap flags |
|---------|-------------|------------|
| `silent` | Stealthy, slow, low noise | `-T2 -sS -sV --version-intensity 1` |
| `balanced` (default) | Default speed/accuracy trade-off | `-T3 -sV --version-intensity 5` |
| `aggressive` | Fast, noisy, more likely to be detected | `-T4 -A --version-intensity 7` |

## Tools installed

| Tool | Version | Used for |
|------|---------|----------|
| nmap | 7.94+ | Port scan, service detection |
| nuclei | latest | Vulnerability templates (3000+) |
| gobuster | 3.6+ | Directory brute-force |
| subfinder | 2.5+ | Subdomain discovery (crtsh, hackertarget, etc.) |
| wpscan | 3.8+ | WordPress scanner |

Plus SecLists wordlists mounted read-only at `/app/wordlists/SecLists/`.

## Knowledge graph

Every scan result is parsed by `utils/result_parser.py` and `utils/graph_builder.py` into:
- **Assets** (domain, IP, host, service, port, vulnerability, impact)
- **AssetRelations** (connects, hosts, exposes, runs, affected_by, causes) with JSONB properties

The graph is stored in PostgreSQL via Apache AGE and exposed via the api-gateway at `/api/recon/graph/<scan_id>`.

## Configuration

All env vars are read at startup. See `.env.docker.example` for the full list with defaults.

| Var | Default | Purpose |
|-----|---------|---------|
| `REDIS_URL` | `redis://redis:6379/0` | For Redis Streams publishing |
| `WORDLISTS_DIR` | `/app/wordlists` | SecLists mount point |
| `SCAN_NMAP_DEFAULT_OPTS` | `-T3 -sV --version-intensity 5` | Default nmap flags for balanced profile |
| `SCAN_NUCLEI_DEFAULT_OPTS` | `-severity medium,high,critical` | Default nuclei severity filter |
| `SCAN_GOBUSTER_DEFAULT_OPTS` | `-t 30 -to 10s` | Threads + timeout |
| `SCAN_SUBFINDER_DEFAULT_OPTS` | `-silent` | Suppress info-level logging |
| `WPSCAN_API_TOKEN` | (empty) | Required for full WP scan results |
