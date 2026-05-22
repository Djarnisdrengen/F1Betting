<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

function maskSecret($const) {
    return defined($const) ? '********' : 'Not defined';
}
function showValue($const) {
    return defined($const) ? htmlspecialchars(constant($const), ENT_QUOTES, 'UTF-8') : '<em>Not defined</em>';
}

$rows = [
    ['SMTP_HOST',       showValue('SMTP_HOST'),       false],
    ['SMTP_PORT',       showValue('SMTP_PORT'),       false],
    ['SMTP_USER',       showValue('SMTP_USER'),       false],
    ['SMTP_FROM_EMAIL', showValue('SMTP_FROM_EMAIL'), false],
    ['SMTP_FROM_NAME',  showValue('SMTP_FROM_NAME'),  false],
    ['SMTP_PASS',       maskSecret('SMTP_PASS'),      true],
    ['RESEND_API_KEY',  maskSecret('RESEND_API_KEY'), true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>SMTP Config</title>
<style>
body { font-family: sans-serif; padding: 2rem; }
table { border-collapse: collapse; width: 100%; max-width: 600px; }
th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
th { background: #f4f4f4; }
</style>
</head>
<body>
<h1>Current SMTP Configuration</h1>
<table>
<thead><tr><th>Key</th><th>Value</th></tr></thead>
<tbody>
<?php foreach ($rows as [$key, $val, $secret]): ?>
<tr><td><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></td><td><?= $val ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
