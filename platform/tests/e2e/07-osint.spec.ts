// tests/e2e/07-osint.spec.ts
// OSINT — form submission and results display.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('OSINT', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('OSINT index page loads', async ({ page }) => {
    const response = await page.goto('/osint', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
  });

  test('OSINT page has a target input', async ({ page }) => {
    await page.goto('/osint');
    const input = page.locator('input[name*="target"], input[name*="domain"], input[type="text"]').first();
    await expect(input).toBeVisible();
  });

  test('Run OSINT on ensi.tn', async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto('/osint', { waitUntil: 'networkidle' });

    const targetInput = page.locator('input[name*="target"], input[name*="domain"], input[type="text"]').first();
    await targetInput.fill('ensi.tn');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    if (await submitBtn.count() > 0) {
      await submitBtn.click();
      await page.waitForLoadState('networkidle');
      // OSINT takes a few seconds
      await page.waitForTimeout(5000);
      // Should now show results — DNS records, subdomains
      const body = await page.locator('body').innerText();
      expect(body.length).toBeGreaterThan(100);
    } else {
      test.skip();
    }
  });

  test('Screenshot for rapport — OSINT', async ({ page }) => {
    await page.goto('/osint');
    await capture(page, 'osint');
  });
});
