<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mfa.php';

requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $displayName = sanitizeString($_POST['display_name'] ?? '');
        if (mb_strlen($displayName) > 100) {
            $error = t('display_name_too_long');
        } else {
            $stmt = $db->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $stmt->execute([$displayName, $currentUser['id']]);
            $_SESSION['flash_success'] = t('profile_updated');
            header('Location: profile.php');
            exit;
        }

    } elseif ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $hash = $stmt->fetchColumn();

        if (!verifyPassword($currentPw, $hash)) {
            $_SESSION['flash_error'] = t('current_password_wrong');
        } elseif (strlen($newPw) < 6) {
            $_SESSION['flash_error'] = t('passwords_min_6');
        } elseif ($newPw !== $confirmPw) {
            $_SESSION['flash_error'] = t('passwords_no_match');
        } else {
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([hashPassword($newPw), $currentUser['id']]);
            $_SESSION['flash_success'] = t('password_changed');
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'update_preferences') {
        $newTheme = in_array($_POST['pref_theme'] ?? '', ['dark', 'light'])       ? $_POST['pref_theme'] : 'dark';
        $newFont  = in_array($_POST['pref_font']  ?? '', ['system', 'editorial']) ? $_POST['pref_font']  : 'system';
        $newLang  = in_array($_POST['language']   ?? '', ['da', 'en'])            ? $_POST['language']   : 'da';
        setTheme($newTheme);
        setFont($newFont);
        setLang($newLang);
        $_SESSION['flash_success'] = t('preferences_updated');
        header('Location: profile.php?tab=tab-preferences');
        exit;

    } elseif ($action === 'totp_begin') {
        // Start (or restart) authenticator enrollment — stores an unconfirmed sealed secret.
        totpBegin($db, $currentUser['id']);
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'totp_confirm') {
        if (totpConfirm($db, $currentUser['id'], $_POST['code'] ?? '')) {
            $codes = ensureRecoveryCodes($db, $currentUser['id']); // first factor → show recovery codes once
            if ($codes) $_SESSION['flash_recovery_codes'] = $codes;
            $_SESSION['flash_success'] = t('totp_enabled');
        } else {
            $_SESSION['flash_error'] = t('mfa_invalid_code');
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'totp_cancel') {
        // Abandon an in-progress enrollment — no re-auth (nothing was activated).
        totpCancelPending($db, $currentUser['id']);
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'totp_disable') {
        if (!mfaReauth($db, $currentUser['id'], $_POST['current_password'] ?? '')) {
            $_SESSION['flash_error'] = t('mfa_reauth_required');
        } else {
            totpDisable($db, $currentUser['id']);
            $_SESSION['flash_success'] = t('totp_disabled');
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'recovery_regen') {
        if (!mfaReauth($db, $currentUser['id'], $_POST['current_password'] ?? '')) {
            $_SESSION['flash_error'] = t('mfa_reauth_required');
        } else {
            $codes = genRecoveryCodes();
            storeRecoveryCodes($db, $currentUser['id'], $codes);
            $_SESSION['flash_recovery_codes'] = $codes;
            $_SESSION['flash_success'] = t('recovery_codes');
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'emailotp_begin') {
        // Email the member a confirmation code and show the confirm field.
        try { issueEmailOtp($db, $currentUser['id'], 'enroll'); } catch (Exception $e) {}
        $_SESSION['flash_emailotp_enrolling'] = true;
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'emailotp_confirm') {
        if (verifyEmailOtp($db, $currentUser['id'], $_POST['code'] ?? '', 'enroll')) {
            setEmailOtpEnabled($db, $currentUser['id'], true);
            $codes = ensureRecoveryCodes($db, $currentUser['id']);
            if ($codes) $_SESSION['flash_recovery_codes'] = $codes;
            $_SESSION['flash_success'] = t('email_otp_enabled');
        } else {
            $_SESSION['flash_error'] = t('mfa_invalid_code');
            $_SESSION['flash_emailotp_enrolling'] = true;
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'emailotp_cancel') {
        // Abandon email-OTP enrollment: invalidate any pending enroll code and drop the enrolling view.
        $db->prepare("UPDATE user_email_otp SET used_at = NOW() WHERE user_id = ? AND purpose = 'enroll' AND used_at IS NULL")
           ->execute([$currentUser['id']]);
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'emailotp_disable') {
        if (!mfaReauth($db, $currentUser['id'], $_POST['current_password'] ?? '')) {
            $_SESSION['flash_error'] = t('mfa_reauth_required');
        } else {
            setEmailOtpEnabled($db, $currentUser['id'], false);
            $_SESSION['flash_success'] = t('email_otp_disabled');
        }
        header('Location: profile.php?tab=tab-security');
        exit;

    } elseif ($action === 'mfa_default') {
        // Preferred challenge method — a preference, not a factor change, so no re-auth.
        setMfaDefaultMethod($db, $currentUser['id'], $_POST['mfa_default_method'] ?? null);
        $_SESSION['flash_success'] = t('mfa_default_saved');
        header('Location: profile.php?tab=tab-security');
        exit;
    }
}

// Verifies the member's current password — gate for disabling factors / regenerating codes.
function mfaReauth(PDO $db, string $uid, string $password): bool {
    $st = $db->prepare("SELECT password FROM users WHERE id = ?");
    $st->execute([$uid]);
    $hash = $st->fetchColumn();
    return $hash !== false && verifyPassword($password, $hash);
}

// Bet history
$betHistory = $db->prepare("
    SELECT b.id, b.points, b.is_perfect, b.placed_at,
           r.name AS race_name, r.race_date, r.race_time, r.location, r.result_p1,
           d1.name AS p1_name, d2.name AS p2_name, d3.name AS p3_name
    FROM bets b
    JOIN races r  ON b.race_id = r.id
    JOIN drivers d1 ON b.p1 = d1.id
    JOIN drivers d2 ON b.p2 = d2.id
    JOIN drivers d3 ON b.p3 = d3.id
    WHERE b.user_id = ?
    ORDER BY r.race_date DESC
");
$betHistory->execute([$currentUser['id']]);
$betHistory = $betHistory->fetchAll();

// ── MFA state for the Security tab ──────────────────────────────────────────
$totpIsActive  = totpActive($db, $currentUser['id']);
$emailOtpIsOn  = emailOtpActive($db, $currentUser['id']);
$recoveryCount = countRecoveryCodes($db, $currentUser['id']);

// Preferred challenge method — only meaningful (and only offered) when 2+ factors are active.
$activeMethods    = activeMfaMethods($db, $currentUser['id']);
$mfaDefaultMethod = getMfaDefaultMethod($db, $currentUser['id']);

// Pending (unconfirmed) authenticator enrollment → drives the QR / confirm view.
$totpEnrollSecret = null;
if (!$totpIsActive) {
    $st = $db->prepare("SELECT secret_enc FROM user_totp WHERE user_id = ? AND confirmed_at IS NULL");
    $st->execute([$currentUser['id']]);
    $blob = $st->fetchColumn();
    if ($blob !== false) $totpEnrollSecret = mfaOpen($blob);
}
$totpOtpauthUri = $totpEnrollSecret
    ? totpUri($totpEnrollSecret, $currentUser['email'], getSettings()['app_title'] ?? 'Paddock Picks')
    : '';

// One-time displays carried across the PRG redirect.
$flashRecoveryCodes = $_SESSION['flash_recovery_codes'] ?? null;
$emailOtpEnrolling  = !empty($_SESSION['flash_emailotp_enrolling']);
unset($_SESSION['flash_recovery_codes'], $_SESSION['flash_emailotp_enrolling']);

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">

    <!-- Profile head -->
    <div class="hf-profile-head">
        <div class="hf-profile-avatar"><?= userInitial($currentUser) ?></div>
        <div class="hf-profile-id">
            <div class="hf-profile-name"><?= displayUserName($currentUser) ?></div>
            <div class="hf-profile-sub"><?= escape($currentUser['email']) ?></div>
        </div>
    </div>

    <?php $user = $currentUser; include 'partials/profile_stats.php'; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-3"><?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error mb-3"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <!-- 2-col grid -->
    <div class="hf-profile-grid">

        <!-- Left: tabbed forms -->
        <div class="hf-profile-forms">
            <div class="hf-tabs" data-testid="profile-tabs">

                <nav class="hf-tab-nav">
                    <button class="hf-tab-btn" data-target="tab-profile" data-testid="tab-profile-btn"><?= t('tab_profile') ?></button>
                    <button class="hf-tab-btn" data-target="tab-security" data-testid="tab-security-btn"><?= t('tab_security') ?></button>
                    <button class="hf-tab-btn" data-target="tab-preferences" data-testid="tab-preferences-btn"><?= t('tab_preferences') ?></button>
                    <button class="hf-tab-btn" data-target="tab-history" data-testid="tab-history-btn"><?= t('tab_history') ?></button>
                </nav>

                <!-- Profile tab -->
                <div class="hf-tab-panel" id="tab-profile" data-testid="tab-profile-panel" hidden>
                    <div class="card">
                        <div class="card-body">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-group">
                                    <label class="form-label"><?= t('email') ?></label>
                                    <input type="email" class="form-input" value="<?= escape($currentUser['email']) ?>" disabled style="opacity: 0.7;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?= t('display_name') ?></label>
                                    <input type="text" name="display_name" class="form-input" value="<?= escape($currentUser['display_name']) ?>" maxlength="100" data-testid="display-name-input">
                                    <span class="hf-char-counter" data-testid="char-counter">0/100</span>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-save"></i> <?= t('save') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security tab -->
                <div class="hf-tab-panel" id="tab-security" data-testid="tab-security-panel" hidden>
                    <div class="card">
                        <div class="card-body">
                            <h3 style="margin-bottom:16px;"><i class="fas fa-lock text-accent"></i> <?= t('change_password_title') ?></h3>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-group">
                                    <label class="form-label"><?= t('current_password') ?></label>
                                    <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?= t('new_password') ?></label>
                                    <input type="password" name="new_password" class="form-input" required autocomplete="new-password" minlength="6" data-testid="new-password-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?= t('confirm_password') ?></label>
                                    <input type="password" name="confirm_password" class="form-input" required autocomplete="new-password" minlength="6" data-testid="confirm-password-input">
                                    <span class="hf-pw-match" aria-live="polite" data-testid="pw-match-indicator"></span>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-key"></i> <?= t('change_password_title') ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Two-factor login -->
                    <div class="card" style="margin-top:16px;" data-testid="mfa-card">
                        <div class="card-body">
                            <h3 style="margin-bottom:16px;"><i class="fas fa-shield-halved text-accent"></i> <?= t('two_factor') ?></h3>

                            <?php if ($flashRecoveryCodes): ?>
                                <!-- Persistent reveal (NOT .alert — app.js auto-hides alerts). Shown once; copy/download before dismissing. -->
                                <div class="hf-recovery-reveal mb-3" data-testid="recovery-codes" style="border:1px solid var(--f1-red-light);border-radius:10px;padding:14px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                                        <strong><?= t('recovery_codes') ?></strong>
                                        <div style="display:flex;gap:8px;">
                                            <button type="button" class="btn btn-secondary" data-recovery-copy data-testid="recovery-copy-btn"><i class="fas fa-copy"></i> <?= t('copy') ?></button>
                                            <button type="button" class="btn btn-secondary" data-recovery-download><i class="fas fa-download"></i> <?= t('download') ?></button>
                                            <span data-recovery-copied hidden style="align-self:center;color:var(--success,#3fb950);font-size:13px;"><?= t('copied') ?></span>
                                        </div>
                                    </div>
                                    <p style="margin:6px 0;font-size:13px;"><?= t('recovery_codes_intro') ?></p>
                                    <pre data-recovery-codes style="font-family:var(--font-mono,monospace);font-size:15px;line-height:1.8;margin:8px 0;"><?php foreach ($flashRecoveryCodes as $rc) { echo escape($rc) . "\n"; } ?></pre>
                                    <button type="button" class="btn btn-primary" data-recovery-dismiss data-testid="recovery-dismiss-btn"><?= t('recovery_saved') ?></button>
                                </div>
                            <?php endif; ?>

                            <!-- Authenticator app (TOTP) -->
                            <div style="padding:12px 0;border-bottom:1px solid var(--border,#2a2a2a);">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <div>
                                        <div style="font-weight:600;"><?= t('totp_app') ?></div>
                                        <div class="text-muted" style="font-size:13px;" data-testid="totp-status">
                                            <?= $totpIsActive ? t('totp_active') : t('totp_inactive') ?>
                                        </div>
                                    </div>
                                    <?php if ($totpIsActive): ?>
                                        <form method="POST" onsubmit="return !!this.current_password.value;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="totp_disable">
                                            <input type="password" name="current_password" class="form-input" style="max-width:160px;display:inline-block;" placeholder="<?= t('current_password') ?>" autocomplete="current-password" required>
                                            <button type="submit" class="btn btn-secondary" data-testid="totp-disable-btn"><?= t('disable') ?></button>
                                        </form>
                                    <?php elseif (!$totpEnrollSecret): ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="totp_begin">
                                            <button type="submit" class="btn btn-primary" data-testid="totp-setup-btn"><?= t('totp_setup') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$totpIsActive && $totpEnrollSecret): ?>
                                    <div style="margin-top:14px;" data-testid="totp-enroll">
                                        <p style="font-size:13px;margin:0 0 6px;"><?= t('totp_scan') ?></p>
                                        <!-- QR image is a progressive enhancement (renders client-side from data-otpauth);
                                             the otpauth link and manual key below work without it. -->
                                        <div class="hf-qr" data-otpauth="<?= escape($totpOtpauthUri) ?>" style="margin:8px 0;"></div>
                                        <a href="<?= escape($totpOtpauthUri) ?>" class="text-accent" style="font-size:13px;word-break:break-all;">otpauth://…</a>
                                        <p style="font-size:13px;margin:8px 0 4px;"><?= t('totp_manual_key') ?></p>
                                        <code data-testid="totp-secret" style="display:inline-block;letter-spacing:2px;font-size:15px;padding:6px 10px;background:var(--surface,#1a1a1a);border-radius:6px;"><?= escape(chunk_split($totpEnrollSecret, 4, ' ')) ?></code>
                                        <form method="POST" style="margin-top:12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="totp_confirm">
                                            <div class="form-group" style="margin:0;">
                                                <label class="form-label"><?= t('totp_enter_code') ?></label>
                                                <input type="text" name="code" class="form-input" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="123456" data-testid="totp-confirm-input">
                                            </div>
                                            <button type="submit" class="btn btn-primary" data-testid="totp-confirm-btn"><?= t('totp_confirm') ?></button>
                                        </form>
                                        <form method="POST" style="margin-top:8px;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="totp_cancel">
                                            <button type="submit" class="btn btn-secondary" data-testid="totp-cancel-btn"><?= t('cancel') ?></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Email code (OTP) -->
                            <div style="padding:12px 0;border-bottom:1px solid var(--border,#2a2a2a);">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <div>
                                        <div style="font-weight:600;"><?= t('email_otp') ?></div>
                                        <div class="text-muted" style="font-size:13px;" data-testid="emailotp-status">
                                            <?= $emailOtpIsOn ? t('totp_active') : t('totp_inactive') ?>
                                        </div>
                                    </div>
                                    <?php if ($emailOtpIsOn): ?>
                                        <form method="POST" onsubmit="return !!this.current_password.value;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="emailotp_disable">
                                            <input type="password" name="current_password" class="form-input" style="max-width:160px;display:inline-block;" placeholder="<?= t('current_password') ?>" autocomplete="current-password" required>
                                            <button type="submit" class="btn btn-secondary" data-testid="emailotp-disable-btn"><?= t('disable') ?></button>
                                        </form>
                                    <?php elseif (!$emailOtpEnrolling): ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="emailotp_begin">
                                            <button type="submit" class="btn btn-primary" data-testid="emailotp-setup-btn"><?= t('email_otp_send') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$emailOtpIsOn && $emailOtpEnrolling): ?>
                                    <form method="POST" style="margin-top:12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;" data-testid="emailotp-enroll">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="emailotp_confirm">
                                        <div class="form-group" style="margin:0;">
                                            <label class="form-label"><?= t('totp_enter_code') ?></label>
                                            <input type="text" name="code" class="form-input" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="123456" data-testid="emailotp-confirm-input">
                                        </div>
                                        <button type="submit" class="btn btn-primary" data-testid="emailotp-confirm-btn"><?= t('totp_confirm') ?></button>
                                    </form>
                                    <form method="POST" style="margin-top:8px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="emailotp_cancel">
                                        <button type="submit" class="btn btn-secondary" data-testid="emailotp-cancel-btn"><?= t('cancel') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- Recovery codes -->
                            <?php if ($totpIsActive || $emailOtpIsOn): ?>
                            <div style="padding:12px 0;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <div>
                                        <div style="font-weight:600;"><?= t('recovery_codes') ?></div>
                                        <div class="text-muted" style="font-size:13px;"><?= $recoveryCount ?></div>
                                    </div>
                                    <form method="POST" onsubmit="return !!this.current_password.value;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="recovery_regen">
                                        <input type="password" name="current_password" class="form-input" style="max-width:160px;display:inline-block;" placeholder="<?= t('current_password') ?>" autocomplete="current-password" required>
                                        <button type="submit" class="btn btn-secondary" data-testid="recovery-regen-btn"><?= t('recovery_regenerate') ?></button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Preferred method — only when there's an actual choice (2+ active factors) -->
                            <?php if (count($activeMethods) >= 2): ?>
                            <div style="padding:12px 0;border-top:1px solid var(--border,#2a2a2a);" data-testid="mfa-default-method">
                                <div style="font-weight:600;margin-bottom:2px;"><?= t('mfa_default_method') ?></div>
                                <div class="text-muted" style="font-size:13px;margin-bottom:10px;"><?= t('mfa_default_hint') ?></div>
                                <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mfa_default">
                                    <select name="mfa_default_method" class="form-input" style="max-width:220px;" data-testid="mfa-default-select">
                                        <?php if (in_array('totp', $activeMethods, true)): ?>
                                            <option value="totp"  <?= $mfaDefaultMethod === 'totp'  ? 'selected' : '' ?>><?= t('totp_app') ?></option>
                                        <?php endif; ?>
                                        <?php if (in_array('email', $activeMethods, true)): ?>
                                            <option value="email" <?= $mfaDefaultMethod === 'email' ? 'selected' : '' ?>><?= t('email_otp') ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <button type="submit" class="btn btn-secondary" data-testid="mfa-default-save-btn"><?= t('save') ?></button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Preferences tab -->
                <div class="hf-tab-panel" id="tab-preferences" data-testid="tab-preferences-panel" hidden>
                    <div class="card">
                        <div class="card-body">
                            <h3 style="margin-bottom:16px;"><i class="fas fa-sliders-h text-accent"></i> <?= t('preferences') ?></h3>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_preferences">
                                <div class="form-group">
                                    <label class="form-label"><?= t('theme') ?></label>
                                    <div class="hf-pref-toggle" role="group" aria-label="<?= t('theme') ?>">
                                        <button type="button" class="hf-pref-btn<?= getTheme() === 'dark'  ? ' active' : '' ?>" data-target="pref_theme" data-value="dark"><?= t('theme_dark') ?></button>
                                        <button type="button" class="hf-pref-btn<?= getTheme() === 'light' ? ' active' : '' ?>" data-target="pref_theme" data-value="light"><?= t('theme_light') ?></button>
                                    </div>
                                    <input type="hidden" name="pref_theme" id="pref_theme" value="<?= getTheme() ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?= t('font_label') ?></label>
                                    <div class="hf-pref-toggle" role="group" aria-label="<?= t('font_label') ?>">
                                        <button type="button" class="hf-pref-btn<?= getFont() === 'system'    ? ' active' : '' ?>" data-target="pref_font" data-value="system"><?= t('font_system') ?></button>
                                        <button type="button" class="hf-pref-btn<?= getFont() === 'editorial' ? ' active' : '' ?>" data-target="pref_font" data-value="editorial"><?= t('font_editorial') ?></button>
                                    </div>
                                    <input type="hidden" name="pref_font" id="pref_font" value="<?= getFont() ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?= t('language_label') ?></label>
                                    <div class="hf-pref-toggle" role="group" aria-label="<?= t('language_label') ?>">
                                        <button type="button" class="hf-pref-btn<?= ($currentUser['language'] ?? 'da') === 'da' ? ' active' : '' ?>" data-target="language" data-value="da">🇩🇰 Dansk</button>
                                        <button type="button" class="hf-pref-btn<?= ($currentUser['language'] ?? 'da') === 'en' ? ' active' : '' ?>" data-target="language" data-value="en">🇬🇧 English</button>
                                    </div>
                                    <input type="hidden" name="language" id="language" value="<?= escape($currentUser['language'] ?? 'da') ?>">
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-save"></i> <?= t('save') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Betting history tab -->
                <div class="hf-tab-panel" id="tab-history" data-testid="tab-history-panel" hidden>
                    <div class="hf-section-h" style="margin-bottom:12px;">
                        <h3><?= t('betting_history') ?></h3>
                    </div>

                    <?php if (empty($betHistory)): ?>
                        <p data-testid="empty-bet-history" class="text-muted" style="padding: 12px 0;"><?= t('no_bets_yet') ?></p>
                    <?php else: ?>
                        <div class="hf-history-list">
                        <?php foreach ($betHistory as $bet): ?>
                            <div class="hf-racecard hf-racecard--static">
                                <div>
                                    <div class="hf-racename">
                                        <?= escape($bet['race_name']) ?>
                                        <?php if ($bet['is_perfect']): ?><span class="star" style="margin-left:4px;">★</span><?php endif; ?>
                                    </div>
                                    <div class="hf-racemeta">
                                        <?= escape($bet['location']) ?> · <?= formatRaceDateTime($bet['race_date'], $bet['race_time']) ?>
                                    </div>
                                    <div class="hf-racemeta">
                                        P1: <?= driverLastName(['name' => $bet['p1_name']]) ?>
                                        &nbsp;· P2: <?= driverLastName(['name' => $bet['p2_name']]) ?>
                                        &nbsp;· P3: <?= driverLastName(['name' => $bet['p3_name']]) ?>
                                    </div>
                                </div>
                                <?php if ($bet['result_p1']): ?>
                                    <span class="hf-badge <?= $bet['is_perfect'] ? 'open' : 'done' ?>" style="align-self: center;">
                                        <?= $bet['is_perfect'] ? '★ ' : '' ?><?= $bet['points'] ?>p
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="align-self: center;">—</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </div>
</div>

<script nonce="<?= $nonce ?>" src="assets/js/qrcode.min.js"></script>
<script nonce="<?= $nonce ?>" src="assets/js/mfa.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
