<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$currentUser = getCurrentUser();
$db = getDB();
$settings = getSettings();

$raceId      = sanitizeString($_GET['race'] ?? '');
$returnParam = ($_GET['return'] ?? '') === 'index' ? 'index' : 'races';
$returnTo    = $returnParam === 'index' ? 'index.php' : 'races.php';

if (!$raceId) {
    header("Location: " . $returnTo);
    exit;
}

$stmt = $db->prepare("SELECT * FROM races WHERE id = ?");
$stmt->execute([$raceId]);
$race = $stmt->fetch();

if (!$race) {
    header("Location: " . $returnTo);
    exit;
}

$status = getBettingStatus($race, $settings);
if ($status['status'] !== 'open') {
    header("Location: " . $returnTo);
    exit;
}

if (!$currentUser['in_competition']) {
    header("Location: " . $returnTo . "?error=not_in_competition");
    exit;
}

$stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
$stmt->execute([$currentUser['id'], $raceId]);
if ($stmt->fetch()) {
    header("Location: " . $returnTo . "?error=already_bet");
    exit;
}

[$drivers, $driversById] = fetchDrivers($db);

$stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ?");
$stmt->execute([$raceId]);
$existingBets = $stmt->fetchAll();

$error = '';
$lang  = getLang();
$pts   = ['p1' => $settings['points_p1'] ?? 25, 'p2' => $settings['points_p2'] ?? 18, 'p3' => $settings['points_p3'] ?? 15];

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

// Build JS driver map (surname only, JSON-safe)
$driversForJs = [];
foreach ($driversById as $id => $d) {
    $parts = explode(' ', $d['name'] ?? '');
    $driversForJs[$id] = ['surname' => array_pop($parts)];
}

$raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
$locAbbr      = mb_strtoupper(mb_substr($race['location'], 0, 3));

include __DIR__ . '/includes/header.php';
?>

<main class="hf-body">
    <div class="hf-container" style="padding-top:24px; padding-bottom:24px;">
        <div class="hf-racefull">
            <div class="hf-racefull-hd">
                <div class="hf-racefull-info">
                    <div class="hf-racename"><?= escape($race['name']) ?></div>
                    <div class="hf-racemeta">
                        <?= escape($race['location']) ?> &nbsp;·&nbsp;
                        <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script nonce="<?= $nonce ?>">
window.driversById = <?= json_encode($driversForJs) ?>;
window.betPostBack = {
    p1: <?= json_encode($_POST['p1'] ?? '') ?>,
    p2: <?= json_encode($_POST['p2'] ?? '') ?>,
    p3: <?= json_encode($_POST['p3'] ?? '') ?>
};
window.betL10n = { pickDriver: <?= json_encode(t('select_driver')) ?> };
</script>

<div class="hf-modal-overlay" data-link="closeBetModal" data-return="<?= escape($returnTo) ?>" role="presentation">
    <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">

        <section class="hf-bet-header">
            <div class="hf-bet-avatar"><?= escape($locAbbr) ?></div>
            <div class="hf-bet-meta">
                <h2 id="bet-modal-title" class="hf-bet-title"><?= escape($race['name']) ?></h2>
                <div class="hf-bet-submeta">
                    <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                </div>
                <div class="countdown-timer betting-open hf-bet-countdown" data-closes="<?= $raceDateTime->format('c') ?>">
                    <i class="fas fa-stopwatch"></i>
                    <?= t('betting_closes_in') ?>:
                    <span class="hf-bet-countdown-val countdown-value">--</span>
                </div>
            </div>
            <span class="hf-bet-badge open"><?= t('betting_open') ?></span>
            <a href="<?= escape($returnTo) ?>" class="hf-bet-close" aria-label="<?= t('close') ?>">✕</a>
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

            <footer class="hf-bet-actions">
                <a href="<?= escape($returnTo) ?>" class="hf-btn-ghost"><?= t('cancel') ?></a>
                <button class="hf-btn-primary is-disabled" data-link="saveBet" aria-disabled="true" type="button">
                    <i class="fas fa-floppy-disk"></i> <?= t('save') ?>
                </button>
            </footer>
        </section>

    </div>
</div>

<form id="bet-form" method="POST" action="bet.php?race=<?= urlencode($raceId) ?>&return=<?= urlencode($returnParam) ?>" hidden>
    <?= csrfField() ?>
    <input type="hidden" name="p1" id="form-p1">
    <input type="hidden" name="p2" id="form-p2">
    <input type="hidden" name="p3" id="form-p3">
</form>

<script nonce="<?= $nonce ?>" src="assets/js/bet-modal.js"></script>

<?php include __DIR__ . '/includes/footer.php';
// Note: footer.php opens with </main> which closes the <main class="hf-body"> above
?>
