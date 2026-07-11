<?php
// $ipBuckets / $acctBuckets / $usersById are fetched in admin.php (case 'security').
// Locked = the bucket's attempt count has hit the same threshold isRateLimited() enforces —
// reuse rateLimitThreshold() directly so this view can never drift from the real lockout state.
?>

<h3 class="mb-1"><i class="fas fa-network-wired text-accent"></i> <?= t('security_by_ip') ?> (<?= count($ipBuckets) ?>)</h3>
<?php if (!empty($ipBuckets)): ?>
    <?php foreach ($ipBuckets as $bucket): ?>
        <?php $threshold = rateLimitThreshold($bucket['scope'], 'ip'); $locked = $bucket['attempts'] >= $threshold; ?>
        <div class="card mb-1">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($bucket['bucket_key']) ?></strong>
                    <span class="badge" style="background: var(--bg-secondary); margin-left: 0.5rem;"><?= $bucket['scope'] === 'mfa' ? t('security_scope_mfa') : t('security_scope_login') ?></span>
                    <?php if ($locked): ?>
                        <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;"><?= t('security_locked') ?></span>
                    <?php endif; ?>
                    <br><small class="text-muted"><?= t('security_last_attempt') ?> <?= date('d M Y, H:i', strtotime($bucket['last_attempt'])) ?></small>
                </div>
                <span class="text-accent"><?= intval($bucket['attempts']) ?> / <?= $threshold ?></span>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="card mb-2"><div class="card-body text-center text-muted"><?= t('security_no_attempts') ?></div></div>
<?php endif; ?>

<h3 class="mb-1 mt-2"><i class="fas fa-user-lock text-accent"></i> <?= t('security_by_account') ?> (<?= count($acctBuckets) ?>)</h3>
<?php if (!empty($acctBuckets)): ?>
    <?php foreach ($acctBuckets as $bucket): ?>
        <?php
        $threshold = rateLimitThreshold($bucket['scope'], 'account');
        $locked = $bucket['attempts'] >= $threshold;
        if ($bucket['scope'] === 'mfa') {
            $mfaUser = $usersById[$bucket['bucket_key']] ?? null;
            $identifier = $mfaUser ? ($mfaUser['display_name'] ?: $mfaUser['email']) : $bucket['bucket_key'];
        } else {
            $identifier = $bucket['bucket_key'];
        }
        ?>
        <div class="card mb-1">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($identifier) ?></strong>
                    <span class="badge" style="background: var(--bg-secondary); margin-left: 0.5rem;"><?= $bucket['scope'] === 'mfa' ? t('security_scope_mfa') : t('security_scope_login') ?></span>
                    <?php if ($locked): ?>
                        <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;"><?= t('security_locked') ?></span>
                    <?php endif; ?>
                    <br><small class="text-muted"><?= t('security_last_attempt') ?> <?= date('d M Y, H:i', strtotime($bucket['last_attempt'])) ?></small>
                </div>
                <div class="flex gap-1 items-center">
                    <span class="text-accent"><?= intval($bucket['attempts']) ?> / <?= $threshold ?></span>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="scope" value="<?= escape($bucket['scope']) ?>">
                        <input type="hidden" name="account" value="<?= escape($bucket['bucket_key']) ?>">
                        <button type="submit" name="clear_login_attempts" class="btn btn-secondary btn-sm">
                            <?= t('security_clear') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="card"><div class="card-body text-center text-muted"><?= t('security_no_attempts') ?></div></div>
<?php endif; ?>
