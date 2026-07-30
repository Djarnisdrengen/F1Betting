<?php
// Challenges usage — shared helper, required unconditionally from admin-dashboards.php (unlike
// the challenges.php tab partial, which only loads when ?tab=challenges) so Dashboards →
// Oversigt can call chGetUsageSnapshot() regardless of which tab is active. See
// epics/Admin settings and dashboards/feature-5-challenges-usage-dashboard.md.

function chGetActiveParticipantsCount(PDO $db): int {
    // See challenges.php's own header note: no existing "active participants" figure exists
    // anywhere else in the admin area to reuse — defined here for the first time so this and
    // the Challenges tab's own KPI can never disagree (NFR-501).
    return (int) $db->query("
        SELECT COUNT(DISTINCT cp.participant_id)
        FROM challenge_points cp
        JOIN challenge_participants p ON p.id = cp.participant_id
        WHERE p.status = 'verified'
    ")->fetchColumn();
}

// Composition point for Dashboards → Oversigt (Feature 2) — read-only. flagCount now reflects
// real Content Supply flags (REQ-707) via chGetContentHealthSnapshot() below — this dashboard
// used to have no natural "problem" state ("flag = 0 in steady state, this dashboard is
// analytics, not itself a source of problems"), but content supply genuinely does: an overdue
// batch, blocked rumor archival, or a low KB runway.
function chGetUsageSnapshot(PDO $db): array {
    return [
        'activeParticipants' => chGetActiveParticipantsCount($db),
        'flagCount'          => chGetContentHealthSnapshot($db)['flagCount'],
    ];
}

// ============================================
// CONTENT HEALTH (Real expiry & rotation for content-topup — Feature 2)
// ============================================
// See epics/Real expiry & rotation for content-topup/feature-2-content-health-dashboard.md.
if (!defined('KB_RUNWAY_LOW_WEEKS'))        define('KB_RUNWAY_LOW_WEEKS', 4);
if (!defined('CONTENT_TOPUP_OVERDUE_HOURS')) define('CONTENT_TOPUP_OVERDUE_HOURS', 7 * 24 + 6); // 1 week + 6h grace
if (!defined('KB_WEEKLY_DRAW_RATE'))        define('KB_WEEKLY_DRAW_RATE', 6.0); // fallback when the actual per-env setting isn't known (see chGetContentHealthSnapshot())

// Pure (NFR-703) — takes an already-read string (or null, simulating file_get_contents()
// returning false), never touches the filesystem itself. Returns ['usedCount' => int] or null
// on a missing/malformed file — never throws, never fabricates a count.
function parseGeneratorState(?string $json): ?array {
    if ($json === null || $json === '') {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['usedKbIds']) || !is_array($data['usedKbIds'])) {
        return null;
    }
    return ['usedCount' => count($data['usedKbIds'])];
}

// Pure (NFR-703) — weeks of unused KB material left, given an already-parsed state (or null)
// and the known ~6-items-per-batch draw rate (REQ-705). Any missing input yields null
// ("unknown"), never a fabricated number.
function computeKbRunway(?array $state, int $totalDocs, float $weeklyDrawRate): ?float {
    if ($state === null || $weeklyDrawRate <= 0) {
        return null;
    }
    return max(0, $totalDocs - $state['usedCount']) / $weeklyDrawRate;
}

// Thin file-reading wrappers — the only place this feature touches the filesystem, so
// NFR-703's degrade-cleanly requirement is provably satisfied by the pure functions above plus
// an @-suppressed read here. bin/state/*.json is deployed one level above public/ (same
// non-web-accessible placement as config.php) by build-deploy/deploy.js specifically so this
// panel can read it — see that file's own comment for why it isn't part of publicDir wholesale.
function chReadGeneratorState(string $generator, string $env): ?array {
    $path = __DIR__ . "/../../../bin/state/{$generator}-generator-state.{$env}.json";
    $json = @file_get_contents($path);
    return parseGeneratorState($json === false ? null : $json);
}

function chReadKbTotalDocs(): ?int {
    $json = @file_get_contents(__DIR__ . '/../../paddock-rumors/knowledge-base.json');
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? count($data) : null;
}

// Batch cadence per environment (REQ-704) — reuses the existing GH Actions run cache
// (ghListWorkflowRunsMulti(), warmed every 5 minutes by cron/warm_actions_cache.php — NFR-701,
// no new GitHub API calls on a normal page load). cron-content-topup.yml fans out into a
// job-per-(generator, environment) matrix on every scheduled run, so distinguishing test vs
// live cadence needs the *job* list of the latest completed run, not just its run-level status
// — a run-level view can't tell "test ok, live failed" from "both failed" (NFR-702's per-
// environment distinctness). Job names carry the matrix suffix "(test)"/"(live)" (GitHub's own
// naming convention for a matrixed job). Scoped to the single latest completed run only, not a
// multi-run scan — ghListRunJobs() caches a completed run's jobs for 30 days, so in steady
// state this is a local cache read, not a live API call.
function chContentTopupCadence(): array {
    $unknown = ['status' => 'unknown', 'lastRunAt' => null];
    $result  = ['test' => $unknown, 'live' => $unknown];

    $runs = ghListWorkflowRunsMulti(['cron-content-topup.yml'], 5)['cron-content-topup.yml'] ?? [];
    $latest = null;
    foreach ($runs as $run) {
        if (($run['status'] ?? '') === 'completed') {
            $latest = $run;
            break;
        }
    }
    if (!$latest) {
        return $result;
    }

    $lastRunAt = $latest['run_started_at'] ?? $latest['created_at'] ?? null;
    $jobs = ghListRunJobs((int) $latest['id'], true);
    foreach (['test', 'live'] as $env) {
        $envJobs = array_values(array_filter($jobs, fn($j) => str_contains($j['name'] ?? '', "($env)")));
        if (!$envJobs) {
            continue;
        }
        $statuses = array_map('ghNormalizeRunStatus', $envJobs);
        $status = in_array('failure', $statuses, true) ? 'failure'
            : (in_array('in_progress', $statuses, true) ? 'in_progress' : 'success');
        $result[$env] = ['status' => $status, 'lastRunAt' => $lastRunAt];
    }
    return $result;
}

// "Overdue" (REQ-704) — no run recorded at all, or the most recent one for this environment is
// older than a week plus a grace window. A day-count heuristic rather than precise next-fire-
// time math off the '0 6 * * 5' cron string: the workflow fires weekly, so "has it been
// meaningfully longer than a week" answers the same question with far less code.
function chIsCadenceOverdue(array $envCadence): bool {
    if ($envCadence['lastRunAt'] === null) {
        return true;
    }
    $last = new DateTimeImmutable($envCadence['lastRunAt']);
    $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return ($now->getTimestamp() - $last->getTimestamp()) > CONTENT_TOPUP_OVERDUE_HOURS * 3600;
}

// Composition point for Dashboards → Challenges' Content Supply panel (Feature 2) — read-only
// (REQ-706/NFR-704: no new schema, purely derived from existing tables + the GH Actions cache +
// on-disk state files).
function chGetContentHealthSnapshot(PDO $db): array {
    // Live counts (REQ-702) — same predicates nextRumorItem()/nextTriviaQuestion() use
    // (public/challenges.php), so this can never disagree with what admin-challenges.php's own
    // filtered counts show (NFR-702).
    $rumorLive = (int) $db->query("
        SELECT COUNT(*) FROM challenge_items WHERE status = 'published' AND publish_date <= CURDATE()
    ")->fetchColumn();
    $triviaLive = (int) $db->query("
        SELECT COUNT(*) FROM challenge_trivia_questions
        WHERE status = 'published' AND publish_date <= CURDATE()
              AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ")->fetchColumn();
    $rumorArchived  = (int) $db->query("SELECT COUNT(*) FROM challenge_items WHERE status = 'archived'")->fetchColumn();
    $triviaArchived = (int) $db->query("SELECT COUNT(*) FROM challenge_trivia_questions WHERE status = 'archived'")->fetchColumn();

    // Computed live from the same pure function the cron step itself uses (rumorArchiveBudget(),
    // includes/challenges.php) rather than persisting the last run's flag (REQ-703) — always
    // reflects "would a run right now be blocked," can never go stale between Monday runs, and
    // can never disagree with what the cron would actually do.
    $rumorGuardBlocked = rumorArchiveBudget($rumorLive, RUMOR_MIN_LIVE) <= 0;

    $cadence = chContentTopupCadence();
    $overdue = ['test' => chIsCadenceOverdue($cadence['test']), 'live' => chIsCadenceOverdue($cadence['live'])];

    // Draw rate per generator: use this environment's own admin-configured batch size
    // (Settings tab, "Weekly Content Batch Size") for the column matching where this page is
    // actually running — this process only has a live DB connection to its own environment's
    // `settings` row, never the other one's, so the *other* env's column still falls back to
    // KB_WEEKLY_DRAW_RATE's historical assumption rather than a value we can't actually see.
    $settings  = getSettings();
    $ownEnv    = (defined('APP_ENV') && APP_ENV === 'live') ? 'live' : 'test';
    $drawRates = [
        'rumor'  => [$ownEnv => (float) ($settings['rumor_batch_size'] ?? KB_WEEKLY_DRAW_RATE)],
        'trivia' => [$ownEnv => (float) ($settings['trivia_batch_size'] ?? KB_WEEKLY_DRAW_RATE)],
    ];

    $kbTotal  = chReadKbTotalDocs();
    $kbRunway = [];
    $lowKbRunway = false;
    foreach (['rumor', 'trivia'] as $generator) {
        foreach (['test', 'live'] as $env) {
            $rate  = $drawRates[$generator][$env] ?? KB_WEEKLY_DRAW_RATE;
            $weeks = $kbTotal === null ? null : computeKbRunway(chReadGeneratorState($generator, $env), $kbTotal, $rate);
            $kbRunway[$generator][$env] = $weeks;
            if ($weeks !== null && $weeks < KB_RUNWAY_LOW_WEEKS) {
                $lowKbRunway = true;
            }
        }
    }

    return [
        'rumor'    => ['live' => $rumorLive, 'archived' => $rumorArchived, 'guardBlocked' => $rumorGuardBlocked],
        'trivia'   => ['live' => $triviaLive, 'archived' => $triviaArchived],
        'cadence'  => $cadence,
        'overdue'  => $overdue,
        'kbRunway' => $kbRunway,
        'flagCount' => (int) $rumorGuardBlocked + (int) $overdue['test'] + (int) $overdue['live'] + (int) $lowKbRunway,
    ];
}
