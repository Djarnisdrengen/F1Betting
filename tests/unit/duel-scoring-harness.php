<?php
// CLI harness for scoreDuelPrediction() in public/includes/challenges.php (DUEL-01, D1's
// fixed 5/2/0 duel scoring — distinct from core scoring's settings-driven 25/18/15).
// includes/challenges.php only defines functions at top level (guarded `define()`s aside),
// so it's safe to load standalone without a DB connection.
// Run: php tests/unit/duel-scoring-harness.php    (exit 0 = all green)

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../public/includes/challenges.php';

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

// AC scenario "Exact win under 5/2/0": A picks VER/NOR/LEC, result VER/NOR/LEC → 15 (5+5+5).
check(
    'all three exact → 15',
    scoreDuelPrediction(['ver', 'nor', 'lec'], ['ver', 'nor', 'lec']) === 15
);

// Same scenario, B side: picks NOR/VER/PIA vs result VER/NOR/LEC → 4 (2+2+0).
check(
    'both swapped + one absent → 4 (2+2+0)',
    scoreDuelPrediction(['nor', 'ver', 'pia'], ['ver', 'nor', 'lec']) === 4
);

check(
    'total miss → 0',
    scoreDuelPrediction(['pia', 'sai', 'alo'], ['ver', 'nor', 'lec']) === 0
);

check(
    'single exact position only → 5',
    scoreDuelPrediction(['ver', 'sai', 'alo'], ['ver', 'nor', 'lec']) === 5
);

// The function itself doesn't dedupe a repeated pick against the single matching result slot
// (each position independently scans the whole result array) — real duplicate picks never
// reach it in practice, blocked upstream by validateDuelPick()'s no-same-driver rule. Documented
// here as the function's actual behavior, not a requirement.
check(
    'repeated pick scores against the same result slot from every position that tries it',
    scoreDuelPrediction(['nor', 'nor', 'nor'], ['ver', 'nor', 'lec']) === 9 // p1: wrong-pos +2, p2: exact +5, p3: wrong-pos +2
);

check(
    'identical picks to result at every position → max 15',
    scoreDuelPrediction(['a', 'b', 'c'], ['a', 'b', 'c']) === 15
);

check(
    'full rotation (each pick is a different result driver, wrong slot) → 6 (2+2+2)',
    scoreDuelPrediction(['b', 'c', 'a'], ['a', 'b', 'c']) === 6
);

check(
    'missing pick slot (null) contributes nothing, does not throw',
    scoreDuelPrediction(['ver', null, 'lec'], ['ver', 'nor', 'lec']) === 10 // p1 + p3 exact, p2 skipped
);

echo $fails === 0 ? "\nAll duel-scoring checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
