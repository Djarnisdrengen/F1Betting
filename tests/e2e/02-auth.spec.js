'use strict';
const { test, expect } = require('@playwright/test');
const seed = require('../helpers/seed');
const { parseMarkers, expectMarker } = require('../helpers/markers');
const { assertDelivered } = require('../helpers/email');

const AUTH_INBOX = 'e2e_auth_f1@mailsac.com';

async function loginAs(page, email, password) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/);
}

test.describe('Auth flows', () => {
    test.describe.configure({ mode: 'serial' });

    let seedData;

    test.beforeAll(async () => {
        seedData = await seed.authUser();
    });

    test.afterAll(async () => {
        await seed.cleanup.authUser();
    });

    // ── Login ─────────────────────────────────────────────────────────────────

    test.describe('Login', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('wrong password shows error', async ({ page }) => {
            await page.goto('/login.php');
            await page.fill('input[name="email"]', seedData.email);
            await page.fill('input[name="password"]', 'wrongpassword');
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-error')).toBeVisible();
        });

        test('correct credentials redirect to index', async ({ page }) => {
            await page.goto('/login.php');
            await page.fill('input[name="email"]', seedData.email);
            await page.fill('input[name="password"]', seedData.password);
            await page.click('button[type="submit"]');
            await page.waitForURL(/index\.php/);
        });
    });

    // ── Forgot password ───────────────────────────────────────────────────────

    test.describe('Forgot password', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('form renders', async ({ page }) => {
            await page.goto('/forgot_password.php');
            await expect(page.locator('input[name="email"]')).toBeVisible();
            await expect(page.locator('button[type="submit"]')).toBeVisible();
        });

        test('unknown email shows success without error (no user enumeration)', async ({ page }) => {
            await page.goto('/forgot_password.php');
            await page.fill('input[name="email"]', 'nonexistent@example.com');
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-success')).toBeVisible();
            await expect(page.locator('.alert-error')).not.toBeVisible();
        });

        test('known email emits markers and hides form', async ({ page }) => {
            await page.goto(`/forgot_password.php?e2e_token=${process.env.INTEGRATION_SEED_TOKEN}`);
            await page.fill('input[name="email"]', seedData.email);
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-success')).toBeVisible();
            await expect(page.locator('input[name="email"]')).not.toBeVisible();

            const body = await page.textContent('body');
            expectMarker(body, 'forgot-pwd-to', seedData.email);
            expect(parseMarkers(body)['forgot-pwd-link']).toContain('/reset_password.php?token=');
        });

        test('real reset email delivered to Mailsac', async ({ page }) => {
            test.setTimeout(90000);
            await page.goto('/forgot_password.php');
            await page.fill('input[name="email"]', seedData.email);
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-success')).toBeVisible();
            await assertDelivered(AUTH_INBOX, process.env.MAILSAC_API_KEY);
        });
    });

    // ── Password change via profile form ──────────────────────────────────────

    test.describe('Password change via profile', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('wrong current password shows error', async ({ page }) => {
            await loginAs(page, seedData.email, seedData.password);
            await page.goto('/profile.php');
            await page.click('[data-testid="tab-security-btn"]');
            await page.fill('input[name="current_password"]', 'wrongpassword');
            await page.fill('input[name="new_password"]', 'NewPassword2026!');
            await page.fill('input[name="confirm_password"]', 'NewPassword2026!');
            await page.locator('form:has(input[value="change_password"]) button[type="submit"]').click();
            await expect(page.locator('.alert-error')).toBeVisible();
        });

        test('mismatched confirm password shows error', async ({ page }) => {
            await loginAs(page, seedData.email, seedData.password);
            await page.goto('/profile.php');
            await page.click('[data-testid="tab-security-btn"]');
            await page.fill('input[name="current_password"]', seedData.password);
            await page.fill('input[name="new_password"]', 'NewPassword2026!');
            await page.fill('input[name="confirm_password"]', 'DifferentPassword2026!');
            await page.locator('form:has(input[value="change_password"]) button[type="submit"]').click();
            await expect(page.locator('.alert-error')).toBeVisible();
        });

        test('correct credentials change password successfully', async ({ page }) => {
            await loginAs(page, seedData.email, seedData.password);
            await page.goto('/profile.php');
            await page.click('[data-testid="tab-security-btn"]');
            await page.fill('input[name="current_password"]', seedData.password);
            await page.fill('input[name="new_password"]', 'UpdatedPassword2026!');
            await page.fill('input[name="confirm_password"]', 'UpdatedPassword2026!');
            await page.locator('form:has(input[value="change_password"]) button[type="submit"]').click();
            await expect(page.locator('.alert-success')).toBeVisible();
        });
    });

    // ── Password reset via token link ─────────────────────────────────────────
    // Runs last: the reset changes the user's password; cleanup deletes the user anyway.

    test.describe('Password reset via token link', () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        test('reset link from forgot-password allows new password and login succeeds', async ({ page }) => {
            // Request a reset link via e2e_token (markers emitted, no email sent)
            await page.goto(`/forgot_password.php?e2e_token=${process.env.INTEGRATION_SEED_TOKEN}`);
            await page.fill('input[name="email"]', seedData.email);
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-success')).toBeVisible();

            const body = await page.textContent('body');
            const resetLink = parseMarkers(body)['forgot-pwd-link'];
            expect(resetLink).toContain('/reset_password.php?token=');

            // Navigate to the full URL from the marker (full URL since SITE_URL is embedded)
            await page.goto(resetLink);
            await expect(page.locator('input[name="password"]')).toBeVisible();
            await page.fill('input[name="password"]', 'ResetPassword2026!');
            await page.fill('input[name="confirm_password"]', 'ResetPassword2026!');
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-success')).toBeVisible();

            // Verify the new password works
            await page.goto('/login.php');
            await page.fill('input[name="email"]', seedData.email);
            await page.fill('input[name="password"]', 'ResetPassword2026!');
            await page.click('button[type="submit"]');
            await page.waitForURL(/index\.php/);
        });
    });
});
