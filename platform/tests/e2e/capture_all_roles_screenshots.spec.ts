/**
 * tests/e2e/capture_all_roles_screenshots.spec.ts
 * 
 * Comprehensive Playwright test suite to navigate and capture high-resolution
 * production screenshots across the entire CyberSec-ASM platform for all 4 RBAC roles:
 *   1. Public / Unauthenticated (Login & Register)
 *   2. Security Analyst (analyst@cybersec.local)
 *   3. System Administrator (admin@cybersec.local)
 *   4. Client / Audité (client@cybersec.local)
 *   5. Compliance Auditor (auditor@cybersec.local)
 *
 * All input boxes, select dropdowns, textareas, and radio buttons are pre-filled
 * with realistic cybersecurity engagement data before capturing.
 *
 * All screenshots are saved at 2x Retina DPI to:
 *   - cybersec-workspace/rapport/img/
 *   - platform/tests/e2e/screenshots/
 */

import { test, expect, Page, BrowserContext } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// Destination directories using process.cwd() for ESM compatibility
const RAPPORT_IMG_DIR = path.resolve(process.cwd(), '../rapport/img');
const LOCAL_SCREENSHOTS_DIR = path.resolve(process.cwd(), 'tests/e2e/screenshots');

// Ensure directories exist
fs.mkdirSync(RAPPORT_IMG_DIR, { recursive: true });
fs.mkdirSync(LOCAL_SCREENSHOTS_DIR, { recursive: true });

// Credentials for the 4 RBAC roles
const CREDS = {
  admin:   { email: 'admin@cybersec.local',   password: 'password', name: 'Administrateur Système' },
  analyst: { email: 'analyst@cybersec.local', password: 'password', name: 'Analyste Sécurité' },
  client:  { email: 'client@cybersec.local',  password: 'password', name: 'Client Entreprise' },
  auditor: { email: 'auditor@cybersec.local', password: 'password', name: 'Auditeur Conformité' },
} as const;

/**
 * Capture a screenshot to both the rapport img folder and e2e screenshots folder
 */
async function captureScreen(page: Page, filename: string, options: { fullPage?: boolean, clipY?: number, clipHeight?: number } = {}) {
  // Hide scrollbars for clean academic aesthetic
  await page.addStyleTag({ content: '::-webkit-scrollbar { display: none !important; }' });
  await page.waitForTimeout(600); // Allow Alpine.js / Tailwind animations to settle

  const rapportPath = path.join(RAPPORT_IMG_DIR, `${filename}.png`);
  const localPath = path.join(LOCAL_SCREENSHOTS_DIR, `${filename}.png`);

  if (options.clipY !== undefined && options.clipHeight !== undefined) {
    const viewport = page.viewportSize() || { width: 1280, height: 800 };
    await page.screenshot({
      path: rapportPath,
      clip: {
        x: 0,
        y: options.clipY,
        width: viewport.width,
        height: options.clipHeight,
      },
    });
  } else {
    await page.screenshot({
      path: rapportPath,
      fullPage: options.fullPage ?? false,
    });
  }

  // Copy to local screenshots dir
  fs.copyFileSync(rapportPath, localPath);
  const size = fs.statSync(rapportPath).size;
  console.log(`  📸 [CAPTURE] ${filename}.png (${Math.round(size / 1024)} KB)`);
}

/**
 * Helper to safely fill an input if visible
 */
async function safeFill(page: Page, selector: string, value: string) {
  try {
    const loc = page.locator(selector).first();
    if (await loc.isVisible({ timeout: 1500 }).catch(() => false)) {
      await loc.fill(value);
    }
  } catch (e) {
    // Ignore optional fill error
  }
}

/**
 * Helper to log in as a specific role
 */
async function loginAsRole(page: Page, role: keyof typeof CREDS) {
  const { email, password } = CREDS[role];
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  
  const emailInput = page.locator('input[name="email"], input[type="email"]').first();
  if (await emailInput.isVisible({ timeout: 3000 }).catch(() => false)) {
    await emailInput.fill(email);
    await page.locator('input[name="password"], input[type="password"]').first().fill(password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.locator('button[type="submit"]').first().click(),
    ]);
  }
  await page.waitForTimeout(800);
}

/**
 * Helper to log out
 */
async function logoutCurrent(page: Page) {
  await page.goto('/dashboard');
  const logoutBtn = page.locator('a[href*="/logout"], form[action*="/logout"] button, button:has-text("Logout"), button:has-text("Déconnexion")').first();
  if (await logoutBtn.count() > 0) {
    await logoutBtn.click().catch(() => {});
  } else {
    await page.evaluate(() => {
      const form = document.querySelector('form[action*="/logout"]') as HTMLFormElement;
      if (form) form.submit();
    });
  }
  await page.waitForTimeout(500);
}

test.describe.serial('CyberSec-ASM Complete Multi-Role Screenshot Suite', () => {
  test.setTimeout(300_000);

  let page: Page;
  let context: BrowserContext;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({
      viewport: { width: 1280, height: 800 },
      deviceScaleFactor: 2,
    });
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await context.close();
  });

  // =========================================================================
  // 1. PUBLIC & AUTHENTICATION
  // =========================================================================
  test('01 - Capture Login Page (Filled with Credentials)', async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await safeFill(page, 'input[name="email"], input[type="email"]', 'analyst@cybersec.local');
    await safeFill(page, 'input[name="password"], input[type="password"]', 'P@ssw0rd2026!Sec');
    const rememberMe = page.locator('input[name="remember"], input[type="checkbox"]').first();
    if (await rememberMe.isVisible({ timeout: 1000 }).catch(() => false)) await rememberMe.check();
    await captureScreen(page, 'login_page');
  });

  test('02 - Capture Register Page (Filled Form)', async () => {
    await page.goto('/register', { waitUntil: 'domcontentloaded' });
    await safeFill(page, 'input[name="name"]', 'Aymen AZIZI');
    await safeFill(page, 'input[name="email"]', 'aymen.azizi@tekup.de');
    await safeFill(page, 'input[name="password"]', 'P@ssw0rd2026!Sec');
    await safeFill(page, 'input[name="password_confirmation"]', 'P@ssw0rd2026!Sec');
    const terms = page.locator('input[type="checkbox"]').first();
    if (await terms.isVisible({ timeout: 1000 }).catch(() => false)) await terms.check();
    await captureScreen(page, 'register_page');
  });

  // =========================================================================
  // 2. SECURITY ANALYST JOURNEY
  // =========================================================================
  test('03 - Analyst Authentication & Dashboard', async () => {
    await loginAsRole(page, 'analyst');
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000); // Allow Chart.js charts to render

    // Capture Part 1 (Top KPIs, attack surface summary)
    await captureScreen(page, 'dashboard_p1', { clipY: 0, clipHeight: 750 });

    // Scroll and Capture Part 2 (Vulnerability breakdown, recent scans)
    await page.evaluate(() => window.scrollBy(0, 600));
    await page.waitForTimeout(500);
    await captureScreen(page, 'dashboard_p2', { clipY: 0, clipHeight: 750 });
    await page.evaluate(() => window.scrollTo(0, 0));
  });

  test('04 - Analyst Projects Management', async () => {
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'projects');
  });

  test('05 - Analyst Project Creation Form with PoA (Fully Filled)', async () => {
    await page.goto('/projects/create', { waitUntil: 'domcontentloaded' });
    await safeFill(page, 'input#name, input[name="name"]', 'Audit Périmètre Critique — Banque Populaire');
    await safeFill(page, 'input#client_name, input[name="client_name"]', 'Banque Populaire Tunisienne (Direction SI)');
    await safeFill(page, 'textarea#description, textarea[name="description"]', 'Évaluation exhaustive des vulnérabilités externes, cartographie de la surface d’attaque ASM, et modélisation de graphe sous mandat d’autorisation PoA strict.');
    
    const statusSelect = page.locator('select#status, select[name="status"]').first();
    if (await statusSelect.isVisible({ timeout: 1000 }).catch(() => false)) await statusSelect.selectOption('active');
    
    await safeFill(page, 'input#expires_at, input[name="expires_at"]', '2026-12-31');
    await safeFill(page, 'input[name="scope_config[allowed_domains][]"]', 'ebanking.populaire.tn');
    await safeFill(page, 'input[name="scope_config[allowed_ips][]"]', '192.168.10.0/24');
    await safeFill(page, 'textarea#excluded_paths, textarea[name="scope_config[excluded_paths]"]', '/admin/internal-billing\n/api/v1/payment-gateway-live\n/auth/saml/sso-core');

    await captureScreen(page, 'new_project');
  });

  test('06 - Analyst Project Detail with Targets & PoA Status', async () => {
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    const firstProj = page.locator('a[href*="/projects/"]').first();
    if (await firstProj.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstProj.click();
      await page.waitForLoadState('domcontentloaded');
    } else {
      await page.goto('/projects/1', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForTimeout(800);
    await captureScreen(page, 'project_detail');
  });

  test('07 - Analyst Launch Scan Configuration Form (Fully Filled)', async () => {
    await page.goto('/scans/create', { waitUntil: 'domcontentloaded' });
    
    const projectSelect = page.locator('select#project_id').first();
    if (await projectSelect.isVisible({ timeout: 1000 }).catch(() => false)) {
      await projectSelect.selectOption({ index: 1 }).catch(() => {});
      await page.waitForTimeout(500);
    }
    
    const targetSelect = page.locator('select#target_id').first();
    if (await targetSelect.isVisible({ timeout: 1000 }).catch(() => false)) {
      const opts = targetSelect.locator('option');
      if (await opts.count() > 1) {
        await targetSelect.selectOption({ index: 1 }).catch(() => {});
      }
    }

    // Select Nmap or Nuclei scan type
    const nmapRadio = page.locator('input[type="radio"][value="nmap"]').first();
    if (await nmapRadio.isVisible({ timeout: 1000 }).catch(() => false)) await nmapRadio.check();

    // Select Balanced profile
    const balancedProfile = page.locator('input[type="radio"][value="balanced"]').first();
    if (await balancedProfile.isVisible({ timeout: 1000 }).catch(() => false)) await balancedProfile.check();

    // Expand advanced configuration
    const advToggle = page.locator('[data-collapse-toggle="advanced-config"]').first();
    if (await advToggle.isVisible({ timeout: 1000 }).catch(() => false)) {
      await advToggle.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    await safeFill(page, 'input#custom_ports, input[name="config[custom_ports]"]', '80,443,8080,8443,22,3306');
    await safeFill(page, 'input#custom_flags, input[name="config[custom_flags]"]', '--script=vuln,default -sV -T4 --open');

    await captureScreen(page, 'launch_scan');
  });

  test('08 - Analyst Scans List & Status Monitoring', async () => {
    await page.goto('/scans', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'scans_list');
  });

  test('09 - Analyst Scan Results & Vulnerability Findings', async () => {
    await page.goto('/scans', { waitUntil: 'domcontentloaded' });
    const firstScan = page.locator('a[href*="/scans/"]').first();
    if (await firstScan.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstScan.click();
      await page.waitForLoadState('domcontentloaded');
    } else {
      await page.goto('/scans/1', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForTimeout(1000);
    
    // Part 1: Top stats, severity breakdown
    await captureScreen(page, 'scan_results_p1', { clipY: 0, clipHeight: 750 });

    // Part 2: Findings table with CWE/CVE badges
    await page.evaluate(() => window.scrollBy(0, 600));
    await page.waitForTimeout(500);
    await captureScreen(page, 'scan_results_p2', { clipY: 0, clipHeight: 750 });
    await page.evaluate(() => window.scrollTo(0, 0));
  });

  test('10 - Analyst Individual Finding Detail & Evidence', async () => {
    await page.goto('/findings', { waitUntil: 'domcontentloaded' });
    const firstFinding = page.locator('a[href*="/findings/"]').first();
    if (await firstFinding.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstFinding.click();
      await page.waitForLoadState('domcontentloaded');
    }
    await page.waitForTimeout(1000);
    await captureScreen(page, 'finding_detail');
  });

  test('11 - Analyst AI Remediation-as-Code View', async () => {
    const cur = page.url();
    if (cur.includes('/findings/')) {
      const remUrl = cur.endsWith('/remediation') ? cur : `${cur}/remediation`;
      await page.goto(remUrl, { waitUntil: 'domcontentloaded' });
    } else {
      await page.goto('/findings', { waitUntil: 'domcontentloaded' });
      const fLink = page.locator('a[href*="/findings/"]').first();
      if (await fLink.isVisible({ timeout: 1500 }).catch(() => false)) {
        const href = await fLink.getAttribute('href');
        if (href) await page.goto(`${href}/remediation`, { waitUntil: 'domcontentloaded' });
      }
    }
    await page.waitForTimeout(1500);
    await captureScreen(page, 'remediation');
  });

  test('12 - Analyst Interactive Knowledge Graph (Cytoscape)', async () => {
    await page.goto('/projects/1/graph', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000); // Allow Cytoscape graph animation to layout
    await captureScreen(page, 'knowledge_graph');
  });

  test('13 - Analyst Blast Radius Impact Propagation', async () => {
    await page.goto('/projects/1/graph?impact=1', { waitUntil: 'domcontentloaded' }).catch(async () => {
      await page.goto('/projects/1/graph', { waitUntil: 'domcontentloaded' });
    });
    await page.waitForTimeout(2500);
    await captureScreen(page, 'blast_radius_analysis');
  });

  test('14 - Analyst OSINT Reconnaissance Tool (Filled Query)', async () => {
    await page.goto('/osint', { waitUntil: 'domcontentloaded' });
    await safeFill(page, 'input[name="domain"], input[name="target"], input#target', 'ebanking.populaire.tn');
    await captureScreen(page, 'osint');
  });

  test('15 - Analyst Isolated Sandbox Environment', async () => {
    await page.goto('/security/sandbox', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    await captureScreen(page, 'sandbox');
  });

  test('16 - Analyst Security Alerts & Incident Management', async () => {
    await page.goto('/security/alerts', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    
    // Part 1: Top alerts
    await captureScreen(page, 'alerts_p1', { clipY: 0, clipHeight: 750 });

    // Part 2: Alerts table
    await page.evaluate(() => window.scrollBy(0, 500));
    await page.waitForTimeout(500);
    await captureScreen(page, 'alerts_p2', { clipY: 0, clipHeight: 750 });
    await page.evaluate(() => window.scrollTo(0, 0));
  });

  test('17 - Analyst Continuous Monitoring & Telemetry', async () => {
    await page.goto('/security/monitoring', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await captureScreen(page, 'monitoring');
  });

  test('18 - Analyst AI Co-pilot Chat Assistant (Active Prompt)', async () => {
    await page.goto('/chat', { waitUntil: 'domcontentloaded' });
    const firstChatLink = page.locator('a[href*="/chat/"]').first();
    if (await firstChatLink.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstChatLink.click().catch(() => {});
      await page.waitForLoadState('domcontentloaded');
    }
    
    await safeFill(page, '#chat-input, textarea[name="content"], input[name="message"]', 'Comment remédier à la vulnérabilité CVE-2024-21626 (runc container breakout) sur les serveurs Nginx/Docker en production ?');
    await page.waitForTimeout(1000);
    await captureScreen(page, 'chat');
  });

  test('19 - Analyst Reports List & Certified PDF Export', async () => {
    await page.goto('/reports', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'reports');
  });

  test('20 - Analyst Rendered Report View', async () => {
    await page.goto('/reports', { waitUntil: 'domcontentloaded' });
    const firstRep = page.locator('a[href*="/reports/"]').first();
    if (await firstRep.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstRep.click();
      await page.waitForLoadState('domcontentloaded');
    } else {
      await page.goto('/reports/1', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForTimeout(1000);

    // Part 1: Executive Summary & Integrity Hash
    await captureScreen(page, 'report_view_p1', { clipY: 0, clipHeight: 750 });

    // Part 2: Detailed Findings Section
    await page.evaluate(() => window.scrollBy(0, 700));
    await page.waitForTimeout(500);
    await captureScreen(page, 'report_view_p2', { clipY: 0, clipHeight: 750 });
    await page.evaluate(() => window.scrollTo(0, 0));
  });

  // =========================================================================
  // 3. SYSTEM ADMINISTRATOR JOURNEY
  // =========================================================================
  test('21 - Admin User Management & RBAC Quotas', async () => {
    await logoutCurrent(page);
    await loginAsRole(page, 'admin');
    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'admin_users');
  });

  test('22 - Admin User Edit & Quota Configuration (Fully Filled)', async () => {
    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    const firstEdit = page.locator('a[href*="/edit"]').first();
    if (await firstEdit.isVisible({ timeout: 1500 }).catch(() => false)) {
      await firstEdit.click();
      await page.waitForLoadState('domcontentloaded');
    } else {
      await page.goto('/admin/users/1/edit', { waitUntil: 'domcontentloaded' });
    }
    
    await safeFill(page, 'input#name, input[name="name"]', 'Aymen AZIZI');
    await safeFill(page, 'input#email, input[name="email"]', 'aymen.azizi@tekup.de');
    
    const roleSelect = page.locator('select#role, select[name="role"]').first();
    if (await roleSelect.isVisible({ timeout: 1000 }).catch(() => false)) await roleSelect.selectOption('admin');
    
    await safeFill(page, 'input#quota_scans_per_day, input[name="quota_scans_per_day"]', '50');

    await page.waitForTimeout(800);
    await captureScreen(page, 'admin_user_edit');
  });

  test('23 - Admin Immutable Audit Logs', async () => {
    await page.goto('/admin/audit-logs', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'audit_logs');
  });

  test('24 - Admin 13 Microservices System Health', async () => {
    await page.goto('/admin/system-health', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await captureScreen(page, 'system_health');
  });

  // =========================================================================
  // 4. CLIENT / AUDITÉ JOURNEY
  // =========================================================================
  test('25 - Client Restricted Posture Dashboard', async () => {
    await logoutCurrent(page);
    await loginAsRole(page, 'client');
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await captureScreen(page, 'client_dashboard');
  });

  test('26 - Client Assigned Projects View', async () => {
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'client_projects');
  });

  test('27 - Client Finalized Reports Download View', async () => {
    await page.goto('/reports', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'client_reports');
  });

  // =========================================================================
  // 5. COMPLIANCE AUDITOR JOURNEY
  // =========================================================================
  test('28 - Auditor Compliance Dashboard & PoA Inspection', async () => {
    await logoutCurrent(page);
    await loginAsRole(page, 'auditor');
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await captureScreen(page, 'auditor_dashboard');
  });

  test('29 - Auditor Legal PoA Projects Inspection', async () => {
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'auditor_projects');
  });

  test('30 - Auditor Verification of Immutable Audit Logs', async () => {
    await page.goto('/admin/audit-logs', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await captureScreen(page, 'auditor_audit_logs');
  });

  test('31 - Auditor Service Availability & Health Inspection', async () => {
    await page.goto('/admin/system-health', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    await captureScreen(page, 'auditor_system_health');
  });
});
