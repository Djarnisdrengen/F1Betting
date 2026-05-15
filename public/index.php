<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$currentUser = getCurrentUser();
$settings = getSettings();
$lang = getLang();

// Hent data
$races = getRaces($db);
[$drivers, $driversById] = fetchDrivers($db, 'number');

$betsByRace = getBetsByRace($db);

// Hent leaderboard
$leaderboard = $db->query("
    SELECT u.*, COUNT(b.id) as bets_count 
    FROM users u 
    LEFT JOIN bets b ON u.id = b.user_id
    WHERE u.in_competition = 1
    GROUP BY u.id 
    ORDER BY  u.stars DESC, u.points DESC
    LIMIT 10
")->fetchAll();

// Hero tekst
$heroTitle = $lang === 'da' ? $settings['hero_title_da'] : $settings['hero_title_en'];
$heroText = $lang === 'da' ? $settings['hero_text_da'] : $settings['hero_text_en'];

// Find first upcoming race for scroll - exclude completed AND past races
$now = new DateTime();
$upcomingRaces = array_filter($races, function($r) use ($now) {
    //if ($r['result_p1']) return false; // Har resultat = afsluttet
    $raceDateTime = new DateTime($r['race_date'] . ' ' . $r['race_time']);

    $eightHoursAgo = (clone $now)->modify('-8 hours');
    return $raceDateTime > $eightHoursAgo; // Fremtidige løb + op til 8 timer gamle
});
$firstUpcomingRaceId = !empty($upcomingRaces) ? array_values($upcomingRaces)[0]['id'] : null;

include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero">
    <h1><?= escape($heroTitle) ?></h1>
    <p><?= escape($heroText) ?></p>
</section>

<div class="homepage-grid">
    <!-- Leaderboard Sidebar (shows first on mobile, collapsed by default) -->
    <div class="leaderboard-section"> 

        <!-- Mobile collapsible header -->
        <div class="leaderboard-collapse-header">
            <h2><i class="fas fa-trophy text-accent"></i> <?= t('leaderboard') ?></h2>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        
        <!-- Desktop header -->
        <h2 class="mb-2 desktop-header"><i class="fas fa-trophy text-accent"></i> <?= t('leaderboard') ?></h2>
        
        <div class="leaderboard-content" id="leaderboard-content">
            <div class="card">
                <?php if (empty($leaderboard)): ?>
                    <div class="card-body text-center text-muted"><?= t('no_bets') ?></div>
                <?php else: ?>
                    <?php foreach (array_slice($leaderboard, 0, 3) as $i => $entry): ?>
                        <div class="leaderboard-entry" style="padding: 1rem; border-bottom: 1px solid var(--border-color); background: linear-gradient(90deg, rgba(225, 6, 0, 0.1), transparent);">
                            <div class="flex items-center gap-2">
                                <span class="position-badge position-<?= $i + 1 ?>"><?= $i + 1 ?></span>
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

    <!-- Upcoming Races -->
    <div class="races-section">
        <h2 class="mb-2"><i class="fas fa-flag text-accent"></i> <?= t('upcoming_races') ?></h2>
        
        <?php if (empty($upcomingRaces)): ?>
            <div class="card">
                <div class="card-body text-center text-muted">
                    <?= t('no_upcoming_races') ?>
                </div>
            </div>
        <?php else: ?>
            <?php 
            $bettingWindowHours = $settings['betting_window_hours'] ?? 48;
            foreach ($upcomingRaces as $race): 
                $status = getBettingStatus($race, $settings);
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
                $bettingOpens->modify("-{$bettingWindowHours} hours");
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
                                        <?= t('betting_opens_in') ?>:
                                        <span class="countdown-value" id="countdown-<?= $race['id'] ?>">--</span>
                                    </div>
                                <?php elseif ($status['status'] === 'open'): ?>
                                    <div class="countdown-timer betting-open" data-closes="<?= $raceDateTime->format('c') ?>">
                                        <i class="fas fa-stopwatch"></i>
                                        <?= t('betting_closes_in') ?>:
                                        <span class="countdown-value" id="countdown-<?= $race['id'] ?>">--</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $status['class'] ?>"><?= $status['label'] ?></span>
                        </div>
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

                        <!-- Qualifying -->
                        <?php $_qd_data = $race; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); include __DIR__ . '/includes/qualifying-display.php'; ?>

                        <!-- Race result -->
                        <?php $_qd_data = $race; $_qd_keys = ['result_p1', 'result_p2', 'result_p3']; $_qd_label = t('result'); include __DIR__ . '/includes/qualifying-display.php'; ?>

                        <!-- Actions -->
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                            <div class="flex gap-1">
                                <?php if ($status['status'] === 'open' && $currentUser && !$userBet && $currentUser['in_competition']): ?>                                    
                                    <a href="bet.php?race=<?= $race['id'] ?>&return=index" class="btn btn-primary btn-sm"><?= t('place_bet') ?></a>
                                <?php elseif ($status['status'] === 'open' && $currentUser && $userBet && $currentUser['in_competition']): ?>
                                    <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> <?= t('edit') ?></a>
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
                                            <div class="bet-avatar"><?= escape(strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1))) ?></div>
                                            <div>
                                                <strong class="flex items-center gap-1">
                                                    <?= escape($bet['display_name'] ?: $bet['email']) ?>
                                                    <?php if ($isMyBet): ?><span class="badge" style="background: var(--f1-red); color: white; font-size: 0.7rem; padding: 2px 6px;"><?= t('you_badge') ?></span><?php endif; ?>
                                                    <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
                                                    <?php if ($bet['points'] > 0): ?>
                                                        <!-- <span class="badge" style="background: var(--f1-red); color: white;"><?= $bet['points'] ?> pts</span> -->
                                                         <span class="badge position-1"> <?= $bet['points'] ?> pts</span>
                                                    <?php endif; ?>

                                                </strong>
                                                <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <div class="bet-predictions">
                                                <?php foreach (['p1', 'p2', 'p3'] as $i => $key): 
                                                    $driver = $driversById[$bet[$key]] ?? null;
                                                    $isP3 = ($key === 'p3'); // Check if this is the third position                                                   
                                                ?>
                                                    <span class="bet-pred"><b>P<?= $i + 1 ?>:</b> <?= $driver ? explode(' ', $driver['name'])[count(explode(' ', $driver['name']))-1] : '?' ?></span>          
                                                <?php endforeach; ?>
                                            </div>
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
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
