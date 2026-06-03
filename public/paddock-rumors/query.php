<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if (!defined('PADDOCK_RUMORS_API_URL')) {
    define('PADDOCK_RUMORS_API_URL', 'https://YOUR-PADDOCK-RUMORS.vercel.app');
}
if (!defined('PADDOCK_RUMORS_TIMEOUT')) {
    define('PADDOCK_RUMORS_TIMEOUT', 30);
}

// Clear history
if (isset($_POST['clear_history'])) {
    $_SESSION['paddock_history'] = [];
    header('Location: query.php');
    exit;
}

if (!isset($_SESSION['paddock_history'])) {
    $_SESSION['paddock_history'] = [];
}

$query   = trim($_POST['q'] ?? '');
$error   = null;

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
    $raw     = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerr = curl_error($ch);
    $elapsed = round(microtime(true) - $t0, 2);

    if ($curlerr) {
        $error = 'Connection failed: ' . $curlerr;
    } elseif ($code !== 200) {
        $decoded = json_decode($raw, true);
        $error = 'API error ' . $code . ': ' . ($decoded['error'] ?? $raw);
    } else {
        $result = json_decode($raw, true);
        if ($result) {
            // Prepend so newest is first
            array_unshift($_SESSION['paddock_history'], [
                'query'   => $query,
                'answer'  => $result['answer'] ?? '',
                'sources' => $result['sources'] ?? [],
                'elapsed' => $elapsed,
                'kb_size' => $result['kb_size'] ?? '?',
                'time'    => date('H:i:s'),
            ]);
            // Cap at 20 entries
            $_SESSION['paddock_history'] = array_slice($_SESSION['paddock_history'], 0, 20);
        } else {
            $error = 'Could not parse API response';
        }
    }
}

$history = $_SESSION['paddock_history'];
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paddock Rumors — Query</title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
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
        .subtitle { color: #666; font-size: 14px; margin-bottom: 20px; }
        .nav { margin-bottom: 20px; font-size: 13px; }
        .nav a { color: #1976d2; text-decoration: none; margin-right: 16px; }
        .nav a:hover { text-decoration: underline; }

        /* Input */
        .search-row { display: flex; gap: 8px; margin-bottom: 6px; }
        textarea {
            flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 8px;
            font-size: 15px; font-family: inherit; resize: vertical; min-height: 56px;
        }
        textarea:focus { outline: none; border-color: #e3000b; }
        .btn-ask {
            padding: 10px 24px; background: #e3000b; color: #fff;
            border: none; border-radius: 8px; cursor: pointer; font-size: 15px; align-self: flex-start;
        }
        .btn-ask:hover { background: #b80009; }
        .examples { color: #888; font-size: 13px; margin-bottom: 24px; line-height: 1.8; }
        .examples span { cursor: pointer; color: #1976d2; text-decoration: underline; margin-right: 12px; }

        /* Alerts */
        .error { background: #fce4ec; padding: 12px 16px; border-radius: 6px; margin: 12px 0; border-left: 4px solid #c62828; color: #c62828; }
        .warn  { background: #fff3e0; padding: 12px 16px; border-radius: 6px; margin: 12px 0; border-left: 4px solid #f57c00; }

        /* History */
        .history-header {
            display: flex; justify-content: space-between; align-items: center;
            margin: 24px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e3000b;
        }
        .history-header h2 { color: #333; }
        .btn-clear {
            padding: 5px 14px; background: #f5f5f5; color: #666;
            border: 1px solid #ccc; border-radius: 6px; cursor: pointer; font-size: 13px;
        }
        .btn-clear:hover { background: #eee; }

        /* Q&A entry */
        .qa-entry { margin-bottom: 28px; }
        .qa-entry + .qa-entry { border-top: 1px solid #f0f0f0; padding-top: 24px; }
        .qa-question {
            font-weight: 600; font-size: 15px; color: #111; margin-bottom: 10px;
            display: flex; gap: 10px; align-items: flex-start;
        }
        .qa-question .q-icon { color: #e3000b; flex-shrink: 0; }
        .qa-meta { color: #aaa; font-size: 11px; margin-bottom: 10px; }
        .answer-box {
            background: #f9f9f9; padding: 18px 20px; border-radius: 8px;
            border-left: 4px solid #e3000b; line-height: 1.7; font-size: 14px;
            margin-bottom: 12px;
        }
        /* Markdown styles inside answer */
        .answer-box h2, .answer-box h3 { color: #333; margin: 14px 0 6px; font-size: 15px; }
        .answer-box h2 { border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        .answer-box p  { margin-bottom: 8px; }
        .answer-box ul, .answer-box ol { padding-left: 20px; margin-bottom: 8px; }
        .answer-box li { margin-bottom: 4px; }
        .answer-box strong { font-weight: 700; }
        .answer-box em    { font-style: italic; }
        .answer-box hr    { border: none; border-top: 1px solid #ddd; margin: 12px 0; }
        .answer-box table { border-collapse: collapse; width: 100%; margin-bottom: 8px; font-size: 13px; }
        .answer-box th, .answer-box td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        .answer-box th { background: #f5f5f5; font-weight: 600; }
        .answer-box code { background: #eee; padding: 1px 5px; border-radius: 3px; font-family: monospace; font-size: 12px; }

        /* Sources */
        .sources-toggle {
            font-size: 13px; color: #1976d2; cursor: pointer; background: none;
            border: none; padding: 0; text-decoration: underline;
        }
        .source-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px; margin-top: 10px; }
        .source { border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px; font-size: 12px; }
        .source-id    { font-family: monospace; font-size: 10px; color: #bbb; margin-bottom: 3px; }
        .source-title { font-weight: 600; color: #222; margin-bottom: 5px; font-size: 12px; }
        .source-tags  { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 5px; }
        .tag { font-size: 10px; padding: 1px 6px; border-radius: 10px; font-weight: 600; background: #e3f2fd; color: #1565c0; }
        .tag.type-race       { background: #fce4ec; color: #880e4f; }
        .tag.type-driver     { background: #e8f5e9; color: #1b5e20; }
        .tag.type-qualifying { background: #fff8e1; color: #f57f17; }
        .tag.type-analysis   { background: #fff3e0; color: #e65100; }
        .source-snippet { color: #666; line-height: 1.4; }

        .empty-state { text-align: center; color: #aaa; padding: 40px 0; font-size: 15px; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #ddd; color: #aaa; font-size: 12px; }
        .config-warn code { background: #f5f5f5; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
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

    <form method="post" id="queryForm">
        <div class="search-row">
            <textarea name="q" id="queryInput"
                placeholder="e.g. Who would qualify in top 5 at Monaco and of those who takes the podium?"><?= htmlspecialchars($query) ?></textarea>
            <button type="submit" class="btn-ask">Ask</button>
        </div>
        <div class="examples">
            Try:
            <span onclick="setQ(this)">How has Antonelli performed so far in 2026?</span>
            <span onclick="setQ(this)">Who qualifies well at street circuits?</span>
            <span onclick="setQ(this)">Which teams have shown reliability issues?</span>
            <span onclick="setQ(this)">What does the Canadian GP tell us about Mercedes pace?</span>
        </div>
    </form>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($history)): ?>
        <div class="history-header">
            <h2>Conversation</h2>
            <form method="post" style="margin:0">
                <button name="clear_history" value="1" class="btn-clear"
                    onclick="return confirm('Clear all conversation history?')">Clear history</button>
            </form>
        </div>

        <?php foreach ($history as $i => $entry): ?>
            <div class="qa-entry">
                <div class="qa-question">
                    <span class="q-icon">Q</span>
                    <span><?= htmlspecialchars($entry['query']) ?></span>
                </div>
                <div class="qa-meta">
                    <?= htmlspecialchars($entry['time']) ?>
                    &nbsp;·&nbsp; <?= count($entry['sources']) ?> source(s)
                    &nbsp;·&nbsp; <?= $entry['elapsed'] ?>s
                    &nbsp;·&nbsp; KB: <?= $entry['kb_size'] ?> docs
                </div>
                <div class="answer-box markdown-body" data-md="<?= htmlspecialchars($entry['answer']) ?>"></div>

                <?php if (!empty($entry['sources'])): ?>
                    <button class="sources-toggle" onclick="toggleSources(this)">
                        Show <?= count($entry['sources']) ?> source(s)
                    </button>
                    <div class="source-grid" style="display:none">
                        <?php foreach ($entry['sources'] as $src): ?>
                            <?php $type = $src['type'] ?? 'unknown'; ?>
                            <div class="source">
                                <div class="source-id"><?= htmlspecialchars($src['id'] ?? '') ?></div>
                                <div class="source-title"><?= htmlspecialchars($src['title'] ?? '') ?></div>
                                <div class="source-tags">
                                    <span class="tag type-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></span>
                                    <?php if (!empty($src['season'])): ?>
                                        <span class="tag">s<?= (int)$src['season'] ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($src['round'])): ?>
                                        <span class="tag">r<?= (int)$src['round'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="source-snippet"><?= htmlspecialchars($src['snippet'] ?? '') ?>…</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state">Ask a question above to get started.</div>
    <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        Paddock Rumors Query &nbsp;·&nbsp; <?= htmlspecialchars(defined('APP_ENV') ? APP_ENV : '') ?>
        &nbsp;·&nbsp; <?= date('Y-m-d H:i:s') ?>
    </div>
</div>

<script>
// Render all markdown answer boxes
document.querySelectorAll('.markdown-body[data-md]').forEach(el => {
    el.innerHTML = marked.parse(el.dataset.md);
    delete el.dataset.md;
});

function setQ(el) {
    document.getElementById('queryInput').value = el.innerText;
    document.getElementById('queryInput').focus();
}

function toggleSources(btn) {
    const grid = btn.nextElementSibling;
    const shown = grid.style.display !== 'none';
    grid.style.display = shown ? 'none' : 'grid';
    btn.textContent = shown
        ? btn.textContent.replace('Hide', 'Show')
        : btn.textContent.replace('Show', 'Hide');
}

// Enter submits, Shift+Enter adds newline
document.getElementById('queryInput')?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('queryForm').submit();
    }
});
</script>
</body>
</html>
