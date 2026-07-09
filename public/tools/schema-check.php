<?php
// Reports which required schema objects are missing from this environment's DB.
// Driven by build-deploy/deploy.js after upload; the list of required objects is
// POSTed as JSON so this endpoint holds no migration knowledge of its own and
// never needs updating. Source of truth for the list is database/migrations.json.
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
$objects = $payload['objects'] ?? null;
if (!is_array($objects)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Expected JSON body { "objects": [...] }']);
    exit;
}

$db = getDB();

$tableExists = function (PDO $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
};

$columnExists = function (PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$missing = [];
try {
    foreach ($objects as $obj) {
        $table     = $obj['table'] ?? null;
        $migration = $obj['migration'] ?? 'unknown';
        if (!$table) {
            continue;
        }

        if (!$tableExists($db, $table)) {
            $missing[] = ['migration' => $migration, 'detail' => "missing table: $table"];
            continue; // no point checking columns of a table that isn't there
        }

        foreach (($obj['columns'] ?? []) as $column) {
            if (!$columnExists($db, $table, $column)) {
                $missing[] = ['migration' => $migration, 'detail' => "missing column: $table.$column"];
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    if (defined('APP_LOG_FILE')) {
        logToFile(APP_LOG_FILE, '[ERROR] schema-check: ' . $e->getMessage());
    } else {
        error_log('schema-check: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => 'Schema check failed — see server logs']);
    exit;
}

echo json_encode(['ok' => empty($missing), 'missing' => $missing]);
