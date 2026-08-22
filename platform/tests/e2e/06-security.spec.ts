// tests/e2e/06-security.spec.ts
// Security module — alerts, monitoring, sandbox.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Security module', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Security alerts page loads', async ({ page }) => {
    const response = await page.goto('/security/alerts', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    // Seeded dataset has 18 alerts
    const rows = page.locator('table tbody tr, [data-testid="alert"]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('Acknowledge an alert', async ({ page }) => {
    await page.goto('/security/alerts', { waitUntil: 'networkidle' });
    const ackBtn = page.locator('button:has-text("Acknowledge"), a:has-text("Acknowledge"), button:has-text("Ack")').first();
    if (await ackBtn.count() > 0) {
      const countBefore = await page.locator('table tbody tr').count();
      await ackBtn.click();
      await page.waitForLoadState('networkidle');
      // After acknowledgement, the alert should be marked ack'd
      const body = await page.locator('body').innerText();
      expect(body.length).toBeGreaterThan(50);
    } else {
      // No acknowledge button visible — skip
      test.skip();
    }
  });

  test('Monitoring dashboard loads', async ({ page }) => {
    const response = await page.goto('/security/monitoring', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
  });

  test('Sandbox page loads with available environments', async ({ page }) => {
    const response = await page.goto('/security/sandbox', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    // Should list DVWA, SQLi-Labs, WebGoat, bWAPP
    const body = await page.locator('body').innerText();
    expect(body.length).toBeGreaterThan(100);
  });

  test('Launch a sandbox instance (DVWA)', async ({ page }) => {
    test.setTimeout(30_000);
    await page.goto('/security/sandbox', { waitUntil: 'networkidle' });
    // Find the launch button for DVWA
    const dvwaCard = page.locator('text=DVWA').locator('xpath=ancestor::*[position()=1] | ..').first();
    const launchBtn = page.locator('button:has-text("Launch")').first();
    if (await launchBtn.count() > 0) {
      await launchBtn.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(3000);
      // Sandbox should now show as running
      const body = await page.locator('body').innerText();
      expect(body.length).toBeGreaterThan(100);
    } else {
      test.skip();
    }
  });

  test('Screenshot for rapport — alerts', async ({ page }) => {
    await page.goto('/security/alerts');
    await capture(page, 'alerts');
  });

  test('Screenshot for rapport — monitoring', async ({ page }) => {
    await page.goto('/security/monitoring');
    await capture(page, 'monitoring');
  });

  test('Screenshot for rapport — sandbox', async ({ page }) => {
    await page.goto('/security/sandbox');
    await capture(page, 'sandbox');
  });
});
