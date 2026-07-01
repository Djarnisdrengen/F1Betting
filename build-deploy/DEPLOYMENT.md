# Deployment Strategy

## npm Commands

### Deploy

| Command | What it does |
|---|---|
| `npm run deploy:test` | Uploads all files from `public/` to **hpovlsen.dk** via FTP, respecting `.deployignore`. After upload, runs HTTP smoke tests. Test-only files (`test-seed.php`, `sync-from-live.php`) **are** uploaded here — they live only on the test server. |
| `npm run deploy:live` | Uploads all files from `public/` to **formula-1.dk** via FTP. Requires typing `YES` at the confirmation prompt. Before uploading, creates a timestamped backup of the current live site. After upload, runs smoke tests + Playwright E2E tests. If either fails, automatically rolls back to the backup. Test-only files are excluded via `.deployignore.live`. |
| `npm run setup:deploy` | One-time interactive setup that writes FTP credentials into `build-deploy/.env`. Run this when setting up the project on a new machine. |

### Sync & Restore

| Command | What it does |
|---|---|
| `npm run sync:live` | Copies all data from the live database (formula-1.dk) into the test database (hpovlsen.dk), overwriting everything except the `settings` table. Drops any `old_` prefixed legacy tables. Useful for testing against real data. Requires `LIVE_DB_NAME` to be defined in the test server's `config.php`. |
| `npm run restore:db` | Lists all available DB backups (from `build-deploy/backups/live/`). Run with a timestamp and target to restore: `npm run restore:db -- <timestamp> [test\|live]`. Reads `db-backup.json` from the chosen backup folder and re-imports all tables into the target database. Restoring to **live** has a 5-second abort window before it proceeds. `db-restore.php` must be present on the target server — it is deployed to test automatically but **excluded from live** by default (remove it from `.deployignore.live` temporarily if you need a live restore). |

### Backup & Rollback

| Command | What it does |
|---|---|
| `node build-deploy/backup.js` | Manually back up the live server: downloads all files from live via FTP and exports the database as `db-backup.json`. Saves to `build-deploy/backups/live/<ISO-timestamp>/`. A backup is also created automatically before every `deploy:live`. Only the 2 most recent backups are kept. |
| `node build-deploy/rollback.js` | Interactively lists available backups and re-uploads the chosen one to the live server over FTP. Rollback also runs automatically if `deploy:live` tests fail. |

### Test

| Command | What it does |
|---|---|
| `npm run test:smoke` | Fires HTTP requests against the deployed site and checks that key pages return 200. Fast, no browser. Runs automatically as part of every deploy. Target URL is read from `config.test.php` or `config.live.php`. |
| `npm run test:e2e` | Runs the Playwright E2E browser tests (`smoke.spec.js`) against whichever `DEPLOY_ENV` is set. URL and credentials are read automatically from the matching `config.*.php` file. Used internally by `deploy:live`. |
| `npm run test:e2e:live` | Manually runs E2E tests against **formula-1.dk**. URL and credentials are read from `config.live.php`. |
| `npm run test:e2e:test` | Manually runs E2E tests against **hpovlsen.dk**. URL and credentials are read from `config.test.php`. |
| `npm run test:integration` | Runs the Playwright integration test suite against **hpovlsen.dk** only. Before asserting, calls `test-seed.php` to reset the test database and seed 5 races of deterministic data (3 users, 10 drivers, 15 bets). Asserts correct points totals, leaderboard order, star counts, and betting pool sizes. **Never run this against the live site — it seeds fake data.** Run manually after deploying to test. |
| `npm run test:all` | Runs `test:smoke` then `test:e2e`. Equivalent to what `deploy:live` runs automatically after upload. |

### Security

Runs OWASP-mapped security checks against the deployed site. By default targets **hpovlsen.dk** (test); use the `:live` variants for **formula-1.dk**.

| Command | What it does |
|---|---|
| `npm run test:security` | Runs all security checks (transport, headers, cookies, access control, CSRF, info disclosure, DNS, CWE Top 25, session hardening). Rate-limit and SSL Labs checks are skipped. |
| `npm run test:security:ratelimit` | Same as above, plus tests login rate-limiting (sends 6 rapid failed attempts and expects a 429 response). Only enable when you know the scan IP is not already blocked. |
| `npm run test:security:ssllabs` | Same as base, plus queries the SSL Labs API for a full TLS grade. Takes 60–90 seconds. |
| `npm run test:security:full` | All checks enabled — rate-limit test + SSL Labs. |
| `npm run test:security:live` | Base checks against **formula-1.dk**. |
| `npm run test:security:live:ratelimit` | Rate-limit check against **formula-1.dk**. |
| `npm run test:security:live:ssllabs` | SSL Labs check against **formula-1.dk**. |
| `npm run test:security:live:full` | All checks against **formula-1.dk**. |

Reports are saved to `build-deploy/security-reports/` as `.md` and `.json` (two most recent per environment kept).

---

## Overview

| Environment | Site | Branch |
|-------------|------|--------|
| Local dev   | Direct file editing | `main` |
| Test        | hpovlsen.dk | `main` |
| Live        | formula-1.dk | `main` (only after test verified) |

---

## Local Folder Setup

You only need **one local folder** — the GitHub repo:

```
~/Documents/Websites/github/F1Betting/
```

Do all development here. No need for a separate live copy locally.

---

## Workflow

### 1. Develop locally
Edit files in the repo.

### 2. Commit to GitHub
```bash
git add .
git commit -m "describe change"
git push
```

### 3. Deploy to test
```bash
node build-deploy/deploy.js test
```
Verify everything works on **hpovlsen.dk**.

### 4. Deploy to live — only when test is confirmed working
```bash
node build-deploy/deploy.js live
```

---

## Preventing Accidental Live Deploys

`deploy:live` requires typing `YES` exactly at the confirmation prompt — pressing Enter or any other input cancels it.

---

## What Gets Deployed

- Everything in the `public/` folder
- `config.shared.php` (from repo root) — deployed alongside `config.php` on each target server
- Respects exclusions listed in `build-deploy/.deployignore`

## Schema check (guards against forgotten migrations)

Migrations are applied manually per environment (phpMyAdmin). To stop code from going live against a DB that hasn't been migrated, every deploy runs a schema check after upload:

1. `database/migrations.json` lists every table/column the code depends on, each tagged with the migration file that introduces it. **When you add a migration, add its objects here** — otherwise a forgotten manual run won't be caught.
2. `deploy.js` POSTs that list to `public/tools/schema-check.php`, which introspects the target env's own DB and reports anything missing.
3. If something is missing, the deploy fails and lists exactly which migration file(s) to run. On live it first rolls back to the pre-deploy backup.

The endpoint is uploaded earlier in the same deploy, so the check is active immediately — including the deploy that first introduces it. If `schema-check.php` is unreachable (404), the deploy warns and continues rather than blocking on the checker itself.

## Environment Variables

`build-deploy/.env` holds **FTP credentials only** (never committed to git):

```env
FTP_HOST=your-ftp-server.com
FTP_USER=your-ftp-username
FTP_PASS=your-ftp-password
FTP_ROOT_TEST=/path/to/test/root
FTP_ROOT_LIVE=/path/to/live/root
DRY_RUN=false
```

All other configuration (site URLs, admin credentials, seed tokens, cron secrets) lives in `config.test.php` and `config.live.php`. The build and test scripts read these files directly — no need to duplicate values in `.env`.

---

## GitHub Actions Setup

The nightly CI workflow (`nightly.yml`) runs against the live site. It cannot read `config.live.php` (that file is local only), so required values must be configured in the GitHub repo settings.

Go to **Settings → Secrets and variables → Actions**:

### Variables tab (not secrets)

| Variable | Example value |
|---|---|
| `BASE_URL_LIVE` | `https://www.formula-1.dk` |
| `SMTP_HOST` | `smtp.protonmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_FROM` | `noreply@formula-1.dk` |

### Secrets tab

| Secret | Description |
|---|---|
| `SMTP_USER` | SMTP login username |
| `SMTP_PASS` | SMTP password |
| `REPORT_TO` | Recipient address for nightly report |
| `TEST_USER_PASSWORD_LIVE` | Admin password for E2E login on live |

---

## Summary

```
edit code → git commit → deploy test → verify on hpovlsen.dk → deploy live
```
