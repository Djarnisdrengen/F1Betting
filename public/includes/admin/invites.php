<div class="card mb-2">
    <div class="card-header"><h3><?= $lang === 'da' ? 'Inviter ny bruger' : 'Invite new user' ?></h3></div>
    <div class="card-body">
        <form method="POST" class="flex gap-2" style="align-items: end;">
            <?= csrfField() ?>
            <div class="form-group" style="margin:0; flex:1;">
                <label class="form-label"><?= t('email') ?></label>
                <input type="email" name="invite_email" class="form-input" required placeholder="name@example.com">
            </div>
            <button type="submit" name="create_invite" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> <?= $lang === 'da' ? 'Send invitation' : 'Send invite' ?>
            </button>
        </form>
        <p class="text-muted mt-1" style="font-size: 0.875rem;">
            <?= $lang === 'da'
                ? 'Invitationen udløber efter 7 dage. Brugeren modtager en email med et registreringslink.'
                : 'Invite expires after 7 days. User will receive an email with a registration link.' ?>
        </p>
    </div>
</div>

<?php
$pendingInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) > time());
$usedInvites    = array_filter($invites, fn($i) => $i['used']);
$expiredInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) <= time());
?>

<?php if (!empty($pendingInvites)): ?>
    <h3 class="mb-1"><i class="fas fa-clock text-accent"></i> <?= $lang === 'da' ? 'Afventende invitationer' : 'Pending invites' ?> (<?= count($pendingInvites) ?>)</h3>
    <?php foreach ($pendingInvites as $invite): ?>
        <div class="card mb-1">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <br><small class="text-muted">
                        <?= $lang === 'da' ? 'Inviteret af' : 'Invited by' ?> <?= escape($invite['created_by_name'] ?: $invite['created_by_email']) ?>
                        · <?= $lang === 'da' ? 'Udløber' : 'Expires' ?> <?= date('d M Y H:i', strtotime($invite['expires_at'])) ?>
                    </small>
                </div>
                <div class="flex gap-1">
                    <a href="?tab=invites&resend_invite=<?= escape($invite['id']) ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                        <i class="fas fa-redo"></i>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm invite-copy-btn" data-link="<?= escape((defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL) . '/register.php?token=' . $invite['token']) ?>" title="<?= $lang === 'da' ? 'Kopiér link' : 'Copy link' ?>">
                        <i class="fas fa-copy"></i>
                    </button>
                    <a href="?tab=invites&delete_invite=<?= escape($invite['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($usedInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-check-circle" style="color: #10b981;"></i> <?= $lang === 'da' ? 'Brugte invitationer' : 'Used invites' ?> (<?= count($usedInvites) ?>)</h3>
    <?php foreach ($usedInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.7;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #10b981; color: white; margin-left: 0.5rem;"><?= $lang === 'da' ? 'Registreret' : 'Registered' ?></span>
                    <br><small class="text-muted"><?= $lang === 'da' ? 'Inviteret' : 'Invited' ?> <?= date('d M Y', strtotime($invite['created_at'])) ?></small>
                </div>
                <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($expiredInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-times-circle" style="color: #ef4444;"></i> <?= $lang === 'da' ? 'Udløbne invitationer' : 'Expired invites' ?> (<?= count($expiredInvites) ?>)</h3>
    <?php foreach ($expiredInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.5;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;"><?= $lang === 'da' ? 'Udløbet' : 'Expired' ?></span>
                    <br><small class="text-muted"><?= $lang === 'da' ? 'Udløb' : 'Expired' ?> <?= date('d M Y', strtotime($invite['expires_at'])) ?></small>
                </div>
                <div class="flex gap-1">
                    <a href="?tab=invites&resend_invite=<?= $invite['id'] ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                        <i class="fas fa-redo"></i> <?= $lang === 'da' ? 'Forny' : 'Renew' ?>
                    </a>
                    <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (empty($invites)): ?>
    <div class="card">
        <div class="card-body text-center text-muted"><?= $lang === 'da' ? 'Ingen invitationer endnu' : 'No invites yet' ?></div>
    </div>
<?php endif; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.invite-copy-btn').forEach(button => {
        button.addEventListener('click', function() {
            navigator.clipboard.writeText(this.getAttribute('data-link')).then(function() {
                alert('<?= $lang === 'da' ? 'Link kopieret!' : 'Link copied!' ?>');
            });
        });
    });
});
</script>
