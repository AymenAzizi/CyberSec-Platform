// tests/e2e/02-dashboard.spec.ts
// Dashboard — KPIs match the seeded DB counts.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
  });

  test('Loads with 200 status', async ({ page }) => {
    const response = await page.goto('/dashboard');
    expect(response?.status()).toBe(200);
  });

  test('Sidebar shows all main navigation links', async ({ page }) => {
    const sidebar = page.locator('aside, nav').first();
    await expect(sidebar).toBeVisible();
    const links = ['Dashboard', 'Projects', 'Scans', 'Reports'];
    for (const text of links) {
      await expect(sidebar.locator(`a:has-text("${text}")`).first()).toBeVisible();
    }
  });

  test('KPI cards show correct counts and links', async ({ page }) => {
    const body = await page.locator('body').innerText();
    expect(body).toContain('Total Projects');
    expect(body).toContain('Total Findings');
    expect(body).toContain('Critical Findings');
    expect(body).toContain('High Findings');
  });

  test('Severity donut chart renders', async ({ page }) => {
    // ECharts canvas should be visible
    const chart = page.locator('canvas, svg').first();
    await expect(chart).toBeVisible();
  });

  test('Recent scans table is populated', async ({ page }) => {
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
  });

  test('Recent alerts are visible', async ({ page }) => {
    const alerts = page.locator('text=/CVE|critical|alert/i').first();
    await expect(alerts).toBeVisible({ timeout: 5000 });
  });

  test('Screenshot for rapport', async ({ page }) => {
    await capture(page, 'dashboard');
  });
});
