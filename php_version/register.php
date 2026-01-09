<?php
require_once __DIR__ . '/config.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    
    if ($email && $password) {
        $db = getDB();
        
        // Check om email allerede findes
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = t('email_exists');
        } else {
            // Tjek om der er nogen brugere (første bruger bliver admin)
            $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $role = $userCount == 0 ? 'admin' : 'user';
            
            $userId = generateUUID();
            $hashedPassword = hashPassword($password);
            
            $stmt = $db->prepare("INSERT INTO users (id, email, password, display_name, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $email, $hashedPassword, $displayName ?: explode('@', $email)[0], $role]);
            
            $_SESSION['user_id'] = $userId;
            header("Location: index.php");
            exit;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 400px; margin: 3rem auto;">
    <div class="card">
        <div class="card-header text-center">
            <div style="width: 64px; height: 64px; background: var(--f1-red); border-radius: 16px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-flag-checkered" style="font-size: 2rem; color: white;"></i>
            </div>
            <h2><?= t('register') ?></h2>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label"><?= t('display_name') ?></label>
                    <input type="text" name="display_name" class="form-input" placeholder="Max Verstappen">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-input" required placeholder="name@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('password') ?></label>
                    <input type="password" name="password" class="form-input" required minlength="6" placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;"><?= t('register') ?></button>
            </form>
            
            <p class="text-center mt-2 text-muted">
                <?= t('login') ?>? <a href="login.php" class="text-accent"><?= t('login') ?></a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
