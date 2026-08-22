// playwright.config.ts
// End-to-end test config for the CyberSec Platform.
// Override baseURL at runtime: BASE_URL=http://localhost bunx playwright test

import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';
const CAPTURE_SCREENSHOTS = process.env.CAPTURE_SCREENSHOTS === '1';
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || './tests/e2e/screenshots';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: false, // Most tests share DB state — sequential is safer
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1, // Single worker — the platform has shared state (queue, sessions)
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2,
    ignoreHTTPSErrors: true,
    actionTimeout: 15000,
    navigationTimeout: 30000,
    storageState: undefined, // Always start fresh — auth handled per-test
    extraHTTPHeaders: {
      'Accept-Language': 'en-US,en;q=0.9',
    },
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Optional: start the dev server if not already running
  // webServer: {
  //   command: 'docker compose up -d',
  //   url: BASE_URL,
  //   reuseExistingServer: true,
  //   timeout: 120_000,
  // },
});

// Export for screenshot helper
export { CAPTURE_SCREENSHOTS, SCREENSHOT_DIR, BASE_URL };
