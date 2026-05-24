<?php
// Renders a single bet row inside a bets-section list.
// Caller must have in scope: $bet, $driversById, $currentUser.
$_bi_mine = $currentUser && $bet['user_id'] === $currentUser['id'];
?>
<div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?> <?= $_bi_mine ? 'my-bet' : '' ?>">
    <div class="bet-user">
        <div class="bet-avatar"><?= userInitial($bet) ?></div>
        <div style="flex: 1; min-width: 0;">
            <strong class="flex items-center gap-1">
                <?= displayUserName($bet) ?>
                <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
            </strong>
            <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
        </div>
        <?php if ($bet['points'] > 0): ?>
            <span class="hf-badge soon" style="flex-shrink: 0;"><?= $bet['points'] ?> pts</span>
        <?php endif; ?>
    </div>
    <div class="bet-predictions">
        <?php foreach (['p1', 'p2', 'p3'] as $_bi_i => $_bi_key):
            $_bi_driver = $driversById[$bet[$_bi_key]] ?? null;
        ?>
            <span class="bet-pred"><b>P<?= $_bi_i + 1 ?>:</b> <?= $_bi_driver ? driverLastName($_bi_driver) : '?' ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php unset($_bi_mine, $_bi_i, $_bi_key, $_bi_driver); ?>
