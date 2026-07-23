'use strict';
const { test, expect } = require('@playwright/test');

// Separate file, plain @playwright/test (not ../../fixtures) — deliberately unauthenticated,
// unlike 14-actions-dashboard.spec.js's admin-authed tests.
test.describe('GitHub Actions Dashboard — access control', { tag: '@admin' }, () => {
    test('logged-out visitor is redirected away from admin-dashboards.php?tab=actions', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=actions');
        await expect(page).toHaveURL(/login\.php/);
    });

    // The lazy-expand endpoint is a separate code path from the page's own GET render
    // (public/admin-dashboards.php's `?tab=actions&ajax=run_jobs` branch) — it needs its own
    // admin gate, not just "the page is gated" by implication.
    test('?tab=actions&ajax=run_jobs is independently admin-gated for a logged-out visitor', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=actions&ajax=run_jobs&run_id=90001');
        await expect(page).toHaveURL(/login\.php/);
    });

    // admin-actions.php itself is now just a compatibility redirect shim (see
    // epics/Admin settings and dashboards/plan.md decision 2) — it must stay admin-gated too,
    // not become an unauthenticated peek at whether the redirect target exists.
    test('logged-out visitor hitting the old admin-actions.php URL is sent to login, not redirected onward', async ({ page }) => {
        await page.goto('/admin-actions.php');
        await expect(page).toHaveURL(/login\.php/);
    });
});
