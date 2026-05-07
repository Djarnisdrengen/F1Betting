<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['tables'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid backup payload']);
    exit;
}

$db = getDB();

try {
    $db->beginTransaction();

    // Delete in FK-safe order (dependents first)
    $db->query("DELETE FROM bets");
    $db->query("DELETE FROM password_resets");
    $db->query("DELETE FROM invites");
    $db->query("DELETE FROM users");
    $db->query("DELETE FROM races");
    $db->query("DELETE FROM drivers");
    $db->query("DELETE FROM settings");

    $restored = [];

    // Insert in FK-safe order (parents first)
    foreach (['settings', 'drivers', 'users', 'races', 'bets', 'password_resets', 'invites'] as $table) {
        $rows = $data['tables'][$table] ?? null;
        if ($rows === null || count($rows) === 0) {
            $restored[$table] = 0;
            continue;
        }
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
        $restored[$table] = count($rows);
    }

    $db->commit();

    echo json_encode(['ok' => true, 'restored' => $restored]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('db-restore: failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Restore failed — check server logs']);
}
