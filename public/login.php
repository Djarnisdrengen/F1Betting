<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $db       = getDB();
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
    $email    = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $rateLimited = isRateLimited($db, $ip);
    } catch (Exception $e) {
        $rateLimited = false;
    }

    if ($rateLimited) {
        // OpenResty (reverse proxy) intercepts non-2xx FastCGI responses and replaces
        // them with its own 400 page, so we cannot use http_response_code(429) here.
        // Instead, send Retry-After (detectable by both clients and the security scanner)
        // and fall through to render the normal login page with the error message.
        header('Retry-After: 900');
        $error = t('rate_limited');
    } elseif ($email && $password) {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && verifyPassword($password, $user['password'])) {
            try { clearLoginAttempts($db, $ip); } catch (Exception $e) {}
            $_SESSION['user_id'] = $user['id'];
            session_regenerate_id(true);
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            header("Location: index.php");
            exit;
        } else {
            try { recordLoginAttempt($db, $ip); } catch (Exception $e) {}
            $error = t('invalid_credentials');
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
            <h2><?= t('login') ?></h2>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?= csrfField() ?>
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
                <a href="forgot_password.php" class="text-accent"><?= t('forgot_password') ?></a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
