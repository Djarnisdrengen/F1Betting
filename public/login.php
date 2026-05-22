<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';

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
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['lang']    = in_array($user['language'] ?? '', ['da', 'en']) ? $user['language'] : 'da';
            session_regenerate_id(true);
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            setTheme($user['theme']      ?? $anonTheme);
            setFont($user['font_stack']  ?? $anonFont);
            header("Location: index.php");
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
                <div>
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-input" required placeholder="name@example.com">
                </div>
                <div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;"><?= t('password') ?></label>
                        <a href="forgot_password.php" style="font-family:var(--font-display);font-weight:600;font-size:12px;color:var(--f1-red-light);text-decoration:none;"><?= t('forgot_password') ?></a>
                    </div>
                    <input type="password" name="password" class="form-input" required placeholder="••••••••">
                </div>
                <button type="submit" class="hf-cta-primary" style="width:100%;margin-top:8px;">
                    <?= t('login') ?> <span class="arrow">→</span>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
