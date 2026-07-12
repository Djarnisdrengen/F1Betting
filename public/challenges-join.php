<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';
require_once __DIR__ . '/includes/smtp.php';

if (getCurrentUser() || !empty($_SESSION['challenge_participant_id'])) {
    header("Location: /challenges.php");
    exit;
}

$error = '';
$success = '';
$lang = getLang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $email = sanitizeEmail($_POST['email'] ?? '');

    $rateLimited = true;
    try {
        $rateLimited = isRateLimited($db, $ip, 'magic', $email !== false ? $email : '');
    } catch (Exception $e) {
        if (defined('APP_LOG_FILE')) {
            logToFile(APP_LOG_FILE, '[RATE-LIMIT] magic-link check failed, failing closed: ' . $e->getMessage());
        }
    }

    if ($rateLimited) {
        header('Retry-After: 900');
        $error = t('rate_limited');
    } elseif ($email) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $coreUser = $stmt->fetch();

        if ($coreUser) {
            $success = t('ch_join_core_member_prompt');
        } else {
            $stmt = $db->prepare("SELECT id FROM challenge_participants WHERE email = ?");
            $stmt->execute([$email]);
            $existingParticipant = $stmt->fetch();

            $participantId = $existingParticipant ? $existingParticipant['id'] : generateUUID();

            if (!$existingParticipant) {
                $db->prepare("
                    INSERT INTO challenge_participants
                    (id, email, language, status, created_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                ")->execute([
                    $participantId,
                    $email,
                    $lang
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 1800);

            $db->prepare("DELETE FROM challenge_magic_links WHERE participant_id = ?")
               ->execute([$participantId]);

            $db->prepare("
                INSERT INTO challenge_magic_links
                (participant_id, token, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
            ")->execute([
                $participantId,
                $token,
                $expiresAt
            ]);

            $magicLink = SITE_URL . "/challenges-verify.php?token=" . $token;

            $emailHtml = "
                <p>" . t('ch_email_greeting') . "</p>
                <p><a href=\"$magicLink\" style=\"display:inline-block;padding:10px 20px;background:#e60600;color:white;text-decoration:none;border-radius:6px;\">
                    " . t('ch_email_magic_button') . "
                </a></p>
                <p><small>" . t('ch_email_link_expires') . "</small></p>
            ";

            sendEmail($email, t('ch_email_magic_subject'), $emailHtml);
            try { recordLoginAttempt($db, $ip, 'magic', $email); } catch (Exception $e) {}

            $success = t('ch_join_success_check_email');
        }
    } else {
        $error = t('enter_valid_email');
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
                        <i class="fas fa-gamepad" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_join_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= t('ch_join_subtitle') ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?= escape($success) ?></div>
                    <?php if ($coreUser ?? false): ?>
                        <a href="/login.php" class="btn btn-primary" style="width:100%;">
                            <?= t('go_to_login') ?>
                        </a>
                    <?php else: ?>
                        <p class="text-center text-muted" style="margin-top:20px;">
                            <a href="challenges-join.php" class="text-accent"><?= t('ch_join_didnt_receive') ?></a>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="form-label"><?= t('email') ?></label>
                            <input type="email" name="email" class="form-input" required placeholder="din@email.dk">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= t('ch_join_send_magic_link') ?>
                        </button>
                    </form>

                    <p class="text-center mt-2 text-muted">
                        <a href="index.php" class="text-accent"><?= t('back_home') ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
