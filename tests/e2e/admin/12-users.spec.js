'use strict';
const { test, expect } = require('../../fixtures');
const seed = require('../../helpers/seed');
const { assertDelivered } = require('../../helpers/email');
const { expectMarker } = require('../../helpers/markers');

const SEED_TOKEN           = process.env.INTEGRATION_SEED_TOKEN;
const MAILSAC_API_KEY      = process.env.MAILSAC_API_KEY;
const E2E_USER_EMAIL       = 'e2e_testing_testuser_f1@mailsac.com';
const E2E_USER_INITIAL_PW  = 'E2ETestPassword2026!';
const E2E_USER_NEW_PW      = 'E2ENewPassword456!';
const E2E_BET_DELETE_EMAIL = 'e2e_bet_delete_f1@mailsac.com';

async function confirmDeleteModal(page) {
    await page.locator('.btn-user-delete-confirm').click();
}

function userCard(page) {
    return page
        .locator('.card')
        .filter({ has: page.locator('small', { hasText: E2E_USER_EMAIL }) });
}

// ─── Reset race result ─────────────────────────────────────────────────────────

test.describe.serial('Reset race result', () => {
    let seedData;

    test.beforeAll(async () => {
        await seed.cleanup.resetResult();
        seedData = await seed.resetResult();
        expect(seedData.points).toBeGreaterThan(0);
    });

    test.afterAll(async () => {
        await seed.cleanup.resetResult();
    });

    test('reset button visible on last completed race', async ({ page }) => {
        await page.goto('/admin.php?tab=races');

        const raceCard = page
            .locator('.hf-racefull')
            .filter({ has: page.locator('.hf-racename', { hasText: 'E2E Reset Race' }) });
        await expect(raceCard.locator('button[name="reset_race_result"]')).toBeVisible();
    });

    test('reset clears results and removes points from users', async ({ page }) => {
        await page.goto('/admin.php?tab=races');

        const raceCard = page
            .locator('.hf-racefull')
            .filter({ has: page.locator('.hf-racename', { hasText: 'E2E Reset Race' }) });

        await raceCard.locator('button[name="reset_race_result"]').click();
        await page.locator('.btn-user-delete-confirm').click();
        await page.waitForURL(/msg=/);

        const raceCardAfter = page
            .locator('.hf-racefull')
            .filter({ has: page.locator('.hf-racename', { hasText: 'E2E Reset Race' }) });
        await expect(raceCardAfter.locator('[data-testid="admin-race-result"]')).toHaveCount(0);
        await expect(raceCardAfter.locator('button[name="reset_race_result"]')).toHaveCount(0);

        await page.goto('/admin.php?tab=users');
        const card = page
            .locator('.card')
            .filter({ has: page.locator('small', { hasText: 'e2e_reset_race_f1@mailsac.com' }) });
        await expect(card.locator('.text-accent')).toContainText('0 pts');
    });
});

// ─── Bet deleted notification ──────────────────────────────────────────────────

test.describe.serial('Bet deleted notification', () => {
    let seedData;

    test.beforeAll(async () => {
        await seed.cleanup.betDeleted();
        seedData = await seed.betDeleted();
    });

    test.afterAll(async () => {
        await seed.cleanup.betDeleted();
    });

    test('admin deletes bet and notification email markers are emitted', async ({ page }) => {
        test.setTimeout(60000);
        await page.goto(`/admin.php?tab=bets&e2e_token=${SEED_TOKEN}`);

        const raceCard = page.locator('.card').filter({ hasText: 'E2E Bet Delete Race' });
        await expect(raceCard).toBeVisible();
        await raceCard.locator('button[name="delete_bet"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/tab=bets/, { timeout: 50000 });

        const body = await page.textContent('body');
        expectMarker(body, 'bet-deleted-to', seedData.email);
        expect(body).toContain(`[bet-deleted-race] ${seedData.raceName}`);
        expectMarker(body, 'bet-deleted-lang', 'en');
        expectMarker(body, 'bet-deleted-sent', 'true');

        await assertDelivered(E2E_BET_DELETE_EMAIL, MAILSAC_API_KEY);
    });
});

// ─── User management ──────────────────────────────────────────────────────────

test.describe('User management', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(async () => {
        await seed.e2eUser({ language: 'en' });
    });

    test('Toggle in competition on test user', async ({ page }) => {
        await page.goto('/admin.php?tab=users');

        const btn = userCard(page).locator('button[name="toggle_competition"]');
        await expect(btn).toContainText(/Not In Competition|Ikke I Konkurrence/);

        await btn.click();
        await page.waitForURL(/tab=users/);

        await expect(
            userCard(page).locator('button[name="toggle_competition"]')
        ).not.toContainText(/Not In Competition|Ikke I Konkurrence/);
    });

    test('Toggle admin role on test user', async ({ page }) => {
        await page.goto('/admin.php?tab=users');

        await expect(userCard(page).locator('span.badge')).toContainText('user');

        await userCard(page).locator('button[name="toggle_role"]').click();
        await page.waitForURL(/tab=users/);
        await expect(userCard(page).locator('span.badge')).toContainText('admin');

        await userCard(page).locator('button[name="toggle_role"]').click();
        await page.waitForURL(/tab=users/);
        await expect(userCard(page).locator('span.badge')).toContainText('user');
    });

    test('Set password on test user', async ({ page }) => {
        test.setTimeout(60000);
        await page.goto(`/admin.php?tab=users&e2e_token=${SEED_TOKEN}`);

        await userCard(page).locator('.btn-reset-pwd').click();
        const pwInput = userCard(page).locator('input[name="new_password"]');
        await pwInput.waitFor({ state: 'visible' });
        await pwInput.fill(E2E_USER_NEW_PW);
        await userCard(page).locator('button[name="reset_user_password"]').click();

        // Password reset sends a real email then redirects — SMTP may take up to 30s.
        await page.waitForURL(/tab=users/, { timeout: 50000 });
        await expect(page.locator('.alert-success')).toBeVisible({ timeout: 5000 });

        const body = await page.textContent('body');
        expectMarker(body, 'admin-reset-to', E2E_USER_EMAIL);
        expectMarker(body, 'admin-reset-new-password', E2E_USER_NEW_PW);
        expectMarker(body, 'admin-reset-lang', 'en');
        expectMarker(body, 'admin-reset-sent', 'true');

        await assertDelivered(E2E_USER_EMAIL, MAILSAC_API_KEY);
    });

    // Needs a fresh context: login.php redirects already-authenticated users.
    test.describe('Update display name on test user profile', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('logs in as E2E user and updates display name', async ({ page }) => {
            await page.goto('/login.php');
            await page.fill('input[name="email"]',    E2E_USER_EMAIL);
            await page.fill('input[name="password"]', E2E_USER_NEW_PW);
            await page.click('button[type="submit"]');
            await page.waitForURL(/index\.php/, { timeout: 5000 });

            await page.goto('/profile.php');
            await page.fill('input[name="display_name"]', 'E2E Updated Name');
            await page.click('button[type="submit"]');

            await expect(page.locator('.alert-success')).toBeVisible();
            await expect(page.locator('input[name="display_name"]')).toHaveValue('E2E Updated Name');
        });
    });

    test('Delete test user', async ({ page }) => {
        await page.goto('/admin.php?tab=users');

        await userCard(page).locator('button.btn-delete').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator('small', { hasText: E2E_USER_EMAIL })
        ).toHaveCount(0);
    });
});
