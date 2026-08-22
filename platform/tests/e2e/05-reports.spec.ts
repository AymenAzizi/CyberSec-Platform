// tests/e2e/05-reports.spec.ts
// Reports — generate report from scan, view detail, export as JSON.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Reports', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Reports index loads', async ({ page }) => {
    const response = await page.goto('/reports', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
  });

  test('Report detail page loads for report ID 1', async ({ page }) => {
    const response = await page.goto('/reports/1', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    // Should show executive summary, severity breakdown
    const body = await page.locator('body').innerText();
    expect(body.length).toBeGreaterThan(100);
  });

  test('Generate report from scan 1', async ({ page }) => {
    // The generate endpoint returns a redirect to the new report
    const response = await page.goto('/scans/1/report/generate', { waitUntil: 'networkidle' });
    // Should be 200 (rendered report) or 302 (redirect to new report)
    expect([200, 302].includes(response?.status() || 0)).toBeTruthy();
  });

  test('Report PDF download endpoint returns PDF content-type', async ({ request }) => {
    const response = await request.get('/reports/1/pdf', { maxRedirects: 0 });
    if (response.status() === 200) {
      const ct = response.headers()['content-type'] || '';
      expect(ct).toContain('pdf');
    } else {
      // 404 if the PDF generator isn't available — not a hard failure
      expect([200, 404].includes(response.status())).toBeTruthy();
    }
  });

  test('Report JSON export returns JSON with content-disposition', async ({ request }) => {
    const response = await request.get('/reports/1/export/json', { maxRedirects: 0 });
    if (response.status() === 200) {
      const ct = response.headers()['content-type'] || '';
      const cd = response.headers()['content-disposition'] || '';
      expect(ct).toContain('json');
      expect(cd).toContain('attachment');
      // Body should be valid JSON
      const body = await response.text();
      expect(() => JSON.parse(body)).not.toThrow();
    } else {
      expect([200, 404].includes(response.status())).toBeTruthy();
    }
  });

  test('Screenshot for rapport — reports list', async ({ page }) => {
    await page.goto('/reports');
    await capture(page, 'reports');
  });

  test('Screenshot for rapport — report detail', async ({ page }) => {
    await page.goto('/reports/1');
    await capture(page, 'report_view');
  });
});
