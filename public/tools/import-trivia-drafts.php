<?php
// Import endpoint for bin/generate-trivia-questions.js. Writes challenge_trivia_questions rows.
// Defaults to status='draft' (inert until an admin publishes on admin-challenges.php), but the
// caller may pass {"status":"published"} to insert them already live — the automated
// cron-content-topup.yml pipeline uses this to publish unattended. A published import IS
// immediately player-visible, so the Bearer token is a publish-to-live capability, not just a
// staging one. Mirrors import-rumor-drafts.php's Bearer auth pattern.
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
    INSERT INTO challenge_trivia_questions
    (id, question_da, question_en, options_da, options_en, correct_option, topic, explain_da, explain_en, status, publish_date, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

foreach ($items as $i => $item) {
    $questionDa = trim($item['question_da'] ?? '');
    $questionEn = trim($item['question_en'] ?? '');
    $optionsDa  = $item['options_da'] ?? null;
    $optionsEn  = $item['options_en'] ?? null;

    if ($questionDa === '' || $questionEn === ''
        || !is_array($optionsDa) || !is_array($optionsEn)
        || count($optionsDa) < 2 || count($optionsDa) > 4
        || count($optionsDa) !== count($optionsEn)
        || !isset($item['correct_option'])
    ) {
        $errors[] = "item $i: missing/invalid question_da/question_en/options_da/options_en/correct_option";
        continue;
    }

    $optionsDa = array_values($optionsDa);
    $optionsEn = array_values($optionsEn);
    $correctOption = (int) $item['correct_option'];
    if ($correctOption < 0 || $correctOption >= count($optionsDa)) {
        $errors[] = "item $i: correct_option out of range";
        continue;
    }

    $stmt->execute([
        generateUUID(),
        $questionDa,
        $questionEn,
        json_encode($optionsDa),
        json_encode($optionsEn),
        $correctOption,
        trim($item['topic'] ?? ''),
        trim($item['explain_da'] ?? ''),
        trim($item['explain_en'] ?? ''),
        $status,
        $item['publish_date'] ?? date('Y-m-d'),
    ]);
    $inserted++;
}

echo json_encode(['ok' => true, 'inserted' => $inserted, 'errors' => $errors]);
