// tests/e2e/helpers/screenshots.ts
// Screenshot helper — captures full-page PNGs at 2x DPI for the rapport PDF.

import { Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || './tests/e2e/screenshots';
const CAPTURE = process.env.CAPTURE_SCREENSHOTS === '1';

let _captureEnabled = CAPTURE;

export function enableScreenshots(enable: boolean = true): void {
  _captureEnabled = enable;
}

export function isScreenshotCaptureEnabled(): boolean {
  return _captureEnabled;
}

/**
 * Capture a full-page PNG of the current page.
 * Saved to {SCREENSHOT_DIR}/{name}.png at 2x device scale factor.
 *
 * Usage in tests:
 *
 *   test('dashboard loads', async ({ page }) => {
 *     await loginAsAdmin(page);
 *     await page.goto('/dashboard');
 *     await capture(page, 'dashboard');
 *   });
 */
export async function capture(page: Page, name: string): Promise<string> {
  if (!_captureEnabled) return '';

  const dir = SCREENSHOT_DIR;
  fs.mkdirSync(dir, { recursive: true });

  // Hide scrollbars for cleaner screenshots
  await page.addStyleTag({ content: '::-webkit-scrollbar{display:none}' });
  await page.waitForTimeout(800); // Let async UI settle

  const outPath = path.resolve(dir, `${name}.png`);
  await page.screenshot({
    path: outPath,
    fullPage: true,
  });

  const size = fs.statSync(outPath).size;
  console.log(`  📸 ${name}.png (${Math.round(size / 1024)} KB)`);
  return outPath;
}

/**
 * Capture only if the page has settled (no network activity for 1s).
 * Useful for pages with Cytoscape.js or ECharts that render async.
 */
export async function captureWhenSettled(page: Page, name: string, settleMs: number = 1500): Promise<string> {
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(settleMs);
  return capture(page, name);
}
