<?php
// TEMPORARY DIAGNOSTIC — delete after use
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT password, role, in_competition FROM users WHERE email = ?");
$stmt->execute([F1_ADMIN_EMAIL]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'User not found', 'email' => F1_ADMIN_EMAIL]);
    exit;
}

$passwordToTest = F1_ADMIN_PASSWORD;
$verifies = verifyPassword($passwordToTest, $user['password']);

echo json_encode([
    'ok'             => true,
    'email'          => F1_ADMIN_EMAIL,
    'hash_prefix'    => substr($user['password'], 0, 7),
    'password_len'   => strlen($passwordToTest),
    'pepper_applied' => true,
    'verifies'       => $verifies,
    'role'           => $user['role'],
    'in_competition' => (int)$user['in_competition'],
]);
