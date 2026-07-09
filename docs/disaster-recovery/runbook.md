# Disaster Recovery

## Contents

- [Backup tiers](#backup-tiers)
- [Recovery scope — decide before starting](#recovery-scope--decide-before-starting)
- [Full-loss recovery runbook](#full-loss-recovery-runbook)
  - [Step 1 — Get latest backup](#step-1--get-latest-backup)
  - [Step 2 — Reconstruct build-deploy/.env](#step-2--reconstruct-build-deployenv)
  - [Step 3 — Reconstruct config.live.php](#step-3--reconstruct-configlivephp)
  - [Step 3a — Reconstruct config.test.php](#step-3a--reconstruct-configtestphp)
  - [Step 4 — Deploy files](#step-4--deploy-files)
  - [Step 5 — Restore schema](#step-5--restore-schema)
  - [Step 6 — Restore data](#step-6--restore-data)
  - [Step 7 — Verify admin login](#step-7--verify-admin-login)
  - [Step 8 — Verify](#step-8--verify)
  - [Step 9 — Re-provision Simply.com cron jobs](#step-9--re-provision-simplycom-cron-jobs)
- [GitHub Secrets and Variables — re-provisioning checklist](#github-secrets-and-variables--re-provisioning-checklist)
  - [Secrets (Settings → Secrets and variables → Actions → Secrets)](#secrets-settings--secrets-and-variables--actions--secrets)
  - [Variables (Settings → Secrets and variables → Actions → Variables)](#variables-settings--secrets-and-variables--actions--variables)
- [DR Drill procedure (test environment)](#dr-drill-procedure-test-environment)
  - [Prerequisites](#prerequisites)
  - [Drill steps](#drill-steps)
  - [Acceptance criteria](#acceptance-criteria)

---

## Backup tiers

| Tier | Location | Retention | Trigger |
|---|---|---|---|
| Pre-deploy snapshot | `build-deploy/backups/live/` (5 kept) | Last 5 deploys | `npm run deploy:live` |
| Manual snapshot | Same directory | Same 5-backup pool | `npm run backup:live` |
| Nightly artifact | GitHub Actions → Nightly DB Backup workflow → Artifacts | 90 days | 01:00 UTC daily |

---

## Recovery scope — decide before starting

| Scenario | What's lost | Entry point |
|---|---|---|
| Bad deploy / data corruption — files OK | DB data only | Skip to step 5 (restore data) |
| Live server wiped | Live files + DB | Full runbook steps 1–9 |
| Test server wiped | Test files + DB | Steps 2, 3a, then `npm run deploy:test`, then step 5 targeting test, then DR Drill steps 4–7 to verify |
| Both environments wiped | Everything | Recover live first (steps 1–9), then `npm run sync:live` to rebuild test from live |
| Developer machine lost — servers still up | Local `.env` + configs only | Steps 2–3a only, then `npm run deploy:test` to verify connectivity |

---

## Full-loss recovery runbook

### Step 1 — Get latest backup

GitHub → Actions → **Nightly DB Backup** workflow → most recent successful run → Artifacts → download `db-backup-<run_id>-<run_number>`.

If GitHub is unavailable, check local `build-deploy/backups/live/` for the most recent timestamped directory containing `db-backup.json`.

### Step 2 — Reconstruct `build-deploy/.env`

```
node build-deploy/setup-deployment.js
```

Enter FTP credentials from your password manager when prompted. See `build-deploy/.env.example` for all required keys.

### Step 3 — Reconstruct `config.live.php`

Copy `config.example.php` to `config.live.php` and fill in all constants from your password manager.

**Critical:** `PASSWORD_PEPPER` must match the original value exactly — changing it breaks all password logins.

### Step 3a — Reconstruct `config.test.php`

Same process as step 3, using:
- `APP_ENV = 'test'`
- `DB_NAME` pointing to the test database
- `SITE_URL = 'https://www.hpovlsen.dk'`
- `LIVE_DB_NAME` pointing to the live DB (used by `sync-from-live.php`)

Required before the DR Drill verification steps can run.

### Step 4 — Deploy files

```
npm run deploy:live
```

This backs up whatever is currently on the live server first, then uploads all PHP files and `config.live.php` as `config.php`.

### Step 5 — Restore schema

phpMyAdmin (Simply.com control panel) → select live database → SQL tab → paste and run the contents of `database/schema.sql`.

This creates all tables and inserts the default `settings` row, stub admin user, and 2025 F1 drivers.

### Step 6 — Restore data

**Option A — phpMyAdmin import (recommended)**

```
node build-deploy/backup-to-sql.js path/to/db-backup.json
```

Then phpMyAdmin → Import → upload the generated `db-restore.sql` from the same directory.

**Option B — programmatic (faster)**

1. Remove `tools/db-restore.php` from `build-deploy/.deployignore.live`
2. `npm run deploy:live`
3. `npm run restore:db -- --env live`
4. Re-add `tools/db-restore.php` to `.deployignore.live`
5. `npm run deploy:live`

**Complete both deploys in the same session — do not leave `db-restore.php` on the live server overnight.**

### Step 7 — Verify admin login

The data restore in step 6 overwrites the stub admin user from `schema.sql` with the real account from the backup (correct bcrypt+pepper hash). Log in at `https://www.formula-1.dk` as `f1_admin@helvegpovlsen.dk`.

If login fails (backup predates a password change, or `PASSWORD_PEPPER` was rotated), use the forgot-password flow at `https://www.formula-1.dk/forgot-password.php` to reset via the admin email account.

### Step 8 — Verify

```bash
node tests/smoke.js https://www.formula-1.dk
npm run test:e2e:live
```

Note: `test:smoke` requires the URL passed explicitly — it is not read from `.env` or the PHP config. Always use the `www` prefix.

Smoke tests verify all pages respond. E2E tests verify login, race display, and betting workflow end-to-end.

### Step 9 — Re-provision Simply.com cron jobs

**This step is mid-migration (F6, `security-findings-remaining.md`) — check that file for
current status before following it.** Simply.com's control-panel cron feature can't send a
custom header, so the trigger is moving to GitHub Actions (`.github/workflows/cron-qualifying-import.yml`,
`cron-notifications.yml`). Until that migration's cutover is complete, Simply's control panel is
still the live trigger, kept working by a temporary `?token=` shim in both cron scripts:

Simply.com control panel → Cron jobs → re-add both endpoints with `CRON_SECRET`:

- `https://www.formula-1.dk/cron/import_qualifying.php?token=<CRON_SECRET>`
- `https://www.formula-1.dk/cron/notifications.php?token=<CRON_SECRET>`

Once F6's cutover is complete, this step changes to: re-add the `CRON_SECRET` GitHub Actions
repo secret and confirm both workflows' `schedule:` triggers are enabled — no Simply.com
control-panel entries to re-provision at all.

---

## GitHub Secrets and Variables — re-provisioning checklist

If the GitHub repository must be recreated, re-provision in this order.

### Secrets (Settings → Secrets and variables → Actions → Secrets)

| Secret | Source |
|---|---|
| `SMTP_USER` | Password manager |
| `SMTP_PASS` | Password manager |
| `RESEND_API_KEY` | resend.com dashboard → API Keys |
| `TEST_USER_PASSWORD_LIVE` | Password manager (= `F1_ADMIN_PASSWORD` from `config.live.php`) |
| `INTEGRATION_SEED_TOKEN` | Password manager (= `INTEGRATION_SEED_TOKEN` from `config.live.php`) |
| `CRON_SECRET` | Password manager (= `CRON_SECRET` from `config.live.php`) — added for the F6 cron trigger migration; see Step 9 |

### Variables (Settings → Secrets and variables → Actions → Variables)

| Variable | Value |
|---|---|
| `BASE_URL_LIVE` | `https://www.formula-1.dk` |
| `SMTP_HOST` | `smtp.protonmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_FROM` | `noreply@formula-1.dk` |
| `REPORT_TO` | Admin email address |

---

## DR Drill procedure (test environment)

Run once per season or after any significant infrastructure change.

### Prerequisites

- `INTEGRATION_SEED_TOKEN` provisioned as a GitHub Actions Secret (Settings → Secrets and variables → Actions → Secrets)
- Test environment is up to date: `npm run deploy:test`
- At least one local backup exists in `build-deploy/backups/live/`

### Drill steps

Note: Simply.com's WAF blocks curl when a long token appears in the query string. Use Node.js `fetch` for all token-authenticated requests.

| Step | Command / Action | Pass condition |
|---|---|---|
| 1. Pre-drill snapshot | `node -e "…"` via `php-config.js` (see [DR Test Plan](../build-deploy/backups/dr-drill/)) | `ok: true` + row counts printed |
| 2. Simulate destruction | phpMyAdmin → SQL: `SET foreign_key_checks=0; DROP TABLE IF EXISTS invites, bets, password_resets, leaderboard_snapshots, races, users, drivers, settings, login_attempts; SET foreign_key_checks=1;` | No tables in structure tab |
| 2b. Simulate file loss | FTP/file manager: rename `public/` → `public.bak` | Site returns 404/500 |
| 3. Restore files | `npm run deploy:test` | Upload complete (smoke failures OK — DB still empty) |
| 3b. Restore schema | phpMyAdmin → SQL → paste `database/schema.sql` | 9 tables visible in structure tab |
| 4. Restore data | `npm run restore:db -- --env test` → select pre-drill snapshot, type YES | `✅ Restore complete` |
| 5. E2E tests | `npm run test:e2e:test` | All pass |
| 6. Admin login | Browser: log in as `f1_admin@helvegpovlsen.dk` | Admin panel loads |
| 7. Cron endpoints | Node.js fetch to `?token=<CRON_SECRET>` (not `?secret=`) | qualifying: `Cron token validation: VALID`; notifications: `Notification check complete.` |
| 8. Sync back | `npm run sync:live` | Restores test DB to match live |

Record drill date and outcome in `docs/disaster-recovery/dr-drills.md`.

### Acceptance criteria

```gherkin
Feature: Disaster Recovery restore path

  Scenario: DB restore from nightly artifact passes E2E tests
    Given a nightly db-backup artifact exists from GitHub Actions
    And the test database has been wiped (all rows deleted)
    When I run `npm run restore:db -- --env test` selecting the artifact
    Then `npm run test:e2e:test` passes with 0 failures
    And admin login works at https://www.hpovlsen.dk

  Scenario: restore:db requires YES for both environments
    Given a backup exists
    When I run `npm run restore:db -- --env test`
    Then I am prompted "You are about to overwrite the TEST database"
    And typing anything other than "YES" aborts with no changes

  Scenario: Nightly backup artifact is created even when E2E tests fail
    Given the nightly test workflow runs and E2E tests fail
    When the Nightly DB Backup workflow runs independently at 01:00 UTC
    Then a db-backup artifact is uploaded to GitHub Actions
    And the artifact contains valid JSON with "ok":true

  Scenario: backup-to-sql.js produces importable SQL
    Given a db-backup.json exists
    When I run `node build-deploy/backup-to-sql.js path/to/db-backup.json`
    Then a db-restore.sql file is created
    And importing it via phpMyAdmin succeeds with no errors
    And row counts match the original backup
```
