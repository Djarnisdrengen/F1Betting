<?php
// CLI harness for Nøgler & Rotation's pure math and config-replace logic
// (public/includes/admin-dashboards/nogler-rotation-lib.php). DB-free and file-write-free —
// see epics/Admin settings and dashboards/feature-3-nogler-rotation.md and plan.md's
// "Testing approach" for why these specific boundary cases are here (test-manager review
// flagged the original proposal as missing them: policy=0, negative/clock-skew age, the
// unseeded/null case, and the duplicate-define() failure mode).
// Run: php tests/unit/nogler-rotation-harness.php    (exit 0 = all green)

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../public/includes/admin-dashboards/nogler-rotation-lib.php';

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

// ── nrHealthScore ────────────────────────────────────────────────────────────────────

check('health score: no issues -> 100', nrHealthScore(0, 0, 0) === 100);
check('health score: mixed issues -> exact formula (100-16-11-8=65)', nrHealthScore(1, 1, 2) === 65);
check('health score: clamps at 0, never negative', nrHealthScore(10, 10, 10) === 0);
check('health score: clamps at 100 ceiling too (defensive)', nrHealthScore(0, 0, 0) <= 100);

// ── nrTokenBadge ─────────────────────────────────────────────────────────────────────

check('token badge: unknown when no expiry recorded yet', nrTokenBadge(null) === 'unknown');
check('token badge: exactly 14 days -> warn (boundary)', nrTokenBadge(14) === 'warn');
check('token badge: 15 days -> ok', nrTokenBadge(15) === 'ok');
check('token badge: 0 days -> warn (expires today, not yet expired)', nrTokenBadge(0) === 'warn');
check('token badge: -1 day (past expiry) -> bad', nrTokenBadge(-1) === 'bad');

// ── nrSecretBadge / nrSecretProgressPct ──────────────────────────────────────────────

check('secret badge: unknown when no rotation ever recorded', nrSecretBadge(null, 90) === 'unknown');
check('secret badge: exactly 80% of policy -> due (boundary)', nrSecretBadge(72, 90) === 'due');
check('secret badge: just under 80% -> ok', nrSecretBadge(71, 90) === 'ok');
check('secret badge: exactly 100% of policy -> over (boundary)', nrSecretBadge(90, 90) === 'over');
check('secret badge: policy = 0 -> over, no division-by-zero error', nrSecretBadge(5, 0) === 'over');
check('secret progress: policy = 0 -> 100%, no division-by-zero error', nrSecretProgressPct(5, 0) === 100);
check('secret progress: negative age (clock skew) clamps to 0%, not negative', nrSecretProgressPct(-3, 90) === 0);
check('secret progress: unknown age -> 100% (visually needs-attention, not silently 0)', nrSecretProgressPct(null, 90) === 100);
check('secret progress: exact half of policy -> 50%', nrSecretProgressPct(45, 90) === 50);

// ── nrDaysBetween ────────────────────────────────────────────────────────────────────

$d1 = new DateTimeImmutable('2026-07-01T00:00:00Z');
$d2 = new DateTimeImmutable('2026-07-11T00:00:00Z');
check('nrDaysBetween: 10 days forward is 10', nrDaysBetween($d1, $d2) === 10);
check('nrDaysBetween: reversed args is negative', nrDaysBetween($d2, $d1) === -10);
check('nrDaysBetween: same instant is 0', nrDaysBetween($d1, $d1) === 0);

// ── nrReplaceConfigConst — the config-file writer's pure core ───────────────────────

$fixture = "<?php\ndefine('APP_ENV', 'test');\ndefine('CHALLENGE_INVITE_SECRET', 'oldvalue123');\ndefine('SITE_URL', 'https://example.dk');\n";

$replaced = nrReplaceConfigConst($fixture, 'CHALLENGE_INVITE_SECRET', 'newvalue456');
check('replace: single match is replaced', $replaced !== null && str_contains($replaced, "define('CHALLENGE_INVITE_SECRET', 'newvalue456');"));
check('replace: old value is gone', $replaced !== null && !str_contains($replaced, 'oldvalue123'));
check('replace: every other line is byte-identical', $replaced !== null
    && str_contains($replaced, "define('APP_ENV', 'test');")
    && str_contains($replaced, "define('SITE_URL', 'https://example.dk');"));
check('replace: line count is unchanged', $replaced !== null && substr_count($replaced, "\n") === substr_count($fixture, "\n"));

$missing = nrReplaceConfigConst($fixture, 'DOES_NOT_EXIST', 'x');
check('replace: zero matches (missing constant) fails closed to null', $missing === null);

$duplicateFixture = "<?php\ndefine('DB_PASS', 'first');\ndefine('DB_PASS', 'second');\n";
$duplicate = nrReplaceConfigConst($duplicateFixture, 'DB_PASS', 'new');
check('replace: 2+ matches (ambiguous, e.g. a prior botched edit) fails closed to null, does not guess', $duplicate === null);

echo $fails === 0 ? "\nAll Nøgler & Rotation checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
