// dash_shot.cjs — Capture a single dashboard screenshot from a running CyberSec Platform.
// Usage: node dash_shot.cjs [base_url] [output_path]
//   base_url    defaults to http://localhost:8000 (BACKEND_DEV_PORT)
//   output_path defaults to ./screenshots/dashboard.png
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE_URL  = process.argv[2] || process.env.BASE_URL || 'http://localhost:8000';
const OUT_PATH  = process.argv[3] || process.env.OUTPUT_PATH || path.join(process.cwd(), 'screenshots', 'dashboard.png');

fs.mkdirSync(path.dirname(OUT_PATH), { recursive: true });

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  try {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle', timeout: 30000 });
    await page.fill('input[name="email"]', 'admin@cybersec.local');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(3000);
    // Make sure we're on dashboard
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle', timeout: 15000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: OUT_PATH, fullPage: true });
    console.log(`Dashboard captured: ${await page.title()}`);
    console.log(`Saved to: ${OUT_PATH}`);
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
  await browser.close();
})();
