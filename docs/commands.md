# Command Reference

## Contents

- [Setup (run once)](#setup-run-once)
- [Deploy](#deploy)
- [Test](#test)
  - [Security tests](#security-tests)
- [Database](#database)
- [Backup & Rollback](#backup--rollback)
- [Cron (manual trigger)](#cron-manual-trigger)
- [Git](#git)
- [Utilities](#utilities)

---

All terminal commands for the F1 Betting project.

---

## Setup (run once)

| Command | What it does |
|---|---|
| `git clone https://github.com/<org>/F1Betting.git` | Clone the repository |
| `npm install` | Install Node.js dependencies |
| `cp config.example.php config.test.php` | Create test environment config |
| `cp config.example.php config.live.php` | Create live environment config |
| `npm run setup:deploy` | Interactive FTP setup — writes `build-deploy/.env` |
| `openssl rand -hex 32` | Generate a 32-hex secret (for PASSWORD_PEPPER) |

---

## Deploy

| Command | What it does |
|---|---|
| `npm run deploy:test` | Upload to test server + run smoke tests |
| `npm run deploy:live` | Upload to live server (requires typing `YES`) + run smoke + E2E |
| `npm run schema:check` | Introspect the test DB against `database/migrations.json`; lists missing tables/columns. Also runs automatically as part of every deploy — see [gotchas.md #18](gotchas.md#18-migrations-are-manual-per-environment--the-deploy-schema-check-catches-forgotten-ones) |
| `npm run schema:check:live` | Same, against the live DB |

---

## Test

| Command | What it does |
|---|---|
| `npm run test:smoke` | HTTP checks — key pages return 200 (test env) |
| `npm run test:unit` | Node built-in runner — mailer unit tests (no network, no browser) |
| `npm run test:e2e:test` | Playwright browser tests against hpovlsen.dk (email captured via SMTP intercept) |
| `npm run test:e2e:live` | Playwright browser tests against formula-1.dk — **requires YES** |
| `npm run test:email:preview` | Render all email types via SMTP intercept for manual visual review — writes HTML to `tests/email-previews/` (not pass/fail) |
| `npm run test:all` | Smoke + unit + E2E against test |

### Security tests

| Command | What it does |
|---|---|
| `npm run test:security` | OWASP scan against test |
| `npm run test:security:ratelimit` | + rate-limit check against test |
| `npm run test:security:ssllabs` | + SSL Labs TLS grade against test (60–90 s) |
| `npm run test:security:full` | All security checks against test |
| `npm run test:security:live` | OWASP scan against live — **requires YES** |
| `npm run test:security:live:ratelimit` | + rate-limit check against live — **requires YES** |
| `npm run test:security:live:ssllabs` | + SSL Labs TLS grade against live — **requires YES** (60–90 s) |
| `npm run test:security:live:full` | All security checks against live — **requires YES** |

---

## Database

| Command | What it does |
|---|---|
| `npm run sync:live` | Copy live DB into test DB (overwrites all test data; rewrites all user emails to `@hpovlsen.dk`, catch-all forwards to a real inbox) |
| `npm run restore:db` | Interactive — list backups, pick one, restore to test or live — **requires YES if live** |

---

## Backup & Rollback

| Command | What it does |
|---|---|
| `node build-deploy/backup.js` | Manual backup of live files + DB |
| `node build-deploy/rollback.js` | Interactive rollback — pick a backup and re-upload to live |

---

## Cron (manual trigger)

| Command | What it does |
|---|---|
| `php public/cron/import_qualifying.php <CRON_SECRET>` | Import qualifying results from F1 API (CLI) |
| `php public/cron/notifications.php <CRON_SECRET>` | Send betting window email notifications (CLI) |

Or via HTTP:

| URL | What it does |
|---|---|
| `/cron/import_qualifying.php?token=<CRON_SECRET>` | Same as above, via browser/curl |
| `/cron/import_qualifying.php?token=<CRON_SECRET>&test=true` | Dry run — no DB writes |
| `/cron/notifications.php?token=<CRON_SECRET>` | Same as above, via browser/curl |
| `/cron/notifications.php?token=<CRON_SECRET>&test=true` | Dry run — no emails sent, same log output |

---

## Git

| Command | What it does |
|---|---|
| `git status` | Show changed files |
| `git add <file>` | Stage a file |
| `git commit -m "message"` | Commit staged changes |
| `git push` | Push to GitHub |

---

## Utilities

| Command | What it does |
|---|---|
| `which php` | Find PHP binary path (Linux/Mac) |
| `where php` | Find PHP binary path (Windows) |
| `chmod 755 public/logs` | Fix log directory permissions on server |
