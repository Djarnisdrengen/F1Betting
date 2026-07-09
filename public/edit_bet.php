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

if (!$currentUser['in_competition']) {
    header("Location: index.php?error=not_in_competition");
    exit;
}

[$drivers, $driversById] = fetchDrivers($db);

// For combo-duplicate validation: excludes own bet so submitting unchanged picks passes
$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ? AND id != ?");
$stmt->execute([$bet['race_id'], $betId]);
$existingBets = $stmt->fetchAll();

// For display: all bets including own (own will show "You" badge via bet-item.php)
$stmt = $db->prepare("
    SELECT b.*, u.display_name, u.email
    FROM bets b JOIN users u ON b.user_id = u.id
    WHERE b.race_id = ?
    ORDER BY b.placed_at DESC
");
$stmt->execute([$bet['race_id']]);
$displayBets = $stmt->fetchAll();
$betCount = count($displayBets);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $p1 = sanitizeString($_POST['p1'] ?? '');
    $p2 = sanitizeString($_POST['p2'] ?? '');
    $p3 = sanitizeString($_POST['p3'] ?? '');

    $error = validateBetCombination($p1, $p2, $p3, $bet, $existingBets, array_keys($driversById));
    if (!$error) {
        $db->prepare("UPDATE bets SET p1 = ?, p2 = ?, p3 = ?, placed_at = NOW() WHERE id = ?")
           ->execute([$p1, $p2, $p3, $betId]);

        // Confirmation email — best effort, never blocks the bet (sendEmail logs failures)
        require_once __DIR__ . '/includes/smtp.php';
        sendBetConfirmationEmail(
            $currentUser['email'],
            $currentUser['display_name'],
            $bet['race_name'],
            [$driversById[$p1]['name'] ?? $p1, $driversById[$p2]['name'] ?? $p2, $driversById[$p3]['name'] ?? $p3],
            true,
            $lang
        );

        header("Location: index.php?success=bet_updated");
        exit;
    }
}

// Current values: POST-back takes precedence over saved bet
$curP1 = $_POST['p1'] ?? $bet['p1'];
$curP2 = $_POST['p2'] ?? $bet['p2'];
$curP3 = $_POST['p3'] ?? $bet['p3'];

$pts          = ['p1' => $settings['points_p1'] ?? 25, 'p2' => $settings['points_p2'] ?? 18, 'p3' => $settings['points_p3'] ?? 15];
$raceDateTime = new DateTime($bet['race_date'] . ' ' . $bet['race_time']);

include __DIR__ . '/includes/header.php';
?>

<main class="hf-body">
    <div class="hf-container" style="padding-top:24px; padding-bottom:24px;">
        <div class="hf-racefull">
            <div class="hf-racefull-hd">
                <div class="hf-racefull-info">
                    <div class="hf-racename"><?= escape($bet['race_name']) ?></div>
                    <div class="hf-racemeta">
                        <?= escape($bet['location']) ?> &nbsp;·&nbsp;
                        <?= formatRaceDateTime($bet['race_date'], $bet['race_time']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div class="hf-modal-overlay" data-link="closeBetModal" data-return="index.php" role="presentation">
    <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">

        <section class="hf-bet-header">
            <div class="hf-bet-meta">
                <h2 id="bet-modal-title" class="hf-bet-title"><?= escape($bet['race_name']) ?></h2>
                <div class="hf-bet-submeta">
                    <?= escape($bet['location']) ?> · <?= formatRaceDateTime($bet['race_date'], $bet['race_time']) ?>
                </div>
                <div class="countdown-timer betting-open hf-bet-countdown" data-closes="<?= $raceDateTime->format('c') ?>">
                    <i class="fas fa-stopwatch"></i>
                    <?= t('betting_closes_in') ?>:
                    <span class="hf-bet-countdown-val countdown-value">--</span>
                </div>
            </div>
            <span class="hf-bet-badge open"><?= t('betting_open') ?></span>
            <a href="index.php" class="hf-bet-close" aria-label="<?= t('close') ?>">✕</a>
        </section>

        <?php if ($betCount > 0): ?>
        <div class="hf-all-bets-panel">
            <button type="button" class="hf-all-bets-toggle toggle-bets"
                    data-target="hf-all-bets-list">
                <i class="fas fa-eye"></i>
                <?= t('all_bets') ?> (<?= $betCount ?>)
                <i class="fas fa-chevron-down"></i>
            </button>
            <div id="hf-all-bets-list" class="hf-all-bets-body hidden">
                <?php $_saved_bet = $bet; foreach ($displayBets as $bet): ?>
                    <?php include __DIR__ . '/includes/bet-item.php'; ?>
                <?php endforeach; $bet = $_saved_bet; unset($_saved_bet); ?>
            </div>
        </div>
        <?php endif; ?>

        <form class="hf-bet-controls" method="POST" action="edit_bet.php?id=<?= urlencode($betId) ?>">
            <?= csrfField() ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>

            <?php $curValues = ['p1' => $curP1, 'p2' => $curP2, 'p3' => $curP3]; ?>
            <div class="hf-bet-selects">
                <?php foreach ([1 => 'p1', 2 => 'p2', 3 => 'p3'] as $pos => $key): ?>
                <div class="hf-bet-select-row">
                    <span class="hf-slot-badge pos-<?= $pos ?>">P<?= $pos ?></span>
                    <select name="<?= $key ?>" class="form-select hf-bet-select" required
                            aria-label="P<?= $pos ?> — <?= $pts[$key] ?> pts">
                        <option value=""><?= t('select_driver') ?></option>
                        <?php foreach ($drivers as $d): ?>
                        <option value="<?= escape($d['id']) ?>"
                            <?= ($curValues[$key] === $d['id']) ? 'selected' : '' ?>>
                            <?= driverLastName($d) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hf-slot-pts"><?= $pts[$key] ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>

            <footer class="hf-bet-actions">
                <a href="index.php" class="hf-btn-ghost"><?= t('cancel') ?></a>
                <button type="submit" class="hf-btn-primary" id="save-btn" disabled>
                    <i class="fas fa-floppy-disk"></i> <?= t('save') ?>
                </button>
            </footer>
        </form>

    </div>
</div>

<script nonce="<?= $nonce ?>" src="assets/js/bet-modal.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
