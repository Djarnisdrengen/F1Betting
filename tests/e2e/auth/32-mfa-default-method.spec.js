'use strict';
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const seed = require('../../helpers/seed');
const mail = require('../../helpers/intercepted-mail');

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

// Assert that NO email lands within the window (used to prove nothing is sent by default).
async function expectNoEmail(inbox, window = 4000) {
    const deadline = Date.now() + window;
    while (Date.now() < deadline) {
        const msgs = await mail.getMessages(inbox);
        expect(msgs.length, 'no email should be sent until the member asks for one').toBe(0);
        await new Promise(r => setTimeout(r, 800));
    }
}

async function login(page, email, password) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
}

test.describe('MFA default method + on-demand email code', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: { cookies: [], origins: [] } });

    let user, secret;

    test.beforeAll(async () => { user = await seed.authUser(); });
    test.afterAll(async () => { await seed.cleanup.authUser(); });

    test('enroll BOTH authenticator and email code', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);

        // Authenticator (first factor → recovery codes shown once).
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="totp-setup-btn"]');
        await expect(page.locator('[data-testid="totp-enroll"]')).toBeVisible();
        secret = (await page.locator('[data-testid="totp-secret"]').innerText()).replace(/\s/g, '');
        await page.fill('[data-testid="totp-confirm-input"]', totp(secret));
        await page.click('[data-testid="totp-confirm-btn"]');
        await expect(page.locator('[data-testid="totp-status"]')).toContainText(/Active|Aktiv/);

        // Email code.
        await mail.purgeInbox();
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="emailotp-setup-btn"]');
        const code = await readOtp(user.email);
        await page.fill('[data-testid="emailotp-confirm-input"]', code);
        await page.click('[data-testid="emailotp-confirm-btn"]');
        await expect(page.locator('[data-testid="emailotp-status"]')).toContainText(/Active|Aktiv/);

        // With two factors active the preferred-method selector appears.
        await expect(page.locator('[data-testid="mfa-default-method"]')).toBeVisible();
    });

    test('TOTP is the default: login does NOT email a code', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // The lead block is the authenticator, not email.
        await expect(page.locator('[data-testid="mfa-default"]')).toHaveAttribute('data-method', 'totp');
        // And nothing was emailed on arrival.
        await expectNoEmail(user.email);
    });

    test('email code is sent only when explicitly requested, then verifies', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Email lives in the collapsed "other options"; expand and request a code.
        await page.locator('[data-testid="mfa-other-options"] summary').click();
        await page.click('[data-testid="mfa-email-send"]');
        await page.waitForURL(/mfa_challenge\.php/);

        const code = await readOtp(user.email);
        // After sending, the email block now offers an input; fill and verify.
        await page.locator('[data-testid="mfa-other-options"] summary').click();
        await page.locator('[data-testid="mfa-form-email"] input[name="code"]').fill(code);
        await page.locator('[data-testid="mfa-form-email"] button[type="submit"]').click();
        await page.waitForURL(/index\.php/);
    });

    test('setting email as preferred pre-sends the code at login', async ({ page }) => {
        // Log in fully via TOTP, then switch the preference to email.
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await page.locator('[data-testid="mfa-default"] input[name="code"]').fill(totp(secret));
        await page.locator('[data-testid="mfa-default"] button[type="submit"]').click();
        await page.waitForURL(/index\.php/);

        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'email');
        await page.click('[data-testid="mfa-default-save-btn"]');

        // Fresh login now leads with email and the code is already waiting.
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-default"]')).toHaveAttribute('data-method', 'email');
        await readOtp(user.email);
    });
});
