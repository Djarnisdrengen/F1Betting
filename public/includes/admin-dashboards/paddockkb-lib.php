<?php
// PaddockKB — shared helpers, required unconditionally from admin-dashboards.php (unlike the
// paddockkb.php tab partial, which only loads when ?tab=paddockkb) so Dashboards → Oversigt
// can call kbGetHealthSnapshot() regardless of which tab is active. See
// epics/Admin settings and dashboards/feature-4-paddockkb-dashboard.md.

function pkbFormatBytes(int $bytes): string {
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function pkbBuildRunView(array $run, string $lang, DateTimeImmutable $now): array {
    $status  = ghNormalizeRunStatus($run);
    $meta    = ghStatusMeta($status);
    $started = new DateTimeImmutable($run['run_started_at'] ?? $run['created_at']);
    $updated = new DateTimeImmutable($run['updated_at'] ?? ($run['run_started_at'] ?? $run['created_at']));
    $durationSec = $status === 'in_progress'
        ? max(0, $now->getTimestamp() - $started->getTimestamp())
        : max(0, $updated->getTimestamp() - $started->getTimestamp());
    return [
        'status'      => $status,
        'statusIcon'  => $meta['icon'],
        'statusColor' => $meta['color'],
        'statusLabel' => t('admin_actions_st_' . $status, $lang),
        'startedFull' => ghFormatCetFull($started, $lang),
        'ago'         => ghRelativeTime($started, $now, $lang),
        'duration'    => ghFormatDuration($durationSec),
    ];
}

// Composition point for Dashboards → Oversigt (Feature 2) — read-only, reuses the same
// ghListWorkflowRuns() call the PaddockKB tab itself uses for its "Sidste opdatering" card.
function kbGetHealthSnapshot(): array {
    $kbWf = ghWorkflowConfig()['kb-update'];
    $runs = ghListWorkflowRuns($kbWf['file'], 1);
    $lastStatus = !empty($runs) ? ghNormalizeRunStatus($runs[0]) : null;
    return [
        'healthy'  => $lastStatus !== 'failure',
        'flagCount'=> $lastStatus === 'failure' ? 1 : 0,
    ];
}
