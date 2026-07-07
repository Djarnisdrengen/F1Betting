# Implementation Plan: Passkey (WebAuthn) Authentication

Epic: `epic-passkey-authentication.md` · Test plan: `test-plan.md`
Produced by the web-architecture-review skill against the shipped MFA code (2026-07-05).

Branch: `passkey-auth`

---

## Decisions (signed off)

All review conditions from `test-plan.md` §8 were decided by Djarnis on 2026-07-05 and are folded into the steps below (rpId guardrail, one-time launch device pass, atomicity gate, error parity, flash-on-reload, hard-fail sync, all nice-to-haves).

- **Role — full scope:** passwordless "Sign in with a passkey" on `login.php` (discoverable credentials) **and** passkey as a method in the existing `mfa_challenge.php` for password logins. Default ordering: passkey → totp → email.
- **Verification — vendored library, no Composer:** vendor **lbuchs/WebAuthn** (MIT, self-contained, no dependencies beyond ext-openssl) into `public/includes/webauthn/`, pinned to a tagged release recorded in a `VERSION` file. Same precedent as the vendored `public/assets/js/qrcode.min.js`. `require`d **only** from the new `public/includes/passkey.php` wrapper so the rest of the codebase stays library-agnostic.
- **rpId = registrable domain**, `hpovlsen.dk` (test) / `formula-1.dk` (live). This keeps credentials valid for both `www` and apex. **One-way door:** changing the rpId after the first live registration orphans every passkey. **Guardrail (review 🔴-2):** an explicit `PASSKEY_RPID` constant in `config.test.php`/`config.live.php` (documented placeholder in `config.example.php`, next to `MFA_KEY`); `passkeyRpId()` fails loud — same pattern as `mfaKey()` — if the constant is missing or doesn't match the registrable domain derived from `SITE_DOMAIN`. A config edit that changes the domain now produces an immediate error instead of silently orphaning credentials.
- **No schema migration:** `user_passkeys` already exists in `database/schema.sql` (and `database/migrations.json`) with the needed columns (`credential_id VARBINARY(255) UNIQUE`, `public_key BLOB`, `sign_count`, `transports`, `friendly_name`, `aaguid`, `last_used_at`). Platform credential IDs are well under 255 bytes; the wrapper rejects oversized IDs defensively.
- **Attestation `none`, user verification `required`, resident key `required`, authenticatorAttachment `platform`** (best-effort steering per the epic's Constraints).

## Atomicity constraint (release-critical)

`passkeyActive()` is already wired into `userHasActiveFactor()` (`public/includes/mfa.php:266-277`). The first `user_passkeys` row gates that member's password login behind the two-step challenge. Therefore **registration (Step 2), the challenge passkey method (Step 3), and assertion verification (Step 1) ship in one release** — never registration alone.

## Phasing

| Phase | Content | Shippable | Status |
|---|---|---|---|
| **1** | Vendored lib + `passkey.php` core + endpoint + registration UI + challenge method + `sync.js` guard + docs | yes (complete passkey-as-factor) | **shipped live 2026-07-06** (both gates passed: auth suite green on test, device checklist signed) |
| **2** | Passwordless login button on `login.php` (conditional UI deferred — see Phase 2) | yes | implemented 2026-07-06 |
| **3** | Hardening: login-method instrumentation, AAGUID labels, lib vectors | yes | implemented 2026-07-06 (nudge copy not done — still optional) |

Phases 1 and 2 can also land as a single release; Phase 1 is the minimum safe unit.

---

## Phase 1 — Core, registration, challenge

### Step 1.1 — Vendor the library (`public/includes/webauthn/`)

Copy the lbuchs/WebAuthn `src/` tree (WebAuthn.php, Binary/, Attestation/, CBOR/) unmodified; add `VERSION` (tag + upstream commit hash + date) and the MIT `LICENSE`. Update procedure documented in the file header: re-copy a newer tag, diff, rerun the auth E2E suite.

### Step 1.2 — `public/includes/passkey.php` (security core)

Procedural wrapper in `mfa.php` style — pure functions, `getDB()` only, no globals. The only file that `require`s the vendored lib.

| Function | Purpose |
|---|---|
| `passkeyRpId()` | returns `PASSKEY_RPID`; throws (fail-loud, like `mfaKey()`) if the constant is missing or doesn't equal `SITE_DOMAIN` minus leading `www.` |
| `passkeyInstance()` | configured `\lbuchs\WebAuthn\WebAuthn` (rp name = app title, `passkeyRpId()`, attestation `none`, ES256 + RS256) |
| `passkeyChallengeBegin($purpose)` | store `['challenge', 'purpose', 'exp' => time()+300]` in `$_SESSION['webauthn_challenge']` (one slot) |
| `passkeyChallengeTake($purpose)` | fetch **and unset** (single-use); null on missing/expired/purpose mismatch |
| `passkeyRegisterOptions($db, $user)` | create-args: resident key + UV required, attachment `platform`, `excludeCredentials` = member's existing credential ids, userId = `users.id` |
| `passkeyRegisterVerify($db, $uid, $clientData, $attestation)` | verify create → INSERT `user_passkeys` (`generateUUID()` id, credential_id, public_key PEM, sign_count, transports, default `friendly_name`, aaguid) |
| `passkeyAssertOptions($db, ?$uid)` | get-args; with `$uid` → `allowCredentials` from the member's rows; null → empty (discoverable, passwordless) |
| `passkeyAssertVerify($db, $payload, ?$uid)` | resolve row by credential id (passwordless: also require assertion `userHandle` == row `user_id`); verify signature/origin/rpId/UV via the lib; sign-count policy below; update `sign_count`, `last_used_at`; return `user_id` or null |
| `passkeyList($db, $uid)` / `passkeyRename(...)` / `passkeyDelete(...)` | Security-tab management |
| `passkeyDefaultLabel()` | coarse label from UA platform hint, else "Passkey (jul 2026)"-style; member renames afterwards |

**Sign-count policy:** reject only when `stored > 0 && new > 0 && new <= stored` (possible clone) and log via `logToFile()`. Most platform authenticators always report 0 — treat the check as advisory, never lock the account on it.

**`public/includes/mfa.php` edits (small):** `activeMfaMethods()` prepends `'passkey'` when `passkeyActive()`; `setMfaDefaultMethod()` whitelist becomes `['passkey','totp','email']`; drop the "Phase 2 placeholder" comment. `getMfaDefaultMethod()` needs no change — it already resolves preference against the active list.

### Step 1.3 — Endpoint `public/webauthn.php` (JSON, POST-only)

Standard page opening (`config.php`, `functions.php`, `mfa.php`, `passkey.php`), `requireCsrf()` on every action (JS sends `csrf_token` in `FormData` — matches `requireCsrf()` reading `$_POST['csrf_token']`; the login page already renders `csrfField()`). Responses `Content-Type: application/json`.

**Error parity (review 🟡-2, decided):** every failure mode — unknown credential, bad signature, revoked, expired challenge — returns the **byte-identical** generic JSON body (`{"error": t('passkey_error')}`), so nothing is enumerable. The one deliberate exception is the rate-limit response, which keeps the `Retry-After` header (consistent with `mfa_challenge.php`). All diagnostic detail goes to `logToFile()` only — debugging passkey failures means reading the server log, by design.

| `action` | Guard | Effect |
|---|---|---|
| `register_options` | `getCurrentUser()` | create-args JSON + session challenge (`purpose=register`) |
| `register_verify` | `getCurrentUser()` | verify + INSERT; if first factor → `ensureRecoveryCodes()` → `$_SESSION['flash_recovery_codes']`; `{ok:true}`. **Decided (🟡-1 A):** `passkey.js` reloads the profile page on success so the existing flash block renders the codes — no new codes UI |
| `challenge_options` | valid `$_SESSION['mfa_pending']` | get-args with `allowCredentials` for the pending uid |
| `challenge_verify` | valid `mfa_pending` + `isRateLimited()` | on success promote **exactly** like `mfa_challenge.php:70-90`: unset pending, set `user_id`, `session_regenerate_id(true)`, `clearLoginAttempts()`, `last_login`, `setLang/setTheme/setFont`; `{redirect}` |
| `login_options` | anonymous | discoverable get-args (`purpose=login`) |
| `login_verify` | anonymous + `isRateLimited()` | verify → same promotion contract (no `mfa_pending` involved); `recordLoginAttempt()` on failure; `{redirect}` |

The two `*_verify` login actions are the **only new places that set `$_SESSION['user_id']`** — mirror the challenge page's promotion block verbatim and keep it in one helper if that stays readable.

### Step 1.4 — Security tab (`public/profile.php`)

New passkey card in `tab-security`: list rows (`friendly_name`, `created_at`, `last_used_at`), "Add passkey" button (drives `passkey.js`), inline rename, revoke. New `action` branches following the existing table:

| action | Re-auth | Effect |
|---|---|---|
| `passkey_rename` | no (cosmetic) | update `friendly_name` |
| `passkey_delete` | **`mfaReauth()`** | delete row; if last factor, account returns to password-only |

Add `passkey` to the `mfa_default` selector options when `passkeyActive()`. `data-testid`s: `passkey-add`, `passkey-row`, `passkey-rename`, `passkey-delete`.

### Step 1.5 — Challenge page (`public/mfa_challenge.php`)

`activeMfaMethods()` already drives ordering, so the lead/other layout picks passkey up automatically. Add to `mfaMethodBlock()` a `passkey` branch: a button (`data-testid="mfa-form-passkey"`) that triggers the `challenge_options`/`challenge_verify` fetch flow; a hidden unsupported-browser message (`passkey.js` reveals options only when `window.PublicKeyCredential` exists — otherwise the block collapses and "Other options" remains the path). Add `passkey` to `mfaMethodLabel()`.

### Step 1.6 — `sync:live` guard (`build-deploy/sync.js`)

After copying live → test, truncate `user_passkeys` on the **test** DB: live credentials are rpId-bound to `formula-1.dk`, unusable on `hpovlsen.dk`, and would gate those members' test logins behind an unsatisfiable factor. **Decided (🟡-4): fail loud** — if the truncate errors, the sync exits non-zero and says why; the one tolerated case is "table doesn't exist" (pre-migration DB, nothing to clear). Row count reported in the sync output.

### Step 1.7 — Frontend (`public/assets/js/passkey.js`)

Vanilla JS, loaded with the existing CSP nonce script pattern (`header.php` emits `script-src 'self' 'nonce-…'`). Contents: base64url ⇄ ArrayBuffer codecs, feature detection, the three fetch flows (register / challenge / login), and error display via existing alert styles. No external requests, no new CSP entries.

### Step 1.8 — Translations (`public/lang/user.php`, da + en)

| Key | DA | EN |
|---|---|---|
| `passkey` | `'Passkey'` | `'Passkey'` |
| `passkey_signin` | `'Log ind med passkey'` | `'Sign in with a passkey'` |
| `passkey_add` | `'Tilføj passkey'` | `'Add passkey'` |
| `passkey_intro` | `'Log ind med Face ID, fingeraftryk eller din adgangskodemanager'` | `'Sign in with Face ID, fingerprint, or your password manager'` |
| `passkey_registered` | `'Passkey tilføjet'` | `'Passkey added'` |
| `passkey_removed` | `'Passkey fjernet'` | `'Passkey removed'` |
| `passkey_rename` | `'Omdøb'` | `'Rename'` |
| `passkey_revoke` | `'Fjern passkey'` | `'Remove passkey'` |
| `passkey_last_used` | `'Sidst brugt'` | `'Last used'` |
| `passkey_error` | `'Passkey-handlingen mislykkedes. Prøv igen.'` | `'The passkey action failed. Please try again.'` |
| `passkey_unsupported` | `'Din browser understøtter ikke passkeys'` | `'Your browser does not support passkeys'` |
| `passkey_challenge_prompt` | `'Brug din passkey for at fortsætte'` | `'Use your passkey to continue'` |

### Step 1.9 — Docs

- `docs/gotchas.md`: rpId one-way door; test/live credential non-portability; `sync:live` truncates `user_passkeys`; sign-count-zero note; registration/challenge atomicity.
- `docs/architecture.md`: extend the auth section with the two new promotion paths.
- `database/schema.sql`: comment on `user_passkeys` pointing at `passkey.php` (no structural change).

---

## Phase 2 — Passwordless login (`public/login.php`)

- "Sign in with a passkey" button under the password form (`data-testid="passkey-login"`), visible only after JS feature detection (ships `hidden`; `passkey.js` reveals `[data-passkey-supported]` when WebAuthn exists — no dead button for no-JS/unsupported browsers).
- Flow: `login_options` → `navigator.credentials.get()` → `login_verify` → JS navigates to the returned redirect. Failures fall back silently to the password form with a generic error.
- Covered by PWL-01 in `35-passkey.spec.js` (register → logout → button → session, no email/password typed).

**Conditional UI deferred (2026-07-06).** The optional autofill flow
(`navigator.credentials.get({mediation:'conditional'})`) is intentionally **not** implemented:

1. **Untestable in the release gate:** the CDP virtual authenticator cannot drive the browser's
   autofill credential picker, so the flow can never be covered by the auth E2E suite that gates
   every live deploy — and depending on Chromium version, a pending conditional request against a
   virtual authenticator with a resident credential risks *auto-resolving*, which would let
   passwordless auto-login race the password flows in every existing auth spec.
2. **Device-unverified:** the one-time real-device gate (🔴-1 B) has already run and did not
   include conditional UI; shipping it now would put unverified prompt UX on iOS/Android.
3. A pending conditional request must be `AbortController`-cancelled before any modal
   `credentials.get()` or the button flow throws — extra state machine for marginal gain.

The email field already carries `autocomplete="username webauthn"`, so enabling it later is a
JS-only change. Revisit only on demand, with its own device pass.

## Phase 3 — Hardening (committed — all review nice-to-haves accepted 2026-07-05; implemented 2026-07-06)

- **Login-method instrumentation (🟢-2) — done:** `logLoginMethod()` in `mfa.php`, one
  `[LOGIN] method=… user=…` line to `APP_LOG_FILE` from every promotion path (`login.php`,
  `mfa_challenge.php`, `webauthn.php`). Methods: `password`, `passkey` (passwordless),
  `password+totp/email/recovery/passkey` (two-step) — richer than the original single-token
  list so passwordless share is readable directly. No schema change.
- **AAGUID default naming (🟢-3) — done:** `passkeyAaguidLabel()` in `passkey.php` maps
  well-known provider AAGUIDs (iCloud Keychain, Google Password Manager, Windows Hello,
  Proton Pass, 1Password, Bitwarden, …) to `friendly_name` defaults with a date suffix;
  precedence is member-supplied label → AAGUID map → UA/date fallback. The device pass
  recorded no AAGUID observations, so the map is from the community list — safe either way,
  since unknown/zeroed ids fall through to the existing fallback.
- **Vendored-lib vector harness (🟢-1) — done:** `tests/unit/passkey-harness.php` now runs
  known-answer create/get ceremonies against the lib: an ext-openssl P-256 key, hand-built
  CBOR attestation, then `processCreate`/`processGet` with production's exact call shape —
  asserting credential id, byte-exact public-key DER, AAGUID, counter, plus rejection of
  tampered signatures, wrong challenges, and missing user verification. *Deviation from the
  plan text:* synthetic vectors instead of published ones — they bind to our rpId/origin
  config, cover signature verification end-to-end, and stay deterministic offline. Rerun on
  every version bump of `public/includes/webauthn/`.
- Post-login nudge copy for members with no passkey — **not implemented** (still optional;
  product copy for Djarnis to decide).

---

## Files changed

| File | Change |
|---|---|
| `epics/Passkey/*.md` | These docs |
| `public/includes/webauthn/` | New: vendored lbuchs/WebAuthn (pinned) + `VERSION` + `LICENSE` |
| `public/includes/passkey.php` | New: security core wrapper |
| `public/includes/mfa.php` | `activeMfaMethods()` + `setMfaDefaultMethod()` extended |
| `public/webauthn.php` | New: JSON endpoint (6 actions) |
| `public/profile.php` | Passkey card + `passkey_rename`/`passkey_delete` actions + default selector |
| `public/mfa_challenge.php` | Passkey method block + label |
| `public/login.php` | Passkey button + conditional UI (Phase 2) |
| `public/assets/js/passkey.js` | New: codecs + fetch flows |
| `public/lang/user.php` | New keys (da+en) |
| `config.example.php` | Document `PASSKEY_RPID` placeholder (real values go in local `config.test.php` / `config.live.php`) |
| `build-deploy/sync.js` | Truncate `user_passkeys` on test copy (fail loud) |
| `docs/gotchas.md`, `docs/architecture.md`, `database/schema.sql` | Documentation |
| `tests/e2e/auth/35-passkey.spec.js`, `36-passkey-negative.spec.js` | New specs (see `test-plan.md`) |
| `tests/unit/passkey.test.js`, `tests/unit/passkey-harness.php` | New: JS codec tests (`node --test`) + PHP CLI harness (challenge lifecycle, sign-count policy, lib vectors) |
| `tests/helpers/seed.js`, `public/tools/test-seed.php` | `cleanup_passkeys`, `set_passkey_sign_count` seed actions |

**No changes to:** scoring, betting, leaderboard, password hashing, TOTP/email-OTP/recovery internals. No new schema objects, no Composer, no new external services.

## Verification

```bash
php -l public/includes/passkey.php
php -l public/webauthn.php
node --test tests/unit/passkey.test.js && php tests/unit/passkey-harness.php
DEPLOY_ENV=test npx playwright test tests/e2e/auth/ --config tests/playwright.config.js
npm run test:smoke
npm run test:security
```

**Live-deploy gates (decided 2026-07-05):**

- **Every live deploy (🔴-3 A):** the full auth E2E suite — including `35-passkey.spec.js` register → challenge-login sequence — must be green on the **test env** with the exact code being deployed. This proves registration/challenge atomicity on real infrastructure; the passkey specs stay out of smoke (🟡-3 A), so this ordering discipline is the gate. The live smoke itself must **never** register passkeys against the live DB (same hazard class as the `cd917d3` smoke-credentials fix).
- **Once, at launch (🔴-1 B):** a written real-device checklist on the test env — iPhone Safari (Face/Touch ID + iCloud Keychain), Android Chrome (Google Password Manager); Windows Hello optional — each device: register, passwordless login, challenge login, revoke. One-time gate before the first live deploy of this epic; later mobile regressions are an accepted residual risk (logged in `test-plan.md` §6).

## Rollback

All changes additive. To disable: hide the passkey UI blocks and short-circuit `public/webauthn.php` (single early-exit flag); delete a member's `user_passkeys` rows to un-gate their login (their password + other factors keep working). `activeMfaMethods()`/`setMfaDefaultMethod()` degrade gracefully when no passkey rows exist. Schema stays.
