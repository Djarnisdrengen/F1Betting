<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

if (getCurrentUser()) {
    header("Location: index.php");
    exit;
}

$lang = getLang();
$error = '';
$success = '';
$inviteValid = false;
$inviteEmail = '';

// Tjek invite token
$token = sanitizeString($_GET['token'] ?? $_POST['token'] ?? '');

if ($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM invites WHERE token = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
    
    if ($invite) {
        $inviteValid = true;
        $inviteEmail = $invite['email'];
    } else {
        $error = t('invalid_invite');
    }
} else {
    $error = t('invite_required');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inviteValid) {
    requireCsrf();
    
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $displayName = sanitizeString($_POST['display_name'] ?? '');
    
    $pwError = validatePasswordStrength($password);

    // Email skal matche invite email
    if ($email !== $inviteEmail) {
        $error = sprintf(t('email_must_match_invite'), escape($inviteEmail));
    } elseif ($email && $password && !$pwError) {
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
            
            // Marker invite som brugt
            $stmt = $db->prepare("UPDATE invites SET used = 1 WHERE token = ?");
            $stmt->execute([$token]);
            
            establishSession($db, $userId);
            header("Location: index.php?success=welcome");
            exit;
        }
    } else {
        $error = $pwError ?: t('password_min_length');
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-auth-wrap">
        <div class="card">
            <div class="card-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:64px;height:64px;background:var(--f1-red);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user-plus" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('register') ?></h2>
                    <?php if ($inviteValid): ?>
                        <p class="text-muted" style="margin:0;"><?= t('you_are_invited') ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                <?php endif; ?>

                <?php if ($inviteValid): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="token" value="<?= escape($token) ?>">
                        <div class="form-group">
                            <label class="form-label"><?= t('display_name') ?></label>
                            <input type="text" name="display_name" class="form-input" placeholder="Max Verstappen">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('email') ?></label>
                            <input type="email" name="email" class="form-input" required value="<?= escape($inviteEmail) ?>" readonly style="background:var(--bg-secondary);cursor:not-allowed;">
                            <small class="text-muted"><?= t('email_set_by_invite') ?></small>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('password') ?></label>
                            <input type="password" name="password" class="form-input" required minlength="10" placeholder="••••••••">
                            <small class="text-muted"><?= t('password_requirements_hint') ?></small>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;"><?= t('register') ?></button>
                    </form>
                <?php endif; ?>

                <p class="text-center mt-2 text-muted">
                    <?= t('already_have_account') ?>
                    <a href="login.php" class="text-accent"><?= t('login') ?></a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
