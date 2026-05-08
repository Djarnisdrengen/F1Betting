<?php
// ── APP ───────────────────────────────────────────────────────────────
define('APP_ENV', 'test');                          // 'test' | 'live'

// ── DATABASE ──────────────────────────────────────────────────────────
define('DB_HOST', 'xxx');
define('DB_NAME', 'xxx');
define('DB_USER', 'xxx');
define('DB_PASS', 'xxx');
define('LIVE_DB_NAME', 'xxx');                      // test only — live DB on same host, used by sync-from-live.php

// ── SITE ──────────────────────────────────────────────────────────────
define('SITE_URL',    'https://www.hpovlsen.dk');   // no trailing slash, always www
define('SITE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST));

// ── SECURITY ──────────────────────────────────────────────────────────
define('JWT_SECRET',             'xxx');
define('PASSWORD_PEPPER',        'xxx');
define('INTEGRATION_SEED_TOKEN', 'xxx');            // shared with build-deploy/.env

// ── SMTP ──────────────────────────────────────────────────────────────
define('SMTP_HOST',       'websmtp.simply.com');
define('SMTP_PORT',       587);
define('SMTP_USER',       'xxx');
define('SMTP_PASS',       'xxx');
define('SMTP_FROM_EMAIL', 'xxx');
define('SMTP_FROM_NAME',  'xxx');

// ── CRON ──────────────────────────────────────────────────────────────
define('CRON_SECRET',   'xxx');
define('CRON_LOG_FILE', __DIR__ . '/cron_import_log.txt');

// ── F1 API ────────────────────────────────────────────────────────────
define('F1_API_BASE',    'https://api.jolpi.ca/ergast/f1');
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
