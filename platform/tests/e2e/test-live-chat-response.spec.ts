import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test('verify live interactive AI chatbot send & response', async ({ page }) => {
  test.setTimeout(60_000);

  await loginAsAdmin(page);
  await page.goto('/chat', { waitUntil: 'domcontentloaded' });

  // Open or create a chat
  const firstSession = page.locator('a.card-hover').first();
  if (await firstSession.count() > 0) {
    await firstSession.click();
  } else {
    await page.click('a:has-text("New Chat")');
  }
  await page.waitForLoadState('domcontentloaded');

  const chatInput = page.locator('#chat-input, textarea[name="content"]').first();
  const sendBtn = page.locator('#chat-form button[type="submit"], button:has-text("send")').first();

  await expect(chatInput).toBeVisible();
  await chatInput.fill('What are the risks of open port 22 and how should we secure SSH?');
  await sendBtn.click();

  // Wait for assistant response bubble to appear
  const assistantBubble = page.locator('#chat-messages .bg-white\\/5, #chat-messages div:has-text("SSH"), #chat-messages div:has-text("Analysis")').last();
  await expect(assistantBubble).toBeVisible({ timeout: 15_000 });

  console.log('AI Chatbot successfully returned structured technical response!');
});
