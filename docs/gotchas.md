# Common Gotchas

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

Always pass the token: `?token=<CRON_SECRET>`.

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

## 13. `.env.example` shows old keys

`build-deploy/.env.example` was written before the config consolidation and still lists `BASE_URL_TEST`, `BASE_URL_LIVE`, `TEST_USER_EMAIL_*`, `TEST_USER_PASSWORD_*`, `INTEGRATION_SEED_TOKEN`, and `CRON_SECRET`. These are no longer read from `.env` — they come from `config.*.php` via `php-config.js`.

The current `.env` needs only:
```
FTP_HOST, FTP_USER, FTP_PASS, FTP_ROOT_TEST, FTP_ROOT_LIVE, DRY_RUN
```

Run `npm run setup:deploy` to create a clean `.env` without stale keys.

---

## 15. Nightly report emails appear twice when `SMTP_FROM` and `REPORT_TO` share the same Proton account

`SMTP_FROM` is `info@formula-1.dk` and `REPORT_TO` is `f1_admin@helvegpovlsen.dk` — both resolve to addresses on the same Proton Mail account. Proton treats this as a self-send and creates two copies: one stored as a sent item under `info@formula-1.dk` and one delivered to `thomas@helvegpovlsen.dk`. Any Proton filter that matches on subject will catch both copies and move them to the same folder, making it look like the email was sent twice.

There is no incoming-only condition available in Proton's simple filter builder, so the duplicate cannot be eliminated by a filter alone. The fix is to either change `SMTP_FROM` to an address outside this Proton account, or change `REPORT_TO` to an external address (e.g. Gmail).

**Note:** Simply.com's mail servers also appear to act as an SMTP relay/fallback for the domain. Bounce messages from Simply.com (`localsmtp.web.simply.com`) after mail routing changes indicate that Simply.com may relay mail for `formula-1.dk` independently of the Proton MX records — for example via a mail alias or forwarding rule set up in the Simply.com control panel. Check Simply.com → Email → Forwarders when debugging unexpected mail routing.

---

## 16. `sync:live` rewrites all user emails to `@mailsac.com`

When `npm run sync:live` copies the live database into test, every user email that is not already `@mailsac.com` is rewritten: `thomas@helvegpovlsen.dk` becomes `thomas@mailsac.com`, `user@gmail.com` becomes `user@mailsac.com`, and so on. This prevents any email accidentally triggered on the test site from reaching real inboxes.

The admin account (`F1_ADMIN_EMAIL`, currently `f1_admin@helvegpovlsen.dk`) is preserved unchanged — it is saved before the sync wipe and restored afterward.

If you expect to trigger emails to synced users during manual testing, look them up in the Mailsac web UI at mailsac.com using the rewritten address.

---

## 14. `quali_p1/p2/p3` must match exact bet validation

When qualifying results are entered, the bet form shows an error if the user's selected P1/P2/P3 exactly matches the qualifying order (the qualy-match rule). This validation compares driver IDs, not names. If you add qualifying results to a race in the admin panel, the P1/P2/P3 fields must be driver IDs from the `drivers` table — not display names.

The admin UI's qualifying fields use the same driver dropdowns as the bet form, so this should not be an issue in practice, but be aware of it if you write DB seeds manually.
