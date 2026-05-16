<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/smtp.php';
require_once __DIR__ . '/includes/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();

$rawToken = $_GET['e2e_token'] ?? $_POST['e2e_token'] ?? '';
$testMode = defined('INTEGRATION_SEED_TOKEN') && !empty($rawToken) && $rawToken === INTEGRATION_SEED_TOKEN;
$pwdResetTestOutput = [];

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

            if ($testMode) {
                $pwdResetTestOutput[] = "[forgot-pwd-to] {$user['email']}";
                $pwdResetTestOutput[] = "[forgot-pwd-link] {$resetLink}";
                $success = t('reset_link_sent');
            } else {
                $result = sendPasswordResetEmail($user['email'], $user['display_name'], $resetLink, $lang);
                $success = $result['success'] ? t('reset_link_sent') : t('reset_link_failed');
            }
        } else {
            // Don't reveal if email exists or not (security)
            $success = t('reset_link_check_email');
        }
    } else {
        $error = t('enter_valid_email');
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
            <h2><?= t('forgot_password_title') ?></h2>
            <p class="text-muted"><?= t('forgot_password_desc') ?></p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= escape($success) ?></div>
            <?php else: ?>
                <form method="POST"<?= !empty($_GET['e2e_token']) ? ' action="forgot_password.php?e2e_token=' . urlencode($_GET['e2e_token']) . '"' : '' ?>>
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label"><?= t('email') ?></label>
                        <input type="email" name="email" class="form-input" required placeholder="din@email.dk">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= t('send_reset_link') ?>
                    </button>
                </form>
            <?php endif; ?>
            
            <p class="text-center mt-2 text-muted">
                <a href="login.php" class="text-accent"><?= t('back_to_login') ?></a>
            </p>
        </div>
    </div>
</div>

<?php if ($testMode && !empty($pwdResetTestOutput)): ?>
<pre id="e2e-pwd-reset-output" style="display:none"><?= implode("\n", array_map('escape', $pwdResetTestOutput)) ?></pre>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
