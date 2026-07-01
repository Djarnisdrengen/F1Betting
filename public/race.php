<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$raceId = $_GET['id'] ?? '';
if (!$raceId) {
    header('Location: races.php');
    exit;
}

$db       = getDB();
$settings = getSettings();

$stmt = $db->prepare("SELECT * FROM races WHERE id = ?");
$stmt->execute([$raceId]);
$race = $stmt->fetch();

if (!$race) {
    header('Location: races.php');
    exit;
}

$currentUser = getCurrentUser();
[$drivers, $driversById] = fetchDrivers($db, 'number');

$betStmt = $db->prepare("
    SELECT b.*, u.display_name, u.email
    FROM bets b JOIN users u ON b.user_id = u.id
    WHERE b.race_id = ?
    ORDER BY b.placed_at DESC
");
$betStmt->execute([$race['id']]);
$raceBets = $betStmt->fetchAll();

// Round number (1-indexed) computed from sorted race list
$allRaces    = getRaces($db);
$totalRounds = count($allRaces);
$round       = 0;
foreach ($allRaces as $i => $r) {
    if ($r['id'] === $race['id']) { $round = $i + 1; break; }
}

$bettingWindowHours = $settings['betting_window_hours'] ?? 48;
$raceDT       = new DateTime($race['race_date'] . ' ' . $race['race_time']);
$bettingOpens = (clone $raceDT)->modify("-{$bettingWindowHours} hours");
$qualiDT      = (!empty($race['quali_date']) && !empty($race['quali_time']))
    ? new DateTime($race['quali_date'] . ' ' . $race['quali_time'])
    : null;
$now = new DateTime();

$status = getBettingStatus($race, $settings);

if ($race['result_p1']) {
    usort($raceBets, fn($a, $b) => (int)$b['points'] <=> (int)$a['points']);
}

$myBet = null;
if ($currentUser) {
    foreach ($raceBets as $b) {
        if ($b['user_id'] === $currentUser['id']) { $myBet = $b; break; }
    }
}

$badgeMap = [
    'status-open'      => 'open',
    'status-pending'   => 'soon',
    'status-closed'    => 'done',
    'status-completed' => 'done',
];

$loginRedirect = urlencode('race.php?id=' . $race['id']);

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">

    <!-- Back link -->
    <div style="margin-bottom:0.75rem;">
        <a href="races.php" style="color:var(--text-secondary);font-size:0.875rem;text-decoration:none;">
            ← <?= t('all_races') ?>
        </a>
    </div>

    <!-- Identity + schedule card -->
    <div class="hf-racefull" style="margin-bottom:1.25rem;">
        <div class="hf-racefull-hd">
            <div class="hf-racefull-info" style="flex:1;">
                <div class="hf-racemeta" style="margin-bottom:0.25rem;font-size:0.8rem;">
                    <?= t('round') ?> <?= $round ?> <?= t('of') ?> <?= $totalRounds ?>
                </div>
                <div class="hf-racename race-title" style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <?= escape($race['name']) ?>
                    <?php if ($race['bettingpool_won']): ?>
                        <span class="hf-badge open">★ <?= t('pool_won') ?></span>
                    <?php endif; ?>
                </div>
                <div class="hf-racemeta" style="margin-top:0.25rem;">
                    <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                    · <span><i class="fas fa-flag-checkered"></i> <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?></span>
                    <?php if ($qualiDT): ?>
                        · <span><i class="fas fa-stopwatch"></i> <?= date('d M', strtotime($race['quali_date'])) ?> <?= substr($race['quali_time'], 0, 5) ?> CET</span>
                    <?php endif; ?>
                </div>
            </div>
            <span class="hf-badge <?= $badgeMap[$status['class']] ?? 'done' ?>"><?= $status['label'] ?></span>
        </div>

        <!-- Schedule box: timings + actions -->
        <div class="race-schedule">

            <!-- Qualifying start countdown (or "done" once qualifying has run) -->
            <?php if ($qualiDT): ?>
                <?php $qualiDone = (bool)$race['quali_p1']; ?>
                <div class="countdown-timer<?= $qualiDone ? ' done' : '' ?>"
                    <?= !$qualiDone ? 'data-target="' . $qualiDT->format('c') . '"' : '' ?>>
                    <i class="fas fa-stopwatch"></i>
                    <?= t('quali_starts') ?>:
                    <span class="countdown-value"><?= $qualiDone ? t('status_done') : '--' ?></span>
                </div>
            <?php endif; ?>

            <!-- Race start countdown (or "done" once the race has run) -->
            <?php $raceDone = (bool)$race['result_p1']; ?>
            <div class="countdown-timer<?= $raceDone ? ' done' : '' ?>"
                <?= !$raceDone ? 'data-target="' . $raceDT->format('c') . '"' : '' ?>>
                <i class="fas fa-flag-checkered"></i>
                <?= t('race_starts') ?>:
                <span class="countdown-value"><?= $raceDone ? t('status_done') : '--' ?></span>
            </div>

            <!-- Betting window line (pending / open only) -->
            <?php if ($status['status'] === 'pending'): ?>
                <div class="countdown-timer" data-target="<?= $bettingOpens->format('c') ?>">
                    <i class="fas fa-hourglass-half"></i>
                    <?= t('betting_opens_in') ?>:
                    <span class="countdown-value">--</span>
                </div>
            <?php elseif ($status['status'] === 'open'): ?>
                <div class="countdown-timer betting-open" data-target="<?= $raceDT->format('c') ?>">
                    <i class="fas fa-lock-open"></i>
                    <?= t('betting_closes_in') ?>:
                    <span class="countdown-value">--</span>
                </div>
            <?php endif; ?>

            <!-- Pool size (modest gold, aligned into the schedule grid) -->
            <?php if ($race['bettingpool_size']): ?>
                <div class="countdown-timer">
                    <i class="fas fa-dollar-sign bettingpool_size"></i>
                    <?= t('pool_size') ?>
                    <span class="countdown-value bettingpool_size"><?= escape($race['bettingpool_size']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($status['status'] === 'open' && !$currentUser): ?>
                <a href="login.php?redirect=<?= $loginRedirect ?>" class="race-login-mini">
                    <i class="fas fa-sign-in-alt"></i> <?= t('login_to_bet') ?>
                </a>
            <?php elseif ($status['status'] === 'open' && $currentUser && $currentUser['in_competition']): ?>
                <div>
                    <?php if (!$myBet): ?>
                        <a href="bet.php?race=<?= escape($race['id']) ?>" class="hf-badge open">
                            <?= t('place_bet') ?> →
                        </a>
                    <?php else: ?>
                        <a href="edit_bet.php?id=<?= escape($myBet['id']) ?>" class="hf-badge open">
                            <i class="fas fa-edit"></i> <?= t('edit') ?> →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- Qualifying + race results (two-column at >=1024px, stacked below) -->
        <div class="race-results-two" style="margin-top:1rem;">

            <!-- Qualifying result or pending placeholder -->
            <div>
                <?php if ($race['quali_p1']): ?>
                    <?php
                    $_qd_data  = $race;
                    $_qd_keys  = ['quali_p1', 'quali_p2', 'quali_p3'];
                    $_qd_label = t('qualifying');
                    $_qd_style = 'background: var(--bg-secondary); padding: 0.75rem 0.875rem; border-radius: 8px;';
                    include __DIR__ . '/includes/qualifying-display.php';
                    ?>
                <?php else: ?>
                    <div class="result-pending">
                        <i class="fas fa-lock" style="flex-shrink:0;"></i>
                        <span><?= t('qualifying') ?>: <?= t('result_after_quali') ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Race result or pending placeholder -->
            <div>
                <?php if ($race['result_p1']): ?>
                    <?php
                    $_qd_data  = $race;
                    $_qd_keys  = ['result_p1', 'result_p2', 'result_p3'];
                    $_qd_label = t('result');
                    $_qd_style = 'background: var(--bg-secondary); padding: 0.75rem 0.875rem; border-radius: 8px;';
                    include __DIR__ . '/includes/qualifying-display.php';
                    ?>
                <?php else: ?>
                    <div class="result-pending">
                        <i class="fas fa-lock" style="flex-shrink:0;"></i>
                        <span><?= t('result') ?>: <?= t('result_after_race') ?></span>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

    <!-- Login CTA banner — shown when betting is open and user is not logged in -->
    <?php if ($status['status'] === 'open' && !$currentUser): ?>
        <div class="race-login-cta">
            <div>
                <strong><?= t('betting_open') ?></strong>
                <div style="font-size:0.875rem;color:var(--text-secondary);margin-top:0.25rem;">
                    <?= t('login_to_bet_hint') ?>
                </div>
            </div>
            <a href="login.php?redirect=<?= $loginRedirect ?>" class="hf-badge open">
                <i class="fas fa-sign-in-alt"></i> <?= t('login_to_bet') ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- Bets section — always visible -->
    <div class="bets-section race-bets">
        <div class="hf-section-h" style="margin-bottom:0.75rem;">
            <h2><?= t('all_bets') ?> (<?= count($raceBets) ?>)</h2>
            <?php if ($race['result_p1']): ?>
                <small class="text-muted"><?= t('sorted_by_points') ?></small>
            <?php endif; ?>
        </div>

        <?php if (empty($raceBets)): ?>
            <div class="bets-empty"><?= t('no_bets') ?></div>
        <?php else: ?>
            <?php foreach ($raceBets as $bet): ?>
                <?php $_bi_full = true; $_bi_scored = (bool) $race['result_p1']; ?>
                <?php include __DIR__ . '/includes/bet-item.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
