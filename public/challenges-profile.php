<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

$participant = getChallengeParticipant();

// Gating (REQ-607/608): no participant at all -> hub; core-linked -> the core profile page.
if (!$participant) {
    header('Location: /challenges.php');
    exit;
}
if (!empty($participant['core_user_id'])) {
    header('Location: /profile.php');
    exit;
}

$db = getDB();
$isAnonymous = $participant['status'] === 'pending' || empty($participant['email']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    // Anonymous participants have no tabs to act from; guard the actions server-side too.
    if ($isAnonymous) {
        header('Location: /challenges.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_display_name') {
        $displayName = sanitizeString($_POST['display_name'] ?? '');
        if (mb_strlen($displayName) > 100) {
            $_SESSION['flash_error'] = t('display_name_too_long');
        } else {
            $db->prepare("UPDATE challenge_participants SET display_name = ? WHERE id = ?")
               ->execute([$displayName, $participant['id']]);
            $_SESSION['flash_success'] = t('profile_updated');
        }
        header('Location: challenges-profile.php?tab=tab-profile');
        exit;

    } elseif ($action === 'update_preferences') {
        $newTheme = in_array($_POST['pref_theme'] ?? '', ['dark', 'light'])       ? $_POST['pref_theme'] : 'dark';
        $newFont  = in_array($_POST['pref_font']  ?? '', ['system', 'editorial']) ? $_POST['pref_font']  : 'system';
        $newLang  = in_array($_POST['language']   ?? '', ['da', 'en'])            ? $_POST['language']   : 'da';
        setTheme($newTheme);
        setFont($newFont);
        setLang($newLang);
        // setLang() only persists to `users` for a core session; participants persist here.
        $db->prepare("UPDATE challenge_participants SET language = ? WHERE id = ?")
           ->execute([$newLang, $participant['id']]);
        $_SESSION['flash_success'] = t('preferences_updated');
        header('Location: challenges-profile.php?tab=tab-preferences');
        exit;

    } elseif ($action === 'set_password') {
        if (!empty($participant['password_hash'])) {
            $_SESSION['flash_error'] = t('ch_stale_form'); // stale form; state already moved on
        } else {
            $newPw     = $_POST['new_password'] ?? '';
            $confirmPw = $_POST['confirm_password'] ?? '';
            $pwError   = validatePasswordStrength($newPw);
            if ($pwError) {
                $_SESSION['flash_error'] = $pwError;
            } elseif ($newPw !== $confirmPw) {
                $_SESSION['flash_error'] = t('passwords_no_match');
            } else {
                $db->prepare("UPDATE challenge_participants SET password_hash = ? WHERE id = ?")
                   ->execute([hashPassword($newPw), $participant['id']]);
                issueAccessToken($db, $participant['id']);
                $_SESSION['flash_success'] = t('ch_setpw_success');
            }
        }
        header('Location: challenges-profile.php?tab=tab-account');
        exit;

    } elseif ($action === 'change_password') {
        if (empty($participant['password_hash'])) {
            $_SESSION['flash_error'] = t('ch_stale_form'); // stale form; not permanent yet
        } else {
            $currentPw = $_POST['current_password'] ?? '';
            $newPw     = $_POST['new_password'] ?? '';
            $confirmPw = $_POST['confirm_password'] ?? '';
            $pwError   = validatePasswordStrength($newPw);
            if (!verifyPassword($currentPw, $participant['password_hash'])) {
                $_SESSION['flash_error'] = t('current_password_wrong');
            } elseif ($pwError) {
                $_SESSION['flash_error'] = $pwError;
            } elseif ($newPw !== $confirmPw) {
                $_SESSION['flash_error'] = t('passwords_no_match');
            } else {
                $db->prepare("UPDATE challenge_participants SET password_hash = ? WHERE id = ?")
                   ->execute([hashPassword($newPw), $participant['id']]);
                $_SESSION['flash_success'] = t('password_changed');
            }
        }
        header('Location: challenges-profile.php?tab=tab-account');
        exit;

    } elseif ($action === 'signout') {
        revokeAccessToken($db);
        unset($_SESSION['challenge_participant_id']);
        header('Location: /challenges.php');
        exit;

    } elseif ($action === 'signout_all') {
        revokeAllAccessTokens($db, $participant['id']);
        unset($_SESSION['challenge_participant_id']);
        header('Location: /challenges.php');
        exit;

    } elseif ($action === 'request_core') {
        requestCoreMembership($db, $participant['id']);
        $_SESSION['flash_success'] = t('ch_promote_requested');
        header('Location: challenges-profile.php?tab=tab-account');
        exit;
    }
}

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$cpTotal = getChallengeCpTotal($db, $participant['id']);
$streak  = getChallengeStreak($db, $participant['id']);
$cpRank  = null;
foreach (getCpLeaderboard($db) as $i => $row) {
    if ($row['id'] === $participant['id']) {
        $cpRank = $i + 1;
        break;
    }
}
$isPermanent = !empty($participant['password_hash']);
$pendingCore = !empty($participant['promotion_requested_at']);
$displayName = $participant['display_name'] ?: ('Guest ' . substr($participant['id'], -4));

include __DIR__ . '/includes/header.php';
?>

<div class="hf-arena-base" style="min-height:100vh;padding-bottom:80px;">
    <div class="hf-arena-header">
        <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--text-primary);">
            <i class="fas fa-user text-accent" style="margin-right:8px;"></i>
            <?= t('ch_profile_title') ?>
        </h1>
    </div>

    <div class="hf-container" style="padding:20px;">

        <?php if ($isAnonymous): ?>

            <div class="card">
                <div class="card-body" style="text-align:center;">
                    <p><?= t('ch_save_spot_prompt') ?></p>
                    <a href="/challenges-invite.php" class="btn btn-primary"><?= t('ch_save_spot_cta') ?></a>
                </div>
            </div>

        <?php else: ?>

            <!-- Identity head -->
            <div class="hf-profile-head">
                <div class="hf-profile-avatar"><?= escape(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></div>
                <div class="hf-profile-id">
                    <div class="hf-profile-name"><?= escape($displayName) ?></div>
                    <?php if ($participant['email']): ?>
                        <div class="hf-profile-sub"><?= escape($participant['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hf-stats-metrics" data-testid="ch-profile-stats" style="margin:16px 0;">
                <article class="hf-stats-metric" data-testid="ch-stats-cp">
                    <div class="k"><?= t('ch_your_cp') ?></div>
                    <div class="v"><?= (int) $cpTotal ?></div>
                </article>
                <article class="hf-stats-metric" data-testid="ch-stats-rank">
                    <div class="k"><?= t('ch_rank') ?></div>
                    <div class="v"><?= $cpRank !== null ? (int) $cpRank : '—' ?></div>
                </article>
                <article class="hf-stats-metric" data-testid="ch-stats-streak">
                    <div class="k"><?= t('ch_streak') ?></div>
                    <div class="v"><?= (int) $streak ?></div>
                </article>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><?= escape($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error mb-3"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>

            <div class="hf-tabs" data-testid="profile-tabs">

                <nav class="hf-tab-nav">
                    <button class="hf-tab-btn" data-target="tab-profile" data-testid="tab-profile-btn"><?= t('tab_profile') ?></button>
                    <button class="hf-tab-btn" data-target="tab-preferences" data-testid="tab-preferences-btn"><?= t('tab_preferences') ?></button>
                    <button class="hf-tab-btn" data-target="tab-account" data-testid="tab-account-btn">
                        <?= t('ch_tab_account') ?><?php if (!$pendingCore): ?> <span class="hf-badge-dot" aria-hidden="true" data-testid="account-tab-promo-dot"></span><?php endif; ?>
                    </button>
                </nav>

                <!-- Profile tab -->
                <div class="hf-tab-panel" id="tab-profile" data-testid="tab-profile-panel" hidden>
                    <div class="card">
                        <div class="card-body">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_display_name">
                                <?php if ($participant['email']): ?>
                                <div class="form-group">
                                    <label class="form-label"><?= t('email') ?></label>
                                    <input type="email" class="form-input" value="<?= escape($participant['email']) ?>" disabled style="opacity:0.7;">
                                </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label class="form-label"><?= t('display_name') ?></label>
                                    <input type="text" name="display_name" class="form-input" value="<?= escape($participant['display_name']) ?>" maxlength="100" data-testid="display-name-input">
                                    <span class="hf-char-counter" data-testid="char-counter">0/100</span>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-save"></i> <?= t('save') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Preferences tab -->
                <div class="hf-tab-panel" id="tab-preferences" data-testid="tab-preferences-panel" hidden>
                    <div class="card">
                        <div class="card-body">
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
                                        <button type="button" class="hf-pref-btn<?= $participant['language'] === 'da' ? ' active' : '' ?>" data-target="language" data-value="da">🇩🇰 Dansk</button>
                                        <button type="button" class="hf-pref-btn<?= $participant['language'] === 'en' ? ' active' : '' ?>" data-target="language" data-value="en">🇬🇧 English</button>
                                    </div>
                                    <input type="hidden" name="language" id="language" value="<?= escape($participant['language']) ?>">
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="fas fa-save"></i> <?= t('save') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Account tab -->
                <div class="hf-tab-panel" id="tab-account" data-testid="tab-account-panel" hidden>

                    <div class="card mb-3">
                        <div class="card-body">
                            <?php if ($isPermanent): ?>
                                <h3 style="margin-bottom:16px;"><i class="fas fa-key text-accent"></i> <?= t('change_password_title') ?></h3>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label"><?= t('current_password') ?></label>
                                        <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label"><?= t('new_password') ?></label>
                                        <input type="password" name="new_password" class="form-input" required autocomplete="new-password" minlength="10" data-testid="new-password-input">
                                        <small class="text-muted"><?= t('password_requirements_hint') ?></small>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label"><?= t('confirm_password') ?></label>
                                        <input type="password" name="confirm_password" class="form-input" required autocomplete="new-password" minlength="10" data-testid="confirm-password-input">
                                        <span class="hf-pw-match" aria-live="polite" data-testid="pw-match-indicator"></span>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="width:100%;">
                                        <?= t('change_password_title') ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <h3 style="margin-bottom:16px;"><i class="fas fa-key text-accent"></i> <?= t('ch_setpw_title') ?></h3>
                                <p class="text-muted" style="margin:0 0 12px;"><?= t('ch_setpw_subtitle') ?></p>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_password">
                                    <div class="form-group">
                                        <label class="form-label"><?= t('new_password') ?></label>
                                        <input type="password" name="new_password" class="form-input" required autocomplete="new-password" minlength="10" data-testid="new-password-input">
                                        <small class="text-muted"><?= t('password_requirements_hint') ?></small>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label"><?= t('confirm_password') ?></label>
                                        <input type="password" name="confirm_password" class="form-input" required autocomplete="new-password" minlength="10" data-testid="confirm-password-input">
                                        <span class="hf-pw-match" aria-live="polite" data-testid="pw-match-indicator"></span>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="width:100%;">
                                        <?= t('ch_setpw_button') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <h3 style="margin-bottom:16px;"><i class="fas fa-right-from-bracket text-accent"></i> <?= t('ch_sessions_title') ?></h3>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="signout">
                                    <button type="submit" class="btn btn-secondary"><?= t('ch_signout') ?></button>
                                </form>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="signout_all">
                                    <button type="submit" class="btn btn-secondary"><?= t('ch_signout_all') ?></button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <p class="text-muted" style="margin:0 0 12px;"><?= t('ch_promote_hint') ?></p>
                            <?php if ($pendingCore): ?>
                                <div class="alert alert-info" style="margin:0;"><?= t('ch_promote_requested') ?></div>
                            <?php else: ?>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="request_core">
                                    <button type="submit" class="btn btn-secondary" style="width:100%;"><?= t('ch_promote_button') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
