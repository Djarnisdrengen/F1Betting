<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = getDB();
$tables = ['settings', 'drivers', 'users', 'races', 'bets', 'password_resets', 'invites'];

$dump = [];
foreach ($tables as $table) {
    try {
        $dump[$table] = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $dump[$table] = null;
    }
}

echo json_encode(['ok' => true, 'timestamp' => date('c'), 'tables' => $dump]);
