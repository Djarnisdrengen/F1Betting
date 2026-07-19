<?php
// Import endpoint for bin/generate-rumor-items.js (Phase 3 generator). Writes challenge_items
// rows. Defaults to status='draft' (inert until an admin publishes on admin-challenges.php), but
// the caller may pass {"status":"published"} to insert them already live — the automated
// cron-content-topup.yml pipeline uses this to publish unattended. publish_date is written
// explicitly (the column is DATE NULL with no default): a published rumor is only visible once
// publish_date <= today, so leaving it NULL would make it silently unplayable. A published import
// IS immediately player-visible, so the Bearer token is a publish-to-live capability.
// Auth mirrors schema-check.php's Bearer pattern.
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

// Default to draft; only an explicit "published" flips it live. Anything else is treated as draft.
$status = (($payload['status'] ?? 'draft') === 'published') ? 'published' : 'draft';

$db = getDB();
$inserted = 0;
$errors = [];

$stmt = $db->prepare("
    INSERT INTO challenge_items
    (id, text_da, text_en, context_da, context_en, explain_da, explain_en, is_real, status, source_ref, publish_date, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
        $status,
        $item['source_ref'] ?? null,
        $item['publish_date'] ?? date('Y-m-d'),
    ]);
    $inserted++;
}

echo json_encode(['ok' => true, 'inserted' => $inserted, 'errors' => $errors]);
