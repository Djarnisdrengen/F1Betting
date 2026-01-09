<?php
require_once __DIR__ . '/config.php';

$db = getDB();

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

<?php foreach ($races as $race): 
    $status = getBettingStatus($race);
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
?>
    <div class="card mb-2">
        <div class="race-card">
            <div class="race-header">
                <div>
                    <h3 class="race-title">
                        <?= escape($race['name']) ?>
                        <?php if ($hasBet): ?>
                            <span class="badge" style="background: #059669; color: white; margin-left: 0.5rem;">
                                <i class="fas fa-check"></i> <?= getLang() === 'da' ? 'Bet placeret' : 'Bet placed' ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div class="race-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                        <span><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($race['race_date'])) ?> - <?= substr($race['race_time'], 0, 5) ?></span>
                    </div>
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
            
            <!-- User's own bet -->
            <?php if ($userBet): ?>
                <div class="<?= $userBet['is_perfect'] ? 'perfect-bet' : '' ?>" style="background: var(--bg-hover); padding: 0.75rem; border-radius: 8px; margin-top: 1rem; border: 1px solid var(--border-color);">
                    <small class="text-muted flex items-center gap-1">
                        <?= t('your_bets') ?>
                        <?php if ($userBet['is_perfect']): ?>
                            <span class="star">★</span>
                        <?php endif; ?>
                    </small>
                    <div class="quali-row">
                        <?php foreach (['p1', 'p2', 'p3'] as $i => $key): 
                            $driver = $driversById[$userBet[$key]] ?? null;
                            if ($driver):
                        ?>
                            <div class="quali-item">
                                <span class="position-badge position-<?= $i + 1 ?>">P<?= $i + 1 ?></span>
                                <?= escape($driver['name']) ?>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                    <?php if ($userBet['points'] > 0): ?>
                        <p class="text-accent mt-1" style="font-weight: bold;"><?= $userBet['points'] ?> <?= t('points') ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Actions and Bets -->
            <div class="flex items-center justify-between mt-2">
                <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                <div class="flex gap-1">
                    <?php if ($status['status'] === 'open' && $currentUser && !$hasBet): ?>
                        <a href="bet.php?race=<?= $race['id'] ?>" class="btn btn-primary btn-sm"><?= t('place_bet') ?></a>
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
                        <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?>">
                            <div class="bet-user">
                                <div class="bet-avatar"><?= strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1)) ?></div>
                                <div>
                                    <strong class="flex items-center gap-1">
                                        <?= escape($bet['display_name'] ?: $bet['email']) ?>
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
