'use strict';
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const path = require('path');
const seed = require('../../helpers/seed');

// Passkey happy paths via Chromium's CDP virtual authenticator (ctap2/internal,
// resident key + UV, automatic presence). Real credentials cannot be seeded
// server-side — the private key lives in the authenticator — so every test
// enrolls through the profile UI on its own page, and the auth user is re-seeded
// per test (seed_auth_user recreates the row; FKs cascade all factors away).
// NOT part of smoke (decided 🟡-3 A).

// ── TOTP generator (same as 30-totp-mfa.spec.js) — used for method-ordering cases ──
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

// Attach a virtual authenticator to the page BEFORE any navigator.credentials
// call. Its credentials live and die with this page — which is why each test
// enrolls its own.
async function addVirtualAuthenticator(page) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });
}

// Register a passkey from the Security tab. passkey.js reloads the page on
// success, after which the row (and, for a first factor, the recovery-codes
// flash) render server-side.
async function registerPasskey(page) {
    await page.goto('/profile.php?tab=tab-security');
    await page.click('[data-testid="passkey-add"]');
    await expect(page.locator('[data-testid="passkey-row"]').first()).toBeVisible();
}

async function dismissRecoveryCodes(page) {
    const panel = page.locator('[data-testid="recovery-codes"]');
    if (await panel.count()) {
        await page.click('[data-testid="recovery-dismiss-btn"]');
        await expect(panel).toHaveCount(0);
    }
}

// Fills a 6-digit code into a method's OTP boxes and submits its verify form (see
// 30-totp-mfa.spec.js for the box-distribution mechanics this relies on).
async function submitMfaCode(page, method, code) {
    const wrapper = page.locator(`[data-testid="mfa-form-${method}"]`);
    await wrapper.locator('[data-testid="mfa-otp-box"]').first().fill(code);
    await wrapper.locator('form').first().locator('button[type="submit"]').click();
}

test.describe('Passkey (WebAuthn) authentication', () => {
    test.describe.configure({ mode: 'serial' }); // shared seeded account — never parallel
    test.use({ storageState: { cookies: [], origins: [] } });

    let user;

    // Fresh user per test: no factors, no recovery codes, no passkey rows.
    test.beforeEach(async () => { user = await seed.authUser(); });
    test.afterAll(async () => { await seed.cleanup.authUser(); });

    test('password-only login reaches index (regression)', async ({ page }) => {
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
    });

    test('register a passkey; first factor shows recovery codes once (REG-01/REG-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);

        await registerPasskey(page);
        await expect(page.locator('[data-testid="passkey-status"]')).toContainText(/Active|Aktiv/);

        // First enrolled factor → recovery codes must be VISIBLE on screen (decided 🟡-1).
        const panel = page.locator('[data-testid="recovery-codes"]');
        await expect(panel).toBeVisible();
        await page.click('[data-testid="recovery-dismiss-btn"]');
        await expect(panel).toHaveCount(0);
        await page.reload();
        await expect(page.locator('[data-testid="recovery-codes"]')).toHaveCount(0); // shown once
    });

    test('password login is gated; passkey is not offered on the challenge — recovery is the fallback (CHA-01/CHA-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Passkey is retired from the challenge screen (v3.0.0). A passkey-only member who used
        // the password form (not the login screen's passwordless button) has no other real
        // factor, so the screen resolves straight to the recovery fallback with a hint pointing
        // back to login.
        await expect(page.locator('[data-testid="mfa-view-root"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-passkey-btn"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-form-recovery"]')).toBeVisible();
        const hint = page.locator('[data-testid="mfa-footer-link"]');
        await expect(hint).toBeVisible();
        await expect(hint).toHaveAttribute('href', '/login.php');

        // Following that hint back to login and using the passkey button there still works.
        await hint.click();
        await page.waitForURL(/login\.php/);
        await page.click('[data-testid="passkey-login"]');
        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/profile\.php/); // genuinely promoted
    });

    test('passwordless login from the login page (PWL-01)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);
        await page.goto('/logout.php');

        // The button ships hidden and is revealed by feature detection.
        await page.goto('/login.php');
        const btn = page.locator('[data-testid="passkey-login"]');
        await expect(btn).toBeVisible();

        // No email, no password — discoverable credential straight to a session.
        await btn.click();
        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/profile\.php/); // genuinely promoted
    });

    test('rename a passkey (management)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        const nameInput = page.locator('[data-testid="passkey-row"] input[name="friendly_name"]').first();
        await nameInput.fill('Min testnøgle');
        await page.locator('[data-testid="passkey-rename-btn"]').first().click();
        await expect(page.locator('[data-testid="passkey-name"]').first()).toHaveText('Min testnøgle');
    });

    test('sign-count regression is rejected on the passwordless login path (SEC-01)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);
        await page.goto('/logout.php');

        // Force the stored counter above anything the authenticator reports next. The check
        // lives in the shared passkeyAssertVerify() (includes/passkey.php), used by both the
        // challenge and the passwordless login_verify action — relocated here since passkey no
        // longer appears on the challenge screen (v3.0.0; see CHA-01/CHA-02 above).
        await seed.setPasskeySignCount(user.email, 999999);

        await page.goto('/login.php');
        await page.click('[data-testid="passkey-login"]');
        await expect(page.locator('[data-testid="passkey-login-error"]')).toBeVisible();
        await expect(page).toHaveURL(/login\.php/); // no promotion
    });

    test('passkey never appears on the challenge; TOTP opens directly regardless of preference (CHA-04/CHA-05)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        // Enroll TOTP as a second method.
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="totp-setup-btn"]');
        await expect(page.locator('[data-testid="totp-enroll"]')).toBeVisible();
        const secret = (await page.locator('[data-testid="totp-secret"]').innerText()).replace(/\s/g, '');
        await page.fill('[data-testid="totp-confirm-input"]', totp(secret));
        await page.click('[data-testid="totp-confirm-btn"]');
        await expect(page.locator('[data-testid="totp-status"]')).toContainText(/Active|Aktiv/);

        // Passkey + TOTP active, no preference set: passkey is filtered out entirely, leaving
        // TOTP as the only real candidate — its detail view opens directly, no list, no passkey
        // button anywhere on the screen.
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-view-root"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-passkey-btn"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);

        // Explicitly preferring TOTP is a no-op for the challenge screen here (it was already
        // the only real candidate) — the preference selector should still accept it without error.
        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'totp');
        await page.click('[data-testid="mfa-default-save-btn"]');

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-passkey-btn"]')).toHaveCount(0);
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);
    });

    test('admin strips two-step factors; member returns to password-only (support path)', async ({ page, browser }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);
        await page.goto('/logout.php');

        // Admin (session from global-setup) removes the member's factors.
        const adminCtx = await browser.newContext({
            storageState: path.join(__dirname, '../../../.auth/admin.json'),
            baseURL: process.env.BASE_URL,
        });
        const adminPage = await adminCtx.newPage();
        await adminPage.goto('/admin.php?tab=users');
        const card = adminPage.locator('.card', { hasText: user.email });
        await card.locator('[data-testid="admin-remove-mfa"]').click();
        await adminPage.locator('#delete-modal .btn-user-delete-confirm').click();
        await adminPage.waitForURL(/msg=/);
        await expect(adminPage.locator('.alert-success')).toBeVisible();
        // Button gone: the member no longer has any factor.
        await expect(adminPage.locator('.card', { hasText: user.email })
            .locator('[data-testid="admin-remove-mfa"]')).toHaveCount(0);
        await adminCtx.close();

        // Password login goes straight in — no challenge.
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await page.goto('/profile.php');
        await expect(page).toHaveURL(/profile\.php/);
    });

    test('revoke requires the password; removing it restores password-only (REV-01/REV-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        // Wrong password → row must survive.
        await page.locator('form:has([value="passkey_delete"])').first()
            .locator('input[name="current_password"]').fill('not-the-password');
        await page.locator('[data-testid="passkey-delete-btn"]').first().click();
        await expect(page.locator('.alert-error')).toBeVisible();
        await expect(page.locator('[data-testid="passkey-row"]')).toHaveCount(1);

        // Correct password → removed; account returns to password-only login.
        await page.locator('form:has([value="passkey_delete"])').first()
            .locator('input[name="current_password"]').fill(user.password);
        await page.locator('[data-testid="passkey-delete-btn"]').first().click();
        await expect(page.locator('[data-testid="passkey-row"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="passkey-status"]')).toContainText(/Not enabled|Ikke aktiveret/);

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/); // no challenge — password-only restored
    });
});
