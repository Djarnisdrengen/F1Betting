<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
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

// Hent kørere
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$driversById = [];
foreach ($drivers as $d) {
    $driversById[$d['id']] = $d;
}

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
    
    // Validering
    if (!$p1 || !$p2 || !$p3) {
        $error = $lang === 'da' ? 'Vælg alle 3 positioner' : 'Select all 3 positions';
    } elseif ($p1 === $p2 || $p1 === $p3 || $p2 === $p3) {
        $error = $lang === 'da' ? 'Kan ikke vælge samme kører flere gange' : 'Cannot select same driver multiple times';
    } elseif ($bet['quali_p1'] && $p1 === $bet['quali_p1'] && $p2 === $bet['quali_p2'] && $p3 === $bet['quali_p3']) {
        $error = $lang === 'da' ? 'Bet kan ikke matche kvalifikationsresultatet' : 'Bet cannot match qualifying result';
    } else {
        // Tjek om kombinationen allerede er taget
        $isTaken = false;
        foreach ($existingBets as $eb) {
            if ($eb['p1'] === $p1 && $eb['p2'] === $p2 && $eb['p3'] === $p3) {
                $isTaken = true;
                break;
            }
        }
        
        if ($isTaken) {
            $error = $lang === 'da' ? 'Denne kombination er allerede taget' : 'This combination is already taken';
        } else {
            // Opdater bet
            $stmt = $db->prepare("UPDATE bets SET p1 = ?, p2 = ?, p3 = ?, placed_at = NOW() WHERE id = ?");
            $stmt->execute([$p1, $p2, $p3, $betId]);
            
            header("Location: index.php?success=bet_updated");
            exit;
        }
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
                    <h2 style="margin: 0;"><?= $lang === 'da' ? 'Rediger Bet' : 'Edit Bet' ?></h2>
                    <p class="text-muted" style="margin: 0;">
                        <?= escape($bet['race_name']) ?> · <?= escape($bet['location']) ?>
                    </p>
                </div>
            </div>
            
            <p class="text-muted" style="margin-top: 0.5rem;">
                <i class="fas fa-clock"></i> <?= date('d M Y', strtotime($bet['race_date'])) ?> - <?= substr($bet['race_time'], 0, 5) ?> CET
            </p>
            
            <!-- Qualifying -->
            <?php if ($bet['quali_p1']): ?>
                <div style="background: var(--bg-secondary); padding: 0.75rem; border-radius: 8px; margin-top: 1rem;">
                    <small class="text-muted"><?= t('qualifying') ?></small>
                    <div class="quali-row">
                        <?php foreach (['quali_p1', 'quali_p2', 'quali_p3'] as $i => $key): 
                            $driver = $driversById[$bet[$key]] ?? null;
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
        </div>
        
        <div class="card-body">
            <div class="alert" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa;">
                <i class="fas fa-info-circle"></i> 
                <?= $lang === 'da' 
                    ? 'Timestamp vil blive opdateret når du gemmer ændringer.' 
                    : 'Timestamp will be updated when you save changes.' ?>
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
                                <option value="<?= $driver['id'] ?>" <?= $currentValue === $driver['id'] ? 'selected' : '' ?>>
                                    #<?= $driver['number'] ?> <?= escape($driver['name']) ?> - <?= escape($driver['team']) ?>
                                </option>
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
