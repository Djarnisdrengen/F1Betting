<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || !hash_equals(INTEGRATION_SEED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = getDB();
// password_resets is intentionally NOT dumped: reset tokens are short-lived, are not needed to
// restore the app, and must never sit in backup artifacts (a leaked backup would otherwise be an
// account-takeover primitive). All other tables are included for disaster recovery.
$tables = ['settings', 'drivers', 'users', 'races', 'leaderboard_snapshots', 'bets', 'invites'];

$schema = [];
$dump = [];
foreach ($tables as $table) {
    try {
        $row = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $schema[$table] = $row['Create Table'] ?? null;
        $dump[$table] = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $schema[$table] = null;
        $dump[$table] = null;
    }
}

echo json_encode(['ok' => true, 'timestamp' => date('c'), 'schema' => $schema, 'tables' => $dump]);
