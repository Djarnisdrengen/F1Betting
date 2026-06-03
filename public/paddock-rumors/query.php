<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if (!defined('PADDOCK_RUMORS_API_URL')) {
    define('PADDOCK_RUMORS_API_URL', 'https://YOUR-PADDOCK-RUMORS.vercel.app');
}
if (!defined('PADDOCK_RUMORS_TIMEOUT')) {
    define('PADDOCK_RUMORS_TIMEOUT', 30);
}

$query   = trim($_POST['q'] ?? '');
$result  = null;
$error   = null;
$elapsed = null;

if ($query !== '') {
    $t0 = microtime(true);
    $ch = curl_init(PADDOCK_RUMORS_API_URL . '/api/query');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => PADDOCK_RUMORS_TIMEOUT,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $elapsed = round(microtime(true) - $t0, 2);

    if ($err) {
        $error = 'Connection failed: ' . $err;
    } elseif ($code !== 200) {
        $decoded = json_decode($raw, true);
        $error = 'API error ' . $code . ': ' . ($decoded['error'] ?? $raw);
    } else {
        $result = json_decode($raw, true);
        if (!$result) $error = 'Could not parse API response';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paddock Rumors — Query</title>
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
        .container { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        h1 { color: #e3000b; margin-bottom: 6px; }
        h2 { color: #333; margin: 28px 0 14px; padding-bottom: 8px; border-bottom: 2px solid #e3000b; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 24px; }
        .search-row { display: flex; gap: 8px; margin-bottom: 6px; }
        textarea {
            flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 8px;
            font-size: 15px; font-family: inherit; resize: vertical; min-height: 60px;
        }
        button {
            padding: 10px 24px; background: #e3000b; color: #fff;
            border: none; border-radius: 8px; cursor: pointer; font-size: 15px;
            align-self: flex-start;
        }
        button:hover { background: #b80009; }
        .examples { color: #888; font-size: 13px; margin-bottom: 20px; }
        .examples span { cursor: pointer; color: #1976d2; text-decoration: underline; margin-right: 12px; }
        .info  { background: #e3f2fd; padding: 12px 16px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #1976d2; line-height: 1.6; }
        .warn  { background: #fff3e0; padding: 12px 16px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #f57c00; }
        .error { background: #fce4ec; padding: 12px 16px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #c62828; color: #c62828; }
        .answer-box {
            background: #f9f9f9; padding: 20px; border-radius: 8px;
            border-left: 4px solid #e3000b; line-height: 1.7; font-size: 15px;
            margin-bottom: 20px; white-space: pre-wrap;
        }
        .meta { color: #999; font-size: 12px; margin-bottom: 16px; }
        .source-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
        .source {
            border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px;
            font-size: 13px;
        }
        .source-id   { font-family: monospace; font-size: 11px; color: #aaa; margin-bottom: 4px; }
        .source-title { font-weight: 600; color: #222; margin-bottom: 6px; }
        .source-tags { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 6px; }
        .tag {
            font-size: 11px; padding: 2px 7px; border-radius: 10px; font-weight: 600;
            background: #e3f2fd; color: #1565c0;
        }
        .tag.type-race      { background: #fce4ec; color: #880e4f; }
        .tag.type-driver    { background: #e8f5e9; color: #1b5e20; }
        .tag.type-qualifying { background: #fff8e1; color: #f57f17; }
        .tag.type-analysis  { background: #fff3e0; color: #e65100; }
        .source-snippet { color: #555; line-height: 1.5; }
        .config-warn code { background: #f5f5f5; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #ddd; color: #aaa; font-size: 12px; }
        .nav { margin-bottom: 20px; font-size: 13px; }
        .nav a { color: #1976d2; text-decoration: none; margin-right: 16px; }
        .nav a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <h1>Paddock Rumors — Query</h1>
    <p class="subtitle">Ask about 2026 race results, qualifying, driver form and technical analysis</p>

    <div class="nav">
        <a href="test.php">KB Inspector</a>
        <a href="query.php">Query (you are here)</a>
    </div>

    <?php if (PADDOCK_RUMORS_API_URL === 'https://YOUR-PADDOCK-RUMORS.vercel.app'): ?>
        <div class="warn config-warn">
            <strong>Configuration required.</strong><br>
            Add <code>define('PADDOCK_RUMORS_API_URL', 'https://your-app.vercel.app');</code>
            to your server's <code>config.php</code>.
        </div>
    <?php else: ?>

    <form method="post">
        <div class="search-row">
            <textarea name="q" placeholder="e.g. Who has the best qualifying pace at street circuits this season?"><?= htmlspecialchars($query) ?></textarea>
            <button type="submit">Ask</button>
        </div>
        <div class="examples">
            Try:
            <span onclick="document.querySelector('textarea').value=this.innerText">How has Antonelli performed so far in 2026?</span>
            <span onclick="document.querySelector('textarea').value=this.innerText">Who qualifies well at street circuits?</span>
            <span onclick="document.querySelector('textarea').value=this.innerText">Which teams have shown reliability issues?</span>
            <span onclick="document.querySelector('textarea').value=this.innerText">What does the Canadian GP result tell us about Mercedes pace?</span>
        </div>
    </form>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <h2>Answer</h2>
        <div class="meta">
            Query: <em><?= htmlspecialchars($result['query'] ?? $query) ?></em>
            &nbsp;·&nbsp; <?= count($result['sources'] ?? []) ?> source(s) used
            &nbsp;·&nbsp; <?= $elapsed ?>s
            &nbsp;·&nbsp; KB: <?= $result['kb_size'] ?? '?' ?> docs
        </div>
        <div class="answer-box"><?= htmlspecialchars($result['answer'] ?? '') ?></div>

        <?php if (!empty($result['sources'])): ?>
        <h2>Sources</h2>
        <div class="source-grid">
            <?php foreach ($result['sources'] as $src): ?>
                <?php $type = $src['type'] ?? 'unknown'; ?>
                <div class="source">
                    <div class="source-id"><?= htmlspecialchars($src['id'] ?? '') ?></div>
                    <div class="source-title"><?= htmlspecialchars($src['title'] ?? '') ?></div>
                    <div class="source-tags">
                        <span class="tag type-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></span>
                        <?php if (!empty($src['season'])): ?>
                            <span class="tag">season <?= (int)$src['season'] ?></span>
                        <?php endif; ?>
                        <?php if (!empty($src['round'])): ?>
                            <span class="tag">round <?= (int)$src['round'] ?></span>
                        <?php endif; ?>
                        <span class="tag">score <?= $src['score'] ?></span>
                    </div>
                    <div class="source-snippet"><?= htmlspecialchars($src['snippet'] ?? '') ?>…</div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        Paddock Rumors Query &nbsp;·&nbsp; <?= htmlspecialchars(defined('APP_ENV') ? APP_ENV : '') ?>
        &nbsp;·&nbsp; <?= date('Y-m-d H:i:s') ?>
    </div>
</div>

<script>
// Allow Shift+Enter to submit
document.querySelector('textarea')?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        e.target.closest('form').submit();
    }
});
</script>
</body>
</html>
