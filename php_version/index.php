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

include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero">
    <h1><?= escape($heroTitle) ?></h1>
    <p><?= escape($heroText) ?></p>
    <?php if (!$currentUser): ?>
        <div class="flex items-center justify-center gap-2">
            <a href="register.php" class="btn btn-primary"><?= t('register') ?></a>
            <a href="login.php" class="btn btn-secondary"><?= t('login') ?></a>
        </div>
    <?php endif; ?>
</section>

<div class="grid" style="grid-template-columns: 2fr 1fr; gap: 2rem;">
    <!-- Upcoming Races -->
    <div>
        <h2 class="mb-2"><i class="fas fa-flag text-accent"></i> <?= t('upcoming_races') ?></h2>
        
        <?php 
        $upcomingRaces = array_filter($races, fn($r) => !$r['result_p1']);
        if (empty($upcomingRaces)): 
        ?>
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
            ?>
                <div class="card mb-2">
                    <div class="race-card">
                        <div class="race-header">
                            <div>
                                <h3 class="race-title"><?= escape($race['name']) ?></h3>
                                <div class="race-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
                                    <span><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($race['race_date'])) ?> - <?= substr($race['race_time'], 0, 5) ?></span>
                                </div>
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
                        
                        <!-- User's Bet -->
                        <?php if ($userBet): ?>
                            <div class="<?= $userBet['is_perfect'] ? 'perfect-bet' : '' ?>" style="background: var(--bg-hover); padding: 0.75rem; border-radius: 8px; margin-top: 1rem; border: 1px solid var(--border-color);">
                                <div class="flex items-center justify-between">
                                    <small class="text-muted flex items-center gap-1">
                                        <?= t('your_bets') ?>
                                        <?php if ($userBet['is_perfect']): ?>
                                            <span class="star">★</span>
                                        <?php endif; ?>
                                    </small>
                                    <?php if ($status['status'] === 'open'): ?>
                                        <a href="edit_bet.php?id=<?= $userBet['id'] ?>" class="btn btn-ghost btn-sm" title="<?= t('edit') ?>">
                                            <i class="fas fa-edit"></i> <?= t('edit') ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
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
                        
                        <!-- Actions -->
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-muted"><i class="fas fa-users"></i> <?= count($raceBets) ?> bets</span>
                            <div class="flex gap-1">
                                <?php if ($status['status'] === 'open' && $currentUser && !$userBet): ?>
                                    <a href="bet.php?race=<?= $race['id'] ?>" class="btn btn-primary btn-sm"><?= t('place_bet') ?></a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
