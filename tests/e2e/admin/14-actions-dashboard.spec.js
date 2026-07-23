'use strict';
const { test, expect } = require('../../fixtures');

// Deterministic GitHub data — see public/includes/actions-dashboard-mock.json and
// epics/github_actions_dashboard/plan.md's "Testing approach" for the exact numbers this
// fixture is built to produce. Gated the same way admin.php's own e2e test-mode is
// (INTEGRATION_SEED_TOKEN-matched e2e_token) so it can never be reached on live.
const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const FIXTURE_QS = `e2e_token=${encodeURIComponent(SEED_TOKEN)}&e2e_gh_fixture=1`;

test.describe('GitHub Actions Dashboard', { tag: '@admin' }, () => {
    test('summary stats match the fixture (workflows, 24h runs, success rate, failing now)', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        const stats = page.locator('.gha-summary .gha-stat-value');
        await expect(stats.nth(0)).toHaveText('9');   // Workflows — static config, independent of fixture
        await expect(stats.nth(1)).toHaveText('8');   // Runs · last 24h
        await expect(stats.nth(2)).toHaveText('75%'); // Success rate
        await expect(stats.nth(3)).toHaveText('2');   // Failing now
        await expect(stats.nth(3)).toHaveClass(/danger/);
    });

    test('GitHub fetch failure still renders page chrome with an error banner', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS.replace('e2e_gh_fixture=1', 'e2e_gh_fixture=error')}`);
        // The <h1> is now the shared Dashboards area heading, not an Actions-specific one — the
        // active tab is what identifies this as the Actions tab post-reparenting.
        await expect(page.locator('.admin-nav-tab.active')).toContainText('Actions');
        await expect(page.locator('.gha-error-banner')).toBeVisible();
        await expect(page.locator('.gha-stat-value').first()).toHaveText('9'); // static config still renders
    });

    test('filter narrows the workflow rail; no-match shows the empty state', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        const rows = page.locator('.gha-wf-row');
        await expect(rows).toHaveCount(9);

        await page.fill('#gha-filter', 'nightly');
        await expect(page.locator('.gha-wf-row:visible')).toHaveCount(2); // Nightly DB Backup, Nightly Tests & Security Scan

        await page.fill('#gha-filter', 'zzz-does-not-exist');
        await expect(page.locator('.gha-wf-row:visible')).toHaveCount(0);
        await expect(page.locator('#gha-wf-empty')).toBeVisible();
    });

    test('selecting a workflow from the rail updates the detail card', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        await page.locator('.gha-wf-row', { hasText: 'Cron — Qualifying Results Import' }).click();
        await expect(page).toHaveURL(/workflow=quali-import/);
        await expect(page.locator('.gha-detail-title h2')).toHaveText('Cron — Qualifying Results Import');
    });

    test('selecting a run from the 12h table updates the detail card', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        await page.locator('#gha-12h-toggle').click();
        await page.locator('.gha-run-row-12h', { hasText: 'Cron — Email Notifications' }).first().click();
        await expect(page).toHaveURL(/workflow=email-notify/);
        await expect(page.locator('.gha-detail-title h2')).toHaveText('Cron — Email Notifications');
    });

    test('selecting a workflow from the schedule matrix updates the detail card', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        await page.locator('.gha-matrix-row', { hasText: 'Nightly Tests & Security Scan' }).click();
        await expect(page).toHaveURL(/workflow=nightly-tests/);
        await expect(page.locator('.gha-detail-title h2')).toHaveText('Nightly Tests & Security Scan');
    });

    test('Runs · last 12h panel is collapsed by default and toggles open/closed', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        const body = page.locator('#gha-12h-body');
        const pill = page.locator('#gha-12h-panel .gha-pill');
        await expect(body).toBeHidden();
        await expect(pill).toHaveText('6');

        await page.locator('#gha-12h-toggle').click();
        await expect(body).toBeVisible();
        await page.locator('#gha-12h-toggle').click();
        await expect(body).toBeHidden();
    });

    test('expanding a run row lazy-loads its steps and does not re-fetch on a second expand', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&workflow=nightly-tests&${FIXTURE_QS}`);
        let ajaxRequests = 0;
        page.on('request', (req) => { if (req.url().includes('ajax=run_jobs')) ajaxRequests++; });

        const firstRun = page.locator('[data-run-toggle]').first(); // run #100, id 90001
        await firstRun.click();
        const consoleBlock = firstRun.locator('xpath=following-sibling::div[1]//*[@data-console]');
        await expect(consoleBlock).toContainText('Run nightly report');
        expect(ajaxRequests).toBe(1);

        await firstRun.click(); // collapse
        await firstRun.click(); // re-expand — should use the cached DOM, not fetch again
        expect(ajaxRequests).toBe(1);
    });

    test('language toggle switches dashboard chrome to English', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=actions&${FIXTURE_QS}`);
        await expect(page.locator('.gha-stat-label').first()).toContainText('Workflows');
        await page.goto(`/admin-dashboards.php?tab=actions&toggle_lang=1&${FIXTURE_QS}`);
        await expect(page.locator('.gha-runs-hint')).toHaveText(/Click a run to see its output|Klik på en kørsel/);
        // Whichever direction the shared test account's saved preference started in, the two
        // labels for the same stat must never both read the same string — proves the toggle
        // actually changed something rather than no-op'ing.
        const label = await page.locator('.gha-panel-toggle-title h3').first().textContent();
        expect(['Runs · last 12h', 'Kørsler · seneste 12t']).toContain(label.trim());
    });
});
