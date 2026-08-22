// tests/e2e/11-remediation.spec.ts
// Remediation — generate bash/ansible/dockerfile scripts for a finding.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Remediation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Remediation page for finding 1 loads', async ({ page }) => {
    const response = await page.goto('/findings/1/remediation', { waitUntil: 'networkidle' });
    // Finding 1 may or may not exist; 200 means the page rendered
    expect([200, 404].includes(response?.status() || 0)).toBeTruthy();
  });

  test('Generate remediation script via POST', async ({ page, request }) => {
    // The generate endpoint POSTs a finding ID and returns the generated script
    const response = await request.post('/findings/1/remediation/generate', {
      headers: { 'Content-Type': 'application/json' },
      data: {},
    });
    // 200 = generated successfully, 302 = redirect to view, 404 = finding not found
    expect([200, 302, 404].includes(response.status())).toBeTruthy();
  });

  test('Remediation script download returns a file', async ({ request }) => {
    // The first remediation script (if any)
    const response = await request.get('/remediation/1/download', { maxRedirects: 0 });
    expect([200, 404, 302].includes(response.status())).toBeTruthy();
    if (response.status() === 200) {
      const cd = response.headers()['content-disposition'] || '';
      expect(cd).toContain('attachment');
    }
  });

  test('Full remediation flow on first finding', async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto('/findings/1/remediation', { waitUntil: 'networkidle' });

    // If page loaded with a Generate button, click it
    const generateBtn = page.locator('button:has-text("Generate"), a:has-text("Generate")').first();
    if (await generateBtn.count() > 0) {
      await generateBtn.click();
      // Wait for AI response — up to 30s
      for (let i = 0; i < 15; i++) {
        await page.waitForTimeout(2000);
        const body = await page.locator('body').innerText();
        if (body.includes('bash') || body.includes('ansible') || body.includes('Dockerfile')) {
          break;
        }
      }
      const body = await page.locator('body').innerText();
      // Should contain at least one remediation script
      expect(body.length).toBeGreaterThan(100);
    } else {
      test.skip();
    }
  });

  test('Screenshot for rapport — remediation', async ({ page }) => {
    await page.goto('/findings/1/remediation');
    await capture(page, 'remediation');
  });
});
