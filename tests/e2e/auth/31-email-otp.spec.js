'use strict';
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');
const mail = require('../../helpers/intercepted-mail');

// Reads the most recent intercepted email for `inbox` and extracts the 6-digit OTP
// (present in both the subject and the body). Uses the server-side mail interception
// (SMTP_INTERCEPT JSONL) — no external inbox / Mailsac.
async function readOtp(inbox, timeout = 20000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const msgs = await mail.getMessages(inbox);
        if (msgs.length) {
            const m = msgs[msgs.length - 1];
            const hay = `${m.subject || ''} ${m.text || ''} ${m.html || ''}`;
            const match = hay.match(/\b(\d{6})\b/);
            if (match) return match[1];
        }
        await new Promise(r => setTimeout(r, 1500));
    }
    throw new Error(`No OTP email arrived for ${inbox}`);
}

async function login(page, email, password) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
}

// Fills a 6-digit code into a method's OTP boxes (box 1 gets the whole string; client JS
// distributes it across the rest — see assets/js/mfa.js) and submits its verify form.
async function submitMfaCode(page, method, code) {
    const wrapper = page.locator(`[data-testid="mfa-form-${method}"]`);
    await wrapper.locator('[data-testid="mfa-otp-box"]').first().fill(code);
    await wrapper.locator('form').first().locator('button[type="submit"]').click();
}

test.describe('Email OTP multi-factor authentication', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: { cookies: [], origins: [] } });

    let user;

    test.beforeAll(async () => { user = await seed.authUser(); });
    test.afterAll(async () => { await seed.cleanup.authUser(); });

    test('cancel email-OTP setup returns to the setup button', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="emailotp-setup-btn"]');
        await expect(page.locator('[data-testid="emailotp-enroll"]')).toBeVisible();
        await page.click('[data-testid="emailotp-cancel-btn"]');
        await expect(page.locator('[data-testid="emailotp-setup-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="emailotp-enroll"]')).toHaveCount(0);
    });

    test('enable email OTP via emailed confirmation code', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);

        await mail.purgeInbox();
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="emailotp-setup-btn"]');
        await expect(page.locator('[data-testid="emailotp-enroll"]')).toBeVisible();

        const code = await readOtp(user.email);
        await page.fill('[data-testid="emailotp-confirm-input"]', code);
        await page.click('[data-testid="emailotp-confirm-btn"]');

        await expect(page.locator('[data-testid="emailotp-status"]')).toContainText(/Active|Aktiv/);
        await expect(page.locator('[data-testid="recovery-codes"]')).toBeVisible(); // first factor → codes shown once
    });

    test('login now emails a code and stops at the challenge, single factor skips the list (AC-MFA-05)', async ({ page }) => {
        await mail.purgeInbox();
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        // Email is the member's only factor: pre-sent by login.php, its detail view opens directly.
        await expect(page.locator('[data-testid="mfa-view-root"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();
        await readOtp(user.email);
    });

    test('BYPASS GUARD: protected page denied while awaiting the emailed code', async ({ page }) => {
        await mail.purgeInbox();
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/login\.php/); // no session granted by the password step alone
    });

    test('correct emailed code promotes the session', async ({ page }) => {
        await mail.purgeInbox();
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        const code = await readOtp(user.email);
        await submitMfaCode(page, 'email', code);

        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/profile\.php/);
    });

    test('wrong emailed code is rejected', async ({ page }) => {
        await mail.purgeInbox();
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await submitMfaCode(page, 'email', '000000');
        await expect(page.locator('.alert-error')).toBeVisible();
        await expect(page).toHaveURL(/mfa_challenge\.php/);
    });

    test('disable email OTP requires the password and returns to password-only', async ({ page }) => {
        // Log in fully.
        await mail.purgeInbox();
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        const code = await readOtp(user.email);
        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);

        await page.goto('/profile.php?tab=tab-security');
        await page.locator('form:has([value="emailotp_disable"]) input[name="current_password"]').fill(user.password);
        await page.click('[data-testid="emailotp-disable-btn"]');
        await expect(page.locator('[data-testid="emailotp-status"]')).toContainText(/Not enabled|Ikke aktiveret/);

        // Log out, then confirm password-only login now reaches index directly (no challenge).
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
    });
});
