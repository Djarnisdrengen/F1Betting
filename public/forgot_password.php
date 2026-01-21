<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/smtp.php';
require_once __DIR__ . '/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $email = sanitizeEmail($_POST['email'] ?? '');
    
    if ($email) {
        $db = getDB();
        
        // Find user by email
        $stmt = $db->prepare("SELECT id, email, display_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Delete old tokens for this user
            $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            // Insert new token
            $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expiresAt]);
            
            // Create reset link
            $resetLink = SITE_URL . "/reset_password.php?token=" . $token;
            
            // Send email via SMTP
            $result = sendPasswordResetEmail($user['email'], $user['display_name'], $resetLink, $lang);
            
            if ($result['success']) {
                $success = $lang === 'da' 
                    ? 'En email med nulstillingslink er sendt til din email.' 
                    : 'An email with reset link has been sent to your email.';
            } else {
                // If mail fails, show error (or link for testing)
                $success = $lang === 'da' 
                    ? 'Email kunne ikke sendes. Kontakt administrator.' 
                    : 'Email could not be sent. Contact administrator.';
            }
        } else {
            // Don't reveal if email exists or not (security)
            $success = $lang === 'da' 
                ? 'Hvis emailen findes i systemet, vil du modtage et nulstillingslink.' 
                : 'If the email exists in our system, you will receive a reset link.';
        }
    } else {
        $error = $lang === 'da' ? 'Indtast en gyldig email' : 'Enter a valid email';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 400px; margin: 3rem auto;">
    <div class="card">
        <div class="card-header text-center">
            <div style="width: 64px; height: 64px; background: var(--f1-red); border-radius: 16px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-key" style="font-size: 2rem; color: white;"></i>
            </div>
            <h2><?= $lang === 'da' ? 'Glemt adgangskode' : 'Forgot Password' ?></h2>
            <p class="text-muted"><?= $lang === 'da' ? 'Indtast din email for at modtage et nulstillingslink' : 'Enter your email to receive a reset link' ?></p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= escape($success) ?></div>
            <?php else: ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label"><?= t('email') ?></label>
                        <input type="email" name="email" class="form-input" required placeholder="din@email.dk">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $lang === 'da' ? 'Send nulstillingslink' : 'Send reset link' ?>
                    </button>
                </form>
            <?php endif; ?>
            
            <p class="text-center mt-2 text-muted">
                <a href="login.php" class="text-accent"><?= $lang === 'da' ? 'Tilbage til login' : 'Back to login' ?></a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
