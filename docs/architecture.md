# Architecture

## Contents

- [Repository Layout](#repository-layout)
- [Database Schema](#database-schema)
  - [users](#users)
  - [drivers](#drivers)
  - [races](#races)
  - [bets](#bets)
  - [settings (singleton: id=1)](#settings-singleton-id1)
  - [password_resets, login_attempts, invites](#password_resets-login_attempts-invites)
- [Config System](#config-system)
  - [What each file contains](#what-each-file-contains)
- [Request Lifecycle](#request-lifecycle)
- [Security Model](#security-model)
- [Localisation & Theme](#localisation--theme)

---

## Repository Layout

```
F1Betting/
├── config.example.php          Template — copy to config.test.php + config.live.php
├── config.shared.php           Shared bootstrap included by both config files
├── config.test.php             Test-env config (gitignored, local + server)
├── config.live.php             Live-env config (gitignored, local + server)
├── package.json                npm scripts and Node.js dependencies
├── .php-cs-fixer.php           PHP code style (PSR-12)
│
├── public/                     Web root — everything here is served by Apache
│   ├── index.php               Home page (hero, upcoming races, leaderboard)
│   ├── login.php               Login form + POST handler
│   ├── logout.php              Session destroy + redirect
│   ├── register.php            Invite-only registration
│   ├── forgot_password.php     Password reset request
│   ├── reset_password.php      Password reset with token
│   ├── admin.php               Admin dashboard (drivers, races, users, settings)
│   ├── profile.php             User profile
│   ├── leaderboard.php         Full leaderboard
│   ├── races.php               All races (past + upcoming)
│   ├── rules.php               Game rules page
│   ├── bet.php                 Place a new bet for a race
│   ├── edit_bet.php            Edit an existing bet (while betting is open)
│   ├── csp-report.php          CSP violation report endpoint
│   ├── .htaccess               Apache: rewrites, cache headers
│   │
│   ├── includes/               Shared PHP
│   │   ├── functions.php       All utility functions (DB, auth, CSRF, i18n, etc.)
│   │   ├── header.php          HTML <head>, CSP nonce, navigation
│   │   ├── footer.php          JS includes, mobile menu, countdown timers
│   │   ├── scoring.php         Points calculation logic
│   │   ├── smtp.php            SMTP email wrapper
│   │   ├── qualifying-display.php  Reusable P1/P2/P3 result display (include pattern)
│   │   └── admin/              Admin panel section partials
│   │       ├── drivers.php
│   │       ├── races.php
│   │       ├── bets.php
│   │       ├── users.php
│   │       ├── invites.php
│   │       └── settings.php
│   │
│   ├── cron/                   Scripts triggered by server cron or HTTP
│   │   ├── import_qualifying.php   Auto-import qualifying results from F1 API
│   │   └── notifications.php       Email users when betting opens/closes
│   │
│   ├── tools/                  Admin utilities (some excluded from live deploy)
│   │   ├── setup_admin.php     First-time admin account initialiser
│   │   ├── test-seed.php       Seed deterministic test data (integration tests only)
│   │   ├── db-backup.php       Export all tables as JSON
│   │   ├── db-restore.php      Restore from a JSON backup
│   │   ├── sync-from-live.php  Copy live DB into test DB
│   │   └── test_smtp.php       SMTP connectivity test
│   │
│   ├── lang/                   Translation strings
│   │   ├── user.php            User-facing strings (da + en)
│   │   ├── admin.php           Admin-facing strings
│   │   └── email.php           Email subject + body strings
│   │
│   ├── assets/
│   │   ├── css/style.css       All styles (dark/light themes, two colour palettes)
│   │   ├── js/app.js           Browser JS (countdowns, mobile menu, leaderboard)
│   │   └── fontawesome/        Icon library (self-hosted)
│   │
│   └── logs/                   Writable log directory (protected by .htaccess)
│
├── database/
│   ├── schema.sql              Full schema — run once on a new database
│   ├── add_login_attempts.sql  Migration for rate-limiting table
│   └── seasons/
│       └── data_2026.sql       2026 race calendar seed data
│
├── build-deploy/               Node.js tooling — not deployed to server
│   ├── deploy.js               FTP upload + test runner
│   ├── php-config.js           Reads PHP config files from Node.js
│   ├── setup-deployment.js     Interactive .env creator
│   ├── sync.js                 Sync test DB from live
│   ├── backup.js               Backup live files + DB
│   ├── restore-db.js           Interactive DB restore
│   ├── rollback.js             Rollback a failed live deploy
│   ├── nightly-report.js       Run tests + email report (GitHub Actions)
│   ├── .env                    FTP credentials (gitignored)
│   ├── .env.example            Template for .env
│   ├── .deployignore           Files excluded from every deploy
│   ├── .deployignore.live      Additional exclusions for live deploys
│   ├── DEPLOYMENT.md           Deploy-specific reference
│   ├── backups/                Timestamped live backups (gitignored)
│   └── security-reports/       Security scan output (gitignored)
│
├── tests/
│   ├── playwright.config.js            E2E config (smoke, admin, cron specs)
│   ├── playwright.integration.config.js Integration config (seeds DB first)
│   ├── smoke.js                        Node.js HTTP smoke runner
│   ├── reporter.js                     Custom Playwright reporter
│   ├── e2e/
│   │   ├── smoke.spec.js               Public pages, translations, login
│   │   ├── admin.spec.js               Admin functionality
│   │   ├── integration.spec.js         Points, leaderboard, pool size
│   │   └── cron.spec.js               Cron endpoint tests
│   └── security/
│       └── security.js                 OWASP + SSL Labs + rate-limit scanner
│
└── .github/
    └── workflows/
        ├── nightly-tests.yml           Daily CI: E2E + security + email report
        ├── nightly-backup.yml          Daily DB backup artifact (90-day retention)
        └── monthly-security-review.yml Monthly OWASP/CWE coverage report
```

---

## Database Schema

Eight tables, all using UUID primary keys (VARCHAR 36) except auto-increment tables.

### users
| Column | Type | Notes |
|---|---|---|
| id | VARCHAR(36) PK | UUID |
| email | VARCHAR(255) UNIQUE | |
| password | VARCHAR(255) | bcrypt + pepper |
| display_name | VARCHAR(100) | |
| role | ENUM('user','admin') | |
| points | INT | Running total |
| stars | INT | Perfect-bet count |
| language | VARCHAR(2) | `'da'` or `'en'`, default `'da'` |
| theme | ENUM('dark','light') | NULL = no profile pref yet |
| font_stack | ENUM('system','editorial') | NULL = no profile pref yet |
| in_competition | TINYINT(1) | 0 = observer/admin, 1 = player |
| created_at, last_login | DATETIME | |

### drivers
| Column | Type |
|---|---|
| id | VARCHAR(36) PK |
| name | VARCHAR(100) |
| team | VARCHAR(100) |
| number | INT |

### races
| Column | Type | Notes |
|---|---|---|
| id | VARCHAR(36) PK | |
| name, location | VARCHAR(100) | |
| race_date | DATE | |
| race_time | TIME | CET |
| quali_p1/p2/p3 | VARCHAR(36) FK → drivers | Set after qualifying |
| result_p1/p2/p3 | VARCHAR(36) FK → drivers | Set after race |
| bettingpool_won | TINYINT(1) | |
| bettingpool_size | INT | Current prize pool in kr |

### bets
| Column | Type | Notes |
|---|---|---|
| id | VARCHAR(36) PK | |
| user_id | FK → users CASCADE | |
| race_id | FK → races CASCADE | |
| p1/p2/p3 | FK → drivers | Predicted podium |
| points | INT | Earned after race |
| is_perfect | TINYINT(1) | Exact match |
| placed_at | DATETIME | |

`UNIQUE(user_id, race_id)` — one bet per user per race.

### settings (singleton: id=1)
Points schema (p1=25, p2=18, p3=15, wrong_pos=5), betting window hours, bet size, hero text (DA+EN), app title/year.

### password_resets, login_attempts, invites
Standard security tables. See `database/schema.sql` for full DDL.

---

## Config System

```
config.example.php         (committed — template only, no real values)
        ↓  copy + fill in
config.test.php            (gitignored — local machine + test server)
config.live.php            (gitignored — local machine + live server)
        ↓  both end with:
require_once __DIR__ . '/config.shared.php';
```

### What each file contains

**config.test.php / config.live.php** define:
- `APP_ENV` ('test' or 'live')
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `SITE_URL`, `SITE_DOMAIN`
- `F1_ADMIN_EMAIL`, `F1_ADMIN_PASSWORD`
- `PASSWORD_PEPPER` (32 hex chars)
- `INTEGRATION_SEED_TOKEN`, `CRON_SECRET`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`

**config.shared.php** (committed, deployed) defines:
- Log file paths (relative to repo root)
- F1 API base URL and timeout
- Sets PHP ini: timezone, error display, session hardening (secure, httponly, samesite=Lax, strict mode)
- Starts the session
- Sets security headers (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
- `require_once` functions.php

**build-deploy/.env** (FTP credentials only):
```
FTP_HOST, FTP_USER, FTP_PASS, FTP_ROOT_TEST, FTP_ROOT_LIVE, DRY_RUN
```

**php-config.js** — Node.js bridge that reads `define('KEY', 'value')` constants from config.*.php using regex. Used by all build/test scripts so credentials and URLs don't need to be in `.env`.

---

## Request Lifecycle

1. Apache serves `public/` as web root, `.htaccess` handles rewrites.
2. Each PHP page opens with:
   ```php
   require_once __DIR__ . '/../config.php';      // env constants + session
   require_once __DIR__ . '/includes/functions.php'; // loaded by config.shared.php
   requireLogin();   // or requireAdmin()
   ```
3. `config.php` on the server is the renamed `config.test.php` or `config.live.php`.
4. CSP nonce is generated in `header.php` per request; inline scripts reference it.
5. All POST handlers call `requireCsrf()` before processing.
6. Output is escaped with `escape()` (wraps `htmlspecialchars`).

---

## Security Model

| Concern | Implementation |
|---|---|
| Authentication | bcrypt + `PASSWORD_PEPPER` constant |
| Multi-factor auth | Opt-in passkey / TOTP / email OTP / recovery codes (`includes/mfa.php`). After a correct password, an enrolled member gets only `$_SESSION['mfa_pending']`; a session is granted solely by `mfa_challenge.php` or the `webauthn.php` verify actions. The challenge opens on the member's **preferred** factor (`getMfaDefaultMethod()` — `mfa_default_method`, else priority passkey → totp → email), with every other method reachable via each panel's "Other options" list. Lockout recovery: admin Users tab can strip all factors (`remove_user_mfa` — logged, member notified by email) |
| Passkeys (WebAuthn) | Vendored lbuchs/WebAuthn (`includes/webauthn/`, no Composer) behind `includes/passkey.php`; JSON endpoint `public/webauthn.php` (6 actions, byte-identical generic errors); rpId = `PASSKEY_RPID` (registrable domain — one-way door, see gotcha #20) |
| Session fixation | `session_regenerate_id()` after login |
| CSRF | Per-session token, `csrfField()` + `requireCsrf()` |
| Rate limiting | 5 failed logins per IP per 15 min, `login_attempts` table. Successful login clears the IP's attempts, updates `users.last_login`, and logs `[LOGIN] method=…` to `APP_LOG_FILE` via `logLoginMethod()` (passkey-adoption metrics); no separate audit log exists |
| XSS | `escape()` on all output, CSP with per-request nonce |
| Clickjacking | `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` |
| SQL injection | PDO prepared statements everywhere |
| Invitation-only signup | Admin creates invite token; registration requires valid token |

---

## Localisation & Theme

Three user preferences are exposed via the bottom-nav toggles: **language**, **theme**, and **font**. Each is stored in up to three places depending on the user's state:

| Store | When written | Purpose |
|---|---|---|
| PHP session (`$_SESSION`) | On every `set*()` call | Runtime source of truth for the current request |
| Preference cookie (`f1_theme`, `f1_font`) | On every `setTheme()` / `setFont()` call, and on first page load if absent | Device persistence for anonymous visitors and across sessions |
| DB (`users.theme`, `users.font_stack`, `users.language`) | When authenticated | Cross-device persistence, survives login on any device |

**Resolution order** (first match wins):
1. PHP session (already populated this request)
2. Preference cookie (anonymous returning visitor, or post-logout)
3. System default (`dark` / `da` / `system`)

**On login:**
- If the user's DB columns are NULL (first login), the current session prefs are written to the profile (seeding).
- If the DB columns have values (returning user), those values override the session and cookies on this device.

**On logout:**
- `session_unset()` clears the session. The preference cookies remain untouched — they were kept in sync by every `setTheme()` / `setFont()` call during the session. The next anonymous page load reads them via the cookie fallback.

**Helper functions** (all in `public/includes/functions.php`):
- `getTheme()` / `setTheme($theme)` — session → cookie (`f1_theme`) → default `'dark'`
- `getFont()` / `setFont($font)` — session → cookie (`f1_font`) → default `'system'`
- `getLang()` / `setLang($lang)` — session → default `'da'`; language has no preference cookie (it already survives via DB on login and explicit session preservation on logout)

Toggle redirects (`?toggle_theme=1`, `?toggle_lang=1`, `?toggle_font=1`) are handled in `public/includes/header.php` and preserve existing query parameters on redirect.

Colour palette (`broadcast`/`clubhouse`) is session-only — toggled via `?toggle_palette=1`.

Strings are loaded from `public/lang/user.php`, `admin.php`, and `email.php` via `t($key)`. Email functions pass the recipient's language explicitly: `t($key, $lang)`.
