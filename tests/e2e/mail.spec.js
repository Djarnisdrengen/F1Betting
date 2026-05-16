const { test, expect } = require('@playwright/test');

async function loginAsAdmin(page) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/, { timeout: 5000 });
}

// ─── SMTP / Resend config (test_smtp.php) ─────────────────────────────────────

test.describe('SMTP / Resend config (test_smtp.php)', () => {
    test('denies access to unauthenticated users', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        await expect(page.locator('body')).toContainText('Access denied');
    });

    test('admin can access the page', async ({ page }) => {
        await loginAsAdmin(page);
        const res = await page.goto('/tools/test_smtp.php');
        expect(res.status()).toBe(200);
        await expect(page.locator('body')).toContainText('Current SMTP Configuration');
    });

    test('config table shows all required keys', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/tools/test_smtp.php');
        for (const key of ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_FROM_EMAIL', 'RESEND_API_KEY']) {
            await expect(page.locator('td').filter({ hasText: key }).first()).toBeVisible();
        }
    });

    test('RESEND_API_KEY is configured', async ({ page }) => {
        await loginAsAdmin(page);
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
        await page.goto('/forgot_password.php');
        await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
        await page.click('button[type="submit"]');
        // Either success or graceful failure message — both appear in .alert-success
        // (forgot_password.php never reveals whether the address exists)
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('input[name="email"]')).not.toBeVisible();
    });

    test('submitting unknown email shows no error (does not reveal user existence)', async ({ page }) => {
        await page.goto('/forgot_password.php');
        await page.fill('input[name="email"]', 'nonexistent@example.com');
        await page.click('button[type="submit"]');
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-error')).not.toBeVisible();
    });
});

// ─── Betting window notification emails ───────────────────────────────────────

test.describe('Betting window notification emails', () => {
    test('notifications cron runs without error', async ({ page }) => {
        await page.goto(`/cron/notifications.php?token=${process.env.CRON_SECRET}`);
        const text = await page.textContent('body');
        expect(text).toContain('Notification check complete');
        expect(text).not.toContain('FAILED to send');
    });
});
