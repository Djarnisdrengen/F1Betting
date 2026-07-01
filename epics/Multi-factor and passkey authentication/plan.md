# Implementation Plan: Multi-Factor & Passkey Authentication

Feature doc: `epics/Multi-factor and passkey authentication/feature.md`
Test plan:   `epics/Multi-factor and passkey authentication/test-plan.md`

Branch: `mfa-passkey-auth`

---

## Phasing

| Phase | Content | New deps | Shippable |
|---|---|---|---|
| **0** | Migration, `MFA_KEY` config, translation stubs | none | yes (invisible) |
| **1** | TOTP + recovery codes + email OTP, two-step login, Security UI | none | yes |
| **2** | Passkeys (WebAuthn) | Composer + `web-auth/webauthn-lib` | yes |
| **3** | Hardening: notify emails, nudge, docs polish | none | yes |

This plan covers **Phase 0 + Phase 1**. Phases 2–3 are tracked in `feature.md`.

---

## Phase 0 — Foundations (no user-visible change)

### Step 0.1 — Migration (`database/add_mfa.sql`)
Adds `users.email_otp_enabled` + tables `user_totp`, `user_recovery_codes`, `user_email_otp`,
`user_passkeys`. Idempotent guards (`CREATE TABLE IF NOT EXISTS`, conditional column add). Apply to
test DB before Phase 1 code runs.

### Step 0.2 — Config constant `MFA_KEY`
- `config.example.php`: documented placeholder next to `PASSWORD_PEPPER`
  (`// Generate with: php -r "echo bin2hex(random_bytes(32));"`).
- Local `config.test.php` / `config.live.php`: real 64-hex value (Djarnis regenerates on the server).
- `mfa.php` fails loud if `MFA_KEY` is missing or not 64 hex chars — never silently weakens.

### Step 0.3 — Translation stubs
Add keys to `public/lang/user.php` (da+en) and `public/lang/email.php`. See key table below.

---

## Phase 1 — TOTP + recovery + email OTP

### Step 1.1 — `public/includes/mfa.php` (security core)
Pure functions, no globals beyond `getDB()`:

| Function | Purpose |
|---|---|
| `mfaKey()` | 32-byte binary key from `MFA_KEY`; throws if misconfigured |
| `mfaSeal($plain)` / `mfaOpen($blob)` | secretbox seal/open (nonce-prefixed) |
| `base32Encode/Decode()` | RFC 4648 (no padding) for TOTP secret |
| `totpSecret()` | 20 random bytes → base32 |
| `totpCode($secret,$t)` | RFC 6238 HOTP/SHA1, 6 digits, 30 s step |
| `totpVerify($secret,$code,$window=1)` | constant-time, ±1 step skew |
| `totpUri($secret,$account,$issuer)` | `otpauth://` provisioning URI for QR |
| `totpActive($db,$uid)` | confirmed row exists? |
| `genRecoveryCodes()` / `storeRecoveryCodes()` | 10 codes, hashed |
| `verifyRecoveryCode($db,$uid,$code)` | atomic single-use consume |
| `emailOtpActive($db,$uid)` | `users.email_otp_enabled = 1` |
| `issueEmailOtp($db,$uid,$purpose)` | gen + hash + store + send via `smtp.php` |
| `verifyEmailOtp($db,$uid,$code,$purpose)` | TTL + attempts + single-use |
| `userHasActiveFactor($db,$uid)` | TOTP \|\| email OTP \|\| passkey |

**Critical:** recovery + email consumption use
`UPDATE ... SET used_at=NOW() WHERE id=? AND used_at IS NULL` and check `rowCount()===1`, so a race
can't double-spend.

### Step 1.2 — Two-step login (`public/login.php`)
After `verifyPassword()` succeeds, **before** setting `user_id`:
```php
if (userHasActiveFactor($db, $user['id'])) {
    $_SESSION['mfa_pending'] = ['uid' => $user['id'], 'exp' => time() + 600];
    if (emailOtpActive($db, $user['id'])) issueEmailOtp($db, $user['id'], 'login');
    session_regenerate_id(true);            // rotate pre-auth session id
    header('Location: /mfa_challenge.php?redirect=' . urlencode($redirect));
    exit;
}
// else: existing path unchanged (set user_id, regenerate, set prefs, redirect)
```

### Step 1.3 — `public/mfa_challenge.php`
- Guard: requires a non-expired `mfa_pending`; else → `/login.php`. Never readable when fully logged in.
- Renders the active factor(s): TOTP code field and/or email-OTP field, always a "use a recovery code" link.
- POST (CSRF + rate limit): verify in priority order; on success promote (unset pending, set `user_id`,
  `session_regenerate_id(true)`, set lang/theme/font like the normal path), redirect.
- Wrong code → `recordLoginAttempt()` + generic error.

### Step 1.4 — Profile Security section (`public/profile.php`)
New `action` branches (all `requireCsrf()`, all re-auth where noted):

| action | Effect |
|---|---|
| `totp_begin` | generate secret (pending row), show QR + manual key + confirm field |
| `totp_confirm` | verify code → set `confirmed_at`; if first factor, generate recovery codes (show once) |
| `totp_disable` | re-auth → delete `user_totp` row |
| `recovery_regen` | re-auth → replace recovery set, show once |
| `emailotp_begin` | issue enroll OTP via email |
| `emailotp_confirm` | verify enroll OTP → `email_otp_enabled = 1` (+ recovery codes if first factor) |
| `emailotp_disable` | re-auth → `email_otp_enabled = 0` |

UI is a new "Security" card in the profile layout, strings via `t()`, QR rendered client-side from a
`data-otpauth` attribute (manual key always shown as the guaranteed path).

### Step 1.5 — Translations (`public/lang/user.php`, `email.php`)

| Key | DA | EN |
|---|---|---|
| `security` | `'Sikkerhed'` | `'Security'` |
| `two_factor` | `'To-faktor login'` | `'Two-factor login'` |
| `totp_app` | `'Authenticator-app'` | `'Authenticator app'` |
| `totp_setup` | `'Opsæt authenticator'` | `'Set up authenticator'` |
| `totp_scan` | `'Scan QR-koden med din app'` | `'Scan the QR code with your app'` |
| `totp_manual_key` | `'Eller indtast nøglen manuelt'` | `'Or enter the key manually'` |
| `totp_enter_code` | `'Indtast 6-cifret kode'` | `'Enter 6-digit code'` |
| `totp_enabled` | `'Authenticator aktiveret'` | `'Authenticator enabled'` |
| `totp_disabled` | `'Authenticator deaktiveret'` | `'Authenticator disabled'` |
| `email_otp` | `'Kode på e-mail'` | `'Email code'` |
| `email_otp_enabled` | `'E-mailkode aktiveret'` | `'Email code enabled'` |
| `recovery_codes` | `'Gendannelseskoder'` | `'Recovery codes'` |
| `recovery_codes_intro` | `'Gem disse koder sikkert. De vises kun én gang.'` | `'Save these codes safely. They are shown only once.'` |
| `recovery_regenerate` | `'Generér nye koder'` | `'Regenerate codes'` |
| `mfa_prompt_title` | `'Bekræft din identitet'` | `'Verify your identity'` |
| `mfa_use_recovery` | `'Brug en gendannelseskode'` | `'Use a recovery code'` |
| `mfa_invalid_code` | `'Ugyldig eller udløbet kode'` | `'Invalid or expired code'` |
| `mfa_reauth_required` | `'Indtast din adgangskode for at fortsætte'` | `'Enter your password to continue'` |

`email.php`: `otp_subject`, `otp_body` (da+en) for the login/enroll code mail.

### Step 1.6 — E2E specs
`tests/e2e/auth/30-totp-mfa.spec.js`, `34-email-otp.spec.js`, `33-mfa-bypass-negative.spec.js`.
TOTP codes generated in-test from the enrolled secret; email codes read via the existing **Mailsac**
integration. See `test-plan.md` for the case matrix.

---

## Files changed (Phase 0 + 1)

| File | Change |
|---|---|
| `epics/.../feature.md`, `plan.md`, `test-plan.md` | These docs |
| `database/add_mfa.sql` | New migration |
| `config.example.php` | Document `MFA_KEY` |
| `public/includes/mfa.php` | New security core |
| `public/login.php` | Two-step branch |
| `public/mfa_challenge.php` | New challenge gate |
| `public/profile.php` | Security section + actions + QR script tags |
| `public/assets/js/qrcode.min.js` | Vendored MIT qrcodejs (CSP-safe: nonce script, data: img) |
| `public/assets/js/mfa.js` | Renders `.hf-qr[data-otpauth]` → authenticator QR |
| `public/lang/user.php`, `email.php` | New keys (da+en) |
| `tests/e2e/auth/30-totp-mfa.spec.js` | TOTP enroll/login/disable + bypass guard |
| `tests/e2e/auth/31-email-otp.spec.js` | Email OTP via server-side mail interception (no Mailsac) + bypass guard |
| `database/schema.sql`, `docs/gotchas.md` | Document tables + MFA_KEY deploy note |

> **Email OTP testing note:** E2E reads the code from the server-side `SMTP_INTERCEPT` JSONL
> store via `tests/helpers/intercepted-mail.js` — the same mechanism the email specs already use.
> Mailsac is no longer in the loop.

**No changes to:** scoring, betting, leaderboard, `functions.php` core helpers (additive only).

---

## Verification

```bash
php -l public/includes/mfa.php
php -l public/mfa_challenge.php
DEPLOY_ENV=test npx playwright test tests/e2e/auth/ --config tests/playwright.config.js
npm run test:smoke
DEPLOY_ENV=test npx playwright test tests/e2e/01-smoke.spec.js --config tests/playwright.config.js  # login regression
```

## Rollback

All changes additive. To disable: revert `login.php` two-step branch (members fall back to
password-only) — tables can remain. Drop tables + column only if fully abandoning.
