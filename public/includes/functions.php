<?php
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

function driverLabel($driver) {
    $parts = explode(' ', $driver['name'] ?? '');
    $last  = array_pop($parts);
    $first = implode(' ', $parts);
    return escape($last) . ', ' . escape($first) . ' (#' . intval($driver['number']) . ', ' . escape($driver['team']) . ')';
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
// LOGGING
// ============================================
function logToFile($file, $message) {
    if (file_exists($file) && filesize($file) > 200 * 1024) {
        rename($file, $file . '.bak');
    }
    file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
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

function t($key, $lang = null) {
    static $translations = null;
    if ($translations === null) {
        $base = __DIR__ . '/../lang/';
        $user  = require $base . 'user.php';
        $admin = require $base . 'admin.php';
        $email = require $base . 'email.php';
        foreach (['da', 'en'] as $l) {
            $translations[$l] = array_merge(
                $user[$l]  ?? [],
                $admin[$l] ?? [],
                $email[$l] ?? []
            );
        }
    }
    $useLang = $lang ?? getLang();
    return $translations[$useLang][$key] ?? $key;
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

function getPalette() {
    return $_SESSION['palette'] ?? 'broadcast';
}

function setPalette($palette) {
    $_SESSION['palette'] = in_array($palette, ['broadcast', 'clubhouse']) ? $palette : 'broadcast';
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
    $stmt = $db->prepare("SELECT id, email, display_name, role, points, stars, created_at, in_competition, last_login FROM users WHERE id = ?");
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
    if (!$user) {
        header("Location: login.php");
        exit;
    }
    if ($user['role'] !== 'admin') {
        header("Location: index.php");
        exit;
    }
}

// ============================================
// DISPLAY HELPERS
// ============================================

function displayUserName(array $user): string {
    return escape($user['display_name'] ?: $user['email']);
}

function userInitial(array $user): string {
    return escape(strtoupper(substr($user['display_name'] ?: $user['email'], 0, 1)));
}

// Returns the driver's last name, HTML-escaped.
function driverLastName(array $driver): string {
    $parts = explode(' ', $driver['name'] ?? '');
    return escape(array_pop($parts));
}

// Formats race date + time as "d M Y - HH:MM CET".
function formatRaceDateTime(string $date, string $time): string {
    return date('d M Y', strtotime($date)) . ' - ' . substr($time, 0, 5) . ' CET';
}

// ============================================
// DATABASE HELPERS
// ============================================

// Returns [$drivers, $driversById].
// $order = 'name' sorts by last name (use for dropdowns / driver selection).
// $order = 'number' sorts by car number (use for race result display).
function fetchDrivers(PDO $db, string $order = 'name'): array {
    $sql = $order === 'number'
        ? "SELECT * FROM drivers ORDER BY number"
        : "SELECT * FROM drivers ORDER BY SUBSTRING_INDEX(name, ' ', -1)";
    $drivers = $db->query($sql)->fetchAll();
    return [$drivers, array_column($drivers, null, 'id')];
}

// Returns all races ordered by date ascending.
function getRaces(PDO $db): array {
    return $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
}

// Returns leaderboard rows ordered by stars desc, points desc. Pass a limit to cap results.
function getLeaderboard(PDO $db, ?int $limit = null): array {
    $sql = "
        SELECT u.*, COUNT(b.id) AS bets_count
        FROM users u
        LEFT JOIN bets b ON u.id = b.user_id
        WHERE u.in_competition = 1
        GROUP BY u.id
        ORDER BY u.stars DESC, u.points DESC
    ";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . $limit;
    }
    return $db->query($sql)->fetchAll();
}

// Returns all bets (with display_name + email) grouped by race_id.
function getBetsByRace(PDO $db): array {
    $bets = $db->query("
        SELECT b.*, u.display_name, u.email
        FROM bets b
        JOIN users u ON b.user_id = u.id
        ORDER BY b.placed_at DESC
    ")->fetchAll();
    $grouped = [];
    foreach ($bets as $bet) {
        $grouped[$bet['race_id']][] = $bet;
    }
    return $grouped;
}

// ============================================
// DATABASE
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            if (defined('APP_LOG_FILE')) {
                logToFile(APP_LOG_FILE, '[ERROR] DB connection failed: ' . $e->getMessage());
            }
            die("Database connection failed");
        }
    }
    return $pdo;
}

// ============================================
// PASSWORDS
// ============================================
function hashPassword($password) {
    return password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password . PASSWORD_PEPPER, $hash);
}

// ── Rate limiting ──────────────────────────────────────────────────────────────
// 3 failed attempts per IP within a 15-minute sliding window triggers a block.

function isRateLimited(PDO $db, string $ip): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn() >= 5;
}

function recordLoginAttempt(PDO $db, string $ip): void {
    $db->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
    // Purge records older than 1 hour to keep the table lean
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->execute();
}

function clearLoginAttempts(PDO $db, string $ip): void {
    $db->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
}

// ============================================
// BET VALIDATION
// ============================================

// Validates a P1/P2/P3 combination. Returns a translated error string, or '' on success.
// $context must contain quali_p1/p2/p3 keys (from a race or bet row).
function validateBetCombination(string $p1, string $p2, string $p3, array $context, array $existingBets): string {
    if (!$p1 || !$p2 || !$p3) {
        return t('select_all_positions');
    }
    if ($p1 === $p2 || $p1 === $p3 || $p2 === $p3) {
        return t('no_same_driver');
    }
    if ($context['quali_p1'] && $p1 === $context['quali_p1'] && $p2 === $context['quali_p2'] && $p3 === $context['quali_p3']) {
        return t('quali_match_error');
    }
    foreach ($existingBets as $eb) {
        if ($eb['p1'] === $p1 && $eb['p2'] === $p2 && $eb['p3'] === $p3) {
            return t('combo_taken');
        }
    }
    return '';
}

// ============================================
// CSRF
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
    return '<input type="hidden" name="csrf_token" value="' . escape(generateCsrfToken()) . '">';
}

function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
    }
}
