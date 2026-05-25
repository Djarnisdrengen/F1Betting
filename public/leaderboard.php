<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db          = getDB();
$currentUser = getCurrentUser();

$leaderboard = getLeaderboard($db);
$totalUsers  = count($leaderboard);

// Pre-compute current user's entry so we can use it in two places
$selfEntry = null;
$selfRank  = null;
foreach ($leaderboard as $i => $entry) {
    if ($currentUser && $entry['id'] === $currentUser['id']) {
        $selfEntry = $entry;
        $selfRank  = $i + 1;
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

    <?php if (count($leaderboard) >= 3): ?>
    <!-- Top-3 podium cards (MD+) -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;" class="hf-podium-strip">
        <?php
        $podiumBg = [
            'linear-gradient(135deg, rgba(251,191,36,0.18), var(--bg-card) 60%)',
            'linear-gradient(135deg, rgba(156,163,175,0.14), var(--bg-card) 60%)',
            'linear-gradient(135deg, rgba(205,124,47,0.14), var(--bg-card) 60%)',
        ];
        $podiumBorder = [
            'rgba(251,191,36,0.4)',
            'rgba(156,163,175,0.3)',
            'rgba(205,124,47,0.3)',
        ];
        foreach ([0, 1, 2] as $i):
            $entry = $leaderboard[$i];
        ?>
        <div class="hf-stat" style="align-items:center;text-align:center;background:<?= $podiumBg[$i] ?>;border-color:<?= $podiumBorder[$i] ?>;">
            <div class="hf-rank r<?= $i+1 ?>" style="width:40px;height:40px;margin-bottom:8px;font-size:16px;"><?= $i+1 ?></div>
            <div class="hf-stat-n" style="font-size:36px;"><?= $entry['points'] ?>p</div>
            <div style="font-family:var(--font-display);font-weight:700;font-size:14px;color:var(--text-primary);margin-top:6px;"><?= displayUserName($entry) ?></div>
            <?php if ($entry['stars'] > 0): ?>
            <div style="color:var(--gold);font-family:var(--font-display);font-size:12px;font-weight:700;margin-top:2px;">★<?= $entry['stars'] ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- DIN POSITION horizontal card -->
    <?php if ($selfEntry):
        $d = $selfEntry['rank_delta'];
        if ($d !== null) {
            if ($d > 0)       $deltaText = '↑ ' . $d . ' ' . t('rank_delta_places');
            elseif ($d < 0)   $deltaText = '↓ ' . abs($d) . ' ' . t('rank_delta_places');
            else               $deltaText = t('rank_no_change');
        }
    ?>
    <div class="hf-self-card hf-self-card-home">
        <div class="hf-self-card-home-left">
            <div class="hf-self-label"><?= t('your_position') ?></div>
            <div class="hf-self-rank">
                <span class="hf-self-rank-n"><?= $selfRank ?></span>
                <span class="hf-self-rank-of">/ <?= $totalUsers ?></span>
            </div>
            <?php if ($d !== null): ?>
            <div class="hf-self-delta"><?= escape($deltaText) ?></div>
            <?php endif; ?>
        </div>
        <div class="hf-self-stats hf-self-card-home-right">
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
        </div>
    </div>
    <?php endif; ?>

    <!-- Body: standings list -->
    <div class="hf-lb-body">

        <!-- Full standings list -->
        <div class="hf-lb-list">
            <?php if (empty($leaderboard)): ?>
                <p style="text-align:center;color:var(--text-muted);padding:3rem 0;"><?= t('no_bets') ?></p>
            <?php else: ?>
                <?php foreach ($leaderboard as $i => $entry):
                    $isSelf  = $currentUser && $entry['id'] === $currentUser['id'];
                    $rankCls = $i < 3 ? 'hf-rank r'.($i+1) : 'hf-rank';
                ?>
                <div class="hf-row<?= $isSelf ? ' self' : '' ?>">
                    <div class="<?= $rankCls ?>"><?= $i+1 ?></div>
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
                        $d = $entry['rank_delta'];
                        if ($d === null)    echo '<span class="nc">—</span>';
                        elseif ($d > 0)    echo '<span class="up">▲'.$d.'</span>';
                        elseif ($d < 0)    echo '<span class="dn">▼'.abs($d).'</span>';
                        else               echo '<span class="nc">—</span>';
                    ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- .hf-lb-body -->
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
