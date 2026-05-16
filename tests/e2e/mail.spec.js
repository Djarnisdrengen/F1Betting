const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_AUTH   = path.join(__dirname, '../../.auth/admin.json');
const SEED_TOKEN   = process.env.INTEGRATION_SEED_TOKEN;
const TEST_USER_EMAIL = process.env.TEST_USER_EMAIL;

// ─── SMTP / Resend config (test_smtp.php) ─────────────────────────────────────

// Unauthenticated check runs first in its own isolated context.
test.describe('SMTP / Resend config — access control', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('denies access to unauthenticated users', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        await expect(page.locator('body')).toContainText('Access denied');
    });
});

test.describe('SMTP / Resend config (test_smtp.php)', () => {
    test.use({ storageState: ADMIN_AUTH });

    test('admin can access the page', async ({ page }) => {
        const res = await page.goto('/tools/test_smtp.php');
        expect(res.status()).toBe(200);
        await expect(page.locator('body')).toContainText('Current SMTP Configuration');
    });

    test('config table shows all required keys', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        for (const key of ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_FROM_EMAIL', 'RESEND_API_KEY']) {
            await expect(page.locator('td').filter({ hasText: key }).first()).toBeVisible();
        }
    });

    test('RESEND_API_KEY is configured', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        const resendRow = page.locator('tr').filter({ has: page.locator('td', { hasText: 'RESEND_API_KEY' }) });
        await expect(resendRow).toContainText('********');
        await expect(resendRow).not.toContainText('Not defined');
    });
});

// ─── Password reset email ──────────────────────────────────────────────────────

test.describe('Password reset email', () => {
    test('forgot_password page renders form', async ({ page }) => {
        await page.goto('/forgot_password.php');
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('submitting known email triggers email and hides form', async ({ page }) => {
        await page.goto(`/forgot_password.php?e2e_token=${SEED_TOKEN}`);
        await page.fill('input[name="email"]', TEST_USER_EMAIL);
        await page.click('button[type="submit"]');
        await expect(page.locator('.alert-success')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('input[name="email"]')).not.toBeVisible();

        const body = await page.textContent('body');
        expect(body).toContain(`[forgot-pwd-to] ${TEST_USER_EMAIL}`);
        expect(body).toContain('[forgot-pwd-link] ');
        expect(body).toContain('/reset_password.php?token=');
    });

    test('submitting unknown email shows no error (does not reveal user existence)', async ({ page }) => {
        await page.goto('/forgot_password.php');
        await page.fill('input[name="email"]', 'nonexistent@example.com');
        await page.click('button[type="submit"]');
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-error')).not.toBeVisible();
    });
});
