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

<h2 style="margin:24px 0 8px;"><?= t('admin_ch_participants_title') ?></h2>
<p class="text-muted" style="margin:0 0 12px;"><?= sprintf(t('admin_ch_participants_count'), count($allParticipants)) ?></p>

<?php if (empty($allParticipants)): ?>
    <p class="text-muted"><?= t('admin_ch_participants_empty') ?></p>
<?php else: ?>
<!-- Bulk action bar — checkboxes on each row below post ids[] here via the HTML5 form= attribute,
     driven by the generic data-bulk-group multiselect JS in app.js (group "participant"). -->
<form method="POST" id="bulk-participant" class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;" data-bulk-group="participant">
    <?= csrfField() ?>
    <label class="flex items-center gap-1" style="margin:0;cursor:pointer;">
        <input type="checkbox" data-bulk-toggle="participant"> <?= t('admin_ch_bulk_select_all') ?>
    </label>
    <span class="text-muted" data-bulk-count="participant" data-tpl="<?= escape(str_replace('%d', '{n}', t('admin_ch_bulk_selected'))) ?>" style="font-size:13px;"></span>
    <span style="flex:1;"></span>
    <button type="submit" name="action" value="bulk_delete_participants" class="btn btn-sm" data-bulk-action data-confirm="<?= escape(t('admin_ch_participant_bulk_delete_confirm')) ?>" style="background:var(--f1-red);color:#fff;border:none;" disabled><?= t('admin_ch_bulk_delete') ?></button>
</form>

<?php foreach ($allParticipants as $p):
    // Row kind (independent of pending/verified status): native-core rows are auto-created
    // on first hub visit (email NULL); a linked core_user_id with an email is a promoted
    // full member; otherwise it's a plain guest.
    if (empty($p['email']))              { $kind = 'native';   $kindClass = 'soon'; }
    elseif (!empty($p['core_user_id']))  { $kind = 'promoted'; $kindClass = 'open'; }
    else                                 { $kind = 'guest';    $kindClass = 'soon'; }
    $deleteName = $p['display_name'] ?: ($p['email'] ?: $p['id']);
?>
<div class="hf-racefull" data-testid="participant-row"
     data-participant-id="<?= escape($p['id']) ?>" data-kind="<?= $kind ?>">
    <div class="hf-racefull-hd">
        <label class="hf-bulk-check" style="margin:0;padding-top:2px;cursor:pointer;" title="<?= t('admin_ch_bulk_select_all') ?>">
            <input type="checkbox" name="ids[]" value="<?= escape($p['id']) ?>" form="bulk-participant" data-bulk-item="participant">
        </label>
        <div class="hf-racefull-info">
            <div class="hf-racename">
                <?= escape($p['display_name'] ?: '—') ?>
                <span class="hf-badge <?= $kindClass ?>"><?= t('admin_ch_participant_badge_' . $kind) ?></span>
            </div>
            <div class="hf-racemeta">
                <?= $p['email'] ? escape($p['email']) : '—' ?>
                · <?= escape(strtoupper($p['language'])) ?>
                · <span class="hf-badge <?= $p['status'] === 'verified' ? 'open' : 'soon' ?>"><?= t('admin_ch_status_' . $p['status']) ?></span>
                · <?= t('admin_ch_participant_created') ?> <?= escape(date('d M Y', strtotime($p['created_at']))) ?>
                · <?= t('admin_ch_participant_promo_requested') ?>
                   <?= $p['promotion_requested_at'] ? escape(date('d M Y', strtotime($p['promotion_requested_at']))) : '—' ?>
            </div>
        </div>
        <div class="flex gap-1" style="flex-shrink:0;flex-wrap:wrap;">
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="participant_id" value="<?= escape($p['id']) ?>">
                <button type="submit" name="action" value="delete_participant" class="btn btn-sm btn-delete" data-name="<?= escape($deleteName) ?>" style="background:var(--f1-red);color:#fff;border:none;">
                    <i class="fas fa-trash"></i> <?= t('admin_ch_participant_delete') ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
