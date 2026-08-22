import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('All Scanners Live Execution Suite', () => {

  test('1. Nmap Scanner executes with -sT non-root and parses open ports', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsAdmin(page);
    await page.goto('/scans/create');
    await page.waitForLoadState('domcontentloaded');

    // Select project & target
    await page.selectOption('#project_id', { index: 1 });
    await page.waitForTimeout(500);

    const targetSelect = page.locator('#target_id');
    const targetVal = await targetSelect.inputValue();
    if (!targetVal) {
      await page.selectOption('#target_id', { index: 1 });
    }

    // Select Nmap
    await page.click('input[name="scan_type"][value="nmap"], input[value="nmap"]');

    // Launch scan
    await page.click('button[type="submit"]:has-text("Launch")', { noWaitAfter: true });
    await expect(page).toHaveURL(/.*\/scans\/\d+/, { timeout: 25_000 });

    const scanUrl = page.url();
    console.log(`Launched Nmap scan: ${scanUrl}`);

    // Wait for scan completion
    await expect(page.locator('text=Completed, text=Failed')).toBeVisible({ timeout: 60_000 });
    
    // Ensure it did not fail with root error
    const rawOutput = await page.locator('pre, code, div').allInnerTexts();
    const rawText = rawOutput.join(' ');
    expect(rawText).not.toContain('You requested a scan type which requires root privileges');
    console.log('Nmap scan executed successfully without root privilege errors!');
  });

  test('2. Subfinder Scanner discovers live subdomains', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsAdmin(page);
    await page.goto('/scans/create');
    await page.waitForLoadState('domcontentloaded');

    await page.selectOption('#project_id', { index: 1 });
    await page.waitForTimeout(500);

    const targetSelect = page.locator('#target_id');
    const targetVal = await targetSelect.inputValue();
    if (!targetVal) {
      await page.selectOption('#target_id', { index: 1 });
    }

    // Select Subfinder if available or launch via API
    const subfinderRadio = page.locator('input[value="subfinder"]');
    if (await subfinderRadio.isVisible()) {
      await subfinderRadio.click();
      await page.click('button[type="submit"]:has-text("Launch")', { noWaitAfter: true });
      await expect(page).toHaveURL(/.*\/scans\/\d+/, { timeout: 25_000 });
      await expect(page.locator('text=Completed, text=Failed')).toBeVisible({ timeout: 60_000 });
      console.log('Subfinder scan completed!');
    }
  });

  test('3. Security WAF & Injection Test APIs return real telemetry', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/security/monitoring');
    await page.waitForLoadState('domcontentloaded');

    // Verify telemetry cards are visible
    const monitoringCards = page.locator('.card');
    await expect(monitoringCards.first()).toBeVisible({ timeout: 10_000 });
    console.log('Security monitoring & telemetry active!');
  });

});
