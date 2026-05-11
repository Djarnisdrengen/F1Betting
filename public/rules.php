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

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-book text-accent"></i> <?= t('rules_title') ?></h1>

<div class="rules-container">
        <!-- Betting Window -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-clock text-accent"></i> <?= t('rules_betting_window') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= t('opens') ?></strong></td>
                        <td><?= sprintf(t('betting_opens_hours'), $bettingWindowHours) ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= t('closes') ?></strong></td>
                        <td><?= t('at_race_start') ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= t('edit_label') ?></strong></td>
                        <td><?= t('bets_editable') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Points System -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-star text-accent"></i> <?= t('rules_points_system') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <thead>
                        <tr>
                            <th><?= t('position') ?></th>
                            <th><?= t('correct_prediction') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="position-badge position-1">P1</span></td>
                            <td><strong><?= $pointsP1 ?> <?= t('points_label') ?></strong></td>
                        </tr>
                        <tr>
                            <td><span class="position-badge position-2">P2</span></td>
                            <td><strong><?= $pointsP2 ?> <?= t('points_label') ?></strong></td>
                        </tr>
                        <tr>
                            <td><span class="position-badge position-3">P3</span></td>
                            <td><strong><?= $pointsP3 ?> <?= t('points_label') ?></strong></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-plus-circle text-accent"></i> <strong>Bonus</strong></td>
                            <td>+<?= $pointsWrongPos ?> <?= t('wrong_pos_rule') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Stars -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><span class="star">★</span> <?= t('rules_stars') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= t('perfect_bet') ?></strong></td>
                        <td><?= t('perfect_bet_stars_desc') ?> <span class="star">★</span> 1 <?= t('star') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Betting pool -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><span class="star">$</span> <?= t('rules_pool') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= t('perfect_bet') ?></strong></td>
                        <td><?= t('pool_win_desc') ?> <span class="star">$</span>.</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Restrictions -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-ban text-accent"></i> <?= t('rules_restrictions') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= t('one_bet_per_race') ?></strong></td>
                        <td><?= t('one_bet_per_race_desc') ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= t('no_duplicates') ?></strong></td>
                        <td><?= t('no_duplicates_desc') ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= t('unique_combo') ?></strong></td>
                        <td><?= t('unique_combo_desc') ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= t('quali_restriction') ?></strong></td>
                        <td><?= t('quali_restriction_desc') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Leaderboard Sorting -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-trophy text-accent"></i> <?= t('rules_leaderboard_sort') ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong>1.</strong></td>
                        <td><?= t('leaderboard_sort_points') ?></td>
                    </tr>
                    <tr>
                        <td><strong>2.</strong></td>
                        <td><?= t('leaderboard_sort_stars') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Example -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-lightbulb text-accent"></i> <?= t('rules_example') ?></h3>
            </div>
            <div class="card-body">
                <div class="example-box">
                    <p><strong><?= t('race_result_label') ?>:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
                </div>
                
                <div class="example-scenario mt-2">
                    <h4><?= t('scenario_1') ?>:</h4>
                    <p><strong><?= t('your_bet') ?>:</strong> P1 = Verstappen, P2 = Leclerc, P3 = Norris</p>
                    <ul class="example-calc">
                        <li><span class="text-accent">✓</span> P1 <?= t('correct') ?>: <strong>+<?= $pointsP1 ?> <?= t('points_label') ?></strong></li>
                        <li><span class="text-muted">○</span> P2 <?= t('wrong_but_top3_leclerc') ?>: <strong>+<?= $pointsWrongPos ?> <?= t('points_label') ?></strong></li>
                        <li><span class="text-muted">○</span> P3 <?= t('wrong_but_top3_norris') ?>: <strong>+<?= $pointsWrongPos ?> <?= t('points_label') ?></strong></li>
                        <li class="total"><strong>Total: <?= $pointsP1 + $pointsWrongPos + $pointsWrongPos ?> <?= t('points_label') ?></strong></li>
                    </ul>
                </div>
                
                <div class="example-scenario mt-2">
                    <h4><?= t('scenario_2_perfect') ?> <span class="star">★</span></h4>
                    <p><strong><?= t('your_bet') ?>:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
                    <ul class="example-calc">
                        <li><span class="text-accent">✓</span> P1 <?= t('correct') ?>: <strong>+<?= $pointsP1 ?> <?= t('points_label') ?></strong></li>
                        <li><span class="text-accent">✓</span> P2 <?= t('correct') ?>: <strong>+<?= $pointsP2 ?> <?= t('points_label') ?></strong></li>
                        <li><span class="text-accent">✓</span> P3 <?= t('correct') ?>: <strong>+<?= $pointsP3 ?> <?= t('points_label') ?></strong></li>
                        <li class="total"><strong>Total: <?= $pointsP1 + $pointsP2 + $pointsP3 ?> <?= t('points_label') ?> + <span class="star">★</span> 1 <?= t('star') ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/includes/footer.php'; ?>
