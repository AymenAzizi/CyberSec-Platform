// tests/e2e/99-screenshots.spec.ts
// Captures all 21 production screenshots used in the PFE report.
// Run with: CAPTURE_SCREENSHOTS=1 SCREENSHOT_DIR=/path/to/img bunx playwright test tests/e2e/99-screenshots.spec.ts
//
// The screenshots go into /home/z/my-project/rapport/img/ by default
// (or wherever SCREENSHOT_DIR points) and are referenced by main.tex.

import { test } from '@playwright/test';
import { loginAsAdmin, loginAsAnalyst, loginAsClient, loginAsAuditor } from './helpers/auth';
import { enableScreenshots, capture, captureWhenSettled } from './helpers/screenshots';

// Enable screenshot capture regardless of CAPTURE_SCREENSHOTS env var
// (this spec file is specifically for capturing)
enableScreenshots(true);

const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/home/z/my-project/rapport/img';

test.describe('Capture all 21 rapport screenshots', () => {
  test('login_page (logged out)', async ({ page, browser }) => {
    // Fresh context (no auth cookie)
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    await p.goto('/login', { waitUntil: 'networkidle' });
    await capture(p, 'login_page');
    await ctx.close();
  });

  test('register_page (logged out)', async ({ page, browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    await p.goto('/register', { waitUntil: 'networkidle' });
    await capture(p, 'register_page');
    await ctx.close();
  });

  test('dashboard (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await captureWhenSettled(page, 'dashboard');
  });

  test('projects (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/projects', { waitUntil: 'networkidle' });
    await capture(page, 'projects');
  });

  test('new_project (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/projects/create', { waitUntil: 'networkidle' });
    await capture(page, 'new_project');
  });

  test('project_detail (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/projects/1', { waitUntil: 'networkidle' });
    await capture(page, 'project_detail');
  });

  test('launch_scan (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/scans/create', { waitUntil: 'networkidle' });
    await capture(page, 'launch_scan');
  });

  test('scans_list (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/scans', { waitUntil: 'networkidle' });
    await capture(page, 'scans_list');
  });

  test('scan_results (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/scans/1', { waitUntil: 'networkidle' });
    await capture(page, 'scan_results');
  });

  test('reports (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/reports', { waitUntil: 'networkidle' });
    await capture(page, 'reports');
  });

  test('report_view (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/reports/1', { waitUntil: 'networkidle' });
    await capture(page, 'report_view');
  });

  test('osint (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/osint', { waitUntil: 'networkidle' });
    await capture(page, 'osint');
  });

  test('knowledge_graph (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/projects/1/graph', { waitUntil: 'networkidle' });
    await captureWhenSettled(page, 'knowledge_graph', 3000);
  });

  test('alerts (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/security/alerts', { waitUntil: 'networkidle' });
    await capture(page, 'alerts');
  });

  test('sandbox (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/security/sandbox', { waitUntil: 'networkidle' });
    await capture(page, 'sandbox');
  });

  test('monitoring (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/security/monitoring', { waitUntil: 'networkidle' });
    await capture(page, 'monitoring');
  });

  test('audit_logs (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/audit-logs', { waitUntil: 'networkidle' });
    await capture(page, 'audit_logs');
  });

  test('admin_users (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/users', { waitUntil: 'networkidle' });
    await capture(page, 'admin_users');
  });

  test('system_health (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/system-health', { waitUntil: 'networkidle' });
    await capture(page, 'system_health');
  });

  test('chat (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/chat', { waitUntil: 'networkidle' });
    // Try to open the most recent chat session
    const link = page.locator('a[href*="/chat/"]').first();
    if (await link.count() > 0) {
      await link.click();
      await page.waitForLoadState('networkidle');
    }
    await capture(page, 'chat');
  });

  test('remediation (admin)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/findings/1/remediation', { waitUntil: 'networkidle' });
    await capture(page, 'remediation');
  });
});

console.log(`\n📸 Screenshots will be saved to: ${SCREENSHOT_DIR}\n`);
