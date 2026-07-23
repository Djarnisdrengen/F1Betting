<?php
// Oversigt (overview) — pure composition of the other four dashboards' own snapshot
// functions. No re-querying, no second computation of any figure — see
// epics/Admin settings and dashboards/feature-2-dashboards-oversigt.md NFR-201.

$nrSnapshot = nrGetHealthSnapshot($db);
$ghSnapshot = ghGetHealthSnapshot();
$kbSnapshot = kbGetHealthSnapshot();
$chSnapshot = chGetUsageSnapshot($db);

$tiles = [
    [
        'tab' => 'keys', 'icon' => 'fas fa-key', 'name' => t('admin_dash_tab_keys'),
        'stat' => (string) $nrSnapshot['health'], 'unit' => t('admin_dash_ov_unit_health'),
        'flag' => $nrSnapshot['flagCount'],
        'note' => $nrSnapshot['flagCount'] > 0 ? sprintf(t('admin_dash_ov_actions_needed'), $nrSnapshot['flagCount']) : t('admin_dash_ov_all_running'),
        'tone' => $nrSnapshot['flagCount'] > 0 ? 'var(--f1-red)' : 'var(--status-success-light)',
    ],
    [
        'tab' => 'actions', 'icon' => 'fa-brands fa-github', 'name' => t('admin_actions_title'),
        'stat' => $ghSnapshot['successRate'] . '%', 'unit' => t('admin_dash_ov_unit_success30'),
        'flag' => $ghSnapshot['failingNow'],
        'note' => $ghSnapshot['failingNow'] > 0 ? sprintf(t('admin_dash_ov_workflows_failing'), $ghSnapshot['failingNow']) : t('admin_dash_ov_all_running'),
        'tone' => $ghSnapshot['failingNow'] > 0 ? 'var(--f1-red)' : 'var(--status-success-light)',
    ],
    [
        'tab' => 'paddockkb', 'icon' => 'fas fa-book-open', 'name' => t('admin_dash_tab_paddockkb'),
        'stat' => $kbSnapshot['healthy'] ? t('admin_dash_ov_healthy') : t('admin_dash_ov_degraded'),
        'unit' => '', 'flag' => $kbSnapshot['flagCount'],
        'note' => $kbSnapshot['flagCount'] > 0 ? t('admin_dash_ov_last_ingest_failed') : t('admin_dash_ov_all_running'),
        'tone' => $kbSnapshot['flagCount'] > 0 ? 'var(--status-warning-light)' : 'var(--status-success-light)',
    ],
    [
        'tab' => 'challenges', 'icon' => 'fas fa-trophy', 'name' => t('admin_dash_tab_challenges'),
        'stat' => (string) $chSnapshot['activeParticipants'], 'unit' => t('admin_dash_ov_unit_active_players'),
        'flag' => $chSnapshot['flagCount'], 'note' => t('admin_dash_ov_all_running'),
        'tone' => 'var(--status-success-light)',
    ],
];

$actionItems = [];
if ($nrSnapshot['flagCount'] > 0) {
    $actionItems[] = ['tab' => 'keys', 'text' => sprintf(t('admin_dash_ov_action_keys'), $nrSnapshot['flagCount'])];
}
if ($ghSnapshot['failingNow'] > 0) {
    $actionItems[] = ['tab' => 'actions', 'text' => sprintf(t('admin_dash_ov_action_gh'), $ghSnapshot['failingNow'])];
}
if (!$kbSnapshot['healthy']) {
    $actionItems[] = ['tab' => 'paddockkb', 'text' => t('admin_dash_ov_action_kb')];
}
?>

<div class="dash-tile-grid">
    <?php foreach ($tiles as $tile): ?>
    <a href="?tab=<?= $tile['tab'] ?>" class="dash-tile" style="text-decoration:none;color:inherit">
        <div class="dash-tile-head">
            <div style="display:flex;align-items:center;gap:11px">
                <span class="dash-tile-icon"><i class="<?= $tile['icon'] ?>" style="color:var(--f1-red);font-size:16px"></i></span>
                <span style="font-weight:800;font-size:15px"><?= escape($tile['name']) ?></span>
            </div>
            <?php if ($tile['flag'] > 0): ?><span class="dash-tile-flag"><?= $tile['flag'] ?></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:baseline;gap:9px">
            <span class="dash-tile-stat"><?= escape($tile['stat']) ?></span>
            <?php if ($tile['unit']): ?><span style="font-size:13px;color:var(--text-muted)"><?= escape($tile['unit']) ?></span><?php endif; ?>
        </div>
        <div class="dash-tile-foot">
            <span style="color:<?= $tile['tone'] ?>"><?= escape($tile['note']) ?></span>
            <span style="color:var(--text-muted)"><?= t('admin_dash_ov_open') ?> →</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<div style="margin-top:16px;border:1px solid rgba(225,6,0,0.4);border-radius:13px;background:linear-gradient(90deg,rgba(225,6,0,0.09),transparent 60%);padding:16px 18px">
    <div style="display:flex;align-items:center;gap:9px;margin-bottom:12px">
        <i class="fas fa-triangle-exclamation" style="color:var(--f1-red)"></i>
        <span style="font-weight:800;font-size:14px"><?= t('admin_dash_nr_action_required') ?></span>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;color:var(--text-secondary)">
        <?php if (empty($actionItems)): ?>
            <div style="display:flex;align-items:center;gap:10px"><i class="fas fa-circle-check" style="color:var(--status-success-light)"></i><span><?= t('admin_dash_nr_all_healthy') ?></span></div>
        <?php else: foreach ($actionItems as $a): ?>
            <div style="display:flex;align-items:center;gap:10px">
                <i class="fas fa-circle" style="font-size:7px;color:var(--f1-red)"></i>
                <span style="flex:1"><?= escape($a['text']) ?></span>
                <a href="?tab=<?= $a['tab'] ?>" style="font-size:12px;color:var(--f1-red)"><?= t('admin_dash_ov_open') ?> →</a>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
