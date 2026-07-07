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

// Assert the inbox count does not grow past `baseline` within the window (used to prove
// re-selecting an already-sent method does not re-send / double-burn the rate limit).
async function expectNoNewEmail(inbox, baseline, window = 4000) {
    const deadline = Date.now() + window;
    while (Date.now() < deadline) {
        const msgs = await mail.getMessages(inbox);
        expect(msgs.length, 'no additional email should be sent for an already-sent code').toBe(baseline);
        await new Promise(r => setTimeout(r, 800));
    }
}

async function login(page, email, password) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
}

async function submitMfaCode(page, method, code) {
    const wrapper = page.locator(`[data-testid="mfa-form-${method}"]`);
    await wrapper.locator('[data-testid="mfa-otp-box"]').first().fill(code);
    await wrapper.locator('form').first().locator('button[type="submit"]').click();
}

test.describe('MFA method list (2+ factors) + on-demand email code', () => {
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

    test('2+ factors: challenge always shows the method list, no email until picked (AC-MFA-01/05)', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // The list shows both methods (+ recovery) — no single-method primary block, no
        // stored-preference skip once 2+ non-passkey factors are enabled.
        await expect(page.locator('[data-testid="mfa-view-root"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-method-totp"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-method-email"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-method-recovery"]')).toBeVisible();
        // And nothing was emailed just from landing on the page.
        await expectNoEmail(user.email);
    });

    test('email code is sent only when explicitly requested, then verifies (AC-MFA-04)', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Tapping the row auto-sends — no separate send button, no expand step.
        await page.click('[data-testid="mfa-method-email"]');
        const code = await readOtp(user.email);
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();

        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);
    });

    test('setting email as preferred pre-sends the code at login; re-selecting it does not resend', async ({ page }) => {
        // Log in fully via TOTP — 2 factors still means the list shows first.
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await page.click('[data-testid="mfa-method-totp"]');
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);

        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'email');
        await page.click('[data-testid="mfa-default-save-btn"]');

        // Fresh login: the list still shows first (decision: stored preference no longer skips
        // it), but the email code is already waiting — pre-sent by login.php's default-method
        // optimization, which this redesign keeps.
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-view-root"]')).toBeVisible();
        const code = await readOtp(user.email);
        const baseline = (await mail.getMessages(user.email)).length;

        // Selecting the already-sent method must NOT re-send (guards the shared rate-limit
        // bucket — see security-findings-remaining.md F7 — from being burned by browsing alone).
        await page.click('[data-testid="mfa-method-email"]');
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();
        await expectNoNewEmail(user.email, baseline);

        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);
    });

    test('AC-MFA-09 — no horizontal scroll at 320px; list and OTP boxes keep tap targets', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 700 });
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        const card = page.locator('.hf-login-card');
        const hasHScroll = await card.evaluate(el => el.scrollWidth > el.clientWidth);
        expect(hasHScroll).toBe(false);

        const totpRow = page.locator('[data-testid="mfa-method-totp"]');
        await expect(totpRow).toBeVisible();
        expect((await totpRow.boundingBox()).height).toBeGreaterThanOrEqual(44);

        await totpRow.click();
        const box = page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first();
        await expect(box).toBeVisible();
        expect((await box.boundingBox()).height).toBeGreaterThanOrEqual(44);
        const confirmBtn = page.locator('[data-testid="mfa-form-totp"] button[type="submit"]');
        expect((await confirmBtn.boundingBox()).height).toBeGreaterThanOrEqual(44);

        await page.setViewportSize({ width: 1280, height: 720 });
    });
});
