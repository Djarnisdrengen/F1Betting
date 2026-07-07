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

$postMethod = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['method'] ?? '') : '';

if ($postMethod === 'resend' && $hasEmailOtp) {
    requireCsrf();
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

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

        if ($postMethod === 'recovery') {
            $ok = verifyRecoveryCode($db, $uid, $code);
        } elseif ($postMethod === 'email' && $hasEmailOtp) {
            $ok = verifyEmailOtp($db, $uid, $code, 'login');
        } elseif ($postMethod === 'totp' && $hasTotp) {
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
            logLoginMethod('password+' . $postMethod, $uid); // $ok only for recovery|email|totp
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

// Passkey is deliberately not offered here — it's only reachable via the login screen's
// passwordless button, which skips this second-factor step entirely (v3.0.0). The
// challenge_options/challenge_verify actions in webauthn.php stay in place (nothing in the
// auth backend changes) but have no UI caller on this page as of this redesign.
$emailSent  = !empty($pending['email_sent']);
$order      = ['totp', 'email'];
$active     = array_diff(activeMfaMethods($db, $uid), ['passkey']);
$candidates = array_values(array_intersect($order, $active));
$hasList    = count($candidates) >= 2;

// Which view renders this request: an explicit method (resend / failed verify / the sole
// candidate), or null for the root list. All views are rendered into the DOM regardless (just
// hidden) so the client-side swap has something to reveal without another round trip.
if ($postMethod === 'resend') {
    $openView = 'email';
} elseif ($error && in_array($postMethod, ['totp', 'email', 'recovery'], true)) {
    $openView = $postMethod;
} elseif (count($candidates) === 1) {
    $openView = $candidates[0];
} elseif (count($candidates) === 0) {
    $openView = 'recovery'; // e.g. a passkey-only member reaching this screen via password login
} else {
    $openView = null; // 2+ candidates → list first
}

// Footer link for the view currently being rendered ($forView, not necessarily $openView):
// back-to-list when the list exists; otherwise a narrower escape hatch (recovery from the one
// skipped-to method, or back to that method from the recovery fallback), and a pointer to the
// login screen's passkey button for members with no non-passkey factor.
function mfaFooterLink(bool $hasList, array $candidates, string $forView): array {
    if ($hasList) {
        return ['view' => 'root', 'label' => t('mfa_back')];
    }
    if (count($candidates) === 1) {
        return $forView === 'recovery'
            ? ['view' => $candidates[0], 'label' => t('mfa_back')]
            : ['view' => 'recovery', 'label' => t('mfa_use_recovery')];
    }
    return ['href' => '/login.php', 'label' => t('mfa_passkey_use_login')];
}

// Icon / title / row-hint / detail-subtitle per method. $email is used only for the email
// subtitle's masked address.
function mfaMethodMeta(string $method, string $email = ''): array {
    if ($method === 'totp') {
        return ['icon' => 'fa-shield-halved', 'title' => t('totp_app'), 'hint' => t('mfa_hint_totp'), 'sub' => t('mfa_sub_totp')];
    }
    if ($method === 'email') {
        return ['icon' => 'fa-envelope', 'title' => t('email_otp'), 'hint' => t('mfa_hint_email'), 'sub' => sprintf(t('mfa_sub_email'), maskEmail($email))];
    }
    return ['icon' => 'fa-key', 'title' => t('recovery_codes'), 'hint' => t('mfa_hint_recovery'), 'sub' => t('mfa_sub_recovery')];
}

// th•••@hpovlsen.dk — first two chars of the local part, domain intact.
function maskEmail(string $email): string {
    $at = strpos($email, '@');
    if ($at === false) return $email;
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at);
    return mb_substr($local, 0, min(2, mb_strlen($local))) . '•••' . $domain;
}

$emailAddr = '';
if (in_array('email', $candidates, true) || $openView === 'email') {
    $st = $db->prepare("SELECT email FROM users WHERE id = ?");
    $st->execute([$uid]);
    $emailAddr = (string)$st->fetchColumn();
}

// Renders one method's detail view: pill + heading + subtitle + input + confirm, per epic §3.
function mfaDetailView(string $method, bool $open, bool $emailSent, string $emailAddr, array $footer): void {
    $meta = mfaMethodMeta($method, $emailAddr);
    ?>
    <div data-mfa-view="<?= escape($method) ?>" data-testid="mfa-form-<?= escape($method) ?>" <?= $open ? '' : 'hidden' ?>>
        <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= escape($meta['title']) ?></div>
        <h2 style="font-family:var(--font-display);font-weight:900;font-size:24px;letter-spacing:-0.02em;margin:0 0 6px;"><?= t('mfa_verify_heading') ?></h2>
        <p class="sub" style="color:var(--text-secondary);font-size:14px;margin:0 0 16px;"><?= escape($meta['sub']) ?></p>

        <?php if ($method === 'email' && $emailSent): ?>
            <div class="code-sent" style="margin-bottom:14px;"><i class="fas fa-paper-plane"></i> <?= t('mfa_code_sent') ?></div>
        <?php endif; ?>

        <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
            <?= csrfField() ?>
            <input type="hidden" name="method" value="<?= escape($method) ?>">
            <?php if ($method === 'recovery'): ?>
                <input type="text" name="code" class="form-input" autocomplete="off" required
                       style="font-family:var(--font-mono);letter-spacing:.15em;text-align:center;"
                       placeholder="XXXXX-XXXXX">
            <?php else: ?>
                <div data-otp-group>
                    <div class="otp">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" inputmode="numeric" data-testid="mfa-otp-box"
                                   <?= $i === 0 ? 'autocomplete="one-time-code"' : 'autocomplete="off"' ?>
                                   aria-label="<?= sprintf(escape(t('mfa_otp_digit')), $i + 1) ?>">
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="code" data-testid="mfa-otp-value">
                </div>
            <?php endif; ?>
            <button type="submit" class="hf-cta-primary" style="width:100%;"><?= t('verify') ?> <span class="arrow">→</span></button>
        </form>

        <?php if ($method === 'email'): ?>
            <form method="POST" style="margin-top:8px;" data-testid="mfa-resend-form">
                <?= csrfField() ?>
                <input type="hidden" name="method" value="resend">
                <button type="submit" data-testid="mfa-email-resend" style="background:none;border:none;color:var(--f1-red-light);font-family:var(--font-display);font-weight:600;font-size:13px;cursor:pointer;padding:0;"><?= t('email_otp_resend') ?></button>
            </form>
        <?php endif; ?>

        <?php if (isset($footer['view'])): ?>
            <button type="button" class="more-toggle" style="margin-top:16px;" data-mfa-select="<?= escape($footer['view']) ?>" data-testid="mfa-footer-link"><i class="fas fa-arrow-left"></i> <?= escape($footer['label']) ?></button>
        <?php else: ?>
            <p style="margin-top:16px;font-size:13px;"><a href="<?= escape($footer['href']) ?>" data-testid="mfa-footer-link" style="color:var(--f1-red-light);"><?= escape($footer['label']) ?></a></p>
        <?php endif; ?>
    </div>
    <?php
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

            <?php if ($hasList): ?>
                <div data-mfa-view="root" data-testid="mfa-view-root" <?= $openView === null ? '' : 'hidden' ?>>
                    <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= t('mfa_two_step_label') ?></div>
                    <h2 style="font-family:var(--font-display);font-weight:900;font-size:24px;letter-spacing:-0.02em;margin:0 0 6px;"><?= t('mfa_verify_heading') ?></h2>
                    <p class="sub" style="color:var(--text-secondary);font-size:14px;margin:0 0 16px;"><?= t('mfa_choose_method') ?></p>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <?php foreach (array_merge($candidates, ['recovery']) as $m): $meta = mfaMethodMeta($m, $emailAddr); ?>
                            <button type="button" class="method" data-testid="mfa-method-<?= escape($m) ?>"
                                    data-mfa-select="<?= escape($m) ?>" <?= ($m === 'email' && !$emailSent) ? 'data-mfa-autosend' : '' ?>>
                                <span class="ic"><i class="fas <?= escape($meta['icon']) ?>"></i></span>
                                <span style="flex:1;text-align:left;">
                                    <div style="font-weight:700;"><?= escape($meta['title']) ?></div>
                                    <div style="font-size:12.5px;color:var(--text-secondary);"><?= escape($meta['hint']) ?></div>
                                </span>
                                <span><i class="fas fa-chevron-right"></i></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach (array_merge($candidates, ['recovery']) as $m): ?>
                <?php mfaDetailView($m, $openView === $m, $emailSent, $emailAddr, mfaFooterLink($hasList, $candidates, $m)); ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script nonce="<?= $nonce ?>" src="assets/js/mfa.js"></script>
