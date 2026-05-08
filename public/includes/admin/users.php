<?php foreach ($users as $user): ?>
    <div class="card mb-1">
        <div class="card-body flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="user-avatar"><?= escape(strtoupper(substr($user['display_name'] ?: $user['email'], 0, 1))) ?></div>
                <div>
                    <strong><?= escape($user['display_name'] ?: $user['email']) ?></strong>
                    <br><small class="text-muted"><?= escape($user['email']) ?></small>
                </div>
                <span class="badge" style="background: <?= $user['role'] === 'admin' ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['role'] === 'admin' ? 'white' : 'var(--text-primary)' ?>;">
                    <?= escape($user['role']) ?>
                </span>
                <?php if ($user['stars'] > 0): ?>
                    <span class="star">★<?= intval($user['stars']) ?></span>
                <?php endif; ?>
                <span class="text-accent"><?= intval($user['points']) ?> pts</span>
            </div>
            <div class="flex gap-1">
                <a href="?tab=users&toggle_competition=<?= escape($user['id']) ?>" class="btn btn-sm" style="background: <?= $user['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
                    <i class="fas fa-<?= $user['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i> <?= $user['in_competition'] ? ($lang === 'da' ? 'I Konkurrence' : 'In Competition') : ($lang === 'da' ? 'Ikke I Konkurrence' : 'Not In Competition') ?>
                </a>
                <?php if ($user['id'] !== $currentUser['id']): ?>
                    <a href="?tab=users&toggle_role=<?= escape($user['id']) ?>" class="btn btn-secondary btn-sm">
                        <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm btn-reset-pwd" data-link="<?= escape($user['id']) ?>">
                        <i class="fas fa-key"></i>
                    </button>
                    <a href="?tab=users&delete_user=<?= escape($user['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($user['id'] !== $currentUser['id']): ?>
            <div id="reset-pw-<?= escape($user['id']) ?>" class="hidden" style="padding: 1rem; border-top: 1px solid var(--border-color);">
                <form method="POST" class="flex gap-1 items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                    <input type="hidden" name="user_email" value="<?= escape($user['email']) ?>">
                    <input type="hidden" name="user_name" value="<?= escape($user['display_name']) ?>">
                    <div class="form-group" style="margin:0; flex:1;">
                        <label class="form-label"><?= $lang === 'da' ? 'Ny adgangskode' : 'New password' ?></label>
                        <input type="password" name="new_password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" name="reset_user_password" class="btn btn-primary btn-sm">
                        <?= $lang === 'da' ? 'Nulstil' : 'Reset' ?>
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
});
</script>
