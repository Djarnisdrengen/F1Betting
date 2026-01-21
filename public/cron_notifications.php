<?php
/**
 * Cron Job Script for F1 Betting Email Notifications
 * 
 * This script should be run every hour via cron:
 *   0 * * * * php /path/to/cron_notifications.php
 * 
 * It sends email notifications when:
 * - Betting window opens (configurable hours before race)
 * - Betting window closes soon (2 hours before race)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/smtp.php';
require_once __DIR__ . '/functions.php';

//***************************************** */
// Log file setup
//***************************************** */
$logFile = __DIR__ . '/cron_import_log.txt';

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

//***************************************** */
// Cron token validation
//***************************************** */
$tokenValid = false;

if (php_sapi_name() === 'cli') {
    global $argv;
    $tokenValid = isset($argv[1]) && $argv[1] === CRON_SECRET;
} else {
    $tokenValid = isset($_GET['token']) && $_GET['token'] === CRON_SECRET;
}

if (!$tokenValid) {
    logMessage("Unauthorized access. Exiting.");
    exit(1);
}


$db = getDB();
$settings = getSettings();
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;

// Get all users who want notifications (all active users for now)
$users = $db->query("SELECT * FROM users")->fetchAll();

// Get upcoming races
$races = $db->query("SELECT * FROM races WHERE result_p1 IS NULL ORDER BY race_date ASC")->fetchAll();

$now = time();
$appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';

foreach ($races as $race) {
    $raceDateTime = strtotime($race['race_date'] . ' ' . $race['race_time']);
    $bettingOpens = $raceDateTime - ($bettingWindowHours * 60 * 60);
    $bettingClosesWarning = $raceDateTime - (2 * 60 * 60); // 2 hours before
    
    // Check if betting just opened (within last hour)
    $hourAgo = $now - 3600;
    if ($bettingOpens > $hourAgo && $bettingOpens <= $now) {
        // Betting window just opened - notify all users
        echo "Betting opened for: {$race['name']}\n";
        
        foreach ($users as $user) {
            // Check if user already has a bet for this race
            $stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
            $stmt->execute([$user['id'], $race['id']]);
            if ($stmt->fetch()) continue; // Already has bet, skip
            
            sendBettingOpenEmail($user, $race, $bettingWindowHours);
        }
    }
    
    // Check if betting closes soon (within 2-3 hours)
    $threeHours = $now + (3 * 60 * 60);
    $twoHours = $now + (2 * 60 * 60);
    if ($raceDateTime > $twoHours && $raceDateTime <= $threeHours) {
        // Betting closes soon - notify users without bets
        echo "Betting closing soon for: {$race['name']}\n";
        
        foreach ($users as $user) {
            // Check if user already has a bet for this race
            $stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
            $stmt->execute([$user['id'], $race['id']]);
            if ($stmt->fetch()) continue; // Already has bet, skip
            
            sendBettingClosingEmail($user, $race);
        }
    }
}

echo "Notification check complete.\n";

/**
 * Send betting window open email
 */
function sendBettingOpenEmail($user, $race, $bettingWindowHours = 48) {
    global $appName;
    
    $name = $user['display_name'] ?: $user['email'];
    $raceDate = date('d M Y', strtotime($race['race_date']));
    $raceTime = substr($race['race_time'], 0, 5);
    $betLink = convertToEmailUrl(SITE_URL . "/bet.php?race=" . $race['id']);
    
    // Try Danish first, then English
    $subject = "Betting åbent: {$race['name']} - $appName";
    $greeting = "Hej $name!";
    $intro = "Betting er nu åbent for <strong>{$race['name']}</strong> ({$race['location']})!";
    $details = "Løbet starter: <strong>$raceDate kl. $raceTime</strong><br>Du har {$bettingWindowHours} timer til at placere dit bet.";
    $buttonText = "Placer dit bet nu";
    $footer = "Held og lykke!<br>$appName";
    
    $htmlContent = getEmailTemplate($greeting, "$intro<br><br>$details", $buttonText, $betLink, '', '', $footer, $appName);
    $textContent = "$greeting\n\n$intro\n\nLøbet starter: $raceDate kl. $raceTime\n\n$buttonText: $betLink";
    
    $result = sendEmail($user['email'], $subject, $htmlContent, $textContent);
    
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
    global $appName;
    
    $name = $user['display_name'] ?: $user['email'];
    $raceDate = date('d M Y', strtotime($race['race_date']));
    $raceTime = substr($race['race_time'], 0, 5);
    $betLink = convertToEmailUrl(SITE_URL . "/bet.php?race=" . $race['id']);
    
    $subject = "⏰ Sidste chance: {$race['name']} - $appName";
    $greeting = "Hej $name!";
    $intro = "Betting lukker snart for <strong>{$race['name']}</strong>!";
    $details = "Du har kun <strong>ca. 2 timer</strong> tilbage til at placere dit bet.<br>Løbet starter: $raceDate kl. $raceTime";
    $buttonText = "Placer dit bet NU";
    $footer = "Skynd dig!<br>$appName";
    
    $htmlContent = getEmailTemplate($greeting, "$intro<br><br>$details", $buttonText, $betLink, '', '', $footer, $appName);
    $textContent = "$greeting\n\n$intro\n\nDu har kun ca. 2 timer tilbage!\n\n$buttonText: $betLink";
    
    $result = sendEmail($user['email'], $subject, $htmlContent, $textContent);
    
    if ($result['success']) {
        echo "  - Sent closing notification to: {$user['email']}\n";
    } else {
        echo "  - FAILED to send to: {$user['email']} - {$result['message']}\n";
    }
}
