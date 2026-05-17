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
    $hash     = hashPassword('E2ETestPassword2026!');
    $userLang = in_array($_GET['language'] ?? '', ['da', 'en']) ? $_GET['language'] : 'da';
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language) VALUES (?, ?, ?, 'E2E Test User', 'user', 0, 0, 0, ?)")
       ->execute([$id, $e2eUserEmail, $hash, $userLang]);
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

// Action: seed_notification_open — race 47h30m from now so betting window just opened.
// Creates:
//   - in-competition user     → receives betting-opened notification
//   - non-competing user      → receives pool-reminder (skipped for betting notification)
//   - pending invite email    → receives pool-reminder with registration link
// Returns: { ok, raceId, emailCompeting, emailNonCompeting, emailInvited }
if (($_GET['action'] ?? '') === 'seed_notification_open') {
    $e2eEmailIn     = 'e2e_notify_open_in_f1@helvegpovlsen.dk';
    $e2eEmailOut    = 'e2e_notify_open_out_f1@helvegpovlsen.dk';
    $e2eEmailInvite = 'e2e_notify_open_invite_f1@helvegpovlsen.dk';

    foreach ([$e2eEmailIn, $e2eEmailOut] as $em) {
        $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$em]);
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([$em]);
    }
    $db->prepare("DELETE FROM invites WHERE email = ?")->execute([$e2eEmailInvite]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Notify Open Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Notify Open Race'");

    $db->query("UPDATE settings SET betting_window_hours = 48 WHERE id = 1");

    // In-competition user — language='en' to verify per-user language in betting-opened email
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language) VALUES (?, ?, ?, 'E2E Notify Open In', 'user', 1, 0, 0, 'en')")
       ->execute([seed_uuid(), $e2eEmailIn, hashPassword('E2ENotifyOpen2026!')]);

    // Non-competing registered user — language='en' to verify per-user language in pool reminder
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language) VALUES (?, ?, ?, 'E2E Notify Open Out', 'user', 0, 0, 0, 'en')")
       ->execute([seed_uuid(), $e2eEmailOut, hashPassword('E2ENotifyOpen2026!')]);

    // Pending invite — must receive pool reminder with registration link
    $adminStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $adminStmt->execute([F1_ADMIN_EMAIL]);
    $adminUser = $adminStmt->fetch();
    if (!$adminUser) {
        echo json_encode(['ok' => false, 'error' => 'Admin user not found — cannot create invite']);
        exit;
    }
    $db->prepare("INSERT INTO invites (email, token, created_by, expires_at) VALUES (?, ?, ?, ?)")
       ->execute([$e2eEmailInvite, 'e2e-notify-open-token', $adminUser['id'], (new DateTime('+48 hours'))->format('Y-m-d H:i:s')]);

    // 47h30m from now → bettingOpens = raceDateTime - 48h = now - 30min, inside the 1-hour window
    // Pool size 150 kr simulates a carried-over pool from a previous race
    $raceAt = new DateTime('+47 hours +30 minutes');
    $raceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Notify Open Race', 'Test Circuit', ?, ?, 150)")
       ->execute([$raceId, $raceAt->format('Y-m-d'), $raceAt->format('H:i:s')]);

    // Read back the actual betting_window_hours so the test can verify the setting took effect
    $settingsRow = $db->query("SELECT betting_window_hours FROM settings WHERE id = 1")->fetch();
    $actualWindow = $settingsRow ? (int)$settingsRow['betting_window_hours'] : 48;

    $bettingOpensAt = $raceAt->getTimestamp() - ($actualWindow * 3600);
    echo json_encode([
        'ok'              => true,
        'raceId'          => $raceId,
        'emailCompeting'  => $e2eEmailIn,
        'emailNonCompeting' => $e2eEmailOut,
        'emailInvited'    => $e2eEmailInvite,
        'bettingWindowHours' => $actualWindow,
        'raceAt'          => $raceAt->format('Y-m-d H:i:s'),
        'bettingOpensAt'  => date('Y-m-d H:i:s', $bettingOpensAt),
        'nowAt'           => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// Action: cleanup_notification_open
if (($_GET['action'] ?? '') === 'cleanup_notification_open') {
    $e2eEmailIn     = 'e2e_notify_open_in_f1@helvegpovlsen.dk';
    $e2eEmailOut    = 'e2e_notify_open_out_f1@helvegpovlsen.dk';
    $e2eEmailInvite = 'e2e_notify_open_invite_f1@helvegpovlsen.dk';
    foreach ([$e2eEmailIn, $e2eEmailOut] as $em) {
        $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$em]);
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([$em]);
    }
    $db->prepare("DELETE FROM invites WHERE email = ?")->execute([$e2eEmailInvite]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Notify Open Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Notify Open Race'");
    echo json_encode(['ok' => true]);
    exit;
}

// Action: seed_notification_close — race 2h30m from now (inside the 2-3h closing window).
// Creates user A (no bet, should receive notification) and user B (has bet, should be skipped).
// Returns: { ok, raceId, emailUnbetted, emailBetted }
if (($_GET['action'] ?? '') === 'seed_notification_close') {
    $e2eEmailA = 'e2e_notify_close_a_f1@helvegpovlsen.dk';
    $e2eEmailB = 'e2e_notify_close_b_f1@helvegpovlsen.dk';

    foreach ([$e2eEmailA, $e2eEmailB] as $em) {
        $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$em]);
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([$em]);
    }
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Notify Close Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Notify Close Race'");

    // Ensure 3 drivers exist for user B's bet
    $driverIds = [];
    foreach ([
        [44, 'Lewis Hamilton',  'Mercedes'],
        [1,  'Max Verstappen',  'Red Bull'],
        [16, 'Charles Leclerc', 'Ferrari'],
    ] as [$num, $fullName, $team]) {
        $parts = explode(' ', $fullName);
        $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute(['%' . end($parts) . '%']);
        $row = $stmt->fetch();
        if ($row) {
            $driverIds[] = $row['id'];
        } else {
            $newId = seed_uuid();
            $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
               ->execute([$newId, $fullName, $team, $num]);
            $driverIds[] = $newId;
        }
    }

    // User A — language='en' to verify per-user language in betting-closing email
    $userAId = seed_uuid();
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language) VALUES (?, ?, ?, 'E2E Notify Close A', 'user', 1, 0, 0, 'en')")
       ->execute([$userAId, $e2eEmailA, hashPassword('E2ENotifyCloseA2026!')]);

    $userBId = seed_uuid();
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES (?, ?, ?, 'E2E Notify Close B', 'user', 1, 0, 0)")
       ->execute([$userBId, $e2eEmailB, hashPassword('E2ENotifyCloseB2026!')]);

    // 2h30m from now → inside the cron's $raceDateTime > $twoHours && $raceDateTime <= $threeHours window
    $raceAt = new DateTime('+2 hours +30 minutes');
    $raceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Notify Close Race', 'Test Circuit', ?, ?, 0)")
       ->execute([$raceId, $raceAt->format('Y-m-d'), $raceAt->format('H:i:s')]);

    // User B has already placed a bet — should be skipped by the cron
    $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3, points, is_perfect) VALUES (?, ?, ?, ?, ?, ?, 0, 0)")
       ->execute([seed_uuid(), $userBId, $raceId, $driverIds[0], $driverIds[1], $driverIds[2]]);

    echo json_encode(['ok' => true, 'raceId' => $raceId, 'emailUnbetted' => $e2eEmailA, 'emailBetted' => $e2eEmailB]);
    exit;
}

// Action: cleanup_notification_close
if (($_GET['action'] ?? '') === 'cleanup_notification_close') {
    $e2eEmailA = 'e2e_notify_close_a_f1@helvegpovlsen.dk';
    $e2eEmailB = 'e2e_notify_close_b_f1@helvegpovlsen.dk';
    foreach ([$e2eEmailA, $e2eEmailB] as $em) {
        $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$em]);
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([$em]);
    }
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Notify Close Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Notify Close Race'");
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

    // Next day (2026-05-16) — acts as next race for pool rollover.
    // Must sort before any real upcoming race so scoring targets this throwaway
    // race instead of modifying the real next race's pool size.
    $nextRaceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Next Race', 'Test', '2026-05-16', '14:00:00', 0)")
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

// Action: seed_bet_deleted — user with a bet on an open race so admin can delete it.
// Race is 12 h away; with 48 h window, betting opened 36 h ago → canDelete = true.
// Returns: { ok, email, raceName }
if (($_GET['action'] ?? '') === 'seed_bet_deleted') {
    $e2eEmail = 'e2e_bet_delete_f1@helvegpovlsen.dk';

    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eEmail]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Bet Delete Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Bet Delete Race'");
    $db->query("UPDATE settings SET betting_window_hours = 48 WHERE id = 1");

    $driverIds = [];
    foreach ([
        [44, 'Lewis Hamilton',  'Mercedes'],
        [1,  'Max Verstappen',  'Red Bull'],
        [16, 'Charles Leclerc', 'Ferrari'],
    ] as [$num, $fullName, $team]) {
        $parts = explode(' ', $fullName);
        $stmt = $db->prepare("SELECT id FROM drivers WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute(['%' . end($parts) . '%']);
        $row = $stmt->fetch();
        if ($row) {
            $driverIds[] = $row['id'];
        } else {
            $newId = seed_uuid();
            $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
               ->execute([$newId, $fullName, $team, $num]);
            $driverIds[] = $newId;
        }
    }

    $userId = seed_uuid();
    // language='en' to verify per-user language in bet-deleted email
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language) VALUES (?, ?, ?, 'E2E Bet Delete User', 'user', 1, 0, 0, 'en')")
       ->execute([$userId, $e2eEmail, hashPassword('E2EBetDelete2026!')]);

    // Race 12 h from now → betting opened 36 h ago, race not yet started → canDelete = true
    $raceAt = new DateTime('+12 hours');
    $raceId = seed_uuid();
    $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, bettingpool_size) VALUES (?, 'E2E Bet Delete Race', 'Test Circuit', ?, ?, 30)")
       ->execute([$raceId, $raceAt->format('Y-m-d'), $raceAt->format('H:i:s')]);

    $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3, points, is_perfect) VALUES (?, ?, ?, ?, ?, ?, 0, 0)")
       ->execute([seed_uuid(), $userId, $raceId, $driverIds[0], $driverIds[1], $driverIds[2]]);

    echo json_encode(['ok' => true, 'email' => $e2eEmail, 'raceName' => 'E2E Bet Delete Race']);
    exit;
}

// Action: cleanup_bet_deleted
if (($_GET['action'] ?? '') === 'cleanup_bet_deleted') {
    $e2eEmail = 'e2e_bet_delete_f1@helvegpovlsen.dk';
    $db->prepare("DELETE FROM bets WHERE user_id IN (SELECT id FROM users WHERE email = ?)")->execute([$e2eEmail]);
    $db->prepare("DELETE FROM users WHERE email = ?")->execute([$e2eEmail]);
    $db->query("DELETE FROM bets WHERE race_id IN (SELECT id FROM races WHERE name = 'E2E Bet Delete Race')");
    $db->query("DELETE FROM races WHERE name = 'E2E Bet Delete Race'");
    echo json_encode(['ok' => true]);
    exit;
}

// Action: send_email_preview — sends one real email of each type to F1_ADMIN_EMAIL for visual review.
// No DB side-effects. All dummy data, all sent to F1_ADMIN_EMAIL.
// Sends all 8 types in both Danish and English (16 emails total).
// Returns: { ok, emails: { "<key>_<lang>": { sent, to, subject, ...details } } }
if (($_GET['action'] ?? '') === 'send_email_preview') {
    require_once __DIR__ . '/../includes/smtp.php';

    $adminEmail   = F1_ADMIN_EMAIL;
    $appName      = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
    $emails       = [];

    $nextRace = $db->query("SELECT * FROM races WHERE result_p1 IS NULL ORDER BY race_date ASC LIMIT 1")->fetch();
    $previewRace = $nextRace ?: [
        'id'               => 'preview-race-000',
        'name'             => 'Preview Grand Prix',
        'location'         => 'Test Circuit',
        'race_date'        => date('Y-m-d', strtotime('+2 days')),
        'race_time'        => '14:00:00',
        'bettingpool_size' => 0,
    ];
    $raceDate = date('d M Y', strtotime($previewRace['race_date']));
    $raceTime = substr($previewRace['race_time'], 0, 5);
    $betLink  = convertToEmailUrl(SITE_URL . '/bet.php?race=' . $previewRace['id']);
    $lbLink   = convertToEmailUrl(SITE_URL . '/leaderboard.php');
    $regLink  = convertToEmailUrl(SITE_URL . '/register.php?token=preview-invite-token-5678');

    foreach (['da', 'en'] as $lang) {
        $suffix      = "_{$lang}";
        $previewName = $lang === 'da' ? 'Preview Bruger' : 'Preview User';

        // 1. Forgot password
        $resetLink = SITE_URL . '/reset_password.php?token=preview-reset-token-1234';
        $subject   = sprintf(t('email_reset_subject', $lang), $appName);
        $r = sendPasswordResetEmail($adminEmail, $previewName, $resetLink, $lang);
        $emails["1_password_reset{$suffix}"] = [
            'sent'       => $r['success'],
            'to'         => $adminEmail,
            'subject'    => $subject,
            'reset_link' => convertToEmailUrl($resetLink),
        ];

        // 2. Admin reset password (inline — matches admin.php logic)
        $subject  = sprintf(t('email_admin_reset_subject', $lang), $appName);
        $greeting = sprintf(t('email_admin_reset_greeting', $lang), $previewName);
        $intro    = sprintf(t('email_admin_reset_intro', $lang), 'Admin', 'PreviewPw123!');
        $btnText  = t('email_admin_reset_button', $lang);
        $expiry   = t('email_admin_contact', $lang);
        $regards  = sprintf(t('email_regards', $lang), $appName);
        $html     = getEmailTemplate($greeting, $intro, $btnText, $emailBaseUrl, $expiry, '', $regards, $appName);
        $r = sendEmail($adminEmail, $subject, $html);
        $emails["2_admin_reset_password{$suffix}"] = [
            'sent'         => $r['success'],
            'to'           => $adminEmail,
            'subject'      => $subject,
            'new_password' => 'PreviewPw123!',
            'reset_by'     => 'Admin',
        ];

        // 3. Invitation
        $inviteLink = SITE_URL . '/register.php?token=preview-invite-token-5678';
        $subject    = sprintf(t('email_invite_subject', $lang), $appName);
        $r = sendInviteEmail($adminEmail, $inviteLink, 'Admin', $lang);
        $emails["3_invite{$suffix}"] = [
            'sent'        => $r['success'],
            'to'          => $adminEmail,
            'subject'     => $subject,
            'invite_link' => convertToEmailUrl($inviteLink),
            'invited_by'  => 'Admin',
        ];

        // 4. Betting window open (in-competition user)
        $poolSize4 = (int)$previewRace['bettingpool_size'];
        $subject   = sprintf(t('email_betting_open_subject', $lang), $previewRace['name'], $appName);
        $greeting4 = sprintf(t('email_betting_open_greeting', $lang), $previewName);
        $intro     = sprintf(t('email_betting_open_intro', $lang), $previewRace['name'], $previewRace['location']);
        $poolLine  = $poolSize4 > 0 ? sprintf(t('email_betting_open_pool', $lang), $poolSize4) : '';
        $details   = sprintf(t('email_betting_open_details', $lang), $raceDate, $raceTime, 48);
        $html      = getEmailTemplate($greeting4, "$intro<br><br>{$poolLine}{$details}",
            t('email_betting_open_button', $lang), $betLink, '', '',
            sprintf(t('email_betting_open_footer', $lang), $appName), $appName);
        $r = sendEmail($adminEmail, $subject, $html);
        $emails["4_betting_open{$suffix}"] = [
            'sent'                 => $r['success'],
            'to'                   => $adminEmail,
            'subject'              => $subject,
            'race'                 => $previewRace['name'],
            'race_date'            => "$raceDate $raceTime",
            'pool_size'            => $poolSize4,
            'betting_window_hours' => 48,
            'bet_link'             => $betLink,
        ];

        // 5. Pool reminder — non-competing registered user (leaderboard CTA)
        $poolSize    = (int)$previewRace['bettingpool_size'];
        $prSubject   = sprintf(t('email_pool_reminder_subject', $lang), $poolSize, $appName);
        $prGreeting  = sprintf(t('email_pool_reminder_greeting', $lang), $previewName);
        $prIntro     = sprintf(t('email_pool_reminder_intro', $lang), $previewRace['name'], $previewRace['location']);
        $prBody      = sprintf(t('email_pool_reminder_body', $lang), $poolSize, $raceDate, $raceTime);
        $prButton    = t('email_pool_reminder_button', $lang);
        $html        = getEmailTemplate($prGreeting, "$prIntro<br><br>$prBody", $prButton, $lbLink, '', '', $appName, $appName);
        $r = sendEmail($adminEmail, $prSubject, $html);
        $emails["5_pool_reminder_noncompeting{$suffix}"] = [
            'sent'      => $r['success'],
            'to'        => $adminEmail,
            'subject'   => $prSubject,
            'race'      => $previewRace['name'],
            'pool_size' => $poolSize,
            'cta_link'  => $lbLink,
        ];

        // 6. Pool reminder — pending invite (registration CTA)
        $html = getEmailTemplate($prGreeting, "$prIntro<br><br>$prBody", $prButton, $regLink, '', '', $appName, $appName);
        $r = sendEmail($adminEmail, $prSubject, $html);
        $emails["6_pool_reminder_invite{$suffix}"] = [
            'sent'      => $r['success'],
            'to'        => $adminEmail,
            'subject'   => $prSubject,
            'race'      => $previewRace['name'],
            'pool_size' => $poolSize,
            'cta_link'  => $regLink,
        ];

        // 7. Betting closing soon
        $subject  = sprintf(t('email_betting_closing_subject', $lang), $previewRace['name'], $appName);
        $greeting = sprintf(t('email_betting_closing_greeting', $lang), $previewName);
        $intro    = sprintf(t('email_betting_closing_intro', $lang), $previewRace['name']);
        $details  = sprintf(t('email_betting_closing_details', $lang), $raceDate, $raceTime);
        $btnText  = t('email_betting_closing_button', $lang);
        $footer   = sprintf(t('email_betting_closing_footer', $lang), $appName);
        $html     = getEmailTemplate($greeting, "$intro<br><br>$details", $btnText, $betLink, '', '', $footer, $appName);
        $r = sendEmail($adminEmail, $subject, $html);
        $emails["7_betting_closing{$suffix}"] = [
            'sent'      => $r['success'],
            'to'        => $adminEmail,
            'subject'   => $subject,
            'race'      => $previewRace['name'],
            'race_date' => "$raceDate $raceTime",
            'bet_link'  => $betLink,
        ];

        // 8. Bet deleted (inline — matches admin.php logic)
        $subject  = sprintf(t('email_bet_deleted_subject', $lang), $appName);
        $greeting = sprintf(t('email_bet_deleted_greeting', $lang), $previewName);
        $intro    = sprintf(t('email_bet_deleted_intro', $lang), htmlspecialchars($previewRace['name']));
        $btnText  = t('email_go_to_app', $lang);
        $expiry   = t('email_contact_admin', $lang);
        $regards  = sprintf(t('email_regards', $lang), $appName);
        $html     = getEmailTemplate($greeting, $intro, $btnText, $emailBaseUrl, $expiry, '', $regards, $appName);
        $r = sendEmail($adminEmail, $subject, $html);
        $emails["8_bet_deleted{$suffix}"] = [
            'sent'    => $r['success'],
            'to'      => $adminEmail,
            'subject' => $subject,
            'race'    => $previewRace['name'],
        ];
    }

    $allOk = array_reduce($emails, fn($c, $e) => $c && $e['sent'], true);
    echo json_encode(['ok' => $allOk, 'emails' => $emails]);
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
