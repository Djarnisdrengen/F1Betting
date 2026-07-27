<?php
// Challenges — usage analytics across Duels / Rumor or Not / Trivia. Read-only aggregates
// over existing Paddock Challenges tables, no new schema. See
// epics/Admin settings and dashboards/feature-5-challenges-usage-dashboard.md.
//
// NFR-501 originally assumed an existing "active participants" figure to reuse from the
// Members admin tab for consistency — none exists (verified: the Members tab has never shown
// a total participant count, only the pending-promotion queue). Defined here for the first
// time: verified participants with at least one scored Challenge Point.
//
// Per-game "completion %" (feature-5 REQ-502) doesn't map onto real schema the same way for
// all three games: Rumor or Not / Trivia answers are scored the instant they're submitted (no
// separate "abandoned" state to measure completion against), while Duels genuinely do have an
// unresolved→resolved lifecycle (scoring waits for the race result). Rather than force a
// uniform "completion %" that would be trivially 100% for two of the three games, each card
// shows the metric that's actually real for that game: Duels = resolved rate, Rumor or Not /
// Trivia = correct-answer rate. Labeled per-card so nothing is silently misrepresented.

function chWeeklyBars(PDO $db, string $table, string $dateCol): array {
    $rows = $db->query("SELECT YEARWEEK($dateCol, 3) AS yw, COUNT(*) AS c FROM $table GROUP BY yw ORDER BY yw DESC LIMIT 8")->fetchAll();
    $counts = array_reverse(array_map(fn($r) => (int) $r['c'], $rows));
    while (count($counts) < 8) array_unshift($counts, 0);
    return $counts;
}

function chScaleBars(array $counts, int $maxHeight = 32, int $minHeight = 3): array {
    $max = max($counts) ?: 1;
    return array_map(fn($c) => $c > 0 ? max($minHeight, (int) round($c / $max * $maxHeight)) : $minHeight - 1, $counts);
}

$sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

// chGetActiveParticipantsCount() lives in challenges-usage-lib.php (required unconditionally
// by the router) so this KPI and Oversigt's tile can never disagree.
$activeParticipants = chGetActiveParticipantsCount($db);

$totalVerified = (int) $db->query("SELECT COUNT(*) FROM challenge_participants WHERE status = 'verified'")->fetchColumn();
$participationRate = $totalVerified > 0 ? round($activeParticipants / $totalVerified * 100) : 0;

$stmt = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM challenge_answers WHERE answered_at >= ?) +
        (SELECT COUNT(*) FROM duel_predictions WHERE submitted_at >= ?) +
        (SELECT COUNT(*) FROM challenge_trivia_answers WHERE answered_at >= ?)
");
$stmt->execute([$sevenDaysAgo, $sevenDaysAgo, $sevenDaysAgo]);
$plays7d = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM challenge_participants
    WHERE promotion_requested_at IS NOT NULL AND promotion_requested_at >= ?
");
$stmt->execute([$sevenDaysAgo]);
$newApplications7d = (int) $stmt->fetchColumn();

// ── Duels ──
$duelsParticipants = (int) $db->query("SELECT COUNT(DISTINCT participant_id) FROM duel_predictions")->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM duel_predictions WHERE submitted_at >= ?");
$stmt->execute([$sevenDaysAgo]);
$duelsPlays7d = (int) $stmt->fetchColumn();
$duelsStatusCounts = $db->query("
    SELECT status, COUNT(*) AS c FROM duels WHERE status != 'void' GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$duelsTotal = array_sum($duelsStatusCounts);
$duelsResolvedPct = $duelsTotal > 0 ? round(($duelsStatusCounts['resolved'] ?? 0) / $duelsTotal * 100) : 0;
$duelsBars = chScaleBars(chWeeklyBars($db, 'duel_predictions', 'submitted_at'));

// ── Rumor or Not ──
$ronParticipants = (int) $db->query("SELECT COUNT(DISTINCT participant_id) FROM challenge_answers")->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM challenge_answers WHERE answered_at >= ?");
$stmt->execute([$sevenDaysAgo]);
$ronPlays7d = (int) $stmt->fetchColumn();
$ronTotals = $db->query("SELECT COUNT(*) AS total, SUM(correct) AS correct FROM challenge_answers")->fetch();
$ronCorrectPct = $ronTotals['total'] > 0 ? round($ronTotals['correct'] / $ronTotals['total'] * 100) : 0;
$ronBars = chScaleBars(chWeeklyBars($db, 'challenge_answers', 'answered_at'));

// ── Trivia ──
$triviaParticipants = (int) $db->query("SELECT COUNT(DISTINCT participant_id) FROM challenge_trivia_answers")->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM challenge_trivia_answers WHERE answered_at >= ?");
$stmt->execute([$sevenDaysAgo]);
$triviaPlays7d = (int) $stmt->fetchColumn();
$triviaTotals = $db->query("SELECT COUNT(*) AS total, SUM(correct) AS correct FROM challenge_trivia_answers")->fetch();
$triviaCorrectPct = $triviaTotals['total'] > 0 ? round($triviaTotals['correct'] / $triviaTotals['total'] * 100) : 0;
$triviaBars = chScaleBars(chWeeklyBars($db, 'challenge_trivia_answers', 'answered_at'));

// ── Funnel (participated → registered → requested membership) ──
// Top-of-funnel "visitors" step dropped — not sourced anywhere (feature-5 REQ, deferred item).
$funnelParticipated = (int) $db->query("
    SELECT COUNT(DISTINCT participant_id) FROM (
        SELECT participant_id FROM challenge_answers
        UNION SELECT participant_id FROM duel_predictions
        UNION SELECT participant_id FROM challenge_trivia_answers
    ) all_plays
")->fetchColumn();
$funnelRegistered = $totalVerified;
$funnelRequestedMembership = (int) $db->query("
    SELECT COUNT(*) FROM challenge_participants WHERE promotion_requested_at IS NOT NULL
")->fetchColumn();

$games = [
    ['name' => 'Duels', 'tag' => t('admin_dash_ch_duels_tag'), 'icon' => 'fa-hand-fist', 'iconBg' => 'linear-gradient(135deg,#e10600,#b30500)',
     'players' => $duelsParticipants, 'plays' => $duelsPlays7d, 'metricLabel' => t('admin_dash_ch_resolved'), 'metricPct' => $duelsResolvedPct, 'bars' => $duelsBars],
    ['name' => 'Rumor or Not', 'tag' => t('admin_dash_ch_ron_tag'), 'icon' => 'fa-question', 'iconBg' => 'linear-gradient(135deg,#f59e0b,#d97706)',
     'players' => $ronParticipants, 'plays' => $ronPlays7d, 'metricLabel' => t('admin_dash_ch_correct'), 'metricPct' => $ronCorrectPct, 'bars' => $ronBars],
    ['name' => 'Weekly Trivia', 'tag' => t('admin_dash_ch_trivia_tag'), 'icon' => 'fa-brain', 'iconBg' => 'linear-gradient(135deg,#10b981,#059669)',
     'players' => $triviaParticipants, 'plays' => $triviaPlays7d, 'metricLabel' => t('admin_dash_ch_correct'), 'metricPct' => $triviaCorrectPct, 'bars' => $triviaBars],
];

$funnelSteps = [
    ['label' => t('admin_dash_ch_funnel_participated'), 'val' => $funnelParticipated, 'pct' => 100],
    ['label' => t('admin_dash_ch_funnel_registered'), 'val' => $funnelRegistered, 'pct' => $funnelParticipated > 0 ? round($funnelRegistered / $funnelParticipated * 100) : 0],
    ['label' => t('admin_dash_ch_funnel_requested'), 'val' => $funnelRequestedMembership, 'pct' => $funnelParticipated > 0 ? round($funnelRequestedMembership / $funnelParticipated * 100) : 0],
];

// ── Content Supply (Real expiry & rotation for content-topup, Feature 2) ──
// Second panel on this same tab (plan.md decision 5) — not a new top-level tab.
$health = chGetContentHealthSnapshot($db);

function chCadenceBadgeMeta(string $status, bool $overdue): array {
    if ($overdue) {
        return ['label' => t('admin_dash_ch_cadence_overdue'), 'color' => '#ef4444'];
    }
    // Same hardcoded hex triad Nøgler & Rotation's own badges use (nogler-rotation-lib.php) —
    // shared visual vocabulary for "ok/warn/bad" across the Dashboards area (plan.md decision 5).
    return match ($status) {
        'success'     => ['label' => t('admin_dash_ch_cadence_ok'), 'color' => '#10b981'],
        'failure'     => ['label' => t('admin_dash_ch_cadence_failed'), 'color' => '#ef4444'],
        'in_progress' => ['label' => t('admin_dash_ch_cadence_running'), 'color' => '#f59e0b'],
        default       => ['label' => t('admin_dash_ch_cadence_unknown'), 'color' => '#8b8b96'],
    };
}
?>

<div class="admin-page-header"><i class="fas fa-trophy"></i><?= t('admin_dash_tab_challenges') ?></div>

<div class="stat-card-grid">
    <div class="stat-card">
        <div class="stat-card-label"><i class="fas fa-user-check"></i> <?= t('admin_dash_ch_kpi_active') ?></div>
        <div class="stat-card-value"><?= $activeParticipants ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label"><i class="fas fa-gamepad"></i> <?= t('admin_dash_ch_kpi_plays7d') ?></div>
        <div class="stat-card-value"><?= $plays7d ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label"><i class="fas fa-envelope-open-text"></i> <?= t('admin_dash_ch_kpi_new_apps') ?></div>
        <div class="stat-card-value"><?= $newApplications7d ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label"><i class="fas fa-chart-line"></i> <?= t('admin_dash_ch_kpi_rate') ?></div>
        <div class="stat-card-value success"><?= $participationRate ?>%</div>
    </div>
</div>

<section class="section-card" style="padding:18px 20px;margin-bottom:18px">
    <h3 style="margin:0 0 14px;font-size:15px"><i class="fas fa-flag-checkered" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_ch_competitions') ?></h3>
    <?php foreach ($games as $g): ?>
    <div style="border:1px solid var(--border-color);border-radius:11px;padding:14px 16px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:8px;background:<?= $g['iconBg'] ?>;display:flex;align-items:center;justify-content:center">
                <i class="fas <?= $g['icon'] ?>" style="color:#fff;font-size:14px"></i>
            </div>
            <div>
                <div style="font-weight:800;font-size:14px"><?= escape($g['name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= escape($g['tag']) ?></div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:auto auto auto 1fr;gap:20px;align-items:flex-end;margin-top:14px">
            <div><div class="label-mono" style="font-size:19px;color:var(--text-primary)"><?= $g['players'] ?></div><div style="font-size:10px;color:var(--text-muted)"><?= t('admin_dash_ch_col_players') ?></div></div>
            <div><div class="label-mono" style="font-size:19px;color:var(--text-primary)"><?= $g['plays'] ?></div><div style="font-size:10px;color:var(--text-muted)"><?= t('admin_dash_ch_col_plays7d') ?></div></div>
            <div><div class="label-mono" style="font-size:19px;color:var(--status-success-light)"><?= $g['metricPct'] ?>%</div><div style="font-size:10px;color:var(--text-muted)"><?= escape($g['metricLabel']) ?></div></div>
            <div style="display:flex;align-items:flex-end;gap:3px;height:34px;justify-self:end">
                <?php foreach ($g['bars'] as $h): ?>
                    <div style="width:7px;height:<?= $h ?>px;background:var(--f1-red);opacity:0.65;border-radius:2px"></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<section class="section-card" style="padding:16px 18px">
    <h3 style="margin:0 0 14px;font-size:15px"><i class="fas fa-filter" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_ch_funnel') ?></h3>
    <?php foreach ($funnelSteps as $f): ?>
    <div style="display:grid;grid-template-columns:200px 1fr auto;gap:12px;align-items:center;margin-bottom:10px">
        <span style="font-size:13px;color:var(--text-secondary)"><?= escape($f['label']) ?></span>
        <div style="height:22px;border-radius:6px;background:var(--bg-hover);overflow:hidden">
            <div style="height:100%;width:<?= $f['pct'] ?>%;background:linear-gradient(90deg,var(--f1-red),var(--f1-red-light));border-radius:6px"></div>
        </div>
        <span class="label-mono" style="font-size:13px;color:var(--text-primary);width:64px;text-align:right"><?= $f['val'] ?></span>
    </div>
    <?php endforeach; ?>
</section>

<section class="section-card" style="padding:18px 20px;margin-top:18px">
    <h3 style="margin:0 0 14px;font-size:15px"><i class="fas fa-box-archive" style="color:var(--f1-red);margin-right:7px"></i><?= t('admin_dash_ch_supply_title') ?></h3>

    <div class="stat-card-grid" style="margin-bottom:16px">
        <div class="stat-card">
            <div class="stat-card-label"><?= t('admin_dash_ch_supply_rumor') ?> · <?= t('admin_dash_ch_supply_live') ?></div>
            <div class="stat-card-value"><?= $health['rumor']['live'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label"><?= t('admin_dash_ch_supply_rumor') ?> · <?= t('admin_dash_ch_supply_archived') ?></div>
            <div class="stat-card-value"><?= $health['rumor']['archived'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label"><?= t('admin_dash_ch_supply_trivia') ?> · <?= t('admin_dash_ch_supply_live') ?></div>
            <div class="stat-card-value"><?= $health['trivia']['live'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-label"><?= t('admin_dash_ch_supply_trivia') ?> · <?= t('admin_dash_ch_supply_archived') ?></div>
            <div class="stat-card-value"><?= $health['trivia']['archived'] ?></div>
        </div>
    </div>

    <?php if ($health['rumor']['guardBlocked']): ?>
    <div class="alert alert-warning" style="margin-bottom:16px"><?= t('admin_dash_ch_supply_guard_blocked') ?></div>
    <?php endif; ?>

    <h4 style="margin:0 0 10px;font-size:13px;color:var(--text-muted);text-transform:uppercase"><?= t('admin_dash_ch_cadence_title') ?></h4>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <?php foreach (['test', 'live'] as $env): $c = $health['cadence'][$env]; $meta = chCadenceBadgeMeta($c['status'], $health['overdue'][$env]); ?>
        <div style="border:1px solid var(--border-color);border-radius:11px;padding:12px 14px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span class="label-mono" style="font-size:12px;text-transform:uppercase"><?= strtoupper($env) ?></span>
                <span class="label-badge" style="padding:3px 8px;border-radius:999px;background:<?= $meta['color'] ?>;color:#fff;font-size:11px"><?= $meta['label'] ?></span>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
                <?= $c['lastRunAt']
                    ? sprintf(t('admin_dash_ch_cadence_last_run'), ghRelativeTime(new DateTimeImmutable($c['lastRunAt']), new DateTimeImmutable('now', new DateTimeZone('UTC')), $lang))
                    : t('admin_dash_ch_cadence_no_run') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <h4 style="margin:0 0 10px;font-size:13px;color:var(--text-muted);text-transform:uppercase"><?= t('admin_dash_ch_kb_title') ?></h4>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <?php foreach (['rumor' => t('admin_dash_ch_supply_rumor'), 'trivia' => t('admin_dash_ch_supply_trivia')] as $generator => $generatorLabel): ?>
        <div style="border:1px solid var(--border-color);border-radius:11px;padding:12px 14px">
            <div style="font-weight:700;font-size:13px;margin-bottom:6px"><?= escape($generatorLabel) ?></div>
            <?php foreach (['test', 'live'] as $env): $weeks = $health['kbRunway'][$generator][$env]; $low = $weeks !== null && $weeks < KB_RUNWAY_LOW_WEEKS; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:3px 0">
                <span class="label-mono" style="color:var(--text-muted)"><?= strtoupper($env) ?></span>
                <span style="<?= $low ? 'color:var(--status-warning)' : '' ?>">
                    <?= $weeks === null ? t('admin_dash_ch_kb_unknown') : sprintf(t('admin_dash_ch_kb_weeks'), round($weeks, 1)) ?>
                    <?= $low ? ' · ' . t('admin_dash_ch_kb_low') : '' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
