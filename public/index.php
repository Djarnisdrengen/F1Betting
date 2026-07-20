<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

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

// Context-aware hero (REQ-006, D9): the race hero shows only inside its window
// (windowOpen-24h through raceStart+3h); the Challenges hero shows the rest of the time,
// and always when there's no upcoming race at all.
$showRaceHero = $heroRace ? isRaceHeroWindow($heroRace, $settings, $now) : false;

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

// Challenges-hero stats (REQ-006/109) — only computed outside the race-hero window.
// $challengeParticipant/$challengeCP come from header.php, resolved only for an active
// (verified/core-linked) identity — same rule as the CP chip (REQ-005).
$cpTop3    = [];
$myCpRank  = null;
$myStreak  = 0;
if (!$showRaceHero) {
    $cpLeaderboardFull = getCpLeaderboard($db);
    $cpTop3 = array_slice($cpLeaderboardFull, 0, 3);
    if ($challengeParticipant) {
        foreach ($cpLeaderboardFull as $i => $row) {
            if ($row['participant_id'] === $challengeParticipant['id']) { $myCpRank = $i + 1; break; }
        }
        $myStreak = getChallengeStreak($db, $challengeParticipant['id']);
    }
}

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

<?php if ($showRaceHero): ?>
<!-- Race hero -->
<section class="hf-hero" data-testid="hero-race">
    <div class="hf-container">
        <div class="hf-hero-inner">
            <div class="hf-hero-left">
                <?php if ($heroRace): ?>
                    <div class="hf-hero-eyebrow"><a href="race.php?id=<?= escape($heroRace['id']) ?>" style="color:inherit;text-decoration:none;"><?= escape($heroRace['name']) ?></a></div>
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

<!-- Challenges slim strip (REQ-007) -->
<div class="hf-container">
    <a href="challenges.php" class="clickable-card" data-testid="challenges-strip" style="margin-top:18px;border-radius:12px;padding:12px 14px;background:var(--bg-card);border:1px solid var(--border-color);display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(36,114,232,.14);color:var(--f1-accent-challenges-light);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
            <i class="fas fa-gamepad"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-family:var(--display);font-weight:800;font-size:14px;"><?= t('ch_nav_challenges') ?></div>
            <div class="text-muted" style="font-size:11.5px;">
                <?php if ($challengeParticipant): ?><?= $challengeCP ?> CP &middot; <?php endif; ?><?= t('ch_games_live') ?>
            </div>
        </div>
        <i class="fas fa-chevron-right text-muted" style="font-size:12px;"></i>
    </a>
</div>

<?php else: ?>
<!-- Challenges hero (REQ-006, between races) -->
<section class="hf-hero" data-testid="challenges-hero" style="background:radial-gradient(circle at 84% -15%, rgba(36,114,232,.4), transparent 55%), radial-gradient(circle at 5% 125%, rgba(251,191,36,.14), transparent 50%), var(--bg-secondary);">
    <div class="hf-container">
        <div class="hf-hero-inner">
            <div class="hf-hero-left">
                <span class="hf-hero-eyebrow"><?= t('ch_hero_eyebrow') ?></span>
                <h1 class="hf-hero-title">Paddock<br>Challenges</h1>
                <div style="font-size:14px;margin-top:12px;max-width:30ch;color:var(--text-secondary);"><?= t('ch_hero_sub') ?></div>

                <?php if ($challengeParticipant): ?>
                <div data-testid="challenges-hero-stats" style="display:flex;gap:20px;margin-top:18px;">
                    <div>
                        <div data-testid="hero-stat-cp" style="font-family:var(--display);font-weight:900;font-size:26px;color:var(--gold);line-height:1;"><?= $challengeCP ?></div>
                        <div class="hf-stat-l"><?= t('ch_your_cp') ?></div>
                    </div>
                    <div>
                        <div data-testid="hero-stat-rank" style="font-family:var(--display);font-weight:900;font-size:26px;line-height:1;"><?= $myCpRank ? 'P' . $myCpRank : '—' ?></div>
                        <div class="hf-stat-l"><?= t('ch_rank') ?></div>
                    </div>
                    <div>
                        <div data-testid="hero-stat-streak" style="font-family:var(--display);font-weight:900;font-size:26px;color:var(--f1-red-light);line-height:1;"><i class="fa-solid fa-fire" style="font-size:20px;"></i> <?= $myStreak ?></div>
                        <div class="hf-stat-l"><?= t('ch_streak') ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <a href="challenges.php" class="hf-cta-primary hf-cta-primary--challenges" style="margin-top:20px;"><?= t('ch_play_now') ?> <span class="arrow">→</span></a>
            </div>
        </div>
    </div>
</section>

<div class="hf-container">
    <?php if (!empty($upcomingRaces)): ?>
        <?php $nextRace = $upcomingRaces[0]; $nextRaceStatus = getBettingStatus($nextRace, $settings); ?>
        <div class="hf-section-h" style="margin-top:20px;"><h2><?= t('upcoming_races') ?></h2><a href="races.php"><?= t('see_all') ?></a></div>
        <div class="hf-racecard clickable-card" data-href="race.php?id=<?= escape($nextRace['id']) ?>">
            <div>
                <div class="hf-racename"><?= escape($nextRace['name']) ?></div>
                <div class="hf-racemeta"><?= escape($nextRace['location']) ?> &middot; <?= formatRaceDateTime($nextRace['race_date'], $nextRace['race_time']) ?></div>
            </div>
            <span class="hf-badge <?= $badgeMap[$nextRaceStatus['class']] ?? 'done' ?>"><?= $nextRaceStatus['label'] ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($cpTop3)): ?>
        <div class="hf-section-h" style="margin-top:22px;"><h2><?= t('ch_challenge_points') ?></h2><a href="challenges-board.php"><?= t('ch_full_board') ?></a></div>
        <?php foreach ($cpTop3 as $i => $row): ?>
            <?php $isSelfCp = $challengeParticipant && $row['participant_id'] === $challengeParticipant['id']; ?>
            <div class="hf-row<?= $isSelfCp ? ' self' : '' ?>" style="grid-template-columns:32px 32px 1fr auto;">
                <div class="hf-rank r<?= $i + 1 ?>"><?= $i + 1 ?></div>
                <div class="hf-avatar"><?= $row['display_name'] ? escape(strtoupper(substr($row['display_name'], 0, 1))) : 'G' ?></div>
                <div class="hf-who"><div class="hf-who-name"><?= $row['display_name'] ? escape($row['display_name']) : 'Guest ' . substr($row['id'], -4) ?></div></div>
                <div class="hf-pts"><?= intval($row['total_cp']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

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
            <?php if (!empty($recentResults)): ?>
            <div class="hf-home-seg-wrap">
                <div class="hf-seg">
                    <button class="active" id="home-seg-upcoming"><?= t('upcoming_races') ?></button>
                    <button id="home-seg-results"><?= t('recent_results') ?></button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming races -->
            <div class="hf-section hf-home-sec" id="home-col-upcoming">
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
                        <div class="hf-racecard clickable-card" id="race-<?= $race['id'] ?>" data-href="race.php?id=<?= escape($race['id']) ?>">
                            <div>
                                <div class="hf-racename"><a href="race.php?id=<?= escape($race['id']) ?>" style="color:inherit;text-decoration:none;"><?= escape($race['name']) ?></a></div>
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
            <div class="hf-section hf-home-sec hidden" id="home-col-results">
                <div class="hf-section-h">
                    <h2><?= t('recent_results') ?></h2>
                    <a href="races.php?tab=completed"><?= t('see_all') ?></a>
                </div>
                <?php foreach ($recentResults as $race): ?>
                    <div class="hf-racecard clickable-card" data-href="race.php?id=<?= escape($race['id']) ?>">
                        <div>
                            <div class="hf-racename"><a href="race.php?id=<?= escape($race['id']) ?>" style="color:inherit;text-decoration:none;"><?= escape($race['name']) ?></a></div>
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

<script nonce="<?= $nonce ?>">
(function () {
    var seg  = { upcoming: document.getElementById('home-seg-upcoming'),  results: document.getElementById('home-seg-results') };
    var cols = { upcoming: document.getElementById('home-col-upcoming'),   results: document.getElementById('home-col-results') };
    if (!seg.upcoming || !cols.results) return;
    function show(tab) {
        ['upcoming', 'results'].forEach(function (k) {
            seg[k].classList.toggle('active', k === tab);
            cols[k].classList.toggle('hidden', k !== tab);
        });
    }
    seg.upcoming.addEventListener('click', function () { show('upcoming'); });
    seg.results.addEventListener('click',  function () { show('results'); });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
