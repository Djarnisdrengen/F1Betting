<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

// Core members are already full members — nothing to do here.
if (getCurrentUser()) {
    header("Location: /challenges.php");
    exit;
}

if (empty($_SESSION['challenge_participant_id'])) {
    header("Location: /challenges-join.php");
    exit;
}

$db          = getDB();
$participant = getChallengeParticipant();

// Must be a verified participant to set a password or request promotion.
if (!$participant || $participant['status'] !== 'verified') {
    header("Location: /challenges-verify.php");
    exit;
}

$error            = '';
$success          = '';
$hasPassword      = !empty($participant['password_hash']);
$promotionPending = !empty($participant['promotion_requested_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'set_password') {
        // Set a password → permanent participant (B4). Stays in challenge_participants;
        // NO users row, NO establishSession() — this is not a core account.
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $pwError = validatePasswordStrength($password);
        if ($pwError) {
            $error = $pwError;
        } elseif ($password !== $confirmPassword) {
            $error = t('passwords_no_match');
        } else {
            $db->prepare("UPDATE challenge_participants SET password_hash = ? WHERE id = ?")
               ->execute([hashPassword($password), $participant['id']]);
            $hasPassword = true;
            $success = t('ch_setpw_success');
        }
    } elseif ($action === 'request_promotion') {
        // Admin-gated core-membership request (B6/D14). No participant-initiated write to `users`.
        requestCoreMembership($db, $participant['id']);
        $promotionPending = true;
        $success = t('ch_promote_requested');
    }
}

$email = $participant['email'] ?? '';

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-auth-wrap">
        <div class="card">
            <div class="card-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:64px;height:64px;background:var(--f1-red);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-shield-halved" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_setpw_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= escape($email) ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= escape($success) ?></div>
                <?php endif; ?>

                <?php if (!$hasPassword): ?>
                    <p class="text-muted" style="margin:0 0 12px;"><?= t('ch_setpw_subtitle') ?></p>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_password">
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
                            <?= t('ch_setpw_button') ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info"><?= t('ch_setpw_already') ?></div>
                <?php endif; ?>

                <hr style="margin:24px 0;border:none;border-top:1px solid var(--border-color);">

                <p class="text-muted" style="margin:0 0 12px;"><?= t('ch_promote_hint') ?></p>
                <?php if ($promotionPending): ?>
                    <div class="alert alert-info"><?= t('ch_promote_requested') ?></div>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="request_promotion">
                        <button type="submit" class="btn btn-secondary" style="width:100%;">
                            <?= t('ch_promote_button') ?>
                        </button>
                    </form>
                <?php endif; ?>

                <p class="text-center mt-2 text-muted">
                    <a href="challenges.php" class="text-accent"><?= t('ch_verify_go_to_challenges') ?></a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
