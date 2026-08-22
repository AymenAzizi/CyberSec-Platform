import { test, expect } from '@playwright/test';
import { capture } from './helpers/screenshots';

test.describe('Full Real Flow for Rapport', () => {
  // Use a long timeout for the full flow
  test.setTimeout(300_000); // 5 minutes

  test('End-to-End User Journey', async ({ page }) => {
    const timestamp = Date.now();
    const email = `demo${timestamp}@cybersec.local`;
    const password = 'Password123!';
    
    // 1. Register
    await page.goto('/register');
    await capture(page, '01_register_page');
    
    await page.fill('input[name="name"]', 'Report Demo User');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.fill('input[name="password_confirmation"]', password);
    await page.check('input[type="checkbox"]');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    
    // Wait for redirect to dashboard
    await expect(page).toHaveURL(/\/dashboard/);
    await capture(page, '02_dashboard_empty');

    // 2. Create Project
    await page.goto('/projects/create');
    await capture(page, '03_create_project');
    
    await page.fill('input[name="name"]', 'Rapport Live Target');
    await page.fill('input[name="name"]', 'Rapport Live Target');
    await page.locator('input[name="client"], input[name="client_name"]').first().fill('Internal Security Team');
    await page.locator('textarea[name*="scope"], input[name*="domain"]').first().fill('scanme.nmap.org');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    
    await capture(page, '04_project_created');

    // 3. Run Real Scan
    await page.goto('/scans/create');
    await capture(page, '05_scan_launch');
    
    // Select nmap
    await page.locator('select[name="scan_type"], select[name="tool"]').first().selectOption({ label: 'nmap' });
    await page.locator('input[name="target"], input[name="host"]').first().fill('scanme.nmap.org');
    
    const profileRadio = page.locator('input[name="profile"][value*="balanced"], label:has-text("Balanced") input').first();
    if (await profileRadio.count() > 0) {
      await profileRadio.check();
    } else {
      await page.locator('select[name="profile"]').first().selectOption({ label: 'balanced' });
    }
    
    await page.click('button[type="submit"]');
    
    await page.waitForLoadState('networkidle');
    await capture(page, '06_scan_running');
    
    // Wait for scan to complete
    let completed = false;
    for (let i = 0; i < 45; i++) {
      await page.waitForTimeout(2000);
      await page.reload({ waitUntil: 'networkidle' });
      const body = await page.locator('body').innerText();
      if (/Completed|completed/i.test(body)) {
        completed = true;
        break;
      }
    }
    
    await capture(page, '07_scan_results');

    // 4. OSINT
    await page.goto('/osint');
    await page.fill('input[name="target"]', 'scanme.nmap.org');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(5000); // Wait for OSINT to finish
    await capture(page, '08_osint_results');

    // 5. AI Chat
    await page.goto('/chat');
    await page.click('a:has-text("New Chat")');
    await page.waitForLoadState('networkidle');
    await page.fill('textarea[name="message"]', 'Explain the risks of open port 22 (SSH) on a public server.');
    await page.click('button[type="submit"]');
    
    // Wait for AI response
    for (let i = 0; i < 30; i++) {
      await page.waitForTimeout(2000);
      const body = await page.locator('body').innerText();
      if (body.length > 500 && !body.includes('Thinking')) break;
    }
    await capture(page, '09_ai_chat');

    // 6. Sandbox
    await page.goto('/security/sandbox');
    await capture(page, '10_sandbox_list');
    await page.click('text=DVWA >> xpath=ancestor::*[position()=1] >> button:has-text("Launch")');
    await page.waitForTimeout(4000);
    await capture(page, '11_sandbox_running');
  });
});
