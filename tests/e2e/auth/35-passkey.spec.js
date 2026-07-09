'use strict';
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const path = require('path');
const seed = require('../../helpers/seed');
const mail = require('../../helpers/intercepted-mail');

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

// Read the latest 6-digit code from an intercepted inbox (mirrors 32-mfa-default-method.spec.js).
async function readOtp(inbox, timeout = 20000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const msgs = await mail.getMessages(inbox);
        if (msgs.length) {
            const m = msgs[msgs.length - 1];
            const match = `${m.subject || ''} ${m.text || ''} ${m.html || ''}`.match(/\b(\d{6})\b/);
            if (match) return match[1];
        }
        await new Promise(r => setTimeout(r, 1500));
    }
    throw new Error(`No OTP email arrived for ${inbox}`);
}

// Assert NO email lands within the window — proves login pre-send is suppressed by a passkey.
async function expectNoEmail(inbox, window = 4000) {
    const deadline = Date.now() + window;
    while (Date.now() < deadline) {
        expect((await mail.getMessages(inbox)).length, 'no email should be pre-sent when a passkey is active').toBe(0);
        await new Promise(r => setTimeout(r, 800));
    }
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
    test.describe.configure({ mode: 'serial', timeout: 25000 }); // shared account; email/SMTP round-trips need headroom
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

    test('passkey is the primary factor on the challenge; tapping it promotes the session (CHA-01/CHA-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Passkey is offered on the challenge again, as the PRIMARY factor (reverses v3.0.0). Its
        // panel opens first and the ceremony button is revealed by feature detection.
        await expect(page.locator('[data-testid="mfa-view-passkey"]')).toBeVisible();
        const btn = page.locator('[data-testid="mfa-passkey-btn"]');
        await expect(btn).toBeVisible();
        // A passkey-only member has no code fallback — only recovery sits beneath the button.
        await expect(page.locator('[data-testid="mfa-view-passkey"] [data-testid="mfa-method-recovery"]')).toBeVisible();

        // Tapping the passkey completes the second factor and promotes the session.
        await btn.click();
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
        // challenge and the passwordless login_verify action. This case exercises the
        // passwordless path; SEC-02 covers the same guard on the challenge path.
        await seed.setPasskeySignCount(user.email, 999999);

        await page.goto('/login.php');
        await page.click('[data-testid="passkey-login"]');
        await expect(page.locator('[data-testid="passkey-login-error"]')).toBeVisible();
        await expect(page).toHaveURL(/login\.php/); // no promotion
    });

    test('the preferred method leads on the challenge; the other is one tap away (CHA-04/CHA-05)', async ({ page }) => {
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

        // Prefer TOTP → TOTP leads on the challenge; the passkey drops to an Other option (an
        // explicit preference outranks the passkey default).
        await page.selectOption('[data-testid="mfa-default-select"]', 'totp');
        await page.click('[data-testid="mfa-default-save-btn"]');

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-totp"] [data-testid="mfa-method-passkey"]')).toBeVisible();
        await submitMfaCode(page, 'totp', totp(secret)); // verify with the preferred method
        await page.waitForURL(/index\.php/);

        // Switch the preference to passkey → the passkey panel now leads instead.
        await page.goto('/profile.php?tab=tab-security');
        await page.selectOption('[data-testid="mfa-default-select"]', 'passkey');
        await page.click('[data-testid="mfa-default-save-btn"]');

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await expect(page.locator('[data-testid="mfa-view-passkey"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-passkey-btn"]')).toBeVisible();
        await page.click('[data-testid="mfa-passkey-btn"]');
        await page.waitForURL(/index\.php/);
    });

    test('sign-count regression is rejected on the challenge path too (SEC-02)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);
        await page.goto('/logout.php');

        // Same shared guard as SEC-01, exercised through the challenge's passkey button.
        await seed.setPasskeySignCount(user.email, 999999);

        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);
        await page.click('[data-testid="mfa-passkey-btn"]');
        await expect(page.locator('[data-testid="mfa-passkey-error"]')).toBeVisible();
        await expect(page).toHaveURL(/mfa_challenge\.php/); // no promotion
    });

    test('default: passkey leads with no email pre-sent; the email fallback shows boxes instantly (CHA-06)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        // Add email OTP but leave the preference unset — passkey stays the default primary.
        await mail.purgeInbox();
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="emailotp-setup-btn"]');
        const enrollCode = await readOtp(user.email);
        await page.fill('[data-testid="emailotp-confirm-input"]', enrollCode);
        await page.click('[data-testid="emailotp-confirm-btn"]');
        await expect(page.locator('[data-testid="emailotp-status"]')).toContainText(/Active|Aktiv/);

        await mail.purgeInbox();
        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // Passkey leads (default preference) and nothing is pre-sent (email isn't the top panel).
        await expect(page.locator('[data-testid="mfa-view-passkey"]')).toBeVisible();
        await expectNoEmail(user.email);

        // Pick email from the passkey panel's Other options → boxes appear at once; status shows
        // "sending", then flips to "code sent" once the code actually goes out.
        await page.locator('[data-testid="mfa-view-passkey"] [data-testid="mfa-method-email"]').click();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-testid="mfa-otp-box"]').first()).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-mfa-code-sending]')).toBeVisible();
        const code = await readOtp(user.email);
        await expect(page.locator('[data-testid="mfa-form-email"] .code-sent')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-form-email"] [data-mfa-code-sending]')).toBeHidden();
        await submitMfaCode(page, 'email', code);
        await page.waitForURL(/index\.php/);
    });

    test('passkey enrolled but WebAuthn unsupported: button hidden, note shown, TOTP fallback works (CHA-07)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);
        await registerPasskey(page);
        await dismissRecoveryCodes(page);

        // Enroll TOTP so there's a usable fallback for the stranded-device scenario.
        await page.goto('/profile.php?tab=tab-security');
        await page.click('[data-testid="totp-setup-btn"]');
        await expect(page.locator('[data-testid="totp-enroll"]')).toBeVisible();
        const secret = (await page.locator('[data-testid="totp-secret"]').innerText()).replace(/\s/g, '');
        await page.fill('[data-testid="totp-confirm-input"]', totp(secret));
        await page.click('[data-testid="totp-confirm-btn"]');
        await expect(page.locator('[data-testid="totp-status"]')).toContainText(/Active|Aktiv/);
        await page.goto('/logout.php');

        // Simulate a browser/device that can't use the passkey (no WebAuthn). Applies to every
        // navigation from here on; feature detection in passkey.js then hides the CTA.
        await page.addInitScript(() => { try { delete window.PublicKeyCredential; } catch (e) { window.PublicKeyCredential = undefined; } });

        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // The passkey panel still renders (a passkey is enrolled) but the CTA stays hidden and the
        // unsupported note takes its place — the fallbacks below carry the member through.
        await expect(page.locator('[data-testid="mfa-view-passkey"]')).toBeVisible();
        await expect(page.locator('[data-testid="mfa-passkey-btn"]')).toBeHidden();
        await expect(page.locator('[data-passkey-unsupported]')).toBeVisible();

        await page.locator('[data-testid="mfa-view-passkey"] [data-testid="mfa-method-totp"]').click();
        await submitMfaCode(page, 'totp', totp(secret));
        await page.waitForURL(/index\.php/);
    });

    test('recovery code is the break-glass while a passkey is primary (CHA-08)', async ({ page }) => {
        await addVirtualAuthenticator(page);
        await login(page, user.email, user.password);
        await page.waitForURL(/index\.php/);

        // Capture a recovery code from the first-factor reveal before dismissing it.
        await registerPasskey(page);
        const codesText = await page.locator('[data-recovery-codes]').innerText();
        const recoveryCode = codesText.trim().split(/\s+/)[0];
        expect(recoveryCode).toMatch(/^[0-9a-f]{5}-[0-9a-f]{5}$/i);
        await page.click('[data-testid="recovery-dismiss-btn"]');

        await page.goto('/logout.php');
        await login(page, user.email, user.password);
        await page.waitForURL(/mfa_challenge\.php/);

        // From the passkey panel, drop to recovery and redeem a code.
        await expect(page.locator('[data-testid="mfa-view-passkey"]')).toBeVisible();
        await page.locator('[data-testid="mfa-view-passkey"] [data-testid="mfa-method-recovery"]').click();
        const form = page.locator('[data-testid="mfa-form-recovery"]');
        await form.locator('input[name="code"]').fill(recoveryCode);
        await form.locator('button[type="submit"]').click();
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
