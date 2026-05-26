<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db          = getDB();
$currentUser = getCurrentUser();

$leaderboard = getLeaderboard($db);
$totalUsers  = count($leaderboard);

$races          = getRaces($db);
$racesCompleted = count(array_filter($races, fn($r) => !empty($r['result_p1'])));

// Pre-compute current user's entry
$selfEntry = null;
$selfRank  = null;
$deltaText = null;
foreach ($leaderboard as $i => $entry) {
    if ($currentUser && $entry['id'] === $currentUser['id']) {
        $selfEntry = $entry;
        $selfRank  = $i + 1;
        $d = $entry['rank_delta'];
        if ($d !== null) {
            if ($d > 0)      $deltaText = '↑ ' . $d . ' ' . t('rank_delta_places');
            elseif ($d < 0)  $deltaText = '↓ ' . abs($d) . ' ' . t('rank_delta_places');
            else             $deltaText = t('rank_no_change');
        }
        break;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <header class="hf-pageh">
        <div class="crumb"><?= t('leaderboard') ?> &middot; <?= t('season') ?> <?= escape($settings['app_year']) ?></div>
        <h1><?= t('leaderboard') ?></h1>
    </header>

    <!-- Podium — full width above the list -->
    <?php if (count($leaderboard) >= 3):
        $medals      = ['🏆', '🥈', '🥉'];
        $podiumOrder = [1, 0, 2]; // DOM: [2nd, 1st, 3rd] — CSS order property controls visual position
    ?>
    <div class="hf-podium-strip">
        <?php foreach ($podiumOrder as $i):
            $entry     = $leaderboard[$i];
            $pos       = $i + 1;
            $fullName  = escape(displayUserName($entry));
            $firstName = escape(explode(' ', trim(displayUserName($entry)))[0]);
        ?>
        <div class="hf-podium-block p<?= $pos ?>">
            <div class="hf-podium-icon"><?= $medals[$i] ?></div>
            <div class="hf-rank r<?= $pos ?>" style="width:30px;height:30px;font-size:12px;margin-bottom:5px;"><?= $pos ?></div>
            <div class="hf-podium-pts"><?= (int)$entry['points'] ?>p</div>
            <div class="hf-podium-name">
                <span class="first-name"><?= $firstName ?></span>
                <span class="full-name"><?= $fullName ?></span>
            </div>
            <?php if ($entry['stars'] > 0): ?>
            <div style="color:var(--gold);font-size:10px;font-weight:700;margin-top:2px;">★<?= (int)$entry['stars'] ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Body: list left, self card right at LG+; stacked on XS–MD -->
    <div class="hf-lb-body">

        <!-- Full standings list -->
        <div class="hf-lb-list">
            <?php if (empty($leaderboard)): ?>
                <p style="text-align:center;color:var(--text-muted);padding:3rem 0;"><?= t('no_bets') ?></p>
            <?php else: ?>
                <?php foreach ($leaderboard as $i => $entry):
                    $isSelf = $currentUser && $entry['id'] === $currentUser['id'];
                ?>
                <div class="hf-row<?= $isSelf ? ' self' : '' ?>">
                    <div class="hf-rank"><?= $i+1 ?></div>
                    <div class="hf-avatar"><?= userInitial($entry) ?></div>
                    <div class="hf-who">
                        <div class="hf-who-name"><?= displayUserName($entry) ?></div>
                        <div class="hf-who-sub"><?= $entry['bets_count'] ?> <?= t('bets') ?></div>
                    </div>
                    <div class="hf-stars"><?= $entry['stars'] > 0 ? '<span class="star">★'.$entry['stars'].'</span>' : '' ?></div>
                    <div class="hf-pts"><?= $entry['points'] ?>p</div>
                    <?php if ($entry['last_bet_points'] !== null): ?>
                    <div class="hf-last-picks">
                        <span class="hf-last-bet-label"><?= t('last_bet_label') ?></span>
                        <span class="hf-last-bet-pts <?= $entry['last_bet_points'] > 0 ? 'has-pts' : 'zero-pts' ?>">+<?= (int)$entry['last_bet_points'] ?>p</span>
                    </div>
                    <?php endif; ?>
                    <div class="hf-rank-delta"><?php
                        $rd = $entry['rank_delta'];
                        if ($rd === null)   echo '<span class="nc">—</span>';
                        elseif ($rd > 0)   echo '<span class="up">▲'.$rd.'</span>';
                        elseif ($rd < 0)   echo '<span class="dn">▼'.abs($rd).'</span>';
                        else               echo '<span class="nc">—</span>';
                    ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Your Position — right sidebar at LG+, below list on XS–MD -->
        <?php if ($selfEntry): ?>
        <div class="hf-self-card">
            <div class="hf-self-label"><?= t('your_position') ?></div>
            <div class="hf-self-rank">
                <span class="hf-self-rank-n"><?= $selfRank ?></span>
                <span class="hf-self-rank-of">/ <?= $totalUsers ?></span>
            </div>
            <?php if ($deltaText !== null): ?>
            <div class="hf-self-delta"><?= escape($deltaText) ?></div>
            <?php endif; ?>
            <div class="hf-self-stats" style="grid-template-columns:repeat(2,1fr);">
                <div>
                    <div class="hf-self-stat-label"><?= strtoupper(t('points_label')) ?></div>
                    <div class="hf-self-stat-val"><?= $selfEntry['points'] ?></div>
                </div>
                <div>
                    <div class="hf-self-stat-label"><?= strtoupper(t('stars')) ?></div>
                    <div class="hf-self-stat-val"><?= $selfEntry['stars'] > 0 ? '★' . $selfEntry['stars'] : '—' ?></div>
                </div>
                <div>
                    <div class="hf-self-stat-label"><?= strtoupper(t('bets')) ?></div>
                    <div class="hf-self-stat-val"><?= $selfEntry['bets_count'] ?></div>
                </div>
                <div>
                    <div class="hf-self-stat-label"><?= strtoupper(t('rounds_played')) ?></div>
                    <div class="hf-self-stat-val"><?= $racesCompleted ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- .hf-lb-body -->
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
