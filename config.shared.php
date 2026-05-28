<?php
// Shared configuration — included at the end of config.test.php and config.live.php.
// Do not require this file directly; it depends on constants defined by the calling file.

// ── LOGGING ───────────────────────────────────────────────────────────
define('APP_LOG_FILE',                __DIR__ . '/public/logs/app.log');
define('MAIL_LOG_FILE',               __DIR__ . '/public/logs/mail.log');
define('CRON_NOTIFICATIONS_LOG_FILE', __DIR__ . '/public/logs/cron_notifications.log');
define('CRON_QUALIFYING_LOG_FILE',    __DIR__ . '/public/logs/cron_qualifying.log');

// ── APP VERSION ───────────────────────────────────────────────────────
define('APP_VERSION', 'v2.5.0');

// ── F1 API ────────────────────────────────────────────────────────────
define('F1_API_BASE',    'https://api.jolpi.ca/ergast/f1'); // Jolpica (Ergast successor)
define('F1_API_TIMEOUT', 30);

// ── BOOTSTRAP ─────────────────────────────────────────────────────────
date_default_timezone_set('Europe/Copenhagen');
ini_set('display_errors', 0);
ini_set('log_errors',     1);
ini_set('error_log',      APP_LOG_FILE);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Security headers on every PHP response (including redirects).
// mod_headers in .htaccess is Apache-only and silently ignored on nginx/OpenResty.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
// CSP is set in header.php where the per-request nonce is available.

// ── F1 INTELLIGENCE ───────────────────────────────────────────────────
define('F1_INTELLIGENCE_API_URL', 'https://api-chi-nine-25.vercel.app');
define('F1_INTELLIGENCE_TIMEOUT', 30);
// F1_INTELLIGENCE_DEBUG is defined per-env in config.test.php / config.live.php

require_once __DIR__ . '/public/includes/functions.php';
