<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

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
    }
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

            </div>
        </div>

        <!-- Right: bet history -->
        <div>
            <div class="hf-section-h" style="margin-bottom:12px;">
                <h3><?= t('betting_history') ?></h3>
            </div>

            <?php if (empty($betHistory)): ?>
                <p data-testid="empty-bet-history" class="text-muted" style="padding: 12px 0;"><?= t('no_bets_yet') ?></p>
            <?php else: ?>
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
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
