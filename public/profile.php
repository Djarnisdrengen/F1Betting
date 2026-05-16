<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$success = '';
$error = '';

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
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

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
    }
}

// Bet history
$betHistory = $db->prepare("
    SELECT b.id, b.points, b.is_perfect, b.placed_at,
           r.name AS race_name, r.race_date, r.result_p1,
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

<div style="max-width: 700px; margin: 0 auto;">
    <h1 class="mb-3"><i class="fas fa-user text-accent"></i> <?= t('profile') ?></h1>

    <?php if ($success): ?>
        <div class="alert alert-success mb-3"><?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error mb-3"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-2 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <i class="fas fa-trophy text-accent" style="font-size: 2rem;"></i>
                <h2><?= $currentUser['points'] ?></h2>
                <p class="text-muted"><?= t('points') ?></p>
            </div>
        </div>
        <div class="card text-center">
            <div class="card-body">
                <span class="star" style="font-size: 2rem;">★</span>
                <h2><?= $currentUser['stars'] ?></h2>
                <p class="text-muted"><?= t('stars') ?></p>
            </div>
        </div>
        <div class="card text-center">
            <div class="card-body">
                <i class="fas fa-<?= $currentUser['role'] === 'admin' ? 'user-shield' : 'user' ?> text-accent" style="font-size: 2rem;"></i>
                <h2><?= $currentUser['role'] === 'admin' ? 'Admin' : t('user') ?></h2>
                <p class="text-muted"><?= t('role') ?></p>
            </div>
        </div>
        <div class="card text-center">
            <div class="card-body">
                <i class="fas fa-<?= $currentUser['in_competition'] ? 'check-circle' : 'times-circle' ?>" style="font-size: 2rem; color: <?= $currentUser['in_competition'] ? 'var(--f1-red)' : 'var(--text-muted)' ?>;"></i>
                <h2><?= $currentUser['in_competition'] ? t('yes') : t('no') ?></h2>
                <p class="text-muted"><?= t('in_competition') ?></p>
            </div>
        </div>
    </div>

    <!-- Edit Profile -->
    <div class="card mb-3">
        <div class="card-header">
            <h3><?= t('edit_profile') ?></h3>
        </div>
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
                    <input type="text" name="display_name" class="form-input" value="<?= escape($currentUser['display_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('language_label') ?></label>
                    <select name="language" class="form-input">
                        <option value="da" <?= ($currentUser['language'] ?? 'da') === 'da' ? 'selected' : '' ?>>🇩🇰 Dansk</option>
                        <option value="en" <?= ($currentUser['language'] ?? 'da') === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> <?= t('save') ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="card mb-3">
        <div class="card-header">
            <h3><i class="fas fa-lock text-accent"></i> <?= t('change_password_title') ?></h3>
        </div>
        <div class="card-body">
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
                <button type="submit" class="btn btn-secondary" style="width: 100%;">
                    <i class="fas fa-key"></i> <?= t('change_password_title') ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Bet History -->
    <div class="card mb-3">
        <div class="card-header">
            <h3><i class="fas fa-history text-accent"></i> <?= t('betting_history') ?></h3>
        </div>
        <?php if (empty($betHistory)): ?>
            <div class="card-body text-center text-muted"><?= t('no_bets_yet') ?></div>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 0.75rem 1rem; text-align: left;"><?= t('races') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; white-space: nowrap;">P1 / P2 / P3</th>
                        <th style="padding: 0.75rem 1rem; text-align: right;"><?= t('points') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($betHistory as $bet): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.75rem 1rem;">
                                <div style="font-weight: 600;"><?= escape($bet['race_name']) ?></div>
                                <div class="text-muted" style="font-size: 0.8rem;"><?= date('d M Y', strtotime($bet['race_date'])) ?></div>
                            </td>
                            <td style="padding: 0.75rem 1rem;">
                                <div class="bet-predictions" style="flex-direction: column; gap: 0.15rem;">
                                    <span class="bet-pred"><b>P1:</b> <?= escape(explode(' ', $bet['p1_name'])[count(explode(' ', $bet['p1_name']))-1]) ?></span>
                                    <span class="bet-pred"><b>P2:</b> <?= escape(explode(' ', $bet['p2_name'])[count(explode(' ', $bet['p2_name']))-1]) ?></span>
                                    <span class="bet-pred"><b>P3:</b> <?= escape(explode(' ', $bet['p3_name'])[count(explode(' ', $bet['p3_name']))-1]) ?></span>
                                </div>
                            </td>
                            <td style="padding: 0.75rem 1rem; text-align: right; white-space: nowrap;">
                                <?php if ($bet['result_p1']): ?>
                                    <?php if ($bet['is_perfect']): ?>
                                        <span class="star">★</span>
                                    <?php endif; ?>
                                    <span class="text-accent" style="font-weight: bold;"><?= $bet['points'] ?> pts</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.85rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
