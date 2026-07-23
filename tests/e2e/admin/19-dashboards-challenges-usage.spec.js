'use strict';
const { test, expect } = require('../../fixtures');

// Challenges usage — see epics/Admin settings and dashboards/feature-5-challenges-usage-dashboard.md.
// Strictly read-only aggregates over existing Paddock Challenges tables — no new schema, no
// fixture mode needed (unlike PaddockKB/GitHub Actions, there's no external API involved).
test.describe('Dashboards Challenges usage', { tag: '@admin' }, () => {
    test('KPI row and per-game cards render', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('.gha-stat-value')).toHaveCount(4);
    });

    test('three competition cards render, one per game, each with its own metric label', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('text=Duels')).toBeVisible();
        await expect(page.locator('text=Rumor or Not')).toBeVisible();
        await expect(page.locator('text=Weekly Trivia')).toBeVisible();
    });

    test('funnel panel renders three steps in descending order', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        const rows = page.locator('.gha-panel').last().locator('div[style*="grid-template-columns:200px"]');
        await expect(rows).toHaveCount(3);
    });

    test('read-only guarantee: no form exists on this page', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('form')).toHaveCount(0);
    });

    // Non-admin/logged-out access rejection is covered in 20-dashboards-access.spec.js — see
    // that file's header comment for why it isn't tested here with browser.newContext().
});
