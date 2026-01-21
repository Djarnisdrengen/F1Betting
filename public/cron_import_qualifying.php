<?php 
/**
 * F1 Qualifying Results Auto-Import (Debug + Logging)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

//***************************************** */
// Log file setup from config.php
//***************************************** */
if (!defined('CRON_LOG_FILE')) {
    die("CRON_LOG_FILE is not defined in config.php");
}
$logFile = CRON_LOG_FILE;

//***************************************** */
// Logging function
//***************************************** */
function logMessage($message) {
    global $logFile;

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message";

    // Detect if running in CLI or browser
    if (php_sapi_name() === 'cli') {
        // CLI: use newline
        echo $line . PHP_EOL;
    } else {
        // Browser: use HTML line break
        echo nl2br($line . "\n");
    }

    // Always write to log file with newline
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

//***************************************** */
// Test mode via URL or CLI argument
//***************************************** */
$TEST_MODE = false;

if (php_sapi_name() !== 'cli') {
    $TEST_MODE = isset($_GET['test']) && $_GET['test'] === 'true';
} else {
    global $argv;
    foreach ($argv as $arg) {
        if ($arg === '--test') {
            $TEST_MODE = true;
            break;
        }
    }
}

// Always log entering test mode or live mode
logMessage("[DEBUG] Script running in " . ($TEST_MODE ? "TEST MODE" : "LIVE MODE"));

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

logMessage("[DEBUG] Cron token validation: " . ($tokenValid ? "VALID" : "INVALID"));

if (!$tokenValid && !$TEST_MODE) {
    logMessage("[ERROR] Unauthorized access. Exiting.");
    exit(1);
}

//***************************************** */
// Time window validation: only run 06-23
//***************************************** */
$hour = (int)date('H');
logMessage("[DEBUG] Current hour: $hour");

if (!$TEST_MODE && ($hour < 6 || $hour > 23)) {
    logMessage("[INFO] Outside allowed time window (06:00-23:59). Exiting.");
    exit(0);
}

//***************************************** */
// Database connection
//***************************************** */
$db = getDB();
logMessage("[DEBUG] Database connection established");

//***************************************** */
// Main import logic
//***************************************** */
$currentYear = date('Y');
logMessage("[DEBUG] Fetching qualifying results for season $currentYear...");

// Fetch data from API or stub
if ($TEST_MODE) {
    // Load stub data from separate file
    $data = require __DIR__ . '/f1_testdata.php';
    logMessage("[TEST MODE] Loaded stub F1 data with " . count($data['MRData']['RaceTable']['Races']) . " races");
} else {
    $data = fetchF1Api("/$currentYear/qualifying");
}

if (!$data || !isset($data['MRData']['RaceTable']['Races'])) {
    logMessage("[INFO] No qualifying data available.");
    exit(1);
}

$races = $data['MRData']['RaceTable']['Races'];

if (empty($races)) {
    logMessage("[INFO] No races with qualifying results found.");
    exit(0);
}

$importedCount = 0;

foreach ($races as $raceData) {
    $raceName = $raceData['raceName'];
    $raceDate = $raceData['date'];
    $round = $raceData['round'];

    logMessage("\n[INFO] Processing: $raceName (Round $round, $raceDate)");

    if (!isset($raceData['QualifyingResults']) || count($raceData['QualifyingResults']) < 3) {
        logMessage("[INFO] No qualifying results yet for this race.");
        continue;
    }

    // Find the race in DB
    $race = findRace($db, $raceName, $raceDate);
    if (!$race) {
        logMessage("[INFO] Race not found in database or already has qualifying results.");
        continue;
    }

    $qualiResults = $raceData['QualifyingResults'];
    $driversImported = [];

    // Loop through P1, P2, P3
    foreach (['P1', 'P2', 'P3'] as $i => $pos) {
        $driverData = $qualiResults[$i];
        $driverId = findDriverId(
            $db,
            $driverData['Driver']['code'] ?? '',
            $driverData['Driver']['givenName'] . ' ' . $driverData['Driver']['familyName'],
            $driverData['Driver']['permanentNumber'] ?? null
        );
        $driversImported[$pos] = [
            'name' => $driverData['Driver']['familyName'],
            'id' => $driverId
        ];
    }

    // Skip if any driver not found
    if (!$driversImported['P1']['id'] || !$driversImported['P2']['id'] || !$driversImported['P3']['id']) {
        logMessage("[WARN] Could not find all drivers in database, skipping race.");
        logMessage(print_r($driversImported, true));
        continue;
    }

    // Update race qualifying results
    $stmt = $db->prepare("UPDATE races SET quali_p1 = ?, quali_p2 = ?, quali_p3 = ? WHERE id = ?");
    $result = $stmt->execute([
        $driversImported['P1']['id'],
        $driversImported['P2']['id'],
        $driversImported['P3']['id'],
        $race['id']
    ]);

    if ($result) {
        logMessage("[SUCCESS] Updated qualifying results for {$race['name']} (ID {$race['id']})");
        foreach ($driversImported as $pos => $driver) {
            logMessage("    $pos: {$driver['name']} (ID {$driver['id']})");
        }
        $importedCount++;
    } else {
        logMessage("[ERROR] Failed to update database for race {$race['name']} (ID {$race['id']})");
    }
}

logMessage("\n=== Update Complete ===");
logMessage("Total races updated: $importedCount");

//***************************************** */
// END OF Main import logic
//***************************************** */


//***************************************** */
// Fetch data from F1 API (stubbed for test mode)
//***************************************** */
function fetchF1Api($endpoint) {
    global $TEST_MODE;

    logMessage("[DEBUG] fetchF1Api called with endpoint: $endpoint, TEST_MODE=" . ($TEST_MODE ? "true" : "false"));

    // Test mode: return stub data
    if ($TEST_MODE) {
        logMessage("[DEBUG] Returning stub data for endpoint: $endpoint");

        return [
            'MRData' => [
                'RaceTable' => [
                    'Races' => [
                        [
                            'raceName' => 'Australian Grand Prix',
                            'date' => '2026-03-08',
                            'round' => 1,
                            'QualifyingResults' => [
                                [
                                    'Driver' => [
                                        'givenName' => 'Lewis',
                                        'familyName' => 'Hamilton',
                                        'code' => 'HAM',
                                        'permanentNumber' => 44
                                    ]
                                ],
                                [
                                    'Driver' => [
                                        'givenName' => 'Max',
                                        'familyName' => 'Verstappen',
                                        'code' => 'VER',
                                        'permanentNumber' => 33
                                    ]
                                ],
                                [
                                    'Driver' => [
                                        'givenName' => 'Charles',
                                        'familyName' => 'Leclerc',
                                        'code' => 'LEC',
                                        'permanentNumber' => 16
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    // Live mode: fetch from actual API
    $url = F1_API_BASE . $endpoint . '.json';
    logMessage("[DEBUG] Fetching URL: $url");

    $context = stream_context_create([
        'http' => [
            'timeout' => F1_API_TIMEOUT,
            'header' => "User-Agent: F1Betting/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        logMessage("[ERROR] Failed to fetch from API: $url");
        return null;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("[ERROR] Invalid JSON response: " . json_last_error_msg());
        return null;
    }

    logMessage("[DEBUG] API fetch successful for endpoint: $endpoint");
    return $data;
}


//***************************************** */
// Helper functions with added debug logging
//***************************************** */
function findDriverId($db, $driverCode, $driverName, $permanentNumber) {
    logMessage("[DEBUG] Searching for driver: Name='$driverName', Code='$driverCode', Number='$permanentNumber'");

    $nameParts = explode(' ', $driverName);
    $lastName = end($nameParts);

    $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
    $stmt->execute(['%' . $lastName . '%']);
    $driver = $stmt->fetch();

    if ($driver) {
        logMessage("[DEBUG] Found driver by last name: $driverName -> ID {$driver['id']}");
        return $driver['id'];
    }

    if ($permanentNumber) {
        $stmt = $db->prepare("SELECT id FROM drivers WHERE number = ?");
        $stmt->execute([$permanentNumber]);
        $driver = $stmt->fetch();

        if ($driver) {
            logMessage("[DEBUG] Found driver by number: $driverName (#$permanentNumber) -> ID {$driver['id']}");
            return $driver['id'];
        }
    }

    logMessage("[WARN] Driver not found: $driverName (#$permanentNumber)");
    return null;
}

function findRace($db, $raceName, $raceDate) {
    logMessage("[DEBUG] Searching for race: Name='$raceName', Date='$raceDate'");

    $stmt = $db->prepare("SELECT * FROM races WHERE race_date = ? AND (quali_p1 IS NULL OR quali_p1 = '')");
    $stmt->execute([$raceDate]);
    $race = $stmt->fetch();

    if ($race) {
        logMessage("[DEBUG] Race found by exact date: {$race['name']} (ID {$race['id']})");
        return $race;
    }

    $stmt = $db->prepare("
        SELECT * FROM races 
        WHERE race_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
        AND (quali_p1 IS NULL OR quali_p1 = '')
    ");
    $stmt->execute([$raceDate, $raceDate]);
    $race = $stmt->fetch();

    if ($race) {
        logMessage("[DEBUG] Race found by date range: {$race['name']} (ID {$race['id']})");
    } else {
        logMessage("[WARN] Race not found in DB for date $raceDate: $raceName");
    }

    return $race;
}
