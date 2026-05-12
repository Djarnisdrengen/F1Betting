<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    exit;
}

$report = json_decode($raw, true);
if ($report === null) {
    http_response_code(400);
    exit;
}

$entry = $report['csp-report'] ?? $report;

$line = implode(' | ', array_filter([
    'blocked-uri:'    . ($entry['blocked-uri']    ?? ''),
    'violated:'       . ($entry['violated-directive'] ?? $entry['effective-directive'] ?? ''),
    'document-uri:'   . ($entry['document-uri']   ?? ''),
    'source-file:'    . ($entry['source-file']     ?? ''),
]));

logToFile(APP_LOG_FILE, '[CSP] ' . $line);

http_response_code(204);
