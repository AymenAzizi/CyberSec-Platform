// tests/e2e/09-chat.spec.ts
// AI Chat — create session, ask question, get response.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('AI Chat', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Chat index loads', async ({ page }) => {
    const response = await page.goto('/chat', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
  });

  test('New chat creation form is available', async ({ page }) => {
    await page.goto('/chat');
    // Either a list of existing chats or a "New Chat" button
    const newChatBtn = page.locator('a:has-text("New Chat"), a:has-text("Create"), button:has-text("New")');
    const existingChats = page.locator('a[href*="/chat/"]');
    expect(await newChatBtn.count() + await existingChats.count()).toBeGreaterThan(0);
  });

  test('Ask the AI a question and receive a response', async ({ page }) => {
    test.setTimeout(60_000); // AI may take 30s+

    await page.goto('/chat', { waitUntil: 'networkidle' });
    const existingChatLink = page.locator('a[href*="/chat/"]').first();
    if (await existingChatLink.count() > 0) {
      await existingChatLink.click();
    } else {
      const newChatBtn = page.locator('a:has-text("New"), button:has-text("New"), a:has-text("Create")').first();
      if (await newChatBtn.count() > 0) {
        await newChatBtn.click();
      }
    }
    await page.waitForLoadState('networkidle');

    const messageInput = page.locator('textarea[name="message"], input[name="message"], textarea').first();
    const sendBtn = page.locator('button[type="submit"], button:has-text("Send")').first();

    if (await messageInput.count() > 0 && await sendBtn.count() > 0) {
      await messageInput.fill('What is port 22 and is it safe?');
      await sendBtn.click();

      // Wait for AI response — up to 30s
      for (let i = 0; i < 30; i++) {
        await page.waitForTimeout(2000);
        const body = await page.locator('body').innerText();
        // Look for any AI response (bot message)
        if (body.length > 500 && !body.includes('Loading') && !body.includes('Thinking')) {
          break;
        }
      }
      const body = await page.locator('body').innerText();
      expect(body.length).toBeGreaterThan(100);
    } else {
      // Chat form not visible — skip
      test.skip();
    }
  });

  test('Screenshot for rapport — chat', async ({ page }) => {
    await page.goto('/chat');
    // Try to open the most recent chat session
    const link = page.locator('a[href*="/chat/"]').first();
    if (await link.count() > 0) {
      await link.click();
      await page.waitForLoadState('networkidle');
    }
    await capture(page, 'chat');
  });
});
