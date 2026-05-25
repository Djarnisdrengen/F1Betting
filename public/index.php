<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$currentUser = getCurrentUser();
$settings = getSettings();
$lang = getLang();

$races = getRaces($db);
[$drivers, $driversById] = fetchDrivers($db, 'number');
$betsByRace = getBetsByRace($db);
$leaderboard = getLeaderboard($db);

$now = new DateTime();
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;

// Split races into upcoming (future + running) and completed
$upcomingRaces = array_values(array_filter($races, function($r) use ($now) {
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);
    return $raceDateTime > (clone $now)->modify('-8 hours');
}));
$completedRaces = array_values(array_filter($races, function($r) use ($now) {
    if (!$r['result_p1']) return false;
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);
    return $raceDateTime <= (clone $now)->modify('-8 hours');
}));

$racesCompleted  = count($completedRaces);
$racesRemaining  = count($upcomingRaces);
$recentResults   = array_slice(array_reverse($completedRaces), 0, 3);

// Hero: derive from next upcoming race
$heroRace = $upcomingRaces[0] ?? null;
$heroCountdownTarget = null;
$heroStatus = null;
$heroPool = null;
if ($heroRace) {
    $heroStatus = getBettingStatus($heroRace, $settings);
    $raceDateTime = new DateTime($heroRace['race_date'] . ' ' . $heroRace['race_time']);
    $bettingOpens = (clone $raceDateTime)->modify("-{$bettingWindowHours} hours");
    if ($heroStatus['status'] === 'pending') {
        $heroCountdownTarget = $bettingOpens->format('c');
    } else {
        $heroCountdownTarget = $raceDateTime->format('c');
    }
    $heroPool = $heroRace['bettingpool_size'] ?: null;
}

// My rank for home card
$myRank       = null;
$myPoints     = null;
$myBets       = null;
$myStars      = null;
$myDelta      = null;
$totalUsers   = count($leaderboard);
if ($currentUser) {
    foreach ($leaderboard as $i => $entry) {
        if ($entry['id'] === $currentUser['id']) {
            $myRank   = $i + 1;
            $myPoints = $entry['points'];
            $myBets   = $entry['bets_count'];
            $myStars  = $entry['stars'];
            $myDelta  = $entry['rank_delta'];
            break;
        }
    }
    if ($myRank === null) {
        $myPoints = $currentUser['points'] ?? 0;
        $myStars  = $currentUser['stars'] ?? 0;
        $stmt = $db->prepare("SELECT COUNT(*) FROM bets WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);
        $myBets = (int)$stmt->fetchColumn();
    }
}

// Maps getBettingStatus() class → hf-badge modifier
$badgeMap = [
    'status-open'      => 'open',
    'status-pending'   => 'soon',
    'status-closed'    => 'done',
    'status-completed' => 'done',
];

$cdLabels = [t('cd_days'), t('cd_hrs'), t('cd_min'), t('cd_sec')];

$heroTitle = $lang === 'da' ? $settings['hero_title_da'] : $settings['hero_title_en'];

include __DIR__ . '/includes/header.php';

$successMessages = [
    'bet_placed'  => t('bet_placed'),
    'bet_updated' => t('bet_updated'),
];
$errorMessages = [
    'already_bet'        => t('already_bet_long'),
    'not_in_competition' => t('not_in_competition'),
];
$flashSuccess = $successMessages[$_GET['success'] ?? ''] ?? null;
$flashError   = $errorMessages[$_GET['error']   ?? ''] ?? null;

// Inline countdown snippet (reused twice in the hero)
function renderHfCountdown(string $target, array $labels, string $extraClass = ''): string {
    $cells = '';
    foreach ($labels as $lbl) {
        $cells .= '<div class="hf-cd-cell"><div class="hf-cd-num">--</div><div class="hf-cd-label">' . htmlspecialchars($lbl) . '</div></div>';
    }
    $cls = 'hf-countdown' . ($extraClass ? ' ' . $extraClass : '');
    return '<div class="' . $cls . '" data-target="' . htmlspecialchars($target) . '">' . $cells . '</div>';
}
?>

<!-- Hero -->
<section class="hf-hero">
    <div class="hf-container">
        <div class="hf-hero-inner">
            <div class="hf-hero-left">
                <?php if ($heroRace): ?>
                    <div class="hf-hero-eyebrow"><?= escape($heroRace['name']) ?></div>
                <?php endif; ?>
                <h1 class="hf-hero-title"><?= escape($heroTitle) ?></h1>

                <?php if ($heroRace): ?>
                    <div class="hf-hero-meta">
                        <?php if ($heroPool): ?>
                            <span><?= t('pool_size') ?> <?= escape($heroPool) ?></span>
                            <span class="dot"></span>
                        <?php endif; ?>
                        <span class="hf-badge <?= $badgeMap[$heroStatus['class']] ?? 'done' ?>"><?= $heroStatus['label'] ?></span>
                    </div>

                    <?php if ($heroCountdownTarget): ?>
                        <?= renderHfCountdown($heroCountdownTarget, $cdLabels, 'hf-hero-cd-inline') ?>
                    <?php endif; ?>

                    <?php if ($heroStatus['status'] === 'open' && $currentUser && $currentUser['in_competition']): ?>
                        <?php
                        $userBet = null;
                        foreach ($betsByRace[$heroRace['id']] ?? [] as $b) {
                            if ($b['user_id'] === $currentUser['id']) { $userBet = $b; break; }
                        }
                        ?>
                        <?php if (!$userBet): ?>
                            <a href="bet.php?race=<?= $heroRace['id'] ?>&return=index" class="hf-cta-primary">
                                <?= t('place_bet') ?> <span class="arrow">→</span>
                            </a>
                        <?php else: ?>
                            <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="hf-cta-primary">
                                <?= t('edit') ?> <span class="arrow">→</span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($heroRace && $heroCountdownTarget): ?>
            <div class="hf-hero-right">
                <?= renderHfCountdown($heroCountdownTarget, $cdLabels) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <div class="hf-container"><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= escape($flashSuccess) ?></div></div>
<?php elseif ($flashError): ?>
    <div class="hf-container"><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($flashError) ?></div></div>
<?php endif; ?>

<?php if ($currentUser && $myRank !== null): ?>
<div class="hf-container">
    <div class="hf-self-card hf-self-card-home">
        <div class="hf-self-card-home-left">
            <div class="hf-self-label"><?= t('your_position') ?></div>
            <div class="hf-self-rank">
                <span class="hf-self-rank-n"><?= $myRank ?></span>
                <span class="hf-self-rank-of">/ <?= $totalUsers ?></span>
            </div>
            <?php if ($myDelta !== null):
                if ($myDelta > 0)        $deltaText = '↑ ' . $myDelta . ' ' . t('rank_delta_places');
                elseif ($myDelta < 0)    $deltaText = '↓ ' . abs($myDelta) . ' ' . t('rank_delta_places');
                else                     $deltaText = t('rank_no_change');
            ?>
            <div class="hf-self-delta"><?= escape($deltaText) ?></div>
            <?php endif; ?>
        </div>
        <div class="hf-self-stats hf-self-card-home-right">
            <div>
                <div class="hf-self-stat-label"><?= strtoupper(t('points_label')) ?></div>
                <div class="hf-self-stat-val"><?= $myPoints ?? 0 ?></div>
            </div>
            <div>
                <div class="hf-self-stat-label"><?= strtoupper(t('stars')) ?></div>
                <div class="hf-self-stat-val"><?= ($myStars ?? 0) > 0 ? '★' . $myStars : '—' ?></div>
            </div>
            <div>
                <div class="hf-self-stat-label"><?= strtoupper(t('bets')) ?></div>
                <div class="hf-self-stat-val"><?= $myBets ?? 0 ?></div>
            </div>
            <div>
                <div class="hf-self-stat-label"><?= strtoupper(t('rounds_played')) ?></div>
                <div class="hf-self-stat-val"><?= $racesCompleted ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main grid -->
<div class="hf-container">
    <div class="hf-home-grid">

        <!-- Leaderboard column -->
        <div>
            <div class="hf-section">
                <div class="hf-section-h">
                    <h2><?= t('leaderboard') ?></h2>
                    <a href="leaderboard.php"><?= t('see_all') ?></a>
                </div>

                <?php if (empty($leaderboard)): ?>
                    <p class="text-muted" style="padding: 12px 0;"><?= t('no_bets') ?></p>
                <?php else: ?>
                    <?php foreach (array_slice($leaderboard, 0, 5) as $i => $entry):
                        $isSelf  = $currentUser && $entry['id'] === $currentUser['id'];
                        $rankCls = $i < 3 ? 'hf-rank r'.($i+1) : 'hf-rank';
                    ?>
                        <div class="hf-row<?= $isSelf ? ' self' : '' ?>">
                            <div class="<?= $rankCls ?>"><?= $i + 1 ?></div>
                            <div class="hf-avatar"><?= userInitial($entry) ?></div>
                            <div class="hf-who">
                                <div class="hf-who-name"><?= displayUserName($entry) ?></div>
                                <div class="hf-who-sub"><?= $entry['bets_count'] ?> <?= t('bets') ?></div>
                            </div>
                            <div class="hf-stars"><?= $entry['stars'] > 0 ? '★'.$entry['stars'] : '' ?></div>
                            <div class="hf-pts"><?= $entry['points'] ?>p</div>
                            <?php if ($entry['last_bet_points'] !== null): ?>
                            <div class="hf-last-picks">
                                <span class="hf-last-bet-label"><?= t('last_bet_label') ?></span>
                                <span class="hf-last-bet-pts <?= $entry['last_bet_points'] > 0 ? 'has-pts' : 'zero-pts' ?>">+<?= (int)$entry['last_bet_points'] ?>p</span>
                            </div>
                            <?php endif; ?>
                            <div class="hf-rank-delta"><?php
                                $d = $entry['rank_delta'];
                                if ($d === null)   echo '<span class="nc">—</span>';
                                elseif ($d > 0)   echo '<span class="up">▲'.$d.'</span>';
                                elseif ($d < 0)   echo '<span class="dn">▼'.abs($d).'</span>';
                                else              echo '<span class="nc">—</span>';
                            ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Races + results column -->
        <div data-testid="home-results">
            <!-- Upcoming races -->
            <div class="hf-section">
                <div class="hf-section-h">
                    <h2><?= t('upcoming_races') ?></h2>
                    <a href="races.php"><?= t('see_all') ?></a>
                </div>

                <?php if (empty($upcomingRaces)): ?>
                    <p class="text-muted" style="padding: 12px 0;"><?= t('no_upcoming_races') ?></p>
                <?php else: ?>
                    <?php foreach (array_slice($upcomingRaces, 0, 3) as $race):
                        $status  = getBettingStatus($race, $settings);
                        $raceBets = $betsByRace[$race['id']] ?? [];
                        $userBet = null;
                        if ($currentUser) {
                            foreach ($raceBets as $b) {
                                if ($b['user_id'] === $currentUser['id']) { $userBet = $b; break; }
                            }
                        }
                    ?>
                        <div class="hf-racecard" id="race-<?= $race['id'] ?>">
                            <div>
                                <div class="hf-racename"><?= escape($race['name']) ?></div>
                                <div class="hf-racemeta"><?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?></div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                                <?php if ($status['status'] === 'open' && $currentUser && !$userBet && $currentUser['in_competition']): ?>
                                    <a href="bet.php?race=<?= $race['id'] ?>&return=index" class="hf-badge open"><?= t('place_bet') ?> →</a>
                                <?php elseif ($status['status'] === 'open' && $currentUser && $userBet && $currentUser['in_competition']): ?>
                                    <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="hf-badge open"><?= t('edit') ?> →</a>
                                <?php else: ?>
                                    <span class="hf-badge <?= $badgeMap[$status['class']] ?? 'done' ?>"><?= $status['label'] ?></span>
                                <?php endif; ?>
                                <?php if ($status['status'] === 'open' && count($raceBets) > 0): ?>
                                    <button class="btn btn-ghost btn-sm toggle-bets" data-target="race-bets-<?= $race['id'] ?>">
                                        <?= t('all_bets') ?> <i class="fas fa-chevron-down"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($status['status'] === 'open' && count($raceBets) > 0): ?>
                                <div id="race-bets-<?= $race['id'] ?>" class="bets-section hidden" style="grid-column:1/-1;margin-top:4px;padding-top:8px;">
                                    <?php foreach ($raceBets as $bet): ?>
                                        <?php include __DIR__ . '/includes/bet-item.php'; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Recent results -->
            <?php if (!empty($recentResults)): ?>
            <div class="hf-section">
                <div class="hf-section-h">
                    <h2><?= t('recent_results') ?></h2>
                    <a href="races.php?tab=completed"><?= t('see_all') ?></a>
                </div>
                <?php foreach ($recentResults as $race): ?>
                    <div class="hf-racecard">
                        <div>
                            <div class="hf-racename"><?= escape($race['name']) ?></div>
                            <div class="hf-racemeta">
                                <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                                <?php if ($race['result_p1']): ?>
                                    &nbsp;· P1: <?= escape($driversById[$race['result_p1']]['name'] ?? '—') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="hf-badge done"><?= t('status_done') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
