<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

if (getCurrentUser()) {
    header("Location: /challenges.php");
    exit;
}

if (empty($_SESSION['challenge_participant_id'])) {
    header("Location: /challenges-join.php");
    exit;
}

$db = getDB();
$participant = getChallengeParticipant();

if (!$participant || $participant['status'] !== 'verified') {
    header("Location: /challenges-verify.php");
    exit;
}

$error = '';
$success = '';
$email = $participant['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $pwError = validatePasswordStrength($password);
    if ($pwError) {
        $error = $pwError;
    } elseif ($password !== $confirmPassword) {
        $error = t('passwords_no_match');
    } elseif (!$email) {
        $error = t('enter_valid_email');
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = t('invalid_credentials');
        } else {
            $userId = generateUUID();
            $hashedPassword = hashPassword($password);

            try {
                $db->prepare("
                    INSERT INTO users
                    (id, email, password, display_name, language, in_competition, created_at, last_login)
                    VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
                ")->execute([
                    $userId,
                    $email,
                    $hashedPassword,
                    $participant['display_name'] ?: null,
                    $participant['language'] ?? 'da'
                ]);

                $db->prepare("
                    UPDATE challenge_participants
                    SET core_user_id = ?
                    WHERE id = ?
                ")->execute([$userId, $participant['id']]);

                unset($_SESSION['challenge_participant_id']);
                establishSession($db, $userId);
                $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$userId]);
                setLang($participant['language'] ?? 'da');

                $success = t('ch_upgrade_success');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false && strpos($e->getMessage(), 'email') !== false) {
                    $error = t('invalid_credentials');
                } else {
                    $error = 'An error occurred. Please try again.';
                    if (defined('APP_LOG_FILE')) {
                        logToFile(APP_LOG_FILE, '[UPGRADE] ' . $e->getMessage());
                    }
                }
            }
        }
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
                    <h2 style="margin:0 0 6px;"><?= t('ch_upgrade_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= escape($email) ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?= escape($success) ?></div>
                    <a href="/challenges.php" class="btn btn-primary" style="width:100%;">
                        <?= t('ch_verify_go_to_challenges') ?>
                    </a>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>

                        <div class="form-group">
                            <label class="form-label"><?= t('ch_upgrade_password_label') ?></label>
                            <input type="password" name="password" class="form-input" required minlength="10" placeholder="••••••••">
                            <small class="text-muted"><?= t('ch_upgrade_password_hint') ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?= t('confirm_password') ?></label>
                            <input type="password" name="confirm_password" class="form-input" required minlength="10" placeholder="••••••••">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= t('ch_upgrade_create_account') ?>
                        </button>
                    </form>

                    <p class="text-center mt-2 text-muted">
                        <a href="challenges.php" class="text-anchor"><?= t('cancel') ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
