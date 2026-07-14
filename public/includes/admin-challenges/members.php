<div class="card mb-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_promotion_queue') ?></h2>

        <?php if (empty($pendingRequests)): ?>
            <p class="text-muted"><?= t('admin_ch_queue_empty') ?></p>
        <?php else: ?>
            <?php foreach ($pendingRequests as $req): ?>
                <div class="card mb-1">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <div class="user-avatar"><?= escape(strtoupper(substr($req['display_name'] ?: $req['email'], 0, 1))) ?></div>
                            <div>
                                <strong><?= escape($req['display_name'] ?: $req['email']) ?></strong>
                                <br><small class="text-muted"><?= escape($req['email']) ?></small>
                                <br><small class="text-muted">
                                    <?= !empty($req['password_hash']) ? t('admin_ch_permanent') : t('admin_ch_verified_only') ?>
                                    · <?= (int) getChallengeCpTotal($db, $req['id']) ?> CP
                                    · <?= escape(date('d M Y', strtotime($req['promotion_requested_at']))) ?>
                                </small>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve_promotion">
                                <input type="hidden" name="participant_id" value="<?= escape($req['id']) ?>">
                                <button type="submit" class="btn btn-primary btn-sm"><?= t('admin_ch_approve') ?></button>
                            </form>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject_promotion">
                                <input type="hidden" name="participant_id" value="<?= escape($req['id']) ?>">
                                <button type="submit" class="btn btn-secondary btn-sm"><?= t('admin_ch_reject') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_converted_guests') ?></h2>

        <?php if (empty($convertedGuests)): ?>
            <p class="text-muted"><?= t('admin_ch_no_converted_guests') ?></p>
        <?php else: ?>
            <?php foreach ($convertedGuests as $guest): ?>
                <div class="card mb-1">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <div class="user-avatar"><?= escape(strtoupper(substr($guest['display_name'] ?: $guest['email'], 0, 1))) ?></div>
                            <div>
                                <strong><?= escape($guest['display_name'] ?: $guest['email']) ?></strong>
                                <br><small class="text-muted"><?= escape($guest['email']) ?></small>
                                <br><small class="text-muted">
                                    <?= (int) $guest['points'] ?> pts · <?= (int) getChallengeCpTotal($db, $guest['participant_id']) ?> CP
                                </small>
                            </div>
                        </div>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_guest_competition">
                            <input type="hidden" name="user_id" value="<?= escape($guest['user_id']) ?>">
                            <button type="submit" class="btn btn-sm" style="background: <?= $guest['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $guest['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
                                <i class="fas fa-<?= $guest['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i>
                                <?= $guest['in_competition'] ? t('in_competition_label') : t('not_in_competition_label') ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
