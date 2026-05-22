# CLAUDE.md

## Contents

- [What this project is](#what-this-project-is)
- [Infrastructure at a glance](#infrastructure-at-a-glance)
- [Commands](#commands)
  - [Deploy](#deploy)
  - [Test](#test)
- [Architecture](#architecture)
- [PHP conventions](#php-conventions)
- [Key gotchas](#key-gotchas)

---

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

Formula 1 prediction game. Players pick the top-3 podium finishers (P1/P2/P3) before each race. Points are awarded per position, with bonus pool payouts for perfect predictions. Two environments: **test** (`www.hpovlsen.dk`) and **live** (`www.formula-1.dk`). Bilingual: Danish (default) and English, stored per user in the DB.

Full docs are in `docs/` — see `docs/architecture.md`, `docs/patterns.md`, `docs/gotchas.md`, `docs/testing.md`, `docs/github-actions.md`.

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

- **Backend**: Procedural PHP (no framework). Each page is a standalone `.php` file in `public/`.
- **Frontend**: Vanilla JS (`public/assets/js/app.js`), Bootstrap CSS. No build step.
- **Database**: MySQL. Schema at `database/schema.sql`.
- **Email**: `public/includes/smtp.php` — Proton Mail primary, Resend API fallback.
- **Deploy/test**: Node.js scripts in `build-deploy/` and `tests/`.
- **Config**: no single `config.php` — each page requires a per-env file (`config.test.php` / `config.live.php`). `config.shared.php` holds shared constants and is in git. `APP_ENV` is `'test'` or `'live'`.

See `docs/architecture.md` for request lifecycle, scoring, admin panel, cron jobs, and test seeding details. See `docs/github-actions.md` for CI workflows. See `docs/testing.md` for Mailsac and E2E test architecture.

---

## PHP conventions

See `docs/patterns.md` for the full reference.

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
- `setLang($db, $userId, $lang)` — updates both session and DB
- `logToFile($msg, $file)` — use constants from `config.shared.php` for the path

**Output escaping:** escape at render time with `htmlspecialchars()`; never pre-escape on input. Prepared statements for all DB writes. Every POST form needs `<?= csrfField() ?>` and every handler needs `requireCsrf()`.

---

## Key gotchas

Full list in `docs/gotchas.md`. The ones most likely to affect code changes:

- **Always use `www` in URLs** — Apache redirects non-www → www with a 301, which drops POST bodies.
- **Admin has `in_competition = 0`** — intentional. Never appears on the leaderboard or in pool calculations.
- **`quali_p1/p2/p3` are driver IDs** — not names. Mismatches silently fail scoring.
- **`config.shared.php` must be deployed** — it's in git but must be present on the server alongside `config.php`.
- **Nightly report email deduplication** — if `SMTP_FROM` and `REPORT_TO` share the same Proton Mail account, the email appears twice in the inbox.
