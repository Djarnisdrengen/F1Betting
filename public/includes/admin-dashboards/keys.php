<?php
// Nøgler & Rotation — see epics/Admin settings and dashboards/feature-3-nogler-rotation.md.
// The one dashboard in this epic with a real privileged side effect; read the "implementation"
// revision note at the top of that doc (and nogler-rotation-lib.php) before touching this file
// — most secrets here are record-only on purpose, not an oversight.

$tokens   = nrGetTokens($db);
$secrets  = nrGetSecrets($db);
$kpis     = nrComputeKpis($tokens, $secrets);
$auditLog = nrGetAuditLog($db, 8);
$nrMsg    = $_GET['nr_msg'] ?? '';

$envLabel = (defined('APP_ENV') && APP_ENV === 'live') ? 'Live' : 'Test';

// Reveal-once panel for a just-rotated 'auto' secret — read + immediately unset, so a page
// refresh (or navigating away and back) never shows the value a second time. See the
// "newValue" comment in nrRotateSecret() for why this is the only place it's readable at all.
$rotatedFlash = $_SESSION['flash_nr_rotated'] ?? null;
unset($_SESSION['flash_nr_rotated']);
$rotatedCfg = ($rotatedFlash && isset($rotatedFlash['itemKey'])) ? (nrSecretConfig()[$rotatedFlash['itemKey']] ?? null) : null;
if ($rotatedFlash && !$rotatedCfg) $rotatedFlash = null; // unknown item_key — nothing to show
$rotatedGithubSecret = $rotatedFlash ? nrGithubSecretName($rotatedFlash['itemKey']) : null;
$rotatedConfigFile   = 'config.' . ((defined('APP_ENV') && APP_ENV === 'live') ? 'live' : 'test') . '.php';

$tokenBadgeMeta = [
    'ok'      => ['label' => t('admin_dash_nr_badge_ok'),      'color' => 'var(--status-success-light)'],
    'warn'    => ['label' => t('admin_dash_nr_badge_warn'),    'color' => 'var(--status-warning-light)'],
    'bad'     => ['label' => t('admin_dash_nr_badge_bad'),     'color' => 'var(--status-danger-light)'],
    'unknown' => ['label' => t('admin_dash_nr_badge_unknown'), 'color' => 'var(--text-muted)'],
];
$secretBadgeMeta = [
    'ok'      => ['label' => t('admin_dash_nr_sbadge_ok'),   'color' => 'var(--status-success-light)'],
    'due'     => ['label' => t('admin_dash_nr_sbadge_due'),  'color' => 'var(--status-warning-light)'],
    'over'    => ['label' => t('admin_dash_nr_sbadge_over'), 'color' => 'var(--status-danger-light)'],
    'unknown' => ['label' => t('admin_dash_nr_badge_unknown'), 'color' => 'var(--text-muted)'],
];

$actionItems = [];
foreach ($tokens as $t) {
    if ($t['badge'] === 'bad') {
        $actionItems[] = ['icon' => $t['icon'], 'color' => 'var(--status-danger-light)', 'text' => sprintf(t('admin_dash_nr_action_token_expired'), $t['name'])];
    } elseif ($t['badge'] === 'warn') {
        $actionItems[] = ['icon' => $t['icon'], 'color' => 'var(--status-warning-light)', 'text' => sprintf(t('admin_dash_nr_action_token_soon'), $t['name'], $t['daysUntilExpiry'])];
    }
}
foreach ($secrets as $s) {
    if ($s['badge'] === 'over') {
        $actionItems[] = ['icon' => $s['icon'], 'color' => 'var(--status-danger-light)', 'text' => sprintf(t('admin_dash_nr_action_secret_over'), $s['name'], $s['ageDays'] ?? 0)];
    } elseif ($s['badge'] === 'due') {
        $actionItems[] = ['icon' => $s['icon'], 'color' => 'var(--status-warning-light)', 'text' => sprintf(t('admin_dash_nr_action_secret_due'), $s['name'])];
    }
}

$healthColor = $kpis['health'] >= 80 ? '#10b981' : ($kpis['health'] >= 55 ? '#f59e0b' : '#ef4444');
$today = date('Y-m-d');
?>

<?php if ($nrMsg !== ''): ?>
    <?php $msgKey = 'admin_dash_nr_msg_' . $nrMsg; $isOk = str_ends_with($nrMsg, 'recorded') || str_ends_with($nrMsg, 'rotated'); ?>
    <div class="alert <?= $isOk ? 'alert-success' : 'alert-danger' ?>"><?= escape(t($msgKey)) ?></div>
<?php endif; ?>

<?php if ($rotatedFlash): ?>
    <div class="gha-panel" id="nr-reveal-panel" data-item-key="<?= escape($rotatedFlash['itemKey']) ?>" style="padding:16px 18px;margin-bottom:18px;border:1px solid var(--f1-red)">
        <div style="font-weight:800;font-size:14px;margin-bottom:6px">
            <i class="fas fa-triangle-exclamation" style="color:var(--f1-red);margin-right:7px"></i><?= escape(sprintf(t('admin_dash_nr_reveal_title'), $rotatedCfg['name'])) ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= t('admin_dash_nr_reveal_once_hint') ?></div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
            <input type="text" readonly value="<?= escape($rotatedFlash['newValue']) ?>" id="nr-reveal-value" data-select-on-click
                   style="flex:1;font-family:var(--font-mono);font-size:12px;padding:7px 10px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary)">
            <button type="button" class="btn btn-sm" data-copy-target="nr-reveal-value" style="border:1px solid var(--f1-red);color:var(--f1-red);background:transparent">
                <i class="fas fa-copy"></i> <?= t('admin_dash_nr_copy') ?>
            </button>
        </div>
        <ul style="margin:0;padding-left:18px;font-size:12px;color:var(--text-secondary);line-height:1.7">
            <?php if ($rotatedGithubSecret): ?>
                <li><?= escape(sprintf(t('admin_dash_nr_reveal_github'), $rotatedGithubSecret)) ?></li>
            <?php endif; ?>
            <li><?= escape(sprintf(t('admin_dash_nr_reveal_local_config'), $rotatedCfg['configConst'], $rotatedConfigFile)) ?></li>
        </ul>
    </div>
<?php endif; ?>

<h2 style="font-size:16px;margin-bottom:16px"><?= escape(sprintf(t('admin_dash_nr_env_label'), $envLabel)) ?></h2>

<div class="dash-action-queue" style="border:1px solid rgba(225,6,0,0.45);background:linear-gradient(90deg,rgba(225,6,0,0.10),transparent 60%)">
    <div style="display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:9px">
            <i class="fas fa-triangle-exclamation" style="color:var(--f1-red)"></i>
            <span style="font-weight:800;font-size:14px"><?= t('admin_dash_nr_action_required') ?></span>
        </div>
        <span class="label-mono" style="font-size:20px;color:var(--f1-red)"><?= count($actionItems) ?></span>
    </div>
    <div style="margin-top:12px">
        <?php if (empty($actionItems)): ?>
            <div class="dash-action-row"><i class="fas fa-circle-check" style="color:var(--status-success-light)"></i><?= t('admin_dash_nr_all_healthy') ?></div>
        <?php else: foreach ($actionItems as $a): ?>
            <div class="dash-action-row"><i class="<?= $a['icon'] ?>" style="color:<?= $a['color'] ?>;width:16px;text-align:center"></i><?= escape($a['text']) ?></div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:130px 1fr;gap:18px;align-items:center;margin-bottom:20px">
    <div style="display:flex;justify-content:center">
        <div class="dash-health-ring" style="background:conic-gradient(<?= $healthColor ?> <?= $kpis['health'] ?>%, var(--border-color) 0)">
            <div class="dash-health-ring-inner">
                <span class="dash-health-score"><?= $kpis['health'] ?></span>
                <span class="dash-health-label"><?= t('admin_dash_nr_health_label') ?></span>
            </div>
        </div>
    </div>
    <div class="gha-summary" style="grid-template-columns:repeat(3,1fr)">
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_expired') ?></div><div class="gha-stat-value danger"><?= $kpis['expiredTokens'] ?></div></div>
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_soon') ?></div><div class="gha-stat-value" style="color:var(--status-warning-light)"><?= $kpis['soonCount'] ?></div></div>
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_overdue') ?></div><div class="gha-stat-value danger"><?= $kpis['overdueSecrets'] ?></div></div>
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_last_rotation') ?></div><div class="gha-stat-value" style="font-size:16px"><?= $kpis['lastRotationDate'] ? escape(date('d M', strtotime($kpis['lastRotationDate']))) : t('admin_dash_nr_never') ?></div></div>
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_secret_count') ?></div><div class="gha-stat-value"><?= $kpis['secretCount'] ?></div></div>
        <div class="gha-stat-card"><div class="gha-stat-label"><?= t('admin_dash_nr_kpi_token_count') ?></div><div class="gha-stat-value"><?= $kpis['tokenCount'] ?></div></div>
    </div>
</div>

<section class="gha-panel" style="padding:16px 18px;margin-bottom:18px">
    <h3 style="margin:0 0 12px;font-size:15px"><i class="fas fa-plug" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_nr_tokens_title') ?></h3>
    <?php foreach ($tokens as $t): $meta = $tokenBadgeMeta[$t['badge']]; ?>
    <div data-item-key="<?= escape($t['key']) ?>" style="display:grid;grid-template-columns:30px 1fr auto;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--border-color)">
        <i class="<?= $t['icon'] ?>" style="color:var(--text-secondary);font-size:17px;text-align:center"></i>
        <div style="min-width:0">
            <div style="font-weight:700;font-size:13px"><?= escape($t['name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:1px">
                <?php if ($t['rotatedAt']): ?>
                    <?= escape(sprintf(t('admin_dash_nr_last_rotated'), date('d M', strtotime($t['rotatedAt'])), escape($t['rotatedBy'] ?? '—'))) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px">
            <div style="display:flex;align-items:center;gap:9px">
                <span class="label-mono" style="font-size:11px;color:var(--text-secondary)">
                    <?php if ($t['daysUntilExpiry'] === null): ?>
                        <?= t('admin_dash_nr_expiry_unknown') ?>
                    <?php elseif ($t['daysUntilExpiry'] < 0): ?>
                        <?= escape(sprintf(t('admin_dash_nr_expired_since'), abs($t['daysUntilExpiry']))) ?>
                    <?php else: ?>
                        <?= escape(sprintf(t('admin_dash_nr_expires_in'), $t['daysUntilExpiry'])) ?>
                    <?php endif; ?>
                </span>
                <span class="label-badge" style="padding:3px 9px;border-radius:999px;background:<?= $meta['color'] ?>;color:#fff;font-size:11px"><?= escape($meta['label']) ?></span>
            </div>
            <details class="dash-details">
                <summary style="border:1px solid var(--border-color);cursor:pointer;background:transparent;color:var(--text-secondary);font-family:var(--font-display);font-weight:700;font-size:11px;padding:5px 11px;border-radius:6px;display:inline-block">
                    <i class="fas fa-check" style="margin-right:6px;color:var(--f1-red)"></i><?= t('admin_dash_nr_record_expiry') ?>
                </summary>
                <form method="POST" style="display:flex;align-items:center;gap:6px;margin-top:8px">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="nr_record_token">
                    <input type="hidden" name="item_key" value="<?= escape($t['key']) ?>">
                    <input type="date" name="expires_at" required max="2099-12-31" style="background:var(--bg-primary);border:1px solid var(--f1-red);border-radius:6px;color:var(--text-primary);font-family:var(--font-mono);font-size:12px;padding:4px 8px">
                    <button type="submit" class="btn btn-primary btn-sm"><?= t('admin_dash_nr_save') ?></button>
                    <button type="button" class="btn btn-sm" data-dash-cancel style="border:1px solid var(--border-color);background:transparent;color:var(--text-muted)"><?= t('admin_dash_nr_cancel') ?></button>
                </form>
            </details>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<section class="gha-panel" style="padding:16px 18px;margin-bottom:18px">
    <h3 style="margin:0 0 4px;font-size:15px"><i class="fas fa-file-shield" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_nr_secrets_title') ?></h3>
    <div style="font-size:12px;color:var(--text-muted);margin:0 0 12px"><?= t('admin_dash_nr_secrets_hint') ?></div>
    <?php foreach ($secrets as $s): $meta = $secretBadgeMeta[$s['badge']]; $barColor = $s['badge'] === 'over' ? '#ef4444' : ($s['badge'] === 'due' ? '#f59e0b' : '#10b981'); ?>
    <div data-item-key="<?= escape($s['key']) ?>" data-mode="<?= escape($s['mode']) ?>" style="display:grid;grid-template-columns:24px 1.4fr 1fr auto auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color)">
        <i class="<?= $s['icon'] ?>" style="color:var(--text-muted);font-size:13px;text-align:center"></i>
        <div style="min-width:0">
            <div style="font-family:var(--font-mono);font-weight:700;font-size:13px"><?= escape($s['name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= $s['rotatedBy'] ? escape($s['rotatedBy']) : '' ?></div>
        </div>
        <div>
            <div class="dash-progress-track"><div class="dash-progress-fill" style="width:<?= $s['progressPct'] ?>%;background:<?= $barColor ?>"></div></div>
            <div class="label-mono" style="font-size:10px;color:var(--text-muted);margin-top:4px">
                <?= $s['ageDays'] === null ? t('admin_dash_nr_age_unknown') : escape(sprintf(t('admin_dash_nr_age_of_policy'), $s['ageDays'], $s['policyDays'])) ?>
            </div>
        </div>
        <span class="label-badge" style="padding:3px 8px;border-radius:999px;background:<?= $meta['color'] ?>;color:#fff;font-size:11px;justify-self:end"><?= escape($meta['label']) ?></span>
        <?php if ($s['mode'] === 'auto'): ?>
            <form method="POST" data-confirm-msg="<?= escape(t('admin_dash_nr_rotate_confirm')) ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="nr_rotate_secret">
                <input type="hidden" name="item_key" value="<?= escape($s['key']) ?>">
                <button type="submit" class="btn" style="border:1px solid var(--f1-red);color:var(--f1-red);background:transparent;font-size:11px;padding:5px 11px"><?= t('admin_dash_nr_rotate_now') ?></button>
            </form>
        <?php else: ?>
            <details class="dash-details">
                <summary style="border:1px solid var(--border-color);cursor:pointer;background:transparent;color:var(--text-secondary);font-family:var(--font-display);font-weight:700;font-size:11px;padding:5px 11px;border-radius:6px;display:inline-block"><?= t('admin_dash_nr_record_rotation') ?></summary>
                <form method="POST" style="display:flex;align-items:center;gap:6px;margin-top:8px" data-confirm-msg="<?= escape(t('admin_dash_nr_record_confirm')) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="nr_record_secret">
                    <input type="hidden" name="item_key" value="<?= escape($s['key']) ?>">
                    <input type="date" name="rotated_at" value="<?= $today ?>" max="<?= $today ?>" required style="background:var(--bg-primary);border:1px solid var(--f1-red);border-radius:6px;color:var(--text-primary);font-family:var(--font-mono);font-size:12px;padding:4px 8px">
                    <button type="submit" class="btn btn-primary btn-sm"><?= t('admin_dash_nr_save') ?></button>
                    <button type="button" class="btn btn-sm" data-dash-cancel style="border:1px solid var(--border-color);background:transparent;color:var(--text-muted)"><?= t('admin_dash_nr_cancel') ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>

<section class="gha-panel" style="padding:16px 18px">
    <h3 style="margin:0 0 12px;font-size:15px"><i class="fas fa-clock-rotate-left" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_nr_history_title') ?></h3>
    <?php if (empty($auditLog)): ?>
        <div class="gha-empty"><?= t('admin_dash_nr_history_empty') ?></div>
    <?php else: foreach ($auditLog as $e): ?>
        <div class="dash-run-row" style="grid-template-columns:130px 1fr">
            <span class="dash-run-when"><?= escape(date('d M Y H:i', strtotime($e['created_at']))) ?></span>
            <span style="font-size:12px;color:var(--text-secondary)"><strong style="color:var(--text-primary)"><?= escape($e['actor']) ?></strong> <?= escape($e['action']) ?> <span style="color:var(--text-muted)">(<?= escape($e['item_key']) ?>)</span></span>
        </div>
    <?php endforeach; endif; ?>
</section>

<script nonce="<?= $nonce ?>">
// Confirmation step for the two write actions on this page (REQ-309) — a real
// addEventListener handler, not an inline onsubmit="" attribute, because this site's CSP
// (script-src has no 'unsafe-inline'/'unsafe-hashes') silently no-ops inline event handler
// attributes. A form without this listener would submit immediately with no confirmation.
document.querySelectorAll('form[data-confirm-msg]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!window.confirm(form.dataset.confirmMsg)) {
            e.preventDefault();
        }
    });
});

// Cancel closes the enclosing <details> disclosure without submitting — same "Fortryd"
// affordance for both the token-expiry and record-mode-secret date forms.
document.querySelectorAll('[data-dash-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const details = btn.closest('details');
        if (details) details.open = false;
    });
});

// Reveal-once rotation panel: click-to-select the value field, and a Copy button using the
// Clipboard API (same CSP reason as above — no inline onclick="").
document.querySelectorAll('[data-select-on-click]').forEach(function (el) {
    el.addEventListener('click', function () { el.select(); });
});
document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const el = document.getElementById(btn.dataset.copyTarget);
        if (!el) return;
        el.select();
        navigator.clipboard.writeText(el.value).catch(function () {});
    });
});
</script>
