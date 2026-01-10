<?php
require_once __DIR__ . '/../config.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();
$validToken = false;
$token = $_GET['token'] ?? '';

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
        $error = $lang === 'da' 
            ? 'Ugyldigt eller udløbet link. Anmod om et nyt.' 
            : 'Invalid or expired link. Request a new one.';
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 6) {
        $error = $lang === 'da' 
            ? 'Adgangskoden skal være mindst 6 tegn' 
            : 'Password must be at least 6 characters';
    } elseif ($password !== $confirmPassword) {
        $error = $lang === 'da' 
            ? 'Adgangskoderne matcher ikke' 
            : 'Passwords do not match';
    } else {
        // Update password
        $hashedPassword = hashPassword($password);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $resetRequest['user_id']]);
        
        // Mark token as used
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->execute([$token]);
        
        $success = $lang === 'da' 
            ? 'Din adgangskode er blevet nulstillet. Du kan nu logge ind.' 
            : 'Your password has been reset. You can now log in.';
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
            <h2><?= $lang === 'da' ? 'Nulstil adgangskode' : 'Reset Password' ?></h2>
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
                    <?= $lang === 'da' ? 'Gå til login' : 'Go to login' ?>
                </a>
            <?php elseif ($validToken): ?>
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label"><?= $lang === 'da' ? 'Ny adgangskode' : 'New password' ?></label>
                        <input type="password" name="password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= $lang === 'da' ? 'Bekræft adgangskode' : 'Confirm password' ?></label>
                        <input type="password" name="confirm_password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $lang === 'da' ? 'Nulstil adgangskode' : 'Reset password' ?>
                    </button>
                </form>
            <?php else: ?>
                <p class="text-center text-muted mb-2">
                    <?= $lang === 'da' ? 'Linket er ugyldigt eller udløbet.' : 'The link is invalid or expired.' ?>
                </p>
                <a href="forgot_password.php" class="btn btn-primary" style="width: 100%;">
                    <?= $lang === 'da' ? 'Anmod om nyt link' : 'Request new link' ?>
                </a>
            <?php endif; ?>
            
            <p class="text-center mt-2 text-muted">
                <a href="login.php" class="text-accent">
                    <i class="fas fa-arrow-left"></i> <?= $lang === 'da' ? 'Tilbage til login' : 'Back to login' ?>
                </a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
