<?php
// GitHub Actions Dashboard tab — moved verbatim from the former standalone
// public/admin-actions.php (now a compatibility redirect here) as part of the
// Dashboards nav restructure. Content/behavior unchanged — see
// epics/Admin settings and dashboards/plan.md decision 2 and
// epics/github_actions_dashboard/plan.md for the original architecture.

// ── Page-local view-model builder ────────────────────────────────────────────
function gaBuildRunView(array $run, string $wfId, array $wf, string $lang, DateTimeImmutable $now): array {
    $status  = ghNormalizeRunStatus($run);
    $meta    = ghStatusMeta($status);
    $started = new DateTimeImmutable($run['run_started_at'] ?? $run['created_at']);
    $updated = new DateTimeImmutable($run['updated_at'] ?? ($run['run_started_at'] ?? $run['created_at']));
    $durationSec = $status === 'in_progress'
        ? max(0, $now->getTimestamp() - $started->getTimestamp())
        : max(0, $updated->getTimestamp() - $started->getTimestamp());
    $event = $run['event'] ?? 'schedule';

    return [
        'id'           => $run['id'],
        'no'           => $run['run_number'] ?? 0,
        'status'       => $status,
        'statusIcon'   => $meta['icon'],
        'statusColor'  => $meta['color'],
        'statusLabel'  => t('admin_actions_st_' . $status, $lang),
        'triggerIcon'  => ghTriggerIcon($event),
        'triggerLabel' => ghTriggerLabel($event, $lang),
        'actor'        => $run['actor']['login'] ?? 'github-actions[bot]',
        'branch'       => $run['head_branch'] ?? 'main',
        'sha'          => substr((string)($run['head_sha'] ?? ''), 0, 7),
        'startedUtc'   => $started,
        'startedFull'  => ghFormatCetFull($started, $lang),
        'ago'          => ghRelativeTime($started, $now, $lang),
        'duration'     => ghFormatDuration($durationSec),
        'completed'    => ($run['status'] ?? '') === 'completed',
        'wfId'         => $wfId,
        'wfName'       => $wf['name'],
        'wfIcon'       => $wf['icon'],
    ];
}

// ── Assemble page data ───────────────────────────────────────────────────────
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$workflowConfig = ghWorkflowConfig();
$monthStart     = $nowUtc->modify('first day of this month')->setTime(0, 0, 0);
$schedule       = ghComputeSchedule($workflowConfig, $monthStart, $nowUtc);

$runsByFile = ghListWorkflowRunsMulti(array_column($workflowConfig, 'file'), 10);

$runsByWorkflow = [];
foreach ($workflowConfig as $id => $wf) {
    $views = [];
    foreach ($runsByFile[$wf['file']] ?? [] as $run) {
        $views[] = gaBuildRunView($run, $id, $wf, $lang, $nowUtc);
    }
    $runsByWorkflow[$id] = $views;
}
// Shared with Dashboards → Oversigt's snapshot (ghGetHealthSnapshot()) — same aggregator,
// so the two views can never disagree on the arithmetic (feature-2 NFR-201).
$summary = ghSummarizeRuns($runsByWorkflow, $nowUtc);
$totalRuns24 = $summary['totalRuns24'];
$successRate = $summary['successRate'];
$failingNow  = $summary['failingNow'];

// Sort order: monthly run frequency descending; manual-only (no schedule) workflows last.
$order = array_keys($workflowConfig);
usort($order, function ($a, $b) use ($schedule, $workflowConfig) {
    $ma = !empty($workflowConfig[$a]['manual']);
    $mb = !empty($workflowConfig[$b]['manual']);
    if ($ma !== $mb) return $ma <=> $mb;
    return ($schedule['monthlyTotal'][$b] ?? 0) <=> ($schedule['monthlyTotal'][$a] ?? 0);
});

// Default selection: the workflow with the most recently started run; else first in order.
$selectedId = $_GET['workflow'] ?? '';
if (!isset($workflowConfig[$selectedId])) {
    $latest = null; $selectedId = $order[0];
    foreach ($runsByWorkflow as $id => $views) {
        if (!empty($views) && ($latest === null || $views[0]['startedUtc'] > $latest)) {
            $latest = $views[0]['startedUtc']; $selectedId = $id;
        }
    }
}
$selectedWf   = $workflowConfig[$selectedId];
$selectedRuns = $runsByWorkflow[$selectedId];

// Runs · last 12h, flattened across workflows, newest first.
$twelveAgo = $nowUtc->modify('-12 hours');
$recent12 = [];
foreach ($runsByWorkflow as $views) {
    foreach ($views as $v) {
        if ($v['startedUtc'] > $twelveAgo) $recent12[] = $v;
    }
}
usort($recent12, fn($a, $b) => $b['startedUtc'] <=> $a['startedUtc']);

$fetchError = !empty($GLOBALS['ghFetchError']);
?>

<div style="text-align:right;margin-bottom:10px">
    <span class="gha-pill"><i class="fas fa-code-branch"></i> main</span>
</div>

<?php if ($fetchError): ?>
<div class="gha-error-banner">
    <i class="fas fa-triangle-exclamation"></i>
    <div><strong><?= escape(t('admin_actions_error_title')) ?></strong> — <?= escape(t('admin_actions_error_body')) ?></div>
</div>
<?php endif; ?>
<?php if (!defined('GITHUB_TOKEN')): ?>
<div class="gha-token-hint"><i class="fas fa-circle-info"></i> <?= escape(t('admin_actions_no_token_hint')) ?></div>
<?php endif; ?>

<div class="gha-page">

<div class="gha-summary">
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-diagram-project"></i> <?= t('admin_actions_workflows') ?></div>
        <div class="gha-stat-value"><?= count($workflowConfig) ?></div>
    </div>
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-clock-rotate-left"></i> <?= t('admin_actions_runs24') ?></div>
        <div class="gha-stat-value"><?= $totalRuns24 ?></div>
    </div>
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-chart-line"></i> <?= t('admin_actions_success_rate') ?></div>
        <div class="gha-stat-value success"><?= $successRate ?>%</div>
    </div>
    <div class="gha-stat-card">
        <div class="gha-stat-label"><i class="fas fa-triangle-exclamation"></i> <?= t('admin_actions_failing_now') ?></div>
        <div class="gha-stat-value <?= $failingNow > 0 ? 'danger' : 'success' ?>"><?= $failingNow ?></div>
    </div>
</div>

<section class="gha-panel" id="gha-12h-panel">
    <div class="gha-panel-toggle collapsed" id="gha-12h-toggle">
        <div class="gha-panel-toggle-title">
            <i class="fas fa-clock-rotate-left" style="color:var(--f1-red)"></i>
            <h3><?= t('admin_actions_last12') ?></h3>
        </div>
        <div class="gha-panel-toggle-right">
            <span class="gha-pill"><?= count($recent12) ?></span>
            <i class="fas fa-chevron-down" id="gha-12h-chevron" style="color:var(--text-muted);font-size:12px"></i>
        </div>
    </div>
    <div id="gha-12h-body" style="display:none">
        <div class="gha-colheader gha-cols-12h">
            <span><?= t('admin_actions_col_workflow') ?></span><span><?= t('admin_actions_col_status') ?></span>
            <span><?= t('admin_actions_col_trigger') ?></span><span><?= t('admin_actions_col_started') ?></span>
            <span class="gha-col-right"><?= t('admin_actions_col_duration') ?></span>
        </div>
        <div class="gha-scroll" style="max-height:340px">
            <?php if (empty($recent12)): ?>
                <div class="gha-empty"><?= t('admin_actions_no_runs12') ?></div>
            <?php else: foreach ($recent12 as $r): ?>
                <a href="?tab=actions&workflow=<?= urlencode($r['wfId']) ?>" class="gha-run-row-12h">
                    <span class="gha-run-wf"><i class="fas fa-<?= escape($r['wfIcon']) ?>"></i><span class="gha-ellipsis"><?= escape($r['wfName']) ?></span></span>
                    <span class="gha-status-cell" style="color:<?= $r['statusColor'] ?>"><i class="fas <?= $r['statusIcon'] ?>"></i><?= escape($r['statusLabel']) ?></span>
                    <span class="gha-trigger-cell"><i class="fas <?= $r['triggerIcon'] ?>"></i><span class="gha-ellipsis"><?= escape($r['triggerLabel']) ?> · <?= escape($r['actor']) ?></span></span>
                    <span class="gha-started-cell"><span class="gha-started-full"><?= escape($r['startedFull']) ?></span><span class="gha-started-ago"><?= escape($r['ago']) ?></span></span>
                    <span class="gha-duration-cell"><?= escape($r['duration']) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</section>

<div class="gha-master-detail">

    <aside class="gha-rail">
        <div class="gha-rail-header">
            <span><?= t('admin_actions_all_workflows') ?></span>
            <span class="gha-pill"><?= count($workflowConfig) ?></span>
        </div>
        <div class="gha-filter-wrap">
            <i class="fas fa-magnifying-glass gha-filter-icon"></i>
            <input type="text" class="gha-input" id="gha-filter" placeholder="<?= escape(t('admin_actions_filter')) ?>" autocomplete="off">
        </div>
        <div class="gha-wf-list" id="gha-wf-list">
            <?php foreach ($order as $id):
                $wf = $workflowConfig[$id];
                $views = $runsByWorkflow[$id];
                $latest = $views[0] ?? null;
                $meta = $latest ? ['icon' => $latest['statusIcon'], 'color' => $latest['statusColor']] : ghStatusMeta('skipped');
            ?>
            <a href="?tab=actions&workflow=<?= urlencode($id) ?>" class="gha-wf-row <?= $id === $selectedId ? 'active' : '' ?>" data-name="<?= escape(mb_strtolower($wf['name'])) ?>">
                <i class="fas <?= $meta['icon'] ?> gha-wf-row-icon" style="color:<?= $meta['color'] ?>"></i>
                <div style="flex:1;min-width:0">
                    <div class="gha-wf-row-name gha-ellipsis"><?= escape($wf['name']) ?></div>
                    <div class="gha-wf-row-last"><i class="fas fa-clock-rotate-left"></i><?= $latest ? escape($latest['startedFull']) : '—' ?></div>
                </div>
                <span class="gha-wf-row-ago"><?= $latest ? escape($latest['ago']) : '' ?></span>
            </a>
            <?php endforeach; ?>
            <div class="gha-empty" id="gha-wf-empty" style="display:none"></div>
        </div>
    </aside>

    <main style="min-width:0">
        <?php
        $latestSelected = $selectedRuns[0] ?? null;
        $selMeta = $latestSelected ? ['icon' => $latestSelected['statusIcon'], 'color' => $latestSelected['statusColor']] : ghStatusMeta('skipped');
        $passed = count(array_filter($selectedRuns, fn($r) => $r['status'] === 'success'));
        $failed = count(array_filter($selectedRuns, fn($r) => $r['status'] === 'failure'));
        $next = empty($selectedWf['manual']) ? ghNextFireDateTime($selectedWf['cron'], $nowUtc) : null;
        ?>
        <section class="gha-detail-card">
            <div class="gha-detail-head">
                <div class="gha-detail-icon"><i class="fas fa-<?= escape($selectedWf['icon']) ?>"></i></div>
                <div class="gha-detail-title">
                    <h2><?= escape($selectedWf['name']) ?></h2>
                    <?php if (!empty($selectedRuns)): ?>
                    <div class="gha-detail-status-line">
                        <i class="fas <?= $selMeta['icon'] ?>" style="color:<?= $selMeta['color'] ?>"></i>
                        <span><?= escape(sprintf(t('admin_actions_runs_summary'), $passed, $failed, count($selectedRuns))) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <span class="gha-detail-file-chip">.github/workflows/<?= escape($selectedWf['file']) ?></span>
            </div>

            <div class="gha-info-grid">
                <div class="gha-info-panel">
                    <div class="gha-info-label"><i class="fas fa-bullseye"></i> <?= t('admin_actions_purpose') ?></div>
                    <p><?= escape(ghWorkflowPurpose($selectedId, $lang)) ?></p>
                </div>
                <div class="gha-info-panel">
                    <div class="gha-info-label"><i class="fas fa-flag-checkered"></i> <?= t('admin_actions_expected') ?></div>
                    <p><?= escape(ghWorkflowExpected($selectedId, $lang)) ?></p>
                </div>
            </div>

            <div class="gha-meta-row">
                <div class="gha-meta-chip">
                    <i class="fas fa-calendar-days"></i>
                    <div><div class="gha-meta-label"><?= t('admin_actions_schedule') ?></div><div class="gha-meta-value"><?= escape(ghScheduleHumanText($selectedWf, $nowUtc, $lang)) ?></div></div>
                </div>
                <div class="gha-meta-chip">
                    <i class="fas fa-terminal"></i>
                    <div><div class="gha-meta-label"><?= t('admin_actions_cron') ?></div><div class="gha-meta-value label-mono"><?= empty($selectedWf['cron']) ? '—' : escape(implode(', ', $selectedWf['cron'])) ?></div></div>
                </div>
                <div class="gha-meta-chip">
                    <i class="fas <?= !empty($selectedWf['manual']) ? 'fa-hand-pointer' : 'fa-clock' ?>"></i>
                    <div><div class="gha-meta-label"><?= t('admin_actions_trigger') ?></div><div class="gha-meta-value"><?= !empty($selectedWf['manual']) ? t('admin_actions_tr_workflow_dispatch') : t('admin_actions_tr_schedule') ?></div></div>
                </div>
                <div class="gha-meta-chip next">
                    <i class="fas <?= !empty($selectedWf['manual']) ? 'fa-hand-pointer' : 'fa-flag-checkered' ?>"></i>
                    <div>
                        <div class="gha-meta-label"><?= t('admin_actions_next_run') ?></div>
                        <div class="gha-meta-value label-mono">
                        <?php if (!empty($selectedWf['manual'])): ?>
                            <?= t('admin_actions_manual_only') ?>
                        <?php elseif ($next): ?>
                            <?= escape(ghFormatCetFull($next, $lang)) ?> <span class="gha-meta-sub">· <?= escape(ghRelativeTime($next, $nowUtc, $lang, true)) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="gha-runs-card">
            <div class="gha-runs-head">
                <h3><?= t('admin_actions_last10') ?></h3>
                <span class="gha-runs-hint"><?= t('admin_actions_click_hint') ?></span>
            </div>
            <div class="gha-colheader gha-cols-runs">
                <span><?= t('admin_actions_col_status') ?></span><span><?= t('admin_actions_col_run') ?></span>
                <span><?= t('admin_actions_col_trigger') ?></span><span><?= t('admin_actions_col_started') ?></span>
                <span class="gha-col-right"><?= t('admin_actions_col_duration') ?></span><span></span>
            </div>
            <?php if (empty($selectedRuns)): ?>
                <div class="gha-empty"><?= t('admin_actions_no_runs12') ?></div>
            <?php endif; ?>
            <?php foreach ($selectedRuns as $r): ?>
            <div class="gha-run-row" data-run-toggle data-run-id="<?= (int)$r['id'] ?>" data-completed="<?= $r['completed'] ? '1' : '0' ?>">
                <span class="gha-run-status" style="color:<?= $r['statusColor'] ?>"><i class="fas <?= $r['statusIcon'] ?>"></i><?= escape($r['statusLabel']) ?></span>
                <span class="gha-run-no">#<?= (int)$r['no'] ?></span>
                <span class="gha-trigger-cell"><i class="fas <?= $r['triggerIcon'] ?>"></i><span class="gha-ellipsis"><?= escape($r['triggerLabel']) ?> · <?= escape($r['actor']) ?></span></span>
                <span class="gha-run-ago"><?= escape($r['ago']) ?></span>
                <span class="gha-run-duration"><?= escape($r['duration']) ?></span>
                <span class="gha-run-chevron"><i class="fas fa-chevron-down"></i></span>
            </div>
            <div class="gha-run-log" data-run-log>
                <div class="gha-run-log-meta">
                    <span><i class="fas fa-user"></i><?= escape($r['actor']) ?></span>
                    <span><i class="fas fa-code-branch"></i><?= escape($r['branch']) ?></span>
                    <span><i class="fas fa-hashtag"></i><span class="label-mono"><?= escape($r['sha']) ?></span></span>
                    <span><i class="fas fa-clock"></i><span class="label-mono"><?= escape($r['startedFull']) ?></span></span>
                </div>
                <div class="gha-console gha-scroll" data-console></div>
            </div>
            <?php endforeach; ?>
        </section>
    </main>
</div>

<?php
$scheduledIds = array_filter($order, fn($id) => empty($workflowConfig[$id]['manual']));
?>
<section class="gha-panel gha-matrix-card" style="margin-top:18px">
    <div class="gha-matrix-header">
        <div class="gha-matrix-title"><i class="fas fa-calendar-week" style="color:var(--f1-red)"></i><h3><?= t('admin_actions_sched_title') ?> · <?= escape(GH_MONTHS[$lang][(int)$monthStart->format('n') - 1]) ?> <?= $monthStart->format('Y') ?></h3></div>
        <div class="gha-heat-legend">
            <span><?= t('admin_actions_heat_label') ?></span>
            <span class="gha-heat-swatch"><span class="gha-swatch" style="background:rgba(225,6,0,0.38)"></span><?= t('admin_actions_heat_1') ?></span>
            <span class="gha-heat-swatch"><span class="gha-swatch" style="background:rgba(225,6,0,0.65)"></span><?= t('admin_actions_heat_few') ?></span>
            <span class="gha-heat-swatch"><span class="gha-swatch" style="background:rgba(225,6,0,0.95)"></span><?= t('admin_actions_heat_many') ?></span>
            <span class="gha-heat-divider"></span>
            <span class="gha-collide-key"><i class="fas fa-triangle-exclamation"></i><?= t('admin_actions_collide_key') ?></span>
        </div>
    </div>
    <div class="gha-matrix-scroll gha-scroll">
        <div class="gha-matrix-inner">
            <div class="gha-matrix-head-row">
                <div></div>
                <div class="gha-matrix-head-time"><?= t('admin_actions_col_time') ?></div>
                <div class="gha-matrix-daygrid" style="grid-template-columns:repeat(<?= $schedule['dayCount'] ?>,1fr)">
                    <?php for ($d = 1; $d <= $schedule['dayCount']; $d++):
                        $dayDt = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $d);
                        $wd = (int)$dayDt->format('w');
                        $isWeekend = $wd === 0 || $wd === 6;
                        $isToday = $dayDt->format('Y-m-d') === $nowUtc->setTimezone(ghCetTz())->format('Y-m-d');
                    ?>
                    <div class="gha-matrix-daycell" style="background:<?= $isWeekend ? 'rgba(225,6,0,0.08)' : 'transparent' ?>">
                        <div class="wd" style="color:<?= $isWeekend ? 'var(--f1-red)' : 'var(--text-secondary)' ?>"><?= GH_WEEKDAY_LETTERS[$lang][$wd] ?></div>
                        <div class="dn" style="color:<?= $isToday ? 'var(--f1-red)' : ($isWeekend ? 'var(--text-muted)' : 'var(--text-secondary)') ?>"><?= $d ?></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="gha-collision-row">
                <div class="gha-collision-label"><i class="fas fa-triangle-exclamation"></i><?= t('admin_actions_collisions') ?></div>
                <div></div>
                <div class="gha-collision-cellgrid" style="grid-template-columns:repeat(<?= $schedule['dayCount'] ?>,1fr)">
                    <?php for ($d = 1; $d <= $schedule['dayCount']; $d++):
                        $dayDt = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $d);
                        $isWeekend = in_array((int)$dayDt->format('w'), [0, 6], true);
                        $strong = $schedule['collisions'][$d];
                        $title = '';
                        if (!empty($strong)) {
                            $bits = [];
                            foreach ($strong as $hm => $names) {
                                [$h, $m] = explode(':', $hm);
                                $cetLabel = ghUtcHourMinToCetLabel((int)$h, (int)$m, $dayDt);
                                $bits[] = "$cetLabel — " . implode(', ', $names) . ' (' . count($names) . ')';
                            }
                            $title = implode('  ·  ', $bits);
                        }
                    ?>
                    <div class="gha-collision-cell" title="<?= escape($title) ?>" style="background:<?= !empty($strong) ? 'rgba(225,6,0,0.92)' : ($isWeekend ? 'rgba(225,6,0,0.06)' : 'transparent') ?>">
                        <?php if (!empty($strong)): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php foreach ($scheduledIds as $id):
                $wf = $workflowConfig[$id];
                $timeLabel = ghScheduleHumanText($wf, $nowUtc, $lang);
            ?>
            <a href="?tab=actions&workflow=<?= urlencode($id) ?>" class="gha-matrix-row <?= $id === $selectedId ? 'active' : '' ?>">
                <div class="gha-matrix-row-name">
                    <i class="fas fa-<?= escape($wf['icon']) ?>"></i>
                    <div style="min-width:0">
                        <div class="gha-matrix-row-label"><?= escape($wf['name']) ?></div>
                        <div class="gha-matrix-row-cadence"><?= escape($timeLabel) ?></div>
                    </div>
                </div>
                <div class="gha-matrix-row-time label-mono"><?= count($wf['cron']) > 1 ? '—' : escape($timeLabel) ?></div>
                <div class="gha-matrix-cellgrid" style="grid-template-columns:repeat(<?= $schedule['dayCount'] ?>,1fr)">
                    <?php for ($d = 1; $d <= $schedule['dayCount']; $d++):
                        $dayDt = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $d);
                        $times = $schedule['perWorkflowDay'][$id][$d];
                        $count = count($times);
                        $opacity = $count >= 24 ? 0.95 : ($count >= 4 ? 0.65 : ($count >= 1 ? 0.38 : 0));
                        $isWeekend = in_array((int)$dayDt->format('w'), [0, 6], true);
                        $isToday = $dayDt->format('Y-m-d') === $nowUtc->setTimezone(ghCetTz())->format('Y-m-d');
                        $bg = $count > 0 ? "rgba(225,6,0,$opacity)" : ($isWeekend ? 'rgba(225,6,0,0.04)' : 'var(--bg-secondary)');
                        $border = $isToday ? 'var(--text-primary)' : ($count > 0 ? 'rgba(225,6,0,' . min(1, $opacity + 0.2) . ')' : 'var(--border-color)');
                        $cellTitle = $wf['name'] . ' · ' . $d . '. ' . GH_MONTHS[$lang][(int)$monthStart->format('n') - 1]
                            . ($count > 0 ? ' · ' . implode('/', array_map(fn($t) => ghUtcHourMinToCetLabel((int)explode(':', $t)[0], (int)explode(':', $t)[1], $dayDt), $times)) . " · {$count}×" : ($lang === 'da' ? ' · kører ikke' : ' · no run'));
                    ?>
                    <div class="gha-matrix-cell" title="<?= escape($cellTitle) ?>" style="background:<?= $bg ?>;border-color:<?= $border ?>"></div>
                    <?php endfor; ?>
                </div>
            </a>
            <?php endforeach; ?>

            <div class="gha-matrix-note"><i class="fas fa-circle-info"></i><?= t('admin_actions_sched_note') ?></div>
        </div>
    </div>
</section>

</div><!-- /.gha-page -->

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Runs · last 12h collapse (default collapsed).
    var toggle12 = document.getElementById('gha-12h-toggle');
    var body12 = document.getElementById('gha-12h-body');
    var chevron12 = document.getElementById('gha-12h-chevron');
    if (toggle12) {
        toggle12.addEventListener('click', function () {
            var open = body12.style.display !== 'none';
            body12.style.display = open ? 'none' : '';
            toggle12.classList.toggle('collapsed', open);
            chevron12.classList.toggle('fa-chevron-down', open);
            chevron12.classList.toggle('fa-chevron-up', !open);
        });
    }

    // Workflow filter (client-side, over server-rendered rows).
    var filterInput = document.getElementById('gha-filter');
    var wfList = document.getElementById('gha-wf-list');
    var wfEmpty = document.getElementById('gha-wf-empty');
    if (filterInput) {
        filterInput.addEventListener('input', function () {
            var q = filterInput.value.trim().toLowerCase();
            var rows = wfList.querySelectorAll('.gha-wf-row');
            var visible = 0;
            rows.forEach(function (row) {
                var match = row.dataset.name.indexOf(q) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (visible === 0) {
                wfEmpty.textContent = <?= json_encode(t('admin_actions_no_match')) ?>.replace('%s', filterInput.value);
                wfEmpty.style.display = '';
            } else {
                wfEmpty.style.display = 'none';
            }
        });
    }

    // Run row expand — lazy-loads step list via ?ajax=run_jobs on first open, cached in the DOM after.
    document.querySelectorAll('[data-run-toggle]').forEach(function (row) {
        row.addEventListener('click', function () {
            var log = row.nextElementSibling;
            var chevron = row.querySelector('.gha-run-chevron i');
            var open = log.classList.toggle('open');
            row.classList.toggle('open', open);
            chevron.classList.toggle('fa-chevron-down', !open);
            chevron.classList.toggle('fa-chevron-up', open);
            if (open && !log.dataset.loaded) {
                log.dataset.loaded = '1';
                var console_ = log.querySelector('[data-console]');
                console_.innerHTML = '<div class="gha-console-loading"><?= addslashes(t('admin_actions_loading')) ?>…</div>';
                // Forward e2e_token/e2e_gh_fixture from the page's own URL — the fixture gate
                // (public/includes/actions-dashboard.php's ghFixtureModeActive()) is checked
                // per-request, so a lazy AJAX call needs it too, not just the initial page load.
                var pageParams = new URLSearchParams(window.location.search);
                var fixtureQs = '';
                ['e2e_token', 'e2e_gh_fixture'].forEach(function (k) {
                    if (pageParams.has(k)) fixtureQs += '&' + k + '=' + encodeURIComponent(pageParams.get(k));
                });
                var url = 'admin-dashboards.php?tab=actions&ajax=run_jobs&run_id=' + encodeURIComponent(row.dataset.runId) + '&completed=' + row.dataset.completed + fixtureQs;
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.steps || !data.steps.length) {
                            console_.innerHTML = '<div class="gha-console-line" style="color:var(--text-muted)">—</div>';
                            return;
                        }
                        var html = '';
                        data.steps.forEach(function (s) {
                            var color = s.status === 'success' ? 'var(--status-success-light)'
                                : s.status === 'failure' ? 'var(--status-danger-light)'
                                : s.status === 'cancelled' ? 'var(--status-warning-light)'
                                : 'var(--text-muted)';
                            var icon = s.status === 'success' ? '✓' : s.status === 'failure' ? '✗' : s.status === 'cancelled' ? '■' : '–';
                            var dur = s.durationSec !== null ? '  (' + s.durationSec + 's)' : '';
                            html += '<div class="gha-console-line" style="color:' + color + '">' + icon + ' ' + escapeHtml(s.name) + dur + '</div>';
                        });
                        console_.innerHTML = html;
                    })
                    .catch(function () {
                        console_.innerHTML = '<div class="gha-console-line" style="color:var(--status-danger-light)"><?= addslashes(t('admin_actions_error_body')) ?></div>';
                    });
            }
        });
    });

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
});
</script>
