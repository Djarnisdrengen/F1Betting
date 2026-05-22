# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

Formula 1 prediction game. Players pick the top-3 podium finishers (P1/P2/P3) before each race. Points are awarded per position, with bonus pool payouts for perfect predictions. Two environments: **test** (`www.hpovlsen.dk`) and **live** (`www.formula-1.dk`). Bilingual: Danish (default) and English, stored per user in the DB.

Full docs are in `docs/` — notably `docs/architecture.md`, `docs/patterns.md`, `docs/gotchas.md`.

## Infrastructure at a glance

| Concern | Provider |
|---|---|
| Hosting & DNS | Simply.com |
| Transactional email (primary) | Proton Mail (SMTP) |
| Transactional email (fallback) | Resend API — automatic fallback in `smtp.php` on SMTP failure |
| Test email interception | Mailsac — seeded test users use `@mailsac.com` addresses; owned inboxes are purged before each E2E run |

---

## Commands

All commands require environment config files (`config.test.php`, `config.live.php`) on disk — they are not in git.

### Deploy

```bash
npm run deploy:test          # upload to test server → run smoke + E2E tests
npm run deploy:live          # upload to live server → run smoke + E2E (01-smoke.spec.js only)
npm run sync:live            # copy live DB → test DB (rewrites emails to @mailsac.com)
```

Deploy includes automatic backup and rollback on test failure. Confirm prompts require typing `YES`.

### Test

```bash
npm run test:smoke           # HTTP endpoint checks (fast, no browser)
npm run test:unit            # Node mailer unit tests only
npm run test:e2e:test        # Playwright full suite against test env
npm run test:e2e:test:mailsac # Same suite with real SMTP + Mailsac delivery assertions
npm run test:e2e:live        # Playwright 01-smoke.spec.js only against live
npm run test:security        # OWASP headers/cookies/access control (test env)
npm run test:security:live   # Same against live
npm run test:all             # smoke + unit + e2e:test
```

**All tests run against a deployed server over HTTP — there is no local test server.**

To run a single Playwright spec:
```bash
DEPLOY_ENV=test npx playwright test tests/e2e/admin/10-content.spec.js --config tests/playwright.config.js
```

To run a single test by title:
```bash
DEPLOY_ENV=test npx playwright test --grep "create and delete a race" --config tests/playwright.config.js
```

---

## Architecture

### Stack

- **Backend**: PHP (no framework). Each page is a standalone `.php` file in `public/`.
- **Frontend**: Vanilla JS (`public/assets/js/app.js`), Bootstrap CSS. No build step.
- **Database**: MySQL. Schema at `database/schema.sql`.
- **Email**: `public/includes/smtp.php` — `SMTPMailer` class hits Proton Mail (primary); falls back to Resend API on failure. Translations live in `public/lang/email.php`.
- **Deploy/test**: Node.js scripts in `build-deploy/` and `tests/`.

### Config pattern

There is no single `config.php`. Each page does:
```php
require_once __DIR__ . '/../../config.php';  // path varies; always resolves to a per-env file
```

- `config.test.php` / `config.live.php` — secrets, DB credentials, SMTP, URLs. Not in git.
- `config.shared.php` — shared constants (logging, F1 API base URL, security headers). In git.
- `config.example.php` — template showing all required constants.

`APP_ENV` is `'test'` or `'live'` and is used to guard test-only tooling (e.g. `tools/test-seed.php` hard-refuses when `APP_ENV !== 'test'`).

### Request lifecycle

1. `.htaccess` sets security headers and rewrites clean URLs
2. PHP page includes `config.php` → `includes/functions.php` → `includes/header.php`
3. `getDB()` returns a PDO connection (singleton pattern inside the function)
4. `getCurrentUser()` reads `$_SESSION` — set at login with `session_regenerate_id(true)`
5. All user-facing output goes through `htmlspecialchars()` wrappers; DB writes use prepared statements
6. `requireCsrf()` and `requireAdmin()` are called at the top of protected pages

### Translation

```php
$lang = getLang();          // reads user's language from session/DB, returns 'da' or 'en'
t('key')                    // looks up in the active lang file; falls back to 'da'
```

Strings are in `public/lang/user.php`, `public/lang/admin.php`, `public/lang/email.php`.

### Scoring (`public/includes/scoring.php`)

`calculateRacePoints($raceId)` is the central function — call it after setting qualifying results. It:
- Reads all bets for the race
- Awards points per position read from the `settings` table (`points_p1`, `points_p2`, `points_p3`, `points_wrong_pos`); defaults 25/18/15/5 if unset
- Marks perfect bets and distributes the betting pool
- Updates the `users.points` column directly

### Admin panel (`public/admin.php` + `public/includes/admin/`)

The admin dashboard is split into tab-specific includes: `drivers.php`, `races.php`, `bets.php`, `invites.php`, `users.php`, `settings.php`. `admin.php` handles routing and shared auth, then `include`s the relevant tab file.

**Test mode in admin.php**: when `e2e_token` matches `INTEGRATION_SEED_TOKEN`, `$testMode = true`. Email-sending actions still send real emails but also emit debug markers (e.g. `[invite-sent] true`) surfaced via POST-redirect-GET through an `e2e_markers` base64 URL param. E2E tests assert these markers.

### Cron jobs (`public/cron/`)

- `import_qualifying.php` — fetches F1 API, updates qualifying results in DB
- `notifications.php` — sends open/close betting window emails to users

Both require `CRON_SECRET` as a bearer token. Called from server cron; also covered by `07-cron.spec.js`.

### E2E test seeding (`public/tools/test-seed.php`)

Used by Playwright tests to create deterministic fixtures. Protected by `INTEGRATION_SEED_TOKEN` **and** `APP_ENV === 'test'`. Actions: `create_e2e_user`, `seed_betting_race`, `seed_reset_result`, `seed_notification_open`, etc. — each is idempotent with a matching `cleanup_*` action.

---

## PHP conventions

See `docs/patterns.md` for the full reference. Critical points:

**Standard page opening sequence:**
```php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();          // or requireAdmin()
requireCsrf();           // on POST handlers
$db       = getDB();
$user     = getCurrentUser();
$settings = $db->query("SELECT * FROM settings LIMIT 1")->fetch();
$lang     = getLang();
```

**Never replicate these — use the helpers:**
- `getBettingStatus($race, $now)` — betting window open/closed logic; never inline
- `fetchDrivers($db)`, `getRaces($db)`, `getBetsByRace($db, $raceId)` — shared queries
- `generateUUID()` — all exposed primary keys (users, bets, invites, etc.)
- `hashPassword()` / `verifyPassword()` — password handling
- `t('key')` — all user-facing strings; never hardcode Danish/English inline
- `setLang($db, $userId, $lang)` — updates both session and DB; call when saving language preference
- `logToFile($msg, $file)` — use constants from `config.shared.php` for the path

**Config constants:** Always `define()` / PHP constants. Never `$_ENV` or `getenv()` in PHP.

**`php-config.js` bridge** (`build-deploy/php-config.js`): reads string `define()` constants from PHP config files for Node.js scripts. It cannot read numeric or boolean constants — those must be string-quoted in the PHP config if Node needs them.

**Output escaping:** escape at render time with `htmlspecialchars()`; do not pre-escape on input. Prepared statements for all DB writes.

**CSRF:** every POST form needs `<?= csrfField() ?>` and every handler needs `requireCsrf()`.

---

## Key gotchas

Full list in `docs/gotchas.md`. The ones most likely to affect code changes:

- **Always use `www` in URLs** — `www.formula-1.dk` and `www.hpovlsen.dk`, not bare domains. Apache redirects non-www → www, which drops POST bodies on 301.
- **Admin has `in_competition = 0`** — intentional. The admin account never appears on the leaderboard or in pool calculations.
- **`quali_p1/p2/p3` are driver IDs** — not names. Bet validation compares driver IDs; mismatches silently fail scoring.
- **`config.shared.php` must be deployed** — it's in git but must be present on the server alongside `config.php`.
- **Nightly report email deduplication** — if `SMTP_FROM` and `REPORT_TO` are on the same Proton Mail account, the email appears twice in the inbox (sent + received copy).

---

## GitHub Actions (`nightly.yml`)

One scheduled workflow runs nightly at 01:00 UTC via `build-deploy/nightly-report.js`. It:
- Runs E2E smoke tests + full security scan (including SSL Labs and rate-limit) against the **live** environment
- Emails an HTML summary report to `REPORT_TO` via SMTP/Resend
- Uploads the report as a GitHub Actions artifact (30-day retention)
- Can be triggered manually from the GitHub UI (`workflow_dispatch`)

Credentials come from GitHub repo Secrets/Variables — no `config.live.php` on the runner.

## Email testing (Mailsac)

5 owned Mailsac inboxes (`f1betting-preview`, `e2e_auth_f1`, `e2e_testing_invite_f1`, `e2e_bet_delete_f1`, `e2e_testing_testuser_f1`) are purged at test suite start and asserted after actions that send real emails. `MAILSAC_API_KEY` in `config.test.php` enables delivery assertions; tests skip cleanly if the key is absent.
