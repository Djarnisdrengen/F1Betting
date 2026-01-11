<?php
/**
 * SMTP Test Script
 * 
 * Upload this file to test your SMTP configuration.
 * DELETE THIS FILE after testing for security!
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/smtp.php';

// Only allow admins to run this test
$currentUser = getCurrentUser();
if (!$currentUser || $currentUser['role'] !== 'admin') {
    die("Access denied. Admin login required.");
}

$testEmail = $currentUser['email'];
$result = null;

if (isset($_POST['test_smtp'])) {
    $testEmail = $_POST['test_email'] ?? $currentUser['email'];
    
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    $subject = "SMTP Test - $appName";
    $htmlContent = getEmailTemplate(
        "Hej!",
        "Dette er en test-email fra $appName.<br><br>Hvis du modtager denne email, virker din SMTP konfiguration korrekt!",
        "Gå til appen",
        SITE_URL,
        "",
        "",
        "Med venlig hilsen,<br>$appName",
        $appName
    );
    
    $result = sendEmail($testEmail, $subject, $htmlContent);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SMTP Test</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #1a1a1a; color: #fff; }
        .card { background: #242424; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #10b981; padding: 10px; border-radius: 4px; }
        .error { background: #e10600; padding: 10px; border-radius: 4px; }
        .debug { background: #333; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; white-space: pre-wrap; overflow-x: auto; }
        input, button { padding: 10px; margin: 5px 0; border-radius: 4px; border: none; }
        input { background: #333; color: #fff; width: 300px; }
        button { background: #e10600; color: #fff; cursor: pointer; }
        h1, h2, h3 { color: #e10600; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px; border-bottom: 1px solid #333; }
        td:first-child { color: #888; width: 150px; }
    </style>
</head>
<body>
    <h1>🔧 SMTP Test</h1>
    
    <div class="card">
        <h3>Current SMTP Configuration</h3>
        <table>
            <tr><td>SMTP_HOST</td><td><?= defined('SMTP_HOST') ? SMTP_HOST : '<em>Not defined</em>' ?></td></tr>
            <tr><td>SMTP_PORT</td><td><?= defined('SMTP_PORT') ? SMTP_PORT : '<em>Not defined</em>' ?></td></tr>
            <tr><td>SMTP_USER</td><td><?= defined('SMTP_USER') ? SMTP_USER : '<em>Not defined</em>' ?></td></tr>
            <tr><td>SMTP_PASS</td><td><?= defined('SMTP_PASS') ? '••••••••' : '<em>Not defined</em>' ?></td></tr>
            <tr><td>SMTP_FROM_EMAIL</td><td><?= defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '<em>Not defined</em>' ?></td></tr>
            <tr><td>SMTP_FROM_NAME</td><td><?= defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '<em>Not defined</em>' ?></td></tr>
        </table>
    </div>
    
    <div class="card">
        <h3>Send Test Email</h3>
        <form method="POST">
            <input type="email" name="test_email" value="<?= htmlspecialchars($testEmail) ?>" placeholder="Email address">
            <button type="submit" name="test_smtp">Send Test Email</button>
        </form>
    </div>
    
    <?php if ($result): ?>
    <div class="card">
        <h3>Result</h3>
        <?php if ($result['success']): ?>
            <div class="success">✅ <?= htmlspecialchars($result['message']) ?></div>
        <?php else: ?>
            <div class="error">❌ <?= htmlspecialchars($result['message']) ?></div>
            <?php if (!empty($result['debug'])): ?>
                <h4>Debug Log:</h4>
                <div class="debug"><?= htmlspecialchars($result['debug']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h3>⚠️ Security Warning</h3>
        <p>Delete this file (<code>test_smtp.php</code>) after testing!</p>
    </div>
</body>
</html>
