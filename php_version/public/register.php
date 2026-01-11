<?php
require_once __DIR__ . '/../config.php';

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
        $error = $lang === 'da' 
            ? 'Ugyldigt eller udløbet invitation. Kontakt administrator for en ny invitation.' 
            : 'Invalid or expired invite. Contact administrator for a new invitation.';
    }
} else {
    $error = $lang === 'da' 
        ? 'Du skal have en invitation for at registrere dig. Kontakt administrator.' 
        : 'You need an invitation to register. Contact administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inviteValid) {
    requireCsrf();
    
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $displayName = sanitizeString($_POST['display_name'] ?? '');
    
    // Email skal matche invite email
    if ($email !== $inviteEmail) {
        $error = $lang === 'da' 
            ? 'Email skal matche invitationens email: ' . escape($inviteEmail) 
            : 'Email must match the invitation email: ' . escape($inviteEmail);
    } elseif ($email && $password && strlen($password) >= 6) {
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
            
            $_SESSION['user_id'] = $userId;
            session_regenerate_id(true);
            header("Location: index.php?success=welcome");
            exit;
        }
    } else {
        $error = $lang === 'da' ? 'Adgangskode skal være mindst 6 tegn' : 'Password must be at least 6 characters';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 400px; margin: 3rem auto;">
    <div class="card">
        <div class="card-header text-center">
            <div style="width: 64px; height: 64px; background: var(--f1-red); border-radius: 16px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-user-plus" style="font-size: 2rem; color: white;"></i>
            </div>
            <h2><?= t('register') ?></h2>
            <?php if ($inviteValid): ?>
                <p class="text-muted"><?= $lang === 'da' ? 'Du er inviteret!' : 'You are invited!' ?></p>
            <?php endif; ?>
        </div>
        <div class="card-body">
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
                        <input type="email" name="email" class="form-input" required value="<?= escape($inviteEmail) ?>" readonly style="background: var(--bg-secondary); cursor: not-allowed;">
                        <small class="text-muted"><?= $lang === 'da' ? 'Email er sat af invitation' : 'Email is set by invitation' ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('password') ?></label>
                        <input type="password" name="password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><?= t('register') ?></button>
                </form>
            <?php endif; ?>
            
            <p class="text-center mt-2 text-muted">
                <?= $lang === 'da' ? 'Har du allerede en konto?' : 'Already have an account?' ?> 
                <a href="login.php" class="text-accent"><?= t('login') ?></a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
