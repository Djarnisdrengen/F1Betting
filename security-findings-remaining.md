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
- **Cron trigger migration (in progress):** `import_qualifying.php`/`notifications.php` were triggered
  by Simply.com's control-panel cron feature, which only does a plain GET with no custom headers —
  incompatible with the header-only fix. Moving their trigger to GitHub Actions instead: new
  `.github/workflows/cron-qualifying-import.yml` / `cron-notifications.yml`, currently
  `workflow_dispatch:`-only (no `schedule:` yet — see cutover steps below). Both cron scripts
  currently carry a **temporary dual-accept shim** — header OR the legacy `?token=` — so Simply's
  existing control-panel entries keep working until the GitHub Actions replacement is proven and the
  Simply.com entries are manually deleted. **Remove the `?token=` branch from both scripts** once
  that happens; don't leave the shim in place long-term.
- **Deferred, accepted as-is:** `seed_f1_admin.php` (already `hash_equals`-safe, excluded from live
  deploy via `.deployignore.live`, and its only "caller" is a human pasting a URL into a browser — no
  header-carrying client exists for it) and `forgot_password.php`'s `e2e_token` (already
  `hash_equals`-gated and hard-`false` on live regardless of `APP_ENV`). Both stay on `?token=`.
- **Remaining manual steps (not automatable):**
  1. Add a `CRON_SECRET` GitHub Actions repo secret (value already in `config.live.php`).
  2. `workflow_dispatch` both new workflows once by hand, confirm green + expected log text
     (`Total races updated` / `Notification check complete`).
  3. Uncomment the `schedule:` block in both new workflow files, then **immediately** delete both
     Simply.com control-panel cron entries (same sitting — avoid an active race weekend window).
  4. After one full clean cycle (a Saturday qualifying window + ~24h of hourly notifications),
     remove the `?token=` shim from both cron scripts and redeploy.
  5. Scrub existing access logs of the old `?token=` values if feasible.
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

## F9 — SMTP header injection (latent) — **Low**
`smtp.php:209,228-229` concatenate `$to`/`$subject` into SMTP data with no CRLF filtering.
- **Status:** not currently exploitable — every caller validates the address via `sanitizeEmail`
  (`functions.php:16`, `FILTER_VALIDATE_EMAIL` rejects CRLF) and subjects are app-controlled strings.
- **Fix (hardening):** strip `\r`/`\n` from `$to` and `$subject` at the SMTP layer regardless of caller.
- **Effort:** low. **Files:** `smtp.php`.

## F10 — Bet driver IDs not validated against `drivers` — **Low**
`validateBetCombination` (`functions.php:416-432`) checks non-empty / distinct / not-quali / not-dupe,
but not that `p1/p2/p3` are real IDs in `drivers`. A crafted POST to `bet.php`/`edit_bet.php` stores
arbitrary strings (low impact: junk never scores and `profile.php` inner-joins `drivers` so it drops).
- **Fix:** verify the three IDs exist in `drivers` for the race before insert.
- **Effort:** low. **Files:** `functions.php`, `bet.php`, `edit_bet.php`.

## F11 — Predictable identifiers — **Low**
`generateUUID()` uses `mt_rand`, not a CSPRNG, so exposed UUIDs are guessable. Object fetches are
correctly scoped by `WHERE user_id = ?` today, so treat this as defense-in-depth.
- **Fix:** build UUIDs from `random_bytes`.
- **Effort:** low. **Files:** `functions.php` (keep every object fetch ownership-scoped).

## F12 — Weak password policy / session hygiene — **Low / Info**
- Minimum password length is 6 across register / reset / change.
- No idle or absolute **session timeout**; changing the password does **not** revoke other active
  sessions (`profile.php:30-51`).
- CSP allows `style-src 'unsafe-inline'` (`includes/header.php`).
- **Fix:** raise the min length + basic strength check; add a session timeout and a "log out other
  sessions" on password change; move inline styles to nonce'd blocks to drop `'unsafe-inline'`.
- **Effort:** low–medium. **Files:** `register.php`, `reset_password.php`, `profile.php`, `header.php`.

---

## Suggested order if you do a second pass
1. ~~**F8** (SMTP TLS) — small change, real MITM exposure, worsened by shared `SMTP_PASS`.~~ ✅ Fixed
2. ~~**F7** (rate-limiting / lockout) — brute-force resistance for login + MFA.~~ ✅ Fixed
3. **F6** (tokens out of URLs) — removes the last log-exposure of the seed/cron tokens.
4. **F12 / F9 / F10 / F11** — low-risk hardening, batch them together.

Not security-blocking for the live deploy of F1–F5. F6 is next up; F9–F12 remain open, low-risk.
