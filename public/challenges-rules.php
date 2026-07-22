<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

$participant = getChallengeParticipant();

$sections = [
    ['id' => 's1', 'title' => t('ch_rules_s1_title'), 'body' => [t('ch_rules_s1_p1'), t('ch_rules_s1_p2'), t('ch_rules_s1_p3')]],
    ['id' => 's2', 'title' => t('ch_rules_s2_title'), 'body' => [t('ch_rules_s2_p1'), t('ch_rules_s2_p2'), t('ch_rules_s2_p3')]],
    ['id' => 's3', 'title' => t('ch_rules_s3_title'), 'body' => [t('ch_rules_s3_p1'), t('ch_rules_s3_p2'), t('ch_rules_s3_p3')]],
    ['id' => 's4', 'title' => t('ch_rules_s4_title'), 'body' => [t('ch_rules_s4_p1'), t('ch_rules_s4_p2'), t('ch_rules_s4_p3'), t('ch_rules_s4_p4')]],
    ['id' => 's5', 'title' => t('ch_rules_s5_title'), 'body' => [t('ch_rules_s5_p1'), t('ch_rules_s5_p2'), t('ch_rules_s5_p3')]],
    ['id' => 's6', 'title' => t('ch_rules_s6_title'), 'body' => [t('ch_rules_s6_p1'), t('ch_rules_s6_p2')]],
    ['id' => 's7', 'title' => t('ch_rules_s7_title'), 'body' => [t('ch_rules_s7_p1'), t('ch_rules_s7_p2')]],
];

include __DIR__ . '/includes/header.php';
?>

<div class="hf-arena-base" style="min-height:100vh;padding-bottom:80px;">
    <div class="hf-arena-header">
        <a href="challenges.php" style="display:inline-block;margin-bottom:8px;color:var(--text-primary);opacity:.65;font-size:12px;text-decoration:none;">
            <i class="fas fa-arrow-left" style="margin-right:6px;"></i><?= t('ch_back_to_overview') ?>
        </a>
        <h1 style="margin:0;font-size:22px;font-weight:700;color:var(--text-primary);">
            <i class="fas fa-book" style="margin-right:8px;color:var(--f1-accent-challenges);"></i>
            <?= t('ch_rules_heading') ?>
        </h1>
    </div>

    <div class="hf-container" style="padding:20px;color:var(--text-primary);">
        <p style="font-size:15px;line-height:1.6;opacity:.9;margin:0 0 20px;"><?= t('ch_rules_intro') ?></p>

        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;">
            <?php foreach ($sections as $s): ?>
            <a href="#<?= $s['id'] ?>" class="hf-rules-chip"><?= escape($s['title']) ?></a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($sections as $i => $s): ?>
        <div class="hf-rules-card" id="<?= $s['id'] ?>">
            <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:10px;">
                <span class="hf-rules-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <h2 style="margin:0;font-size:17px;font-weight:700;color:var(--text-primary);"><?= escape($s['title']) ?></h2>
            </div>
            <?php foreach ($s['body'] as $p): ?>
            <p style="font-size:14px;line-height:1.6;opacity:.9;margin:0 0 10px;"><?= $p ?></p>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div style="text-align:center;margin-top:8px;">
            <?php if ($participant): ?>
            <a href="challenges.php" class="btn btn-primary btn-accent-challenges"><?= t('ch_verify_go_to_challenges') ?></a>
            <?php else: ?>
            <a href="challenges-join.php" class="btn btn-primary btn-accent-challenges"><?= t('ch_play_now') ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
