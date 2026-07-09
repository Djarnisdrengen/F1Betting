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

async function submitMfaCode(page, method, code) {
    const wrapper = page.locator(`[data-testid="mfa-form-${method}"]`);
    await wrapper.locator('[data-testid="mfa-otp-box"]').first().fill(code);
    await wrapper.locator('form').first().locator('button[type="submit"]').click();
}

test.describe('MFA challenge (2+ factors): preferred method leads + on-demand email code', () => {
    test.describe.configure({ mode: 'serial', timeout: 25000 }); // email pre-send blocks on SMTP
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

    test('2+ factors: the preferred method leads (totp by default); no email until picked (AC-MFA-01/05)', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // No stored preference → the first active factor (totp) opens on top; email + recovery are
        // one tap away under "Other options". No neutral list, and nothing emailed from landing.
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await expect(page.locator('[data-testid="mfa-view-root"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-method-email"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-method-recovery"]')).toBeVisible();
        await expectNoEmail(user.email);
    });

    test('picking email shows boxes immediately; status flips sending → sent (AC-MFA-04)', async ({ page }) => {
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Pick email from the totp panel's Other options — the boxes appear immediately, and the
        // status reads "sending" (NOT "sent") while the code is still going out in the background.
        await page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-method-email"]').click();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-mfa-code-sending]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeHidden();

        // Once the code is actually sent, "sending" hides and "code sent" shows.
        const code = await readOtp(user.email);
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-mfa-code-sending]')).toBeHidden();
        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);
    });

    test('email as preferred: it opens on top with the code already pre-sent (no resend on browse)', async ({ page }) => {
        // Log in via TOTP (the default primary while no preference is set) to reach the profile.
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);

        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'email');
        await page.click('[data-testid="mfa-default-save-btn"]');

        // Fresh login: email is now preferred → its panel opens on top, and login.php has already
        // pre-sent the code, so the "code sent" state shows with no extra click.
        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-form-email"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();

        // Since a code already exists, the email rows carry NO data-mfa-autosend — browsing to email
        // from another panel is a pure view swap and never re-sends (guards the shared rate-limit
        // bucket, security-findings-remaining.md F7). Checked on the attribute directly (instant).
        const emailRowInTotp = page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-method-email"]');
        expect(await emailRowInTotp.getAttribute('data-mfa-autosend')).toBeNull();

        const code = await readOtp(user.email);
        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);
    });

    test('AC-MFA-09 — no horizontal scroll at 320px; the open panel keeps its tap targets', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 700 });
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        const card = page.locator('.hf-login-card');
        expect(await card.evaluate(el => el.scrollWidth > el.clientWidth)).toBe(false);

        // Whichever method is preferred opens directly — its boxes, confirm button, and an Other-
        // options row all keep 44px targets (locators are primary-agnostic so test order can't break them).
        const openPanel = page.locator('[data-mfa-view]:not([hidden])');
        const box = openPanel.locator('[data-testid="mfa-otp-box"]').first();
        await expect(box).toBeVisible();
        expect((await box.boundingBox()).height).toBeGreaterThanOrEqual(44);
        const confirmBtn = openPanel.locator('form button[type="submit"]').first();
        expect((await confirmBtn.boundingBox()).height).toBeGreaterThanOrEqual(44);
        const otherRow = openPanel.locator('[data-mfa-select]').first();
        await expect(otherRow).toBeVisible();
        expect((await otherRow.boundingBox()).height).toBeGreaterThanOrEqual(44);

        await page.setViewportSize({ width: 1280, height: 720 });
    });
});
