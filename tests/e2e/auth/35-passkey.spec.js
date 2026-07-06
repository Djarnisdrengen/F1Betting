'use strict';
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
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

    test('password login is gated; passkey leads and promotes the session (CHA-01/CHA-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-default"]')).toHaveAttribute('data-method', 'passkey');

        await page.click('[data-testid="mfa-passkey-btn"]');
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

    test('sign-count regression is rejected (SEC-01)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);
        await page.goto('/logout.php');

        // Force the stored counter above anything the authenticator reports next.
        await seed.setPasskeySignCount(user.email, 999999);

        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await page.click('[data-testid="mfa-passkey-btn"]');
        await expect(page.locator('[data-testid="mfa-passkey-error"]')).toBeVisible();
        await expect(page).toHaveURL(/mfa_challenge\.php/); // no promotion
    });

    test('default ordering and override: passkey first, then TOTP after override (CHA-04/CHA-05)', async ({ page }) => {
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

        // No explicit preference → passkey leads by fallback order.
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-default"]')).toHaveAttribute('data-method', 'passkey');
        await page.click('[data-testid="mfa-passkey-btn"]');
        await page.waitForURL(/index\.php/);

        // Override the preference to TOTP.
        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'totp');
        await page.click('[data-testid="mfa-default-save-btn"]');

        // TOTP now leads; passkey sits under "Other options" and still completes login.
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-default"]')).toHaveAttribute('data-method', 'totp');
        await page.locator('[data-testid="mfa-other-options"] summary').click();
        await page.click('[data-testid="mfa-passkey-btn"]');
        await page.waitForURL(/index\.php/);
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
