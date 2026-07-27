<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';
require_once __DIR__ . '/includes/home-recap.php';

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

// Recap hero (home hero fallback: no race-hero window AND Challenges disabled) — rotates
// the last N completed races' podiums, most-recent-first. N = home_recap_count (admin
// setting, 3-10, default 5); naturally shrinks to whatever exists if fewer races are
// completed, and is empty (→ no recap hero, current blank fallback) if none are.
$homeRecapCount = max(3, min(10, intval($settings['home_recap_count'] ?? 5)));
$recapRaces = array_slice(array_reverse($completedRaces), 0, $homeRecapCount);

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
// Set true inside the recap-hero branch below — used to suppress the "Upcoming races" card
// (which otherwise follows every hero variant and links to the next race) specifically when
// the recap hero is what's showing.
$isRecapHero = false;

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

// Plain-text summary of a race's podium, for the recap carousel's aria-live announcer.
function buildRecapAnnouncement(array $race, ?array $p1, ?array $p2, ?array $p3, ?string $highlightText = null): string {
    $parts = [];
    if ($p1) $parts[] = 'P1 ' . $p1['name'];
    if ($p2) $parts[] = 'P2 ' . $p2['name'];
    if ($p3) $parts[] = 'P3 ' . $p3['name'];
    $summary = $race['name'] . ($parts ? ': ' . implode(', ', $parts) : '');
    return $highlightText ? $summary . '. ' . $highlightText : $summary;
}

// Localizes a getRecapHighlight() result (public/includes/home-recap.php) into display text —
// just the sentence itself, no label prefix (the caller renders "Indsigt"/"Insight" as its own
// styled line in the pull-quote markup, not baked into this string). KB text is translated to
// Danish via translateKbHighlight() when $lang is 'da'; getRecapHighlight() already guarantees a
// translation exists for any 'kb' result it returns when called with the same $lang (untranslated
// candidates are skipped there, never surfaced here) — the fallback to the English source is
// just defense in depth, not an expected path. Betting-upset text is a fully localized t()
// template already, no further translation needed.
function buildRecapHighlightText(?array $highlight, array $driversById, string $lang): ?string {
    if (!$highlight) return null;
    if ($highlight['type'] === 'kb') {
        return $lang === 'da' ? (translateKbHighlight($highlight['text']) ?? $highlight['text']) : $highlight['text'];
    }
    $driver = $driversById[$highlight['driver_id']] ?? null;
    if (!$driver) return null;
    $name = driverLastName($driver);
    if ($highlight['type'] === 'surprise') {
        return sprintf(t('home_recap_upset_surprise'), $highlight['count'], $highlight['total'], $name, $highlight['position']);
    }
    if ($highlight['type'] === 'snub') {
        return sprintf(t('home_recap_upset_snub'), $highlight['count'], $highlight['total'], $name);
    }
    return null;
}
?>

<?php if ($showRaceHero): ?>
<!-- Race hero -->
<section class="hf-hero clickable-card" data-testid="hero-race" data-href="race.php?id=<?= escape($heroRace['id']) ?>">
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

<?php if ($settings['challenges_enabled'] ?? 1): ?>
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
<?php endif; ?>

<?php else: ?>
<?php if ($settings['challenges_enabled'] ?? 1): ?>
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
<?php elseif (!empty($recapRaces)): ?>
<!-- Recap hero (fallback: no race hero, Challenges disabled) — rotates the last N
     completed races' podiums; see $recapRaces / $homeRecapCount above. -->
<?php $isRecapHero = true; $kbKnowledgeBase = loadPaddockKb(); ?>
<section class="hf-hero" data-testid="recap-hero">
    <div class="hf-container">
        <div class="hf-recap-carousel" data-testid="recap-carousel" role="region"
             aria-roledescription="carousel" aria-label="<?= escape(t('home_recap_aria_label')) ?>">

            <?php foreach ($recapRaces as $i => $race):
                $p1 = $race['result_p1'] ? ($driversById[$race['result_p1']] ?? null) : null;
                $p2 = $race['result_p2'] ? ($driversById[$race['result_p2']] ?? null) : null;
                $p3 = $race['result_p3'] ? ($driversById[$race['result_p3']] ?? null) : null;
                $highlight = getRecapHighlight($race, $betsByRace[$race['id']] ?? [], $driversById, $kbKnowledgeBase, $lang);
                $highlightText = buildRecapHighlightText($highlight, $driversById, $lang);
            ?>
            <div class="hf-recap-slide<?= $i === 0 ? '' : ' hidden' ?> clickable-card"
                 data-testid="recap-slide" data-index="<?= $i ?>"
                 data-href="race.php?id=<?= escape($race['id']) ?>"
                 data-announce="<?= escape(buildRecapAnnouncement($race, $p1, $p2, $p3, $highlightText)) ?>">
                <span class="hf-hero-eyebrow"><?= t('recent_results') ?></span>
                <h1 class="hf-hero-title">
                    <?php if ($p1): ?>
                        <?= sprintf(t('home_recap_won_line'), escape($p1['name']), escape($race['location'])) ?>
                    <?php else: ?>
                        <?= escape($race['name']) ?>
                    <?php endif; ?>
                </h1>
                <?php if ($highlightText): ?>
                <div class="hf-recap-insight" data-testid="recap-highlight">
                    <div class="hf-recap-insight-label" data-testid="recap-highlight-label"><?= t(recapHighlightLabelKey($highlight)) ?></div>
                    <div class="hf-recap-insight-text"><?= escape($highlightText) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($p2 || $p3): ?>
                <div class="quali-row hf-recap-podium">
                    <?php foreach ([2 => $p2, 3 => $p3] as $pos => $driver): if ($driver): ?>
                        <div class="quali-item">
                            <span class="position-badge position-<?= $pos ?>">P<?= $pos ?></span>
                            <?= escape(driverLastName($driver)) ?>
                            <span class="text-muted" style="font-size:0.8em;">&middot; <?= escape($driver['team']) ?></span>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if (count($recapRaces) > 1): ?>
            <button type="button" class="hf-recap-arrow hf-recap-arrow-prev" data-testid="recap-prev"
                    aria-label="<?= escape(t('home_recap_prev')) ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="hf-recap-arrow hf-recap-arrow-next" data-testid="recap-next"
                    aria-label="<?= escape(t('home_recap_next')) ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            <div class="hf-recap-dots" data-testid="recap-dots">
                <?php foreach ($recapRaces as $i => $race): ?>
                    <button type="button" class="hf-recap-dot<?= $i === 0 ? ' active' : '' ?>"
                            data-testid="recap-dot" data-index="<?= $i ?>"
                            <?= $i === 0 ? 'aria-current="true"' : '' ?>
                            aria-label="<?= escape(sprintf(t('home_recap_goto'), $i + 1, count($recapRaces))) ?>"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="sr-only" aria-live="polite" id="recap-live" data-testid="recap-live"></div>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="hf-container">
    <?php if (!$isRecapHero && !empty($upcomingRaces)): ?>
        <?php $nextRace = $upcomingRaces[0]; $nextRaceStatus = getBettingStatus($nextRace, $settings); ?>
        <div class="hf-section-h" style="margin-top:20px;"><h2><?= t('upcoming_races') ?></h2><a href="races.php"><?= t('see_all') ?></a></div>
        <div class="hf-racecard clickable-card" data-testid="next-race-card" data-href="race.php?id=<?= escape($nextRace['id']) ?>">
            <div>
                <div class="hf-racename"><?= escape($nextRace['name']) ?></div>
                <div class="hf-racemeta"><?= escape($nextRace['location']) ?> &middot; <?= formatRaceDateTime($nextRace['race_date'], $nextRace['race_time']) ?></div>
            </div>
            <span class="hf-badge <?= $badgeMap[$nextRaceStatus['class']] ?? 'done' ?>"><?= $nextRaceStatus['label'] ?></span>
        </div>
    <?php endif; ?>

    <?php if (($settings['challenges_enabled'] ?? 1) && !empty($cpTop3)): ?>
        <div class="hf-section-h" style="margin-top:22px;"><h2><?= t('ch_challenge_points') ?></h2><a href="challenges.php?section=board"><?= t('ch_full_board') ?></a></div>
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

<script nonce="<?= $nonce ?>">
(function () {
    var root = document.querySelector('.hf-recap-carousel');
    if (!root) return;

    var slides  = Array.prototype.slice.call(root.querySelectorAll('.hf-recap-slide'));
    var dots    = Array.prototype.slice.call(root.querySelectorAll('.hf-recap-dot'));
    var prevBtn = root.querySelector('.hf-recap-arrow-prev');
    var nextBtn = root.querySelector('.hf-recap-arrow-next');
    var live    = document.getElementById('recap-live');
    if (slides.length < 2) return; // single race: nothing to rotate or control

    var AUTO_MS = 6000;
    var current = 0;
    var timer = null;
    var stopped = false; // manual nav permanently stops autoplay (WCAG 2.2.2)
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(i) {
        current = (i + slides.length) % slides.length;
        slides.forEach(function (s, idx) { s.classList.toggle('hidden', idx !== current); });
        dots.forEach(function (d, idx) {
            d.classList.toggle('active', idx === current);
            if (idx === current) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current');
        });
        if (live) live.textContent = slides[current].dataset.announce || '';
    }

    function startAuto() {
        if (reduceMotion || stopped || timer) return;
        timer = setInterval(function () { show(current + 1); }, AUTO_MS);
    }
    function pauseAuto() { if (timer) { clearInterval(timer); timer = null; } }
    function stopAuto()  { stopped = true; pauseAuto(); }

    if (nextBtn) nextBtn.addEventListener('click', function () { show(current + 1); stopAuto(); });
    if (prevBtn) prevBtn.addEventListener('click', function () { show(current - 1); stopAuto(); });
    dots.forEach(function (d, idx) {
        d.addEventListener('click', function () { show(idx); stopAuto(); });
    });

    // Hover/focus = temporary "give me a moment", not a stop signal — resumes on leave.
    root.addEventListener('pointerenter', pauseAuto);
    root.addEventListener('pointerleave', startAuto);
    root.addEventListener('focusin', pauseAuto);
    root.addEventListener('focusout', startAuto);

    startAuto();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
