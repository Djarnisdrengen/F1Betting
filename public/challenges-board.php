<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

$db = getDB();
$leaderboard = getCpLeaderboard($db, 50);

include __DIR__ . '/includes/header.php';
?>

<style>
.hf-arena-base { background-color: #0b0b0d; }
.hf-arena-header {
    background: linear-gradient(90deg, #17171b, #0d0d10);
    padding: 16px;
    border-bottom: 1px solid rgba(245, 245, 247, 0.1);
}
.hf-arena-band {
    background: rgba(13, 13, 16, 0.95);
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.1em;
    color: #f5f5f7;
}
.hf-arena-strip {
    background: repeating-conic-gradient(#f5f5f7 0 25%, #0b0b0d 0 50%) 0 0/14px 14px;
    height: 8px;
}
.hf-row {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 16px;
    align-items: center;
    padding: 12px;
    background: rgba(35, 35, 40, 0.62);
    border-radius: 8px;
    margin-bottom: 8px;
    color: #f5f5f7;
}
.hf-rank {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-weight: 700;
    font-size: 16px;
}
.hf-rank.r1 {
    background: #ffd700;
    color: #0b0b0d;
}
.hf-rank.r2 {
    background: #c0c0c0;
    color: #0b0b0d;
}
.hf-rank.r3 {
    background: #cd7f32;
    color: #fff;
}
.hf-rank.other {
    background: rgba(35, 35, 40, 0.7);
    color: #f5f5f7;
}
.hf-cp-total {
    text-align: right;
    font-weight: 700;
    font-size: 18px;
    color: #f5f5f7;
    text-shadow: 0 0 24px rgba(251, 191, 36, 0.4);
}
</style>

<div class="hf-arena-base" style="min-height:100vh;padding-bottom:80px;">
    <div class="hf-arena-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <h1 style="margin:0;font-size:24px;font-weight:700;color:#f5f5f7;">
                <i class="fas fa-bolt" style="margin-right:8px;color:var(--f1-accent-challenges);"></i>
                <?= t('ch_public_board') ?>
            </h1>
        </div>
    </div>

    <div class="hf-arena-strip"></div>

    <div class="hf-arena-band">
        <?= t('ch_board_lede') ?>
    </div>

    <div class="hf-container" style="padding:20px;color:#f5f5f7;">
        <?php if (empty($leaderboard)): ?>
            <div style="text-align:center;padding:40px 20px;color:#f5f5f7;opacity:0.7;">
                <p><?= t('ch_all_caught_up') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($leaderboard as $index => $row): ?>
                <div class="hf-row">
                    <div class="hf-rank <?= $index === 0 ? 'r1' : ($index === 1 ? 'r2' : ($index === 2 ? 'r3' : 'other')) ?>">
                        <?= intval($index + 1) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;">
                            <?php if ($row['display_name']): ?>
                                <?= escape($row['display_name']) ?>
                            <?php else: ?>
                                Guest <?= substr($row['id'], -4) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="hf-cp-total">
                        <?= intval($row['total_cp']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
