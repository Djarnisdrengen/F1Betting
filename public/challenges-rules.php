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

        <!-- MD-only chip row -->
        <div class="hf-rules-chips">
            <?php foreach ($sections as $i => $s): ?>
            <a href="#<?= $s['id'] ?>" style="display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:7px;text-decoration:none;">
                <div style="font-family:var(--font-display);font-weight:800;font-size:11px;color:var(--text-muted);letter-spacing:.08em;"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                <div style="font-family:var(--font-display);font-weight:700;font-size:13px;color:var(--text-primary);"><?= escape($s['title']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="hf-rules-layout">
            <!-- LG+ TOC sidebar -->
            <nav class="hf-toc">
                <div class="hf-toc-title"><?= t('rules_contents') ?></div>
                <?php foreach ($sections as $i => $s): ?>
                <a href="#<?= $s['id'] ?>"><span class="n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><?= escape($s['title']) ?></a>
                <?php endforeach; ?>
            </nav>

            <!-- Rules body -->
            <div>
                <?php foreach ($sections as $i => $s): ?>
                <div class="hf-rule" id="<?= $s['id'] ?>">
                    <div class="hf-rule-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <div>
                        <h3><?= escape($s['title']) ?></h3>
                        <?php foreach ($s['body'] as $p): ?>
                        <p><?= $p ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

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
