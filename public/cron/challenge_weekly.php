<?php
/**
 * Weekly Paddock Challenges cron — Perfect Week evaluation (REQ-405/406) and pending-
 * participant purge (REQ-110). Runs Monday 06:00 CET via GitHub Actions
 * (.github/workflows/cron-challenges.yml), Bearer CRON_SECRET auth, modeled on
 * cron/notifications.php.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/challenges.php';

$logFile = defined('CHALLENGE_WEEKLY_LOG_FILE') ? CHALLENGE_WEEKLY_LOG_FILE : __DIR__ . '/challenge_weekly.log';

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

$db = getDB();
$tz = new DateTimeZone('Europe/Copenhagen');

// Evaluate the *previous* ISO week — the cron runs Monday morning, after the week it scores
// has fully closed (REQ-402's Sunday 23:59:59 boundary). Computed from today rather than
// assumed to always run on a Monday, so a manual re-run mid-week still scores the same week.
$mondayThisWeek = (new DateTime('today', $tz))->modify('monday this week');
$mondayPrevWeek = (clone $mondayThisWeek)->modify('-7 days');
$sundayPrevWeek = (clone $mondayPrevWeek)->modify('+6 days');
$isoWeek        = isoWeekKey($mondayPrevWeek);

$qStmt = $db->prepare("
    SELECT id FROM challenge_trivia_questions
    WHERE status = 'published' AND publish_date BETWEEN ? AND ?
");
$qStmt->execute([$mondayPrevWeek->format('Y-m-d'), $sundayPrevWeek->format('Y-m-d')]);
$questionIds = $qStmt->fetchAll(PDO::FETCH_COLUMN);
$weekTotal   = count($questionIds);

if ($weekTotal > 0) {
    $placeholders = implode(',', array_fill(0, $weekTotal, '?'));
    $stmt = $db->prepare("
        SELECT participant_id
        FROM challenge_trivia_answers
        WHERE question_id IN ($placeholders)
        GROUP BY participant_id
        HAVING SUM(correct) = ?
    ");
    $stmt->execute(array_merge($questionIds, [$weekTotal]));
    $winners = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $awarded = 0;
    foreach ($winners as $participantId) {
        if (awardChallengePoints($db, $participantId, 'trivia', 20, "trivia_week:$isoWeek")) {
            $awarded++;
        }
    }
    logMessage("ISO week $isoWeek: $weekTotal questions, " . count($winners) . " perfect, $awarded newly awarded.");
} else {
    // Plain ASCII only — this response has no explicit charset header, so a non-ASCII
    // character here risks being read back as Latin-1 by a client that doesn't assume UTF-8.
    logMessage("ISO week $isoWeek: no published questions, skipped.");
}

// GDPR hygiene (REQ-110): participants that never verified, 30+ days old. Cascades to their
// answers/points/tokens via each child table's ON DELETE CASCADE.
$purgeStmt = $db->prepare("DELETE FROM challenge_participants WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$purgeStmt->execute();
logMessage("Purged {$purgeStmt->rowCount()} abandoned pending participant(s).");

logMessage("Weekly challenge cron complete.");
