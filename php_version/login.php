<?php
require_once 'config.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header("Location: index.php");
            exit;
        } else {
            $error = t('invalid_credentials');
        }
    }
}

include 'includes/header.php';
?>

<div style="max-width: 400px; margin: 3rem auto;">
    <div class="card">
        <div class="card-header text-center">
            <div style="width: 64px; height: 64px; background: var(--f1-red); border-radius: 16px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-flag-checkered" style="font-size: 2rem; color: white;"></i>
            </div>
            <h2><?= t('login') ?></h2>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-input" required placeholder="name@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('password') ?></label>
                    <input type="password" name="password" class="form-input" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;"><?= t('login') ?></button>
            </form>
            
            <p class="text-center mt-2 text-muted">
                <?= t('register') ?>? <a href="register.php" class="text-accent"><?= t('register') ?></a>
            </p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
