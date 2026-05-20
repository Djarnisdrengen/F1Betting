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
$leaderboard = getLeaderboard($db, 10);

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

// My rank for stats strip
$myRank = null;
$myPoints = null;
$myBets = null;
if ($currentUser) {
    foreach ($leaderboard as $i => $entry) {
        if ($entry['id'] === $currentUser['id']) {
            $myRank   = $i + 1;
            $myPoints = $entry['points'];
            $myBets   = $entry['bets_count'];
            break;
        }
    }
    // Fall back to user points if not in top-10
    if ($myRank === null) {
        $myPoints = $currentUser['points'] ?? 0;
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

// Countdown label arrays (DA/EN)
$cdLabels = $lang === 'da'
    ? ['DAG', 'TIM', 'MIN', 'SEK']
    : ['DAYS', 'HRS', 'MIN', 'SEC'];

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

<?php if ($currentUser): ?>
<div class="hf-container">
    <div class="hf-home-stats">
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $myRank !== null ? '#' . $myRank : '—' ?></div>
            <div class="hf-stat-l"><?= t('rank') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $myPoints ?? 0 ?></div>
            <div class="hf-stat-l"><?= t('points') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $myBets ?? 0 ?></div>
            <div class="hf-stat-l"><?= t('bets') ?></div>
        </div>
        <div class="hf-stat">
            <div class="hf-stat-n"><?= $racesCompleted ?></div>
            <div class="hf-stat-l"><?= t('rounds_played') ?></div>
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
                            <div class="hf-avatar"><?= escape(userInitial($entry)) ?></div>
                            <div class="hf-who">
                                <div class="hf-who-name"><?= escape(displayUserName($entry)) ?></div>
                                <div class="hf-who-sub"><?= $entry['bets_count'] ?> <?= t('bets') ?></div>
                            </div>
                            <div class="hf-stars"><?= $entry['stars'] > 0 ? '★'.$entry['stars'] : '' ?></div>
                            <div class="hf-pts"><?= $entry['points'] ?>p</div>
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
                            <div class="hf-racenum"><?= escape(mb_strtoupper(mb_substr($race['location'], 0, 3))) ?></div>
                            <div>
                                <div class="hf-racename"><?= escape($race['name']) ?></div>
                                <div class="hf-racemeta"><?= formatRaceDateTime($race['race_date'], $race['race_time']) ?></div>
                            </div>
                            <span class="hf-badge <?= $badgeMap[$status['class']] ?? 'done' ?>"><?= $status['label'] ?></span>
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
                        <div class="hf-racenum"><?= escape(mb_strtoupper(mb_substr($race['location'], 0, 3))) ?></div>
                        <div>
                            <div class="hf-racename"><?= escape($race['name']) ?></div>
                            <div class="hf-racemeta">
                                <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
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
