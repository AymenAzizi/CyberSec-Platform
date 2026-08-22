// tests/e2e/helpers/auth.ts
// Authentication helpers — log in / out as each of the 4 RBAC roles.

import { Page, expect } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';

export interface Credentials {
  email: string;
  password: string;
}

export const CREDS = {
  admin:   { email: 'admin@cybersec.local',   password: 'password' },
  analyst: { email: 'analyst@cybersec.local', password: 'password' },
  client:  { email: 'client@cybersec.local',  password: 'password' },
  auditor: { email: 'auditor@cybersec.local', password: 'password' },
} as const;

export type Role = keyof typeof CREDS;

/**
 * Log in as a given role and land on the dashboard.
 * Uses Playwright's fill + click so CSRF tokens are handled correctly
 * (they come from the initial GET /login page).
 */
export async function loginAs(page: Page, role: Role): Promise<void> {
  const creds = CREDS[role];

  await page.goto(`${BASE_URL}/login`);
  await page.waitForLoadState('domcontentloaded');

  const emailInput = page.locator('input[name="email"], input[type="email"]').first();
  if (await emailInput.isVisible({ timeout: 2000 }).catch(() => false)) {
    await emailInput.fill(creds.email);
    await page.locator('input[name="password"], input[type="password"]').first().fill(creds.password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.locator('button[type="submit"]').first().click()
    ]);
  }
}

export async function loginAsAdmin(page: Page)   { return loginAs(page, 'admin'); }
export async function loginAsAnalyst(page: Page) { return loginAs(page, 'analyst'); }
export async function loginAsClient(page: Page)  { return loginAs(page, 'client'); }
export async function loginAsAuditor(page: Page) { return loginAs(page, 'auditor'); }

/**
 * Log out — clicks the user menu item in the sidebar.
 */
export async function logout(page: Page): Promise<void> {
  // The sidebar logout link
  const logoutBtn = page.locator('a[href*="/logout"], form[action*="/logout"] button, button:has-text("Logout")').first();
  if (await logoutBtn.count() > 0) {
    await logoutBtn.click();
  } else {
    // Fallback: navigate to /logout via POST (Laravel requires POST for logout)
    await page.goto(`${BASE_URL}/dashboard`);
    // If there's a hidden logout form, submit it
    await page.evaluate(() => {
      const form = document.querySelector('form[action*="/logout"]') as HTMLFormElement;
      if (form) form.submit();
    });
  }
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/login/);
}

/**
 * Expect login failure — used by the negative auth test.
 */
export async function expectLoginFailure(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/login/);
}
