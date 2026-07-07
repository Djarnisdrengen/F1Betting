<?php foreach ($users as $user): ?>
    <div class="card mb-1">
        <div class="card-body admin-user-card-body">
            <div class="admin-user-info">
                <div class="user-avatar"><?= escape(strtoupper(substr($user['display_name'] ?: $user['email'], 0, 1))) ?></div>
                <div>
                    <strong><?= escape($user['display_name'] ?: $user['email']) ?></strong>
                    <br><small class="text-muted"><?= escape($user['email']) ?></small>
                    <br><small class="text-muted"><?= t('last_seen') ?><?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : t('never') ?></small>
                </div>
                <span class="badge" style="background: <?= $user['role'] === 'admin' ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['role'] === 'admin' ? 'white' : 'var(--text-primary)' ?>;">
                    <?= escape($user['role']) ?>
                </span>
                <?php if ($user['stars'] > 0): ?>
                    <span class="star">★<?= intval($user['stars']) ?></span>
                <?php endif; ?>
                <span class="text-accent"><?= intval($user['points']) ?> pts</span>
            </div>
            <div class="flex gap-1" style="flex-wrap:wrap;">
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                    <button type="submit" name="toggle_competition" class="btn btn-sm" style="background: <?= $user['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
                        <i class="fas fa-<?= $user['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i> <?= $user['in_competition'] ? t('in_competition_label') : t('not_in_competition_label') ?>
                    </button>
                </form>
                <?php if ($user['id'] !== $currentUser['id']): ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                        <button type="submit" name="toggle_role" class="btn btn-secondary btn-sm">
                            <?= $user['role'] === 'admin' ? t('make_user') : t('make_admin') ?>
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-sm btn-reset-pwd" data-link="<?= escape($user['id']) ?>">
                        <i class="fas fa-key"></i>
                    </button>
                    <?php if (!empty($user['has_mfa'])): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                            <button type="submit" name="remove_user_mfa" class="btn btn-secondary btn-sm btn-remove-mfa" data-testid="admin-remove-mfa"
                                    title="<?= t('remove_mfa') ?>"
                                    data-title="<?= t('remove_mfa_confirm_title') ?>"
                                    data-body="<?= escape(sprintf(t('remove_mfa_confirm_body'), $user['display_name'] ?: $user['email'])) ?>"
                                    data-confirm="<?= t('remove_mfa_confirm_btn') ?>">
                                <i class="fas fa-user-shield"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                        <button type="submit" name="delete_user" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($user['id'] !== $currentUser['id']): ?>
            <div id="reset-pw-<?= escape($user['id']) ?>" class="hidden" style="padding: 1rem; border-top: 1px solid var(--border-color);">
                <form method="POST" action="admin.php?tab=users" class="flex gap-1 items-end">
                    <?= csrfField() ?>
                    <?php if (!empty($_GET['e2e_token'])): ?>
                        <input type="hidden" name="e2e_token" value="<?= escape($_GET['e2e_token']) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                    <input type="hidden" name="user_email" value="<?= escape($user['email']) ?>">
                    <input type="hidden" name="user_name" value="<?= escape($user['display_name']) ?>">
                    <div class="form-group" style="margin:0; flex:1;">
                        <label class="form-label"><?= t('new_password') ?></label>
                        <input type="password" name="new_password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" name="reset_user_password" class="btn btn-primary btn-sm">
                        <?= t('reset') ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-reset-pwd').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('reset-pw-' + this.getAttribute('data-link')).classList.toggle('hidden');
        });
    });
    document.querySelectorAll('.btn-remove-mfa').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            showDeleteModal(this.closest('form'), '', {
                title:       this.dataset.title,
                bodyText:    this.dataset.body,
                confirmText: this.dataset.confirm,
            });
        });
    });
});
</script>
