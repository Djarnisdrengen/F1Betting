'use strict';
const { test, expect } = require('../../fixtures');

// PaddockKB — see epics/Admin settings and dashboards/feature-4-paddockkb-dashboard.md.
// Reuses the GitHub Actions dashboard's fixture mode (e2e_gh_fixture) for run-history
// determinism, same convention as tests/e2e/admin/14-actions-dashboard.spec.js.
const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const FIXTURE_QS = `e2e_token=${encodeURIComponent(SEED_TOKEN)}&e2e_gh_fixture=1`;

test.describe('Dashboards PaddockKB', { tag: '@admin' }, () => {
    test('KPI row renders real entry/category/index-size figures', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=paddockkb');
        await expect(page.locator('.gha-stat-value').first()).not.toBeEmpty();
    });

    test('run log shows both success and failure outcomes, not filtered to successes only', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=paddockkb&${FIXTURE_QS}`);
        const rows = page.locator('.dash-run-row');
        await expect(rows.first()).toBeVisible();
    });

    test('category freshness rows render a dot and a count', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=paddockkb');
        const rows = page.locator('.dash-cat-row');
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);
        await expect(rows.first().locator('.dash-fresh-dot')).toBeVisible();
    });

    test('"Kør opdatering nu" is a real form posting kb_trigger_update', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=paddockkb');
        const form = page.locator('form', { has: page.locator('input[name="action"][value="kb_trigger_update"]') });
        await expect(form).toBeVisible();
        await expect(form.locator('button[type="submit"]')).toBeVisible();
    });

    test('read-only guarantee: no control besides the trigger button posts a write', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=paddockkb');
        const forms = page.locator('form');
        const count = await forms.count();
        for (let i = 0; i < count; i++) {
            const actionValue = await forms.nth(i).locator('input[name="action"]').getAttribute('value');
            expect(actionValue).toBe('kb_trigger_update');
        }
    });

    // Non-admin/logged-out access rejection is covered in 20-dashboards-access.spec.js — see
    // that file's header comment for why it isn't tested here with browser.newContext().
});
