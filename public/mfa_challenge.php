<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mfa.php';

// Already fully authenticated → nothing to do here.
if (getCurrentUser()) {
    header('Location: /index.php');
    exit;
}

// Must be in a valid, non-expired pending state. Otherwise this page grants nothing.
$pending = $_SESSION['mfa_pending'] ?? null;
if (!$pending || ($pending['exp'] ?? 0) < time()) {
    unset($_SESSION['mfa_pending']);
    $_SESSION['flash_error'] = t('mfa_session_expired');
    header('Location: /login.php');
    exit;
}

$db   = getDB();
$uid  = $pending['uid'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';
$error = '';

$hasTotp     = totpActive($db, $uid);
$hasEmailOtp = emailOtpActive($db, $uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $method = $_POST['method'] ?? 'totp';

    // Resend email OTP (rate-limited) without consuming an attempt.
    if ($method === 'resend' && $hasEmailOtp) {
        try {
            if (isRateLimited($db, $ip)) {
                header('Retry-After: 900');
                $error = t('rate_limited');
            } else {
                recordLoginAttempt($db, $ip);
                issueEmailOtp($db, $uid, 'login');
            }
        } catch (Exception $e) {}
    } else {
        try {
            $rateLimited = isRateLimited($db, $ip);
        } catch (Exception $e) {
            $rateLimited = false;
        }

        if ($rateLimited) {
            header('Retry-After: 900');
            $error = t('rate_limited');
        } else {
            $code = trim($_POST['code'] ?? '');
            $ok = false;

            if ($method === 'recovery') {
                $ok = verifyRecoveryCode($db, $uid, $code);
            } elseif ($method === 'email' && $hasEmailOtp) {
                $ok = verifyEmailOtp($db, $uid, $code, 'login');
            } elseif ($method === 'totp' && $hasTotp) {
                $ok = totpVerifyForUser($db, $uid, $code);
            }

            if ($ok) {
                // Promote: this is the ONLY place that grants a session after the password step.
                $user = $db->prepare("SELECT language, theme, font_stack FROM users WHERE id = ?");
                $user->execute([$uid]);
                $u = $user->fetch() ?: [];

                $redirect  = $pending['redirect']  ?? '/index.php';
                $anonTheme = $pending['anonTheme'] ?? 'dark';
                $anonFont  = $pending['anonFont']  ?? 'system';

                unset($_SESSION['mfa_pending']);
                $_SESSION['user_id'] = $uid;
                session_regenerate_id(true);
                try { clearLoginAttempts($db, $ip); } catch (Exception $e) {}
                $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$uid]);
                setLang($u['language']   ?? 'da');
                setTheme($u['theme']     ?? $anonTheme);
                setFont($u['font_stack'] ?? $anonFont);
                header('Location: ' . $redirect);
                exit;
            }

            try { recordLoginAttempt($db, $ip); } catch (Exception $e) {}
            $error = t('mfa_invalid_code');
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-login-grid">
        <div class="hf-login-intro">
            <div class="hf-hero-eyebrow"><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></div>
            <h1 style="font-family:var(--font-display);font-weight:900;font-size:clamp(40px,7vw,68px);letter-spacing:-0.02em;line-height:0.98;margin:12px 0 16px;"><?= t('mfa_prompt_title') ?></h1>
            <p style="color:var(--text-secondary);font-size:17px;line-height:1.55;max-width:44ch;"><?= t('mfa_prompt_intro') ?></p>
        </div>
        <div class="hf-login-card">
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>

            <?php if ($hasTotp): ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="totp">
                <div>
                    <label class="form-label"><?= t('totp_enter_code') ?></label>
                    <input type="text" name="code" class="form-input" inputmode="numeric" autocomplete="one-time-code"
                           pattern="\d{6}" maxlength="6" required autofocus placeholder="123456">
                </div>
                <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
            </form>
            <?php endif; ?>

            <?php if ($hasEmailOtp): ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;margin-top:<?= $hasTotp ? '18px' : '0' ?>;">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="email">
                <div>
                    <label class="form-label"><?= t('email_otp') ?></label>
                    <input type="text" name="code" class="form-input" inputmode="numeric" autocomplete="one-time-code"
                           pattern="\d{6}" maxlength="6" required <?= $hasTotp ? '' : 'autofocus' ?> placeholder="123456">
                </div>
                <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
            </form>
            <form method="POST" style="margin-top:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="resend">
                <button type="submit" style="background:none;border:none;color:var(--f1-red-light);font-family:var(--font-display);font-weight:600;font-size:13px;cursor:pointer;padding:0;"><?= t('email_otp_send') ?></button>
            </form>
            <?php endif; ?>

            <details style="margin-top:18px;">
                <summary style="cursor:pointer;color:var(--text-secondary);font-size:13px;"><?= t('mfa_use_recovery') ?></summary>
                <form method="POST" style="display:flex;flex-direction:column;gap:12px;margin-top:12px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="method" value="recovery">
                    <div>
                        <label class="form-label"><?= t('recovery_codes') ?></label>
                        <input type="text" name="code" class="form-input" autocomplete="off" required placeholder="xxxxx-xxxxx">
                    </div>
                    <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
                </form>
            </details>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
