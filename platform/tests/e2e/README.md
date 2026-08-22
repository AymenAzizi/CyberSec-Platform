# End-to-end tests (Playwright)

This folder contains 13 Playwright spec files that exercise the CyberSec Platform
end-to-end in a real browser (Chromium). They replace the older static HTML
screenshots with live captures against a running instance of the platform.

## Prerequisites

1. **Platform running** — start it with `bash scripts/setup.sh` from the project
   root. After install completes, the app is at `http://localhost:8000`.
2. **Node + Bun** — install Node 20+ and [Bun](https://bun.sh) (or use `npm`).
3. **Install Playwright + browsers**:
   ```bash
   bun install                          # or npm install
   bunx playwright install chromium     # ~150 MB browser download
   ```

## Running the tests

The `package.json` defines these npm scripts:

| Script | What it does |
|---|---|
| `bun run test:e2e` | Runs every spec file in this folder sequentially |
| `bun run test:e2e:ui` | Opens the Playwright UI mode (interactive stepping) |
| `bun run test:e2e:smoke` | Runs only `00-smoke.spec.ts` (login + dashboard sanity check) |
| `bun run test:e2e:auth` | Runs only `01-auth.spec.ts` (login + logout flow) |
| `bun run test:e2e:scans` | Runs only `04-scans.spec.ts` (create + view scans) |
| `bun run test:e2e:report` | Opens the last HTML report in your default browser |
| `bun run screenshots` | Runs `99-screenshots.spec.ts` with `CAPTURE_SCREENSHOTS=1` |

Examples:

```bash
# Run everything (default baseURL = http://localhost:8000)
bun run test:e2e

# Point at a different host (e.g. behind Caddy on HTTPS)
BASE_URL=https://localhost bun run test:e2e

# Run only the auth spec
bun run test:e2e:auth

# Re-capture all screenshots into ./tests/e2e/screenshots/
bun run screenshots
```

## Test inventory

| File | Coverage |
|---|---|
| `00-smoke.spec.ts` | App boots, login page renders, dashboard loads after login |
| `01-auth.spec.ts` | Login as admin, logout, session expiry, login with wrong password |
| `02-dashboard.spec.ts` | Dashboard KPIs render, charts (ECharts) load, quick links |
| `03-projects.spec.ts` | Create project, list projects, view project, delete project |
| `04-scans.spec.ts` | Create + launch scan (nmap), poll status, view findings |
| `05-reports.spec.ts` | Generate report from scan, view HTML/PDF report, delete |
| `06-security.spec.ts` | Launch sandbox (DVWA), test attack detector, view alerts |
| `07-osint.spec.ts` | Run OSINT collection on a target, view DNS/subdomain results |
| `08-knowledge-graph.spec.ts` | Open knowledge graph, verify nodes + edges render (Cytoscape.js) |
| `09-chat.spec.ts` | Send chat messages to AI chatbot, verify GLM-4 response |
| `10-admin.spec.ts` | View users list, audit logs, system health page |
| `11-remediation.spec.ts` | Generate remediation script (bash/ansible/dockerfile) for a finding |
| `99-screenshots.spec.ts` | Re-captures all screenshots used in the rapport PDF |

## Helpers (`helpers/`)

| File | Purpose |
|---|---|
| `auth.ts` | `loginAs(page, role)` — fills the login form and waits for dashboard |
| `selectors.ts` | Centralized CSS selectors (data-test attributes preferred) |
| `screenshots.ts` | `screenshot(page, name)` — saves PNG into `./tests/e2e/screenshots/` |

## Configuration

`playwright.config.ts` at the project root:

- **baseURL**: `http://localhost:8000` (override with `BASE_URL=...`)
- **viewport**: 1440 × 900 @ 2x device-pixel-ratio (matches the rapport screenshots)
- **workers**: 1 (sequential — the platform has shared state)
- **reporter**: list + HTML report at `./playwright-report/`
- **traces**: retained on failure (`trace.zip`)
- **screenshots**: only on failure
- **videos**: retained on failure

## Notes

- The tests assume the platform has been seeded with `bash scripts/seed-demo.sh`
  (which creates admin/analyst/client/auditor users and demo projects/scans).
- The default password for all four roles is `password`.
- Tests are sequential because they share DB state. If you need to parallelize,
  spin up multiple platform instances on different ports and use `BASE_URL`.
- The screenshot spec (`99-screenshots.spec.ts`) only writes screenshots when
  `CAPTURE_SCREENSHOTS=1` is set — this prevents accidentally overwriting the
  rapport images during normal test runs.
