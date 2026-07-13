<?php
// CLI harness for isRaceHeroWindow() in public/includes/challenges.php (HERO-01, D9's hero
// window boundaries — windowOpen = raceStart - betting_window_hours; the race hero shows from
// windowOpen-24h through raceStart+3h, the Challenges hero the rest of the time).
// includes/challenges.php only defines functions at top level, so it's safe to load standalone
// without a DB connection as long as every call passes explicit $settings (skips the
// getSettings() fallback, which needs config.php/a DB).
// Run: php tests/unit/hero-window-harness.php    (exit 0 = all green)

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

$race = ['race_date' => '2026-07-20', 'race_time' => '15:00:00'];
$settings = ['betting_window_hours' => 48];
$raceStart = new DateTime('2026-07-20 15:00:00');
// windowOpen = raceStart - 48h; windowStart = windowOpen - 24h = raceStart - 72h.

check(
    'windowOpen-25h (1h before windowStart) → outside window (Challenges hero)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('-73 hours')) === false
);

check(
    'windowOpen-23h (1h inside windowStart) → inside window (race hero)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('-71 hours')) === true
);

check(
    'raceStart+2h (before raceEnd) → inside window (race hero)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('+2 hours')) === true
);

check(
    'raceStart+4h (past raceEnd) → outside window (Challenges hero)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('+4 hours')) === false
);

// Exact boundaries: windowStart and raceEnd are both inclusive (>= / <=).
check(
    'exactly windowStart → inside window (inclusive lower bound)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('-72 hours')) === true
);

check(
    'exactly raceEnd → inside window (inclusive upper bound)',
    isRaceHeroWindow($race, $settings, (clone $raceStart)->modify('+3 hours')) === true
);

// A shorter betting_window_hours setting shifts windowOpen (and so windowStart) accordingly —
// confirms the setting is actually read, not hard-coded to the 48h default.
$settings24 = ['betting_window_hours' => 24];
check(
    'betting_window_hours=24 narrows the window: raceStart-49h is now outside',
    isRaceHeroWindow($race, $settings24, (clone $raceStart)->modify('-49 hours')) === false
);
check(
    'betting_window_hours=24: raceStart-47h is inside',
    isRaceHeroWindow($race, $settings24, (clone $raceStart)->modify('-47 hours')) === true
);

echo $fails === 0 ? "\nAll hero-window checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
