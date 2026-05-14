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

$loc = $entry['source-file'] ?? '';
if ($loc && (isset($entry['line-number']) || isset($entry['column-number']))) {
    $loc .= ':' . ($entry['line-number'] ?? '?') . ':' . ($entry['column-number'] ?? '?');
}

$line = implode(' | ', array_filter([
    'blocked-uri:'      . ($entry['blocked-uri']          ?? ''),
    'document-uri:'     . ($entry['document-uri']         ?? ''),
    'violated:'         . ($entry['violated-directive']   ?? ''),
    'effective:'        . ($entry['effective-directive']  ?? ''),
    'disposition:'      . ($entry['disposition']          ?? ''),
    'referrer:'         . ($entry['referrer']             ?? ''),
    'source-file:'      . $loc,
    'status-code:'      . ($entry['status-code'] !== null && $entry['status-code'] !== '' ? $entry['status-code'] : ''),
    'script-sample:'    . ($entry['script-sample']        ?? ''),
    'original-policy:'  . ($entry['original-policy']      ?? ''),
]));

logToFile(APP_LOG_FILE, '[CSP] ' . $line);

http_response_code(204);
