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

$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ? AND id != ?");
$stmt->execute([$bet['race_id'], $betId]);
$existingBets = $stmt->fetchAll();

$error = '';

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

// Current values: POST-back takes precedence over saved bet
$curP1 = $_POST['p1'] ?? $bet['p1'];
$curP2 = $_POST['p2'] ?? $bet['p2'];
$curP3 = $_POST['p3'] ?? $bet['p3'];

// Build JS driver map (surname only, JSON-safe)
$driversForJs = [];
foreach ($driversById as $id => $d) {
    $parts = explode(' ', $d['name'] ?? '');
    $driversForJs[$id] = ['surname' => array_pop($parts)];
}

$pts          = ['p1' => $settings['points_p1'] ?? 25, 'p2' => $settings['points_p2'] ?? 18, 'p3' => $settings['points_p3'] ?? 15];
$raceDateTime = new DateTime($bet['race_date'] . ' ' . $bet['race_time']);
$locAbbr      = mb_strtoupper(mb_substr($bet['location'], 0, 3));

include __DIR__ . '/includes/header.php';
?>

<main class="hf-body">
    <div class="hf-container" style="padding-top:24px; padding-bottom:24px;">
        <div class="hf-racefull">
            <div class="hf-racefull-hd">
                <div class="hf-racenum"><?= escape($locAbbr) ?></div>
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

<script nonce="<?= $nonce ?>">
window.driversById = <?= json_encode($driversForJs) ?>;
window.betPostBack = {
    p1: <?= json_encode($curP1) ?>,
    p2: <?= json_encode($curP2) ?>,
    p3: <?= json_encode($curP3) ?>
};
window.betL10n = { pickDriver: <?= json_encode(t('select_driver')) ?> };
</script>

<div class="hf-modal-overlay" data-link="closeBetModal" data-return="index.php" role="presentation">
    <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">

        <section class="hf-bet-header">
            <div class="hf-bet-avatar"><?= escape($locAbbr) ?></div>
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

        <section class="hf-bet-controls">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>

            <div class="hf-slots">
                <?php foreach ([1 => [$pts['p1']], 2 => [$pts['p2']], 3 => [$pts['p3']]] as $pos => [$p]): ?>
                <button class="hf-slot is-empty" data-link="activateSlot" data-pos="<?= $pos ?>" type="button">
                    <span class="hf-slot-badge pos-<?= $pos ?>">P<?= $pos ?></span>
                    <span class="hf-slot-name"><?= t('select_driver') ?></span>
                    <span class="hf-slot-pts"><?= $p ?> pts</span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="hf-driver-list">
                <?php foreach ($drivers as $d): ?>
                <button class="hf-driver-row" data-link="pickDriver" data-driver-id="<?= $d['id'] ?>" type="button">
                    <span class="hf-driver-num">#<?= intval($d['number']) ?></span>
                    <span class="hf-driver-name"><?= driverLastName($d) ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="hf-bet-actions">
                <a href="index.php" class="hf-btn-ghost"><?= t('cancel') ?></a>
                <button class="hf-btn-primary is-disabled" data-link="saveBet" aria-disabled="true" type="button">
                    <i class="fas fa-floppy-disk"></i> <?= t('save') ?>
                </button>
            </div>
        </section>

    </div>
</div>

<form id="bet-form" method="POST" action="edit_bet.php?id=<?= urlencode($betId) ?>" hidden>
    <?= csrfField() ?>
    <input type="hidden" name="p1" id="form-p1">
    <input type="hidden" name="p2" id="form-p2">
    <input type="hidden" name="p3" id="form-p3">
</form>

<script nonce="<?= $nonce ?>" src="assets/js/bet-modal.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
