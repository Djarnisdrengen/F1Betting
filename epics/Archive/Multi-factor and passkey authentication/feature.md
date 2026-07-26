# Feature: Multi-Factor & Passkey Authentication

> **Parent epic:** Advanced Authentication Options (`epic-advanced-authentication.md`)
> **Status:** Ready for implementation
> **Stack:** Procedural PHP 8.5, MySQL, vanilla JS, Playwright E2E

---

## Contents

- [Scope](#scope)
- [Decisions (signed off)](#decisions-signed-off)
- [Requirements](#requirements)
- [User Stories](#user-stories)
- [Architecture](#architecture)
- [Security Model](#security-model)
- [Acceptance Criteria](#acceptance-criteria)
- [New Translation Keys](#new-translation-keys)
- [Files to Change](#files-to-change)

---

## Scope

Give every member **optional** ways to strengthen their single platform account on top of the
existing email + password login. Three second-factor methods plus one passwordless method:

| Pillar | Method | Phase | New deps |
|---|---|---|---|
| A | **TOTP** authenticator app (2nd factor) | 1 | none |
| A | **Recovery codes** (single-use fallback) | 1 | none |
| A | **Email OTP** (2nd factor, reuses `smtp.php`) | 1 | none |
| B | **Passkeys / WebAuthn** (passwordless primary, password fallback) | 2 | Composer + `web-auth/webauthn-lib` |

**Everything is opt-in.** A factor is "active" only because the member enrolled it. Nobody is
forced — members with nothing enrolled log in exactly as today. There is no admin enforcement.

**Out of scope:** SMS OTP, social login, removing passwords, forcing MFA on any role.

---

## Decisions (signed off)

- **Passkey crypto:** introduce Composer solely for `web-auth/webauthn-lib`; `vendor/` is committed
  / built at deploy. `require vendor/autoload.php` lives **only** in `webauthn.php` so the rest of the
  codebase stays Composer-agnostic. *(Phase 2.)*
- **Passkey role:** passwordless **primary** login, password retained as fallback.
- **MFA enforcement:** opt-in for **all** users (no forced admin MFA).
- **Email OTP cost:** reuses the existing `smtp.php` pipeline (Proton SMTP primary → Resend free-tier
  fallback). No new service, no new cost at this volume.

---

## Requirements

### Functional

- [REQ-001] A **Security** section in `public/profile.php` is the single surface to enable/disable
  TOTP, manage email-OTP, view/regenerate recovery codes, and (Phase 2) manage passkeys.
- [REQ-002] A factor is "active" iff the member enrolled it: confirmed `user_totp` row (TOTP),
  `users.email_otp_enabled = 1` (email OTP), ≥1 `user_passkeys` row (passkey). **No `mfa_required` flag.**
- [REQ-003] After a correct password, if any second factor is active, **no `$_SESSION['user_id']` is
  set** — only a short-lived `$_SESSION['mfa_pending']` (uid + expiry) — and the member is sent to
  `mfa_challenge.php`. A real session is granted **only** after a factor is verified, at which point
  `session_regenerate_id(true)` fires.
- [REQ-004] The challenge accepts whichever factor(s) are active: TOTP code, emailed code, or a
  recovery code. The recovery-code option is always present when MFA is active.
- [REQ-005] TOTP secrets are sealed at rest with `sodium_crypto_secretbox` using `MFA_KEY`; recovery
  codes and email OTP codes are stored only as `hashPassword()` hashes. Secrets are never re-rendered
  after enrollment and never logged.
- [REQ-006] Disabling a factor or regenerating recovery codes requires a fresh password re-auth.
- [REQ-007] Challenge verification and email-OTP send both reuse `isRateLimited()` / `login_attempts`.
- [REQ-008] All new labels, errors, and emails go through `t()` in both `da` and `en`.

### Constraints

- No changes to existing `users` columns except an additive `email_otp_enabled TINYINT`.
- The password-only login path (members with no factor) must be byte-for-byte unchanged in behaviour.
- Follow existing helper conventions (`getDB()`, `requireCsrf()`, `sanitizeEmail()`, `t()`, flash pattern).

---

## User Stories

**TOTP (Feature 2)**
- As a member I can enroll an authenticator app (manual key + QR), confirm with a 6-digit code, and TOTP becomes active.
- As a member with TOTP active, after my password I'm prompted for a code before any session is granted.
- As a member I can disable TOTP by re-entering my password.

**Recovery codes (Feature 3)**
- On first MFA enrollment I'm shown 10 single-use recovery codes once, with a copy/download affordance.
- At the challenge I can use a recovery code instead of my app; each code is consumed once.
- I can regenerate recovery codes (invalidating the old set).

**Email OTP (Feature 5 addition)**
- I can enable "email me a code at login"; I confirm once with a code sent to my account email.
- With it active, after my password I'm emailed a 6-digit code and held at the challenge until I enter it.
- I can resend (rate-limited) and disable it via re-auth.

**Passkeys (Feature, Phase 2)**
- I can register a named passkey from the Security section.
- I can sign in with just a passkey (password stays as fallback).
- I can view and remove my passkeys.

---

## Architecture

### Data model (additive — `database/add_mfa.sql`)

```sql
ALTER TABLE users ADD COLUMN email_otp_enabled TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE user_totp (
    user_id      VARCHAR(36) PRIMARY KEY,
    secret_enc   VARBINARY(255) NOT NULL,      -- TOTP secret, sodium secretbox sealed
    confirmed_at DATETIME NULL,                -- NULL = pending enrollment, not yet active
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_recovery_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(36) NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,          -- hashPassword() of the code
    used_at    DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_recovery_user (user_id)
);

CREATE TABLE user_email_otp (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(36) NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,          -- hashPassword() of the 6-digit code
    purpose    ENUM('enroll','login') NOT NULL,
    expires_at DATETIME NOT NULL,              -- short TTL (10 min)
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at    DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_emailotp_user (user_id)
);

-- Phase 2
CREATE TABLE user_passkeys (
    id            VARCHAR(36) PRIMARY KEY,
    user_id       VARCHAR(36) NOT NULL,
    credential_id VARBINARY(255) NOT NULL,
    public_key    BLOB NOT NULL,
    sign_count    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports    VARCHAR(255) NULL,
    friendly_name VARCHAR(100) NULL,
    aaguid        BINARY(16) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME NULL,
    UNIQUE KEY uniq_credential (credential_id),
    KEY idx_passkey_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Components

| File | Responsibility |
|---|---|
| `public/includes/mfa.php` | TOTP (RFC 6238), recovery codes, email OTP, secret sealing, "is any factor active" helpers. Procedural, `require_once`-style. |
| `public/includes/webauthn.php` *(Phase 2)* | Thin wrappers over `web-auth/webauthn-lib`; only file touching Composer. |
| `public/mfa_challenge.php` | Post-password second-factor gate; accepts TOTP / email / recovery code. |
| `public/profile.php` | New `action` branches + Security section partial. |
| `public/login.php` | Branch into the two-step state machine. |

### Two-step login state machine

```
POST /login.php (email + password) → verify password
  ├─ no active factor  → set $_SESSION['user_id']; regenerate; redirect   (UNCHANGED PATH)
  └─ active factor     → set $_SESSION['mfa_pending'] = {uid, exp}; (email OTP? send code); redirect /mfa_challenge.php

POST /mfa_challenge.php (totp | email code | recovery) → verify (rate-limited)
  → on success: unset mfa_pending; set $_SESSION['user_id']; session_regenerate_id(true)

Passkey (Phase 2): assertion verified → set $_SESSION['user_id'] directly (already multi-factor)
```

`mfa_pending` grants **no** access and carries its own expiry. The *only* place outside the
unchanged password path that sets `user_id` is the challenge promotion.

---

## Security Model

- **TOTP secret at rest:** `sodium_crypto_secretbox(secret, nonce, MFA_KEY)`; stored as `VARBINARY`.
- **Recovery / email codes:** `hashPassword()` hashed; constant-time verify; single-use via `used_at`.
- **Brute-force:** challenge verify + email send reuse `isRateLimited()` (per IP). Email OTP also has a
  per-row `attempts` cap and short TTL.
- **Enumeration:** generic errors; no signal whether an email has MFA enabled.
- **Re-auth:** disable / regenerate require a fresh password check (mirrors `change_password`).
- **Session:** real session id regenerated on promotion, never before.
- **www gotcha (Phase 2):** WebAuthn RP ID = the `www` host, else assertions silently fail.

---

## Acceptance Criteria

```gherkin
Feature: Optional second factor, enforced server-side

  Scenario: Password-only member logs in unchanged
    Given a member with no active factor
    When they submit a correct email and password
    Then a session is created and they reach their redirect target

  Scenario: Active TOTP holds the member at the challenge
    Given a member with confirmed TOTP
    When they submit a correct password
    Then no session user_id is set, only mfa_pending
    And they are redirected to mfa_challenge.php

  Scenario: Direct access while pending is denied
    Given a member who passed the password step but not the second factor
    When they request a protected page directly
    Then they are treated as logged out and redirected to login

  Scenario: Correct second factor promotes the session
    Given a member at the challenge
    When they submit a valid TOTP, emailed, or recovery code
    Then mfa_pending is cleared, user_id is set, and the session id is regenerated

  Scenario: Recovery code is single-use
    Given a member redeemed a recovery code
    When they submit the same code again
    Then it is rejected

  Scenario: Disabling a factor requires re-authentication
    Given a logged-in member with TOTP active
    When they disable TOTP without re-entering their password
    Then the request is rejected and TOTP stays active

  Scenario: Email OTP reuses existing mail and is rate limited
    Given a member with email OTP active
    When they pass the password step
    Then a code is emailed via smtp.php
    And repeated sends or wrong guesses from one IP are throttled
```

---

## New Translation Keys

Added to `public/lang/user.php` (`da` + `en`) and `public/lang/email.php` for the OTP email.
See `plan.md` for the full key table.

---

## Files to Change

| File | Change | Phase |
|---|---|---|
| `database/add_mfa.sql` | New migration | 0 |
| `config.example.php` | Document `MFA_KEY` | 0 |
| `public/lang/user.php`, `email.php` | New keys (da+en) | 0/1 |
| `public/includes/mfa.php` | New — TOTP/recovery/email-OTP core | 1 |
| `public/mfa_challenge.php` | New — challenge gate | 1 |
| `public/login.php` | Two-step branch | 1 |
| `public/profile.php` | Security section + actions | 1 |
| `tests/e2e/auth/30-totp-mfa.spec.js`, `34-email-otp.spec.js`, `33-mfa-bypass-negative.spec.js` | New specs | 1 |
| `composer.json`, `public/includes/webauthn.php`, `public/webauthn_*.php`, `public/assets/js/webauthn.js` | New — passkeys | 2 |
| `docs/architecture.md`, `gotchas.md`, `security.md`, `schema.md` | Document new system | 1/2 |

**No changes to:** scoring, betting, leaderboard, existing `users` columns (beyond the additive flag).
