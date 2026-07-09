<?php
// ============================================
// UTILITY FUNCTIONS
// Non-security-sensitive helper functions
// ============================================

// ============================================
// INPUT VALIDERING
// ============================================
function sanitizeString($str) {
    return trim(strip_tags($str ?? ''));
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

// Generer UUID (v4). F11: random_bytes() is a CSPRNG — mt_rand() was not, so exposed
// UUIDs used to be guessable. Object fetches are still ownership-scoped (WHERE user_id
// = ?) regardless; this is defense-in-depth.
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
    return $_SESSION['lang'] ?? $_COOKIE['f1_lang'] ?? 'da';
}

function setLang($lang) {
    $valid = in_array($lang, ['da', 'en']) ? $lang : 'da';
    $_SESSION['lang'] = $valid;
    setcookie('f1_lang', $valid, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!empty($_SESSION['user_id'])) {
        getDB()->prepare("UPDATE users SET language = ? WHERE id = ?")
               ->execute([$valid, $_SESSION['user_id']]);
    }
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
    return $_SESSION['theme'] ?? $_COOKIE['f1_theme'] ?? 'dark';
}

function setTheme($theme) {
    $valid = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
    $_SESSION['theme'] = $valid;
    setcookie('f1_theme', $valid, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!empty($_SESSION['user_id'])) {
        getDB()->prepare("UPDATE users SET theme = ? WHERE id = ?")
               ->execute([$valid, $_SESSION['user_id']]);
    }
}

function getFont() {
    return $_SESSION['font_stack'] ?? $_COOKIE['f1_font'] ?? 'system';
}

function setFont($font) {
    $valid = in_array($font, ['system', 'editorial']) ? $font : 'system';
    $_SESSION['font_stack'] = $valid;
    setcookie('f1_font', $valid, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!empty($_SESSION['user_id'])) {
        getDB()->prepare("UPDATE users SET font_stack = ? WHERE id = ?")
               ->execute([$valid, $_SESSION['user_id']]);
    }
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
// F12: idle + absolute session timeout, in seconds. Checked below against the stamps
// establishSession() sets at login.
const SESSION_IDLE_TIMEOUT     = 1800;  // 30 minutes of inactivity
const SESSION_ABSOLUTE_TIMEOUT = 43200; // 12 hours since login, regardless of activity

// Bootstraps session state after a successful login — the same contract for every
// login path (password, MFA challenge, passkey): rotates the session id and stamps
// login_time/last_activity (used below for the idle/absolute timeout) plus the
// account's current password_changed_at (used to detect, on this session's next
// request, that the password was since changed elsewhere and this session is stale).
function establishSession(PDO $db, string $uid): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $stmt = $db->prepare("SELECT password_changed_at FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $_SESSION['pwd_changed_at'] = $stmt->fetchColumn() ?: null;
}

// Ends the current session while preserving the language cookie/session value,
// mirroring logout.php's own session_unset()+regenerate sequence.
function invalidateSession(): void {
    $lang = $_SESSION['lang'] ?? 'da';
    session_unset();
    $_SESSION['lang'] = $lang;
    session_regenerate_id(true);
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    // F12: sessions from before this shipped have no login_time/last_activity yet —
    // treat those as "not expired" rather than force-logging out everyone on deploy.
    $now = time();
    if (($_SESSION['last_activity'] ?? $now) < $now - SESSION_IDLE_TIMEOUT
        || ($_SESSION['login_time'] ?? $now) < $now - SESSION_ABSOLUTE_TIMEOUT) {
        invalidateSession();
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, display_name, role, points, stars, created_at, in_competition, language, theme, font_stack, last_login, password_changed_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        invalidateSession();
        return null;
    }

    // Only sessions established after this feature shipped carry 'pwd_changed_at' —
    // absence means "predates this fix", not "stale".
    if (array_key_exists('pwd_changed_at', $_SESSION) && $_SESSION['pwd_changed_at'] !== $user['password_changed_at']) {
        invalidateSession();
        return null;
    }

    $_SESSION['last_activity'] = $now;
    unset($user['password_changed_at']);
    return $user;
}

function requireLogin() {
    if (!getCurrentUser()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: /login.php?redirect=" . $redirect);
        exit;
    }
}

function requireAdmin() {
    $user = getCurrentUser();
    if (!$user) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: /login.php?redirect=" . $redirect);
        exit;
    }
    if ($user['role'] !== 'admin') {
        header("Location: /index.php");
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
    $db->exec("CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        race_id VARCHAR(36) NOT NULL,
        `rank` INT NOT NULL,
        points INT NOT NULL,
        scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_race (user_id, race_id)
    ) DEFAULT CHARSET=utf8mb4");

    $latestScored = $db->query(
        "SELECT id FROM races WHERE result_p1 IS NOT NULL
         ORDER BY race_date DESC, race_time DESC LIMIT 1"
    )->fetch();
    $latestRaceId = $latestScored ? $latestScored['id'] : null;

    if ($latestRaceId) {
        $sql = "
            SELECT u.*, COUNT(b.id) AS bets_count,
                (SELECT snap.`rank`
                 FROM leaderboard_snapshots snap
                 JOIN races r2 ON snap.race_id = r2.id
                 WHERE snap.user_id = u.id
                 ORDER BY r2.race_date DESC, r2.race_time DESC
                 LIMIT 1 OFFSET 1) AS prev_rank,
                lb.points AS last_bet_points
            FROM users u
            LEFT JOIN bets b  ON b.user_id = u.id
            LEFT JOIN bets lb ON lb.user_id = u.id AND lb.race_id = :latestRaceId
            WHERE u.in_competition = 1
            GROUP BY u.id, lb.points
            ORDER BY u.stars DESC, u.points DESC
        ";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute(['latestRaceId' => $latestRaceId]);
        $rows = $stmt->fetchAll();
    } else {
        $sql = "
            SELECT u.*, COUNT(b.id) AS bets_count,
                NULL AS prev_rank,
                NULL AS last_bet_points
            FROM users u
            LEFT JOIN bets b ON b.user_id = u.id
            WHERE u.in_competition = 1
            GROUP BY u.id
            ORDER BY u.stars DESC, u.points DESC
        ";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        $rows = $db->query($sql)->fetchAll();
    }

    foreach ($rows as $i => &$row) {
        $prev = $row['prev_rank'];
        $row['rank_delta'] = ($prev !== null) ? (int)$prev - ($i + 1) : null;
    }
    unset($row);
    return $rows;
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

// F12: minimum length + basic composition check. Returns a translated error string,
// or '' on success. Used by register/reset/profile/admin password paths.
function validatePasswordStrength(string $password): string {
    if (strlen($password) < 10) {
        return t('password_min_length');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return t('password_too_weak');
    }
    return '';
}

// ── Bearer token auth (cron/tool endpoints) ─────────────────────────────────────
// Reads the bearer token from the Authorization header, if present. Apache/mod_php
// sometimes omits $_SERVER['HTTP_AUTHORIZATION'] unless CGIPassAuth or a rewrite rule
// is configured; some proxies surface it via REDIRECT_HTTP_AUTHORIZATION instead. F6:
// callers used to pass these tokens as ?token=... query strings, which land in
// web-server/proxy access logs and Referer headers — this reads the replacement
// transport. Callers still compare the result with hash_equals(), same as before.
function getBearerToken(): ?string {
    $header = null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $header = $value; break; }
        }
    }
    $header = $header ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!$header || stripos($header, 'Bearer ') !== 0) return null;
    return substr($header, 7);
}

// ── Rate limiting ──────────────────────────────────────────────────────────────
// Sliding 15-minute window, checked both per-IP (catches broad abuse from one
// address) and per-account (catches a distributed attack on one victim regardless
// of source IP, and lets the rest of a shared/NAT'd IP keep working once its real
// owner proves who they are). 'login' (password step + passwordless passkey) and
// 'mfa' (second-factor challenge: code guesses and resends) are separate scopes —
// separate buckets — so exhausting one budget never blocks the other. The account
// bucket for 'mfa' is stricter than 'login': a 6-digit OTP/TOTP code has a far
// smaller keyspace than a password, so a targeted account needs tighter guessing
// room even though the IP threshold stays the same (shared IPs see normal traffic
// from both steps).
function rateLimitThreshold(string $scope, string $bucket): int {
    if ($bucket === 'account' && $scope === 'mfa') {
        return 3;
    }
    return 5;
}

function isRateLimited(PDO $db, string $ip, string $scope, string $account): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND scope = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$ip, $scope]);
    if ((int)$stmt->fetchColumn() >= rateLimitThreshold($scope, 'ip')) {
        return true;
    }

    if ($account === '') {
        return false; // target account not known yet (e.g. an unresolved passkey assertion)
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE account = ? AND scope = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$account, $scope]);
    return (int)$stmt->fetchColumn() >= rateLimitThreshold($scope, 'account');
}

function recordLoginAttempt(PDO $db, string $ip, string $scope, string $account): void {
    $db->prepare("INSERT INTO login_attempts (ip, scope, account) VALUES (?, ?, ?)")
       ->execute([$ip, $scope, $account !== '' ? $account : null]);
    // Purge records older than 1 hour to keep the table lean
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->execute();
}

// Clears only this account's own counter for this scope — proving who you are resets
// your own lockout. Deliberately never touches the IP-wide bucket: clearing that on
// every success would let an attacker reset the shared budget by logging into their
// own account from the same IP mid-attack.
function clearLoginAttempts(PDO $db, string $scope, string $account): void {
    if ($account === '') return;
    $db->prepare("DELETE FROM login_attempts WHERE account = ? AND scope = ?")->execute([$account, $scope]);
}

// ============================================
// BET VALIDATION
// ============================================

// Validates a P1/P2/P3 combination. Returns a translated error string, or '' on success.
// $context must contain quali_p1/p2/p3 keys (from a race or bet row). $validDriverIds
// is a list of real driver IDs (e.g. array_keys($driversById)) — F10: without this, a
// crafted POST could store IDs that don't exist in `drivers`.
function validateBetCombination(string $p1, string $p2, string $p3, array $context, array $existingBets, array $validDriverIds): string {
    if (!$p1 || !$p2 || !$p3) {
        return t('select_all_positions');
    }
    if (!in_array($p1, $validDriverIds, true) || !in_array($p2, $validDriverIds, true) || !in_array($p3, $validDriverIds, true)) {
        return t('invalid_driver');
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
