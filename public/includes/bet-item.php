<?php
// Renders a single bet row inside a bets-section list.
// Caller must have in scope: $bet, $driversById, $currentUser.
// Optional caller flags (single-race focus page, race.php):
//   $_bi_full   — true to render the YOU tag, 3-letter driver codes, and the scored/"— pts" cell.
//   $_bi_scored — true when the race has a result; drives the points badge vs "— pts" placeholder.
// When the flags are unset (e.g. races.php), the row renders exactly as before.
$_bi_mine   = $currentUser && $bet['user_id'] === $currentUser['id'];
$_bi_full   = $_bi_full   ?? false;
$_bi_scored = $_bi_scored ?? false;
?>
<div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?> <?= $_bi_mine ? 'my-bet' : '' ?>">
    <div class="bet-user">
        <div class="bet-avatar"><?= userInitial($bet) ?></div>
        <div style="flex: 1; min-width: 0;">
            <strong class="flex items-center gap-1">
                <?= displayUserName($bet) ?>
                <?php if ($_bi_full && $_bi_mine): ?><span class="race-you-tag"><?= t('you_badge') ?></span><?php endif; ?>
                <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
            </strong>
            <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
        </div>
        <?php if ($_bi_full): ?>
            <?php if ($_bi_scored): ?>
                <span class="hf-badge soon" style="flex-shrink: 0;"><?= (int) $bet['points'] ?> pts</span>
            <?php else: ?>
                <span class="race-pts-pending" style="flex-shrink: 0;">— pts</span>
            <?php endif; ?>
        <?php elseif ($bet['points'] > 0): ?>
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
<?php unset($_bi_mine, $_bi_full, $_bi_scored, $_bi_i, $_bi_key, $_bi_driver); ?>
