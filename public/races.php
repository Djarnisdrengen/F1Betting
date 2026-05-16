<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$lang = getLang();
$settings = getSettings();

// Hent data
$races = getRaces($db);
[$drivers, $driversById] = fetchDrivers($db, 'number');

$betsByRace = getBetsByRace($db);

$currentUser = getCurrentUser();
$myBets = [];
if ($currentUser) {
    $stmt = $db->prepare("SELECT race_id FROM bets WHERE user_id = ?");
    $stmt->execute([$currentUser['id']]);
    $myBets = array_column($stmt->fetchAll(), 'race_id');
}

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-flag text-accent"></i> <?= t('races') ?></h1>

<?php if (isset($_GET['error'])): ?>
    <?php 
    $errorMessages = [
        'already_bet' => t('already_bet_long'),
        'not_in_competition' => t('not_in_competition'),
    ];
    $errorMsg = $errorMessages[$_GET['error']] ?? 'An error occurred.';
    ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($errorMsg) ?></div>
<?php endif; ?>

<?php foreach ($races as $race): 
    $status = getBettingStatus($race, $settings);
    $bettingpool_won = $race['bettingpool_won'];
    $raceBets = $betsByRace[$race['id']] ?? [];
    $hasBet = in_array($race['id'], $myBets);
    $userBet = null;
    if ($currentUser) {
        foreach ($raceBets as $b) {
            if ($b['user_id'] === $currentUser['id']) {
                $userBet = $b;
                break;
            }
        }
    }
    
    // Beregn countdown
    $bettingWindowHours = $settings['betting_window_hours'] ?? 48;
    $raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
    $bettingOpens = clone $raceDateTime;
    $bettingOpens->modify("-{$bettingWindowHours} hours");
?>
    <div class="card mb-2">
        <div class="race-card">
            <div class="race-header">
                <div>
                    <h3 class="race-title">
                        <?= escape($race['name']) ?>
                        <?php if ($hasBet): ?>
                            <span class="badge" style="background: #059669; color: white; margin-left: 0.5rem;">
                                <i class="fas fa-check"></i> <?= t('bet_placed_label') ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($bettingpool_won): ?>
                            <span class="badge status-pool-won">
                                <i class="fas fa-check"></i> <?= t('pool_won') ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div class="race-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                        <span><i class="fas fa-clock"></i> <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?></span>
                    </div>
                    <!-- Countdown Timer for upcoming races -->
                    <?php if (!$race['result_p1']): ?>
                        <?php if ($status['status'] === 'pending'): ?>
                            <div class="countdown-timer" data-opens="<?= $bettingOpens->format('c') ?>">
                                <i class="fas fa-hourglass-half"></i>
                                <?= t('betting_opens_in') ?>:
                                <span class="countdown-value">--</span>
                            </div>
                        <?php elseif ($status['status'] === 'open'): ?>
                            <div class="countdown-timer betting-open" data-closes="<?= $raceDateTime->format('c') ?>">
                                <i class="fas fa-stopwatch"></i>
                                <?= t('betting_closes_in') ?>:
                                <span class="countdown-value">--</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <!-- Betting Pool Size if there is a poolsize on the race-->
                    <?php if ($race['bettingpool_size']): ?>
                        <div class="countdown-timer bettingpool_size">
                            <i class="fas fa-dollar-sign bettingpool_size"></i>
                            <?= t('pool_size') ?>
                            <span class="bettingpool_size">
                                <?= $race['bettingpool_size'] ?>
                            </span>
                        </div> 
                    <?php endif; ?>
                </div>
                <span class="badge <?= $status['class'] ?>"><?= $status['label'] ?></span>
            </div>
            
            <!-- Qualifying -->
            <?php $_qd_data = $race; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); $_qd_style = 'margin-top: 1rem;'; include __DIR__ . '/includes/qualifying-display.php'; ?>

            <!-- Result -->
            <?php $_qd_data = $race; $_qd_keys = ['result_p1', 'result_p2', 'result_p3']; $_qd_label = t('result'); $_qd_style = 'margin-top: 1rem;'; include __DIR__ . '/includes/qualifying-display.php'; ?>
            
            <!-- Actions and Bets -->
            <div class="flex items-center justify-between mt-2">
                <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                <div class="flex gap-1">
                    <?php if ($status['status'] === 'open' && $currentUser && !$hasBet): ?>
                        <a href="bet.php?race=<?= $race['id'] ?>" class="btn btn-primary btn-sm"><?= t('place_bet') ?></a>
                    <?php elseif ($status['status'] === 'open' && $currentUser && $userBet): ?>
                        <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> <?= t('edit') ?></a>
                    <?php endif; ?>
                    <?php if (count($raceBets) > 0): ?>
                        <button class="btn btn-ghost btn-sm toggle-bets" data-target="race-bets-<?= $race['id'] ?>">
                            <?= t('all_bets') ?> <i class="fas fa-chevron-down"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- All Bets -->
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

<?php include __DIR__ . '/includes/footer.php'; ?>
