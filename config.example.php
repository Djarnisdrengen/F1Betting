<?php
// Copy this file to config.test.php and config.live.php and fill in real values.
// Neither file is committed to git (see .gitignore).
// The deploy script uploads the right one as config.php automatically.

// ── APP ───────────────────────────────────────────────────────────────
define('APP_ENV', 'test');                          // 'test' | 'live'

// ── ADMIN USER ──────────────────────────────────────────────────────────────
// Always preserved — not in competition, but needed for admin tasks and testing.
define('F1_ADMIN_EMAIL',    'f1_admin@helvegpovlsen.dk');
define('F1_ADMIN_PASSWORD', 'change-me-32-randomhex-chars');

// ── DATABASE ──────────────────────────────────────────────────────────
define('DB_HOST', 'myhost');
define('DB_NAME', 'mydb');
define('DB_USER', 'myuser');
define('DB_PASS', 'secret');
// define('LIVE_DB_NAME',       'mydb_live');        // test only — needed by sync-from-live.php
// define('SYNC_TEST_PASSWORD', 'change-me');        // test only — all user passwords reset to this after sync:live

// ── SITE ──────────────────────────────────────────────────────────────
// Always use www — non-www may 301-redirect and strip POST bodies.
define('SITE_URL',    'https://www.example.dk');    // no trailing slash
define('SITE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST));

// ── SECURITY ──────────────────────────────────────────────────────────
// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('PASSWORD_PEPPER',        'change-me-32-random-hex-chars');//changing these will invalidate all existing passwords and MFA keys, so don't change them unless you know what you're doing
// MFA_KEY seals TOTP secrets at rest (sodium secretbox). MUST be exactly 64 hex chars (32 bytes).
define('MFA_KEY',                'change-me-64-random-hex-chars');//changing these will invalidate all existing passwords and MFA keys, so don't change them unless you know what you're doing
// PASSKEY_RPID: the WebAuthn relying-party id — the registrable domain (SITE_DOMAIN
// without www). ONE-WAY DOOR: changing it orphans every registered passkey.
// passkey.php fails loud if this doesn't match the domain derived from SITE_URL.
define('PASSKEY_RPID',           'example.dk');
define('INTEGRATION_SEED_TOKEN', 'change-me');
// HMAC key for Challenges friend-invite opt-out links (verified without a DB lookup). 64 hex chars.
define('CHALLENGE_INVITE_SECRET', 'change-me-64-random-hex-chars');

// ── SMTP (Proton Mail — primary) ──────────────────────────────────────
define('SMTP_HOST',       'smtp.example.dk');
define('SMTP_PORT',       587);                     // 587=TLS, 465=SSL
define('SMTP_USER',       'noreply@example.dk');
define('SMTP_PASS',       'email-password');//password is changed via protonmail.com control panel, so it is not a secret.
define('SMTP_FROM_EMAIL', 'noreply@example.dk');
define('SMTP_FROM_NAME',  'F1 Betting');

// ── RESEND (fallback if SMTP fails) ───────────────────────────────────
// Get an API key at resend.com (free tier is sufficient).
// If unset, a failed SMTP send is not retried.
define('RESEND_API_KEY',  're_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// ── CRON ──────────────────────────────────────────────────────────────
define('CRON_SECRET', 'change-me');

// ── GITHUB ACTIONS DASHBOARD (Dashboards → GitHub Actions / PaddockKB) ──
// Optional. A GitHub PAT for Djarnisdrengen/F1Betting. Without it the dashboard falls back to
// unauthenticated GitHub API calls (60 requests/hour, shared across the whole hosting IP) —
// fine for occasional use, but a token is recommended for reliability.
// Scope: fine-grained "Actions" repo permission — read-only is enough for the GitHub Actions
// dashboard itself, but PaddockKB's "Kør opdatering nu" button (workflow_dispatch) needs
// read+WRITE. Classic PAT: "repo" scope covers both. Without write access, "Kør opdatering nu"
// shows an "insufficient permissions" message rather than failing silently.
// define('GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// ── NØGLER & ROTATION (Dashboards → Nøgler & Rotation) ──────────────────
// No new secret needed — "Roter nu" (auto-mode secrets only; see docs/admin-dashboards.md for
// why only CHALLENGE_INVITE_SECRET is auto-rotatable) writes directly to this same config.php
// file it's already running from.

require_once __DIR__ . '/config.shared.php';
