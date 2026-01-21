<?php
require_once __DIR__ . '/../config.php';

// ============================================
// UTILITY FUNCTIONS
// Non-security-sensitive helper functions
// ============================================

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
// ESCAPE HELPER
// ============================================
function escape($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
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

// ============================================
// SPROG FUNKTIONER
// ============================================
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

// ============================================
// TEMA FUNKTIONER
// ============================================
function getTheme() {
    return $_SESSION['theme'] ?? 'dark';
}

function setTheme($theme) {
    $_SESSION['theme'] = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
}

// ============================================
// HJÆLPEFUNKTIONER - BETTING & SETTINGS
// ============================================
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

// ============================================
// USER FUNCTIONS
// ============================================
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, display_name, role, points, stars, created_at, in_competition FROM users WHERE id = ?");
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
