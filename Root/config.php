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
// INPUT VALIDERING
// ============================================
function sanitizeString($str) {
    return trim(htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'));
}

function sanitizeEmail($email) {
    $email = trim($email ?? '');
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : false;
}

function sanitizeInt($value, $min = null, $max = null) {
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) return false;
    if ($min !== null && $int < $min) return false;
    if ($max !== null && $int > $max) return false;
    return $int;
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

// Generer UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Password funktioner
function hashPassword($password) {
    return password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password . PASSWORD_PEPPER, $hash);
}

// Bruger funktioner
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, display_name, role, points, stars, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function requireLogin() {
    if (!getCurrentUser()) {
        header("Location: login.php");
        exit;
    }
}

function requireAdmin() {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        header("Location: index.php");
        exit;
    }
}

// Sprog funktioner
function getLang() {
    return $_SESSION['lang'] ?? 'da';
}

function setLang($lang) {
    $_SESSION['lang'] = in_array($lang, ['da', 'en']) ? $lang : 'da';
}

function t($key) {
    $translations = [
        'da' => [
            'home' => 'Hjem',
            'races' => 'Løb',
            'leaderboard' => 'Rangliste',
            'admin' => 'Admin',
            'profile' => 'Profil',
            'login' => 'Log ind',
            'register' => 'Registrer',
            'logout' => 'Log ud',
            'place_bet' => 'Placer Bet',
            'upcoming_races' => 'Kommende Løb',
            'your_bets' => 'Dine Bets',
            'all_bets' => 'Alle Bets',
            'points' => 'Point',
            'stars' => 'Stjerner',
            'rank' => 'Rang',
            'user' => 'Bruger',
            'placed_at' => 'Placeret',
            'betting_open' => 'Betting Åben',
            'betting_closed' => 'Betting Lukket',
            'betting_not_open' => 'Betting Ikke Åben',
            'race_completed' => 'Løb Afsluttet',
            'submit' => 'Indsend',
            'save' => 'Gem',
            'delete' => 'Slet',
            'edit' => 'Rediger',
            'add' => 'Tilføj',
            'cancel' => 'Annuller',
            'drivers' => 'Kørere',
            'users' => 'Brugere',
            'bets' => 'Bets',
            'settings' => 'Indstillinger',
            'display_name' => 'Visningsnavn',
            'email' => 'E-mail',
            'password' => 'Adgangskode',
            'team' => 'Hold',
            'number' => 'Nummer',
            'name' => 'Navn',
            'location' => 'Sted',
            'race_date' => 'Løbsdato',
            'race_time' => 'Starttid',
            'qualifying' => 'Kvalifikation',
            'results' => 'Resultater',
            'select_driver' => 'Vælg kører',
            'betting_window' => 'Betting åbner 48t før løb',
            'points_system' => 'Point: P1=25, P2=18, P3=15, +5 for top 3 forkert position',
            'no_bets' => 'Ingen bets endnu',
            'perfect_bet' => 'Perfekt!',
            'already_bet' => 'Du har allerede et bet på dette løb',
            'bet_placed' => 'Bet placeret!',
            'error' => 'Der opstod en fejl',
            'invalid_credentials' => 'Forkert email eller adgangskode',
            'email_exists' => 'Email er allerede registreret',
            'registration_success' => 'Registrering gennemført!',
            'profile_updated' => 'Profil opdateret!',
            'bet_updated' => 'Bet opdateret!',
        ],
        'en' => [
            'home' => 'Home',
            'races' => 'Races',
            'leaderboard' => 'Leaderboard',
            'admin' => 'Admin',
            'profile' => 'Profile',
            'login' => 'Login',
            'register' => 'Register',
            'logout' => 'Logout',
            'place_bet' => 'Place Bet',
            'upcoming_races' => 'Upcoming Races',
            'your_bets' => 'Your Bets',
            'all_bets' => 'All Bets',
            'points' => 'Points',
            'stars' => 'Stars',
            'rank' => 'Rank',
            'user' => 'User',
            'placed_at' => 'Placed At',
            'betting_open' => 'Betting Open',
            'betting_closed' => 'Betting Closed',
            'betting_not_open' => 'Betting Not Open',
            'race_completed' => 'Race Completed',
            'submit' => 'Submit',
            'save' => 'Save',
            'delete' => 'Delete',
            'edit' => 'Edit',
            'add' => 'Add',
            'cancel' => 'Cancel',
            'drivers' => 'Drivers',
            'users' => 'Users',
            'bets' => 'Bets',
            'settings' => 'Settings',
            'display_name' => 'Display Name',
            'email' => 'Email',
            'password' => 'Password',
            'team' => 'Team',
            'number' => 'Number',
            'name' => 'Name',
            'location' => 'Location',
            'race_date' => 'Race Date',
            'race_time' => 'Race Time',
            'qualifying' => 'Qualifying',
            'results' => 'Results',
            'select_driver' => 'Select driver',
            'betting_window' => 'Betting opens 48h before race',
            'points_system' => 'Points: P1=25, P2=18, P3=15, +5 for top 3 wrong position',
            'no_bets' => 'No bets yet',
            'perfect_bet' => 'Perfect!',
            'already_bet' => 'You already have a bet for this race',
            'bet_placed' => 'Bet placed!',
            'error' => 'An error occurred',
            'invalid_credentials' => 'Invalid email or password',
            'email_exists' => 'Email already registered',
            'registration_success' => 'Registration successful!',
            'profile_updated' => 'Profile updated!',
            'bet_updated' => 'Bet updated!',
        ]
    ];
    $lang = getLang();
    return $translations[$lang][$key] ?? $key;
}

// Tema funktioner
function getTheme() {
    return $_SESSION['theme'] ?? 'dark';
}

function setTheme($theme) {
    $_SESSION['theme'] = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
}

// Hjælpefunktioner
function getBettingStatus($race, $settings = null) {
    if (!$settings) {
        $settings = getSettings();
    }
    $bettingWindowHours = $settings['betting_window_hours'] ?? 48;
    
    $raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
    $now = new DateTime();
    $bettingOpens = clone $raceDateTime;
    $bettingOpens->modify("-{$bettingWindowHours} hours");
    
    if ($race['result_p1']) {
        return ['status' => 'completed', 'label' => t('race_completed'), 'class' => 'status-completed'];
    }
    if ($now < $bettingOpens) {
        return ['status' => 'pending', 'label' => t('betting_not_open'), 'class' => 'status-pending'];
    }
    if ($now >= $raceDateTime) {
        return ['status' => 'closed', 'label' => t('betting_closed'), 'class' => 'status-closed'];
    }
    return ['status' => 'open', 'label' => t('betting_open'), 'class' => 'status-open'];
}

function getSettings() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM settings WHERE id = 1");
    return $stmt->fetch() ?: [
        'app_title' => 'F1 Betting',
        'app_year' => '2025',
        'hero_title_da' => 'Forudsig Podiet',
        'hero_title_en' => 'Predict the Podium',
        'hero_text_da' => '',
        'hero_text_en' => '',
        'points_p1' => 25,
        'points_p2' => 18,
        'points_p3' => 15,
        'points_wrong_pos' => 5,
        'betting_window_hours' => 48
    ];
}

function escape($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
