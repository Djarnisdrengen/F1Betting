const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_AUTH = path.join(__dirname, '../../.auth/admin.json');

// ─── SMTP / Resend config (test_smtp.php) ─────────────────────────────────────

// Unauthenticated check runs first in its own isolated context.
test.describe('SMTP / Resend config — access control', { tag: '@admin' }, () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('denies access to unauthenticated users', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        await expect(page.locator('body')).toContainText('Access denied');
    });
});

test.describe('SMTP / Resend config (test_smtp.php)', { tag: '@admin' }, () => {
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

// Forgot-password tests live in 02-auth.spec.js.
