<?php
// Import endpoint for bin/generate-rumor-items.js (Phase 3 generator). Writes challenge_items
// rows with status='draft' only — inert until an admin publishes them on admin-challenges.php,
// so this is safe to call against any environment (test or live), unlike the test-seed.php
// actions which are gated to APP_ENV==='test'. Auth mirrors schema-check.php's Bearer pattern.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$token = getBearerToken() ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || !hash_equals(INTEGRATION_SEED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$items = $payload['items'] ?? null;
if (!is_array($items) || empty($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Expected JSON body { "items": [...] }']);
    exit;
}

$db = getDB();
$inserted = 0;
$errors = [];

$stmt = $db->prepare("
    INSERT INTO challenge_items
    (id, text_da, text_en, context_da, context_en, explain_da, explain_en, is_real, status, source_ref, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW())
");

foreach ($items as $i => $item) {
    $textDa = trim($item['text_da'] ?? '');
    $textEn = trim($item['text_en'] ?? '');
    if ($textDa === '' || $textEn === '' || !isset($item['is_real'])) {
        $errors[] = "item $i: missing text_da/text_en/is_real";
        continue;
    }

    $stmt->execute([
        generateUUID(),
        $textDa,
        $textEn,
        trim($item['context_da'] ?? ''),
        trim($item['context_en'] ?? ''),
        trim($item['explain_da'] ?? ''),
        trim($item['explain_en'] ?? ''),
        $item['is_real'] ? 1 : 0,
        $item['source_ref'] ?? null,
    ]);
    $inserted++;
}

echo json_encode(['ok' => true, 'inserted' => $inserted, 'errors' => $errors]);
