<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if (!defined('F1_INTELLIGENCE_API_URL')) {
    define('F1_INTELLIGENCE_API_URL', 'https://YOUR-VERCEL-APP.vercel.app');
}
if (!defined('F1_INTELLIGENCE_TIMEOUT')) {
    define('F1_INTELLIGENCE_TIMEOUT', 30);
}
if (!defined('F1_INTELLIGENCE_DEBUG')) {
    define('F1_INTELLIGENCE_DEBUG', true);
}

// Load the F1Intelligence class
require_once __DIR__ . '/F1Intelligence.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>F1 Intelligence Test - Paddock Picks</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
            color: #333;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        h1 { color: #e3000b; margin-bottom: 10px; }
        h2 { color: #333; margin-top: 30px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #e3000b; }
        h3 { color: #555; margin: 15px 0 10px; }
        .success { color: #2e7d32; font-weight: 600; }
        .error { color: #c62828; font-weight: 600; }
        .info { background: #e3f2fd; padding: 12px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #1976d2; }
        .warning { background: #fff3e0; padding: 12px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #f57c00; }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 13px;
            border: 1px solid #ddd;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 13px;
        }
        .answer {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #e3000b;
            margin: 15px 0;
            line-height: 1.6;
        }
        .sources {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        .sources li { margin: 5px 0; }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #888;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏁 F1 Intelligence Test Page</h1>
        <p>Verifying the F1 RAG system integration with Paddock Picks</p>
        
        <h2>Configuration</h2>
        <div class="info">
            <p><strong>API URL:</strong> <code><?php echo htmlspecialchars(F1_INTELLIGENCE_API_URL); ?></code></p>
            <p><strong>Timeout:</strong> <?php echo F1_INTELLIGENCE_TIMEOUT; ?> seconds</p>
            <p><strong>Debug Mode:</strong> <?php echo F1_INTELLIGENCE_DEBUG ? 'ON' : 'OFF'; ?></p>
        </div>
        
        <?php
        if (F1_INTELLIGENCE_API_URL === 'https://YOUR-VERCEL-APP.vercel.app') {
            echo '<div class="warning">';
            echo '<strong>⚠️ Configuration Required</strong><br>';
            echo 'Update <code>F1_INTELLIGENCE_API_URL</code> in your config.php with your actual Vercel deployment URL.';
            echo '</div>';
            echo '</div></body></html>';
            exit;
        }
        
        $intelligence = new F1Intelligence(
            F1_INTELLIGENCE_API_URL,
            F1_INTELLIGENCE_TIMEOUT,
            F1_INTELLIGENCE_DEBUG
        );
        
        // Test 1: Health Check
        echo '<h2>Test 1: API Health Check</h2>';
        if ($intelligence->healthCheck()) {
            echo '<p class="success">✅ API is reachable!</p>';
        } else {
            echo '<p class="error">❌ Cannot reach API</p>';
            echo '<div class="warning">';
            echo '<strong>Troubleshooting:</strong>';
            echo '<ul>';
            echo '<li>Verify Vercel deployment is live</li>';
            echo '<li>Check API URL in config.php is correct</li>';
            echo '<li>Verify environment variables are set in Vercel dashboard</li>';
            echo '<li>Test with curl: <code>curl ' . htmlspecialchars(F1_INTELLIGENCE_API_URL) . '/api/intelligence</code></li>';
            echo '</ul>';
            echo '</div>';
            echo '</div></body></html>';
            exit;
        }
        
        // Test 2: Query Test
        echo '<h2>Test 2: Query Test</h2>';
        $testQuestion = "How does Verstappen perform at Monaco?";
        echo '<p>Asking: <em>"' . htmlspecialchars($testQuestion) . '"</em></p>';
        
        $startTime = microtime(true);
        $result = $intelligence->query($testQuestion);
        $duration = round(microtime(true) - $startTime, 2);
        
        if ($result) {
            echo '<p class="success">✅ Query successful! (took ' . $duration . 's)</p>';
            
            echo '<h3>Answer:</h3>';
            echo '<div class="answer">' . nl2br(htmlspecialchars($result['answer'])) . '</div>';
            
            echo '<div class="sources">';
            echo '<h3>Sources Used:</h3>';
            echo '<ul>';
            foreach ($result['sources'] as $source) {
                echo '<li>' . htmlspecialchars($source['title']);
                echo ' <small>(similarity: ' . round($source['similarity'] * 100, 1) . '%)</small></li>';
            }
            echo '</ul>';
            echo '</div>';
            
            if (F1_INTELLIGENCE_DEBUG) {
                echo '<h3>Raw Response (debug):</h3>';
                echo '<pre>' . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            }
        } else {
            echo '<p class="error">❌ Query failed</p>';
            echo '<div class="warning">Check server error logs for details.</div>';
        }
        ?>
        
        <div class="footer">
            <p>F1 Intelligence Test • Paddock Picks • <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
