<?php
// $logFiles / $currentLog / $logLines / $logTailLines fetched in admin.php (case 'logs').
function formatLogSize($bytes) {
    return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
}
?>

<nav class="admin-nav mb-2" aria-label="<?= t('logs') ?>">
    <?php foreach ($logFiles as $key => $lf): ?>
        <a href="?tab=logs&log=<?= $key ?>" class="admin-nav-tab <?= $currentLog === $key ? 'active' : '' ?>"><?= escape($lf['label']) ?></a>
    <?php endforeach; ?>
</nav>

<div class="card mb-2">
    <div class="card-body flex items-center justify-between" style="flex-wrap:wrap;gap:0.5rem;">
        <div>
            <strong><?= escape($logFiles[$currentLog]['label']) ?></strong>
            <br><small class="text-muted" style="font-family:var(--font-mono);word-break:break-all;"><?= escape($logFiles[$currentLog]['path']) ?></small>
        </div>
        <?php if ($logFiles[$currentLog]['exists']): ?>
            <div class="text-muted" style="text-align:right;">
                <div><?= formatLogSize($logFiles[$currentLog]['size']) ?></div>
                <small><?= t('logs_last_modified') ?> <?= date('d M Y, H:i:s', $logFiles[$currentLog]['mtime']) ?></small>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$logFiles[$currentLog]['exists']): ?>
    <div class="card"><div class="card-body text-center text-muted"><?= t('logs_not_found') ?></div></div>
<?php elseif (empty($logLines)): ?>
    <div class="card"><div class="card-body text-center text-muted"><?= t('logs_empty') ?></div></div>
<?php else: ?>
    <p class="text-muted mb-1"><?= sprintf(t('logs_showing_last'), count($logLines), $logTailLines) ?></p>
    <pre style="background:var(--bg-card);border:1px solid var(--border-soft);border-radius:10px;padding:1rem;max-height:70vh;overflow:auto;font-family:var(--font-mono);font-size:0.8rem;line-height:1.5;white-space:pre-wrap;word-break:break-all;"><?php
        foreach ($logLines as $line) {
            echo escape($line) . "\n";
        }
    ?></pre>
<?php endif; ?>
