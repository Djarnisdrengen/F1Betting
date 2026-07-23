<?php
// PaddockKB — ingest health for the knowledge base behind Rumor or Not / Trivia / paddock-query.
// See epics/Admin settings and dashboards/feature-4-paddockkb-dashboard.md. Read-only except
// for "Kør opdatering nu", handled by admin-dashboards.php's kb_trigger_update POST action.
// Shared helpers (pkbFormatBytes, pkbBuildRunView, kbGetHealthSnapshot) live in
// paddockkb-lib.php, required unconditionally by the router.

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$kbWf   = ghWorkflowConfig()['kb-update'];

$kbRunsRaw = ghListWorkflowRuns($kbWf['file'], 6);
$kbRuns    = array_map(fn($r) => pkbBuildRunView($r, $lang, $nowUtc), $kbRunsRaw);
$lastRun   = $kbRuns[0] ?? null;
$nextRunDt = ghNextFireDateTime($kbWf['cron'], $nowUtc);
$kbRunInProgress = $lastRun !== null && $lastRun['status'] === 'in_progress';

// KB file read live and whole — see plan.md decision 7 (under 100 docs, cheap on every load).
// public/paddock-rumors/knowledge-base.json is the deployed, web-root copy that
// public/paddock-rumors/query.php also reads — NOT paddock-rumors/data/knowledge-base.json,
// which is the git-repo/CI-only master the Node ingest pipeline writes to and is never
// deployed (only public/ is uploaded; see docs/paddock-rumors-reference.md NFR-101).
$kbPath      = __DIR__ . '/../../paddock-rumors/knowledge-base.json';
$kbEntries   = [];
$kbReadError = true;
if (is_file($kbPath)) {
    $raw     = @file_get_contents($kbPath);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $kbEntries   = $decoded;
        $kbReadError = false;
    }
}
$totalEntries = count($kbEntries);
$indexSize    = is_file($kbPath) ? pkbFormatBytes((int) filesize($kbPath)) : '—';

// Freshness thresholds (implementation default — not pinned by the design handoff):
// green <14 days since the category's most-recently-updated entry, orange <45 days, else red.
$categories = []; // type => ['count' => n, 'latest' => DateTimeImmutable|null]
foreach ($kbEntries as $entry) {
    $type = $entry['tags']['type'] ?? 'other';
    if (!isset($categories[$type])) {
        $categories[$type] = ['count' => 0, 'latest' => null];
    }
    $categories[$type]['count']++;
    if (!empty($entry['updated_at'])) {
        try {
            $updated = new DateTimeImmutable($entry['updated_at']);
            if ($categories[$type]['latest'] === null || $updated > $categories[$type]['latest']) {
                $categories[$type]['latest'] = $updated;
            }
        } catch (Exception $e) {
            // Malformed date on one entry shouldn't take down the whole dashboard.
        }
    }
}
uasort($categories, fn($a, $b) => $b['count'] <=> $a['count']);

$kbTriggerMsg = $_GET['kb_trigger'] ?? '';
?>

<?php if ($kbTriggerMsg !== ''): ?>
    <?php $triggerKey = 'admin_dash_kb_trigger_' . $kbTriggerMsg; ?>
    <div class="alert <?= $kbTriggerMsg === 'ok' ? 'alert-success' : 'alert-danger' ?>">
        <?= escape(t($triggerKey)) ?>
    </div>
<?php endif; ?>

<?php if ($kbReadError): ?>
<div class="gha-error-banner">
    <i class="fas fa-triangle-exclamation"></i>
    <div><?= escape(t('admin_dash_kb_read_error')) ?></div>
</div>
<?php endif; ?>

<div class="dash-status-grid">
    <div class="dash-status-card">
        <div class="dash-status-label"><?= t('admin_dash_kb_last_update') ?></div>
        <?php if ($lastRun): ?>
            <div class="dash-status-value"><?= escape($lastRun['ago']) ?></div>
            <div class="dash-status-sub" style="color:<?= $lastRun['statusColor'] ?>">
                <i class="fas <?= $lastRun['statusIcon'] ?>"></i> <?= escape($lastRun['statusLabel']) ?> · <?= escape($lastRun['duration']) ?>
            </div>
        <?php else: ?>
            <div class="dash-status-value">—</div>
            <div class="dash-status-sub"><?= t('admin_dash_kb_no_runs') ?></div>
        <?php endif; ?>
    </div>
    <div class="dash-status-card">
        <div class="dash-status-label"><i class="fas fa-hourglass-half" style="margin-right:6px"></i><?= t('admin_dash_kb_next_scheduled') ?></div>
        <?php if ($nextRunDt): ?>
            <div class="dash-status-value"><?= escape(ghRelativeTime($nextRunDt, $nowUtc, $lang, true)) ?></div>
            <div class="dash-status-sub"><?= escape(ghFormatCetFull($nextRunDt, $lang)) ?></div>
        <?php else: ?>
            <div class="dash-status-value">—</div>
            <div class="dash-status-sub"><?= t('admin_dash_kb_no_next_run') ?></div>
        <?php endif; ?>
    </div>
</div>

<form method="POST" style="margin-bottom:20px">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="kb_trigger_update">
    <button type="submit" class="btn btn-primary" <?= $kbRunInProgress ? 'disabled' : '' ?>>
        <i class="fas fa-rotate"></i> <?= t('admin_dash_kb_run_now') ?>
    </button>
    <?php if ($kbRunInProgress): ?>
        <span class="text-muted" style="margin-left:10px;font-size:13px"><?= t('admin_dash_kb_run_already') ?></span>
    <?php endif; ?>
</form>

<div class="gha-summary" style="grid-template-columns:repeat(3,1fr)">
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-database"></i> <?= t('admin_dash_kb_kpi_entries') ?></div>
        <div class="gha-stat-value"><?= number_format($totalEntries, 0, ',', '.') ?></div>
    </div>
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-layer-group"></i> <?= t('admin_dash_kb_kpi_categories') ?></div>
        <div class="gha-stat-value"><?= count($categories) ?></div>
    </div>
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-hard-drive"></i> <?= t('admin_dash_kb_kpi_index_size') ?></div>
        <div class="gha-stat-value"><?= escape($indexSize) ?></div>
    </div>
</div>

<section class="gha-panel" style="padding:18px 20px;margin-bottom:18px">
    <h3 style="margin:0 0 14px;font-size:15px"><i class="fas fa-layer-group" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_kb_categories') ?></h3>
    <?php if (empty($categories)): ?>
        <div class="gha-empty"><?= t('admin_dash_kb_no_runs') ?></div>
    <?php else: foreach ($categories as $type => $cat):
        $label = t('admin_dash_kb_cat_' . $type);
        if ($label === 'admin_dash_kb_cat_' . $type) $label = t('admin_dash_kb_cat_other');
        $pct = $totalEntries > 0 ? round($cat['count'] / $totalEntries * 100) : 0;
        $ageDays = $cat['latest'] ? $nowUtc->diff($cat['latest'])->days : null;
        $freshClass = $ageDays === null ? 'bad' : ($ageDays < 14 ? 'ok' : ($ageDays < 45 ? 'warn' : 'bad'));
        $freshColor = ['ok' => 'var(--status-success-light)', 'warn' => 'var(--status-warning-light)', 'bad' => 'var(--status-danger-light)'][$freshClass];
    ?>
    <div class="dash-cat-row">
        <span class="dash-cat-name"><?= escape($label) ?></span>
        <div class="dash-progress-track"><div class="dash-progress-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--f1-red),var(--f1-red-light))"></div></div>
        <div class="dash-cat-meta">
            <span><?= $cat['count'] ?></span>
            <i class="fas fa-circle dash-fresh-dot" style="color:<?= $freshColor ?>;background:<?= $freshColor ?>"></i>
        </div>
    </div>
    <?php endforeach; endif; ?>
</section>

<section class="gha-panel">
    <div style="padding:15px 20px;border-bottom:1px solid var(--border-color)">
        <h3 style="margin:0;font-size:15px"><i class="fas fa-arrow-down-up-across-line" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_kb_recent_runs') ?></h3>
    </div>
    <?php if (empty($kbRuns)): ?>
        <div class="gha-empty"><?= t('admin_dash_kb_no_runs') ?></div>
    <?php else: foreach ($kbRuns as $r): ?>
        <div class="dash-run-row">
            <span class="dash-run-when"><?= escape($r['startedFull']) ?></span>
            <span class="dash-run-source"><?= escape($kbWf['name']) ?></span>
            <span class="label-mono" style="font-size:12px;color:var(--text-secondary)"><?= escape($r['duration']) ?></span>
            <span style="color:<?= $r['statusColor'] ?>;font-size:12px;font-weight:700"><i class="fas <?= $r['statusIcon'] ?>"></i> <?= escape($r['statusLabel']) ?></span>
        </div>
    <?php endforeach; endif; ?>
</section>
