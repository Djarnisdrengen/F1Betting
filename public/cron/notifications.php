<?php
/**
 * Cron Job Script for F1 Betting Email Notifications
 *
 * This script should be run every hour via cron:
 *   0 * * * * php /home/dit-brugernavn/public_html/f1/cron/notifications.php
 *
 * It sends email notifications when:
 * - Betting window opens (configurable hours before race)
 * - Betting window closes soon (2 hours before race)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/smtp.php';
require_once __DIR__ . '/../includes/functions.php';

//***************************************** */
// Log file setup
//***************************************** */
$logFile = defined('CRON_NOTIFICATIONS_LOG_FILE') ? CRON_NOTIFICATIONS_LOG_FILE : __DIR__ . '/cron_notifications.log';

function logMessage($message) {
    global $logFile;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    logToFile($logFile, $message);
}

//***************************************** */
// Test mode — skips actual SMTP, token still required
//***************************************** */
$TEST_MODE = false;
if (php_sapi_name() !== 'cli') {
    $TEST_MODE = isset($_GET['test']) && $_GET['test'] === 'true';
} else {
    global $argv;
    foreach ($argv as $arg) {
        if ($arg === '--test') { $TEST_MODE = true; break; }
    }
}

//***************************************** */
// Cron token validation
//***************************************** */
$tokenValid = false;

if (php_sapi_name() === 'cli') {
    global $argv;
    $tokenValid = isset($argv[1]) && hash_equals(CRON_SECRET, $argv[1]);
} else {
    // F6 TEMPORARY SHIM: accepts the token via the Authorization header (new,
    // preferred — getBearerToken()) OR the legacy ?token= query string while
    // Simply.com's control-panel cron entry is migrated to GitHub Actions. Delete
    // the ?token= branch once that entry is removed — see security-findings-remaining.md F6.
    $bearer = getBearerToken();
    $tokenValid = ($bearer !== null && hash_equals(CRON_SECRET, $bearer))
        || (isset($_GET['token']) && hash_equals(CRON_SECRET, $_GET['token']));
}

if (!$tokenValid) {
    logMessage("Unauthorized access. Exiting.");
    exit(1);
}


$db = getDB();
$settings = getSettings();
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;

// Only notify users who are actively participating in the competition
$users = $db->query("SELECT * FROM users WHERE in_competition = 1")->fetchAll();

// Get upcoming races
$races = $db->query("SELECT * FROM races WHERE result_p1 IS NULL ORDER BY race_date ASC")->fetchAll();

// Batch-fetch all existing bets for upcoming races (avoids N+1 in the loops below)
$existingBets = [];
if ($races) {
    $raceIds = array_column($races, 'id');
    $ph = implode(',', array_fill(0, count($raceIds), '?'));
    $betsStmt = $db->prepare("SELECT user_id, race_id FROM bets WHERE race_id IN ($ph)");
    $betsStmt->execute($raceIds);
    foreach ($betsStmt->fetchAll() as $row) {
        $existingBets[$row['user_id']][$row['race_id']] = true;
    }
}

$now     = time();
$appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
$lang    = getLang(); // 'da' in cron context (no session)

// Non-competing registered users and pending invites — fetched once, used for pool reminders
$nonCompetingUsers = $db->query("SELECT email, display_name, language FROM users WHERE in_competition = 0 AND role = 'user'")->fetchAll();
$pendingInvites    = $db->query("SELECT email, token FROM invites WHERE used = 0 AND expires_at > NOW()")->fetchAll();

if ($TEST_MODE) {
    echo "[DEBUG] now=" . date('Y-m-d H:i:s', $now) . " | window={$bettingWindowHours}h | races=" . count($races) . " | competing=" . count($users) . " | non-competing=" . count($nonCompetingUsers) . " | invites=" . count($pendingInvites) . "\n";
    foreach ($races as $r) {
        $rDT     = strtotime($r['race_date'] . ' ' . $r['race_time']);
        $rOpens  = $rDT - ($bettingWindowHours * 60 * 60);
        $rDiff   = $rOpens - $now;
        echo "[DEBUG] race={$r['name']} | raceAt=" . date('Y-m-d H:i:s', $rDT) . " | bettingOpens=" . date('Y-m-d H:i:s', $rOpens) . " | diff={$rDiff}s\n";
    }
}

foreach ($races as $race) {
    $raceDateTime = strtotime($race['race_date'] . ' ' . $race['race_time']);
    $bettingOpens = $raceDateTime - ($bettingWindowHours * 60 * 60);

    // Check if betting just opened (within last hour)
    $hourAgo = $now - 3600;
    if ($bettingOpens > $hourAgo && $bettingOpens <= $now) {
        echo "Betting opened for: {$race['name']}\n";

        foreach ($users as $user) {
            if (isset($existingBets[$user['id']][$race['id']])) continue;
            sendBettingOpenEmail($user, $race, $bettingWindowHours);
        }

        // Notify non-competing registered users and people with pending invites
        foreach ($nonCompetingUsers as $u) {
            $link     = convertToEmailUrl(SITE_URL . '/leaderboard.php');
            $userLang = in_array($u['language'] ?? '', ['da', 'en']) ? $u['language'] : 'da';
            sendPoolReminderEmail($u['email'], $u['display_name'] ?: $u['email'], $race, $link, $userLang);
        }
        foreach ($pendingInvites as $invite) {
            $link = convertToEmailUrl(SITE_URL . '/register.php?token=' . $invite['token']);
            sendPoolReminderEmail($invite['email'], $invite['email'], $race, $link, 'da', 'invite');
        }
    }

    // Check if betting closes soon (within 2-3 hours)
    $threeHours = $now + (3 * 60 * 60);
    $twoHours = $now + (2 * 60 * 60);
    if ($raceDateTime > $twoHours && $raceDateTime <= $threeHours) {
        echo "Betting closing soon for: {$race['name']}\n";

        foreach ($users as $user) {
            if (isset($existingBets[$user['id']][$race['id']])) continue;
            sendBettingClosingEmail($user, $race);
        }
    }
}

echo "Notification check complete.\n";

/**
 * Send pool-size reminder to non-competing users and people with a pending invite.
 * Registered-but-not-competing users get a link to the leaderboard;
 * pending invitees get their personal registration link.
 */
function sendPoolReminderEmail($email, $name, $race, $ctaLink, $userLang = 'da', $type = 'noncompeting') {
    global $appName, $TEST_MODE;

    $poolSize = (int)$race['bettingpool_size'];
    $raceDate = date('d M Y', strtotime($race['race_date']));
    $raceTime = substr($race['race_time'], 0, 5);
    $keyPfx   = "email_pool_{$type}";

    $subject  = sprintf(t("{$keyPfx}_subject", $userLang), $poolSize);
    $greeting = sprintf(t("{$keyPfx}_greeting", $userLang), $name);
    $intro    = sprintf(t("{$keyPfx}_intro", $userLang), $race['name'], $race['location']);
    $body     = sprintf(t("{$keyPfx}_body", $userLang), $poolSize, $raceDate, $raceTime);
    $button   = t("{$keyPfx}_button", $userLang);

    if ($TEST_MODE) {
        echo "  - [pool] {$poolSize}\n";
        echo "  - [cta] {$ctaLink}\n";
        echo "  - [lang] {$userLang}\n";
        $result = ['success' => true, 'message' => 'test mode'];
    } else {
        $html   = getEmailTemplate($greeting, "$intro<br><br>$body", $button, $ctaLink, '', '', $appName, $appName);
        $text   = "$greeting\n\n$intro\n\n" . sprintf(t("{$keyPfx}_body_text", $userLang), $poolSize, $raceDate, $raceTime) . "\n\n$button: $ctaLink";
        $result = sendEmail($email, $subject, $html, $text);
    }

    if ($result['success']) {
        echo "  - Sent pool reminder to: {$email}\n";
    } else {
        echo "  - FAILED pool reminder to: {$email} - {$result['message']}\n";
    }
}

/**
 * Send betting window open email
 */
function sendBettingOpenEmail($user, $race, $bettingWindowHours = 48) {
    global $appName, $TEST_MODE;

    $userLang = in_array($user['language'] ?? '', ['da', 'en']) ? $user['language'] : 'da';
    $name     = $user['display_name'] ?: $user['email'];
    $raceDate = date('d M Y', strtotime($race['race_date']));
    $raceTime = substr($race['race_time'], 0, 5);
    $betLink  = convertToEmailUrl(SITE_URL . "/bet.php?race=" . $race['id']);
    $poolSize = (int)$race['bettingpool_size'];

    $subject    = sprintf(t('email_betting_open_subject', $userLang), $race['name']);
    $greeting   = sprintf(t('email_betting_open_greeting', $userLang), $name);
    $intro      = sprintf(t('email_betting_open_intro', $userLang), $race['name'], $race['location']);
    $poolLine   = $poolSize > 0 ? sprintf(t('email_betting_open_pool', $userLang), $poolSize) : '';
    $details    = sprintf(t('email_betting_open_details', $userLang), $raceDate, $raceTime, $bettingWindowHours);
    $buttonText = t('email_betting_open_button', $userLang);
    $footer     = sprintf(t('email_betting_open_footer', $userLang), $appName);

    if ($TEST_MODE) {
        echo "  - [race] {$race['name']}\n";
        echo "  - [window] {$bettingWindowHours}h\n";
        echo "  - [pool] {$poolSize}\n";
        echo "  - [cta] {$betLink}\n";
        echo "  - [lang] {$userLang}\n";
        $result = ['success' => true, 'message' => 'test mode'];
    } else {
        $htmlContent = getEmailTemplate($greeting, "$intro<br><br>{$poolLine}{$details}", $buttonText, $betLink, '', '', $footer, $appName);
        $poolText    = $poolSize > 0 ? sprintf(t('email_betting_open_pool_text', $userLang), $poolSize) . "\n" : '';
        $startsText  = sprintf(t('email_betting_open_starts_text', $userLang), $raceDate, $raceTime);
        $textContent = "$greeting\n\n$intro\n\n{$poolText}{$startsText}\n\n$buttonText: $betLink";
        $result      = sendEmail($user['email'], $subject, $htmlContent, $textContent);
    }

    if ($result['success']) {
        echo "  - Sent open notification to: {$user['email']}\n";
    } else {
        echo "  - FAILED to send to: {$user['email']} - {$result['message']}\n";
    }
}

/**
 * Send betting closing soon email
 */
function sendBettingClosingEmail($user, $race) {
    global $appName, $TEST_MODE;

    $userLang = in_array($user['language'] ?? '', ['da', 'en']) ? $user['language'] : 'da';
    $name     = $user['display_name'] ?: $user['email'];
    $raceDate = date('d M Y', strtotime($race['race_date']));
    $raceTime = substr($race['race_time'], 0, 5);
    $betLink  = convertToEmailUrl(SITE_URL . "/bet.php?race=" . $race['id']);

    $subject    = sprintf(t('email_betting_closing_subject', $userLang), $race['name']);
    $greeting   = sprintf(t('email_betting_closing_greeting', $userLang), $name);
    $intro      = sprintf(t('email_betting_closing_intro', $userLang), $race['name']);
    $details    = sprintf(t('email_betting_closing_details', $userLang), $raceDate, $raceTime);
    $buttonText = t('email_betting_closing_button', $userLang);
    $footer     = sprintf(t('email_betting_closing_footer', $userLang), $appName);

    if ($TEST_MODE) {
        echo "  - [race] {$race['name']}\n";
        echo "  - [cta] {$betLink}\n";
        echo "  - [lang] {$userLang}\n";
        $result = ['success' => true, 'message' => 'test mode'];
    } else {
        $htmlContent = getEmailTemplate($greeting, "$intro<br><br>$details", $buttonText, $betLink, '', '', $footer, $appName);
        $textContent = "$greeting\n\n$intro\n\n" . t('email_betting_closing_time_text', $userLang) . "\n" . sprintf(t('email_betting_closing_starts_text', $userLang), $raceDate, $raceTime) . "\n\n$buttonText: $betLink";
        $result      = sendEmail($user['email'], $subject, $htmlContent, $textContent);
    }

    if ($result['success']) {
        echo "  - Sent closing notification to: {$user['email']}\n";
    } else {
        echo "  - FAILED to send to: {$user['email']} - {$result['message']}\n";
    }
}
