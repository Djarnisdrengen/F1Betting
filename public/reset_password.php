<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();
$validToken = false;
$token = sanitizeString($_GET['token'] ?? '');

$db = getDB();

// Validate token
if ($token) {
    $stmt = $db->prepare("
        SELECT pr.*, u.email, u.display_name 
        FROM password_resets pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();
    
    if ($resetRequest) {
        $validToken = true;
    } else {
        $error = t('token_invalid_expired');
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    requireCsrf();
    
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 6) {
        $error = t('passwords_min_6');
    } elseif ($password !== $confirmPassword) {
        $error = t('passwords_no_match');
    } else {
        // Update password
        $hashedPassword = hashPassword($password);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $resetRequest['user_id']]);
        
        // Mark token as used
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->execute([$token]);
        
        $success = t('password_reset_done');
        $validToken = false; // Hide form after success
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 400px; margin: 3rem auto;">
    <div class="card">
        <div class="card-header text-center">
            <div style="width: 64px; height: 64px; background: var(--f1-red); border-radius: 16px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-lock" style="font-size: 2rem; color: white;"></i>
            </div>
            <h2><?= t('reset_password_title') ?></h2>
            <?php if ($validToken && isset($resetRequest)): ?>
                <p class="text-muted"><?= escape($resetRequest['email']) ?></p>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= escape($success) ?></div>
                <a href="login.php" class="btn btn-primary" style="width: 100%;">
                    <?= t('go_to_login') ?>
                </a>
            <?php elseif ($validToken): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label"><?= t('new_password') ?></label>
                        <input type="password" name="password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('confirm_password') ?></label>
                        <input type="password" name="confirm_password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= t('reset_password_btn') ?>
                    </button>
                </form>
            <?php else: ?>
                <p class="text-center text-muted mb-2">
                    <?= t('link_invalid_expired') ?>
                </p>
                <a href="forgot_password.php" class="btn btn-primary" style="width: 100%;">
                    <?= t('request_new_link') ?>
                </a>
            <?php endif; ?>
            
            <p class="text-center mt-2 text-muted">
                <a href="login.php" class="text-accent">
                    <i class="fas fa-arrow-left"></i> <?= t('back_to_login') ?>
                </a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
