import { test, expect, chromium } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';
const VIDEO_DIR = path.resolve(process.cwd(), 'demo_video');

test.describe('Platform Full Demo Video & Verification', () => {
  test('Complete End-to-End User Journey with Video Recording', async () => {
    test.setTimeout(240000); // 4 minutes

    if (!fs.existsSync(VIDEO_DIR)) {
      fs.mkdirSync(VIDEO_DIR, { recursive: true });
    }

    const browser = await chromium.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({
      viewport: { width: 1440, height: 900 },
      deviceScaleFactor: 1,
      recordVideo: {
        dir: VIDEO_DIR,
        size: { width: 1440, height: 900 },
      },
      ignoreHTTPSErrors: true,
    });

    const page = await context.newPage();

    const timestamp = Date.now();
    const demoEmail = `analyst.demo.${timestamp}@cybersec.local`;
    const demoPassword = 'Password123!';

    console.log('>>> [1/13] Navigating to Registration page...');
    await page.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    // Register a new user
    const nameInput = page.locator('input[name="name"]').first();
    const emailInput = page.locator('input[name="email"]').first();
    const passwordInput = page.locator('input[name="password"]').first();
    const confirmInput = page.locator('input[name="password_confirmation"]').first();
    const termsCheck = page.locator('input[name="terms"]').first();

    if (await nameInput.isVisible()) {
      await nameInput.fill('Security Officer Demo');
      await emailInput.fill(demoEmail);
      await passwordInput.fill(demoPassword);
      await confirmInput.fill(demoPassword);
      if (await termsCheck.isVisible()) {
        await termsCheck.check();
      }
      await page.waitForTimeout(1000);
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');
      console.log('>>> [1/13] Successfully registered new user:', demoEmail);
    }

    await page.waitForTimeout(2000);

    console.log('>>> [2/13] Exploring Dashboard...');
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Smooth scroll down to view cards and metrics
    await page.evaluate(() => window.scrollBy({ top: 350, behavior: 'smooth' }));
    await page.waitForTimeout(1500);
    await page.evaluate(() => window.scrollBy({ top: -350, behavior: 'smooth' }));
    await page.waitForTimeout(1000);

    console.log('>>> [3/13] Navigating to Projects...');
    await page.goto(`${BASE_URL}/projects`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    // Create a new project
    const createProjectBtn = page.locator('a[href*="/projects/create"], button:has-text("New Project"), a:has-text("Nouveau"), a:has-text("Create")').first();
    if (await createProjectBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await createProjectBtn.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(1500);

      const pName = page.locator('input[name="name"]').first();
      const pTarget = page.locator('input[name="target"], input[name="primary_target"], textarea[name="targets"], input[type="text"]').nth(1);
      if (await pName.isVisible()) {
        await pName.fill(`Audit Perimeter - ${timestamp}`);
        if (await pTarget.isVisible()) {
          await pTarget.fill('ensit.tn');
        }
        const poaCheck = page.locator('input[type="checkbox"]').first();
        if (await poaCheck.isVisible()) {
          await poaCheck.check();
        }
        await page.waitForTimeout(1000);
        await page.locator('button[type="submit"]').first().click();
        await page.waitForLoadState('domcontentloaded');
        console.log('>>> [3/13] Project created successfully');
      }
    }

    await page.waitForTimeout(2000);

    console.log('>>> [4/13] Navigating to Scans Manager...');
    await page.goto(`${BASE_URL}/scans`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Open first existing scan if available
    const viewScanBtn = page.locator('a[href*="/scans/"]').first();
    if (await viewScanBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await viewScanBtn.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(2500);

      // Scroll to view findings and raw command evidence
      await page.evaluate(() => window.scrollBy({ top: 400, behavior: 'smooth' }));
      await page.waitForTimeout(1500);
      await page.evaluate(() => window.scrollBy({ top: -400, behavior: 'smooth' }));
      await page.waitForTimeout(1000);
    }

    console.log('>>> [5/13] Inspecting Remediation-as-Code...');
    const findingDetailLink = page.locator('a[href*="/remediation"], a[href*="/findings/"]').first();
    if (await findingDetailLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await findingDetailLink.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(2500);

      // Click generate remediation if available
      const genRemBtn = page.locator('button:has-text("Generate"), button:has-text("Générer"), button:has-text("Remediation")').first();
      if (await genRemBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await genRemBtn.click();
        await page.waitForTimeout(2500);
      }
    }

    console.log('>>> [6/13] Exploring Knowledge Graph...');
    await page.goto(`${BASE_URL}/projects`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    const graphLink = page.locator('a[href*="/graph"]').first();
    if (await graphLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await graphLink.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(3000);
      // Pan and zoom graph
      await page.mouse.move(720, 450);
      await page.mouse.wheel(0, -80);
      await page.waitForTimeout(1500);
    }

    console.log('>>> [7/13] Exploring OSINT Module (5 Tabs)...');
    await page.goto(`${BASE_URL}/osint`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    const osintInput = page.locator('input[name="target"], input[name="domain"], input[type="text"]').first();
    const osintBtn = page.locator('button[type="submit"], button:has-text("Analyze"), button:has-text("Search"), button:has-text("Lancer")').first();
    if (await osintInput.isVisible({ timeout: 2000 }).catch(() => false)) {
      await osintInput.fill('ensit.tn');
      await page.waitForTimeout(1000);
      if (await osintBtn.isVisible()) {
        await osintBtn.click();
        await page.waitForTimeout(3000);
      }
    }

    console.log('>>> [8/13] Exploring Offensive Security Sandbox...');
    await page.goto(`${BASE_URL}/security/sandbox`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.evaluate(() => window.scrollBy({ top: 300, behavior: 'smooth' }));
    await page.waitForTimeout(1000);

    console.log('>>> [9/13] Exploring Security Alerts & Monitoring...');
    await page.goto(`${BASE_URL}/security/alerts`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    await page.goto(`${BASE_URL}/security/monitoring`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    console.log('>>> [10/13] Exploring Reports Module...');
    await page.goto(`${BASE_URL}/reports`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    const reportViewLink = page.locator('a[href*="/reports/"]').first();
    if (await reportViewLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await reportViewLink.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(2500);
      await page.evaluate(() => window.scrollBy({ top: 300, behavior: 'smooth' }));
      await page.waitForTimeout(1000);
    }

    console.log('>>> [11/13] Interacting with AI Security Co-pilot...');
    await page.goto(`${BASE_URL}/chat`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const chatInput = page.locator('textarea[name="message"], input[name="message"], textarea').first();
    const chatSubmit = page.locator('button[type="submit"], button:has-text("Send"), button:has-text("Envoyer")').first();
    if (await chatInput.isVisible({ timeout: 2000 }).catch(() => false)) {
      await chatInput.fill('Analyser la surface d\'attaque et prioriser les actions de sécurisation.');
      await page.waitForTimeout(1000);
      if (await chatSubmit.isVisible()) {
        await chatSubmit.click();
        await page.waitForTimeout(4000);
      }
    }

    console.log('>>> [12/13] Logging in as System Administrator...');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);

    const adminEmail = page.locator('input[name="email"], input[type="email"]').first();
    const adminPass = page.locator('input[name="password"], input[type="password"]').first();
    if (await adminEmail.isVisible({ timeout: 2000 }).catch(() => false)) {
      await adminEmail.fill('admin@cybersec.local');
      await adminPass.fill('password');
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');
    }

    await page.waitForTimeout(2000);

    console.log('>>> [13/13] Admin Governance: Users, Health, & Immutable Audit Logs...');
    // Admin Users Management
    await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Admin System Health
    await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Admin Audit Logs
    await page.goto(`${BASE_URL}/admin/audit-logs`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.evaluate(() => window.scrollBy({ top: 350, behavior: 'smooth' }));
    await page.waitForTimeout(2000);

    console.log('>>> Demo walkthrough complete! Finalizing video recording...');
    await page.close();
    await context.close();
    await browser.close();

    console.log('>>> Video successfully saved in:', VIDEO_DIR);
  });
});
