<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

if (getCurrentUser() || !empty($_SESSION['challenge_participant_id'])) {
    header("Location: /challenges.php");
    exit;
}

$error = '';
$success = '';
$validToken = false;
$participant = null;
$token = sanitizeString($_GET['token'] ?? '');

$db = getDB();

if ($token) {
    $stmt = $db->prepare("
        SELECT ml.*, cp.id as participant_id, cp.display_name, cp.status
        FROM challenge_magic_links ml
        JOIN challenge_participants cp ON ml.participant_id = cp.id
        WHERE ml.token = ? AND ml.used = 0 AND ml.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $magicLink = $stmt->fetch();

    if ($magicLink) {
        $validToken = true;
        $participant = [
            'id' => $magicLink['participant_id'],
            'display_name' => $magicLink['display_name'],
            'status' => $magicLink['status']
        ];
    } else {
        $error = t('ch_verify_token_invalid');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    requireCsrf();

    $displayName = sanitizeString($_POST['display_name'] ?? '');
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'verify') {
        $db->prepare("
            UPDATE challenge_participants
            SET status = 'verified', verified_at = NOW()
            WHERE id = ?
        ")->execute([$participant['id']]);

        $db->prepare("UPDATE challenge_magic_links SET used = 1 WHERE token = ?")
           ->execute([$token]);

        $_SESSION['challenge_participant_id'] = $participant['id'];
        session_regenerate_id(true);

        if ($displayName) {
            $db->prepare("UPDATE challenge_participants SET display_name = ? WHERE id = ?")
               ->execute([$displayName, $participant['id']]);
        }

        header("Location: /challenges.php");
        exit;
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
                        <i class="fas fa-check" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_verify_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= t('ch_verify_subtitle') ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                    <a href="/challenges-join.php" class="btn btn-primary" style="width:100%;">
                        <?= t('ch_verify_request_new_link') ?>
                    </a>
                <?php elseif ($success): ?>
                    <div class="alert alert-success"><?= escape($success) ?></div>
                    <a href="/challenges.php" class="btn btn-primary" style="width:100%;">
                        <?= t('ch_verify_go_to_challenges') ?>
                    </a>
                <?php elseif ($validToken): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="verify">

                        <div class="form-group">
                            <label class="form-label"><?= t('ch_verify_display_name_label') ?></label>
                            <input type="text" name="display_name" class="form-input" placeholder="<?= t('ch_verify_display_name_placeholder') ?>" maxlength="100">
                            <small class="text-muted"><?= t('ch_verify_display_name_hint') ?></small>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= t('ch_verify_get_started') ?>
                        </button>
                    </form>

                    <p class="text-center mt-2 text-muted">
                        <a href="index.php" class="text-anchor"><?= t('back_home') ?></a>
                    </p>
                <?php else: ?>
                    <div class="alert alert-info"><?= t('ch_verify_loading') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
