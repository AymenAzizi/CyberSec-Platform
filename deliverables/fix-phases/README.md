# Production Fix — Phased Approach

## What's broken on production (https://aymenazizi.dijaly.com/)

| # | Issue | Symptom |
|---|-------|---------|
| 1 | Ollama AI microservice down | `curl http://127.0.0.1:11434/api/tags` times out |
| 2 | Subfinder config missing | `/var/www/.config/subfinder/config.yaml` does not exist |
| 3 | Production deployment outdated | 8 routes return 404: `/osint`, `/chat`, `/reports`, `/security/alerts`, `/security/monitoring`, `/admin/audit-logs`, `/admin/system-health`, `/projects/{id}/graph`, `/scans/{id}/export`, `/scans/{id}/report/generate` |
| 4 | Admin credentials don't work | `admin@cybersec.local` / `password` login returns 200 (still on /login) |

## Why I can't just "do it myself"

The 4 fixes require root commands **on the production server itself** (`systemctl restart ollama`, `mkdir /var/www/.config/subfinder`, `php artisan db:seed`, `git pull`, etc.). I'm running in a sandboxed environment — I have no SSH access to your server. What I *can* do from here is HTTP-level verification and prepare scripts.

## The 4 phases — run them one at a time on the server

SSH to your server as root, then run each phase script. After each one, run the verification command shown in the script's output. Tell me the result before moving to the next phase.

| Phase | Script | What it fixes | Time | Reversible? |
|-------|--------|--------------|------|-------------|
| **1** | `phase1-admin-seed.sh` | Fix #4 — admin login | ~30s | Yes (just re-seeds) |
| **2** | `phase2-deploy-code.sh` | Fix #3 — missing routes | ~3-5 min | Yes (git reset --hard to previous commit) |
| **3** | `phase3-subfinder-config.sh` | Fix #2 — subfinder config | ~10s | Yes (just deletes the file) |
| **4** | `phase4-ollama-restart.sh` | Fix #1 — Ollama down | ~10 min (model download) | Yes (systemctl stop ollama) |

## Recommended order

**Phase 1 → 2 → 3 → 4** (most-impact-first, lowest-risk-first)

- Phase 1 lets you log in as admin (low risk, fast)
- Phase 2 restores all missing routes (medium risk, takes a few minutes)
- Phase 3 is a trivial file creation (very low risk)
- Phase 4 is the longest (model download) — do it last so you can move on with other work if it's slow

## How to run

```bash
# On your laptop, download all 4 scripts
scp -r download/fix-phases/ root@your-server:/root/

# SSH to the server
ssh root@your-server

# Run phase 1
sudo bash /root/fix-phases/phase1-admin-seed.sh

# Verify (curl from your laptop, NOT the server)
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://aymenazizi.dijaly.com/login \
     -d 'email=admin@cybersec.local&password=password'
# Expected: 302   (302 = login succeeded)
# Tell me the output. If it's 302, move on. If it's 200, we debug.

# Run phase 2
sudo bash /root/fix-phases/phase2-deploy-code.sh

# Run phase 3
sudo bash /root/fix-phases/phase3-subfinder-config.sh

# Run phase 4 (longest — the model is ~5 GB)
sudo bash /root/fix-phases/phase4-ollama-restart.sh
```

## After all 4 phases are done

Tell me and I will:
1. Re-probe all routes on production
2. Re-capture all 7 screenshots that are still mockups in the report (Figure 4.7 Remediation, 4.8 Reports Index, 4.9 Report Detail, 4.10 Chat, 4.11 Security Monitoring, 4.12 Audit Logs, 4.13 Admin Users)
3. Rebuild the PDF with all real screenshots
