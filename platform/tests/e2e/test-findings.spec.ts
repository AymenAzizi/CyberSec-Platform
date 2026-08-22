import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('Findings Module Verification', () => {
  test('Findings index loads with 200 OK and displays severity filters', async ({ page }) => {
    await loginAsAdmin(page);

    // 1. Visit /findings
    const res = await page.goto('/findings');
    expect(res?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Security Findings');

    // 2. Filter Critical
    const resCrit = await page.goto('/findings?severity=critical');
    expect(resCrit?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('Critical');

    // 3. Filter High
    const resHigh = await page.goto('/findings?severity=high');
    expect(resHigh?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('High');

    console.log('Findings module verified with 200 OK across all severity filters!');
  });
});
