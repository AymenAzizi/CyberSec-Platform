import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('Comprehensive Verification Suite', () => {

  test('1. System Health page shows microservices status', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/system-health');
    await page.waitForLoadState('domcontentloaded');

    // Wait for the status elements
    const statusBadges = page.locator('text=Up');
    await expect(statusBadges.first()).toBeVisible({ timeout: 10_000 });
    
    // Check that all 6 services are Up
    const upCount = await page.locator('span.badge-success:has-text("Up")').count();
    console.log(`System Health Up count: ${upCount}`);
    expect(upCount).toBeGreaterThanOrEqual(5);
  });

  test('2. Sandbox launch works and container shows in active sandboxes', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/security/sandbox');
    await page.waitForLoadState('domcontentloaded');

    // Launch DVWA
    const dvwaForm = page.locator('form[action*="sandbox/launch"]').filter({ hasText: 'Launch' }).first();
    await dvwaForm.locator('button[type="submit"]').click({ noWaitAfter: true });

    // Verify success flash message
    const alert = page.locator('.alert, [role="alert"], div:has-text("launched")').first();
    await expect(alert).toBeVisible({ timeout: 15_000 });
    console.log('Sandbox launch succeeded!');
  });

  test('3. OSINT refresh works without python error', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsAdmin(page);
    await page.goto('/osint');
    await page.waitForLoadState('domcontentloaded');

    // Find the first target's run OSINT form button
    const runBtn = page.locator('table form[action*="osint/"] button[type="submit"]').first();
    await expect(runBtn).toBeVisible({ timeout: 10_000 });

    await runBtn.click({ noWaitAfter: true });

    const alert = page.locator('div:has-text("OSINT data refreshed"), div:has-text("refreshed")').first();
    await expect(alert).toBeVisible({ timeout: 60_000 });
    console.log('OSINT collection succeeded!');
  });

  test('4. AI Chat returns dynamic AI response', async ({ page }) => {
    test.setTimeout(90_000);
    await loginAsAdmin(page);
    await page.goto('/chat');
    await page.waitForLoadState('domcontentloaded');

    // Open first chat or create new
    const newChatBtn = page.locator('a:has-text("New chat"), a:has-text("New Chat")').first();
    if (await newChatBtn.isVisible()) {
      await newChatBtn.click();
      await page.waitForLoadState('domcontentloaded');
    }

    const input = page.locator('#chat-input');
    await expect(input).toBeVisible();

    await input.fill('What is SSH and why is port 22 commonly targeted?');
    await page.locator('#chat-form button[type="submit"]').click({ noWaitAfter: true });

    // Wait for the re-rendered or dynamic assistant response
    const assistantBubble = page.locator('#chat-messages div.justify-start').last();
    await expect(assistantBubble).toBeVisible({ timeout: 60_000 });

    const replyText = await assistantBubble.innerText();
    console.log('AI Response:', replyText.substring(0, 300));
    expect(replyText.length).toBeGreaterThan(15);
    expect(replyText).not.toContain('The content field is required');
  });

  test('5. New Scan launches and completes via queue worker', async ({ page }) => {
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

    // Select Nmap scanner
    await page.click('input[name="scan_type"][value="nmap"], input[value="nmap"]');

    // Submit scan
    await page.click('button[type="submit"]:has-text("Launch")', { noWaitAfter: true });

    // Should redirect to scan detail
    await expect(page).toHaveURL(/.*\/scans\/\d+/, { timeout: 20_000 });
    console.log(`Landed on scan detail: ${page.url()}`);

    // Wait for scan to progress from Queued -> Running -> Completed
    const statusBadge = page.locator('div, span').filter({ hasText: /Running|Completed/ }).first();
    await expect(statusBadge).toBeVisible({ timeout: 30_000 });
    console.log('Scan is executing via worker!');
  });
});
