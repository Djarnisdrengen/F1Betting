<?php
require_once __DIR__ . '/config.php';
requireLogin();

$currentUser = getCurrentUser();
$db = getDB();

$raceId = $_GET['race'] ?? '';
if (!$raceId) {
    header("Location: races.php");
    exit;
}

// Hent race
$stmt = $db->prepare("SELECT * FROM races WHERE id = ?");
$stmt->execute([$raceId]);
$race = $stmt->fetch();

if (!$race) {
    header("Location: races.php");
    exit;
}

// Tjek betting status
$status = getBettingStatus($race);
if ($status['status'] !== 'open') {
    header("Location: races.php");
    exit;
}

// Tjek om bruger allerede har bet
$stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
$stmt->execute([$currentUser['id'], $raceId]);
if ($stmt->fetch()) {
    header("Location: races.php?error=already_bet");
    exit;
}

// Hent kørere
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$driversById = [];
foreach ($drivers as $d) {
    $driversById[$d['id']] = $d;
}

// Hent eksisterende bets for dette løb
$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ?");
$stmt->execute([$raceId]);
$existingBets = $stmt->fetchAll();

$error = '';
$lang = getLang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? '';
    $p2 = $_POST['p2'] ?? '';
    $p3 = $_POST['p3'] ?? '';
    
    // Validering
    if (!$p1 || !$p2 || !$p3) {
        $error = $lang === 'da' ? 'Vælg alle 3 positioner' : 'Select all 3 positions';
    } elseif ($p1 === $p2 || $p1 === $p3 || $p2 === $p3) {
        $error = $lang === 'da' ? 'Kan ikke vælge samme kører flere gange' : 'Cannot select same driver multiple times';
    } elseif ($race['quali_p1'] && $p1 === $race['quali_p1'] && $p2 === $race['quali_p2'] && $p3 === $race['quali_p3']) {
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
            // Opret bet
            $betId = generateUUID();
            $stmt = $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$betId, $currentUser['id'], $raceId, $p1, $p2, $p3]);
            
            header("Location: index.php?success=bet_placed");
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
                    <i class="fas fa-flag-checkered" style="color: white; font-size: 1.25rem;"></i>
                </div>
                <div>
                    <h2 style="margin: 0;"><?= escape($race['name']) ?></h2>
                    <p class="text-muted" style="margin: 0;">
                        <i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?> · 
                        <i class="fas fa-clock"></i> <?= date('d M Y', strtotime($race['race_date'])) ?> - <?= substr($race['race_time'], 0, 5) ?> CET
                    </p>
                </div>
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
        </div>
        
        <div class="card-body">
            <p class="text-muted mb-2"><?= t('betting_window') ?> · <?= t('points_system') ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?php 
                $settings = getSettings();
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
                                <option value="<?= $driver['id'] ?>" <?= ($_POST[$pos['key']] ?? '') === $driver['id'] ? 'selected' : '' ?>>
                                    #<?= $driver['number'] ?> <?= escape($driver['name']) ?> - <?= escape($driver['team']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <?= t('place_bet') ?>
                </button>
                <a href="races.php" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                    <?= t('cancel') ?>
                </a>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
