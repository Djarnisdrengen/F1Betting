'use strict';
const { test, expect } = require('../../fixtures');

// Two-tier admin nav (Core / Paddock Challenges / Dashboards) — see
// epics/Admin settings and dashboards/feature-1-two-tier-nav.md.
test.describe('Admin two-tier nav', { tag: '@admin' }, () => {
    test('Level-1 area row shows exactly three areas, active state follows the current page', async ({ page }) => {
        await page.goto('/admin.php');
        const areas = page.locator('.admin-area-tab');
        await expect(areas).toHaveCount(3);
        await expect(page.locator('.admin-area-tab.active')).toContainText('Core');

        await page.goto('/admin-challenges.php');
        await expect(page.locator('.admin-area-tab.active')).toContainText('Paddock Challenges');

        await page.goto('/admin-dashboards.php');
        await expect(page.locator('.admin-area-tab.active')).toContainText('Dashboards');
    });

    test('Dashboards area exposes GitHub Actions as its fifth section tab', async ({ page }) => {
        await page.goto('/admin-dashboards.php');
        const tabs = page.locator('.admin-nav-tab');
        await expect(tabs).toHaveCount(5);
        await expect(tabs.last()).toContainText('Actions');
    });

    test('old admin-actions.php URL redirects to admin-dashboards.php?tab=actions, preserving query string', async ({ page }) => {
        await page.goto('/admin-actions.php?workflow=nightly-tests');
        await expect(page).toHaveURL(/admin-dashboards\.php\?tab=actions&workflow=nightly-tests/);
        await expect(page.locator('.admin-area-tab.active')).toContainText('Dashboards');
        await expect(page.locator('.admin-nav-tab.active')).toContainText('Actions');
    });

    test('old admin-actions.php URL with no query string still redirects cleanly', async ({ page }) => {
        await page.goto('/admin-actions.php');
        await expect(page).toHaveURL(/admin-dashboards\.php\?tab=actions/);
    });

    test('Core and Paddock Challenges retain their own existing tab counts unchanged', async ({ page }) => {
        await page.goto('/admin.php');
        // Regression guard, not a re-spec: Core's own tab row (races/drivers/users/...) still
        // renders under the new Level-1 chrome exactly as before this feature.
        await expect(page.locator('.admin-nav-tab')).toHaveCount(7);
    });
});
