<?php
// CLI harness for parseGeneratorState()/computeKbRunway() in
// public/includes/admin-dashboards/challenges-usage-lib.php — NFR-703 (Real expiry & rotation
// for content-topup epic, Feature 2): a missing/malformed generator-state file must degrade to
// null, never throw or fabricate a number. In-memory fixture strings only — deliberately not
// exercised against a real bin/state/*.json (also read by bin/generate-*.js, not worth risking
// corruption of a shared file to simulate a bad one).
// Run: php tests/unit/content-health-harness.php    (exit 0 = all green)

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../public/includes/actions-dashboard.php'; // ghNormalizeRunStatus() only
require __DIR__ . '/../../public/includes/admin-dashboards/challenges-usage-lib.php';

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

// ── parseGeneratorState() ──
check('null (file_get_contents() returning false) → null', parseGeneratorState(null) === null);
check('empty string → null', parseGeneratorState('') === null);
check('malformed JSON → null', parseGeneratorState('{not valid json') === null);
check('valid JSON missing usedKbIds → null', parseGeneratorState('{"foo":"bar"}') === null);
check('usedKbIds not an array → null', parseGeneratorState('{"usedKbIds":"oops"}') === null);
check(
    'well-formed JSON → usedCount matches array length',
    parseGeneratorState('{"usedKbIds":["a","b","c"]}') === ['usedCount' => 3]
);
check('well-formed JSON, empty usedKbIds → usedCount 0', parseGeneratorState('{"usedKbIds":[]}') === ['usedCount' => 0]);

// ── computeKbRunway() ──
check('null state → null (never a fabricated number)', computeKbRunway(null, 100, 6.0) === null);
check('zero draw rate → null, no division-by-zero', computeKbRunway(['usedCount' => 10], 100, 0.0) === null);
check('negative draw rate → null', computeKbRunway(['usedCount' => 10], 100, -1.0) === null);
check(
    'normal case: 100 total, 40 used, 6/week → 10 weeks remaining',
    computeKbRunway(['usedCount' => 40], 100, 6.0) === 10.0
);
check(
    'usedCount exceeds totalDocs (stale total) → clamps to 0, not negative',
    computeKbRunway(['usedCount' => 150], 100, 6.0) === 0.0
);
check(
    'zero used → full runway',
    computeKbRunway(['usedCount' => 0], 60, 6.0) === 10.0
);

// ── chAggregateEnvCadenceStatus() ── (cross-environment cleanup: only this env's jobs are ever
// read out of a matrixed run's job list — never the other environment's, even though both are
// present in the same $jobs array)
$mixedJobs = [
    ['name' => 'rumors (test)',  'status' => 'completed', 'conclusion' => 'success'],
    ['name' => 'trivia (test)',  'status' => 'completed', 'conclusion' => 'success'],
    ['name' => 'rumors (live)',  'status' => 'completed', 'conclusion' => 'failure'],
    ['name' => 'trivia (live)',  'status' => 'completed', 'conclusion' => 'failure'],
];
check(
    'picks only this env\'s jobs: test succeeds while live fails in the same run',
    chAggregateEnvCadenceStatus($mixedJobs, 'test') === 'success'
);
check(
    'picks only this env\'s jobs: live fails while test succeeds in the same run',
    chAggregateEnvCadenceStatus($mixedJobs, 'live') === 'failure'
);
check(
    'no jobs at all for this env in the run → null (not a fabricated status)',
    chAggregateEnvCadenceStatus($mixedJobs, 'test') !== null
        && chAggregateEnvCadenceStatus([], 'test') === null
);
check(
    'one env job in_progress, none failed → in_progress (worst-of wins)',
    chAggregateEnvCadenceStatus([
        ['name' => 'rumors (test)', 'status' => 'in_progress', 'conclusion' => null],
        ['name' => 'trivia (test)', 'status' => 'completed', 'conclusion' => 'success'],
    ], 'test') === 'in_progress'
);

if ($fails > 0) {
    echo "\n$fails check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
