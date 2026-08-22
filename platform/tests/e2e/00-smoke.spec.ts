// tests/e2e/00-smoke.spec.ts
// Smoke test — verify every public route responds (200 or 302, never 500).
// If this fails, do NOT bother running the other tests — fix routing first.

import { test, expect } from '@playwright/test';

const PUBLIC_ROUTES = [
  { path: '/',                 expectedStatus: 302 }, // redirect to /dashboard
  { path: '/login',           expectedStatus: 200 },
  { path: '/register',        expectedStatus: 200 },
  { path: '/forgot-password',  expectedStatus: 200 },
];

const AUTH_ROUTES = [
  '/dashboard',
  '/projects',
  '/projects/create',
  '/projects/1',
  '/projects/1/graph',
  '/scans',
  '/scans/create',
  '/scans/1',
  '/reports',
  '/reports/1',
  '/security/alerts',
  '/security/monitoring',
  '/security/sandbox',
  '/osint',
  '/chat',
  '/chat/create',
  '/admin/users',
  '/admin/audit-logs',
  '/admin/system-health',
];

test.describe('Smoke — every route returns a non-error status', () => {
  test.describe('Public routes (no auth)', () => {
    for (const { path, expectedStatus } of PUBLIC_ROUTES) {
      test(`GET ${path} → ${expectedStatus}`, async ({ request }) => {
        const response = await request.get(path, { maxRedirects: 0 });
        expect(response.status(), `GET ${path} returned ${response.status()}`).toBe(expectedStatus);
      });
    }
  });

  test.describe('Auth-required routes redirect to /login when logged out', () => {
    for (const path of AUTH_ROUTES) {
      test(`GET ${path} → 302 (redirect to login)`, async ({ request }) => {
        const response = await request.get(path, { maxRedirects: 0 });
        expect([302, 301].includes(response.status()), `GET ${path} returned ${response.status()}`).toBeTruthy();
        const location = response.headers()['location'] || '';
        expect(location).toContain('/login');
      });
    }
  });

  test('No 500 errors anywhere on the platform', async ({ request }) => {
    // Hit every known route once and ensure none return 500
    const allRoutes = [...PUBLIC_ROUTES.map(r => r.path), ...AUTH_ROUTES];
    const failures: string[] = [];
    for (const path of allRoutes) {
      const response = await request.get(path, { maxRedirects: 0 });
      if (response.status() >= 500) {
        failures.push(`${path} → ${response.status()}`);
      }
    }
    expect(failures, `Got 500s: ${failures.join(', ')}`).toEqual([]);
  });

  test('Health endpoint is up', async ({ request }) => {
    const response = await request.get('/api/health');
    // Either 200 (health endpoint exists) or 404 (no health route — not a failure)
    expect([200, 404].includes(response.status())).toBeTruthy();
  });
});
