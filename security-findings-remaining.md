# Remaining Security Findings (F6–F12) — Medium / Low

Working file — the deferred items from the security review. F1–F5 (Critical/High) are fixed and in
`main`; these are the lower-severity items still open. Delete or fold into a tracker when addressed.

Severity: Medium / Low / Info. "Status" reflects what the F1–F5 work already changed incidentally.

---

## F6 — Secrets & tokens transmitted in URL query strings — **Medium**
Tokens travel in `?token=…` on `db-backup.php`, `schema-check.php`, `seed_f1_admin.php`, `cron/*`, and
the `forgot_password` e2e param, so they land in web-server/proxy access logs and `Referer` headers.
- **Status:** partially mitigated — all these compares are now `hash_equals` (done in F2–F4), so the
  timing side-channel is closed, but the tokens are still in URLs.
- **Fix:** accept the token via a header (e.g. `Authorization: Bearer`) or POST body instead of the
  query string; update the callers (`build-deploy/backup.js`, `.github/workflows/nightly-backup.yml`,
  `build-deploy/schema-check.js`, `restore-db.js`, Simply cron). Scrub existing access logs.
- **Effort:** medium (touches CI + cron config). **Files:** `public/tools/*.php`, the Node callers.

## F7 — Login / MFA rate limiting is weak — **Medium**
`public/includes/functions.php:391-408`: per-IP counter, threshold `>=5`/15 min (comment still says
"3" — drift), and `login.php:33-36` **fails open** on a DB exception while `:51` clears the IP's
counter on any successful login. Uses `REMOTE_ADDR` (so *not* `X-Forwarded-For`-spoofable), but there
is no per-account lockout, and the same IP bucket is shared with the MFA challenge.
- **Fix:** add per-account throttling/lockout; fail closed on error; don't fully reset the counter on
  success; give the MFA step its own stricter budget; fix the "3 vs 5" comment.
- **Effort:** medium. **Files:** `functions.php`, `login.php`, `mfa_challenge.php`.

## F8 — SMTP TLS certificate verification disabled — **Medium**
`public/includes/smtp.php:87-90`: `verify_peer=false`, `verify_peer_name=false`,
`allow_self_signed=true`. The Proton STARTTLS upgrade then proceeds without validation, so an on-path
attacker could MITM and capture the base64 `AUTH LOGIN` credentials.
- **Fix:** enable `verify_peer`/`verify_peer_name`; pin or validate the Proton cert. Test send after.
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
1. **F8** (SMTP TLS) — small change, real MITM exposure, worsened by shared `SMTP_PASS`.
2. **F7** (rate-limiting / lockout) — brute-force resistance for login + MFA.
3. **F6** (tokens out of URLs) — removes the last log-exposure of the seed/cron tokens.
4. **F12 / F9 / F10 / F11** — low-risk hardening, batch them together.

Not security-blocking for the live deploy of F1–F5.
