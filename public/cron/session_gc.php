<?php
/**
 * Session-table garbage collection cron.
 * Runs hourly via GitHub Actions (.github/workflows/cron-session-gc.yml),
 * Bearer CRON_SECRET auth, modeled on cron/warm_actions_cache.php.
 *
 * DB-backed sessions (public/includes/session-handler.php) don't rely on PHP's
 * own probabilistic session.gc — that's exactly the kind of "not really
 * guaranteed" cleanup this change moved away from. This cron is the actual
 * cleanup path: it deletes rows past SESSION_ABSOLUTE_TIMEOUT so the sessions
 * table doesn't grow unbounded.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

$logFile = defined('SESSION_GC_LOG_FILE') ? SESSION_GC_LOG_FILE : __DIR__ . '/session_gc.log';

function logMessage($message) {
    global $logFile;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    logToFile($logFile, $message);
}

$bearer = getBearerToken();
if ($bearer === null || !hash_equals(CRON_SECRET, $bearer)) {
    $source = getTestSourceLabel();
    logMessage("Unauthorized access. Exiting." . ($source ? " (source: $source)" : ""));
    exit(1);
}

$db = getDB();
$stmt = $db->prepare("DELETE FROM sessions WHERE last_activity < ?");
$stmt->execute([time() - SESSION_ABSOLUTE_TIMEOUT]);

logMessage("Deleted " . $stmt->rowCount() . " expired session row(s).");
logMessage("Session GC complete.");
