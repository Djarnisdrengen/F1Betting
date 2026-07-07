'use strict';
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const seed = require('../../helpers/seed');

// ── Minimal RFC 6238 TOTP generator (mirrors public/includes/mfa.php) ────────────
function base32Decode(s) {
    s = s.replace(/[^A-Za-z2-7]/g, '').toUpperCase();
    const alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = 0, val = 0;
    const out = [];
    for (const ch of s) {
        val = (val << 5) | alpha.indexOf(ch);
        bits += 5;
        if (bits >= 8) { out.push((val >> (bits - 8)) & 0xff); bits -= 8; }
    }
    return Buffer.from(out);
}
function totp(secret, t = Math.floor(Date.now() / 1000)) {
    const key = base32Decode(secret);
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(Math.floor(t / 30)));
    const h = crypto.createHmac('sha1', key).update(buf).digest();
    const off = h[h.length - 1] & 0x0f;
    const bin = ((h[off] & 0x7f) << 24) | ((h[off + 1] & 0xff) << 16) | ((h[off + 2] & 0xff) << 8) | (h[off + 3] & 0xff);
    return String(bin % 1000000).padStart(6, '0');
}

async function login(page, email, password) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
}

// Fills a 6-digit code into a method's OTP boxes (box 1 gets the whole string; client JS
// distributes it across the rest — see assets/js/mfa.js) and submits its verify form. The wrapper
// is only ever in the DOM for candidate/recovery methods, so `method` disambiguates which form.
async function submitMfaCode(page, method, code) {
    const wrapper = page.locator(`[data-testid="mfa-form-${method}"]`);
    await wrapper.locator('[data-testid="mfa-otp-box"]').first().fill(code);
    await wrapper.locator('form').first().locator('button[type="submit"]').click();
}

// Enroll TOTP through the profile UI and return the shared secret.
async function enrollTotp(page) {
    await page.goto('/profile.php?tab=tab-security');
    await page.click('[data-testid="totp-setup-btn"]');
    await expect(page.locator('[data-testid="totp-enroll"]')).toBeVisible();
    const secret = (await page.locator('[data-testid="totp-secret"]').innerText()).replace(/\s/g, '');
    await page.fill('[data-testid="totp-confirm-input"]', totp(secret));
    await page.click('[data-testid="totp-confirm-btn"]');
    await expect(page.locator('[data-testid="totp-status"]')).toContainText(/Active|Aktiv/);
    return secret;
}

test.describe('TOTP multi-factor authentication', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: { cookies: [], origins: [] } });

    let user, secret;

    test.beforeAll(async () => { user = await seed.authUser(); });
    test.afterAll(async () => { await seed.cleanup.authUser(); });

    test('password-only login reaches index (regression)', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
    });

    test('cancel authenticator setup returns to the setup button', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="totp-setup-btn"]');
        await expect(page.locator('[data-testid="totp-enroll"]')).toBeVisible();
        await page.click('[data-testid="totp-cancel-btn"]');
        await expect(page.locator('[data-testid="totp-setup-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="totp-enroll"]')).toHaveCount(0); // pending enrollment dropped
    });

    test('enroll authenticator app and see recovery codes once', async ({ page }) => {
        test.setTimeout(20000); // includes a deliberate 6s wait to prove the panel does not auto-hide
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        secret = await enrollTotp(page);
        const panel = page.locator('[data-testid="recovery-codes"]');
        await expect(panel).toBeVisible();
        await expect(page.locator('[data-testid="recovery-copy-btn"]')).toBeVisible();
        // Regression: the codes must NOT auto-hide like an .alert (they used to vanish after ~5s).
        await page.waitForTimeout(6000);
        await expect(panel).toBeVisible();
        // Dismiss removes the panel; and codes are shown once, so a reload does not bring them back.
        await page.click('[data-testid="recovery-dismiss-btn"]');
        await expect(panel).toHaveCount(0);
        await page.reload();
        await expect(page.locator('[data-testid="recovery-codes"]')).toHaveCount(0);
    });

    test('password step now stops at the challenge, single factor skips the list (AC-MFA-05)', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        // Authenticator is the member's only factor: its detail view opens directly, no method list.
        await expect(page.locator('[data-testid="mfa-view-root"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
    });

    test('BYPASS GUARD: protected page is denied while pending', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        // Jump straight to a protected page without completing the second factor.
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/login\.php/); // requireLogin bounced us — no session was granted
    });

    test('wrong code is rejected at the challenge, inline, single-method layout kept (AC-MFA-07)', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await submitMfaCode(page, 'totp', '000000');
        await expect(page.locator('.alert-error')).toBeVisible();
        await expect(page).toHaveURL(/mfa_challenge\.php/);
        // Still the single totp view — not bounced to a list or another method.
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
    });

    test('correct TOTP code promotes the session', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);
        // Now genuinely logged in: a protected page renders.
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/profile\.php/);
    });

    test('disable TOTP requires the password and returns to password-only', async ({ page }) => {
        // Log in fully first.
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);

        await page.goto('/profile.php?tab=tab-security');
        await page.locator('form:has([value="totp_disable"]) input[name="current_password"]').fill(user.password);
        await page.click('[data-testid="totp-disable-btn"]');
        await expect(page.locator('[data-testid="totp-status"]')).toContainText(/Not enabled|Ikke aktiveret/);
    });
});
