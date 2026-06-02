<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$kb_path = __DIR__ . '/knowledge-base.json';
$kb      = null;
$error   = null;

if (!file_exists($kb_path)) {
    $error = 'not_generated';
} else {
    $raw = file_get_contents($kb_path);
    $kb  = json_decode($raw, true);
    if (!is_array($kb)) {
        $error = 'invalid_json';
    }
}

// Stats
$stats = [];
if ($kb) {
    $by_type   = [];
    $seasons   = [];
    $last_at   = null;
    foreach ($kb as $doc) {
        $type = $doc['tags']['type'] ?? 'unknown';
        $by_type[$type] = ($by_type[$type] ?? 0) + 1;
        if (!empty($doc['tags']['season'])) {
            $seasons[$doc['tags']['season']] = true;
        }
        if (!empty($doc['updated_at']) && ($last_at === null || $doc['updated_at'] > $last_at)) {
            $last_at = $doc['updated_at'];
        }
    }
    ksort($by_type);
    ksort($seasons);
    $stats = [
        'total'    => count($kb),
        'by_type'  => $by_type,
        'seasons'  => array_keys($seasons),
        'last_at'  => $last_at ? substr($last_at, 0, 19) . ' UTC' : '—',
    ];
}

// Search
$query   = trim($_GET['q'] ?? '');
$filter_type  = trim($_GET['type'] ?? '');
$filter_round = isset($_GET['round']) && ctype_digit($_GET['round']) ? (int)$_GET['round'] : null;
$results = [];

if ($kb && ($query !== '' || $filter_type !== '' || $filter_round !== null)) {
    $terms = $query !== '' ? array_filter(explode(' ', strtolower($query))) : [];
    foreach ($kb as $doc) {
        if ($filter_type !== '' && ($doc['tags']['type'] ?? '') !== $filter_type) continue;
        if ($filter_round !== null && ($doc['tags']['round'] ?? null) !== $filter_round) continue;
        if ($terms) {
            $haystack = strtolower(($doc['title'] ?? '') . ' ' . ($doc['content'] ?? ''));
            $hit = true;
            foreach ($terms as $t) {
                if (strpos($haystack, $t) === false) { $hit = false; break; }
            }
            if (!$hit) continue;
        }
        $results[] = $doc;
        if (count($results) >= 30) break;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paddock Rumors — KB Inspector</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            max-width: 960px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
            color: #333;
        }
        .container { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        h1 { color: #e3000b; margin-bottom: 6px; }
        h2 { color: #333; margin: 28px 0 14px; padding-bottom: 8px; border-bottom: 2px solid #e3000b; }
        .info  { background: #e3f2fd; padding: 12px 16px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #1976d2; line-height: 1.6; }
        .warn  { background: #fff3e0; padding: 12px 16px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #f57c00; }
        .stats { display: flex; gap: 16px; flex-wrap: wrap; margin: 14px 0; }
        .stat  { background: #f5f5f5; border-radius: 8px; padding: 12px 18px; min-width: 110px; text-align: center; }
        .stat strong { display: block; font-size: 22px; color: #e3000b; }
        .stat span   { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
        form { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; }
        input[type=text], select {
            padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px;
            font-size: 14px; flex: 1; min-width: 160px;
        }
        button { padding: 8px 20px; background: #e3000b; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #b80009; }
        .results-count { color: #555; font-size: 14px; margin-bottom: 12px; }
        .doc { border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; }
        .doc-id    { font-family: monospace; font-size: 12px; color: #888; margin-bottom: 4px; }
        .doc-title { font-weight: 600; font-size: 15px; color: #111; margin-bottom: 6px; }
        .doc-tags  { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .tag {
            font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 600;
            background: #e3f2fd; color: #1565c0;
        }
        .tag.type-race     { background: #fce4ec; color: #880e4f; }
        .tag.type-driver   { background: #e8f5e9; color: #1b5e20; }
        .tag.type-analysis { background: #fff3e0; color: #e65100; }
        .doc-content { font-size: 13px; line-height: 1.6; color: #444; }
        .doc-meta  { font-size: 11px; color: #aaa; margin-top: 8px; }
        code { background: #f5f5f5; padding: 1px 5px; border-radius: 3px; font-family: monospace; font-size: 13px; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #ddd; color: #aaa; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Paddock Rumors — KB Inspector</h1>
    <p style="color:#666;font-size:14px">Content quality monitor for the auto-updating knowledge base</p>

    <?php if ($error === 'not_generated'): ?>
        <h2>Status</h2>
        <div class="warn">
            <strong>Knowledge base not yet generated.</strong><br>
            Run <code>F1TECH_ENRICH=0 node update-kb.js</code> in <code>paddock-rumors/</code> locally,
            then <code>npm run deploy:test</code> to upload the JSON to this server.<br>
            Once the GitHub Actions cron runs after a race weekend it will commit and you can redeploy.
        </div>
    <?php elseif ($error === 'invalid_json'): ?>
        <h2>Status</h2>
        <div class="warn">
            <strong>knowledge-base.json is not valid JSON.</strong>
            The file may have been written during a failed pipeline run.
            Re-run <code>node update-kb.js</code> and redeploy.
        </div>
    <?php else: ?>

        <h2>Stats</h2>
        <div class="stats">
            <div class="stat"><strong><?= $stats['total'] ?></strong><span>Total docs</span></div>
            <?php foreach ($stats['by_type'] as $type => $count): ?>
                <div class="stat"><strong><?= $count ?></strong><span><?= htmlspecialchars($type) ?></span></div>
            <?php endforeach; ?>
            <div class="stat"><strong><?= implode(', ', $stats['seasons']) ?></strong><span>Season(s)</span></div>
        </div>
        <div class="info">
            Last updated: <strong><?= htmlspecialchars($stats['last_at']) ?></strong>
        </div>

        <h2>Search</h2>
        <form method="get">
            <input type="text" name="q" placeholder="Keywords (e.g. verstappen canada)"
                   value="<?= htmlspecialchars($query) ?>">
            <select name="type">
                <option value="">All types</option>
                <?php foreach (array_keys($stats['by_type']) as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"
                        <?= $filter_type === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="round" placeholder="Round #"
                   style="max-width:100px"
                   value="<?= $filter_round !== null ? $filter_round : '' ?>">
            <button type="submit">Search</button>
            <?php if ($query !== '' || $filter_type !== '' || $filter_round !== null): ?>
                <a href="?" style="padding:8px 14px;color:#555;font-size:14px;text-decoration:none">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($query !== '' || $filter_type !== '' || $filter_round !== null): ?>
            <p class="results-count"><?= count($results) ?> result(s)</p>
            <?php if (empty($results)): ?>
                <div class="warn">No documents matched. Try broader terms.</div>
            <?php else: ?>
                <?php foreach ($results as $doc): ?>
                    <?php
                        $type    = $doc['tags']['type'] ?? 'unknown';
                        $round   = $doc['tags']['round'] ?? null;
                        $season  = $doc['tags']['season'] ?? null;
                        $driver  = $doc['tags']['driver'] ?? null;
                        $source  = $doc['tags']['source'] ?? null;
                        $snippet = mb_substr($doc['content'] ?? '', 0, 400);
                        if (mb_strlen($doc['content'] ?? '') > 400) $snippet .= '…';
                    ?>
                    <div class="doc">
                        <div class="doc-id"><?= htmlspecialchars($doc['id'] ?? '') ?></div>
                        <div class="doc-title"><?= htmlspecialchars($doc['title'] ?? '(no title)') ?></div>
                        <div class="doc-tags">
                            <span class="tag type-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></span>
                            <?php if ($season !== null): ?>
                                <span class="tag">season <?= (int)$season ?></span>
                            <?php endif; ?>
                            <?php if ($round !== null): ?>
                                <span class="tag">round <?= (int)$round ?></span>
                            <?php endif; ?>
                            <?php if ($driver): ?>
                                <span class="tag"><?= htmlspecialchars($driver) ?></span>
                            <?php endif; ?>
                            <?php if ($source): ?>
                                <span class="tag"><?= htmlspecialchars($source) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="doc-content"><?= htmlspecialchars($snippet) ?></div>
                        <div class="doc-meta">
                            updated: <?= htmlspecialchars(substr($doc['updated_at'] ?? '', 0, 19)) ?>
                            <?php if (!empty($doc['source_url'])): ?>
                                &nbsp;·&nbsp; <a href="<?= htmlspecialchars($doc['source_url']) ?>" target="_blank" rel="noopener">source</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        Paddock Rumors KB Inspector &nbsp;·&nbsp; <?= htmlspecialchars(defined('APP_ENV') ? APP_ENV : '') ?>
        &nbsp;·&nbsp; <?= date('Y-m-d H:i:s') ?>
    </div>
</div>
</body>
</html>
