<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';
require_once __DIR__ . '/includes/smtp.php';

// Must have played something → be at least an anonymous participant (B2 builds on B1).
$participant = getChallengeParticipant();
if (!$participant) {
    header("Location: /challenges.php");
    exit;
}

$db    = getDB();
$lang  = getLang();
$error = '';
$done  = false;

$game = $_POST['game'] ?? $_GET['game'] ?? 'rumor_or_not';
if (!in_array($game, ['rumor_or_not', 'trivia'], true)) {
    $game = 'rumor_or_not';
}

// The set the participant actually answered for this game, with their score. Derived
// server-side (never trusted from the client) so a sender cannot claim an unplayed score.
function playedSet(PDO $db, string $participantId, string $game): array {
    if ($game === 'trivia') {
        $sql = "SELECT question_id AS item_id, correct FROM challenge_trivia_answers WHERE participant_id = ?";
    } else {
        $sql = "SELECT item_id, correct FROM challenge_answers WHERE participant_id = ?";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$participantId]);
    $rows    = $stmt->fetchAll();
    $itemIds = array_column($rows, 'item_id');
    $score   = array_sum(array_column($rows, 'correct'));
    return [$itemIds, (int)$score];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $ip          = $_SERVER['REMOTE_ADDR'] ?? '';
    $friendEmail = sanitizeEmail($_POST['friend_email'] ?? '');
    $ownEmail    = sanitizeEmail($_POST['own_email'] ?? '');

    [$itemIds, $score] = playedSet($db, $participant['id'], $game);

    // Rate-limit friend sends (scope 'invite'), fail closed (NFR-107).
    $rateLimited = true;
    try {
        $rateLimited = isRateLimited($db, $ip, 'invite', $friendEmail !== false ? $friendEmail : '');
    } catch (Exception $e) {
        if (defined('APP_LOG_FILE')) {
            logToFile(APP_LOG_FILE, '[RATE-LIMIT] invite check failed, failing closed: ' . $e->getMessage());
        }
    }

    if ($rateLimited) {
        header('Retry-After: 900');
        $error = t('rate_limited');
    } elseif (!$friendEmail) {
        $error = t('enter_valid_email');
    } elseif (empty($itemIds)) {
        $error = t('ch_invite_nothing_played');
    } else {
        // Resolve/attach the owner's email. Anonymous owners must supply one and confirm it.
        $ownerEmail = $participant['email'] ?: ($ownEmail ?: '');

        if (!$participant['email']) {
            if (!$ownEmail) {
                $error = t('enter_valid_email');
            } else {
                // Guard: an email that is a core account, or already another participant, is never
                // (re)claimed here (REQ-111). The response stays identical (NFR-106) either way.
                $isCore = $db->prepare("SELECT id FROM users WHERE email = ?");
                $isCore->execute([$ownEmail]);
                $taken = $db->prepare("SELECT id FROM challenge_participants WHERE email = ? AND id <> ?");
                $taken->execute([$ownEmail, $participant['id']]);

                if (!$isCore->fetch() && !$taken->fetch()) {
                    $db->prepare("UPDATE challenge_participants SET email = ? WHERE id = ?")
                       ->execute([$ownEmail, $participant['id']]);
                    $ownerEmail = $ownEmail;

                    // Owner-confirmation link (reuse challenge_magic_links, 30-min, single-use).
                    $mt = bin2hex(random_bytes(32));
                    $db->prepare("DELETE FROM challenge_magic_links WHERE participant_id = ?")->execute([$participant['id']]);
                    $db->prepare("
                        INSERT INTO challenge_magic_links (participant_id, token, expires_at, created_at)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())
                    ")->execute([$participant['id'], $mt]);

                    $confirmUrl = SITE_URL . "/challenges-verify.php?token=" . $mt;
                    $confirmHtml = "<p>" . t('email_magic_greeting') . "</p><p>" . t('email_magic_intro') . "</p>"
                        . "<p><a href=\"$confirmUrl\" style=\"display:inline-block;padding:10px 20px;background:#e60600;color:#fff;text-decoration:none;border-radius:6px;\">"
                        . t('email_magic_button') . "</a></p><p><small>" . t('email_magic_expiry') . "</small></p>";
                    sendEmail($ownEmail, t('email_magic_subject'), $confirmHtml);
                }
            }
        }

        if (!$error) {
            // Send the friend invite unless the friend email is a core account (REQ-111) — the
            // click-side guard in challenges-verify catches it too, but skip the mint here.
            $friendIsCore = $db->prepare("SELECT id FROM users WHERE email = ?");
            $friendIsCore->execute([$friendEmail]);

            if (!$friendIsCore->fetch()) {
                $displayName = $participant['display_name'] ?: t('ch_a_friend');
                [$inviteId, $friendToken] = createChallengeInvite($db, $participant['id'], $game, $itemIds, $score, $friendEmail);

                $inviteUrl  = SITE_URL . "/challenges-verify.php?invite=" . $friendToken;
                $gameName   = $game === 'trivia' ? t('ch_trivia') : t('ch_rumors');
                $inviteHtml = "<p>" . t('email_invite_greeting') . "</p>"
                    . "<p>" . sprintf(t('email_invite_intro'), escape($displayName), escape($gameName)) . "</p>"
                    . "<p><a href=\"$inviteUrl\" style=\"display:inline-block;padding:10px 20px;background:#e60600;color:#fff;text-decoration:none;border-radius:6px;\">"
                    . t('email_invite_button') . "</a></p><p><small>" . t('email_invite_ignore') . "</small></p>";
                sendEmail($friendEmail, t('email_invite_subject'), $inviteHtml);
            }

            try { recordLoginAttempt($db, $ip, 'invite', $friendEmail); } catch (Exception $e) {}
            $done = true;
        }
    }
}

$needsOwnEmail = empty($participant['email']);

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-auth-wrap">
        <div class="card">
            <div class="card-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:64px;height:64px;background:var(--f1-red);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-paper-plane" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_invite_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= t('ch_invite_subtitle') ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= escape($error) ?></div>
                <?php endif; ?>

                <?php if ($done): ?>
                    <div class="alert alert-success"><?= t('ch_invite_success') ?></div>
                    <a href="/challenges.php" class="btn btn-primary" style="width:100%;">
                        <?= t('ch_verify_go_to_challenges') ?>
                    </a>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="game" value="<?= escape($game) ?>">

                        <?php if ($needsOwnEmail): ?>
                        <div class="form-group">
                            <label class="form-label"><?= t('ch_invite_your_email') ?></label>
                            <input type="email" name="own_email" class="form-input" required placeholder="din@email.dk">
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label"><?= t('ch_invite_friend_email') ?></label>
                            <input type="email" name="friend_email" class="form-input" required placeholder="ven@email.dk">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= t('ch_invite_send') ?>
                        </button>
                    </form>

                    <p class="text-center mt-2 text-muted">
                        <a href="challenges.php" class="text-accent"><?= t('cancel') ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
