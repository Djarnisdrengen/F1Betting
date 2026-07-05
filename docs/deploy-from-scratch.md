# Deploy from Scratch

## Contents

- [Server Requirements](#server-requirements)
- [Step 1 — Create the database](#step-1--create-the-database)
- [Step 2 — Create config files locally](#step-2--create-config-files-locally)
- [Step 3 — Set up FTP credentials](#step-3--set-up-ftp-credentials)
- [Step 4 — First deploy](#step-4--first-deploy)
- [Step 5 — Set the admin password](#step-5--set-the-admin-password)
- [Step 6 — Set up cron jobs](#step-6--set-up-cron-jobs)
- [Step 7 — Verify](#step-7--verify)
- [Step 8 — GitHub Actions (live only)](#step-8--github-actions-live-only)
- [File permissions](#file-permissions)
- [Upgrading an existing installation](#upgrading-an-existing-installation)

---

Use this guide when setting up the application on a brand-new server or database for the first time.

---

## Server Requirements

| Requirement | Notes |
|---|---|
| Apache 2.4+ | With `mod_rewrite` enabled |
| PHP 8.0+ | Extensions: `pdo_mysql`, `mbstring`, `openssl` |
| MySQL 8.0+ | `utf8mb4` charset |
| FTP access | Used by the deploy scripts |
| Writable `public/logs/` | PHP needs write access for log rotation |
| SMTP relay | Proton Mail SMTP or equivalent |

---

## Step 1 — Create the database

Log in to phpMyAdmin (or the MySQL CLI) on the target server.

```sql
-- Create database
CREATE DATABASE f1betting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then import the schema:

```
database/schema.sql
```

This creates all 8 tables plus the default settings row and the admin user stub.

If you are adding the rate-limiting table to an existing database:

```
database/add_login_attempts.sql
```

To seed the 2026 race calendar:

```
database/seasons/data_2026.sql
```

---

## Step 2 — Create config files locally

```bash
cp config.example.php config.test.php    # for the test server
cp config.example.php config.live.php    # for the live server
```

Fill in all values for the target environment. See the comments in `config.example.php` for what each constant does.

Critical values to set correctly:
- `APP_ENV` — must be `'test'` or `'live'` exactly
- `SITE_URL` — must use `www` (e.g. `https://www.formula-1.dk`). Non-www 301s drop POST bodies.
- `PASSWORD_PEPPER` — generate with `openssl rand -hex 32`. Once users exist, changing it invalidates all passwords.
- `CRON_SECRET` and `INTEGRATION_SEED_TOKEN` — any random secret; used to authenticate HTTP calls to cron and tool endpoints.

---

## Step 3 — Set up FTP credentials

```bash
npm run setup:deploy
```

Enter the FTP host, username, password, and server paths. This writes `build-deploy/.env`.

---

## Step 4 — First deploy

```bash
npm run deploy:test    # for test server
# or
npm run deploy:live    # for live server (requires typing YES)
```

The deploy script:
1. Uploads everything in `public/` via FTP (respecting `.deployignore`)
2. Uploads `config.shared.php` to the server root
3. Uploads `config.test.php` (or `config.live.php`) as `config.php` in the server root
4. Runs HTTP smoke tests

`config.php` on the server is the renamed environment-specific file. It is never a copy of `config.example.php`.

---

## Step 5 — Set the admin password

The schema seeds the admin user with a placeholder password. After first deploy:

1. Open `https://your-site/tools/setup_admin.php` in a browser
2. Follow the prompts to set the real admin password

Alternatively set the password hash directly in the database.

---

## Step 6 — Set up cron jobs

See [Cron Jobs](cron-jobs.md) for the exact crontab entries.

---

## Step 7 — Verify

```bash
npm run test:smoke             # HTTP checks — all key pages return 200
npm run test:e2e:test          # Playwright browser tests
npm run test:security          # OWASP security headers scan
```

---

## Step 8 — GitHub Actions (live only)

The nightly CI workflow runs automatically once the repository secrets and variables are configured. See [GitHub Actions](github-actions.md).

---

## File permissions

| Path | Required permission |
|---|---|
| `public/logs/` | Writable by the PHP process (e.g. 755 or 775) |
| `public/.htaccess` | Readable by Apache |
| `config.php` (server root) | Readable by PHP, not web-accessible |

`config.php` lives above `public/` so it is never served directly by Apache.

---

## Upgrading an existing installation

There is no automated migration runner. When schema changes are needed:

1. Write the SQL manually (see `database/add_login_attempts.sql` as an example)
2. Apply it in phpMyAdmin or MySQL CLI on the target server
3. Deploy the updated PHP code with `npm run deploy:test` or `npm run deploy:live`
