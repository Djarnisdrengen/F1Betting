<?php
/**
 * 🚀 System Test Suite Runner
 * Optimized for PHPUnit 10.5.x
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tests/AuthenticatedClient.php';
require __DIR__ . '/tests/BaseTestCase.php';

use PHPUnit\TextUI\Application;

$logFile = 'logfile.txt';
$output = '';
$error = '';
$environments = [
    'hpovlsen' => 'https://hpovlsen.dk',
    'formula1' => 'https://formula-1.dk',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $env = $_POST['environment'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!isset($environments[$env]) || !$username || !$password) {
            throw new RuntimeException('All fields are required.');
        }

        // 1. SILENT LOGIN
        // We buffer this because echoes like "DEBUG: Token found" crash PHPUnit 10+
        ob_start();
        $GLOBALS['authClient'] = new AuthenticatedClient(
            $environments[$env],
            $username,
            $password
        );
        $loginLogs = ob_get_clean(); 

        // 2. RUN PHPUNIT
        ob_start();
        
        $testDir = __DIR__ . '/tests';
        
        // Arguments optimized for PHPUnit 10.5.x
$argv = [
    'phpunit',
    '--no-configuration',
    '--bootstrap', __DIR__ . '/tests/BaseTestCase.php',
  '--testdox',
    '--display-errors',
    '--display-warnings',
    '--colors=never',
    '--log-junit', $logFile, // Optional: for structured data
    '--testdox-text', $logFile . '_text', // Better for your display
    $testDir
];

        $exitCode = 1;
try {
    $application = new Application();
    // Use the second parameter to prevent the script from exiting immediately
    $exitCode = $application->run($argv); 
    
    // Read the text results
    if (file_exists($logFile . '_text')) {
        $phpunitOutput = file_get_contents($logFile . '_text');
        unlink($logFile . '_text'); // Cleanup
    }
    
    if (empty($phpunitOutput)) {
        $phpunitOutput = "⚠️ No tests were executed. Check if files end in 'Test.php'.";
    }
        } catch (Throwable $t) {
            $phpunitOutput = ob_get_clean() . "\nCRITICAL ERROR DURING RUN: " . $t->getMessage() . "\nIn: " . $t->getFile() . " line " . $t->getLine();
        }

        // 3. FORMAT FINAL OUTPUT
        $statusText = ($exitCode === 0) ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED";
        $output = "STATUS: $statusText\n";
        $output .= "--------------------------------------------------\n";
        $output .= $phpunitOutput;

    } catch (Throwable $e) {
        // Main catch for login or system level failures
        if (ob_get_level() > 0) ob_end_clean();
        $error = $e->getMessage() . "\n" . $e->getTraceAsString();
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Test Suite</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; padding: 20px; max-width: 1000px; margin: 0 auto; background: #f0f2f5; color: #333; }
        .container { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { color: #1a1a1a; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; font-family: 'Consolas', 'Monaco', monospace; line-height: 1.5; font-size: 14px; border: 1px solid #333; }
        .error-box { background: #fff1f0; color: #cf1322; padding: 20px; border: 1px solid #ffa39e; border-radius: 8px; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 8px; color: #444; }
        input, select { padding: 12px; width: 100%; box-sizing: border-box; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 16px; }
        button { background: #1890ff; color: white; border: none; padding: 14px 20px; width: 100%; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 16px; transition: background 0.3s; }
        button:hover { background: #40a9ff; }
        .back-link { display: inline-block; margin-top: 20px; text-decoration: none; color: #1890ff; font-weight: 500; }
        .status-header { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h1>🚀 System Test Suite</h1>

    <?php if ($error): ?>
        <div class="error-box">
            <strong>System Error:</strong>
            <pre style="background:transparent; color:#cf1322; border:none; padding:10px 0;"><?= htmlspecialchars($error) ?></pre>
        </div>
        <a href="run-tests.php" class="back-link">← Back to Login</a>
    <?php endif; ?>

    <?php if (!$output && !$error): ?>
        <form method="post">
            <div class="form-group">
                <label>Vælg Miljø:</label>
                <select name="environment" required>
                    <option value="hpovlsen">Test: hpovlsen.dk</option>
                    <option value="formula1">Live: formula-1.dk</option>
                </select>
            </div>

            <div class="form-group">
                <label>Brugernavn:</label>
                <input type="text" name="username" placeholder="email@eksempel.dk" required>
            </div>

            <div class="form-group">
                <label>Adgangskode:</label>
                <input type="password" name="password" placeholder="Indtast kode" required>
            </div>

            <button type="submit">Kør Automatiserede Tests</button>
        </form>
    <?php else: ?>
        <h2>Test Resultater</h2>
        <pre><?= htmlspecialchars($output) ?></pre>
        <a href="run-tests.php" class="back-link">← Kør ny test</a>
    <?php endif; ?>
</div>

</body>
</html>