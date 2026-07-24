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

            $appName   = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
            $emailHtml = getEmailTemplate(
                t('ch_email_greeting'),
                t('ch_email_join_intro'),
                t('ch_email_magic_button'),
                $magicLink,
                t('ch_email_link_expires'),
                t('ch_email_join_reentry'),
                sprintf(t('email_footer'), $appName),
                $appName
            );

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
    <?php if ($error): ?>
        <div class="alert alert-error" role="alert" data-persist><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>
    <div class="hf-login-grid">
        <div class="hf-login-intro">
            <div class="hf-hero-eyebrow"><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></div>
            <h1 style="font-family:var(--font-display);font-weight:900;font-size:clamp(48px,8vw,80px);letter-spacing:-0.02em;line-height:0.95;margin:12px 0 16px;"><?= t('ch_join_heading') ?></h1>
            <p style="color:var(--text-secondary);font-size:17px;line-height:1.55;max-width:44ch;"><?= t('ch_join_intro') ?></p>
        </div>
        <div class="hf-login-card">
            <div>
                <div class="hf-hero-eyebrow" style="margin-bottom:8px;"><?= t('ch_join_subtitle') ?></div>
                <h2 style="font-family:var(--font-display);font-weight:900;font-size:28px;letter-spacing:-0.02em;margin:0 0 8px;"><?= t('ch_join_title') ?></h2>
                <?php if (!$success): ?>
                    <p style="margin:0;font-size:13px;color:var(--text-secondary);" data-testid="ch-join-returning-hint"><?= t('ch_join_returning_hint') ?></p>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= escape($success) ?></div>
                <?php if ($coreUser ?? false): ?>
                    <a href="/login.php" class="hf-cta-primary hf-cta-primary--challenges" style="width:100%;">
                        <?= t('go_to_login') ?> <span class="arrow">→</span>
                    </a>
                <?php else: ?>
                    <p class="text-center" style="color:var(--text-secondary);margin:0;">
                        <a href="challenges-join.php" class="text-accent"><?= t('ch_join_didnt_receive') ?></a>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
                    <?= csrfField() ?>
                    <div>
                        <label class="form-label"><?= t('email') ?></label>
                        <input type="email" name="email" class="form-input" required placeholder="din@email.dk">
                    </div>
                    <button type="submit" class="hf-cta-primary hf-cta-primary--challenges" style="width:100%;margin-top:8px;">
                        <?= t('ch_join_send_magic_link') ?> <span class="arrow">→</span>
                    </button>
                </form>

                <p class="text-center" style="color:var(--text-secondary);margin:0;">
                    <a href="index.php" class="text-accent"><?= t('back_home') ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
