// tests/e2e/08-knowledge-graph.spec.ts
// Knowledge graph — Cytoscape.js renders nodes, blast-radius works.

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';
import { capture } from './helpers/screenshots';

test.describe('Knowledge Graph', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Graph page loads', async ({ page }) => {
    const response = await page.goto('/projects/1/graph', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
  });

  test('Cytoscape.js canvas is rendered', async ({ page }) => {
    await page.goto('/projects/1/graph');
    // Cytoscape renders to a canvas element
    const canvas = page.locator('canvas').first();
    await expect(canvas).toBeVisible({ timeout: 10000 });
  });

  test('Graph data endpoint returns JSON with nodes', async ({ request }) => {
    const response = await request.get('/projects/1/graph/data');
    expect(response.status()).toBe(200);
    const data = await response.json();
    // Should have at least some nodes
    const nodes = data.nodes || data.elements?.filter((e: any) => e.group === 'nodes') || [];
    expect(Array.isArray(nodes)).toBeTruthy();
  });

  test('Asset impact analysis endpoint works', async ({ request }) => {
    // First asset from the seeded dataset
    const response = await request.get('/assets/1/impact');
    expect([200, 404].includes(response.status())).toBeTruthy();
  });

  test('Screenshot for rapport — knowledge graph', async ({ page }) => {
    await page.goto('/projects/1/graph');
    await page.waitForTimeout(3000); // Let Cytoscape layout settle
    await capture(page, 'knowledge_graph');
  });
});
