// screenshot.cjs — Capture screenshots from a running CyberSec Platform instance.
// Usage: node screenshot.cjs [base_url] [output_dir]
//   base_url   defaults to http://localhost:8000 (BACKEND_DEV_PORT)
//   output_dir defaults to ./screenshots (created if missing)
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE_URL = process.argv[2] || process.env.BASE_URL || 'http://localhost:8000';
const OUT_DIR  = process.argv[3] || process.env.OUTPUT_DIR || path.join(process.cwd(), 'screenshots');

fs.mkdirSync(OUT_DIR, { recursive: true });

const SHOTS = [
  ['01-dashboard',      '/dashboard'],
  ['02-scans',          '/scans'],
  ['03-scan-create',    '/scans/create'],
  ['04-scan-detail',    '/scans/1'],
  ['05-knowledge-graph','/projects/1/graph'],
  ['06-report',        '/reports/1'],
  ['07-audit-logs',    '/admin/audit-logs'],
  ['08-chat',          '/chat/1'],
  ['09-alerts',        '/security/alerts'],
  ['10-osint',         '/osint/1/results'],
];

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

  try {
    console.log(`Logging in at ${BASE_URL}/login ...`);
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle', timeout: 30000 });
    await page.fill('input[name="email"]', 'admin@cybersec.local');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([
      page.waitForURL('**/dashboard**', { timeout: 15000 })
        .catch(() => console.log('  warning: redirect wait timed out, continuing...')),
      page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(2000);
    console.log('  Login succeeded');

    for (const [name, route] of SHOTS) {
      const outPath = path.join(OUT_DIR, `${name}.png`);
      try {
        await page.goto(`${BASE_URL}${route}`, { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: outPath, fullPage: true });
        console.log(`  ✓ ${name}.png  (${route})`);
      } catch (e) {
        console.log(`  ! ${name}.png  failed: ${e.message}`);
      }
    }
    console.log(`\nAll screenshots saved to: ${OUT_DIR}`);
  } catch (e) {
    console.error('Error:', e.message);
    process.exit(1);
  } finally {
    await browser.close();
  }
})();
