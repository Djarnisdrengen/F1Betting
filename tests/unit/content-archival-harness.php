<?php
// CLI harness for rumorArchiveBudget() in public/includes/challenges.php — the floor-guard
// arithmetic behind REQ-604/NFR-601 (Real expiry & rotation for content-topup epic).
// includes/challenges.php only defines functions at top level (guarded `define()`s aside),
// so it's safe to load standalone without a DB connection.
// Run: php tests/unit/content-archival-harness.php    (exit 0 = all green)

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

// Boundary math only (REQ-604) — archiveStaleContent()'s own DB-touching parts are verified
// by the manual run_content_archival test-seed trigger and by the e2e suite, not here.

check(
    'exactly at the floor → 0 (archiving anything would breach it)',
    rumorArchiveBudget(6, 6) === 0
);

check(
    'one above the floor → 1',
    rumorArchiveBudget(7, 6) === 1
);

check(
    'one below the floor → 0, never negative',
    rumorArchiveBudget(5, 6) === 0
);

check(
    'zero live items, positive floor → 0',
    rumorArchiveBudget(0, 6) === 0
);

check(
    'floor of zero, some live items → all of them budgeted',
    rumorArchiveBudget(12, 0) === 12
);

check(
    'floor of zero, zero live items → 0',
    rumorArchiveBudget(0, 0) === 0
);

check(
    'well above the floor → full surplus budgeted (12 live, floor 6 → 6)',
    rumorArchiveBudget(12, 6) === 6
);

if ($fails > 0) {
    echo "\n$fails check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
