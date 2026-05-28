<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (!defined('LIVE_DB_NAME')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'LIVE_DB_NAME not defined in config.php']);
    exit;
}

$db = getDB();

try {
    $live = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . LIVE_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    if (defined('APP_LOG_FILE')) {
        logToFile(APP_LOG_FILE, '[ERROR] sync-from-live: DB connection failed: ' . $e->getMessage());
    } else {
        error_log('sync-from-live: live DB connection failed: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => 'Live DB connection failed — check server logs']);
    exit;
}

try {
    // Drop any old_ prefixed tables (before transaction — DROP TABLE causes implicit commit in MySQL)
    // FK checks disabled so drop order doesn't matter across old_ tables
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $droppedCount = 0;
    $oldTables = array_filter($tables, fn($t) => strpos($t, 'old_') === 0);
    if ($oldTables) {
        $db->query("SET foreign_key_checks = 0");
        foreach ($oldTables as $table) {
            $db->query("DROP TABLE IF EXISTS `$table`");
            $droppedCount++;
        }
        $db->query("SET foreign_key_checks = 1");
    }

    // Preserve f1_admin service account so it survives the sync wipe
    $adminStmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $adminStmt->execute([F1_ADMIN_EMAIL]);
    $adminRow = $adminStmt->fetch() ?: null;

    $db->beginTransaction();

    // Delete in FK-safe order (dependents first)
    $db->query("DELETE FROM bets");
    $db->query("DELETE FROM users");
    $db->query("DELETE FROM races");
    $db->query("DELETE FROM drivers");

    // password_resets and invites are intentionally excluded (session-scoped).
    // settings IS synced so scoring rules match live — divergent points_p2 etc.
    // would silently produce wrong totals during recalculation testing.

    // Sync game-rule settings from live so scoring matches (test keeps its own
    // content: app_title, app_year, hero texts).
    $liveSett = $live->query("SELECT points_p1, points_p2, points_p3, points_wrong_pos, bet_size, betting_window_hours FROM settings WHERE id = 1")->fetch();
    if ($liveSett) {
        $db->prepare("UPDATE settings SET points_p1=?, points_p2=?, points_p3=?, points_wrong_pos=?, bet_size=?, betting_window_hours=? WHERE id=1")
           ->execute(array_values($liveSett));
    }

    // Copy in FK-safe order (parents first)
    $copied = [];
    foreach (['drivers', 'users', 'races', 'bets'] as $table) {
        $rows = $live->query("SELECT * FROM `$table`")->fetchAll();
        $copied[$table] = count($rows);
        if (empty($rows)) {
            continue;
        }
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
        foreach ($rows as $row) {
            if ($table === 'races' && isset($row['name']) && strpos($row['name'], 'test: ') !== 0) {
                $row['name'] = 'test: ' . $row['name'];
            }
            if ($table === 'users' && isset($row['email'])) {
                $at = strpos($row['email'], '@');
                if ($at !== false && substr($row['email'], $at + 1) !== 'mailsac.com') {
                    $row['email'] = substr($row['email'], 0, $at + 1) . 'mailsac.com';
                }
            }
            $stmt->execute(array_values($row));
        }
    }

    $db->commit();

    // Restore f1_admin — ensures the service account exists even if absent from live
    if ($adminRow) {
        $cols = array_keys($adminRow);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $updates = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
        $db->prepare("INSERT INTO users ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates")
           ->execute(array_values($adminRow));
    }

    // Reset all passwords to known test values. Live and test use different
    // PASSWORD_PEPPER values, so all live hashes (including the preserved f1_admin
    // row) are unverifiable on test — rehash everything with the test pepper.
    $passwordsReset = false;
    if (defined('SYNC_TEST_PASSWORD') && SYNC_TEST_PASSWORD !== '') {
        $testHash = hashPassword(SYNC_TEST_PASSWORD);
        $db->prepare("UPDATE users SET password = ?")->execute([$testHash]);
        $passwordsReset = true;
    }
    // Always rehash f1_admin with its configured password so smoke tests and
    // admin tooling keep working regardless of which env the hash came from.
    $db->prepare("UPDATE users SET password = ? WHERE email = ?")
       ->execute([hashPassword(F1_ADMIN_PASSWORD), F1_ADMIN_EMAIL]);

    // Clean up test-only rows in tables the sync intentionally skips.
    // invites are not synced (session-scoped), but e2e tests may leave
    // stale rows if a run fails before its own teardown.
    $testEmails = [
        'e2e_testing_invite_f1@mailsac.com',
        'e2e_testing_testuser_f1@mailsac.com',
        'e2e_reset_race_f1@mailsac.com',
    ];
    $placeholders = implode(', ', array_fill(0, count($testEmails), '?'));
    $db->prepare("DELETE FROM invites WHERE email IN ($placeholders)")->execute($testEmails);

    echo json_encode([
        'ok'                 => true,
        'dropped_old_tables' => $droppedCount,
        'copied'             => $copied,
        'passwords_reset'    => $passwordsReset,
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    if (defined('APP_LOG_FILE')) {
        logToFile(APP_LOG_FILE, '[ERROR] sync-from-live: operation failed: ' . $e->getMessage());
    } else {
        error_log('sync-from-live: operation failed: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => 'Sync failed — check server logs']);
}
