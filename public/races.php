<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

$db = getDB();
$lang = getLang();

// Hent data
$races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$driversById = [];
foreach ($drivers as $d) {
    $driversById[$d['id']] = $d;
}

// Hent alle bets
$bets = $db->query("
    SELECT b.*, u.display_name, u.email 
    FROM bets b 
    JOIN users u ON b.user_id = u.id 
    ORDER BY b.placed_at DESC
")->fetchAll();

$betsByRace = [];
foreach ($bets as $bet) {
    $betsByRace[$bet['race_id']][] = $bet;
}

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
        'already_bet' => $lang === 'da' ? 'Du har allerede placed et bet på dette løb.' : 'You have already placed a bet on this race.',
        'not_in_competition' => $lang === 'da' ? 'Du er ikke medlem af konkurrencen. Kontakt administrator.' : 'You are not a member of the competition. Contact administrator.',
    ];
    $errorMsg = $errorMessages[$_GET['error']] ?? 'An error occurred.';
    ?>
    <div class="alert" style="background: #fee; color: #c33; border: 1px solid #fcc; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <i class="fas fa-exclamation-circle"></i> <?= $errorMsg ?>
    </div>
<?php endif; ?>

<?php foreach ($races as $race): 
    $status = getBettingStatus($race);
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
    $raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
    $bettingOpens = clone $raceDateTime;
    $bettingOpens->modify('-48 hours');
?>
    <div class="card mb-2">
        <div class="race-card">
            <div class="race-header">
                <div>
                    <h3 class="race-title">
                        <?= escape($race['name']) ?>
                        <?php if ($hasBet): ?>
                            <span class="badge" style="background: #059669; color: white; margin-left: 0.5rem;">
                                <i class="fas fa-check"></i> <?= $lang === 'da' ? 'Bet placeret' : 'Bet placed' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($bettingpool_won): ?>
                            <span class="badge status-pool-won">
                                <i class="fas fa-check"></i> <?= $lang === 'da' ? 'Puljen vundet' : 'Bettingpool won' ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div class="race-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                        <span><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($race['race_date'])) ?> - <?= substr($race['race_time'], 0, 5) ?> CET</span>
                    </div>
                    <!-- Countdown Timer for upcoming races -->
                    <?php if (!$race['result_p1']): ?>
                        <?php if ($status['status'] === 'pending'): ?>
                            <div class="countdown-timer" data-opens="<?= $bettingOpens->format('c') ?>">
                                <i class="fas fa-hourglass-half"></i>
                                <?= $lang === 'da' ? 'Betting åbner om' : 'Betting opens in' ?>:
                                <span class="countdown-value">--</span>
                            </div>
                        <?php elseif ($status['status'] === 'open'): ?>
                            <div class="countdown-timer betting-open" data-closes="<?= $raceDateTime->format('c') ?>">
                                <i class="fas fa-stopwatch"></i>
                                <?= $lang === 'da' ? 'Betting lukker om' : 'Betting closes in' ?>:
                                <span class="countdown-value">--</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <!-- Betting Pool Size if there is a poolsize on the race-->
                    <?php if ($race['bettingpool_size']): ?>
                        <div class="countdown-timer bettingpool_size">
                            <i class="fas fa-dollar-sign bettingpool_size"></i>
                            <?= $lang === 'da' ? 'Puljestørrelse: ' : 'Pool size: ' ?>
                            <span class="bettingpool_size">
                                <?= $race['bettingpool_size'] ?>
                            </span>
                        </div> 
                    <?php endif; ?>
                </div>
                <span class="badge <?= $status['class'] ?>"><?= $status['label'] ?></span>
            </div>
            
            <!-- Qualifying -->
            <?php if ($race['quali_p1']): ?>
                <div style="margin-top: 1rem;">
                    <small class="text-muted"><?= t('qualifying') ?>:</small>
                    <div class="quali-row">
                        <?php foreach (['quali_p1', 'quali_p2', 'quali_p3'] as $i => $key): 
                            $driver = $driversById[$race[$key]] ?? null;
                            if ($driver):
                        ?>
                            <div class="quali-item">
                                <span class="position-badge position-<?= $i + 1 ?>">P<?= $i + 1 ?></span>
                                <?= escape($driver['name']) ?>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Results -->
            <?php if ($race['result_p1']): ?>
                <div style="margin-top: 1rem;">
                    <small class="text-muted"><?= t('results') ?>:</small>
                    <div class="quali-row">
                        <?php foreach (['result_p1', 'result_p2', 'result_p3'] as $i => $key): 
                            $driver = $driversById[$race[$key]] ?? null;
                            if ($driver):
                        ?>
                            <div class="quali-item">
                                <span class="position-badge position-<?= $i + 1 ?>">P<?= $i + 1 ?></span>
                                <?= escape($driver['name']) ?>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
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
                    <?php foreach ($raceBets as $bet): 
                        $isMyBet = $currentUser && $bet['user_id'] === $currentUser['id'];
                    ?>
                        <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?> <?= $isMyBet ? 'my-bet' : '' ?>">
                            <div class="bet-user">
                                <div class="bet-avatar"><?= escape(strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1))) ?></div>
                                <div>
                                    <strong class="flex items-center gap-1">
                                        <?= escape($bet['display_name'] ?: $bet['email']) ?>
                                        <?php if ($isMyBet): ?><span class="badge" style="background: var(--f1-red); color: white; font-size: 0.7rem; padding: 2px 6px;"><?= $lang === 'da' ? 'DIG' : 'YOU' ?></span><?php endif; ?>
                                        <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
                                    </strong>
                                    <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="bet-predictions">
                                    <?php foreach (['p1', 'p2', 'p3'] as $i => $key): 
                                        $driver = $driversById[$bet[$key]] ?? null;
                                    ?>
                                        <span class="bet-pred"><b>P<?= $i + 1 ?>:</b> <?= $driver ? explode(' ', $driver['name'])[count(explode(' ', $driver['name']))-1] : '?' ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($bet['points'] > 0): ?>
                                    <span class="badge" style="background: var(--f1-red); color: white;"><?= $bet['points'] ?> pts</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
