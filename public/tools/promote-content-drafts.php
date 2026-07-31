<?php
// Promotes existing challenge_items/challenge_trivia_questions drafts to published, oldest
// first — called by cron-content-topup.yml BEFORE generating fresh content via Claude, so the
// ~100-item backlog left over from old preview runs gets drawn down at the normal weekly
// cadence instead of sitting inert forever. Only the shortfall (batch_size - promoted) still
// needs Claude. Never touches bin/state/{rumor,trivia}-generator-state.<env>.json — that only
// tracks which knowledge-base docs have been drawn for generation, not which rows are
// published, so promoting an existing draft is entirely orthogonal to KB usage.
// Auth mirrors get-content-batch-size.php / import-rumor-drafts.php's existing Bearer pattern.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$token = getBearerToken() ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || !hash_equals(INTEGRATION_SEED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];

$type = $payload['type'] ?? ($_POST['type'] ?? '');
if (!in_array($type, ['rumor', 'trivia'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "type must be 'rumor' or 'trivia'"]);
    exit;
}

$count = sanitizeInt($payload['count'] ?? ($_POST['count'] ?? null), 1, null);
if ($count === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'count must be a positive integer']);
    exit;
}

$publishDate = trim((string) ($payload['publish_date'] ?? ($_POST['publish_date'] ?? '')));
if ($publishDate !== '' && !DateTime::createFromFormat('Y-m-d', $publishDate)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'publish_date must be YYYY-MM-DD']);
    exit;
}
if ($publishDate === '') {
    $publishDate = upcomingMonday();
}

// Same "upcoming Monday" rule as bin/generate-rumor-items.js / generate-trivia-questions.js
// (Europe/Copenhagen, strictly after today) — kept as an independent port rather than passed
// in from the workflow, so it doesn't depend on bash date arithmetic matching the JS rule
// exactly. All three implementations agree because the rule itself is simple and stable.
function upcomingMonday(): string {
    $cph = new DateTime('now', new DateTimeZone('Europe/Copenhagen'));
    $daysUntilMon = ((8 - (int) $cph->format('N')) % 7) ?: 7; // N: Mon=1..Sun=7; always strictly-next Monday
    $cph->modify("+{$daysUntilMon} days");
    return $cph->format('Y-m-d');
}

$table = $type === 'trivia' ? 'challenge_trivia_questions' : 'challenge_items';

$db = getDB();

$idStmt = $db->prepare("SELECT id FROM $table WHERE status = 'draft' ORDER BY created_at ASC, id ASC LIMIT ?");
$idStmt->bindValue(1, $count, PDO::PARAM_INT);
$idStmt->execute();
$ids = $idStmt->fetchAll(PDO::FETCH_COLUMN);

$promoted = 0;
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $updateStmt = $db->prepare("UPDATE $table SET status = 'published', publish_date = ? WHERE id IN ($placeholders)");
    $updateStmt->execute(array_merge([$publishDate], $ids));
    $promoted = $updateStmt->rowCount();
}

$remaining = (int) $db->query("SELECT COUNT(*) FROM $table WHERE status = 'draft'")->fetchColumn();

echo json_encode([
    'ok' => true,
    'promoted' => $promoted,
    'publish_date' => $publishDate,
    'remaining_drafts' => $remaining,
]);
