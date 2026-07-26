'use strict';
const { test, expect } = require('../../fixtures');

// Two-tier admin nav (Core / Paddock Challenges / Dashboards) — see
// epics/Admin settings and dashboards/feature-1-two-tier-nav.md.
test.describe('Admin two-tier nav', { tag: '@admin' }, () => {
    test('Level-1 area row shows exactly three areas, active state follows the current page', async ({ page }) => {
        await page.goto('/admin.php');
        const areas = page.locator('[data-testid="admin-area-tab"]');
        await expect(areas).toHaveCount(3);
        await expect(page.locator('[data-testid="admin-area-tab"].active')).toContainText('Core');

        await page.goto('/admin-challenges.php');
        await expect(page.locator('[data-testid="admin-area-tab"].active')).toContainText('Paddock Challenges');

        await page.goto('/admin-dashboards.php');
        await expect(page.locator('[data-testid="admin-area-tab"].active')).toContainText('Dashboards');
    });

    test('Dashboards area exposes GitHub Actions as its fifth section tab', async ({ page }) => {
        await page.goto('/admin-dashboards.php');
        const tabs = page.locator('[data-testid="admin-tab"]');
        await expect(tabs).toHaveCount(5);
        await expect(tabs.last()).toContainText('Actions');
    });

    test('old admin-actions.php URL redirects to admin-dashboards.php?tab=actions, preserving query string', async ({ page }) => {
        await page.goto('/admin-actions.php?workflow=nightly-tests');
        await expect(page).toHaveURL(/admin-dashboards\.php\?tab=actions&workflow=nightly-tests/);
        await expect(page.locator('[data-testid="admin-area-tab"].active')).toContainText('Dashboards');
        await expect(page.locator('[data-testid="admin-tab"].active')).toContainText('Actions');
    });

    test('old admin-actions.php URL with no query string still redirects cleanly', async ({ page }) => {
        await page.goto('/admin-actions.php');
        await expect(page).toHaveURL(/admin-dashboards\.php\?tab=actions/);
    });

    test('Core and Paddock Challenges retain their own existing tab counts unchanged', async ({ page }) => {
        await page.goto('/admin.php');
        // Regression guard, not a re-spec: Core's own tab row (races/drivers/users/...) still
        // renders under the new Level-1 chrome exactly as before this feature. Count is 8
        // (races/drivers/users/invites/bets/security/logs/settings) since the Logs tab landed
        // the same day as this dashboards feature, on a separate branch.
        await expect(page.locator('[data-testid="admin-tab"]')).toHaveCount(8);
    });

    // Container-query breakpoint — see epics/Admin area redesign/plan.md "Container-query
    // breakpoint verification": .admin-shell reacts to its own width, not the viewport, but
    // these admin pages are single-column so viewport width tracks it directly (minus the
    // .hf-container padding). Checked on both nav levels independently since each sits in its
    // own .admin-shell and could in principle cross the 720px threshold at a different point.
    test('nav shows desktop tabs and hides the mobile dropdown at a wide viewport', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 800 });
        await page.goto('/admin-dashboards.php');
        const shells = page.locator('.admin-shell');
        await expect(shells.nth(0).locator('.admin-tabs')).toBeVisible();
        await expect(shells.nth(0).locator('.admin-dropdown')).toBeHidden();
        await expect(shells.nth(1).locator('.admin-tabs')).toBeVisible();
        await expect(shells.nth(1).locator('.admin-dropdown')).toBeHidden();
    });

    test('nav shows the mobile dropdown and hides desktop tabs at a narrow viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await page.goto('/admin-dashboards.php');
        const shells = page.locator('.admin-shell');
        await expect(shells.nth(0).locator('.admin-tabs')).toBeHidden();
        await expect(shells.nth(0).locator('.admin-dropdown')).toBeVisible();
        await expect(shells.nth(1).locator('.admin-tabs')).toBeHidden();
        await expect(shells.nth(1).locator('.admin-dropdown')).toBeVisible();
    });

    test('mobile dropdown menu lists all sibling tabs and closes after navigating', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await page.goto('/admin-dashboards.php');
        const secondShell = page.locator('.admin-shell').nth(1);
        const details = secondShell.locator('.admin-dropdown');
        await expect(details).not.toHaveAttribute('open', '');

        await details.locator('summary').click();
        await expect(details).toHaveAttribute('open', '');
        await expect(details.locator('.menu a')).toHaveCount(5);

        await details.locator('.menu a', { hasText: 'PaddockKB' }).click();
        await expect(page).toHaveURL(/tab=paddockkb/);
    });
});
