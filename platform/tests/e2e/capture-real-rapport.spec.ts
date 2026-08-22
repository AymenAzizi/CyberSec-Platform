import { test, expect, Page } from '@playwright/test';
import { capture, captureWhenSettled, enableScreenshots } from './helpers/screenshots';

enableScreenshots(true);

test.describe.serial('Capture Complete Realistic Production Screenshots for Rapport', () => {
  test.setTimeout(120_000);

  let page: Page;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 1280, height: 800 },
      deviceScaleFactor: 2,
    });
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await page.context().close();
  });

  test('01 - login_page (filled with credentials)', async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"], input[type="email"]', 'admin@cybersec.local');
    await page.fill('input[name="password"], input[type="password"]', '••••••••••••');
    const rememberMe = page.locator('input[name="remember"], input[type="checkbox"]').first();
    if (await rememberMe.count() > 0) await rememberMe.check();
    await capture(page, 'login_page');
  });

  test('02 - register_page (filled with user info)', async () => {
    await page.goto('/register', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="name"]', 'Alex Turner');
    await page.fill('input[name="email"]', 'alex.turner@cybersec.local');
    await page.fill('input[name="password"]', 'P@ssw0rd2026!Sec');
    await page.fill('input[name="password_confirmation"]', 'P@ssw0rd2026!Sec');
    const terms = page.locator('input[type="checkbox"]').first();
    if (await terms.count() > 0) await terms.check();
    await capture(page, 'register_page');
  });

  test('03 - authenticate as admin', async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"], input[type="email"]', 'admin@cybersec.local');
    await page.fill('input[name="password"], input[type="password"]', 'password');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.click('button[type="submit"]')
    ]);
  });

  test('04 - dashboard (admin with full stats and charts)', async () => {
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    await captureWhenSettled(page, 'dashboard', 2500);
  });

  test('05 - projects (engagement catalogue)', async () => {
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await capture(page, 'projects');
  });

  test('06 - new_project (filled creation form)', async () => {
    await page.goto('/projects/create', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="name"]', 'FinTech Core Banking Audit');
    const clientInput = page.locator('input[name="client_name"], input[name="client"]').first();
    if (await clientInput.count() > 0) await clientInput.fill('Global Financial Group');
    const desc = page.locator('textarea[name="description"]').first();
    if (await desc.count() > 0) await desc.fill('Comprehensive perimeter vulnerability assessment and internal segmentation security review.');
    const domainInput = page.locator('input[name*="allowed_domains"]').first();
    if (await domainInput.count() > 0) await domainInput.fill('fintech.global-banking.local');
    await capture(page, 'new_project');
  });

  test('07 - project_detail (engagement scope & targets)', async () => {
    await page.goto('/projects/1', { waitUntil: 'domcontentloaded' });
    await capture(page, 'project_detail');
  });

  test('08 - launch_scan (scan launch form)', async () => {
    await page.goto('/scans/create', { waitUntil: 'domcontentloaded' });
    const projectSelect = page.locator('select#project_id, select[name="project_id"]').first();
    if (await projectSelect.count() > 0) {
      await projectSelect.selectOption({ index: 1 });
      await page.waitForTimeout(500);
    }
    const targetSelect = page.locator('select#target_id, select[name="target_id"]').first();
    if (await targetSelect.count() > 0) {
      const options = targetSelect.locator('option');
      if (await options.count() > 1) {
        await targetSelect.selectOption({ index: 1 });
      }
    }
    const nmapRadio = page.locator('input[type="radio"][value="nmap"]').first();
    if (await nmapRadio.count() > 0) {
      await nmapRadio.check();
    }
    await capture(page, 'launch_scan');
  });

  test('09 - scans_list (scan monitoring table)', async () => {
    await page.goto('/scans', { waitUntil: 'domcontentloaded' });
    await capture(page, 'scans_list');
  });

  test('10 - scan_results (vulnerability scan detail)', async () => {
    await page.goto('/scans/39', { waitUntil: 'domcontentloaded' });
    await capture(page, 'scan_results');
  });

  test('11 - reports (executive & technical reports)', async () => {
    await page.goto('/reports', { waitUntil: 'domcontentloaded' });
    await capture(page, 'reports');
  });

  test('12 - report_view (rendered audit report)', async () => {
    await page.goto('/reports/2', { waitUntil: 'domcontentloaded' });
    await capture(page, 'report_view');
  });

  test('13 - osint (reconnaissance intelligence)', async () => {
    await page.goto('/osint', { waitUntil: 'domcontentloaded' });
    await capture(page, 'osint');
  });

  test('14 - knowledge_graph (Cytoscape asset relationship graph)', async () => {
    await page.goto('/projects/1/graph', { waitUntil: 'domcontentloaded' });
    await captureWhenSettled(page, 'knowledge_graph', 3000);
  });

  test('15 - alerts (security alerts management)', async () => {
    await page.goto('/security/alerts', { waitUntil: 'domcontentloaded' });
    await capture(page, 'alerts');
  });

  test('16 - sandbox (isolated testing environments)', async () => {
    await page.goto('/security/sandbox', { waitUntil: 'domcontentloaded' });
    await capture(page, 'sandbox');
  });

  test('17 - monitoring (real-time platform telemetry)', async () => {
    await page.goto('/security/monitoring', { waitUntil: 'domcontentloaded' });
    await capture(page, 'monitoring');
  });

  test('18 - audit_logs (compliance & activity logs)', async () => {
    await page.goto('/admin/audit-logs', { waitUntil: 'domcontentloaded' });
    await capture(page, 'audit_logs');
  });

  test('19 - admin_users (RBAC user management)', async () => {
    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    await capture(page, 'admin_users');
  });

  test('20 - system_health (services & microservices telemetry)', async () => {
    await page.goto('/admin/system-health', { waitUntil: 'domcontentloaded' });
    await capture(page, 'system_health');
  });

  test('21 - chat (interactive AI cybersecurity co-pilot)', async () => {
    await page.goto('/chat/1', { waitUntil: 'domcontentloaded' });
    
    // Type in active prompt to show interactive co-pilot experience
    const chatInput = page.locator('#chat-input, textarea[name="content"]').first();
    if (await chatInput.count() > 0) {
      await chatInput.fill('Can you generate an Ansible playbook for SSH hardening on port 22?');
    }
    await captureWhenSettled(page, 'chat', 2500);
  });

  test('22 - remediation (Remediation-as-Code script)', async () => {
    await page.goto('/findings/205/remediation', { waitUntil: 'domcontentloaded' });
    await capture(page, 'remediation');
  });
});
