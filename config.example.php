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
// define('LIVE_DB_NAME', 'mydb_live');             // test only — needed by sync-from-live.php

// ── SITE ──────────────────────────────────────────────────────────────
// Always use www — non-www may 301-redirect and strip POST bodies.
define('SITE_URL',    'https://www.example.dk');    // no trailing slash
define('SITE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST));

// ── SECURITY ──────────────────────────────────────────────────────────
// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET',             'change-me-32-random-hex-chars');
define('PASSWORD_PEPPER',        'change-me-32-random-hex-chars');
define('INTEGRATION_SEED_TOKEN', 'change-me');

// ── SMTP (Simply.com) ─────────────────────────────────────────────────
define('SMTP_HOST',       'smtp.example.dk');    
define('SMTP_PORT',       587);                     // 587=TLS, 465=SSL
define('SMTP_USER',       'noreply@example.dk');
define('SMTP_PASS',       'email-password');
define('SMTP_FROM_EMAIL', 'noreply@example.dk');
define('SMTP_FROM_NAME',  'F1 Betting');

// ── CRON ──────────────────────────────────────────────────────────────
define('CRON_SECRET',                 'change-me');

require_once __DIR__ . '/config.shared.php';
