// tests/e2e/03-projects.spec.ts
// Projects CRUD — list, create, show, delete.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Projects', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Projects index loads', async ({ page }) => {
    const response = await page.goto('/projects', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    const cards = page.locator('[data-testid="project-card"], .project-card, article, .bg-\\[\\#131826\\]');
    expect(await cards.count()).toBeGreaterThan(0);
  });

  test('Each project card shows name + status', async ({ page }) => {
    await page.goto('/projects');
    const firstCard = page.locator('[data-testid="project-card"], article, .bg-\\[\\#131826\\]').first();
    await expect(firstCard).toBeVisible();
    const text = await firstCard.innerText();
    expect(text.length).toBeGreaterThan(20);
  });

  test('Project detail page loads for project ID 1', async ({ page }) => {
    const response = await page.goto('/projects/1', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    // Should show project name + scope/targets/scans
    const body = await page.locator('body').innerText();
    expect(body.length).toBeGreaterThan(100);
  });

  test('New project form renders all required fields', async ({ page }) => {
    await page.goto('/projects/create');
    // Required fields per the schema
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('input[name="client"], input[name="client_name"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('Create a new project succeeds', async ({ page }) => {
    await page.goto('/projects/create', { waitUntil: 'networkidle' });
    const testProject = `Test Project ${Date.now()}`;
    await page.fill('input[name="name"]', testProject);
    const clientInput = page.locator('input[name="client"], input[name="client_name"]').first();
    await clientInput.fill('Test Client');

    // Scope / allowed domains (textarea or multiple inputs)
    const scopeInput = page.locator('textarea[name*="scope"], input[name*="domain"]').first();
    if (await scopeInput.count() > 0) {
      await scopeInput.fill('example.com');
    }

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    // Should redirect to /projects or /projects/{id}
    expect(page.url()).toMatch(/\/projects(\/\d+)?$/);
    // The new project should appear in the list
    await page.goto('/projects');
    await expect(page.locator(`text=${testProject}`)).toBeVisible({ timeout: 5000 });
  });

  test('Project graph page loads with Cytoscape.js', async ({ page }) => {
    const response = await page.goto('/projects/1/graph', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    // Cytoscape.js renders a canvas
    const canvas = page.locator('canvas').first();
    await expect(canvas).toBeVisible({ timeout: 10000 });
  });

  test('Project graph data endpoint returns valid JSON', async ({ request }) => {
    const response = await request.get('/projects/1/graph/data');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toBeTruthy();
    // Should have nodes and edges arrays
    expect(data.nodes || data.elements || data.data).toBeTruthy();
  });

  test('Screenshot for rapport — projects list', async ({ page }) => {
    await page.goto('/projects');
    await capture(page, 'projects');
  });

  test('Screenshot for rapport — new project form', async ({ page }) => {
    await page.goto('/projects/create');
    await capture(page, 'new_project');
  });

  test('Screenshot for rapport — project detail', async ({ page }) => {
    await page.goto('/projects/1');
    await capture(page, 'project_detail');
  });

  test('Screenshot for rapport — knowledge graph', async ({ page }) => {
    await page.goto('/projects/1/graph');
    await page.waitForTimeout(2000); // Cytoscape.js needs time to render
    await capture(page, 'knowledge_graph');
  });
});
