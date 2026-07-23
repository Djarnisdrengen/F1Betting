'use strict';
const { test, expect } = require('../../fixtures');

// Nøgler & Rotation — see epics/Admin settings and dashboards/feature-3-nogler-rotation.md.
// The one dashboard in this epic with a real privileged side effect. "Roter nu" is tested
// through the ACTUAL production code path (DB update + config-writer + audit log all in one
// pass), not a separate isolated unit test of the writer — the config-writer's target file is
// redirected to a throwaway fixture path via e2e_nr_fixture, exactly the way
// admin-actions.php's own e2e_gh_fixture redirects GitHub API calls. See
// nogler-rotation-lib.php's nrRotationFixtureModeActive() / nrConfigWritePath().
//
// Selectors here deliberately avoid matching on button TEXT — the shared admin-authed session
// this fixture reuses can be left in English by an earlier test (14-actions-dashboard.spec.js's
// own language-toggle test), so "Roter nu" vs "Rotate now" isn't reliable. auto-mode secrets
// render their action as a DIRECT <form> child of the row; record-mode secrets nest theirs
// inside a <details> disclosure — that structural difference is what these tests key off.
const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const FIXTURE_QS = `e2e_token=${encodeURIComponent(SEED_TOKEN)}&e2e_nr_fixture=1`;

test.describe('Dashboards Nøgler & Rotation', { tag: '@admin' }, () => {
    test('health ring, KPI grid and action queue render', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        await expect(page.locator('.dash-health-score')).toBeVisible();
        await expect(page.locator('.gha-stat-value')).toHaveCount(6);
    });

    test('the one real access token (GitHub) is listed, not three', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        await expect(page.locator('[data-item-key="github_pat"]')).toHaveCount(1);
        await expect(page.locator('[data-item-key="anthropic_key"], [data-item-key="openai_key"]')).toHaveCount(0);
    });

    test('token expiry recording updates the row and appends an audit entry', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        const tokenRow = page.locator('[data-item-key="github_pat"]');
        await tokenRow.locator('summary').click();
        await tokenRow.locator('input[name="expires_at"]').fill('2027-01-01');
        await tokenRow.locator('button[type="submit"]').click();
        await expect(page).toHaveURL(/nr_msg=token_recorded/);
    });

    test('Cancel closes the token date-entry disclosure without submitting', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        const tokenRow = page.locator('[data-item-key="github_pat"]');
        const details = tokenRow.locator('details');
        await tokenRow.locator('summary').click();
        await expect(details).toHaveJSProperty('open', true);
        await tokenRow.locator('[data-dash-cancel]').click();
        await expect(details).toHaveJSProperty('open', false);
        await expect(page).toHaveURL(/tab=keys/);
        await expect(page).not.toHaveURL(/nr_msg=/);
    });

    test('a record-mode secret (e.g. DB_PASSWORD) has no direct rotate form, only the details/record flow', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        const dbRow = page.locator('[data-item-key="db_password"]');
        await expect(dbRow).toHaveAttribute('data-mode', 'record');
        await expect(dbRow.locator('> form')).toHaveCount(0);
        await expect(dbRow.locator('details summary')).toBeVisible();
    });

    test('recording a record-mode secret rotation requires confirmation and logs it', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        page.once('dialog', (d) => d.accept());
        const smtpRow = page.locator('[data-item-key="smtp_password"]');
        await smtpRow.locator('summary').click();
        await smtpRow.locator('button[type="submit"]').click();
        await expect(page).toHaveURL(/nr_msg=secret_recorded/);
    });

    // v1 auto-rotatable set (Djarnis's explicit 2026-07-23 call, see
    // feature-3-nogler-rotation.md): CHALLENGE_INVITE_SECRET (always safe), plus
    // INTEGRATION_SEED_TOKEN/CRON_SECRET (CI-breakage-only risk, no user impact — approved).
    // MFA_KEY/PASSWORD_PEPPER stay record-only (mass password/2FA invalidation — not approved).
    for (const key of ['challenge_invite_secret', 'integration_seed_token', 'cron_secret']) {
        test(`${key} is auto-rotatable (direct form, no details disclosure)`, async ({ page }) => {
            await page.goto('/admin-dashboards.php?tab=keys');
            const row = page.locator(`[data-item-key="${key}"]`);
            await expect(row).toHaveAttribute('data-mode', 'auto');
            await expect(row.locator('> form button[type="submit"]')).toBeVisible();
            await expect(row.locator('details')).toHaveCount(0);
        });
    }

    for (const key of ['mfa_key', 'password_pepper']) {
        test(`${key} stays record-only, not auto-rotatable`, async ({ page }) => {
            await page.goto('/admin-dashboards.php?tab=keys');
            const row = page.locator(`[data-item-key="${key}"]`);
            await expect(row).toHaveAttribute('data-mode', 'record');
            await expect(row.locator('> form')).toHaveCount(0);
            await expect(row.locator('details summary')).toBeVisible();
        });
    }

    test('"Roter nu" exercises the real rotation path via the E2E fixture redirect, and resets the badge/age', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=keys&${FIXTURE_QS}`);
        page.once('dialog', (d) => d.accept());
        const inviteRow = page.locator('[data-item-key="challenge_invite_secret"]');
        await inviteRow.locator('> form button[type="submit"]').click();
        await expect(page).toHaveURL(/nr_msg=secret_rotated/);

        await page.goto(`/admin-dashboards.php?tab=keys&${FIXTURE_QS}`);
        const refreshedRow = page.locator('[data-item-key="challenge_invite_secret"]');
        await expect(refreshedRow).toContainText(/0 \//); // age reset to 0
    });

    test('rotating a secret with no GitHub Actions pairing shows a reveal-once panel with the new value and only the local-config line', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=keys&${FIXTURE_QS}`);
        page.once('dialog', (d) => d.accept());
        const inviteRow = page.locator('[data-item-key="challenge_invite_secret"]');
        await inviteRow.locator('> form button[type="submit"]').click();
        await expect(page).toHaveURL(/nr_msg=secret_rotated/);

        const panel = page.locator('#nr-reveal-panel');
        await expect(panel).toHaveAttribute('data-item-key', 'challenge_invite_secret');
        const value = await panel.locator('#nr-reveal-value').inputValue();
        expect(value).toMatch(/^[0-9a-f]{64}$/); // nrGenerateSecretValue()'s shape
        // No GitHub Actions workflow reads CHALLENGE_INVITE_SECRET (see nrSecretConfig()) — only
        // the local-config-file follow-up line, not a GitHub-secret line too.
        await expect(panel.locator('li')).toHaveCount(1);

        // One-time reveal: read-and-unset in keys.php means a reload of the very same URL must
        // not show it a second time.
        await page.reload();
        await expect(page.locator('#nr-reveal-panel')).toHaveCount(0);
    });

    test('rotating a CI-paired secret (CRON_SECRET) names the env-aware GitHub Actions secret in the reveal panel', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=keys&${FIXTURE_QS}`);
        page.once('dialog', (d) => d.accept());
        const cronRow = page.locator('[data-item-key="cron_secret"]');
        await cronRow.locator('> form button[type="submit"]').click();
        await expect(page).toHaveURL(/nr_msg=secret_rotated/);

        const panel = page.locator('#nr-reveal-panel');
        await expect(panel).toHaveAttribute('data-item-key', 'cron_secret');
        // GitHub-secret line + local-config line — CRON_SECRET is CI-paired (see nrSecretConfig()).
        await expect(panel.locator('li')).toHaveCount(2);
        // This suite only ever runs against the test environment — the env-aware name must be
        // the _TEST-suffixed one, never the bare (live) CRON_SECRET.
        await expect(panel).toContainText('CRON_SECRET_TEST');
    });

    test('rotation requires confirmation — dismissing the dialog does not submit', async ({ page }) => {
        await page.goto(`/admin-dashboards.php?tab=keys&${FIXTURE_QS}`);
        page.once('dialog', (d) => d.dismiss());
        const inviteRow = page.locator('[data-item-key="challenge_invite_secret"]');
        await inviteRow.locator('> form button[type="submit"]').click();
        await expect(page).not.toHaveURL(/nr_msg=secret_rotated/);
    });

    test('rotation history is visible and append-only (no edit/delete control)', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=keys');
        const history = page.locator('.gha-panel').last();
        await expect(history.locator('button', { hasText: /slet|delete|rediger|edit/i })).toHaveCount(0);
    });

    // Non-admin/logged-out access rejection is covered in 20-dashboards-access.spec.js — see
    // that file's header comment for why it isn't tested here with browser.newContext().
});
