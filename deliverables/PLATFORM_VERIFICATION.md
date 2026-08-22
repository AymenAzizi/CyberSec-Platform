# Platform Verification Report
## Real Functionality Test — 2026-08-17

This document records the results of a real-world verification of the CyberSec Platform deployed at **https://aymenazizi.dijaly.com/**.

---

## ✅ Verified Working (Real Results)

### Authentication & User Management
- ✅ User registration with strong password policy (12+ chars, 4 character classes)
- ✅ Login with session-based authentication
- ✅ CSRF token protection on all forms
- ✅ Rate limiting (5 attempts/IP) on login endpoint
- ✅ Role-based access control (analyst role verified)

### Project Management
- ✅ Create new project (verified: project ID 5 created)
- ✅ List projects (shows real project cards)
- ✅ View project detail page
- ✅ Project-scoped scans

### Scan Engine (Real Nmap Execution)
- ✅ **Nmap scan of `scanme.nmap.org`** — returned real open ports:
  - **22/tcp** (SSH — open)
  - **80/tcp** (HTTP — open)
  - Status: Execution Complete, Analysis Complete
- ✅ **Nuclei scan of `scanme.nmap.org`** — completed successfully, 0 findings (expected — scanme.nmap.org is hardened)
- ✅ Scan list page shows all scans with status badges
- ✅ Scan detail page displays findings table (Port/Protocol/State/Service)

### Available Scan Types (5)
1. **NMAP** — Network Mapping ✅ Working
2. **GOBUSTER** — Directory Brute-Force ✅ Available
3. **WPSCAN** — WordPress Scanner ✅ Available
4. **NUCLEI** — Vulnerability Templates ✅ Working
5. **SUBFINDER** — Subdomain Discovery ⚠️ Available but config file missing on server

### Dashboard
- ✅ Real KPI counters (1 project, 3 completed scans, 0 active scans, 0 critical findings)
- ✅ Navigation sidebar with Dashboard, Projects, Scans, Sandbox

### Sandbox Page
- ✅ `/sandbox` route accessible, displays "No sandbox zone data" (legitimate empty state)

---

## ❌ Issues Found on Production Server

### 1. AI Microservice (Ollama) — Connection Timeout
**Error:** `Ollama HTTP error: HTTPConnectionPool(host='127.0.0.1', port=11434): Read timed out. (read timeout=45)`

**Impact:** AI Summary on scan results page cannot be generated. The chatbot (if route existed) would also fail.

**Fix:** SSH to the production server and restart Ollama:
```bash
ssh root@your-server
systemctl status ollama
systemctl restart ollama
ollama pull qwen2.5-coder:7b  # ensure model is downloaded
curl http://127.0.0.1:11434/api/tags  # verify it responds
```

### 2. Subfinder Configuration Missing
**Error:** `open /var/www/.config/subfinder/config.yaml: no such file or directory`

**Impact:** Subfinder scans complete with zero results because the tool cannot initialize its passive source list.

**Fix:** SSH to the production server:
```bash
mkdir -p /var/www/.config/subfinder
# Optionally configure API keys for passive sources
# Or run subfinder once to generate the default config:
su -s /bin/bash -c "subfinder" www-data
```

### 3. Production Deployment Is Out of Date
Several routes defined in the current source code (`routes/web.php`) return HTTP 404 on production:

| Route | Source Code Status | Production Status |
|---|---|---|
| `/osint` | ✅ Defined | ❌ 404 |
| `/security/alerts` | ✅ Defined | ❌ 404 |
| `/security/monitoring` | ✅ Defined | ❌ 404 |
| `/security/sandbox` | ✅ Defined | ❌ 404 |
| `/chat` | ✅ Defined | ❌ 404 |
| `/reports` | ✅ Defined | ❌ 404 |
| `/admin/system-health` | ✅ Defined | ❌ 404 |
| `/admin/audit-logs` | ✅ Defined | ❌ 404 |
| `/projects/{id}/graph` | ✅ Defined | ❌ 404 |
| `/scans/{id}/export` | ✅ Defined | ❌ 404 |
| `/scans/{id}/report/generate` | ✅ Defined | ❌ 404 |

**Fix:** SSH to the production server and redeploy the latest code:
```bash
cd /var/www/cybersec-platform
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan route:cache
php artisan config:cache
php artisan view:cache
# Restart PHP-FPM and the Python microservices
systemctl restart php8.3-fpm
systemctl restart cybersec-worker
```

### 4. Seeded Admin Credentials Not Working
The seeded user `admin@cybersec.local` / `password` returns "These credentials do not match our records." This means the `UserSeeder` was not run (or was reset) on production.

**Fix:** SSH to the production server:
```bash
cd /var/www/cybersec-platform
php artisan db:seed --class=UserSeeder
php artisan db:seed --class=RoleSeeder
# Now admin@cybersec.local / password will work
```

---

## 📊 Test Credentials (Created During Verification)

A test user was registered to perform the verification:
- **Email:** `testadmin1786916646@cybersec.local` (auto-generated, role: analyst)
- **Password:** `TestPass123!@`

This user has 1 project ("Test Recon Project", ID 5) with 3 completed scans:
- Scan 7: Nmap of scanme.nmap.org → 2 open ports (22, 80)
- Scan 8: Subfinder of ensi.tn → 0 subdomains (config error)
- Scan 9: Nuclei of scanme.nmap.org → 0 findings (expected)

---

## 📸 Real Screenshots Captured

The following screenshots in `/home/z/my-project/rapport/img/` are real captures from the production deployment:

| Screenshot | Source URL | Real Data |
|---|---|---|
| `login_page.png` | `/login` | Real login form |
| `dashboard.png` | `/dashboard` | Real KPIs: 1 project, 3 scans |
| `projects.png` | `/projects` | Real "Test Recon Project" card |
| `project_detail.png` | `/projects/5` | Real project with 3 scans |
| `scans_list.png` | `/scans` | 3 real completed scans |
| `scan_results.png` | `/scans/7` | **Real Nmap results: ports 22, 80 open** |
| `launch_scan.png` | `/scans/create` | Real scan creation form |
| `sandbox.png` | `/sandbox` | Real (empty) sandbox page |

Bonus evidence in `/home/z/my-project/download/previews/`:
- `subfinder_results2.png` — Subfinder scan with config error visible
- `nuclei_results.png` — Nuclei scan completed (0 findings as expected)

---

## ✅ Conclusion

The platform's **core scan engine is fully functional and produces real results** — verified by the Nmap scan of `scanme.nmap.org` returning the expected open ports (22, 80). The Laravel backend, PostgreSQL database, Redis queue, and Python reconnaissance microservice all work correctly.

However, the **production deployment is out of date** — it's missing the OSINT, Chat, Reports, Knowledge Graph, Security Monitoring, and Admin routes that exist in the current source code. Additionally, the **Ollama AI service is down** on the server, preventing AI-powered features from working.

To restore full functionality, the user should:
1. SSH to the production server
2. Pull the latest code (`git pull`)
3. Restart Ollama (`systemctl restart ollama`)
4. Run the database seeders (`php artisan db:seed`)
5. Clear and rebuild caches (`php artisan route:cache && php artisan config:cache`)
