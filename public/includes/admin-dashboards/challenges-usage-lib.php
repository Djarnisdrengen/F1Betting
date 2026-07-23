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

// Composition point for Dashboards → Oversigt (Feature 2) — read-only. Challenges usage has
// no natural "problem" state the way the other three dashboards do (feature-2: "flag = 0 in
// steady state, this dashboard is analytics, not itself a source of problems").
function chGetUsageSnapshot(PDO $db): array {
    return ['activeParticipants' => chGetActiveParticipantsCount($db), 'flagCount' => 0];
}
