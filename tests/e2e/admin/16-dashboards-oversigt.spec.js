'use strict';
const { test, expect } = require('../../fixtures');

// Dashboards → Oversigt — see epics/Admin settings and dashboards/feature-2-dashboards-oversigt.md.
// Pure composition of the other four dashboards' own snapshot functions — no independent
// computation, so these tests focus on "does it render and link correctly," not re-deriving
// the underlying numbers (those are covered by each source dashboard's own tests).
test.describe('Dashboards Oversigt', { tag: '@admin' }, () => {
    test('renders exactly four tiles, one per other dashboard', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=oversigt');
        await expect(page.locator('.dash-tile')).toHaveCount(4);
    });

    test('clicking a tile navigates to that dashboard tab', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=oversigt');
        await page.locator('.dash-tile', { hasText: 'PaddockKB' }).click();
        await expect(page).toHaveURL(/tab=paddockkb/);
        await expect(page.locator('.admin-nav-tab.active')).toContainText('PaddockKB');
    });

    test('needs-attention strip renders without a fatal error', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=oversigt');
        // Every tile always has a footer note (healthy or flagged) — never blank, never a crash.
        await expect(page.locator('.dash-tile-foot')).toHaveCount(4);
    });

    // Non-admin/logged-out access rejection is covered in
    // 20-dashboards-access.spec.js — deliberately a separate file using plain
    // @playwright/test, not browser.newContext() here. A manually created context inside a file
    // that imports the admin-authed ../../fixtures inherits the admin storageState (see
    // feedback_playwright_manual_context_leak in project memory) — it does NOT start logged-out.
});
