<?php
/**
 * Cache-warming cron for the GitHub Actions Dashboard (Dashboards → GitHub Actions / Oversigt).
 * Runs every 5 minutes via GitHub Actions (.github/workflows/cron-warm-actions-cache.yml),
 * Bearer CRON_SECRET auth, modeled on cron/notifications.php and cron/challenge_weekly.php.
 *
 * Refetches every configured workflow's recent runs and rewrites public/cache/github-actions/,
 * so the on-demand page loads in admin-dashboards.php (Oversigt's ghGetHealthSnapshot() and the
 * Actions tab itself) almost always read an already-warm cache instead of paying for the GitHub
 * API round trip themselves.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/actions-dashboard.php';

$logFile = defined('WARM_ACTIONS_CACHE_LOG_FILE') ? WARM_ACTIONS_CACHE_LOG_FILE : __DIR__ . '/warm_actions_cache.log';

function logMessage($message) {
    global $logFile;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    logToFile($logFile, $message);
}

$bearer = getBearerToken();
if ($bearer === null || !hash_equals(CRON_SECRET, $bearer)) {
    logMessage("Unauthorized access. Exiting.");
    exit(1);
}

$runsByFile = ghWarmAllWorkflowRunCaches();
$emptyFiles = [];
foreach ($runsByFile as $file => $runs) {
    if (empty($runs)) {
        $emptyFiles[] = $file;
    }
}

logMessage("Warmed " . count($runsByFile) . " workflow run cache(s).");
if (!empty($emptyFiles)) {
    // Empty here means either a workflow with a genuinely empty run history (e.g. a manual-only
    // workflow that's never been dispatched) or a fetch failure — the two aren't distinguished at
    // this layer (ghCachedBatch()'s stale-fallback already logs actual fetch errors separately
    // via error_log()). Not itself a problem to alert on; informational only.
    logMessage("No runs on record for: " . implode(', ', $emptyFiles) . ".");
}

logMessage("Actions cache warm complete.");
