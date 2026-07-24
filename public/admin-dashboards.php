<?php
// Dashboards area — Oversigt · Nøgler & Rotation · PaddockKB · Challenges · GitHub Actions.
// See epics/Admin settings and dashboards/plan.md for the architecture behind this router.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin-area-nav.php';
require_once __DIR__ . '/includes/actions-dashboard.php';
require_once __DIR__ . '/includes/admin-dashboards/nogler-rotation-lib.php';
require_once __DIR__ . '/includes/admin-dashboards/paddockkb-lib.php';
require_once __DIR__ . '/includes/admin-dashboards/challenges-usage-lib.php';
requireAdmin();

$db          = getDB();
$currentUser = getCurrentUser();
$actorName   = $currentUser['display_name'] ?: $currentUser['email'];

// ============ POST actions (dispatched by ?action=) ============
// Mirrors admin-challenges.php's own $action-switch convention — a single locus for the
// (small number of) write actions across the Dashboards area's tabs.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // PaddockKB — "Kør opdatering nu" (feature-4-paddockkb-dashboard.md REQ-402).
    // Enqueues the real nightly ingest workflow via workflow_dispatch; never a second,
    // parallel ingest path. NFR-401: refuses to fire again while a run is already in progress.
    if ($action === 'kb_trigger_update') {
        $kbFile = ghWorkflowConfig()['kb-update']['file'];
        $latestRuns = ghListWorkflowRuns($kbFile, 1);
        $alreadyRunning = !empty($latestRuns) && ghNormalizeRunStatus($latestRuns[0]) === 'in_progress';

        if ($alreadyRunning) {
            $kbTriggerResult = 'already_running';
        } else {
            $dispatch = ghTriggerWorkflowDispatch($kbFile);
            $kbTriggerResult = $dispatch['success'] ? 'ok' : $dispatch['error'];
        }
        header('Location: admin-dashboards.php?tab=paddockkb&kb_trigger=' . urlencode($kbTriggerResult));
        exit;
    }

    // Nøgler & Rotation (feature-3-nogler-rotation.md) — three write actions, all admin-gated
    // (above) + CSRF-protected (above) + client-confirmed (data-confirm on each submit button,
    // REQ-309) before they ever reach here.
    if ($action === 'nr_record_token') {
        $itemKey = sanitizeString($_POST['item_key'] ?? '');
        $expiresAt = $_POST['expires_at'] ?? '';
        $result = nrRecordTokenExpiry($db, $itemKey, $expiresAt, $actorName);
        header('Location: admin-dashboards.php?tab=keys&nr_msg=' . urlencode($result['success'] ? 'token_recorded' : $result['error']));
        exit;
    }
    if ($action === 'nr_record_secret') {
        $itemKey = sanitizeString($_POST['item_key'] ?? '');
        $rotatedAtDate = $_POST['rotated_at'] ?? null;
        $result = nrRecordSecretRotation($db, $itemKey, $actorName, $rotatedAtDate ?: null);
        header('Location: admin-dashboards.php?tab=keys&nr_msg=' . urlencode($result['success'] ? 'secret_recorded' : $result['error']));
        exit;
    }
    if ($action === 'nr_rotate_secret') {
        $itemKey = sanitizeString($_POST['item_key'] ?? '');
        $result = nrRotateSecret($db, $itemKey, $actorName);
        // Reveal-once: the new value only ever exists in this one session-flash round-trip —
        // never in the URL (server/proxy logs, browser history), never logged, never persisted
        // beyond this request. Same pattern as $_SESSION['flash_recovery_codes'] in profile.php.
        if ($result['success']) {
            $_SESSION['flash_nr_rotated'] = ['itemKey' => $itemKey, 'newValue' => $result['newValue']];
        }
        header('Location: admin-dashboards.php?tab=keys&nr_msg=' . urlencode($result['success'] ? 'secret_rotated' : $result['error']));
        exit;
    }
}

$tabDefs = [
    'oversigt'   => ['icon' => 'fas fa-table-cells-large', 'label' => 'admin_dash_tab_oversigt'],
    'keys'       => ['icon' => 'fas fa-key',                'label' => 'admin_dash_tab_keys'],
    'paddockkb'  => ['icon' => 'fas fa-book-open',          'label' => 'admin_dash_tab_paddockkb'],
    'challenges' => ['icon' => 'fas fa-trophy',             'label' => 'admin_dash_tab_challenges'],
    'actions'    => ['icon' => 'fa-brands fa-github',       'label' => 'admin_actions_title'],
];

$currentTab = $_GET['tab'] ?? 'oversigt';
if (!array_key_exists($currentTab, $tabDefs)) {
    $currentTab = 'oversigt';
}

// ── ?tab=actions&ajax=run_jobs — lazy per-run step fetch (same-origin, admin-gated,
//    GET/read-only). Must run before any HTML output, same as admin-actions.php did.
if ($currentTab === 'actions' && ($_GET['ajax'] ?? '') === 'run_jobs') {
    require __DIR__ . '/includes/admin-dashboards/actions-ajax.php';
    exit;
}

$lang = getLang();

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
<h1 class="mb-3"><i class="fas fa-gauge-high text-accent"></i> <?= t('admin_page_title') ?></h1>

<?php renderAdminAreaNav('dashboards'); ?>

<div class="admin-shell">

    <nav class="admin-nav" aria-label="<?= t('admin_area_dashboards') ?>">
        <?php foreach ($tabDefs as $key => $def): ?>
            <a href="?tab=<?= $key ?>" class="admin-nav-tab <?= $currentTab === $key ? 'active' : '' ?>">
                <i class="<?= $def['icon'] ?>"></i>
                <span><?= t($def['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php include __DIR__ . "/includes/admin-dashboards/{$currentTab}.php"; ?>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
