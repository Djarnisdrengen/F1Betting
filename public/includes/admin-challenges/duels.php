<div class="card mt-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_duels') ?></h2>

        <?php if (empty($duelsOversight)): ?>
            <p class="text-muted"><?= t('admin_ch_duels_empty') ?></p>
        <?php else: ?>
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
                ?>
                <div class="card mb-1" data-testid="admin-duel-row" data-duel-id="<?= escape($d['id']) ?>" data-status="<?= $displayStatus ?>">
                    <div class="card-body admin-user-card-body">
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
                        <span class="btn btn-sm" style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;border:none;cursor:default;">
                            <?= t('admin_ch_duel_status_' . $displayStatus) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
