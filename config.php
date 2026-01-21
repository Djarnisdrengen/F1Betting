<?php



// ============================================
// KONFIGURATION - REDIGER DISSE VÆRDIER
// VALUES DOMAIN SPECIFIC: (rest is same for all domains)
//    * define('DB_NAME', 'xxx') 
//    * define('SITE_URL', 'xxx')
//    * define('SMTP_FROM_EMAIL', 'xxx'); 
//    * define('EMAIL_BASE_URL', 'xxx');
// ============================================

// Database indstillinger (fra Simply.com kontrolpanel)
define('DB_HOST', 'xxx');  // Eller din specifikke MySQL host
define('DB_NAME', 'xxx');  // Dit database navn
define('DB_USER', 'xxx');     // Dit MySQL brugernavn
define('DB_PASS', 'xxx');       // Dit MySQL password

// Sikkerhed
define('JWT_SECRET', 'xxx');
define('PASSWORD_PEPPER', 'xxx');

// Site URL (uden trailing slash)
// Eksempler:
//   Rodmappe: 'https://dit-domæne.dk'
define('SITE_URL', 'xxx');
define('SITE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST));

// ============================================
// SMTP EMAIL KONFIGURATION (Simply.com)
// ============================================
// Find indstillinger i Simply.com kontrolpanel under "E-mail"
// 
// Simply.com SMTP servere:
//   - websmtp.simply.com (anbefalet)
//   - asmtp.unoeuro.com (alternativ)
//   Port: 587 (TLS/STARTTLS) eller 465 (SSL)
//   Brugernavn: din fulde email adresse
//   Password: din email adgangskode

define('SMTP_HOST', 'xxx');        // Simply.com SMTP server
define('SMTP_PORT', 587);                         // 587 for TLS, 465 for SSL
define('SMTP_USER', 'xxx');    // Din email adresse
define('SMTP_PASS', 'xxx');    // Din email adgangskode
define('SMTP_FROM_EMAIL', 'xxx'); // Afsender email
define('SMTP_FROM_NAME', 'xxx');          // Afsender navn

// Email URL (bruges i email templates - uden trailing slash)
// Hvis du bruger et andet domæne til emails end SITE_URL
define('EMAIL_BASE_URL', 'xxx');

// Cron job secret (bruges til sikring af cron scripts)
define('CRON_SECRET', 'xxx');
define('CRON_LOG_FILE', __DIR__ . '/cron_import_log.txt');

// API Configuration for F1 data
define('F1_API_BASE', 'https://api.jolpi.ca/ergast/f1');  // Jolpica (Ergast successor)
define('F1_API_TIMEOUT', 30);


// ============================================
// SECURE SESSION CONFIGURATION
// ============================================
// Set secure session cookie parameters BEFORE session_start()
ini_set('session.cookie_secure', 1);       // Only send cookie over HTTPS
ini_set('session.cookie_httponly', 1);     // Prevent JavaScript access to session cookie
ini_set('session.cookie_samesite', 'Lax'); // CSRF protection
ini_set('session.use_strict_mode', 1);     // Reject uninitialized session IDs
ini_set('session.use_only_cookies', 1);    // Only use cookies for sessions (no URL params)

session_start();

// ============================================
// LOAD UTILITY FUNCTIONS
// ============================================
require_once __DIR__ . '/public/functions.php';


// ============================================
// CSRF BESKYTTELSE
// ============================================
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . escape($token) . '">';
}

function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
    }
}



// ============================================
// TIMEZONE INDSTILLING
// ============================================
// VIGTIGT: Alle løbstider i databasen er i CET (Central European Time)
// Sørg for at denne timezone passer til din server og brugere.
// For Danmark/Europa bruges 'Europe/Copenhagen' som automatisk 
// håndterer skift mellem CET (vinter) og CEST (sommer).
date_default_timezone_set('Europe/Copenhagen');

// Database forbindelse
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database forbindelse fejlede: " . $e->getMessage());
        }
    }
    return $pdo;
}



// Password funktioner
function hashPassword($password) {
    return password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password . PASSWORD_PEPPER, $hash);
}
