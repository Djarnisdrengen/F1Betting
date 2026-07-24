<div class="card mt-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_qm_queue') ?></h2>

        <?php if (empty($qmQueue)): ?>
            <p class="text-muted" data-testid="admin-qm-queue-empty"><?= t('admin_ch_qm_queue_empty') ?></p>
        <?php else: ?>
            <?php foreach ($qmQueue as $q): ?>
                <div class="card mb-1" data-testid="admin-qm-queue-row" data-participant-id="<?= escape($q['participant_id']) ?>" data-position="<?= $q['position'] ?>">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <span class="btn btn-sm" style="background:var(--bg-secondary);color:var(--text-primary);border:none;cursor:default;min-width:34px;text-align:center;">
                                <?= sprintf(t('admin_ch_qm_position'), $q['position']) ?>
                            </span>
                            <div>
                                <strong><?= escape($q['display_name'] ?: $q['email']) ?></strong>
                                <br><small class="text-muted"><?= escape($q['race_name']) ?> · <?= sprintf(t('admin_ch_qm_waiting_since'), escape(date('d M Y H:i', strtotime($q['created_at'])))) ?></small>
                            </div>
                        </div>
                        <?php if ($q['expired']): ?>
                            <span class="btn btn-sm" style="background:var(--f1-red);color:#fff;border:none;cursor:default;flex-shrink:0;"><?= t('admin_ch_qm_expired') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_duels') ?></h2>

        <div class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;">
            <span class="text-muted" style="font-size:13px;"><?= t('admin_ch_duel_sort_label') ?></span>
            <a href="?tab=duels&duel_sort=newest" class="btn btn-sm <?= $duelSort === 'newest' ? 'btn-primary' : 'btn-secondary' ?>"><?= t('admin_ch_duel_sort_newest') ?></a>
            <a href="?tab=duels&duel_sort=oldest" class="btn btn-sm <?= $duelSort === 'oldest' ? 'btn-primary' : 'btn-secondary' ?>"><?= t('admin_ch_duel_sort_oldest') ?></a>
        </div>

        <?php if (empty($duelsOversight)): ?>
            <p class="text-muted"><?= t('admin_ch_duels_empty') ?></p>
        <?php else: ?>
            <!-- Bulk delete bar — row checkboxes post ids[] here via the HTML5 form= attribute,
                 driven by the generic data-bulk-group multiselect JS in app.js (group "duel"). -->
            <form method="POST" id="bulk-duel" class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;" data-bulk-group="duel">
                <?= csrfField() ?>
                <input type="hidden" name="duel_sort" value="<?= escape($duelSort) ?>">
                <label class="flex items-center gap-1" style="margin:0;cursor:pointer;">
                    <input type="checkbox" data-bulk-toggle="duel"> <?= t('admin_ch_bulk_select_all') ?>
                </label>
                <span class="text-muted" data-bulk-count="duel" data-tpl="<?= escape(str_replace('%d', '{n}', t('admin_ch_bulk_selected'))) ?>" style="font-size:13px;"></span>
                <span style="flex:1;"></span>
                <button type="submit" name="action" value="bulk_delete_duels" class="btn btn-sm" data-bulk-action data-confirm="<?= escape(t('admin_ch_duel_bulk_delete_confirm')) ?>" style="background:var(--f1-red);color:#fff;border:none;" disabled><?= t('admin_ch_bulk_delete') ?></button>
            </form>

            <?php foreach ($duelsOversight as $d): ?>
                <?php
                    $picks    = $duelPicksByDuel[$d['id']] ?? [];
                    $cPick    = $picks[$d['challenger_id']] ?? null;
                    $oPick    = $picks[$d['opponent_id']] ?? null;
                    $locked   = isDuelRaceLocked($d);
                    if ($d['status'] === 'resolved') {
                        $displayStatus = 'settled';
                    } elseif ($d['status'] === 'void') {
                        $displayStatus = 'void';
                    } else {
                        $displayStatus = $locked ? 'locked' : 'open';
                    }
                    $statusColors = [
                        'open'    => ['bg' => 'var(--bg-secondary)', 'fg' => 'var(--text-primary)'],
                        'locked'  => ['bg' => '#f59e0b', 'fg' => '#1a1a1a'],
                        'settled' => ['bg' => 'var(--status-success, #10b981)', 'fg' => '#fff'],
                        'void'    => ['bg' => 'var(--f1-red)', 'fg' => '#fff'],
                    ];
                    $sc = $statusColors[$displayStatus];
                    $duelName = ($d['challenger_name'] ?: $d['challenger_email']) . ' vs ' . ($d['opponent_name'] ?: $d['opponent_email']);
                ?>
                <div class="card mb-1" data-testid="admin-duel-row" data-duel-id="<?= escape($d['id']) ?>" data-status="<?= $displayStatus ?>">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <label class="hf-bulk-check" style="margin:0;cursor:pointer;" title="<?= t('admin_ch_bulk_select_all') ?>">
                                <input type="checkbox" name="ids[]" value="<?= escape($d['id']) ?>" form="bulk-duel" data-bulk-item="duel">
                            </label>
                            <div>
                                <strong><?= escape($d['challenger_name'] ?: $d['challenger_email']) ?> vs <?= escape($d['opponent_name'] ?: $d['opponent_email']) ?></strong>
                                <br><small class="text-muted"><?= escape($d['race_name']) ?></small>
                                <br><small class="text-muted">
                                    <?php if ($locked || in_array($d['status'], ['resolved', 'void'], true)): ?>
                                        <?= $cPick ? escape(driverLastName($duelDriversById[$cPick['p1']] ?? ['name' => '?'])) . '/' . escape(driverLastName($duelDriversById[$cPick['p2']] ?? ['name' => '?'])) . '/' . escape(driverLastName($duelDriversById[$cPick['p3']] ?? ['name' => '?'])) : t('admin_ch_duel_not_picked') ?>
                                        ·
                                        <?= $oPick ? escape(driverLastName($duelDriversById[$oPick['p1']] ?? ['name' => '?'])) . '/' . escape(driverLastName($duelDriversById[$oPick['p2']] ?? ['name' => '?'])) . '/' . escape(driverLastName($duelDriversById[$oPick['p3']] ?? ['name' => '?'])) : t('admin_ch_duel_not_picked') ?>
                                    <?php else: ?>
                                        <?= $cPick ? t('admin_ch_duel_picked') : t('admin_ch_duel_not_picked') ?> · <?= $oPick ? t('admin_ch_duel_picked') : t('admin_ch_duel_not_picked') ?>
                                    <?php endif; ?>
                                    <?php if ($d['status'] === 'resolved'): ?>
                                        · <?= (int)($cPick['score'] ?? 0) ?>–<?= (int)($oPick['score'] ?? 0) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <div class="flex gap-1 items-center" style="flex-shrink:0;flex-wrap:wrap;">
                            <span class="btn btn-sm" style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;border:none;cursor:default;">
                                <?= t('admin_ch_duel_status_' . $displayStatus) ?>
                            </span>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="duel_id" value="<?= escape($d['id']) ?>">
                                <input type="hidden" name="duel_sort" value="<?= escape($duelSort) ?>">
                                <button type="submit" name="action" value="delete_duel" class="btn btn-sm btn-delete" data-name="<?= escape($duelName) ?>" style="background:var(--f1-red);color:#fff;border:none;">
                                    <i class="fas fa-trash"></i> <?= t('admin_ch_duel_delete') ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
