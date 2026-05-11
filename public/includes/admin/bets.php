<?php
$betsByRace = [];
foreach ($bets as $bet) {
    $betsByRace[$bet['race_id']][] = $bet;
}
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;
?>
<?php foreach ($betsByRace as $raceId => $raceBets):
    $raceName = $raceBets[0]['race_name'];
    $raceData = $racesById[$raceId] ?? null;
    $canDeleteBets = false;
    if ($raceData) {
        $raceDateTime = new DateTime($raceData['race_date'] . ' ' . $raceData['race_time']);
        $now = new DateTime();
        $bettingOpens = clone $raceDateTime;
        $bettingOpens->modify("-{$bettingWindowHours} hours");
        $canDeleteBets = !$raceData['result_p1'] && $now >= $bettingOpens && $now < $raceDateTime;
    }
?>
    <div class="card mb-2">
        <div class="card-header flex items-center justify-between">
            <h3><?= escape($raceName) ?></h3>
            <div class="flex items-center gap-2">
                <?php if ($canDeleteBets): ?>
                    <span class="badge status-open"><?= t('betting_open_label') ?></span>
                <?php endif; ?>
                <span class="badge" style="background: var(--bg-secondary);"><?= count($raceBets) ?> bets</span>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($raceBets as $bet): ?>
                <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?>">
                    <div class="bet-user">
                        <div class="bet-avatar"><?= escape(strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1))) ?></div>
                        <div>
                            <strong class="flex items-center gap-1">
                                <?= escape($bet['display_name'] ?: $bet['email']) ?>
                                <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
                            </strong>
                            <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="bet-predictions">
                            <?php foreach (['p1', 'p2', 'p3'] as $i => $key):
                                $driver = $driversById[$bet[$key]] ?? null;
                            ?>
                                <span class="bet-pred"><b>P<?= $i + 1 ?>:</b> <?= $driver ? explode(' ', $driver['name'])[count(explode(' ', $driver['name']))-1] : '?' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($bet['points'] > 0): ?>
                            <span class="badge" style="background: var(--f1-red); color: white;"><?= $bet['points'] ?> pts</span>
                        <?php endif; ?>
                        <?php if ($canDeleteBets): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="bet_id" value="<?= escape($bet['id']) ?>">
                                <button type="submit" name="delete_bet" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($bet['display_name'] ?: $bet['email']) ?>" title="<?= t('delete_notify_user') ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($bets)): ?>
    <div class="card"><div class="card-body text-center text-muted"><?= t('no_bets') ?></div></div>
<?php endif; ?>
