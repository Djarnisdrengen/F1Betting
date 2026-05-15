<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/scoring.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = getDB();

$e2eUserEmail   = 'e2e_testing_testuser_f1@helvegpovlsen.dk';
$e2eInviteEmail = 'e2e_testing_invite_f1@helvegpovlsen.dk';

// Action: create_e2e_user — idempotent, used by admin e2e tests
if (($_GET['action'] ?? '') === 'create_e2e_user') {
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")
       ->execute([$e2eUserEmail]);
    $db->prepare("DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")
       ->execute([$e2eUserEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")
       ->execute([$e2eUserEmail]);
    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    $hash = hashPassword('E2ETestPassword2026!');
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES (?, ?, ?, 'E2E Test User', 'user', 0, 0, 0)")
       ->execute([$id, $e2eUserEmail, $hash]);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: cleanup_e2e_invite — removes stale invite from a failed test run
if (($_GET['action'] ?? '') === 'cleanup_e2e_invite') {
    $db->prepare("DELETE FROM invites WHERE email = ?")
       ->execute([$e2eInviteEmail]);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: cleanup_e2e_user — removes the e2e test user, used by profile spec afterAll
if (($_GET['action'] ?? '') === 'cleanup_e2e_user') {
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")
       ->execute([$e2eUserEmail]);
    $db->prepare("DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")
       ->execute([$e2eUserEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")
       ->execute([$e2eUserEmail]);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: seed_betting_race — in-competition user + open race (race_date 2h from now)
// Returns: { ok, raceId, email, password, drivers: [{id, name}] }
if (($_GET['action'] ?? '') === 'seed_betting_race') {
    $e2eBetEmail = 'e2e_bet_user_f1@helvegpovlsen.dk';

    // Idempotent cleanup
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eBetEmail]);
    $db->prepare("DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eBetEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eBetEmail]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Open Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Open Race'");

    // Ensure 3 known drivers exist
    $driverDefs = [
        [44, 'Lewis Hamilton',  'Mercedes'],
        [1,  'Max Verstappen',  'Red Bull'],
        [16, 'Charles Leclerc', 'Ferrari'],
    ];
    $driverIds = [];
    foreach ($driverDefs as [$num, $fullName, $team]) {
        $parts    = explode(' ', $fullName);
        $lastName = end($parts);
        $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute(['%' . $lastName . '%']);
        $row = $stmt->fetch();
        if ($row) {
            $driverIds[] = ['id' => $row['id'], 'name' => $fullName];
        } else {
            $newId = seed_uuid();
            $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
               ->execute([$newId, $fullName, $team, $num]);
            $driverIds[] = ['id' => $newId, 'name' => $fullName];
        }
    }

    // User with in_competition = 1
    $userId = seed_uuid();
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES (?, ?, ?, 'E2E Bet User', 'user', 1, 0, 0)")
       ->execute([$userId, $e2eBetEmail, hashPassword('E2EBetPassword2026!')]);

    // Guarantee the betting window is open: reset to 48h so a race 2h away is always within it
    $db->query("UPDATE settings SET betting_window_hours = 48 WHERE id = 1");

    // Race 2 hours from now — open under a 48h window
    $raceDate = (new DateTime('+2 hours'))->format('Y-m-d');
    $raceTime = (new DateTime('+2 hours'))->format('H:i:s');
    $raceId   = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Open Race', 'Test Circuit', ?, ?, 0)")
       ->execute([$raceId, $raceDate, $raceTime]);

    echo json_encode([
        'ok'      => true,
        'raceId'  => $raceId,
        'email'   => $e2eBetEmail,
        'password' => 'E2EBetPassword2026!',
        'drivers' => $driverIds,
    ]);
    exit;
}

// Action: cleanup_betting_race
if (($_GET['action'] ?? '') === 'cleanup_betting_race') {
    $e2eBetEmail = 'e2e_bet_user_f1@helvegpovlsen.dk';
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eBetEmail]);
    $db->prepare("DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eBetEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eBetEmail]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Open Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Open Race'");
    echo json_encode(['ok' => true]);
    exit;
}

// Action: seed_register_invite — creates invite for registration flow test
// Returns: { ok, token, email }
if (($_GET['action'] ?? '') === 'seed_register_invite') {
    $e2eRegEmail = 'e2e_register_f1@helvegpovlsen.dk';

    // Idempotent cleanup
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eRegEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eRegEmail]);
    $db->prepare("DELETE FROM invites WHERE email = ?")->execute([$e2eRegEmail]);

    $adminRow = $db->prepare("SELECT id FROM users WHERE email = ?");
    $adminRow->execute([F1_ADMIN_EMAIL]);
    $admin = $adminRow->fetch();
    if (!$admin) {
        echo json_encode(['ok' => false, 'error' => 'Admin user not found']);
        exit;
    }

    $token     = bin2hex(random_bytes(16));
    $expiresAt = (new DateTime('+48 hours'))->format('Y-m-d H:i:s');
    $db->prepare("INSERT INTO invites (email, token, created_by, expires_at) VALUES (?, ?, ?, ?)")
       ->execute([$e2eRegEmail, $token, $admin['id'], $expiresAt]);

    echo json_encode(['ok' => true, 'token' => $token, 'email' => $e2eRegEmail]);
    exit;
}

// Action: cleanup_register — removes registered test user and any remaining invite
if (($_GET['action'] ?? '') === 'cleanup_register') {
    $e2eRegEmail = 'e2e_register_f1@helvegpovlsen.dk';
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eRegEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eRegEmail]);
    $db->prepare("DELETE FROM invites WHERE email = ?")->execute([$e2eRegEmail]);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: seed_cron_qualifying — idempotent, used by cron e2e tests
// Ensures Hamilton/Verstappen/Leclerc drivers exist and adds Australian Grand Prix on 2026-03-08 with no quali results
if (($_GET['action'] ?? '') === 'seed_cron_qualifying') {
    foreach ([
        [44, 'Lewis Hamilton',  'Mercedes'],
        [1,  'Max Verstappen',  'Red Bull'],
        [16, 'Charles Leclerc', 'Ferrari'],
    ] as [$num, $name, $team]) {
        $parts = explode(' ', $name);
        $lastName = end($parts);
        $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute(['%' . $lastName . '%']);
        if (!$stmt->fetch()) {
            $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
               ->execute([seed_uuid(), $name, $team, $num]);
        }
    }
    $db->prepare("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = ? AND race_date = ?)")
       ->execute(['Australian Grand Prix', '2026-03-08']);
    $db->prepare("DELETE FROM races WHERE name = ? AND race_date = ?")
       ->execute(['Australian Grand Prix', '2026-03-08']);
    $db->prepare("INSERT INTO races (id, name, race_date, bettingpool_size) VALUES (?, ?, ?, 0)")
       ->execute([seed_uuid(), 'Australian Grand Prix', '2026-03-08']);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: cleanup_cron_qualifying — removes race created by seed_cron_qualifying
if (($_GET['action'] ?? '') === 'cleanup_cron_qualifying') {
    $db->prepare("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = ? AND race_date = ?)")
       ->execute(['Australian Grand Prix', '2026-03-08']);
    $db->prepare("DELETE FROM races WHERE name = ? AND race_date = ?")
       ->execute(['Australian Grand Prix', '2026-03-08']);
    echo json_encode(['ok' => true]);
    exit;
}

// Action: seed_reset_result — creates a scored race so the reset-result feature can be tested
if (($_GET['action'] ?? '') === 'seed_reset_result') {
    $e2eResetUser = 'e2e_reset_race_f1@helvegpovlsen.dk';

    // Idempotent cleanup
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eResetUser]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name IN ('E2E Reset Race', 'E2E Next Race'))");
    $db->query("DELETE FROM races WHERE name IN ('E2E Reset Race', 'E2E Next Race')");
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eResetUser]);

    // Ensure Hamilton / Verstappen / Leclerc drivers exist
    $driverIds = [];
    foreach ([
        [44, 'Hamilton',   'Lewis Hamilton',   'Mercedes'],
        [1,  'Verstappen', 'Max Verstappen',   'Red Bull'],
        [16, 'Leclerc',    'Charles Leclerc',  'Ferrari'],
    ] as [$num, $lastName, $fullName, $team]) {
        $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute(['%' . $lastName . '%']);
        $row = $stmt->fetch();
        if ($row) {
            $driverIds[] = $row['id'];
        } else {
            $newId = seed_uuid();
            $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")->execute([$newId, $fullName, $team, $num]);
            $driverIds[] = $newId;
        }
    }
    [$hamId, $verId, $lecId] = $driverIds;

    // Test user (in competition so pool calc includes them)
    $userId = seed_uuid();
    $hash = hashPassword('E2ETestPassword2026!');
    $db->prepare("INSERT INTO users (id, email, password, display_name, in_competition, points, stars) VALUES (?, ?, ?, 'E2E Reset User', 1, 0, 0)")
       ->execute([$userId, $e2eResetUser, $hash]);

    // Past race (2026-05-15) with pool 30 — will become the last completed race
    // Results are set in the INSERT so result_p1 IS NOT NULL, matching how admin.php saves them
    $raceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size, result_p1, result_p2, result_p3) VALUES (?, 'E2E Reset Race', 'Test', '2026-05-15', '14:00:00', 30, ?, ?, ?)")
       ->execute([$raceId, $hamId, $verId, $lecId]);

    // Future race (2026-06-15) — acts as next race for pool rollover
    $nextRaceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Next Race', 'Test', '2026-06-15', '14:00:00', 0)")
       ->execute([$nextRaceId]);

    // Bet: p1=Hamilton (correct), p2=Leclerc (wrong pos), p3=Verstappen (wrong pos) → 35 pts, 0 stars, no perfect
    $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3, points, is_perfect) VALUES (?, ?, ?, ?, ?, ?, 0, 0)")
       ->execute([seed_uuid(), $userId, $raceId, $hamId, $lecId, $verId]);

    // Score the race: results p1=Hamilton, p2=Verstappen, p3=Leclerc
    calculateRacePoints($raceId, $hamId, $verId, $lecId);

    $stmt = $db->prepare("SELECT points, stars FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userAfter = $stmt->fetch();

    echo json_encode(['ok' => true, 'points' => (int)$userAfter['points'], 'stars' => (int)$userAfter['stars']]);
    exit;
}

// Action: cleanup_reset_result — removes data created by seed_reset_result
if (($_GET['action'] ?? '') === 'cleanup_reset_result') {
    $e2eResetUser = 'e2e_reset_race_f1@helvegpovlsen.dk';
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eResetUser]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name IN ('E2E Reset Race', 'E2E Next Race'))");
    $db->query("DELETE FROM races WHERE name IN ('E2E Reset Race', 'E2E Next Race')");
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eResetUser]);
    echo json_encode(['ok' => true]);
    exit;
}

// Reset to known state — settings table is preserved
$db->query("UPDATE settings SET bet_size = 10");
$db->query("DELETE FROM bets");

// Preserve f1_admin service account across the wipe
$adminStmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$adminStmt->execute([F1_ADMIN_EMAIL]);
$adminRow = $adminStmt->fetch() ?: null;

$db->query("DELETE FROM users");
$db->query("DELETE FROM drivers");
$db->query("DELETE FROM races");

function seed_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Users — shared test password, all in competition
$hash = hashPassword('Integration2026!');
$uids = [];
foreach ([
    ['Alice',   'alice@test.local'],
    ['Bob',     'bob@test.local'],
    ['Charlie', 'charlie@test.local'],
] as [$name, $email]) {
    $id = seed_uuid();
    $uids[$name] = $id;
    $db->prepare("INSERT INTO users (id, email, password, display_name, in_competition, points, stars) VALUES (?, ?, ?, ?, 1, 0, 0)")
       ->execute([$id, $email, $hash, $name]);
}

// Restore f1_admin service account (in_competition=0, so never affects leaderboard or pool)
if ($adminRow) {
    $cols = array_keys($adminRow);
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $updates = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
    $db->prepare("INSERT INTO users ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates")
       ->execute(array_values($adminRow));
}

// Drivers — $d[number] = UUID
$d = [];
foreach ([
    [44, 'Lewis Hamilton',  'Mercedes'],
    [63, 'George Russell',  'Mercedes'],
    [1,  'Max Verstappen',  'Red Bull'],
    [11, 'Sergio Perez',    'Red Bull'],
    [16, 'Charles Leclerc', 'Ferrari'],
    [55, 'Carlos Sainz',    'Ferrari'],
    [4,  'Lando Norris',    'McLaren'],
    [81, 'Oscar Piastri',   'McLaren'],
    [14, 'Fernando Alonso', 'Aston Martin'],
    [18, 'Lance Stroll',    'Aston Martin'],
] as [$num, $name, $team]) {
    $id = seed_uuid();
    $d[$num] = $id;
    $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
       ->execute([$id, $name, $team, $num]);
}

// Races — [name, date, initial_bettingpool_size, rp1, rp2, rp3]
// Race 1 pool seeded as 3 users x bet_size 10 = 30
$rids = []; // name => [id, rp1, rp2, rp3]
foreach ([
    ['Race 1', '2026-01-01', 30, $d[44], $d[63], $d[1]],
    ['Race 2', '2026-02-01', 0,  $d[11], $d[16], $d[55]],
    ['Race 3', '2026-03-01', 0,  $d[4],  $d[81], $d[14]],
    ['Race 4', '2026-04-01', 0,  $d[44], $d[1],  $d[16]],
    ['Race 5', '2026-05-01', 0,  $d[11], $d[55], $d[81]],
] as [$name, $date, $pool, $rp1, $rp2, $rp3]) {
    $id = seed_uuid();
    $rids[$name] = [$id, $rp1, $rp2, $rp3];
    $db->prepare("INSERT INTO races (id, name, race_date, bettingpool_size, result_p1, result_p2, result_p3) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute([$id, $name, $date, $pool, $rp1, $rp2, $rp3]);
}

// Bets — Race 3: Bob and Charlie inserted before Alice so her perfect bet
// is last in SELECT order, giving deterministic pool write for Race 4.
foreach ([
    // Race 1
    [$uids['Alice'],   $rids['Race 1'][0], $d[44], $d[63], $d[11]],
    [$uids['Bob'],     $rids['Race 1'][0], $d[44], $d[1],  $d[63]],
    [$uids['Charlie'], $rids['Race 1'][0], $d[63], $d[44], $d[1]],
    // Race 2
    [$uids['Alice'],   $rids['Race 2'][0], $d[11], $d[16], $d[4]],
    [$uids['Bob'],     $rids['Race 2'][0], $d[16], $d[11], $d[55]],
    [$uids['Charlie'], $rids['Race 2'][0], $d[55], $d[4],  $d[16]],
    // Race 3 — Alice LAST (her perfect bet must be the final pool update)
    [$uids['Bob'],     $rids['Race 3'][0], $d[4],  $d[14], $d[81]],
    [$uids['Charlie'], $rids['Race 3'][0], $d[81], $d[4],  $d[18]],
    [$uids['Alice'],   $rids['Race 3'][0], $d[4],  $d[81], $d[14]], // PERFECT
    // Race 4
    [$uids['Alice'],   $rids['Race 4'][0], $d[63], $d[1],  $d[16]],
    [$uids['Bob'],     $rids['Race 4'][0], $d[44], $d[16], $d[1]],
    [$uids['Charlie'], $rids['Race 4'][0], $d[1],  $d[44], $d[63]],
    // Race 5
    [$uids['Alice'],   $rids['Race 5'][0], $d[11], $d[55], $d[18]],
    [$uids['Bob'],     $rids['Race 5'][0], $d[16], $d[11], $d[55]],
    [$uids['Charlie'], $rids['Race 5'][0], $d[81], $d[16], $d[11]],
] as [$uid, $rid, $bp1, $bp2, $bp3]) {
    $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3, points, is_perfect) VALUES (?, ?, ?, ?, ?, ?, 0, 0)")
       ->execute([seed_uuid(), $uid, $rid, $bp1, $bp2, $bp3]);
}

// Run scoring engine for all races in chronological order
foreach (['Race 1', 'Race 2', 'Race 3', 'Race 4', 'Race 5'] as $raceName) {
    [$rid, $rp1, $rp2, $rp3] = $rids[$raceName];
    calculateRacePoints($rid, $rp1, $rp2, $rp3);
}

echo json_encode(['ok' => true]);
