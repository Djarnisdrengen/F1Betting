<?php
// Read-only config endpoint for cron-content-topup.yml — the workflow has no direct DB access,
// so it fetches the admin-configured weekly batch size (Settings tab, "Weekly Content Batch
// Size") over HTTP instead. Auth mirrors schema-check.php / import-{rumor,trivia}-drafts.php's
// existing Bearer pattern (same INTEGRATION_SEED_TOKEN secret already trusted for publishing
// content — this only adds a read).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$token = getBearerToken() ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || !hash_equals(INTEGRATION_SEED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$settings = getSettings();
echo json_encode([
    'ok' => true,
    'rumor_batch_size' => (int) ($settings['rumor_batch_size'] ?? 6),
    'trivia_batch_size' => (int) ($settings['trivia_batch_size'] ?? 6),
]);
