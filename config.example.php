<?php
// Copy this file to config.test.php and config.live.php and fill in real values.
// Neither file is committed to git (see .gitignore).
// The deploy script uploads the right one as config.php automatically.

// ── APP ───────────────────────────────────────────────────────────────
define('APP_ENV', 'test');                          // 'test' | 'live'

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
define('INTEGRATION_SEED_TOKEN', 'change-me');      // must match INTEGRATION_SEED_TOKEN in build-deploy/.env

// ── SMTP (Simply.com) ─────────────────────────────────────────────────
define('SMTP_HOST',       'smtp.example.dk');    
define('SMTP_PORT',       587);                     // 587=TLS, 465=SSL
define('SMTP_USER',       'noreply@example.dk');
define('SMTP_PASS',       'email-password');
define('SMTP_FROM_EMAIL', 'noreply@example.dk');
define('SMTP_FROM_NAME',  'F1 Betting');

// ── CRON ──────────────────────────────────────────────────────────────
define('CRON_SECRET',   'change-me');
define('CRON_LOG_FILE', __DIR__ . '/cron_import_log.txt');

// ── F1 API ────────────────────────────────────────────────────────────
define('F1_API_BASE',    'https://api.jolpi.ca/ergast/f1'); // Jolpica (Ergast successor)
define('F1_API_TIMEOUT', 30);

// ── BOOTSTRAP ─────────────────────────────────────────────────────────
date_default_timezone_set('Europe/Copenhagen');
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_start();

require_once __DIR__ . '/public/includes/functions.php';
