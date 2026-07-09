# Security Review Log

## Contents

- [2026-05-21 — Initial review (first monthly report)](#2026-05-21--initial-review-first-monthly-report)
  - [Findings](#findings)
  - [Actions taken](#actions-taken)
  - [Expected result for next monthly report](#expected-result-for-next-monthly-report)
- [2026-07-05 — Ad-hoc review of token-gated endpoints and cron auth (F1–F12)](#2026-07-05--ad-hoc-review-of-token-gated-endpoints-and-cron-auth-f1f12)
  - [Findings](#findings-1)
  - [Actions taken](#actions-taken-1)
  - [Deferred (F6–F12)](#deferred-f6f12)
- [2026-07-09 — Second pass on deferred findings: F7 and F8 fixed](#2026-07-09--second-pass-on-deferred-findings-f7-and-f8-fixed)
  - [Actions taken](#actions-taken-2)
  - [Still deferred (F6, F9–F12)](#still-deferred-f6-f9f12)
- [Template for future entries](#template-for-future-entries)

---

Each entry records what was examined, what was found, and what action was taken.
Add a new entry after each monthly report or any ad-hoc review.

---

## 2026-05-21 — Initial review (first monthly report)

**Reviewer:** Claude (assisted) + Thomas  
**Trigger:** First run of `monthly-security-review.yml` GitHub Actions workflow  
**Report:** `build-deploy/security-reports/2026-05-21-security-review.html`

### Findings

#### False positives — labeling gaps in security.js (fixed)

| Item | Root cause | Fix applied |
|---|---|---|
| A03 Injection | Section L named `'CWE Top 25 (Web)'` — parser didn't find `'OWASP A03'` | Renamed to `'CWE Top 25 / Injection (OWASP A03)'` |
| A08 Software/Data Integrity | Section K named `'Application Hardening'` — parser didn't find `'OWASP A08'` | Renamed to `'Application Hardening (OWASP A08)'` |
| CWE-862 Missing Authorization | Mentioned in a code comment on line 804 but not as a quoted string in any test call | Added `'CWE-862'` as the CWE reference on auth-guard fail/warn calls in section D |
| CWE-863 Incorrect Authorization | Not referenced at all | Added `'CWE-863'` to privilege-escalation test in section L |
| CWE-269 Improper Privilege Management | Only appeared inside larger string literals — regex `/'(CWE-\d+)'/g` requires standalone quoted form | Added second `warn()` call in section L 200-case with `'CWE-269'` as CWE param |
| OWASP A10 (SSRF) | Missing from `OWASP_EXEMPT` — bogus `'CWE-10'` key in `CWE_EXEMPT` never filtered anything | Moved to `OWASP_EXEMPT`; removed invalid `'CWE-10'` from `CWE_EXEMPT` |

#### Not applicable to this app (documented as exemptions)

| Item | Rationale |
|---|---|
| A04 Insecure Design | Architectural/threat-modelling concern; not testable via HTTP scanner |
| A09 Security Logging & Monitoring | Satisfied by `app.log`, `csp-report.php`, and the nightly/monthly GitHub Actions reports. Not automatable via HTTP |
| CWE-502 Deserialization | App uses `json_decode()`, never PHP `unserialize()` on user input |
| CWE-77 Command Injection | Grep confirmed no `exec()`/`shell_exec()`/`system()`/`passthru()` in user-facing PHP |
| CWE-798 Hard-coded Credentials | SAST concern; credentials live in gitignored `config.php`. Not testable via HTTP scanner |
| CWE-918 SSRF | Only outbound HTTP calls are to hardcoded URLs (`api.resend.com`, `api.jolpi.ca`). No user-controlled URL fetching |
| CWE-94 Code Injection | No `eval()` or dynamic `include` on user input anywhere in the codebase |

### Actions taken

- Fixed 6 labeling/coverage issues in `tests/security/security.js`
- Added `OWASP_EXEMPT` and `CWE_EXEMPT` maps to `build-deploy/security-review.js`; exemptions are now shown in the monthly email report with their rationale
- Added `A10` (SSRF) to `OWASP_EXEMPT`; removed bogus `'CWE-10'` key that was never filtering anything
- Updated `CWE_TOP25_WEB` comment with review date
- Baseline: OWASP 2021, CWE Top 25 2024 (web-relevant subset, 16 items)

### Expected result for next monthly report

- OWASP gaps: **none** (A04 and A09 are exempt)
- CWE gaps: **none** (all remaining items either covered or exempt)

---

## 2026-07-05 — Ad-hoc review of token-gated endpoints and cron auth (F1–F12)

**Reviewer:** Claude (assisted) + Thomas
**Trigger:** Ad-hoc code-level review of every endpoint that trusts a bare token (`?token=…`) instead of a session — cron scripts, backup/seed tools, the e2e password-reset backdoor.
**Report:** No HTML report (not a monthly workflow run). Findings tracked directly in commits and in `security-findings-remaining.md` (repo root).

### Findings

Twelve findings, numbered F1–F12 by severity (Critical/High first). F1–F5 were fixed immediately; F6–F12 (Medium/Low) were deferred.

| # | Issue | Severity |
|---|---|---|
| F1 | `forgot_password.php`'s e2e reset-link backdoor was reachable by token alone, with no environment check | Critical |
| F2 | `db-backup.php` token compare used `===`/`==` (timing side-channel); the backup dump included `password_resets` (live reset tokens at rest in backup artifacts) | High |
| F3 | `seed_f1_admin.php` token compare was not constant-time and the tool was deployable to live | High |
| F4 | `import_qualifying.php`'s `?test=true` bypassed `CRON_SECRET` entirely, not just the data source | High |
| F5 | `INTEGRATION_SEED_TOKEN` is shared between test and live config | Medium |
| F6–F12 | Tokens in URL query strings; weak MFA/login rate limiting; SMTP TLS verification disabled; latent SMTP header injection; unvalidated bet driver IDs; predictable UUIDs (`mt_rand`); weak password policy / no session timeout | Medium/Low — see [Deferred](#deferred-f6f12) |

### Actions taken

- `forgot_password.php`: backdoor now gated on `APP_ENV === 'test'` **and** `hash_equals()` against `INTEGRATION_SEED_TOKEN` — can never disclose a reset link on live regardless of token (F1)
- `db-backup.php`: `hash_equals()` token compare; `password_resets` dropped from the dump so reset tokens never sit in backup artifacts (F2)
- `seed_f1_admin.php`: `hash_equals()` token compare; excluded from live deploy via `.deployignore.live` (F3)
- `import_qualifying.php`: `CRON_SECRET` is now always required; `?test=true` only selects stub data, no longer bypasses auth; `hash_equals()` compare (F4)
- E2E: added a case asserting `?test=true` alone is rejected; the import test now supplies a valid token
- F5 (token de-sharing) is config-only (gitignored `config.*.php`), not code — tracked but not part of any commit
- Commits: `dd4c1ed` (F1–F4), `34ac54f` (F6–F12 working notes)

**Convention introduced:** every bare-token comparison in the codebase now uses `hash_equals()`, never `===`. See [patterns.md → Token Comparison](patterns.md#token-comparison-constant-time) for new code.

### Deferred (F6–F12)

Tracked in `security-findings-remaining.md` (repo root, not `docs/` — a working file, delete or fold into a tracker once addressed). Summary, ordered by the suggested next-pass priority in that file:

1. **F8** — SMTP TLS certificate verification disabled (`verify_peer=false` in `smtp.php`) — real MITM exposure, worsened by `SMTP_PASS` being shared test↔live — ✅ fixed 2026-07-09, see below
2. **F7** — Login/MFA rate limiting has no per-account lockout, fails open on DB error, and shares one IP bucket between login and the MFA challenge — ✅ fixed 2026-07-09, see below
3. **F6** — Tokens still travel in URL query strings (land in access logs / `Referer` headers) even though the compares are now constant-time
4. **F12 / F9 / F10 / F11** — weak password policy + no session timeout, latent SMTP header injection (not currently exploitable), unvalidated bet driver IDs, `mt_rand`-based UUIDs — batch as low-risk hardening

None of F6–F12 block a live deploy; F1–F5 were the release gate for this review.

---

## 2026-07-09 — Second pass on deferred findings: F7 and F8 fixed

**Reviewer:** Claude (assisted) + Thomas
**Trigger:** Working through the next-pass priority order set in the 2026-07-05 entry (`security-findings-remaining.md` → "Suggested order if you do a second pass").
**Report:** No HTML report (not a monthly workflow run). Findings tracked in commits and in `security-findings-remaining.md`.

### Actions taken

- **F8 — SMTP TLS certificate verification disabled.** `smtp.php`: `verify_peer`/`verify_peer_name` now `true`, `allow_self_signed` now `false`, and an explicit `peer_name` (the configured `SMTP_HOST`) is set on the context — needed because the STARTTLS path connects via plain `tcp://` first, so PHP has no scheme-derived hostname to check against at the point `stream_socket_enable_crypto` runs. Verified against the real `smtp.protonmail.ch:587` STARTTLS handshake (cert `CN=protonmail.com`, SAN includes `*.protonmail.ch`) — TLS now validates successfully; previously it silently accepted anything. Commit `99ad521`.
- **F7 — Login/MFA rate limiting.** `login_attempts` gained `scope` (`login` \| `mfa`) and `account` columns (migration `database/add_login_attempts_scope.sql`, registered in `migrations.json`). `isRateLimited()` / `recordLoginAttempt()` / `clearLoginAttempts()` (`functions.php`) now check **both** an IP bucket and an account bucket per scope, so a distributed attack on one victim is caught across IPs while a shared/NAT'd IP stays usable for everyone else once its real owner logs in. `login.php`, `mfa_challenge.php`, and `webauthn.php` all fail **closed** on a DB error now (previously `catch { $rateLimited = false; }`), and a success only clears that account's own counter — never the whole IP's — so an attacker can no longer reset a shared IP's budget by logging into their own account mid-attack. `login` (password + passwordless passkey) and `mfa` (code/passkey challenge + resend) are fully separate buckets, so exhausting one never blocks the other; MFA's account threshold is stricter (3 vs. 5) since an OTP/TOTP code has a far smaller keyspace than a password. Verified the new thresholds against the E2E auth suite's incidental MFA failures (`tests/e2e/auth/30-totp-mfa.spec.js`, `31-email-otp.spec.js`, `32-mfa-default-method.spec.js`, `35-passkey.spec.js`) so CI, which shares one runner IP across all of them, doesn't get spuriously rate-limited. Commit `9eaaa16`.
- Both entries in `security-findings-remaining.md` updated to "✅ Fixed" with implementation detail; the "Suggested order" list there struck through.

### Still deferred (F6, F9–F12)

Same priority order as the 2026-07-05 entry, minus F7/F8:

1. **F6** — Tokens still travel in URL query strings — next up.
2. **F12 / F9 / F10 / F11** — weak password policy + no session timeout, latent SMTP header injection (not currently exploitable), unvalidated bet driver IDs, `mt_rand`-based UUIDs — batch as low-risk hardening.

None of these block a live deploy.

---

## Template for future entries

```
## YYYY-MM-DD — [Monthly / Ad-hoc]

**Reviewer:**
**Trigger:** Monthly workflow run / [other reason]
**Report:** build-deploy/security-reports/YYYY-MM-DD-security-review.html

### Findings

[List any new gaps, false positives, or changed items]

### Actions taken

[What was fixed, added, or updated]

### Notes

[Anything worth remembering for next time — e.g. OWASP announced a new version]
```
