# Common Gotchas

## Contents

- [1. config.test.php and config.live.php are not in the repo](#1-configtestphp-and-configlivephp-are-not-in-the-repo)
- [2. config.shared.php must be deployed](#2-configsharedphp-must-be-deployed)
- [3. BASE_URL_LIVE in GitHub must be a variable, not a secret](#3-base_url_live-in-github-must-be-a-variable-not-a-secret)
- [4. Integration tests destroy the test database](#4-integration-tests-destroy-the-test-database)
- [5. db-restore.php is excluded from live by default](#5-db-restorephp-is-excluded-from-live-by-default)
- [6. trim() vs sanitizeString() in admin forms — an intentional asymmetry](#6-trim-vs-sanitizestring-in-admin-forms--an-intentional-asymmetry)
- [7. php-config.js only reads string defines](#7-php-configjs-only-reads-string-defines)
- [8. Always use www in URLs](#8-always-use-www-in-urls)
- [9. Cron scripts require a token — 403 Forbidden is not a server error](#9-cron-scripts-require-a-token--403-forbidden-is-not-a-server-error)
- [10. Session regenerate_id() is called on login](#10-session-regenerate_id-is-called-on-login)
- [11. Log directory must be writable](#11-log-directory-must-be-writable)
- [12. in_competition = 0 for the admin user](#12-in_competition--0-for-the-admin-user)
- [13. quali_p1/p2/p3 must match exact bet validation](#13-quali_p1p2p3-must-match-exact-bet-validation)
- [14. Nightly report emails appear twice when SMTP_FROM and REPORT_TO share the same Proton account](#14-nightly-report-emails-appear-twice-when-smtp_from-and-report_to-share-the-same-proton-account)
- [15. sync:live rewrites all user emails to @hpovlsen.dk](#15-synclive-rewrites-all-user-emails-to-hpovlsendk)
- [16. MFA requires MFA_KEY in config, and MFA tables use the legacy latin1 collation](#16-mfa-requires-mfa_key-in-config-and-mfa-tables-use-the-legacy-latin1-collation)
- [17. Test env sends email by default — interception is opt-in](#17-test-env-sends-email-by-default--interception-is-opt-in-e2e-turns-it-on-per-run)
- [18. Migrations are manual per environment — the deploy schema check catches forgotten ones](#18-migrations-are-manual-per-environment--the-deploy-schema-check-catches-forgotten-ones)
- [19. The test-environment banner is gated by APP_ENV — never loosen the guard](#19-the-test-environment-banner-is-gated-by-app_env--never-loosen-the-guard)
- [20. Passkeys are bound to PASSKEY_RPID — a one-way door per environment](#20-passkeys-are-bound-to-passkey_rpid--a-one-way-door-per-environment)

---

Issues that tend to catch new developers. Read this before your first deploy.

---

## 1. `config.test.php` and `config.live.php` are not in the repo

Both files are gitignored. If you clone fresh and try to deploy, you'll get a "file not found" error from `php-config.js`. See [Getting Started → Step 2](getting-started.md#2-create-your-config-files) for the setup steps.

---

## 2. `config.shared.php` must be deployed

`config.shared.php` is committed to git and is uploaded by the deploy script automatically. If you ever manually upload PHP files (e.g. via FTP GUI), remember to also upload `config.shared.php` to the server root alongside `config.php`, or every page will fail with a fatal "require_once: failed to open stream" error.

The deploy script (`build-deploy/deploy.js`) handles this automatically — it explicitly uploads `config.shared.php` after uploading `public/`.

---

## 3. `BASE_URL_LIVE` in GitHub must be a variable, not a secret

The workflow references it as `${{ vars.BASE_URL_LIVE }}`. If stored as a secret instead, the expression evaluates to empty string and tests silently use the hardcoded fallback URL — a fragile situation. See [GitHub Actions → Variables vs Secrets](github-actions.md#variables-vs-secrets--migration) for the full explanation and migration steps.

---

## 4. Integration tests destroy the test database

`npm run test:integration` calls `tools/test-seed.php` before running, which wipes the test database and replaces it with 5 races of synthetic data (3 fake users, 10 drivers, 15 bets).

**Never run integration tests against live.** The tool is excluded from live deploys by `.deployignore.live`, but if you ever temporarily deploy it to live for debugging, remove it immediately after.

After running integration tests, the test database contains fake data. Run `npm run sync:live` to restore real data if needed.

---

## 5. `db-restore.php` is excluded from live by default

`tools/db-restore.php` is not deployed to the live server (it's in `.deployignore.live`). You must temporarily add it, restore, then remove it again. The full procedure is in [Deployment → Database restore](deployment.md#database-restore).

Leaving it on live is a security risk — anyone with `CRON_SECRET` can overwrite the live database.

---

## 6. `trim()` vs `sanitizeString()` in admin forms — an intentional asymmetry

Values stored in the database (race names, locations, settings text) go through `trim()` on save and `escape()` on output. Using `sanitizeString()` (which calls `htmlspecialchars`) on the way into the database would cause double HTML-encoding when `escape()` is applied on output.

`sanitizeString()` is correct for values that are displayed directly without a subsequent `escape()` call. `sanitizeEmail()` is correct for email addresses — it validates format, not just trims.

---

## 7. `php-config.js` only reads string defines

`build-deploy/php-config.js` parses PHP files with a simple regex that matches single-quoted string constants:
```
define('KEY', 'value')
```

It does **not** parse:
- Numeric defines: `define('SMTP_PORT', 587)` → returns `null`
- Boolean/null defines: `define('DEBUG', true)` → returns `null`
- Double-quoted strings: `define("KEY", "value")` → returns `null`

If a Node.js script needs a numeric value, read it from `process.env` or hardcode it in the script.

---

## 8. Always use `www` in URLs

`SITE_URL` in both config files must use `www` (e.g. `https://www.formula-1.dk`, not `https://formula-1.dk`). Apache redirects bare domain → www with a 301, but 301 redirects cause browsers to drop POST bodies. Any POST to a non-www URL will fail silently.

---

## 9. Cron scripts require a token — `403 Forbidden` is not a server error

The cron scripts (`import_qualifying.php`, `notifications.php`) return an error and exit immediately if the `CRON_SECRET` token is missing or wrong. If you open them in a browser without the token, you get a generic error response, not a 403 HTTP status, but the effect is the same.

Since F6, the token is sent via the `Authorization: Bearer <CRON_SECRET>` header, not a URL query string — a plain browser visit can't set that, so use `npm run test:e2e:test` or a `fetch()`/Node one-liner instead (see `docs/cron-jobs.md` → "Triggering manually"). Both cron scripts currently also still accept the old `?token=<CRON_SECRET>` query string as a **temporary** compatibility shim while their trigger migrates from Simply.com's control-panel cron to GitHub Actions (`security-findings-remaining.md` F6) — don't rely on it, it's slated for removal once that migration finishes.

---

## 10. Session `regenerate_id()` is called on login

After a successful login, `session_regenerate_id(true)` is called. This invalidates the old session ID and creates a new one. If you are writing a test that logs in and then asserts session-dependent state, the session cookie in the test browser will be automatically updated. This is correct security behaviour — do not disable it.

---

## 11. Log directory must be writable

`public/logs/` must be writable by the PHP process. On a shared host this is usually world-writable (`777`) or group-writable depending on the host's setup.

If logs aren't being written, check permissions:
```bash
# Via FTP or SSH
chmod 755 public/logs
```

The logs are protected from direct HTTP access by `public/logs/.htaccess`.

---

## 12. `in_competition = 0` for the admin user

The service admin account (`F1_ADMIN_EMAIL`) has `in_competition = 0` in the database. This means the admin does not appear in the leaderboard and cannot place bets. This is intentional — the admin account is for management only, not for playing.

If you want an admin who also plays, create a separate regular-user account and grant it the `admin` role, or create a second user account for actual betting.

---

## 13. `quali_p1/p2/p3` must match exact bet validation

When qualifying results are entered, the bet form shows an error if the user's selected P1/P2/P3 exactly matches the qualifying order (the qualy-match rule). This validation compares driver IDs, not names. If you add qualifying results to a race in the admin panel, the P1/P2/P3 fields must be driver IDs from the `drivers` table — not display names.

The admin UI's qualifying fields use the same driver dropdowns as the bet form, so this should not be an issue in practice, but be aware of it if you write DB seeds manually.

---

## 14. Nightly report emails appear twice when `SMTP_FROM` and `REPORT_TO` share the same Proton account

`SMTP_FROM` is `info@formula-1.dk` and `REPORT_TO` is `f1_admin@helvegpovlsen.dk` — both resolve to addresses on the same Proton Mail account. Proton treats this as a self-send and creates two copies: one stored as a sent item under `info@formula-1.dk` and one delivered to `thomas@helvegpovlsen.dk`. Any Proton filter that matches on subject will catch both copies and move them to the same folder, making it look like the email was sent twice.

There is no incoming-only condition available in Proton's simple filter builder, so the duplicate cannot be eliminated by a filter alone. The fix is to either change `SMTP_FROM` to an address outside this Proton account, or change `REPORT_TO` to an external address (e.g. Gmail).

**Note:** Simply.com's mail servers also appear to act as an SMTP relay/fallback for the domain. Bounce messages from Simply.com (`localsmtp.web.simply.com`) after mail routing changes indicate that Simply.com may relay mail for `formula-1.dk` independently of the Proton MX records — for example via a mail alias or forwarding rule set up in the Simply.com control panel. Check Simply.com → Email → Forwarders when debugging unexpected mail routing.

---

## 15. `sync:live` rewrites all user emails to `@hpovlsen.dk`

When `npm run sync:live` copies the live database into test, every user email whose domain is not already `hpovlsen.dk` has its domain rewritten, local-part preserved: `thomas@helvegpovlsen.dk` becomes `thomas@hpovlsen.dk`, `user@gmail.com` becomes `user@hpovlsen.dk`, and so on. `hpovlsen.dk` is a domain Djarnis owns with catch-all alias forwarding enabled, so every synced user's mail lands in the same real inbox regardless of their original local-part — this is deliberate: it lets MFA challenges (email OTP) and other email content be verified by hand for accounts copied from production, without digging through the SMTP intercept log. It also means no rewritten address can ever collide with an actual third-party player's real inbox.

The admin account (`F1_ADMIN_EMAIL`, currently `f1_admin@helvegpovlsen.dk`) is preserved unchanged — it is saved before the sync wipe and restored afterward.

Unless SMTP intercept is on, emails to synced users **are sent for real** (captured only if you flip **Admin → Settings → Email delivery** to capture, or `touch /tmp/f1betting_smtp_intercept`). See [testing.md](testing.md). This is intentional for manual testing — but be aware that triggering a bulk action (e.g. running the notification cron) against a large synced user set will fire that many real emails at once into the same inbox.

Automated E2E fixture addresses (`e2e_*_f1@test.localhost`, seeded by `test-seed.php`) are unaffected by this — they keep using `@test.localhost` since those are only ever read back via the SMTP intercept log, not a real inbox, and `test-seed.php`'s destructive actions (`cleanup_passkeys`, `set_passkey_sign_count`) gate on that domain specifically so they can never match a synced or manually created account.


---

## 16. MFA requires `MFA_KEY` in config, and MFA tables use the legacy `latin1` collation

The multi-factor auth system (`public/includes/mfa.php`) seals TOTP secrets at rest with a new config constant **`MFA_KEY`** — exactly 64 hex chars (32 bytes). It must be present in `config.test.php` and `config.live.php` (on the server too), alongside `PASSWORD_PEPPER`. `mfa.php` throws if it is missing or malformed — by design, so a misconfigured deploy fails loud instead of storing unsealed secrets. Generate with `php -r "echo bin2hex(random_bytes(32));"`.

The `users` table is legacy **`latin1_swedish_ci`**. New MFA tables (`user_totp`, `user_recovery_codes`, `user_email_otp`, `user_passkeys`) therefore pin their `user_id` foreign-key columns to `CHARACTER SET latin1 COLLATE latin1_swedish_ci` — otherwise the FK fails with error 3780 ("incompatible columns"). Keep this in mind for any future table that references `users.id`.

Apply the migration with `database/add_mfa.sql` (idempotent except the additive `users.email_otp_enabled` and `users.mfa_default_method` columns, which error harmlessly on re-run). Passkeys use the **vendored** lbuchs/WebAuthn library in `public/includes/webauthn/` — no Composer — and require the `PASSKEY_RPID` config constant (see gotcha #20).

**The preferred method leads the challenge.** The challenge screen (`mfa_challenge.php`) opens on the member's preferred method — `getMfaDefaultMethod()`, i.e. `users.mfa_default_method` if that factor is still active, else the fallback priority **passkey → totp → email**. Passkey is only the *default* preference (it's first in that priority), so an explicit preference of `totp`/`email` **wins** and opens on top; passkey then drops to the "Other options" list. Every other active method plus recovery is always rendered as a hidden panel and reachable one tap away via each panel's "Other options" list (client-side swap, no round trip). The passkey CTA is feature-detected (`passkey.js` reveals `[data-passkey-supported]`); on a device that can't use it the button hides, an "use another method" note shows, and the Other options carry the member through. Passkey on the challenge reverses the v3.0.0 removal.

**On-demand email + instant boxes:** the email OTP is **only** auto-sent at login when the resolved preferred method is `email` (`login.php`), so it's already waiting as the email panel opens. For any other preference no code is emailed until the member picks email from an "Other options" list — and that pick swaps to the boxes **immediately** while `mfa.js` fires the send in the background (never block the view on SMTP). So don't assume a login with email OTP active always sends a code, and don't reintroduce a blocking full-page POST for the email pick.

**⚠️ The automation admin account must NOT have MFA enrolled.** `build-deploy/deploy.js` smoke authed checks and `tests/global-setup.js` both log in as `F1_ADMIN_EMAIL` with a plain email+password POST. If that account has any active factor, login stops at `/mfa_challenge.php`, no session is granted, and **every authed smoke check + the entire E2E run fails** (global-setup can't save `admin.json`). If a deploy suddenly fails on `GET /profile.php [authed] → 302` or E2E dies in setup, check whether someone enrolled MFA on the admin account while testing — disable it (Profile → Security) to restore automation.

---

## 17. Test env sends email by default — interception is opt-in (E2E turns it on per run)

On the test environment `config.test.php` sets `SMTP_INTERCEPT = true`, which makes the environment *capable* of interception but does **not** enable it — **real delivery is the default**, so manual testing (e.g. sending an invite) just works. Interception is active only while the flag file `sys_get_temp_dir()/f1betting_smtp_intercept` is present.

- **E2E**: `tests/global-setup.js` turns interception **on** (`action=smtp_intercept_on`) for the run so specs capture email to the JSONL store; `tests/global-teardown.js` turns it **off** (`smtp_intercept_off`) at the end, restoring the send-by-default state.
- **Manual capture**: flip **Admin → Settings → Email delivery** to "Switch to capture" (and back). The shared helpers are `emailIntercepted()` and `smtpInterceptFlagPath()` in `public/includes/smtp.php`.
- **Live**: `SMTP_INTERCEPT` is undefined, so email always sends and the toggle is hidden.

`npm run test:resend` reads `RESEND_API_KEY` / `SMTP_FROM` / `REPORT_TO` from env vars if present, otherwise falls back to `config.<env>.php` (RESEND_API_KEY, SMTP_FROM_EMAIL, REPORT_TO→F1_ADMIN_EMAIL) — so it runs locally without a `build-deploy/.env`.

---

## 18. Migrations are manual per environment — the deploy schema check catches forgotten ones

Migrations (`database/*.sql` and inline `ALTER`s in `schema.sql`) are applied by hand in phpMyAdmin on each environment. Deploy code that references a not-yet-added column and you get a runtime fatal (e.g. `Unknown column 'quali_date'`), not a deploy failure — unless the object is registered for checking.

`deploy.js` guards this: after upload it POSTs `database/migrations.json` to `public/tools/schema-check.php`, which introspects the target DB. Missing objects fail the deploy (and roll back on live) with the exact migration file(s) to run. **When you add a migration, add the tables/columns it introduces to `database/migrations.json`** or the check can't see them. See `build-deploy/DEPLOYMENT.md → Schema check`.

---

## 19. The test-environment banner is gated by `APP_ENV` — never loosen the guard

`public/includes/header.php` renders a yellow "Dette er en testhjemmeside" banner only when `APP_ENV === 'test'`. The banner is only ever allowed on hpovlsen.dk — never formula-1.dk (owner decision, 2026-07-05). The guard is server-side config, deliberately **not** `$_SERVER['HTTP_HOST']` (client-controlled). Don't remove the guard, don't switch it to Host-header sniffing, and don't raise the banner's `z-index` above the nav drawer's 30. The `deploy:live` E2E gate (`tests/e2e/01-smoke.spec.js`) asserts the banner is absent on live and rolls back the deploy if it isn't. Full spec: `epics/design_handoff_test_banner/`.

---

## 20. Passkeys are bound to `PASSKEY_RPID` — a one-way door per environment

Every passkey is cryptographically bound to the WebAuthn relying-party id: the **registrable domain**, `hpovlsen.dk` (test) / `formula-1.dk` (live), set as `PASSKEY_RPID` in each config. **Changing it after members have registered orphans every passkey silently** — logins just stop working. That's why `passkeyRpId()` (`public/includes/passkey.php`) fails loud unless the constant is present *and* matches the domain derived from `SITE_URL`: a config edit that changes the domain becomes an immediate error, not silent orphaning.

Consequences to keep in mind:

- **Test and live credentials are not interchangeable** — a passkey registered on hpovlsen.dk can never sign in on formula-1.dk, and vice versa.
- **`sync:live` clears `user_passkeys` on the test copy** (`sync-from-live.php`, verified fail-loud by `sync.js`). Live rows would be unusable on test *and* would gate those members' test logins behind a factor that cannot be satisfied — `passkeyActive()` feeds `userHasActiveFactor()`, which triggers the two-step login.
- **Registration and challenge verification must always ship together.** A member's *first* `user_passkeys` row immediately gates their password login, so a deploy that carries registration without the `mfa_challenge.php` passkey block + `webauthn.php` verify actions locks that member down to recovery codes.
- **Sign counts are advisory.** Most platform authenticators always report 0; the clone check in `passkeyAssertVerify()` only rejects when both stored and new counters are non-zero. Don't "harden" it into a lockout — you'd lock out every iCloud/Google-synced passkey.
- The vendored library is pinned in `public/includes/webauthn/VERSION`; bump it only via the documented update procedure (re-copy, diff, rerun `tests/unit/passkey-harness.php` + the auth E2E suite).
