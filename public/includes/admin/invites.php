<div class="card mb-2">
    <div class="card-header"><h3><?= t('invite_new_user') ?></h3></div>
    <div class="card-body">
        <form method="POST" class="flex gap-2" style="align-items: end;">
            <?= csrfField() ?>
            <div class="form-group" style="margin:0; flex:1;">
                <label class="form-label"><?= t('email') ?></label>
                <input type="email" name="invite_email" class="form-input" required placeholder="name@example.com">
            </div>
            <button type="submit" name="create_invite" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> <?= t('send_invite') ?>
            </button>
        </form>
        <p class="text-muted mt-1" style="font-size: 0.875rem;">
            <?= t('invite_expires_desc') ?>
        </p>
    </div>
</div>

<?php
$pendingInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) > time());
$usedInvites    = array_filter($invites, fn($i) => $i['used']);
$expiredInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) <= time());
?>

<?php if (!empty($pendingInvites)): ?>
    <h3 class="mb-1"><i class="fas fa-clock text-accent"></i> <?= t('pending_invites') ?> (<?= count($pendingInvites) ?>)</h3>
    <?php foreach ($pendingInvites as $invite): ?>
        <div class="card mb-1">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <br><small class="text-muted">
                        <?= t('invited_by') ?> <?= escape($invite['created_by_name'] ?: $invite['created_by_email']) ?>
                        · <?= t('expires') ?> <?= date('d M Y H:i', strtotime($invite['expires_at'])) ?>
                    </small>
                </div>
                <div class="flex gap-1">
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
                        <button type="submit" name="resend_invite" class="btn btn-secondary btn-sm" title="<?= t('resend') ?>">
                            <i class="fas fa-redo"></i>
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-sm invite-copy-btn" data-link="<?= escape((defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL) . '/register.php?token=' . $invite['token']) ?>" title="<?= t('copy_link') ?>">
                        <i class="fas fa-copy"></i>
                    </button>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
                        <button type="submit" name="delete_invite" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($usedInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-check-circle" style="color: #10b981;"></i> <?= t('used_invites') ?> (<?= count($usedInvites) ?>)</h3>
    <?php foreach ($usedInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.7;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #10b981; color: white; margin-left: 0.5rem;"><?= t('registered_badge') ?></span>
                    <br><small class="text-muted"><?= t('invited_label') ?> <?= date('d M Y', strtotime($invite['created_at'])) ?></small>
                </div>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
                    <button type="submit" name="delete_invite" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($expiredInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-times-circle" style="color: #ef4444;"></i> <?= t('expired_invites') ?> (<?= count($expiredInvites) ?>)</h3>
    <?php foreach ($expiredInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.5;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;"><?= t('expired_badge') ?></span>
                    <br><small class="text-muted"><?= t('expired_label') ?> <?= date('d M Y', strtotime($invite['expires_at'])) ?></small>
                </div>
                <div class="flex gap-1">
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
                        <button type="submit" name="resend_invite" class="btn btn-secondary btn-sm" title="<?= t('resend') ?>">
                            <i class="fas fa-redo"></i> <?= t('renew') ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
                        <button type="submit" name="delete_invite" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (empty($invites)): ?>
    <div class="card">
        <div class="card-body text-center text-muted"><?= t('no_invites') ?></div>
    </div>
<?php endif; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.invite-copy-btn').forEach(button => {
        button.addEventListener('click', function() {
            navigator.clipboard.writeText(this.getAttribute('data-link')).then(function() {
                alert('<?= t('link_copied') ?>');
            });
        });
    });
});
</script>
