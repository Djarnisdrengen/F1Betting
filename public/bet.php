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

$stmt = $db->prepare("
    SELECT b.*, u.display_name, u.email
    FROM bets b JOIN users u ON b.user_id = u.id
    WHERE b.race_id = ?
    ORDER BY b.placed_at DESC
");
$stmt->execute([$raceId]);
$existingBets = $stmt->fetchAll();
$betCount = count($existingBets);

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

$raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);

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

<div class="hf-modal-overlay" data-link="closeBetModal" data-return="<?= escape($returnTo) ?>" role="presentation">
    <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">

        <section class="hf-bet-header">
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

        <?php if ($betCount > 0): ?>
        <div class="hf-all-bets-panel">
            <button type="button" class="hf-all-bets-toggle toggle-bets"
                    data-target="hf-all-bets-list">
                <i class="fas fa-eye"></i>
                <?= t('all_bets') ?> (<?= $betCount ?>)
                <i class="fas fa-chevron-down"></i>
            </button>
            <div id="hf-all-bets-list" class="hf-all-bets-body hidden">
                <?php foreach ($existingBets as $bet): ?>
                    <?php include __DIR__ . '/includes/bet-item.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form class="hf-bet-controls" method="POST" action="bet.php?race=<?= urlencode($raceId) ?>&return=<?= urlencode($returnParam) ?>">
            <?= csrfField() ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
            <?php endif; ?>

            <div class="hf-bet-selects">
                <?php foreach ([1 => 'p1', 2 => 'p2', 3 => 'p3'] as $pos => $key): ?>
                <div class="hf-bet-select-row">
                    <span class="hf-slot-badge pos-<?= $pos ?>">P<?= $pos ?></span>
                    <select name="<?= $key ?>" class="form-select hf-bet-select" required
                            aria-label="P<?= $pos ?> — <?= $pts[$key] ?> pts">
                        <option value=""><?= t('select_driver') ?></option>
                        <?php foreach ($drivers as $d): ?>
                        <option value="<?= escape($d['id']) ?>"
                            <?= (($_POST[$key] ?? '') === $d['id']) ? 'selected' : '' ?>>
                            <?= driverLastName($d) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hf-slot-pts"><?= $pts[$key] ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>

            <footer class="hf-bet-actions">
                <a href="<?= escape($returnTo) ?>" class="hf-btn-ghost"><?= t('cancel') ?></a>
                <button type="submit" class="hf-btn-primary" id="save-btn" disabled>
                    <i class="fas fa-floppy-disk"></i> <?= t('save') ?>
                </button>
            </footer>
        </form>

    </div>
</div>

<script nonce="<?= $nonce ?>" src="assets/js/bet-modal.js"></script>

<?php include __DIR__ . '/includes/footer.php';
// Note: footer.php opens with </main> which closes the <main class="hf-body"> above
?>
