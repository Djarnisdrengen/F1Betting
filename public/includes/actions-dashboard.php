<?php
// GitHub Actions Dashboard (public/admin-actions.php) — data layer.
// GitHub API calls happen only here, server-side, never from the browser (CSP is
// connect-src 'self'). See epics/github_actions_dashboard/plan.md for the architecture
// decisions this file implements, in particular #5: the workflow cron strings below are
// copied verbatim from the real .github/workflows/*.yml files (verified, not the design
// handoff's illustrative mock table, which drifted from what's actually configured).

define('GH_REPO_OWNER', 'Djarnisdrengen');
define('GH_REPO_NAME',  'F1Betting');
define('GH_CACHE_DIR',  __DIR__ . '/../cache/github-actions');

// ── Static workflow config ──────────────────────────────────────────────────
// Purpose/expected copy lives in public/lang/admin.php (admin_actions_wf_<id>_purpose/expected).
// cron: literal UTC cron string(s) as they appear in the workflow file (kb-update has 7 —
// one workflow, several schedule: lines for the race-weekend cycle).
function ghWorkflowConfig(): array {
    return [
        'content-topup' => [
            'file' => 'cron-content-topup.yml', 'name' => 'Cron — Content Top-up',
            'icon' => 'newspaper', 'cron' => ['0 6 * * 5'], 'manual' => false,
        ],
        'email-notify' => [
            'file' => 'cron-notifications.yml', 'name' => 'Cron — Email Notifications',
            'icon' => 'envelope', 'cron' => ['1 * * * *'], 'manual' => false,
        ],
        'quali-import' => [
            'file' => 'cron-qualifying-import.yml', 'name' => 'Cron — Qualifying Results Import',
            'icon' => 'flag-checkered', 'cron' => ['*/5 6-23 * * 6'], 'manual' => false,
        ],
        'weekly-challenges' => [
            'file' => 'cron-challenges.yml', 'name' => 'Cron — Weekly Challenges',
            'icon' => 'bolt', 'cron' => ['0 5 * * 1'], 'manual' => false,
        ],
        'e2e' => [
            'file' => 'e2e-test-orchestrator.yml', 'name' => 'E2E Orchestrator (test env)',
            'icon' => 'vial', 'cron' => [], 'manual' => true,
        ],
        'sec-review' => [
            'file' => 'monthly-security-review.yml', 'name' => 'Monthly Security Review',
            'icon' => 'shield-halved', 'cron' => ['17 8 1 * *'], 'manual' => false,
        ],
        'db-backup' => [
            'file' => 'nightly-backup.yml', 'name' => 'Nightly DB Backup',
            'icon' => 'database', 'cron' => ['0 1 * * *'], 'manual' => false,
        ],
        'nightly-tests' => [
            'file' => 'nightly-tests.yml', 'name' => 'Nightly Tests & Security Scan',
            'icon' => 'flask', 'cron' => ['0 1 * * *'], 'manual' => false,
        ],
        'kb-update' => [
            'file' => 'paddock-rumors.yml', 'name' => 'Paddock Rumors — KB Update',
            'icon' => 'comments', 'manual' => false,
            'cron' => [
                '0 12,13 * * 6',
                '0 14,15,16,18 * * 6',
                '0 16,18,20,22 * * 0',
                '0 0,2,4,6,8,10,12,14 * * 1',
                '0 18 * * 2',
                '0 18 * * 3',
                '0 12 * * 4',
            ],
        ],
    ];
}

function ghWorkflowPurpose(string $id, string $lang): string {
    return t('admin_actions_wf_' . str_replace('-', '_', $id) . '_purpose', $lang);
}
function ghWorkflowExpected(string $id, string $lang): string {
    return t('admin_actions_wf_' . str_replace('-', '_', $id) . '_expected', $lang);
}

// ── Cron evaluator ───────────────────────────────────────────────────────────
// Standard 5-field cron (minute hour dom month dow), no macros/seconds. Supports *, lists,
// ranges, and */step — the only syntax any of the real workflow files above actually use.
function ghCronField(string $field, int $min, int $max): array {
    $values = [];
    foreach (explode(',', $field) as $part) {
        $step = 1;
        $rangePart = $part;
        if (str_contains($part, '/')) {
            [$rangePart, $stepStr] = explode('/', $part, 2);
            $step = max(1, (int)$stepStr);
        }
        if ($rangePart === '*') {
            $lo = $min; $hi = $max;
        } elseif (str_contains($rangePart, '-')) {
            [$lo, $hi] = array_map('intval', explode('-', $rangePart, 2));
        } else {
            $lo = $hi = (int)$rangePart;
        }
        for ($v = $lo; $v <= $hi; $v += $step) {
            $values[] = $v;
        }
    }
    $values = array_unique($values);
    sort($values);
    return $values;
}

// Fire times ("HH:MM") for one cron string on one UTC calendar day. Returns [] if the cron
// doesn't fire that day at all.
function ghCronFireTimes(string $cron, DateTimeImmutable $utcDay): array {
    $parts = preg_split('/\s+/', trim($cron));
    if (count($parts) !== 5) return [];
    [$minF, $hourF, $domF, $monF, $dowF] = $parts;

    $month = (int)$utcDay->format('n');
    if (!in_array($month, ghCronField($monF, 1, 12), true)) return [];

    $domRestricted = $domF !== '*';
    $dowRestricted = $dowF !== '*';
    $domMatch = in_array((int)$utcDay->format('j'), ghCronField($domF, 1, 31), true);
    $dows = array_map(fn($d) => $d === 7 ? 0 : $d, ghCronField($dowF, 0, 7));
    $dowMatch = in_array((int)$utcDay->format('w'), $dows, true);

    // Standard cron rule: when both dom and dow are restricted, either matching is enough.
    if ($domRestricted && $dowRestricted) {
        $dayMatches = $domMatch || $dowMatch;
    } else {
        $dayMatches = $domRestricted ? $domMatch : ($dowRestricted ? $dowMatch : true);
    }
    if (!$dayMatches) return [];

    $times = [];
    foreach (ghCronField($hourF, 0, 23) as $h) {
        foreach (ghCronField($minF, 0, 59) as $m) {
            $times[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    sort($times);
    return $times;
}

// Union of fire times across every cron line a workflow has (kb-update has 7).
function ghWorkflowDailyFireTimes(array $crons, DateTimeImmutable $utcDay): array {
    $all = [];
    foreach ($crons as $cron) {
        foreach (ghCronFireTimes($cron, $utcDay) as $t) $all[$t] = true;
    }
    $times = array_keys($all);
    sort($times);
    return $times;
}

function ghNextFireDateTime(array $crons, DateTimeImmutable $afterUtc): ?DateTimeImmutable {
    if (empty($crons)) return null;
    $day = $afterUtc->setTime(0, 0, 0);
    for ($i = 0; $i <= 366; $i++) {
        foreach (ghWorkflowDailyFireTimes($crons, $day) as $hm) {
            [$h, $m] = array_map('intval', explode(':', $hm));
            $candidate = $day->setTime($h, $m, 0);
            if ($candidate > $afterUtc) return $candidate;
        }
        $day = $day->modify('+1 day');
    }
    return null;
}

// ── Schedule matrix + collisions ─────────────────────────────────────────────
// Day grid buckets by UTC calendar date (the cron's native frame — matches how the design
// prototype itself laid out its fixed reference month) — cell tooltips convert to CET.
function ghComputeSchedule(array $workflowConfig, DateTimeImmutable $monthStartUtc, DateTimeImmutable $nowUtc): array {
    $dayCount = (int)$monthStartUtc->format('t');
    $perWorkflowDay = []; // id => [day => ["HH:MM", ...]]
    $monthlyTotal = [];   // id => int

    foreach ($workflowConfig as $id => $wf) {
        if (!empty($wf['manual'])) continue;
        $perWorkflowDay[$id] = [];
        $monthlyTotal[$id] = 0;
        for ($d = 1; $d <= $dayCount; $d++) {
            $day = $monthStartUtc->setDate((int)$monthStartUtc->format('Y'), (int)$monthStartUtc->format('n'), $d);
            $times = ghWorkflowDailyFireTimes($wf['cron'], $day);
            $perWorkflowDay[$id][$d] = $times;
            $monthlyTotal[$id] += count($times);
        }
    }

    // Collisions: per day, per exact UTC "HH:MM", which workflows fire — flag 3+.
    $collisions = [];
    for ($d = 1; $d <= $dayCount; $d++) {
        $perTime = [];
        foreach ($perWorkflowDay as $id => $days) {
            foreach ($days[$d] as $hm) {
                $perTime[$hm][] = $workflowConfig[$id]['name'];
            }
        }
        $strong = [];
        foreach ($perTime as $hm => $names) {
            if (count($names) >= 3) $strong[$hm] = $names;
        }
        ksort($strong);
        $collisions[$d] = $strong;
    }

    return [
        'dayCount'      => $dayCount,
        'perWorkflowDay'=> $perWorkflowDay,
        'monthlyTotal'  => $monthlyTotal,
        'collisions'    => $collisions,
    ];
}

// ── Formatting helpers (CET via the app's default Europe/Copenhagen tz — DST-correct) ──
function ghCetTz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Copenhagen');
}

function ghUtcHourMinToCetLabel(int $hour, int $min, DateTimeImmutable $refUtc): string {
    return $refUtc->setTime($hour, $min, 0)->setTimezone(ghCetTz())->format('H:i');
}

const GH_MONTHS = [
    'da' => ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'],
    'en' => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
];
const GH_WEEKDAY_LETTERS = [ // index 0=Sun..6=Sat
    'da' => ['S','M','T','O','T','F','L'],
    'en' => ['S','M','T','W','T','F','S'],
];
const GH_WEEKDAY_PLURAL = [
    'da' => ['Søndage','Mandage','Tirsdage','Onsdage','Torsdage','Fredage','Lørdage'],
    'en' => ['Sundays','Mondays','Tuesdays','Wednesdays','Thursdays','Fridays','Saturdays'],
];

function ghFormatCetFull(DateTimeImmutable $utc, string $lang): string {
    $cet = $utc->setTimezone(ghCetTz());
    $mo  = GH_MONTHS[$lang][(int)$cet->format('n') - 1];
    $time = $cet->format('H:i') . ' CET';
    return $lang === 'da' ? ($cet->format('j') . ". $mo $time") : ("$mo " . $cet->format('j') . ", $time");
}

function ghRelativeTime(DateTimeImmutable $target, DateTimeImmutable $now, string $lang, bool $future = false): string {
    $diffMin = ($future ? $target->getTimestamp() - $now->getTimestamp() : $now->getTimestamp() - $target->getTimestamp()) / 60;
    $units = $lang === 'da' ? ['m' => 'min', 'h' => 't', 'd' => 'd', 'w' => 'u', 'mo' => 'md'] : ['m' => 'm', 'h' => 'h', 'd' => 'd', 'w' => 'w', 'mo' => 'mo'];
    $fmt = function (int $n, string $u) use ($lang, $units, $future) {
        $s = max(1, $n) . $units[$u];
        if ($lang === 'da') return $future ? "om $s" : "for $s siden";
        return $future ? "in $s" : "$s ago";
    };
    if ($diffMin < 60) return $fmt((int)round($diffMin), 'm');
    $h = $diffMin / 60;
    if ($h < 24) return $fmt((int)round($h), 'h');
    $d = $h / 24;
    if ($d < 7) return $fmt((int)round($d), 'd');
    $w = $d / 7;
    if ($w < 5) return $fmt((int)round($w), 'w');
    return $fmt((int)round($d / 30), 'mo');
}

function ghFormatDuration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
}

// Human "Schedule" chip text, computed from the real cron so it can never drift from
// what's actually configured the way the design handoff's hardcoded table did.
function ghScheduleHumanText(array $wf, DateTimeImmutable $nowUtc, string $lang): string {
    if (!empty($wf['manual'])) return t('admin_actions_manual_only', $lang);
    if (count($wf['cron']) > 1) return sprintf(t('admin_actions_multi_window', $lang), count($wf['cron']));

    $parts = preg_split('/\s+/', trim($wf['cron'][0]));
    [$minF, $hourF, $domF, $monF, $dowF] = $parts;
    $isPlain = fn($f) => !str_contains($f, '/') && !str_contains($f, '-') && !str_contains($f, ',');

    if ($minF !== '*' && $hourF === '*' && $domF === '*' && $dowF === '*') {
        return sprintf(t('admin_actions_hourly', $lang), str_pad($minF, 2, '0', STR_PAD_LEFT));
    }
    if ($isPlain($minF) && $isPlain($hourF) && $domF === '*' && $dowF === '*') {
        $label = ghUtcHourMinToCetLabel((int)$hourF, (int)$minF, $nowUtc);
        return sprintf(t('admin_actions_daily', $lang), $label);
    }
    if ($isPlain($minF) && $isPlain($hourF) && $domF === '*' && $isPlain($dowF)) {
        $label = ghUtcHourMinToCetLabel((int)$hourF, (int)$minF, $nowUtc);
        $wd = GH_WEEKDAY_PLURAL[$lang][(int)$dowF % 7];
        return sprintf(t('admin_actions_weekly', $lang), $wd, $label);
    }
    if ($isPlain($minF) && $isPlain($hourF) && $domF === '1' && $dowF === '*') {
        $label = ghUtcHourMinToCetLabel((int)$hourF, (int)$minF, $nowUtc);
        return sprintf(t('admin_actions_monthly', $lang), $label);
    }
    // quali-import's shape: every-N-minutes within an hour range, one weekday. Shown in UTC
    // (matching docs/github-actions.md's own wording for this exact job) rather than CET —
    // a wide UTC window can cross the CET day boundary, which would need day-wrap handling
    // for little reader benefit over "it's literally the cron's own UTC window".
    if (str_starts_with($minF, '*/') && str_contains($hourF, '-') && $isPlain($dowF)) {
        $wd = GH_WEEKDAY_PLURAL[$lang][(int)$dowF % 7];
        [$loH, $hiH] = explode('-', $hourF);
        $step = substr($minF, 2);
        return sprintf(
            $lang === 'da' ? 'Hvert %d. min, %s %s:00–%s:55 UTC' : 'Every %d min, %s %s:00–%s:55 UTC',
            (int)$step, $wd, str_pad($loH, 2, '0', STR_PAD_LEFT), str_pad($hiH, 2, '0', STR_PAD_LEFT)
        );
    }

    $next = ghNextFireDateTime($wf['cron'], $nowUtc);
    return $next ? ghFormatCetFull($next, $lang) : '—';
}

// ── Status / trigger mapping ─────────────────────────────────────────────────
function ghNormalizeRunStatus(array $run): string {
    if (($run['status'] ?? '') !== 'completed') return 'in_progress';
    return match ($run['conclusion'] ?? '') {
        'success' => 'success',
        'failure', 'timed_out', 'action_required' => 'failure',
        'cancelled', 'stale' => 'cancelled',
        default => 'skipped', // conclusion: skipped | neutral | null
    };
}

function ghStatusMeta(string $status): array {
    return match ($status) {
        'success'     => ['icon' => 'fa-circle-check',        'color' => 'var(--status-success-light)'],
        'failure'     => ['icon' => 'fa-circle-xmark',        'color' => 'var(--status-danger-light)'],
        'cancelled'   => ['icon' => 'fa-ban',                 'color' => 'var(--text-muted)'],
        'in_progress' => ['icon' => 'fa-circle-notch fa-spin','color' => 'var(--f1-accent-challenges)'],
        default       => ['icon' => 'fa-circle-minus',        'color' => 'var(--text-muted)'], // skipped
    };
}

function ghTriggerIcon(string $event): string {
    return match ($event) {
        'schedule'          => 'fa-clock',
        'push'               => 'fa-code-commit',
        'pull_request'       => 'fa-code-pull-request',
        'workflow_dispatch'  => 'fa-hand-pointer',
        default              => 'fa-bolt',
    };
}

function ghTriggerLabel(string $event, string $lang): string {
    $key = 'admin_actions_tr_' . $event;
    $label = t($key, $lang);
    return $label === $key ? ucfirst(str_replace('_', ' ', $event)) : $label;
}

// ── GitHub API client (curl, file cache, admin-gated fixture mode for E2E) ──────────────
function ghFixtureModeActive(): bool {
    if (!defined('INTEGRATION_SEED_TOKEN')) return false;
    $token = $_GET['e2e_token'] ?? $_POST['e2e_token'] ?? '';
    if ($token === '' || !hash_equals(INTEGRATION_SEED_TOKEN, (string)$token)) return false;
    return isset($_GET['e2e_gh_fixture']) && $_GET['e2e_gh_fixture'] !== '';
}

function ghFixtureVariant(): string {
    return (string)($_GET['e2e_gh_fixture'] ?? '');
}

// Fixture run timestamps are relative markers ("-2 hours", not an absolute ISO string) so
// the fixture stays fresh — resolved against "now" every time it's served instead of baking
// in a fixed date that would silently drift out of the 12h/24h windows the dashboard computes.
function ghResolveFixtureRunDates(array $runs): array {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($runs as &$run) {
        foreach (['created_at', 'run_started_at', 'updated_at'] as $field) {
            if (!empty($run[$field])) {
                $run[$field] = $now->modify($run[$field])->format('Y-m-d\TH:i:s\Z');
            }
        }
    }
    return $runs;
}

function ghFixtureData(): array {
    static $data = null;
    if ($data === null) {
        $raw = @file_get_contents(__DIR__ . '/actions-dashboard-mock.json');
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        foreach (($data['runs'] ?? []) as $file => $runs) {
            $data['runs'][$file] = ghResolveFixtureRunDates($runs);
        }
    }
    return $data;
}

function ghApiCurlGet(string $url): ?array {
    $headers = [
        'User-Agent: F1Betting-ActionsDashboard',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if (defined('GITHUB_TOKEN') && GITHUB_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . GITHUB_TOKEN;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("[actions-dashboard] GitHub API error ($url): HTTP $httpCode $curlError");
        $GLOBALS['ghFetchError'] = true;
        return null;
    }
    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : null;
}

function ghCached(string $cacheKey, int $ttlSeconds, callable $fetch): ?array {
    $cacheFile = GH_CACHE_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $cacheKey) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $fresh = $fetch();
    if ($fresh !== null) {
        @file_put_contents($cacheFile, json_encode($fresh));
        return $fresh;
    }
    // Fetch failed — serve stale cache if we have any rather than nothing.
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    return null;
}

function ghListWorkflowRuns(string $file, int $perPage = 10): array {
    if (ghFixtureModeActive()) {
        if (ghFixtureVariant() === 'error') { $GLOBALS['ghFetchError'] = true; return []; }
        return ghFixtureData()['runs'][$file] ?? [];
    }
    $data = ghCached("runs_$file", 60, function () use ($file, $perPage) {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/actions/workflows/%s/runs?per_page=%d',
            GH_REPO_OWNER, GH_REPO_NAME, rawurlencode($file), $perPage
        );
        $resp = ghApiCurlGet($url);
        return $resp['workflow_runs'] ?? null;
    });
    return $data ?? [];
}

// ── Workflow dispatch (write) — used only by PaddockKB's "Kør opdatering nu" (Feature 4).
// Needs a GITHUB_TOKEN with actions:write, not just the actions:read the rest of this file
// needs — see epics/Admin settings and dashboards/plan.md decision 7.
function ghTriggerWorkflowDispatch(string $file, string $ref = 'main'): array {
    if (ghFixtureModeActive()) {
        if (ghFixtureVariant() === 'dispatch_error') {
            return ['success' => false, 'error' => 'insufficient_scope'];
        }
        return ['success' => true, 'error' => null];
    }
    if (!defined('GITHUB_TOKEN') || GITHUB_TOKEN === '') {
        return ['success' => false, 'error' => 'no_token'];
    }
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
        GH_REPO_OWNER, GH_REPO_NAME, rawurlencode($file)
    );
    $headers = [
        'User-Agent: F1Betting-ActionsDashboard',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'Authorization: Bearer ' . GITHUB_TOKEN,
        'Content-Type: application/json',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['ref' => $ref]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch); // response body unused — success is purely HTTP-status-based (204)
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 204) {
        return ['success' => true, 'error' => null];
    }
    if ($httpCode === 401 || $httpCode === 403) {
        error_log("[actions-dashboard] workflow_dispatch insufficient permissions ($file): HTTP $httpCode");
        return ['success' => false, 'error' => 'insufficient_scope'];
    }
    error_log("[actions-dashboard] workflow_dispatch failed ($file): HTTP $httpCode $curlError");
    return ['success' => false, 'error' => 'request_failed'];
}

// Pure aggregator shared by the Actions tab (which already has the fuller per-run view
// models built for its own rendering) and Dashboards → Oversigt's snapshot (feature-2's
// NFR-201: the two must compute identically, not maintain two implementations of the same
// arithmetic). Input: workflow id => list of ['status' => ..., 'startedUtc' => DateTimeImmutable].
function ghSummarizeRuns(array $runsByWorkflow, DateTimeImmutable $now): array {
    $dayAgo = $now->modify('-24 hours');
    $totalRuns24 = 0; $successCount = 0; $gradedCount = 0; $failingNow = 0;
    foreach ($runsByWorkflow as $views) {
        foreach ($views as $v) {
            if ($v['startedUtc'] > $dayAgo) $totalRuns24++;
            if (!in_array($v['status'], ['skipped', 'in_progress'], true)) {
                $gradedCount++;
                if ($v['status'] === 'success') $successCount++;
            }
        }
        if (!empty($views) && $views[0]['status'] === 'failure') $failingNow++;
    }
    $successRate = $gradedCount > 0 ? (int) round($successCount / $gradedCount * 100) : 100;
    return ['totalRuns24' => $totalRuns24, 'successRate' => $successRate, 'failingNow' => $failingNow];
}

// Composition point for Dashboards → Oversigt (Feature 2) — read-only, calls the same
// ghListWorkflowRuns()/ghSummarizeRuns() the Actions tab itself uses.
function ghGetHealthSnapshot(): array {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $runsByWorkflow = [];
    foreach (ghWorkflowConfig() as $id => $wf) {
        $views = [];
        foreach (ghListWorkflowRuns($wf['file'], 10) as $run) {
            $started = new DateTimeImmutable($run['run_started_at'] ?? $run['created_at']);
            $views[] = ['status' => ghNormalizeRunStatus($run), 'startedUtc' => $started];
        }
        $runsByWorkflow[$id] = $views;
    }
    $summary = ghSummarizeRuns($runsByWorkflow, $now);
    return ['successRate' => $summary['successRate'], 'failingNow' => $summary['failingNow']];
}

function ghListRunJobs(int $runId, bool $completed): array {
    if (ghFixtureModeActive()) {
        if (ghFixtureVariant() === 'error') { $GLOBALS['ghFetchError'] = true; return []; }
        return ghFixtureData()['jobs'][(string)$runId] ?? [];
    }
    $ttl = $completed ? 60 * 60 * 24 * 30 : 15; // completed runs are immutable; in-progress runs poll fast
    $data = ghCached("jobs_$runId", $ttl, function () use ($runId) {
        $url = sprintf('https://api.github.com/repos/%s/%s/actions/runs/%d/jobs', GH_REPO_OWNER, GH_REPO_NAME, $runId);
        $resp = ghApiCurlGet($url);
        return $resp['jobs'] ?? null;
    });
    return $data ?? [];
}
