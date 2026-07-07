<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mfa.php';
require_once __DIR__ . '/includes/passkey.php';

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

    // Send / resend the email OTP (rate-limited) without consuming a verification attempt.
    if ($method === 'resend' && $hasEmailOtp) {
        try {
            if (isRateLimited($db, $ip)) {
                header('Retry-After: 900');
                $error = t('rate_limited');
            } else {
                recordLoginAttempt($db, $ip);
                if (issueEmailOtp($db, $uid, 'login')) {
                    $_SESSION['mfa_pending']['email_sent'] = true;
                    $pending['email_sent'] = true;
                }
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
                logLoginMethod('password+' . $method, $uid); // $ok only for recovery|email|totp
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

// Resolve which method leads: the member's preference (if still active) or the fallback order.
$default    = getMfaDefaultMethod($db, $uid);
$emailSent  = !empty($pending['email_sent']);

// Order active methods with the default first; the rest go into the collapsed "other options".
$active = activeMfaMethods($db, $uid);
$others = array_values(array_diff($active, [$default]));

// Renders one method's block. $primary drives autofocus (only the lead method grabs focus).
function mfaMethodBlock(string $method, bool $primary, bool $emailSent): void {
    if ($method === 'passkey') {
        // Verification happens via passkey.js → webauthn.php (challenge_options /
        // challenge_verify), not a form POST. Without WebAuthn support the button
        // hides and the unsupported note + "Other options" remain the path.
        ?>
        <div style="display:flex;flex-direction:column;gap:12px;" data-passkey-scope data-testid="mfa-form-passkey">
            <p style="color:var(--text-secondary);font-size:14px;margin:0;"><?= t('passkey_challenge_prompt') ?></p>
            <button type="button" class="hf-cta-primary" style="width:100%;" data-passkey-challenge data-passkey-supported data-testid="mfa-passkey-btn" <?= $primary ? 'autofocus' : '' ?>><?= t('passkey_signin') ?> <span class="arrow">→</span></button>
            <p data-passkey-unsupported hidden style="font-size:13px;margin:0;color:var(--f1-red-light);"><?= t('passkey_unsupported') ?></p>
            <p data-passkey-error hidden role="alert" style="font-size:13px;margin:0;color:var(--f1-red-light);" data-testid="mfa-passkey-error"><?= t('passkey_error') ?></p>
            <form hidden aria-hidden="true"><?= csrfField() ?></form>
        </div>
        <?php
    } elseif ($method === 'totp') {
        ?>
        <form method="POST" style="display:flex;flex-direction:column;gap:12px;" data-testid="mfa-form-totp">
            <?= csrfField() ?>
            <input type="hidden" name="method" value="totp">
            <div>
                <label class="form-label"><?= t('totp_enter_code') ?></label>
                <input type="text" name="code" class="form-input" inputmode="numeric" autocomplete="one-time-code"
                       pattern="\d{6}" maxlength="6" required <?= $primary ? 'autofocus' : '' ?> placeholder="123456">
            </div>
            <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
        </form>
        <?php
    } elseif ($method === 'email') {
        if ($emailSent) {
            ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;" data-testid="mfa-form-email">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="email">
                <div>
                    <label class="form-label"><?= t('email_otp') ?></label>
                    <input type="text" name="code" class="form-input" inputmode="numeric" autocomplete="one-time-code"
                           pattern="\d{6}" maxlength="6" required <?= $primary ? 'autofocus' : '' ?> placeholder="123456">
                </div>
                <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
            </form>
            <form method="POST" style="margin-top:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="resend">
                <button type="submit" data-testid="mfa-email-resend" style="background:none;border:none;color:var(--f1-red-light);font-family:var(--font-display);font-weight:600;font-size:13px;cursor:pointer;padding:0;"><?= t('email_otp_resend') ?></button>
            </form>
            <?php
        } else {
            // No code issued yet — nothing is emailed until the member asks for it here.
            ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;" data-testid="mfa-form-email">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="resend">
                <p style="color:var(--text-secondary);font-size:14px;margin:0;"><?= t('email_otp_prompt') ?></p>
                <button type="submit" class="hf-cta-primary" style="width:100%;" data-testid="mfa-email-send"><?= t('email_otp_send') ?> <span class="arrow">→</span></button>
            </form>
            <?php
        }
    } elseif ($method === 'recovery') {
        ?>
        <form method="POST" style="display:flex;flex-direction:column;gap:12px;" data-testid="mfa-form-recovery">
            <?= csrfField() ?>
            <input type="hidden" name="method" value="recovery">
            <div>
                <label class="form-label"><?= t('recovery_codes') ?></label>
                <input type="text" name="code" class="form-input" autocomplete="off" required placeholder="xxxxx-xxxxx">
            </div>
            <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
        </form>
        <?php
    }
}

// Human label for a method (used to title the lead block).
function mfaMethodLabel(string $method): string {
    if ($method === 'passkey') return t('passkey');
    return $method === 'email' ? t('email_otp') : t('totp_app');
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

            <?php if ($default): ?>
                <div data-testid="mfa-default" data-method="<?= escape($default) ?>">
                    <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= escape(mfaMethodLabel($default)) ?></div>
                    <?php mfaMethodBlock($default, true, $emailSent); ?>
                </div>
            <?php endif; ?>

                <details style="margin-top:18px;" data-testid="mfa-other-options">
                    <summary style="cursor:pointer;color:var(--text-secondary);font-size:13px;"><?= t('mfa_other_options') ?></summary>
                    <div style="margin-top:14px;display:flex;flex-direction:column;gap:20px;">
                        <?php foreach ($others as $m): ?>
                            <div data-method="<?= escape($m) ?>">
                                <div class="form-label" style="margin-bottom:8px;"><?= escape(mfaMethodLabel($m)) ?></div>
                                <?php mfaMethodBlock($m, false, $emailSent); ?>
                            </div>
                        <?php endforeach; ?>
                        <div data-method="recovery">
                            <div class="form-label" style="margin-bottom:8px;"><?= t('mfa_use_recovery') ?></div>
                            <?php mfaMethodBlock('recovery', false, $emailSent); ?>
                        </div>
                    </div>
                </details>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script nonce="<?= $nonce ?>" src="assets/js/passkey.js"></script>
