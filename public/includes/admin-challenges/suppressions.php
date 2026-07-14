<div class="card mt-3">
    <div class="card-body">
        <h2 style="margin-bottom:8px;"><?= t('admin_ch_suppressions') ?></h2>
        <p class="text-muted" style="margin:0 0 12px;"><?= sprintf(t('admin_ch_suppressions_count'), count($suppressions)) ?></p>

        <form method="POST" class="flex gap-1 items-end mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="admin_suppress_email">
            <div class="form-group" style="margin:0;flex:1;">
                <input type="email" name="suppress_email" class="form-input" placeholder="friend@example.com" required>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm"><?= t('admin_ch_suppress_add') ?></button>
        </form>

        <?php if (empty($suppressions)): ?>
            <p class="text-muted"><?= t('admin_ch_suppress_list_empty') ?></p>
        <?php else: ?>
            <div class="form-group">
                <input type="text" id="suppression-search" class="form-input" placeholder="<?= escape(t('admin_ch_suppress_search_placeholder')) ?>">
            </div>
            <div id="suppression-list">
                <?php foreach ($suppressions as $s): ?>
                    <div class="card mb-1" data-testid="suppression-row" data-email="<?= escape(strtolower($s['email'])) ?>">
                        <div class="card-body admin-user-card-body">
                            <div>
                                <strong><?= escape($s['email']) ?></strong>
                                <br><small class="text-muted">
                                    <?= t('admin_ch_suppress_reason_' . $s['reason']) ?> · <?= escape(date('d M Y', strtotime($s['created_at']))) ?>
                                </small>
                            </div>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="suppression_id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" name="action" value="remove_suppression" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($s['email']) ?>">
                                    <i class="fas fa-trash"></i> <?= t('admin_ch_suppress_remove') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('suppression-search');
    if (!search) return;
    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        document.querySelectorAll('#suppression-list [data-testid="suppression-row"]').forEach(function (row) {
            row.style.display = row.dataset.email.indexOf(q) === -1 ? 'none' : '';
        });
    });
});
</script>
