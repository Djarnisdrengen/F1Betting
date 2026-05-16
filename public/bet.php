<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$settings = getSettings();

$raceId    = sanitizeString($_GET['race'] ?? '');
$returnTo  = ($_GET['return'] ?? '') === 'index' ? 'index.php' : 'races.php';

if (!$raceId) {
    header("Location: " . $returnTo);
    exit;
}

// Hent race
$stmt = $db->prepare("SELECT * FROM races WHERE id = ?");
$stmt->execute([$raceId]);
$race = $stmt->fetch();

if (!$race) {
    header("Location: " . $returnTo);
    exit;
}

// Tjek betting status
$status = getBettingStatus($race, $settings);
if ($status['status'] !== 'open') {
    header("Location: " . $returnTo);
    exit;
}

// Tjek om bruger er med i konkurrence
if (!$currentUser['in_competition']) {
    header("Location: " . $returnTo . "?error=not_in_competition");
    exit;
}

// Tjek om bruger allerede har bet
$stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
$stmt->execute([$currentUser['id'], $raceId]);
if ($stmt->fetch()) {
    header("Location: " . $returnTo . "?error=already_bet");
    exit;
}

// Hent kørere
[$drivers, $driversById] = fetchDrivers($db);

// Hent eksisterende bets for dette løb
$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ?");
$stmt->execute([$raceId]);
$existingBets = $stmt->fetchAll();

$error = '';
$lang = getLang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $p1 = sanitizeString($_POST['p1'] ?? '');
    $p2 = sanitizeString($_POST['p2'] ?? '');
    $p3 = sanitizeString($_POST['p3'] ?? '');

    $error = validateBetCombination($p1, $p2, $p3, $race, $existingBets);
    if (!$error) {
        $betId = generateUUID();
        $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$betId, $currentUser['id'], $raceId, $p1, $p2, $p3]);
        header("Location: index.php?success=bet_placed");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-2 mb-2">
                <div style="width: 48px; height: 48px; background: var(--f1-red); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-flag-checkered" style="color: white; font-size: 1.25rem;"></i>
                </div>
                <div>
                    <h2 style="margin: 0;"><?= escape($race['name']) ?></h2>
                    <p class="text-muted" style="margin: 0;">
                        <i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?> · 
                        <i class="fas fa-clock"></i> <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                    </p>
                </div>
            </div>
            
            <!-- Qualifying -->
            <?php $_qd_data = $race; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); include __DIR__ . '/includes/qualifying-display.php'; ?>
        </div>
        
        <div class="card-body">
            <p class="text-muted mb-2"><?= t('betting_window') ?> · <?= t('points_system') ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?= csrfField() ?>
                <?php
                $positions = [
                    ['key' => 'p1', 'label' => 'P1 (' . ($settings['points_p1'] ?? 25) . ' pts)', 'position' => 1],
                    ['key' => 'p2', 'label' => 'P2 (' . ($settings['points_p2'] ?? 18) . ' pts)', 'position' => 2],
                    ['key' => 'p3', 'label' => 'P3 (' . ($settings['points_p3'] ?? 15) . ' pts)', 'position' => 3],
                ];
                foreach ($positions as $pos): 
                ?>
                    <div class="form-group">
                        <label class="form-label flex items-center gap-1">
                            <span class="position-badge position-<?= $pos['position'] ?>">P<?= $pos['position'] ?></span>
                            <?= $pos['label'] ?>
                        </label>
                        <select name="<?= $pos['key'] ?>" class="form-select" required>
                            <option value=""><?= t('select_driver') ?></option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" <?= ($_POST[$pos['key']] ?? '') === $driver['id'] ? 'selected' : '' ?>><?= driverLabel($driver) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <?= t('place_bet') ?>
                </button>
                <a href="<?= $returnTo ?>" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                    <?= t('cancel') ?>
                </a>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
