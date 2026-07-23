<?php
// CLI harness for the GitHub Actions Dashboard's cron evaluator and schedule/collision math
// (public/includes/actions-dashboard.php). Verified against the real .github/workflows/*.yml
// cron strings — see epics/github_actions_dashboard/plan.md decision #5 for why: the design
// handoff's own illustrative schedule table didn't match the real workflow files, so this
// harness pins down the real, verified crons instead.
// The file only needs cron/date math here — t() is never invoked by the functions under test,
// so no lang/DB bootstrap is needed.
// Run: php tests/unit/actions-schedule-harness.php    (exit 0 = all green)

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../public/includes/actions-dashboard.php';

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

$utc = new DateTimeZone('UTC');
$wf = ghWorkflowConfig();

// ── ghCronFireTimes: real cron strings, verified against the actual workflow files ──────

$emailTimes = ghCronFireTimes($wf['email-notify']['cron'][0], new DateTimeImmutable('2026-07-20', $utc));
check('email-notify (1 * * * *): 24 fire times/day', count($emailTimes) === 24);
check('email-notify: all fire at minute :01', $emailTimes === array_map(fn($h) => sprintf('%02d:01', $h), range(0, 23)));

$topupFriday = ghCronFireTimes($wf['content-topup']['cron'][0], new DateTimeImmutable('2026-07-24', $utc)); // a Friday
$topupMonday = ghCronFireTimes($wf['content-topup']['cron'][0], new DateTimeImmutable('2026-07-20', $utc)); // a Monday
check('content-topup (0 6 * * 5): fires once on Friday, at 06:00', $topupFriday === ['06:00']);
check('content-topup: does not fire on Monday', $topupMonday === []);

$qualiSat = ghCronFireTimes($wf['quali-import']['cron'][0], new DateTimeImmutable('2026-07-25', $utc)); // a Saturday
check('quali-import (*/5 6-23 * * 6): 216 fire times on Saturday (18h × 12/h)', count($qualiSat) === 216);
check('quali-import: first fire 06:00, last 23:55', reset($qualiSat) === '06:00' && end($qualiSat) === '23:55');

$secReviewFirst = ghCronFireTimes($wf['sec-review']['cron'][0], new DateTimeImmutable('2026-08-01', $utc));
$secReviewOther = ghCronFireTimes($wf['sec-review']['cron'][0], new DateTimeImmutable('2026-08-02', $utc));
check('sec-review (17 8 1 * *): fires once on the 1st, at 08:17', $secReviewFirst === ['08:17']);
check('sec-review: does not fire on the 2nd', $secReviewOther === []);

// kb-update's Saturday fire set is the union of only its two Saturday-tagged lines
// (0 12,13 * * 6 and 0 14,15,16,18 * * 6), not all 7 lines.
$kbSat = ghWorkflowDailyFireTimes($wf['kb-update']['cron'], new DateTimeImmutable('2026-07-25', $utc));
check('kb-update on Saturday: union of the two Saturday cron lines only', $kbSat === ['12:00', '13:00', '14:00', '15:00', '16:00', '18:00']);
$kbSun = ghWorkflowDailyFireTimes($wf['kb-update']['cron'], new DateTimeImmutable('2026-07-26', $utc));
check('kb-update on Sunday: uses the Sunday-tagged line', $kbSun === ['16:00', '18:00', '20:00', '22:00']);

// ── ghNextFireDateTime ───────────────────────────────────────────────────────────────

$next = ghNextFireDateTime($wf['nightly-tests']['cron'], new DateTimeImmutable('2026-07-22T15:00:00Z'));
check('nightly-tests next fire after 2026-07-22T15:00Z is 2026-07-23T01:00Z',
    $next !== null && $next->format('c') === '2026-07-23T01:00:00+00:00');

check('ghNextFireDateTime returns null for a manual-only (no cron) workflow',
    ghNextFireDateTime($wf['e2e']['cron'], new DateTimeImmutable('2026-07-22T15:00:00Z')) === null);

// ── ghComputeSchedule: monthly totals + per-day counts ──────────────────────────────────

$monthStart = new DateTimeImmutable('2026-07-01', $utc);
$now = new DateTimeImmutable('2026-07-22T15:12:00Z');
$schedule = ghComputeSchedule($wf, $monthStart, $now);

check('July 2026 has 31 days', $schedule['dayCount'] === 31);
check('nightly-tests (daily) monthly total = 31 (one per day)', $schedule['monthlyTotal']['nightly-tests'] === 31);
check('e2e (manual-only) excluded from monthlyTotal entirely', !array_key_exists('e2e', $schedule['monthlyTotal']));
check('e2e excluded from perWorkflowDay entirely', !array_key_exists('e2e', $schedule['perWorkflowDay']));

// content-topup fires only on Fridays — July 2026 has 5 Fridays (3, 10, 17, 24, 31).
check('content-topup monthly total = 5 (Fridays in July 2026)', $schedule['monthlyTotal']['content-topup'] === 5);

// ── Collisions ───────────────────────────────────────────────────────────────────────

// The real crons produce zero 3-way same-UTC-minute collisions this month (decision #5 —
// the design handoff's "Monday 07:00" collision scenario doesn't reproduce with the actual
// schedules: weekly-challenges and content-topup are different weekdays entirely, and
// email-notify's hourly :01 misses weekly-challenges' :00 by a minute). This is the honest
// output of computing from real data — assert it explicitly so a future change that fakes a
// collision to "look more interesting" gets caught.
$anyCollision = false;
foreach ($schedule['collisions'] as $dayCollisions) {
    if (!empty($dayCollisions)) { $anyCollision = true; break; }
}
check('real July 2026 schedule has zero 3-way collisions', $anyCollision === false);

// Synthetic collision check, independent of which 9 workflows exist today: 3 workflows firing
// at the exact same UTC minute must flag; 2 must not (threshold is 3+, not 2+).
$synthetic = [
    'a' => ['file' => 'a.yml', 'name' => 'Job A', 'icon' => 'a', 'cron' => ['0 9 * * 1'], 'manual' => false],
    'b' => ['file' => 'b.yml', 'name' => 'Job B', 'icon' => 'b', 'cron' => ['0 9 * * 1'], 'manual' => false],
    'c' => ['file' => 'c.yml', 'name' => 'Job C', 'icon' => 'c', 'cron' => ['0 9 * * 1'], 'manual' => false],
];
$mondaySchedule = ghComputeSchedule($synthetic, $monthStart, $now);
// 2026-07-06 is a Monday.
check('3 workflows at the same UTC minute → flagged as a collision', !empty($mondaySchedule['collisions'][6]['09:00']));
check('collision entry lists all 3 workflow names', count($mondaySchedule['collisions'][6]['09:00'] ?? []) === 3);

$twoOnly = [
    'a' => ['file' => 'a.yml', 'name' => 'Job A', 'icon' => 'a', 'cron' => ['0 9 * * 1'], 'manual' => false],
    'b' => ['file' => 'b.yml', 'name' => 'Job B', 'icon' => 'b', 'cron' => ['0 9 * * 1'], 'manual' => false],
];
$twoSchedule = ghComputeSchedule($twoOnly, $monthStart, $now);
check('2 workflows at the same UTC minute → NOT flagged (threshold is 3+)', empty($twoSchedule['collisions'][6]));

echo $fails === 0 ? "\nAll actions-schedule checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
