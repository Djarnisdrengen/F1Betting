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

// The challenge opens on the member's PREFERRED method (`users.mfa_default_method`, resolved by
// getMfaDefaultMethod() — the stored preference if still active, else the fallback priority
// passkey → totp → email). Every other active method plus recovery is one tap away beneath it via
// the "Other options" list. Passkey is just the default preference — an explicit preference wins.
// The passkey ceremony still runs against webauthn.php's challenge_options/challenge_verify
// (unchanged) via passkey.js, loaded at the foot of this page.
$emailSent  = !empty($pending['email_sent']);
$hasPasskey = passkeyActive($db, $uid);
$order      = ['totp', 'email'];
$active     = activeMfaMethods($db, $uid);                    // [passkey?, totp?, email?]
$candidates = array_values(array_intersect($order, $active)); // code methods (recovery is separate)
$allMethods = array_merge($active, ['recovery']);             // every openable panel, in fallback order
// "List mode" = the preferred method leads with the rest under an "Other options" list. A lone code
// method (no passkey) keeps the leaner single-method layout with a plain recovery link.
$listMode   = $hasPasskey || count($candidates) >= 2;
$primary    = getMfaDefaultMethod($db, $uid) ?? 'recovery';   // passkey | totp | email | recovery

// Which panel opens on this request. Every panel is rendered into the DOM regardless (just hidden)
// so the client-side swap has something to reveal without another round trip.
if ($postMethod === 'resend') {
    $openView = 'email';
} elseif ($error && in_array($postMethod, ['totp', 'email', 'recovery'], true)) {
    $openView = $postMethod;
} else {
    $openView = $primary; // the preferred method leads
}

// Single-method layout only (no passkey, one code method): a fallback detail view's footer link is
// the recovery⇄method escape hatch. In list mode the "Other options" list replaces this entirely.
function mfaSingleFooter(array $candidates, string $forView): array {
    return $forView === 'recovery'
        ? ['view' => $candidates[0] ?? 'recovery', 'label' => t('mfa_back')]
        : ['view' => 'recovery', 'label' => t('mfa_use_recovery')];
}

// Icon / title / row-hint / detail-subtitle per method. $email is used only for the email
// subtitle's masked address.
function mfaMethodMeta(string $method, string $email = ''): array {
    if ($method === 'passkey') {
        return ['icon' => 'fa-fingerprint', 'title' => t('passkey'), 'hint' => t('mfa_hint_passkey'), 'sub' => t('passkey_challenge_prompt')];
    }
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

// Renders one method's detail view: pill + heading + subtitle + input + confirm, per epic §3. In
// list mode the "Other options" list closes the panel; otherwise a single recovery⇄method link does.
function mfaDetailView(string $method, bool $open, bool $emailSent, string $emailAddr, bool $listMode, array $candidates, array $allMethods): void {
    $meta = mfaMethodMeta($method, $emailAddr);
    ?>
    <div data-mfa-view="<?= escape($method) ?>" data-testid="mfa-form-<?= escape($method) ?>" <?= $open ? '' : 'hidden' ?>>
        <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= escape($meta['title']) ?></div>
        <h2 style="font-family:var(--font-display);font-weight:900;font-size:24px;letter-spacing:-0.02em;margin:0 0 6px;"><?= t('mfa_verify_heading') ?></h2>
        <p class="sub" style="color:var(--text-secondary);font-size:14px;margin:0 0 16px;"><?= escape($meta['sub']) ?></p>

        <?php if ($method === 'email'): // Both states live in the DOM; mfa.js toggles them via
                                        // style.display — NOT [hidden], which a display:flex (class or
                                        // inline) overrides — so the boxes show instantly while the send runs. ?>
            <div class="code-sent" data-mfa-code-sent style="margin-bottom:14px;<?= $emailSent ? '' : 'display:none;' ?>"><i class="fas fa-paper-plane"></i> <?= t('mfa_code_sent') ?></div>
            <div data-mfa-code-sending style="display:none;align-items:center;gap:10px;padding:11px 13px;border-radius:10px;margin-bottom:14px;background:var(--bg-hover);color:var(--text-secondary);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> <?= t('mfa_code_sending') ?></div>
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

        <?php if ($listMode): ?>
            <?php mfaOtherOptions($method, $allMethods, $emailSent, $emailAddr); ?>
        <?php else: $footer = mfaSingleFooter($candidates, $method); ?>
            <button type="button" class="more-toggle" style="margin-top:16px;" data-mfa-select="<?= escape($footer['view']) ?>" data-testid="mfa-footer-link"><i class="fas fa-arrow-left"></i> <?= escape($footer['label']) ?></button>
        <?php endif; ?>
    </div>
    <?php
}

// The "Other options" list shown beneath a panel in list mode: every method except the current one,
// each a data-mfa-select swap trigger. Email carries data-mfa-autosend (see mfaMethodRow / mfa.js).
function mfaOtherOptions(string $current, array $allMethods, bool $emailSent, string $emailAddr): void {
    $others = array_values(array_diff($allMethods, [$current]));
    if (!$others) return;
    ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color);">
        <div class="hf-hero-eyebrow" style="margin-bottom:10px;"><?= t('mfa_other_options') ?></div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($others as $m) mfaMethodRow($m, $emailSent, $emailAddr); ?>
        </div>
    </div>
    <?php
}

// One selectable method row for an "Other options" list. It's a visibility-swap trigger
// (data-mfa-select); email carries data-mfa-autosend so picking it before a code exists also
// fires the send in the background while the boxes show immediately (see mfa.js).
function mfaMethodRow(string $m, bool $emailSent, string $emailAddr): void {
    $meta = mfaMethodMeta($m, $emailAddr);
    ?>
    <button type="button" class="method" data-testid="mfa-method-<?= escape($m) ?>"
            data-mfa-select="<?= escape($m) ?>" <?= ($m === 'email' && !$emailSent) ? 'data-mfa-autosend' : '' ?>>
        <span class="ic"><i class="fas <?= escape($meta['icon']) ?>"></i></span>
        <span style="flex:1;text-align:left;">
            <div style="font-weight:700;"><?= escape($meta['title']) ?></div>
            <div style="font-size:12.5px;color:var(--text-secondary);"><?= escape($meta['hint']) ?></div>
        </span>
        <span><i class="fas fa-chevron-right"></i></span>
    </button>
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

            <?php if ($hasPasskey): // Passkey panel: ceremony button (tap to run) + Other options. ?>
                <div data-mfa-view="passkey" data-testid="mfa-view-passkey" <?= $openView === 'passkey' ? '' : 'hidden' ?>>
                    <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= t('mfa_two_step_label') ?></div>
                    <h2 style="font-family:var(--font-display);font-weight:900;font-size:24px;letter-spacing:-0.02em;margin:0 0 6px;"><?= t('mfa_verify_heading') ?></h2>
                    <p class="sub" style="color:var(--text-secondary);font-size:14px;margin:0 0 16px;"><?= t('passkey_challenge_prompt') ?></p>

                    <?php // CTA ships hidden and is revealed by passkey.js only when WebAuthn is supported;
                          // the unsupported note is revealed instead. With no JS both stay hidden and the
                          // Other options below carry the member through. Ceremony fires on tap (no auto-launch). ?>
                    <div data-passkey-scope>
                        <div hidden data-passkey-supported>
                            <button type="button" class="hf-cta-primary" style="width:100%;" data-passkey-challenge data-testid="mfa-passkey-btn"><i class="fas fa-fingerprint"></i> <?= t('mfa_passkey_cta') ?></button>
                            <p data-passkey-error hidden role="alert" data-testid="mfa-passkey-error" style="font-size:13px;margin:8px 0 0;color:var(--f1-red-light);text-align:center;"><?= t('passkey_error') ?></p>
                        </div>
                        <div hidden data-passkey-unsupported class="alert alert-error" role="alert" style="margin:0;"><i class="fas fa-exclamation-triangle"></i> <?= t('mfa_passkey_unsupported') ?></div>
                    </div>

                    <?php mfaOtherOptions('passkey', $allMethods, $emailSent, $emailAddr); ?>
                </div>
            <?php endif; ?>

            <?php foreach (array_merge($candidates, ['recovery']) as $m): ?>
                <?php mfaDetailView($m, $openView === $m, $emailSent, $emailAddr, $listMode, $candidates, $allMethods); ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script nonce="<?= $nonce ?>" src="assets/js/passkey.js"></script>
<script nonce="<?= $nonce ?>" src="assets/js/mfa.js"></script>
