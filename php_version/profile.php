<?php
require_once __DIR__ . '/config.php';
requireLogin();

$currentUser = getCurrentUser();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = trim($_POST['display_name'] ?? '');
    
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET display_name = ? WHERE id = ?");
    $stmt->execute([$displayName, $currentUser['id']]);
    
    $success = t('profile_updated');
    $currentUser['display_name'] = $displayName;
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto;">
    <h1 class="mb-3"><i class="fas fa-user text-accent"></i> <?= t('profile') ?></h1>
    
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
    </div>
    
    <!-- Profile Form -->
    <div class="card">
        <div class="card-header">
            <h3><?= getLang() === 'da' ? 'Rediger Profil' : 'Edit Profile' ?></h3>
        </div>
        <div class="card-body">
            <?php if ($success): ?>
                <div class="alert alert-success"><?= escape($success) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" class="form-input" value="<?= escape($currentUser['email']) ?>" disabled style="opacity: 0.7;">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('display_name') ?></label>
                    <input type="text" name="display_name" class="form-input" value="<?= escape($currentUser['display_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Rolle</label>
                    <input type="text" class="form-input" value="<?= $currentUser['role'] === 'admin' ? 'Administrator' : 'Bruger' ?>" disabled style="opacity: 0.7;">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> <?= t('save') ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
