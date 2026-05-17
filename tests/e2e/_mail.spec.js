const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_AUTH      = path.join(__dirname, '../../.auth/admin.json');
const SEED_TOKEN      = process.env.INTEGRATION_SEED_TOKEN;
const TEST_USER_EMAIL = process.env.TEST_USER_EMAIL;

// ─── Email preview ────────────────────────────────────────────────────────────
// Sends one real email of every implemented type to F1_ADMIN_EMAIL so the
// visual layout and content can be reviewed manually. Runs first so the pool
// size reflects the real next race before other tests modify DB state.
// Run selectively:
//   npx playwright test mail.spec.js --grep "email preview"

test.describe("email preview", () => {
    test("sends one of each email type to F1_ADMIN_EMAIL in Danish and English", async ({ page }) => {
        test.setTimeout(180000);
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${SEED_TOKEN}&action=send_email_preview`,
            { timeout: 150000 }
        );
        expect(res.status()).toBe(200);
        const body = JSON.parse(await page.textContent("body"));

        const detailLines = ["\n── Email preview results ──────────────────────────"];
        for (const [name, info] of Object.entries(body.emails ?? {})) {
            const status = info.sent ? "✓ SENT" : "✗ FAILED";
            detailLines.push(`\n${status}  ${name}`);
            detailLines.push(`   to:      ${info.to}`);
            detailLines.push(`   subject: ${info.subject}`);
            const skip = new Set(["sent", "to", "subject"]);
            for (const [k, v] of Object.entries(info)) {
                if (!skip.has(k)) detailLines.push(`   ${k.padEnd(12)}: ${v}`);
            }
        }
        detailLines.push("────────────────────────────────────────────────\n");
        console.log(detailLines.join("\n"));

        for (const [name, info] of Object.entries(body.emails ?? {})) {
            expect(info.sent, `Email "${name}" failed to send`).toBe(true);
        }
        expect(body.ok, JSON.stringify(body)).toBe(true);
    });
});

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
