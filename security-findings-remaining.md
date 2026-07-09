# Remaining Security Findings (F6–F12) — Medium / Low

Working file — the deferred items from the security review. F1–F5 (Critical/High) are fixed and in
`main`; these are the lower-severity items still open. Delete or fold into a tracker when addressed.

Severity: Medium / Low / Info. "Status" reflects what the F1–F5 work already changed incidentally.

---

## F6 — Secrets & tokens transmitted in URL query strings — **Medium** — 🚧 In progress
Tokens travel in `?token=…` on `db-backup.php`, `schema-check.php`, `seed_f1_admin.php`, `cron/*`, and
the `forgot_password` e2e param, so they land in web-server/proxy access logs and `Referer` headers.
- **Status:** all compares are `hash_equals`/constant-time now (`notifications.php` and
  `db-restore.php` still had a plain `===`/`!==` — fixed as part of this pass). `db-backup.php`,
  `schema-check.php`, `db-restore.php`, and both `cron/*` scripts' HTTP branch now read the token via
  a new `getBearerToken()` helper (`functions.php`) instead of `$_GET['token']` — `Authorization:
  Bearer <token>`. Node callers (`backup.js`, `schema-check.js`, `restore-db.js`,
  `nightly-backup.yml`) updated to send the header. CLI invocation (`argv`) for the cron scripts is
  unaffected — it was never URL/log-exposed.
- **Cron trigger migration (cutting over 2026-07-09):** `import_qualifying.php`/`notifications.php`
  were triggered by Simply.com's control-panel cron feature, which only does a plain GET with no
  custom headers — incompatible with the header-only fix. Trigger moved to GitHub Actions instead:
  `.github/workflows/cron-qualifying-import.yml` / `cron-notifications.yml`. Both cron scripts still
  carry a **temporary dual-accept shim** — header OR the legacy `?token=` — kept in place until one
  full clean cycle has run on the new schedule (see step 4 below). **Remove the `?token=` branch from
  both scripts** once that happens; don't leave the shim in place long-term.
- **Deferred, accepted as-is:** `seed_f1_admin.php` (already `hash_equals`-safe, excluded from live
  deploy via `.deployignore.live`, and its only "caller" is a human pasting a URL into a browser — no
  header-carrying client exists for it) and `forgot_password.php`'s `e2e_token` (already
  `hash_equals`-gated and hard-`false` on live regardless of `APP_ENV`). Both stay on `?token=`.
- **Cutover checklist:**
  1. ✅ Added the `CRON_SECRET` GitHub Actions repo secret.
  2. ✅ `workflow_dispatch` both new workflows by hand against live — both green.
     Notifications: dry run, `Notification check complete`, no sends (nothing due). Qualifying
     import: **real** run (safe — next race, Belgian GP, is 2026-07-19, no new quali data yet),
     `Total races updated: 0`, no unexpected writes. (Discovered along the way, unrelated to F6:
     `dry_run=true` on the qualifying-import workflow doesn't work against live —
     `tools/f1_testdata.php` is excluded from the live deploy via `.deployignore.live`, so the
     script dies on a fatal `require()` after the token check passes. `dry_run` on that workflow is
     test-env only; noted in the workflow file, not fixed here.)
  3. ✅ `schedule:` enabled in both workflow files (deployed to `main`); Simply.com control-panel
     entries deleted and confirmed removed by user, same sitting.
  4. ✅ Added equivalent triggers for hpovlsen.dk (test) at the same cadence — each workflow now
     runs `trigger-live` and `trigger-test` jobs on one shared `schedule:`. Chosen as full parity
     over `workflow_dispatch`-only for test, knowingly: outside an E2E run `SMTP_INTERCEPT` isn't
     active (gotcha #17), so notifications sends real email on test too, and the qualifying import
     writes real API results into a `races` table `test-seed.php` periodically wipes for E2E
     fixtures. Needs a `BASE_URL_TEST` variable (added) and a **`CRON_SECRET_TEST` repo secret
     (not yet added — value is `CRON_SECRET` from `config.test.php`, different from live's)**.
  5. Schedules tuned from the original spread-hourly-slots design to `*/5 6-23 * * 6` (qualifying
     import — every 5 min across the Saturday UTC day) and `1 * * * *` (notifications).
  6. **Next:** after one full clean cycle (a Saturday qualifying window + ~24h of hourly
     notifications) with no `Unauthorized access` lines in `cron_qualifying.log`/
     `cron_notifications.log` on **either** environment, remove the `?token=` shim from both cron
     scripts and redeploy.
  7. Scrub existing access logs of the old `?token=` values if feasible.
- **Effort:** medium (touches CI + cron config). **Files:** `functions.php`, `public/tools/*.php`,
  `public/cron/*.php`, the Node callers, `.github/workflows/*`, `tests/e2e/07-cron.spec.js`,
  `public/.htaccess`.

## F7 — Login / MFA rate limiting is weak — **Medium** — ✅ Fixed
`public/includes/functions.php:391-408`: per-IP counter, threshold `>=5`/15 min (comment still says
"3" — drift), and `login.php:33-36` **fails open** on a DB exception while `:51` clears the IP's
counter on any successful login. Uses `REMOTE_ADDR` (so *not* `X-Forwarded-For`-spoofable), but there
is no per-account lockout, and the same IP bucket is shared with the MFA challenge.
- **Fix applied:** `login_attempts` gained two columns, `scope` (`login` | `mfa`) and `account`
  (submitted email for `login`, user id for `mfa`; `NULL` when the target account isn't known yet,
  e.g. a failed passwordless passkey assertion) — migration `database/add_login_attempts_scope.sql`,
  registered in `migrations.json`. `isRateLimited()`/`recordLoginAttempt()`/`clearLoginAttempts()` now
  take `$scope` + `$account` and check **both** an IP bucket and an account bucket per scope, so a
  distributed attack on one victim is caught even when it's spread across IPs, and a shared/NAT'd IP
  keeps working for everyone else once the real account owner proves who they are.
  `login.php`/`mfa_challenge.php`/`webauthn.php` all fail **closed** now — an `isRateLimited()`
  exception defaults `$rateLimited = true` and logs to `APP_LOG_FILE` instead of silently letting the
  attempt through. `clearLoginAttempts()` only ever deletes the calling account's own rows for that
  scope — it no longer wipes the IP-wide bucket, which used to let an attacker reset the whole IP's
  budget by logging into their own account mid-attack. `login` and `mfa` are separate buckets end to
  end (password step + passwordless passkey vs. code/passkey MFA challenge + resend), so exhausting one
  never blocks the other. MFA's *account* threshold is stricter (3 vs. 5) — a 6-digit OTP/TOTP code has
  a much smaller keyspace than a password — while its *IP* threshold stays at 5 to tolerate normal
  multi-user traffic from a shared address (validated against the E2E suite's incidental MFA failures
  across `tests/e2e/auth/*.spec.js`, which share one CI runner IP). Fixed the "3 vs 5" comment drift.
- **Effort:** medium. **Files:** `functions.php`, `login.php`, `mfa_challenge.php`, `webauthn.php`,
  `database/schema.sql`, `database/add_login_attempts_scope.sql`, `database/migrations.json`.

## F8 — SMTP TLS certificate verification disabled — **Medium** — ✅ Fixed
`public/includes/smtp.php:87-90`: `verify_peer=false`, `verify_peer_name=false`,
`allow_self_signed=true`. The Proton STARTTLS upgrade then proceeds without validation, so an on-path
attacker could MITM and capture the base64 `AUTH LOGIN` credentials.
- **Fix applied:** `verify_peer`/`verify_peer_name` now `true`, `allow_self_signed` now `false`, and an
  explicit `peer_name` (the configured `SMTP_HOST`) is set on the context — needed because the STARTTLS
  path connects via `tcp://` first, so PHP has no scheme-derived hostname to check against at the point
  `stream_socket_enable_crypto` runs. Verified against the real `smtp.protonmail.ch:587` STARTTLS
  handshake (cert `CN=protonmail.com`, SAN includes `*.protonmail.ch`) — TLS now validates successfully
  with these settings; previously it silently accepted anything.
- **Effort:** low. **Files:** `smtp.php`. (Note: `SMTP_PASS` is shared test↔live, which raises the
  stakes here.)

## F9 — SMTP header injection (latent) — **Low** — ✅ Fixed
`smtp.php:209,228-229` concatenate `$to`/`$subject` into SMTP data with no CRLF filtering.
- **Status (pre-fix):** not currently exploitable — every caller validates the address via
  `sanitizeEmail` (`functions.php:16`, `FILTER_VALIDATE_EMAIL` rejects CRLF) and subjects are
  app-controlled strings.
- **Fix applied:** `SMTPMailer::send()` now strips `\r`/`\n` from `$to` and `$subject` up front,
  before either the SMTP or the Resend fallback path uses them — hardening regardless of caller,
  not a behavior change for any current caller.
- **Effort:** low. **Files:** `smtp.php`.

## F10 — Bet driver IDs not validated against `drivers` — **Low** — ✅ Fixed
`validateBetCombination` (`functions.php:416-432`) checks non-empty / distinct / not-quali / not-dupe,
but not that `p1/p2/p3` are real IDs in `drivers`. A crafted POST to `bet.php`/`edit_bet.php` stores
arbitrary strings (low impact: junk never scores and `profile.php` inner-joins `drivers` so it drops).
- **Fix applied:** `validateBetCombination()` takes a new `$validDriverIds` parameter and rejects any
  p1/p2/p3 not in it (new `invalid_driver` translation key, da/en). `bet.php`/`edit_bet.php` pass
  `array_keys($driversById)` — the driver list they already fetch via `fetchDrivers()`, no new query.
- **Effort:** low. **Files:** `functions.php`, `bet.php`, `edit_bet.php`, `lang/user.php`.

## F11 — Predictable identifiers — **Low** — ✅ Fixed
`generateUUID()` uses `mt_rand`, not a CSPRNG, so exposed UUIDs are guessable. Object fetches are
correctly scoped by `WHERE user_id = ?` today, so treat this as defense-in-depth.
- **Fix applied:** `generateUUID()` now builds a v4 UUID from `random_bytes(16)` (version/variant bits
  set per RFC 4122), same `8-4-4-4-12` lowercase-hex output shape as before — verified against the
  UUIDv4 regex. Every object fetch stays ownership-scoped regardless.
- **Effort:** low. **Files:** `functions.php`.

## F12 — Weak password policy / session hygiene — **Low / Info** — 🚧 Partially fixed
- Minimum password length is 6 across register / reset / change.
- No idle or absolute **session timeout**; changing the password does **not** revoke other active
  sessions (`profile.php:30-51`).
- CSP allows `style-src 'unsafe-inline'` (`includes/header.php`).
- **Fix applied (password policy + session hygiene):** new `validatePasswordStrength()`
  (`functions.php`) enforces 10+ chars plus at least one letter and one digit — wired into
  `register.php`, `reset_password.php`, `profile.php` (change_password), and `admin.php`
  (admin-initiated reset); all five `minlength="6"` HTML attributes bumped to `10`. New
  `establishSession()` helper stamps `login_time`/`last_activity`/`pwd_changed_at` at every
  login-completion path (`login.php`, `mfa_challenge.php`, `webauthn.php`'s
  `passkeyPromoteSession()`, `register.php`) and rotates the session id (unchanged behavior, just
  centralized). `getCurrentUser()` now enforces a 30-minute idle timeout and a 12-hour absolute
  timeout, and treats a session's `pwd_changed_at` stamp not matching the account's current DB value
  as stale — logging it out. New `password_changed_at` column on `users`
  (`database/add_password_changed_at.sql`, registered in `migrations.json`) is set to `NOW()`
  whenever a password changes (self-service, reset-link, or admin reset); the session performing a
  self-service change refreshes its own `pwd_changed_at` stamp immediately after so it doesn't log
  itself out — only *other* active sessions for that account go stale on their next request. Sessions
  that predate this fix (no `login_time`/`pwd_changed_at` in session, or `password_changed_at` still
  `NULL`) are treated as not-expired/not-stale rather than mass-logged-out on deploy.
- **Deferred:** CSP `style-src 'unsafe-inline'` removal. Checked the actual scope before starting —
  it's 264 inline `style="..."` attributes across 27 files (CSP nonces only cover `<style>` blocks,
  not the `style` attribute, so this means a CSS-class refactor across all of them, not a nonce
  change), well past the "low-medium" estimate here and with real visual-regression risk. Djarnis
  chose to scope it out of this pass; left as a separate follow-up if picked up later.
- **Effort:** low–medium (done); CSS refactor for the CSP piece not scoped/estimated. **Files:**
  `functions.php`, `register.php`, `reset_password.php`, `profile.php`, `admin.php`,
  `includes/admin/users.php`, `login.php`, `mfa_challenge.php`, `webauthn.php`, `lang/user.php`,
  `database/schema.sql`, `database/add_password_changed_at.sql`, `database/migrations.json`.
  `header.php` untouched (CSP piece deferred).

---

## Suggested order if you do a second pass
1. ~~**F8** (SMTP TLS) — small change, real MITM exposure, worsened by shared `SMTP_PASS`.~~ ✅ Fixed
2. ~~**F7** (rate-limiting / lockout) — brute-force resistance for login + MFA.~~ ✅ Fixed
3. **F6** (tokens out of URLs) — removes the last log-exposure of the seed/cron tokens.
4. ~~**F12 / F9 / F10 / F11** — low-risk hardening, batch them together.~~ ✅ Fixed (F12's CSP
   `unsafe-inline` piece deferred — see F12 above).

Not security-blocking for the live deploy of F1–F5. F6 is the only item still open (migration to a
DB-tracked `CRON_SECRET_TEST` and the `?token=` shim removal); F9–F12 are done except F12's deferred
CSP/CSS refactor. **New DB migration to apply before this lands anywhere:**
`database/add_password_changed_at.sql` (also registered in `migrations.json`, so `schema:check` /
`schema:check:live` will now fail loudly until it's run against each environment's DB).
