<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/sendgrid.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
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
            
            // Try SendGrid first, then fall back to PHP mail()
            $mailSent = false;
            
            // Check if SendGrid is configured
            if (defined('SENDGRID_API_KEY') && !empty(SENDGRID_API_KEY) && SENDGRID_API_KEY !== 'SG.din_api_nøgle_her') {
                $result = sendPasswordResetEmail($user['email'], $user['display_name'], $resetLink, $lang);
                $mailSent = $result['success'];
            }
            
            // Fallback to PHP mail() if SendGrid not configured or failed
            if (!$mailSent) {
                $subject = $lang === 'da' ? 'Nulstil din adgangskode' : 'Reset your password';
                $message = $lang === 'da' 
                    ? "Hej " . ($user['display_name'] ?: $user['email']) . ",\n\nKlik på linket herunder for at nulstille din adgangskode:\n\n$resetLink\n\nLinket udløber om 1 time.\n\nHvis du ikke har anmodet om dette, kan du ignorere denne email."
                    : "Hi " . ($user['display_name'] ?: $user['email']) . ",\n\nClick the link below to reset your password:\n\n$resetLink\n\nThis link expires in 1 hour.\n\nIf you didn't request this, you can ignore this email.";
                
                $headers = "From: noreply@" . parse_url(SITE_URL, PHP_URL_HOST) . "\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                $mailSent = @mail($user['email'], $subject, $message, $headers);
            }
            
            if ($mailSent) {
                $success = $lang === 'da' 
                    ? 'En email med nulstillingslink er sendt til din email.' 
                    : 'An email with reset link has been sent to your email.';
            } else {
                // If mail fails, show the link directly (for testing/development)
                $success = $lang === 'da' 
                    ? 'Email kunne ikke sendes. Kontakt administrator.' 
                    : 'Email could not be sent. Contact administrator.';
                // Uncomment below line for testing without email:
                // $success .= "<br><br>Reset link (for testing): <a href='$resetLink'>$resetLink</a>";
            }
        } else {
            // Don't reveal if email exists or not (security)
            $success = $lang === 'da' 
                ? 'Hvis emailen findes i systemet, vil du modtage et nulstillingslink.' 
                : 'If the email exists in our system, you will receive a reset link.';
        }
    } else {
        $error = $lang === 'da' ? 'Indtast din email' : 'Enter your email';
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
            <p class="text-muted"><?= $lang === 'da' ? 'Indtast din email for at nulstille din adgangskode' : 'Enter your email to reset your password' ?></p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label"><?= t('email') ?></label>
                        <input type="email" name="email" class="form-input" required placeholder="name@example.com" autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $lang === 'da' ? 'Send nulstillingslink' : 'Send reset link' ?>
                    </button>
                </form>
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
