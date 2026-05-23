<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$success = $_SESSION['flash_success'] ?? '';
$error   = '';
unset($_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $displayName = sanitizeString($_POST['display_name'] ?? '');
        $newLang     = in_array($_POST['language'] ?? '', ['da', 'en']) ? $_POST['language'] : 'da';
        $stmt = $db->prepare("UPDATE users SET display_name = ?, language = ? WHERE id = ?");
        $stmt->execute([$displayName, $newLang, $currentUser['id']]);
        setLang($newLang);
        $success = t('profile_updated');
        $currentUser['display_name'] = $displayName;
        $currentUser['language']     = $newLang;

    } elseif ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $hash = $stmt->fetchColumn();

        if (!verifyPassword($currentPw, $hash)) {
            $error = t('current_password_wrong');
        }
        if (!$error) {
            if (strlen($newPw) < 6) {
                $error = t('passwords_min_6');
            } elseif ($newPw !== $confirmPw) {
                $error = t('passwords_no_match');
            } else {
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([hashPassword($newPw), $currentUser['id']]);
                $success = t('password_changed');
            }
        }

    } elseif ($action === 'update_preferences') {
        $newTheme = in_array($_POST['pref_theme'] ?? '', ['dark', 'light'])       ? $_POST['pref_theme'] : 'dark';
        $newFont  = in_array($_POST['pref_font']  ?? '', ['system', 'editorial']) ? $_POST['pref_font']  : 'system';
        setTheme($newTheme);
        setFont($newFont);
        $_SESSION['flash_success'] = t('preferences_updated');
        header('Location: profile.php');
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
        <div class="hf-profile-avatar"><?= escape(userInitial($currentUser)) ?></div>
        <div class="hf-profile-id">
            <div class="hf-profile-name"><?= escape(displayUserName($currentUser)) ?></div>
            <div class="hf-profile-sub"><?= escape($currentUser['email']) ?></div>
        </div>
    </div>

    <!-- Stats strip -->
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:24px;">
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $currentUser['points'] ?></div>
            <div class="hf-stat-l"><?= t('points') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $currentUser['stars'] ?></div>
            <div class="hf-stat-l"><?= t('stars') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $currentUser['role'] === 'admin' ? 'Admin' : t('user') ?></div>
            <div class="hf-stat-l"><?= t('role') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $currentUser['in_competition'] ? t('yes') : t('no') ?></div>
            <div class="hf-stat-l"><?= t('in_competition') ?></div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success mb-3"><?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error mb-3"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <!-- 2-col grid -->
    <div class="hf-profile-grid">

        <!-- Left: forms -->
        <div class="hf-profile-forms">

            <!-- Edit Profile -->
            <div class="card">
                <div class="card-body">
                    <h3 style="margin-bottom:16px;"><?= t('edit_profile') ?></h3>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group">
                            <label class="form-label"><?= t('email') ?></label>
                            <input type="email" class="form-input" value="<?= escape($currentUser['email']) ?>" disabled style="opacity: 0.7;">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('display_name') ?></label>
                            <input type="text" name="display_name" class="form-input" value="<?= escape($currentUser['display_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('language_label') ?></label>
                            <select name="language" class="form-input">
                                <option value="da" <?= ($currentUser['language'] ?? 'da') === 'da' ? 'selected' : '' ?>>🇩🇰 Dansk</option>
                                <option value="en" <?= ($currentUser['language'] ?? 'da') === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <i class="fas fa-save"></i> <?= t('save') ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
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
                            <input type="password" name="new_password" class="form-input" required autocomplete="new-password" minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('confirm_password') ?></label>
                            <input type="password" name="confirm_password" class="form-input" required autocomplete="new-password" minlength="6">
                        </div>
                        <button type="submit" class="btn btn-secondary" style="width:100%;">
                            <i class="fas fa-key"></i> <?= t('change_password_title') ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Preferences -->
            <div class="card">
                <div class="card-body">
                    <h3 style="margin-bottom:16px;"><i class="fas fa-sliders-h text-accent"></i> <?= t('preferences') ?></h3>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_preferences">
                        <div class="form-group">
                            <label class="form-label"><?= t('theme') ?></label>
                            <select name="pref_theme" class="form-input">
                                <option value="dark"  <?= getTheme() === 'dark'  ? 'selected' : '' ?>><?= t('theme_dark') ?></option>
                                <option value="light" <?= getTheme() === 'light' ? 'selected' : '' ?>><?= t('theme_light') ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('font_label') ?></label>
                            <select name="pref_font" class="form-input">
                                <option value="system"    <?= getFont() === 'system'    ? 'selected' : '' ?>><?= t('font_system') ?></option>
                                <option value="editorial" <?= getFont() === 'editorial' ? 'selected' : '' ?>><?= t('font_editorial') ?></option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <i class="fas fa-save"></i> <?= t('save') ?>
                        </button>
                    </form>
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
