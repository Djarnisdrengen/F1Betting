<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$lang = getLang();
$settings = getSettings();

$betId = sanitizeString($_GET['id'] ?? '');
if (!$betId) {
    header("Location: index.php");
    exit;
}

// Hent bet med race info
$stmt = $db->prepare("
    SELECT b.*, r.name as race_name, r.location, r.race_date, r.race_time,
           r.quali_p1, r.quali_p2, r.quali_p3, r.result_p1
    FROM bets b
    JOIN races r ON b.race_id = r.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->execute([$betId, $currentUser['id']]);
$bet = $stmt->fetch();

if (!$bet) {
    header("Location: index.php?error=bet_not_found");
    exit;
}

// Tjek betting status - kan kun redigere hvis betting stadig er åben
$race = [
    'race_date' => $bet['race_date'],
    'race_time' => $bet['race_time'],
    'result_p1' => $bet['result_p1']
];
$status = getBettingStatus($race, $settings);

if ($status['status'] !== 'open') {
    header("Location: index.php?error=betting_closed");
    exit;
}

// Tjek om bruger er med i konkurrence
if (!$currentUser['in_competition']) {
    header("Location: index.php?error=not_in_competition");
    exit;
}

// Hent kørere
[$drivers, $driversById] = fetchDrivers($db);

// Hent eksisterende bets for dette løb (undtagen brugerens eget)
$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ? AND id != ?");
$stmt->execute([$bet['race_id'], $betId]);
$existingBets = $stmt->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $p1 = sanitizeString($_POST['p1'] ?? '');
    $p2 = sanitizeString($_POST['p2'] ?? '');
    $p3 = sanitizeString($_POST['p3'] ?? '');

    $error = validateBetCombination($p1, $p2, $p3, $bet, $existingBets);
    if (!$error) {
        $db->prepare("UPDATE bets SET p1 = ?, p2 = ?, p3 = ?, placed_at = NOW() WHERE id = ?")
           ->execute([$p1, $p2, $p3, $betId]);
        header("Location: index.php?success=bet_updated");
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
                    <i class="fas fa-edit" style="color: white; font-size: 1.25rem;"></i>
                </div>
                <div>
                    <h2 style="margin: 0;"><?= t('edit_bet_title') ?></h2>
                    <p class="text-muted" style="margin: 0;">
                        <?= escape($bet['race_name']) ?> · <?= escape($bet['location']) ?>
                    </p>
                </div>
            </div>
            
            <p class="text-muted" style="margin-top: 0.5rem;">
                <i class="fas fa-clock"></i> <?= formatRaceDateTime($bet['race_date'], $bet['race_time']) ?>
            </p>
            
            <!-- Qualifying -->
            <?php $_qd_data = $bet; $_qd_keys = ['quali_p1', 'quali_p2', 'quali_p3']; $_qd_label = t('qualifying'); include __DIR__ . '/includes/qualifying-display.php'; ?>
        </div>
        
        <div class="card-body">
            <div class="alert" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa;">
                <i class="fas fa-info-circle"></i> 
                <?= t('timestamp_update_info') ?>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?= csrfField() ?>
                <?php 
                $positions = [
                    ['key' => 'p1', 'label' => 'P1 (25 pts)', 'position' => 1],
                    ['key' => 'p2', 'label' => 'P2 (18 pts)', 'position' => 2],
                    ['key' => 'p3', 'label' => 'P3 (15 pts)', 'position' => 3],
                ];
                foreach ($positions as $pos): 
                    $currentValue = $_POST[$pos['key']] ?? $bet[$pos['key']];
                ?>
                    <div class="form-group">
                        <label class="form-label flex items-center gap-1">
                            <span class="position-badge position-<?= $pos['position'] ?>">P<?= $pos['position'] ?></span>
                            <?= $pos['label'] ?>
                        </label>
                        <select name="<?= $pos['key'] ?>" class="form-select" required>
                            <option value=""><?= t('select_driver') ?></option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" <?= $currentValue === $driver['id'] ? 'selected' : '' ?>><?= driverLabel($driver) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                
                <div class="flex gap-2">
                    <a href="index.php" class="btn" style="flex: 1; text-align: center;">
                        <?= t('cancel') ?>
                    </a>
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> <?= t('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
