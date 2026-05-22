<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

$settings = getSettings();
$pointsP1 = $settings['points_p1'] ?? 25;
$pointsP2 = $settings['points_p2'] ?? 18;
$pointsP3 = $settings['points_p3'] ?? 15;
$pointsWrongPos = $settings['points_wrong_pos'] ?? 5;
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;

$ruleMeta = [
    ['n' => '01', 'label' => t('rules_betting_window')],
    ['n' => '02', 'label' => t('rules_points_system')],
    ['n' => '03', 'label' => t('rules_stars')],
    ['n' => '04', 'label' => t('rules_pool')],
    ['n' => '05', 'label' => t('rules_restrictions')],
    ['n' => '06', 'label' => t('rules_leaderboard_sort')],
    ['n' => '07', 'label' => t('rules_example')],
];

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <header class="hf-pageh">
        <div class="crumb"><?= t('rules_title') ?></div>
        <h1><?= t('rules_how_we_play') ?></h1>
    </header>

    <!-- MD-only chip row -->
    <div class="hf-rules-chips">
        <?php foreach ($ruleMeta as $r): ?>
        <a href="#r-<?= $r['n'] ?>" style="display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:7px;text-decoration:none;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:11px;color:var(--text-muted);letter-spacing:.08em;"><?= $r['n'] ?></div>
            <div style="font-family:var(--font-display);font-weight:700;font-size:13px;color:var(--text-primary);"><?= escape($r['label']) ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="hf-rules-layout">
        <!-- LG+ TOC sidebar -->
        <nav class="hf-toc">
            <div class="hf-toc-title"><?= t('rules_contents') ?></div>
            <?php foreach ($ruleMeta as $r): ?>
            <a href="#r-<?= $r['n'] ?>"><span class="n"><?= $r['n'] ?></span><?= escape($r['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <!-- Rules body -->
        <div>
            <div class="hf-rule" id="r-01">
                <div class="hf-rule-num">01</div>
                <div>
                    <h3><?= t('rules_betting_window') ?></h3>
                    <p><?= t('opens') ?>: <?= sprintf(t('betting_opens_hours'), $bettingWindowHours) ?></p>
                    <p><?= t('closes') ?>: <?= t('at_race_start') ?></p>
                    <p><?= t('edit_label') ?>: <?= t('bets_editable') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-02">
                <div class="hf-rule-num">02</div>
                <div>
                    <h3><?= t('rules_points_system') ?></h3>
                    <p>P1 &rarr; <?= $pointsP1 ?> <?= t('points_label') ?> &middot; P2 &rarr; <?= $pointsP2 ?> <?= t('points_label') ?> &middot; P3 &rarr; <?= $pointsP3 ?> <?= t('points_label') ?></p>
                    <p><?= t('wrong_pos_rule') ?> +<?= $pointsWrongPos ?> <?= t('points_label') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-03">
                <div class="hf-rule-num">03</div>
                <div>
                    <h3><?= t('rules_stars') ?></h3>
                    <p><?= t('perfect_bet_stars_desc') ?> ★ 1 <?= t('star') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-04">
                <div class="hf-rule-num">04</div>
                <div>
                    <h3><?= t('rules_pool') ?></h3>
                    <p><?= t('pool_win_desc') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-05">
                <div class="hf-rule-num">05</div>
                <div>
                    <h3><?= t('rules_restrictions') ?></h3>
                    <p><?= t('one_bet_per_race') ?>: <?= t('one_bet_per_race_desc') ?></p>
                    <p><?= t('no_duplicates') ?>: <?= t('no_duplicates_desc') ?></p>
                    <p><?= t('quali_restriction') ?>: <?= t('quali_restriction_desc') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-06">
                <div class="hf-rule-num">06</div>
                <div>
                    <h3><?= t('rules_leaderboard_sort') ?></h3>
                    <p>1. <?= t('leaderboard_sort_stars') ?></p>
                    <p>2. <?= t('leaderboard_sort_points') ?></p>
                </div>
            </div>

            <div class="hf-rule" id="r-07">
                <div class="hf-rule-num">07</div>
                <div>
                    <h3><?= t('rules_example') ?></h3>
                    <p><strong><?= t('race_result_label') ?>:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
                    <p><?= t('scenario_1') ?>: P1 = Verstappen, P2 = Leclerc, P3 = Norris &rarr; <?= $pointsP1 + $pointsWrongPos + $pointsWrongPos ?> <?= t('points_label') ?></p>
                    <p><?= t('scenario_2_perfect') ?>: P1 = Verstappen, P2 = Norris, P3 = Leclerc &rarr; <?= $pointsP1 + $pointsP2 + $pointsP3 ?> <?= t('points_label') ?> + ★</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
