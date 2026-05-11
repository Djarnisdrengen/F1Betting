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

    $db->beginTransaction();

    // Delete in FK-safe order (dependents first)
    $db->query("DELETE FROM bets");
    $db->query("DELETE FROM users");
    $db->query("DELETE FROM races");
    $db->query("DELETE FROM drivers");

    // settings, password_resets, and invites are intentionally excluded:
    // settings — test server keeps its own settings
    // password_resets, invites — session-scoped, not meaningful to sync

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
            $stmt->execute(array_values($row));
        }
    }

    $db->commit();

    echo json_encode([
        'ok' => true,
        'dropped_old_tables' => $droppedCount,
        'copied' => $copied,
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
