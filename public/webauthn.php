<?php
// ============================================
// PASSKEY (WebAuthn) JSON ENDPOINT
// POST-only. Six actions across three flows:
//   register_options / register_verify   — logged-in, Security tab
//   challenge_options / challenge_verify — mfa_pending, second factor
//   login_options / login_verify         — anonymous, passwordless
// challenge_verify and login_verify are the ONLY places besides
// login.php / mfa_challenge.php that set $_SESSION['user_id'].
// ============================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mfa.php';
require_once __DIR__ . '/includes/passkey.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}
requireCsrf();

header('Content-Type: application/json');

function passkeyJsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

// Every failure mode returns this byte-identical body (enumeration parity).
// HTTP stays 200: the reverse proxy replaces non-2xx FastCGI responses with its
// own error page (see login.php). Detail goes to APP_LOG_FILE only.
function passkeyJsonFail(): void {
    passkeyJsonOut(['error' => t('passkey_error')]);
}

// Same-origin relative paths only — mirrors sanitizeLoginRedirect() in login.php.
function passkeySanitizeRedirect(string $url): string {
    if ($url !== '' && $url[0] === '/' && (strlen($url) < 2 || $url[1] !== '/')) {
        return $url;
    }
    return '/index.php';
}

// Promotion contract shared with login.php / mfa_challenge.php: set user_id,
// rotate the session id, stamp last_login, apply stored preferences.
function passkeyPromoteSession(PDO $db, string $uid, string $anonTheme, string $anonFont): void {
    $st = $db->prepare("SELECT language, theme, font_stack FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch() ?: [];

    establishSession($db, $uid);
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$uid]);
    setLang($u['language']   ?? 'da');
    setTheme($u['theme']     ?? $anonTheme);
    setFont($u['font_stack'] ?? $anonFont);
}

$db     = getDB();
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$action = $_POST['action'] ?? '';

switch ($action) {

    // ── Registration (logged-in, from the Security tab) ─────────────────────
    case 'register_options': {
        $user = getCurrentUser();
        if (!$user) passkeyJsonFail();
        try {
            passkeyJsonOut(['options' => passkeyRegisterOptions($db, $user)]);
        } catch (Throwable $e) {
            logToFile(APP_LOG_FILE, '[PASSKEY] register_options failed: ' . $e->getMessage());
            passkeyJsonFail();
        }
        break;
    }

    case 'register_verify': {
        $user = getCurrentUser();
        if (!$user) passkeyJsonFail();

        $clientDataJSON    = base64_decode($_POST['clientDataJSON'] ?? '', true);
        $attestationObject = base64_decode($_POST['attestationObject'] ?? '', true);
        if (!$clientDataJSON || !$attestationObject) passkeyJsonFail();

        $ok = passkeyRegisterVerify(
            $db, $user['id'], $clientDataJSON, $attestationObject,
            $_POST['transports'] ?? null, $_POST['label'] ?? null
        );
        if (!$ok) passkeyJsonFail();

        // First enrolled factor → issue recovery codes (shown once by profile.php
        // via the existing flash block; passkey.js reloads the page on success).
        $codes = ensureRecoveryCodes($db, $user['id']);
        if ($codes) $_SESSION['flash_recovery_codes'] = $codes;

        passkeyJsonOut(['ok' => true]);
        break;
    }

    // ── Second-factor challenge (mfa_pending, from mfa_challenge.php) ───────
    case 'challenge_options': {
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!$pending || ($pending['exp'] ?? 0) < time()) passkeyJsonFail();
        if (!passkeyActive($db, $pending['uid'])) passkeyJsonFail();
        try {
            passkeyJsonOut(['options' => passkeyAssertOptions($db, $pending['uid'], 'challenge')]);
        } catch (Throwable $e) {
            logToFile(APP_LOG_FILE, '[PASSKEY] challenge_options failed: ' . $e->getMessage());
            passkeyJsonFail();
        }
        break;
    }

    case 'challenge_verify': {
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!$pending || ($pending['exp'] ?? 0) < time()) passkeyJsonFail();

        $rateLimited = true; // fail closed: an unreachable rate-limit table must not be treated as "no limit"
        try {
            $rateLimited = isRateLimited($db, $ip, 'mfa', $pending['uid']);
        } catch (Exception $e) {
            logToFile(APP_LOG_FILE, '[RATE-LIMIT] mfa passkey check failed, failing closed: ' . $e->getMessage());
        }
        if ($rateLimited) {
            header('Retry-After: 900'); // the one allowed divergence (matches mfa_challenge.php)
            passkeyJsonFail();
        }

        $uid = null;
        try {
            $uid = passkeyAssertVerify($db, $_POST, $pending['uid'], 'challenge');
        } catch (Throwable $e) {
            logToFile(APP_LOG_FILE, '[PASSKEY] challenge_verify failed: ' . $e->getMessage());
        }
        if ($uid === null) {
            try { recordLoginAttempt($db, $ip, 'mfa', $pending['uid']); } catch (Exception $e) {}
            passkeyJsonFail();
        }

        $redirect  = passkeySanitizeRedirect($pending['redirect'] ?? '/index.php');
        $anonTheme = $pending['anonTheme'] ?? 'dark';
        $anonFont  = $pending['anonFont']  ?? 'system';
        unset($_SESSION['mfa_pending']);
        try { clearLoginAttempts($db, 'mfa', $uid); } catch (Exception $e) {}
        passkeyPromoteSession($db, $uid, $anonTheme, $anonFont);
        logLoginMethod('password+passkey', $uid);

        passkeyJsonOut(['ok' => true, 'redirect' => $redirect]);
        break;
    }

    // ── Passwordless login (anonymous, from login.php) ──────────────────────
    case 'login_options': {
        if (getCurrentUser()) passkeyJsonFail();
        try {
            // Discoverable credentials: no user context, empty allow-list.
            passkeyJsonOut(['options' => passkeyAssertOptions($db, null, 'login')]);
        } catch (Throwable $e) {
            logToFile(APP_LOG_FILE, '[PASSKEY] login_options failed: ' . $e->getMessage());
            passkeyJsonFail();
        }
        break;
    }

    case 'login_verify': {
        if (getCurrentUser()) passkeyJsonFail();

        // No account is known yet — a discoverable-credential assertion carries its own
        // credential id, and we only learn which user it belongs to once passkeyAssertVerify
        // resolves it below. Failures before that point can only be rate-limited by IP.
        $rateLimited = true; // fail closed: an unreachable rate-limit table must not be treated as "no limit"
        try {
            $rateLimited = isRateLimited($db, $ip, 'login', '');
        } catch (Exception $e) {
            logToFile(APP_LOG_FILE, '[RATE-LIMIT] passwordless login check failed, failing closed: ' . $e->getMessage());
        }
        if ($rateLimited) {
            header('Retry-After: 900');
            passkeyJsonFail();
        }

        $uid = null;
        try {
            $uid = passkeyAssertVerify($db, $_POST, null, 'login');
        } catch (Throwable $e) {
            logToFile(APP_LOG_FILE, '[PASSKEY] login_verify failed: ' . $e->getMessage());
        }
        if ($uid === null) {
            try { recordLoginAttempt($db, $ip, 'login', ''); } catch (Exception $e) {}
            passkeyJsonFail();
        }

        $redirect  = passkeySanitizeRedirect($_POST['redirect'] ?? '/index.php');
        $anonTheme = $_SESSION['theme']      ?? $_COOKIE['f1_theme'] ?? 'dark';
        $anonFont  = $_SESSION['font_stack'] ?? $_COOKIE['f1_font']  ?? 'system';
        try { clearLoginAttempts($db, 'login', $uid); } catch (Exception $e) {}
        passkeyPromoteSession($db, $uid, $anonTheme, $anonFont);
        logLoginMethod('passkey', $uid);

        passkeyJsonOut(['ok' => true, 'redirect' => $redirect]);
        break;
    }

    default:
        passkeyJsonFail();
}
