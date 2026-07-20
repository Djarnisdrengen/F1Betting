<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

// Already signed in (core or challenge session) → straight to the hub.
if (getCurrentUser() || !empty($_SESSION['challenge_participant_id'])) {
    header("Location: /challenges.php");
    exit;
}

$db    = getDB();
$lang  = getLang();
$error = '';

$magicToken  = sanitizeString($_GET['token'] ?? '');
$inviteToken = sanitizeString($_GET['invite'] ?? '');

// ── Friend-invite acceptance (B2/REQ-116) ────────────────────────────────────
// Clicking the invite proves the friend owns the address → verified participant.
// The link stays valid for its lifetime, so a re-click is a return path (REQ-120).
if ($inviteToken) {
    $stmt = $db->prepare("SELECT * FROM challenge_invites WHERE friend_token = ? AND expires_at > NOW()");
    $stmt->execute([$inviteToken]);
    $invite = $stmt->fetch();

    if ($invite) {
        // Core-member guard (REQ-111): never mint a participant for a core account's email.
        $core = $db->prepare("SELECT id FROM users WHERE email = ?");
        $core->execute([$invite['friend_email']]);
        if ($core->fetch()) {
            $_SESSION['flash_error'] = t('ch_join_core_member_prompt');
            header("Location: /login.php");
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE email = ?");
        $stmt->execute([$invite['friend_email']]);
        $friend = $stmt->fetch();

        if (!$friend) {
            $friendId = generateUUID();
            $db->prepare("
                INSERT INTO challenge_participants (id, email, language, status, verified_at, created_at)
                VALUES (?, ?, ?, 'verified', NOW(), NOW())
            ")->execute([$friendId, $invite['friend_email'], $lang]);
        } else {
            $friendId = $friend['id'];
            if ($friend['status'] !== 'verified') {
                $db->prepare("UPDATE challenge_participants SET status='verified', verified_at=NOW() WHERE id = ?")
                   ->execute([$friendId]);
            }
        }

        if ($invite['status'] === 'sent') {
            $db->prepare("UPDATE challenge_invites SET status='accepted', accepted_at=NOW(), friend_participant_id=? WHERE id = ?")
               ->execute([$friendId, $invite['id']]);
        }

        $_SESSION['challenge_participant_id'] = $friendId;
        session_regenerate_id(true);
        issueAccessToken($db, $friendId);

        // Drop the friend into the same challenge (the play UI reads from_invite in Phase 3/4).
        header("Location: /challenges.php?from_invite=" . urlencode($invite['id']));
        exit;
    }

    $error = t('ch_verify_token_invalid');
}

// ── Owner-confirm / sign-in magic link (B2/REQ-115) ──────────────────────────
// Verifies email ownership; a still-valid link re-establishes the session on return.
if (!$error && $magicToken) {
    $stmt = $db->prepare("
        SELECT ml.token, cp.id AS participant_id, cp.status
        FROM challenge_magic_links ml
        JOIN challenge_participants cp ON cp.id = ml.participant_id
        WHERE ml.token = ? AND ml.expires_at > NOW()
    ");
    $stmt->execute([$magicToken]);
    $ml = $stmt->fetch();

    if ($ml) {
        $participantId = $ml['participant_id'];
        if ($ml['status'] !== 'verified') {
            $db->prepare("UPDATE challenge_participants SET status='verified', verified_at=NOW() WHERE id = ?")
               ->execute([$participantId]);
        }
        $db->prepare("UPDATE challenge_magic_links SET used=1 WHERE token = ?")->execute([$magicToken]);

        $_SESSION['challenge_participant_id'] = $participantId;
        session_regenerate_id(true);
        issueAccessToken($db, $participantId);

        // Straight to the Account tab: this is the moment they became a verified guest,
        // and that's where set-password + request-membership live.
        header("Location: /challenges-profile.php?tab=tab-account");
        exit;
    }

    $error = t('ch_verify_token_invalid');
}

if (!$error && !$magicToken && !$inviteToken) {
    $error = t('ch_verify_token_invalid');
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-auth-wrap">
        <div class="card">
            <div class="card-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:64px;height:64px;background:var(--f1-accent-challenges);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-triangle-exclamation" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_verify_title') ?></h2>
                </div>

                <div class="alert alert-error"><?= escape($error) ?></div>
                <a href="/challenges-join.php" class="btn btn-primary btn-accent-challenges" style="width:100%;">
                    <?= t('ch_verify_request_new_link') ?>
                </a>

                <p class="text-center mt-2 text-muted">
                    <a href="index.php" class="text-accent"><?= t('back_home') ?></a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
