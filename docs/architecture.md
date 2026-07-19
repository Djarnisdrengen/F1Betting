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
  - [user_totp, user_recovery_codes, user_email_otp, user_passkeys](#user_totp-user_recovery_codes-user_email_otp-user_passkeys)
  - [leaderboard_snapshots](#leaderboard_snapshots)
- [Config System](#config-system)
  - [What each file contains](#what-each-file-contains)
- [Request Lifecycle](#request-lifecycle)
- [Security Model](#security-model)
- [Localisation & Theme](#localisation--theme)
- [Home Page Hero (Paddock Challenges)](#home-page-hero-paddock-challenges)
- [Admin — Paddock Challenges Control Room](#admin--paddock-challenges-control-room)

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
│   ├── mfa_challenge.php       Second-factor challenge (passkey/TOTP/email/recovery)
│   ├── webauthn.php            JSON endpoint for passkey register/challenge/login actions
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
│   │   ├── mfa.php             TOTP/recovery-code/email-OTP logic, seals secrets with MFA_KEY
│   │   ├── passkey.php         WebAuthn registration/challenge helpers (wraps vendored lib)
│   │   ├── webauthn/           Vendored lbuchs/WebAuthn library (no Composer)
│   │   ├── qualifying-display.php  Reusable P1/P2/P3 result display (include pattern)
│   │   └── admin/              Admin panel section partials
│   │       ├── drivers.php
│   │       ├── races.php
│   │       ├── bets.php
│   │       ├── users.php
│   │       ├── invites.php
│   │       ├── security.php    Login-attempt/lockout visibility (see Security Model)
│   │       └── settings.php
│   │
│   ├── cron/                   Scripts triggered by server cron or HTTP
│   │   ├── import_qualifying.php   Auto-import qualifying results from F1 API
│   │   └── notifications.php       Email users when betting opens/closes
│   │
│   ├── tools/                  Admin utilities (some excluded from live deploy)
│   │   ├── setup_admin.php     First-time admin account initialiser
│   │   ├── seed_f1_admin.php   Seed the service admin account (token-gated, excluded from live)
│   │   ├── test-seed.php       Seed deterministic test data (integration tests only)
│   │   ├── db-backup.php       Export all tables as JSON (token-gated, excludes password_resets)
│   │   ├── db-restore.php      Restore from a JSON backup
│   │   ├── sync-from-live.php  Copy live DB into test DB
│   │   ├── schema-check.php    Introspects DB against database/migrations.json (deploy gate)
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
│   ├── add_login_attempts_scope.sql  Adds scope/account columns (per-account + MFA-scoped limiting)
│   ├── add_mfa.sql             Migration for MFA/passkey tables (idempotent, see gotcha #16)
│   ├── migrations.json         Objects the deploy-time schema check looks for (see gotcha #18)
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
│   ├── playwright.config.js            E2E config
│   ├── playwright.integration.config.js Integration config (seeds DB first)
│   ├── smoke.js                        Node.js HTTP smoke runner
│   ├── reporter.js                     Custom Playwright reporter
│   ├── e2e/                            Numbered specs (01-smoke.spec.js … 14-race-page.spec.js)
│   │   ├── admin/                      Admin-only specs (10-content … 13-scoring)
│   │   └── auth/                       MFA/passkey specs (30-totp-mfa … 36-passkey-negative)
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

Thirteen tables. Core entities use UUID primary keys (VARCHAR 36); a few tables are auto-increment instead (`login_attempts`, `leaderboard_snapshots`, and some MFA tables — see below).

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

### user_totp, user_recovery_codes, user_email_otp, user_passkeys
MFA factor tables (added by `database/add_mfa.sql`). Their `user_id` (and `user_passkeys.id`) columns are pinned to the legacy `latin1_swedish_ci` character set to satisfy the FK against `users.id` — see [gotchas.md #16](gotchas.md#16-mfa-requires-mfa_key-in-config-and-mfa-tables-use-the-legacy-latin1-collation) for why, and for the full security-model writeup see [Security Model](#security-model) below.

### leaderboard_snapshots
Per-race rank/points snapshot (`user_id`, `race_id`, `rank`, `points`), written after each race is scored. Powers the rank-delta shown next to a user's leaderboard row.

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
| Rate limiting | `login_attempts` table, sliding 15-min window, checked per-IP **and** per-account (`scope` = `login` \| `mfa`, `account` = submitted email or user id). Login: 5/IP, 5/account. MFA challenge (code + passkey + resend): 5/IP, 3/account — separate bucket from login, so exhausting one never blocks the other. `isRateLimited()` fails **closed** on a DB error. A successful login/verification clears only that account's own bucket for that scope, never the IP-wide one. Also updates `users.last_login` and logs `[LOGIN] method=…` to `APP_LOG_FILE` via `logLoginMethod()` (passkey-adoption metrics); no separate audit log exists. Admin panel's Security tab (`includes/admin/security.php`) surfaces current buckets grouped by IP and by account against the same thresholds, and lets an admin manually clear a stuck account's bucket (`clear_login_attempts` POST, still account-only — never the IP-wide bucket) |
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

---

## Home Page Hero (Paddock Challenges)

`public/index.php`'s hero is context-aware (Paddock Challenges epic, Phase 6, feature.md REQ-006/007, decision D9): it shows one of two mutually exclusive branches, chosen by `$showRaceHero = $heroRace ? isRaceHeroWindow($heroRace, $settings, $now) : false;`.

- **Race hero** (`$showRaceHero === true`): the existing countdown/CTA hero, unchanged. A slim "Challenges" strip (`data-testid="challenges-strip"`) is added directly below it, linking to `challenges.php`.
- **Challenges hero** (`$showRaceHero === false`, including whenever there's no upcoming race at all): "Paddock Challenges" title, a CP/Rank/Streak stat row for an active challenge identity, a "Play now" CTA, a next-race card, and a top-3 CP section linking to `challenges-board.php`.

`isRaceHeroWindow(array $race, ?array $settings, ?DateTime $now): bool` (`public/includes/challenges.php`) is the pure boundary function: the race hero's window runs from `windowOpen − 24h` through `raceStart + 3h`, where `windowOpen = raceStart − betting_window_hours`. Exhaustively unit-tested in `tests/unit/hero-window-harness.php`; `tests/e2e/challenges/49-home-hero.spec.js` spot-checks the wiring end-to-end.

The hero's CP/rank/streak stats and the CP top-3 section reuse `getChallengeCpTotal()`, `getChallengeStreak()`, and `getCpLeaderboard()` from `public/includes/challenges.php` — the same helpers the Challenges hub's own Overview scoreboard uses (REQ-109 requires the streak in both places).

## Admin — Paddock Challenges Control Room

`public/admin-challenges.php` is a `?tab=` shell — `$currentTab`, `$tabIcons`, cheap `COUNT(*)` `$tabCounts` badges computed for every tab on every load, and a `switch ($currentTab)` for the one active tab's detail query — the exact same convention `public/admin.php` uses for its own races/drivers/users/etc. tabs. Five tabs: `members` (default) / `rumors` / `trivia` / `duels` / `suppressions`, each backed by its own partial in `public/includes/admin-challenges/` (`members.php`, `rumors.php`, `trivia.php`, `duels.php`, `suppressions.php` — renamed from `participants.php` for 1:1 parity with the include convention). Only the active tab's query and partial run per request; POST handlers redirect back to `?tab=<name>` (plus `&edit=<id>` where an edit should stay open, or `&rumor_status=<filter>` for Rumors).

- **Rumors** (`challenge_items`) and **Trivia** (`challenge_trivia_questions`) render as a compact list — one row per item (`.hf-racefull`, the same pattern `includes/admin/races.php` uses), showing a truncated label, status badge, and quick actions. The full bilingual edit form only appears for the one row being edited, via `?edit=<id>` (`$isEditing`, `.edit-form-active` — again mirroring `races.php`), with Save/Cancel there; Cancel returns to the plain list. The "Add new" form is a collapsed-by-default section, same `.collapsible-header.toggleForm` / `.collapsible-form` pattern and `toggleForm()` script as `races.php`'s "Add Race" — `admin-challenges.php` carries its own copy of that script (a no-op on tabs with no `.toggleForm` element). Rumors additionally support **unpublish** (back to draft) and a status filter (`?rumor_status=all|draft|published`); Trivia groups its list by ISO week (`isoWeekKey()`) with a `N/6 questions` header so gaps in the weekly schedule are visible at a glance.
  - The compact row's Publish button posts a separate, minimal `quick_publish_rumor_item` / `quick_publish_trivia_question` action (`UPDATE ... SET status = 'published'`, no other columns) rather than the full-field `publish_rumor_draft` / `publish_trivia_question` used inside the expanded edit form — posting only an id through the full-field UPDATE would blank out the item's text. Saving (either action) never touches `status` on its own (only Publish does), so editing a published item can't silently revert it to draft.
- **Duels** stay read-only oversight (REQ-504 by design — they're player-generated, not authored content).
- **Suppressions** (`challenge_email_suppressions`) is a full searchable list with per-row removal, not just an add-form-plus-count.

### Content generators

`bin/generate-rumor-items.js` (Phase 3) and `bin/generate-trivia-questions.js` (added alongside this admin work, extending trivia beyond the original v1 manual-only scope) both draw from `paddock-rumors/data/knowledge-base.json` via the Anthropic API and POST results to `public/tools/import-rumor-drafts.php` / `import-trivia-drafts.php`. The endpoints default to `status='draft'` (inert until an admin publishes above), but the weekly automation passes `--publish` so items are inserted **already published**, dated the upcoming Monday — the admin tabs are then for correction, not routine publishing. Both scripts are local/CI-only (NFR-101) and track which KB docs they've used in a per-environment `bin/state/*-generator-state.<env>.json`, so repeated runs don't reuse the same source fact. See `docs/github-actions.md`'s Content Top-up Workflow section for the weekly automation.
