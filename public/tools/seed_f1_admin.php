<?php
/**
 * Upserts the f1_admin service account using the pepper from config.php.
 * Run once after deploying to a new environment.
 *
 * Web: https://your-site/tools/seed_f1_admin.php?token=<INTEGRATION_SEED_TOKEN>
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || !hash_equals(INTEGRATION_SEED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (!defined('F1_ADMIN_EMAIL') || !defined('F1_ADMIN_PASSWORD')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'F1_ADMIN_EMAIL and F1_ADMIN_PASSWORD must be defined in config.php']);
    exit;
}

$db   = getDB();
$hash = hashPassword(F1_ADMIN_PASSWORD);

$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([F1_ADMIN_EMAIL]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare("UPDATE users SET password = ?, display_name = 'F1 Admin', role = 'admin', in_competition = 0 WHERE email = ?")
       ->execute([$hash, F1_ADMIN_EMAIL]);
    $action = 'updated';
} else {
    $db->prepare("INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES (?, ?, ?, 'F1 Admin', 'admin', 0, 0, 0)")
       ->execute([generateUUID(), F1_ADMIN_EMAIL, $hash]);
    $action = 'created';
}

echo json_encode(['ok' => true, 'action' => $action, 'email' => F1_ADMIN_EMAIL]);
