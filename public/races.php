<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$lang = getLang();
$settings = getSettings();

$races = getRaces($db);
[$drivers, $driversById] = fetchDrivers($db, 'number');
$betsByRace = getBetsByRace($db);
$currentUser = getCurrentUser();

$bettingWindowHours = $settings['betting_window_hours'] ?? 48;
$now = new DateTime();

$myBets = [];
if ($currentUser) {
    $stmt = $db->prepare("SELECT race_id FROM bets WHERE user_id = ?");
    $stmt->execute([$currentUser['id']]);
    $myBets = array_column($stmt->fetchAll(), 'race_id');
}

$upcomingRaces  = array_values(array_filter($races, function($r) use ($now) {
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);
    return $raceDateTime > (clone $now)->modify('-8 hours');
}));
$completedRaces = array_values(array_reverse(array_filter($races, function($r) use ($now) {
    if (!$r['result_p1']) return false;
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);
    return $raceDateTime <= (clone $now)->modify('-8 hours');
})));

$badgeMap = [
    'status-open'      => 'open',
    'status-pending'   => 'soon',
    'status-closed'    => 'done',
    'status-completed' => 'done',
];

include __DIR__ . '/includes/header.php';

$errorMessages = [
    'already_bet'        => t('already_bet_long'),
    'not_in_competition' => t('not_in_competition'),
];
$flashError = $errorMessages[$_GET['error'] ?? ''] ?? null;
?>

<div class="hf-container">
    <div class="hf-pageh">
        <h1><?= t('races') ?></h1>
    </div>

    <?php if ($flashError): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($flashError) ?></div>
    <?php endif; ?>

    <!-- Seg filter (mobile only; hidden at LG via CSS) -->
    <div class="hf-races-seg-wrap">
        <div class="hf-seg">
            <button class="active" id="seg-upcoming"><?= t('upcoming_races') ?></button>
            <button id="seg-completed"><?= t('results') ?></button>
        </div>
    </div>

    <!-- 2-col grid (stacked mobile, side-by-side LG+) -->
    <div class="hf-races-grid">

        <!-- Upcoming column -->
        <div class="hf-races-col" id="col-upcoming">
            <div class="hf-section-h">
                <h2><?= t('upcoming_races') ?></h2>
            </div>

            <?php if (empty($upcomingRaces)): ?>
                <p class="text-muted" style="padding: 12px 0;"><?= t('no_upcoming_races') ?></p>
            <?php else: ?>
                <?php foreach ($upcomingRaces as $race):
                    $status   = getBettingStatus($race, $settings);
                    $raceBets = $betsByRace[$race['id']] ?? [];
                    $hasBet   = in_array($race['id'], $myBets);
                    $userBet  = null;
                    if ($currentUser) {
                        foreach ($raceBets as $b) {
                            if ($b['user_id'] === $currentUser['id']) { $userBet = $b; break; }
                        }
                    }
                    $raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
                    $bettingOpens = (clone $raceDateTime)->modify("-{$bettingWindowHours} hours");
                ?>
                    <div class="hf-racefull">
                        <div class="hf-racefull-hd">
                            <div class="hf-racefull-info">
                                <div class="hf-racename">
                                    <?= escape($race['name']) ?>
                                    <?php if ($hasBet): ?>
                                        <span class="hf-badge open" style="margin-left: 6px;">
                                            <i class="fas fa-check"></i> <?= t('bet_placed_label') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($race['bettingpool_won']): ?>
                                        <span class="hf-badge open" style="margin-left: 6px;">★ <?= t('pool_won') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="hf-racemeta">
                                    <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                                    <?php if ($race['bettingpool_size']): ?>
                                        <span class="pool-break"><?= t('pool_size') ?> <span class="bettingpool_size"><?= escape($race['bettingpool_size']) ?></span></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($status['status'] === 'pending'): ?>
                                    <div class="countdown-timer" data-opens="<?= $bettingOpens->format('c') ?>">
                                        <i class="fas fa-hourglass-half"></i> <?= t('betting_opens_in') ?>:
                                        <span class="countdown-value">--</span>
                                    </div>
                                <?php elseif ($status['status'] === 'open'): ?>
                                    <div class="countdown-timer betting-open" data-closes="<?= $raceDateTime->format('c') ?>">
                                        <i class="fas fa-stopwatch"></i> <?= t('betting_closes_in') ?>:
                                        <span class="countdown-value">--</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="hf-badge <?= $badgeMap[$status['class']] ?? 'done' ?>"><?= $status['label'] ?></span>
                        </div>

                        <div class="hf-racefull-body">
                            <?php $_qd_data = $race; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); include __DIR__ . '/includes/qualifying-display.php'; ?>

                            <div class="flex items-center justify-between">
                                <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                                <div class="flex gap-1">
                                    <?php if ($status['status'] === 'open' && $currentUser && !$hasBet && $currentUser['in_competition']): ?>
                                        <a href="bet.php?race=<?= $race['id'] ?>" class="hf-badge open"><?= t('place_bet') ?> →</a>
                                    <?php elseif ($status['status'] === 'open' && $currentUser && $userBet && $currentUser['in_competition']): ?>
                                        <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="hf-badge open"><i class="fas fa-edit"></i> <?= t('edit') ?> →</a>
                                    <?php endif; ?>
                                    <?php if (count($raceBets) > 0): ?>
                                        <button class="btn btn-ghost btn-sm toggle-bets" data-target="race-bets-<?= $race['id'] ?>">
                                            <?= t('all_bets') ?> <i class="fas fa-chevron-down"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (count($raceBets) > 0): ?>
                                <div id="race-bets-<?= $race['id'] ?>" class="bets-section hidden">
                                    <h4 class="mb-1"><?= t('all_bets') ?> (<?= count($raceBets) ?>)</h4>
                                    <?php foreach ($raceBets as $bet): ?>
                                        <?php include __DIR__ . '/includes/bet-item.php'; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Completed column (hidden mobile by default) -->
        <div class="hf-races-col hidden" id="col-completed">
            <div class="hf-section-h">
                <h2><?= t('results') ?></h2>
            </div>

            <?php if (empty($completedRaces)): ?>
                <p class="text-muted" style="padding: 12px 0;"><?= t('no_bets') ?></p>
            <?php else: ?>
                <?php foreach ($completedRaces as $race):
                    $raceBets = $betsByRace[$race['id']] ?? [];
                    $hasBet   = in_array($race['id'], $myBets);
                ?>
                    <div class="hf-racefull">
                        <div class="hf-racefull-hd">
                            <div class="hf-racefull-info">
                                <div class="hf-racename">
                                    <?= escape($race['name']) ?>
                                    <?php if ($hasBet): ?>
                                        <span class="hf-badge done" style="margin-left: 6px;">
                                            <i class="fas fa-check"></i> <?= t('bet_placed_label') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($race['bettingpool_won']): ?>
                                        <span class="hf-badge open" style="margin-left: 6px;">★ <?= t('pool_won') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="hf-racemeta">
                                    <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                                    <?php if ($race['bettingpool_size']): ?>
                                        &nbsp;· <?= t('pool_size') ?> <span class="bettingpool_size"><?= escape($race['bettingpool_size']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="hf-badge done"><?= t('status_done') ?></span>
                        </div>

                        <div class="hf-racefull-body">
                            <?php $_qd_data = $race; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); include __DIR__ . '/includes/qualifying-display.php'; ?>
                            <?php $_qd_data = $race; $_qd_keys = ['result_p1', 'result_p2', 'result_p3']; $_qd_label = t('result'); include __DIR__ . '/includes/qualifying-display.php'; ?>

                            <?php if (count($raceBets) > 0): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                                    <button class="btn btn-ghost btn-sm toggle-bets" data-target="race-bets-<?= $race['id'] ?>">
                                        <?= t('all_bets') ?> <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="race-bets-<?= $race['id'] ?>" class="bets-section hidden">
                                    <h4 class="mb-1"><?= t('all_bets') ?> (<?= count($raceBets) ?>)</h4>
                                    <?php foreach ($raceBets as $bet): ?>
                                        <?php include __DIR__ . '/includes/bet-item.php'; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script nonce="<?= $nonce ?>">
(function() {
    var segBtns = { upcoming: document.getElementById('seg-upcoming'), completed: document.getElementById('seg-completed') };
    var cols    = { upcoming: document.getElementById('col-upcoming'),  completed: document.getElementById('col-completed') };
    function activate(key) {
        Object.keys(segBtns).forEach(function(k) {
            segBtns[k].classList.toggle('active', k === key);
            cols[k].classList.toggle('hidden', k !== key);
        });
    }
    segBtns.upcoming.addEventListener('click',  function() { activate('upcoming'); });
    segBtns.completed.addEventListener('click', function() { activate('completed'); });
    // Pre-select tab from query param (e.g. ?tab=completed from "Recent Results" link)
    var initialTab = '<?= in_array($_GET['tab'] ?? '', ['upcoming', 'completed']) ? $_GET['tab'] : 'upcoming' ?>';
    if (initialTab !== 'upcoming') activate(initialTab);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
