<?php
// Public, no-login opt-out endpoint (REQ-804). Verifiable without a DB lookup at send time —
// the HMAC alone proves the link is genuine, so any past or future invite to this address is
// honoured even after the originating invite row expires.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$email = sanitizeEmail($_GET['e'] ?? '');
$token = sanitizeString($_GET['t'] ?? '');

$valid = false;
if ($email && $token) {
    $expected = hash_hmac('sha256', strtolower(trim($email)), CHALLENGE_INVITE_SECRET);
    if (hash_equals($expected, $token)) {
        $valid = true;
        $db = getDB();
        // Idempotent — a repeat click (or an already-admin-suppressed address) is a no-op write.
        $db->prepare("
            INSERT INTO challenge_email_suppressions (email, reason) VALUES (?, 'opt_out')
            ON DUPLICATE KEY UPDATE created_at = created_at
        ")->execute([$email]);
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <div class="hf-auth-wrap">
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <?php if ($valid): ?>
                    <div style="width:64px;height:64px;background:var(--f1-accent-challenges);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-check" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_optout_confirmed_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= t('ch_optout_confirmed_body') ?></p>
                <?php else: ?>
                    <div style="width:64px;height:64px;background:var(--f1-accent-challenges);border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-triangle-exclamation" style="font-size:2rem;color:white;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;"><?= t('ch_optout_invalid_title') ?></h2>
                    <p class="text-muted" style="margin:0;"><?= t('ch_optout_invalid_body') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
