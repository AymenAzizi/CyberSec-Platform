import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('Verify User Reported Bug Fixes', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('1. Project creation automatically assigns target & target is selectable in New Scan', async ({ page }) => {
    test.setTimeout(45_000);
    // Create a new project
    await page.goto('/projects/create');
    await page.waitForLoadState('domcontentloaded');
    await page.fill('#name', 'Security Engagement Beta');
    await page.fill('#client_name', 'CyberSec Corp');
    await page.locator('form[action*="projects"] button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Go to New Scan
    await page.goto('/scans/create');
    await page.waitForLoadState('domcontentloaded');

    // Select the newly created project
    const projectSelect = page.locator('#project_id');
    const option = projectSelect.locator('option:has-text("Security Engagement Beta")').last();
    const val = await option.getAttribute('value');
    await projectSelect.selectOption(val!);
    await projectSelect.evaluate((el: HTMLSelectElement) => el.dispatchEvent(new Event('change', { bubbles: true })));

    // Verify Target dropdown is populated and auto-selected
    const targetSelect = page.locator('#target_id');
    await expect(targetSelect.locator('option')).not.toHaveCount(1, { timeout: 10_000 });

    const selectedValue = await targetSelect.inputValue();
    expect(selectedValue).not.toBe('');
    console.log('Project target auto-selected successfully with ID:', selectedValue);
  });

  test('2. Sandbox launch button and execution work without 405 or socket errors', async ({ page }) => {
    test.setTimeout(45_000);
    await page.goto('/security/sandbox');
    await page.waitForLoadState('domcontentloaded');

    // Launch DVWA sandbox
    const launchForm = page.locator('form[action*="sandbox/launch"]').first();
    await expect(launchForm).toBeVisible();
    await launchForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Verify no 405 error and no undefined socket_create error
    const pageText = await page.textContent('body');
    expect(pageText).not.toContain('405 Method Not Allowed');
    expect(pageText).not.toContain('Call to undefined function App\\Http\\Controllers\\socket_create()');
    console.log('Sandbox launched cleanly without errors!');
  });

  test('3. Chatbot handles multiple messages without 422 or empty content error', async ({ page }) => {
    test.setTimeout(45_000);
    await page.goto('/chat');
    await page.waitForLoadState('domcontentloaded');

    // Click new chat or open session
    const newChatBtn = page.locator('a:has-text("New Chat"), a.card-hover').first();
    await newChatBtn.click();
    await page.waitForLoadState('domcontentloaded');

    const chatInput = page.locator('#chat-input, textarea[name="content"]').first();
    const sendBtn = page.locator('#chat-form button[type="submit"]').first();

    // Turn 1: "hi"
    await chatInput.fill('hi');
    await sendBtn.click();

    // Verify user bubble and assistant bubble appear
    await expect(page.locator('#chat-messages div:has-text("hi")').first()).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#chat-messages').locator('.bg-white\\/5, [class*="border-white"]').last()).toBeVisible({ timeout: 15_000 });

    // Turn 2: "show me targets"
    await chatInput.fill('show me targets and open ports');
    await sendBtn.click();

    await expect(page.locator('#chat-messages div:has-text("show me targets")').first()).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#chat-messages').locator('.bg-white\\/5, [class*="border-white"]').last()).toBeVisible({ timeout: 15_000 });

    // Ensure error message does not exist
    const messagesText = await page.locator('#chat-messages').textContent();
    expect(messagesText).not.toContain('Request failed (422)');
    expect(messagesText).not.toContain('The content field is required');
    console.log('Multi-turn AI Chatbot conversation succeeded without 422 error!');
  });
});
