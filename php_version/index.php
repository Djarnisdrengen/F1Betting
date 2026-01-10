<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$currentUser = getCurrentUser();
$settings = getSettings();
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

// Gruppér bets efter race
$betsByRace = [];
foreach ($bets as $bet) {
    $betsByRace[$bet['race_id']][] = $bet;
}

// Hent leaderboard
$leaderboard = $db->query("
    SELECT u.*, COUNT(b.id) as bets_count 
    FROM users u 
    LEFT JOIN bets b ON u.id = b.user_id 
    GROUP BY u.id 
    ORDER BY u.points DESC, u.stars DESC 
    LIMIT 10
")->fetchAll();

// Hero tekst
$heroTitle = $lang === 'da' ? $settings['hero_title_da'] : $settings['hero_title_en'];
$heroText = $lang === 'da' ? $settings['hero_text_da'] : $settings['hero_text_en'];

// Find first upcoming race for scroll - exclude completed AND past races
$now = new DateTime();
$upcomingRaces = array_filter($races, function($r) use ($now) {
    if ($r['result_p1']) return false; // Har resultat = afsluttet
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);
    return $raceDateTime > $now; // Kun fremtidige løb
});
$firstUpcomingRaceId = !empty($upcomingRaces) ? array_values($upcomingRaces)[0]['id'] : null;

include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero">
    <h1><?= escape($heroTitle) ?></h1>
    <p><?= escape($heroText) ?></p>
    <?php if (!$currentUser): ?>
        <div class="flex items-center justify-center gap-2">
            <a href="login.php" class="btn btn-primary"><?= t('login') ?></a>
        </div>
    <?php endif; ?>
</section>

<div class="grid" style="grid-template-columns: 2fr 1fr; gap: 2rem;">
    <!-- Upcoming Races -->
    <div>
        <h2 class="mb-2"><i class="fas fa-flag text-accent"></i> <?= t('upcoming_races') ?></h2>
        
        <?php if (empty($upcomingRaces)): ?>
            <div class="card">
                <div class="card-body text-center text-muted">
                    <?= $lang === 'da' ? 'Ingen kommende løb' : 'No upcoming races' ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($upcomingRaces as $race): 
                $status = getBettingStatus($race);
                $raceBets = $betsByRace[$race['id']] ?? [];
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
                $now = new DateTime();
            ?>
                <div class="card mb-2" id="race-<?= $race['id'] ?>">
                    <div class="race-card">
                        <div class="race-header">
                            <div>
                                <h3 class="race-title"><?= escape($race['name']) ?></h3>
                                <div class="race-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                                    <span><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($race['race_date'])) ?> - <?= substr($race['race_time'], 0, 5) ?> CET</span>
                                </div>
                                <!-- Countdown Timer -->
                                <?php if ($status['status'] === 'pending'): ?>
                                    <div class="countdown-timer" data-opens="<?= $bettingOpens->format('c') ?>">
                                        <i class="fas fa-hourglass-half"></i>
                                        <?= $lang === 'da' ? 'Betting åbner om' : 'Betting opens in' ?>:
                                        <span class="countdown-value" id="countdown-<?= $race['id'] ?>">--</span>
                                    </div>
                                <?php elseif ($status['status'] === 'open'): ?>
                                    <div class="countdown-timer betting-open" data-closes="<?= $raceDateTime->format('c') ?>">
                                        <i class="fas fa-stopwatch"></i>
                                        <?= $lang === 'da' ? 'Betting lukker om' : 'Betting closes in' ?>:
                                        <span class="countdown-value" id="countdown-<?= $race['id'] ?>">--</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $status['class'] ?>"><?= $status['label'] ?></span>
                        </div>
                        
                        <!-- Qualifying -->
                        <?php if ($race['quali_p1']): ?>
                            <div style="background: var(--bg-secondary); padding: 0.75rem; border-radius: 8px; margin-top: 1rem;">
                                <small class="text-muted"><?= t('qualifying') ?></small>
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
                        
                        <!-- Actions -->
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                            <div class="flex gap-1">
                                <?php if ($status['status'] === 'open' && $currentUser && !$userBet): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openBetModal('<?= $race['id'] ?>', '<?= escape($race['name']) ?>', '<?= escape($race['location']) ?>', '<?= $race['race_date'] ?>', '<?= $race['race_time'] ?>', false)"><?= t('place_bet') ?></button>
                                <?php elseif ($status['status'] === 'open' && $currentUser && $userBet): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openBetModal('<?= $race['id'] ?>', '<?= escape($race['name']) ?>', '<?= escape($race['location']) ?>', '<?= $race['race_date'] ?>', '<?= $race['race_time'] ?>', true, '<?= $userBet['id'] ?>', '<?= $userBet['p1'] ?>', '<?= $userBet['p2'] ?>', '<?= $userBet['p3'] ?>')"><i class="fas fa-edit"></i> <?= t('edit') ?></button>
                                <?php endif; ?>
                                <?php if (count($raceBets) > 0): ?>
                                    <button class="btn btn-ghost btn-sm toggle-bets" data-target="bets-<?= $race['id'] ?>">
                                        <?= t('all_bets') ?> <i class="fas fa-chevron-down"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- All Bets (hidden by default) -->
                        <?php if (count($raceBets) > 0): ?>
                            <div id="bets-<?= $race['id'] ?>" class="bets-section hidden">
                                <h4 class="mb-1"><?= t('all_bets') ?> (<?= count($raceBets) ?>)</h4>
                                <?php foreach ($raceBets as $bet): 
                                    $isMyBet = $currentUser && $bet['user_id'] === $currentUser['id'];
                                ?>
                                    <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?> <?= $isMyBet ? 'my-bet' : '' ?>">
                                        <div class="bet-user">
                                            <div class="bet-avatar"><?= strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1)) ?></div>
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
        <?php endif; ?>
    </div>
    
    <!-- Leaderboard Sidebar -->
    <div>
        <h2 class="mb-2"><i class="fas fa-trophy text-accent"></i> <?= t('leaderboard') ?></h2>
        <div class="card">
            <?php if (empty($leaderboard)): ?>
                <div class="card-body text-center text-muted"><?= t('no_bets') ?></div>
            <?php else: ?>
                <?php foreach ($leaderboard as $i => $entry): ?>
                    <div class="flex items-center justify-between" style="padding: 1rem; border-bottom: 1px solid var(--border-color); <?= $i < 3 ? 'background: linear-gradient(90deg, rgba(225, 6, 0, 0.1), transparent);' : '' ?>">
                        <div class="flex items-center gap-2">
                            <span class="position-badge <?= $i < 3 ? 'position-' . ($i + 1) : '' ?>" <?= $i >= 3 ? 'style="background: var(--bg-secondary);"' : '' ?>><?= $i + 1 ?></span>
                            <div>
                                <strong><?= escape($entry['display_name'] ?: $entry['email']) ?></strong>
                                <br><small class="text-muted"><?= $entry['bets_count'] ?> bets</small>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-accent" style="font-weight: bold;"><?= $entry['points'] ?> pts</span>
                            <?php if ($entry['stars'] > 0): ?>
                                <br><span class="star">★<?= $entry['stars'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a href="leaderboard.php" class="btn btn-secondary mt-2" style="width: 100%;"><?= t('leaderboard') ?></a>
    </div>
</div>

<?php if ($firstUpcomingRaceId): ?>
<script>
// Scroll to first upcoming race on page load
document.addEventListener('DOMContentLoaded', function() {
    const raceEl = document.getElementById('race-<?= $firstUpcomingRaceId ?>');
    if (raceEl) {
        setTimeout(() => {
            raceEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
    }
});
</script>
<?php endif; ?>

<?php if ($currentUser): ?>
<!-- Betting Modal -->
<div id="bet-modal-overlay" class="bet-modal-overlay" onclick="if(event.target === this) closeBetModal()">
    <div class="bet-modal">
        <div class="bet-modal-header">
            <div>
                <h3 id="bet-modal-title"><?= t('place_bet') ?></h3>
                <p class="text-muted" style="margin: 0.25rem 0 0 0;" id="bet-modal-race-info"></p>
            </div>
            <button type="button" class="bet-modal-close" onclick="closeBetModal()">&times;</button>
        </div>
        <form id="bet-modal-form" method="POST" action="api/bet.php">
            <input type="hidden" name="race_id" id="bet-race-id">
            <input type="hidden" name="bet_id" id="bet-bet-id">
            <input type="hidden" name="action" id="bet-action" value="create">
            <div class="bet-modal-body">
                <div id="bet-modal-error" class="alert alert-error" style="display: none;"></div>
                
                <?php 
                $positions = [
                    ['key' => 'p1', 'label' => 'P1 (25 pts)', 'position' => 1],
                    ['key' => 'p2', 'label' => 'P2 (18 pts)', 'position' => 2],
                    ['key' => 'p3', 'label' => 'P3 (15 pts)', 'position' => 3],
                ];
                foreach ($positions as $pos): 
                ?>
                    <div class="form-group">
                        <label class="form-label flex items-center gap-1">
                            <span class="position-badge position-<?= $pos['position'] ?>">P<?= $pos['position'] ?></span>
                            <?= $pos['label'] ?>
                        </label>
                        <select name="<?= $pos['key'] ?>" id="bet-<?= $pos['key'] ?>" class="form-select" required>
                            <option value=""><?= t('select_driver') ?></option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>">
                                    #<?= $driver['number'] ?> <?= escape($driver['name']) ?> - <?= escape($driver['team']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bet-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBetModal()"><?= t('cancel') ?></button>
                <button type="submit" class="btn btn-primary" id="bet-submit-btn"><?= t('place_bet') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openBetModal(raceId, raceName, location, date, time, isEdit, betId, p1, p2, p3) {
    const modal = document.getElementById('bet-modal-overlay');
    const title = document.getElementById('bet-modal-title');
    const raceInfo = document.getElementById('bet-modal-race-info');
    const submitBtn = document.getElementById('bet-submit-btn');
    const actionInput = document.getElementById('bet-action');
    
    document.getElementById('bet-race-id').value = raceId;
    document.getElementById('bet-bet-id').value = betId || '';
    document.getElementById('bet-modal-error').style.display = 'none';
    
    const dateObj = new Date(date + 'T' + time);
    const formattedDate = dateObj.toLocaleDateString('<?= $lang ?>', { day: 'numeric', month: 'short', year: 'numeric' });
    
    raceInfo.innerHTML = '<i class="fas fa-flag-checkered"></i> ' + raceName + ' · ' + location + '<br><i class="fas fa-clock"></i> ' + formattedDate + ' - ' + time.substring(0,5) + ' CET';
    
    if (isEdit) {
        title.textContent = '<?= $lang === 'da' ? 'Rediger Bet' : 'Edit Bet' ?>';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> <?= t('save') ?>';
        actionInput.value = 'update';
        document.getElementById('bet-p1').value = p1 || '';
        document.getElementById('bet-p2').value = p2 || '';
        document.getElementById('bet-p3').value = p3 || '';
    } else {
        title.textContent = '<?= t('place_bet') ?>';
        submitBtn.innerHTML = '<?= t('place_bet') ?>';
        actionInput.value = 'create';
        document.getElementById('bet-p1').value = '';
        document.getElementById('bet-p2').value = '';
        document.getElementById('bet-p3').value = '';
    }
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeBetModal() {
    const modal = document.getElementById('bet-modal-overlay');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Handle form submission via AJAX
document.getElementById('bet-modal-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const errorEl = document.getElementById('bet-modal-error');
    const submitBtn = document.getElementById('bet-submit-btn');
    
    // Validate
    const p1 = formData.get('p1');
    const p2 = formData.get('p2');
    const p3 = formData.get('p3');
    
    if (!p1 || !p2 || !p3) {
        errorEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <?= $lang === 'da' ? 'Vælg alle 3 positioner' : 'Select all 3 positions' ?>';
        errorEl.style.display = 'block';
        return;
    }
    
    if (p1 === p2 || p1 === p3 || p2 === p3) {
        errorEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <?= $lang === 'da' ? 'Kan ikke vælge samme kører flere gange' : 'Cannot select same driver multiple times' ?>';
        errorEl.style.display = 'block';
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    fetch('api/bet.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            errorEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + data.error;
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = formData.get('action') === 'update' ? '<i class="fas fa-save"></i> <?= t('save') ?>' : '<?= t('place_bet') ?>';
        }
    })
    .catch(error => {
        errorEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <?= $lang === 'da' ? 'Der opstod en fejl' : 'An error occurred' ?>';
        errorEl.style.display = 'block';
        submitBtn.disabled = false;
    });
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBetModal();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
