<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mfa.php';

if (getCurrentUser()) {
    header("Location: /index.php");
    exit;
}

// Validate redirect: same-origin relative paths only (starts with / but not //)
function sanitizeLoginRedirect(string $url): string {
    if ($url !== '' && $url[0] === '/' && (strlen($url) < 2 || $url[1] !== '/')) {
        return $url;
    }
    return '/index.php';
}

$redirect = sanitizeLoginRedirect($_POST['redirect'] ?? $_GET['redirect'] ?? '');

$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $db       = getDB();
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
    $email    = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $rateLimited = isRateLimited($db, $ip);
    } catch (Exception $e) {
        $rateLimited = false;
    }

    if ($rateLimited) {
        // OpenResty (reverse proxy) intercepts non-2xx FastCGI responses and replaces
        // them with its own 400 page, so we cannot use http_response_code(429) here.
        // Instead, send Retry-After (detectable by both clients and the security scanner)
        // and fall through to render the normal login page with the error message.
        header('Retry-After: 900');
        $error = t('rate_limited');
    } elseif ($email && $password) {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && verifyPassword($password, $user['password'])) {
            try { clearLoginAttempts($db, $ip); } catch (Exception $e) {}
            $anonTheme = $_SESSION['theme']      ?? $_COOKIE['f1_theme'] ?? 'dark';
            $anonFont  = $_SESSION['font_stack'] ?? $_COOKIE['f1_font']  ?? 'system';

            // Two-step login: if the member has opted into any second factor, the password
            // step alone must NOT grant a session. Hold them in a pending state (no user_id)
            // and send them to the challenge, which is the only place that promotes the session.
            if (userHasActiveFactor($db, $user['id'])) {
                $_SESSION['mfa_pending'] = [
                    'uid'       => $user['id'],
                    'exp'       => time() + 600,           // 10-minute window
                    'redirect'  => $redirect,
                    'anonTheme' => $anonTheme,
                    'anonFont'  => $anonFont,
                    'email_sent'=> false,                  // set once an email code is issued
                ];
                session_regenerate_id(true);               // rotate the pre-auth session id
                // Only pre-send the email code when it's the member's default method (so it's
                // waiting in their inbox on arrival). Otherwise it's sent on demand from the
                // challenge screen — no email until they actually pick that method.
                if (getMfaDefaultMethod($db, $user['id']) === 'email') {
                    try {
                        if (issueEmailOtp($db, $user['id'], 'login')) {
                            $_SESSION['mfa_pending']['email_sent'] = true;
                        }
                    } catch (Exception $e) {}
                }
                header('Location: /mfa_challenge.php');
                exit;
            }

            $_SESSION['user_id'] = $user['id'];
            session_regenerate_id(true);
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            setLang($user['language']    ?? 'da');
            setTheme($user['theme']      ?? $anonTheme);
            setFont($user['font_stack']  ?? $anonFont);
            header("Location: " . $redirect);
            exit;
        } else {
            try { recordLoginAttempt($db, $ip); } catch (Exception $e) {}
            $error = t('invalid_credentials');
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>
    <div class="hf-login-grid">
        <div class="hf-login-intro">
            <div class="hf-hero-eyebrow"><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></div>
            <h1 style="font-family:var(--font-display);font-weight:900;font-size:clamp(48px,8vw,80px);letter-spacing:-0.02em;line-height:0.95;margin:12px 0 16px;"><?= t('login_heading') ?></h1>
            <p style="color:var(--text-secondary);font-size:17px;line-height:1.55;max-width:44ch;"><?= t('login_intro') ?></p>
        </div>
        <div class="hf-login-card">
            <div>
                <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= t('welcome_back') ?></div>
                <h2 style="font-family:var(--font-display);font-weight:900;font-size:28px;letter-spacing:-0.02em;margin:0 0 8px;"><?= t('login') ?></h2>
            </div>
            <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
                <?= csrfField() ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-input" required placeholder="name@example.com" autocomplete="username webauthn">
                </div>
                <div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;"><?= t('password') ?></label>
                        <a href="forgot_password.php" style="font-family:var(--font-display);font-weight:600;font-size:12px;color:var(--f1-red-light);text-decoration:none;"><?= t('forgot_password') ?></a>
                    </div>
                    <input type="password" name="password" class="form-input" required placeholder="••••••••" autocomplete="current-password">
                </div>
                <button type="submit" class="hf-cta-primary" style="width:100%;margin-top:8px;">
                    <?= t('login') ?> <span class="arrow">→</span>
                </button>
            </form>
            <?php // Passwordless login (discoverable credential). Starts hidden — passkey.js
                  // reveals it only when the browser supports WebAuthn, so no-JS and
                  // unsupported browsers never see a dead button. No inline display style
                  // on this element: it would override the hidden attribute. ?>
            <div hidden data-passkey-supported data-passkey-scope>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color);">
                    <button type="button" class="btn btn-secondary" style="width:100%;" data-passkey-login data-redirect="<?= htmlspecialchars($redirect) ?>" data-testid="passkey-login"><i class="fas fa-fingerprint"></i> <?= t('passkey_signin') ?></button>
                    <p data-passkey-error hidden role="alert" style="font-size:13px;margin:0;color:var(--f1-red-light);" data-testid="passkey-login-error"><?= t('passkey_error') ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script nonce="<?= $nonce ?>" src="assets/js/passkey.js"></script>
