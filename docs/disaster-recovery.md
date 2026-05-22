# Disaster Recovery

## Backup tiers

| Tier | Location | Retention | Trigger |
|---|---|---|---|
| Pre-deploy snapshot | `build-deploy/backups/live/` (5 kept) | Last 5 deploys | `npm run deploy:live` |
| Manual snapshot | Same directory | Same 5-backup pool | `npm run backup:live` |
| Nightly artifact | GitHub Actions → nightly workflow → Artifacts | 90 days | 01:00 UTC daily |

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

GitHub → Actions → nightly workflow → most recent successful run → Artifacts → download `db-backup-<run_id>-<run_number>`.

If GitHub is unavailable, check local `build-deploy/backups/live/` for the most recent timestamped directory containing `db-backup.json`.

### Step 2 — Reconstruct `build-deploy/.env`

```
node build-deploy/setup-deployment.js
```

Enter FTP credentials from your password manager when prompted. See `build-deploy/.env.example` for all required keys.

### Step 3 — Reconstruct `config.live.php`

Copy `config.example.php` to `config.live.php` and fill in all 19 constants from your password manager.

**Critical:** `JWT_SECRET` and `PASSWORD_PEPPER` must match the original values exactly — changing either logs out all users or breaks all password logins respectively.

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

```
npm run test:smoke
npm run test:e2e:live
```

Smoke tests verify all pages respond. E2E tests verify login, race display, and betting workflow end-to-end.

### Step 9 — Re-provision Simply.com cron jobs

Simply.com control panel → Cron jobs → re-add both endpoints with `CRON_SECRET`:

- `https://www.formula-1.dk/cron/import_qualifying.php?secret=<CRON_SECRET>`
- `https://www.formula-1.dk/cron/notifications.php?secret=<CRON_SECRET>`

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
| `TEST_REGULAR_USER_EMAIL_LIVE` | Password manager |
| `TEST_REGULAR_USER_PASSWORD_LIVE` | Password manager |
| `INTEGRATION_SEED_TOKEN` | Password manager (= `INTEGRATION_SEED_TOKEN` from `config.live.php`) |

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

| Step | Command / Action | Pass condition |
|---|---|---|
| 1. Pre-drill snapshot | `curl "https://www.hpovlsen.dk/tools/db-backup.php?token=<TOKEN>" > /tmp/drill-before.json` | `"ok":true` in response |
| 2. Simulate destruction | phpMyAdmin on test DB: `DELETE FROM bets; DELETE FROM users; DELETE FROM drivers; DELETE FROM races;` | Tables empty |
| 2b. *(Extended drill)* Also destroy settings | `DELETE FROM settings;` in phpMyAdmin | Settings row absent before restore; present and correct after |
| 3. Restore | `npm run restore:db -- --env test` → select the pre-drill snapshot | `✅ Restore complete` |
| 4. Integration tests | `npm run test:integration` | All pass |
| 5. E2E tests | `npm run test:e2e:test` | All pass |
| 6. Admin login | Browser: log in as `f1_admin@helvegpovlsen.dk` | Admin panel loads |
| 7. Cron endpoints | `curl "https://www.hpovlsen.dk/cron/import_qualifying.php?secret=<CRON_SECRET>"` | `"ok":true` |
| 8. Sync back | `npm run sync:live` | Restores test DB to match live |

Record drill date and outcome in `docs/dr-drills.md`.

### Acceptance criteria

```gherkin
Feature: Disaster Recovery restore path

  Scenario: DB restore from nightly artifact passes integration tests
    Given a nightly db-backup artifact exists from GitHub Actions
    And the test database has been wiped (all rows deleted)
    When I run `npm run restore:db -- --env test` selecting the artifact
    Then `npm run test:integration` passes with 0 failures
    And admin login works at https://www.hpovlsen.dk

  Scenario: restore:db requires YES for both environments
    Given a backup exists
    When I run `npm run restore:db -- --env test`
    Then I am prompted "You are about to overwrite the TEST database"
    And typing anything other than "YES" aborts with no changes

  Scenario: Nightly backup artifact is created even when E2E tests fail
    Given the nightly workflow runs and E2E tests fail
    When the backup-db job runs with `if: always()`
    Then a db-backup artifact is uploaded to GitHub Actions
    And the artifact contains valid JSON with "ok":true

  Scenario: backup-to-sql.js produces importable SQL
    Given a db-backup.json exists
    When I run `node build-deploy/backup-to-sql.js path/to/db-backup.json`
    Then a db-restore.sql file is created
    And importing it via phpMyAdmin succeeds with no errors
    And row counts match the original backup
```
