<?php
// Lazy per-run step fetch for the GitHub Actions tab (moved from the former
// admin-actions.php's inline ?ajax=run_jobs branch). Reached only via
// admin-dashboards.php's early ?tab=actions&ajax=run_jobs check, before any HTML output.
header('Content-Type: application/json');
$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid run_id']);
    return;
}
$lang = getLang();
$jobs = ghListRunJobs($runId, ($_GET['completed'] ?? '1') === '1');
$steps = [];
foreach ($jobs as $job) {
    foreach (($job['steps'] ?? []) as $step) {
        // A job step has the same status/conclusion shape as a run — reuse the run normalizer.
        $status = ghNormalizeRunStatus(['status' => $step['status'], 'conclusion' => $step['conclusion'] ?? null]);
        $durationSec = null;
        if (!empty($step['started_at']) && !empty($step['completed_at'])) {
            $durationSec = max(0, (new DateTimeImmutable($step['completed_at']))->getTimestamp()
                - (new DateTimeImmutable($step['started_at']))->getTimestamp());
        }
        $steps[] = [
            'name'        => $step['name'],
            'status'      => $status,
            'durationSec' => $durationSec,
            'label'       => t('admin_actions_st_' . $status, $lang),
        ];
    }
}
echo json_encode(['steps' => $steps, 'error' => !empty($GLOBALS['ghFetchError'])]);
