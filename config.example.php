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
define('JWT_SECRET',             'change-me-32-random-hex-chars');
define('PASSWORD_PEPPER',        'change-me-32-random-hex-chars');
// MFA_KEY seals TOTP secrets at rest (sodium secretbox). MUST be exactly 64 hex chars (32 bytes).
define('MFA_KEY',                'change-me-64-random-hex-chars');
define('INTEGRATION_SEED_TOKEN', 'change-me');

// ── SMTP (Proton Mail — primary) ──────────────────────────────────────
define('SMTP_HOST',       'smtp.example.dk');
define('SMTP_PORT',       587);                     // 587=TLS, 465=SSL
define('SMTP_USER',       'noreply@example.dk');
define('SMTP_PASS',       'email-password');
define('SMTP_FROM_EMAIL', 'noreply@example.dk');
define('SMTP_FROM_NAME',  'F1 Betting');

// ── RESEND (fallback if SMTP fails) ───────────────────────────────────
// Get an API key at resend.com (free tier is sufficient).
// If unset, a failed SMTP send is not retried.
define('RESEND_API_KEY',  're_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// ── CRON ──────────────────────────────────────────────────────────────
define('CRON_SECRET', 'change-me');

// ── MAILSAC (test email interception — test config only) ──────────────
// Used by e2e tests to verify email delivery and content via the Mailsac API.
// Get a key at mailsac.com → Account → API Keys. Not needed in config.live.php.
define('MAILSAC_API_KEY', 'your-key-here');
define('MAILSAC_INBOX',   'f1betting-preview@mailsac.com');

require_once __DIR__ . '/config.shared.php';
