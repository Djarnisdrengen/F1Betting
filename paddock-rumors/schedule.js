/**
 * Schedule evaluator — the single source of truth for "what should run now".
 *
 * Pure logic, no I/O. Given the current time, the F1 calendar, the pipeline
 * state, and the latest finished round, returns a precise plan: which rounds
 * need Tier 1 (race+driver synthesis), which need an enrichment pass, and
 * which are blocked (too fresh, window passed, already done).
 *
 * Two windows govern enrichment:
 *
 *   ENRICHMENT_MIN_AGE_HOURS  ← analysis pieces (F1MATHS, F1 TECH, Edd Straw
 *                                rankings) aren't published until ~36h after
 *                                race finish, so we don't bother fetching
 *                                before then.
 *
 *   ENRICHMENT_WINDOW_HOURS   ← after this many hours we stop re-running
 *                                enrichment for that round. Anything that
 *                                hasn't been published by T+96h probably
 *                                isn't coming.
 *
 * Between MIN and WINDOW, every pipeline run does an enrichment pass on
 * that round — so if F1Technical drops a F1MATHS piece on Wednesday at 18:00,
 * the next cron picks it up. Enrichment doc IDs are deterministic (slugged
 * from titles), so upsert dedups automatically.
 */

export const ENRICHMENT_MIN_AGE_HOURS = 36;
export const ENRICHMENT_WINDOW_HOURS = 96;

const HOUR_MS = 3600 * 1000;

/**
 * Parse a Jolpica race date+time into a UTC Date.
 * Jolpica gives date "2026-05-24" and time "18:00:00Z" separately. If time
 * is missing we default to 14:00Z, a reasonable European-afternoon assumption.
 */
function raceStartUtc(scheduleEntry) {
  const date = scheduleEntry.date;
  const time = scheduleEntry.time || '14:00:00Z';
  return new Date(`${date}T${time}`);
}

/**
 * Conservative estimate of when a race FINISHES (vs starts). Most races
 * are ~2 hours; we use 2.5h to be safe so we don't try to fetch results
 * for a race that's technically still in progress according to the schedule.
 */
function raceEndEstimate(scheduleEntry) {
  return new Date(raceStartUtc(scheduleEntry).getTime() + 2.5 * HOUR_MS);
}

/**
 * Compute the plan.
 *
 * @param {object}   args
 * @param {Date}    [args.now]                 - defaults to current time
 * @param {object}   args.state                - { season, rounds: { "N": { tier1_at, enrichment_last_at } }, schema_version }
 * @param {object[]} args.schedule             - from getSeasonSchedule() — must include this season's rounds
 * @param {number|null} args.latestFinishedRound - from getLatestFinishedRound()
 * @param {number}   args.currentSeason
 * @returns {object} plan
 */
export function evaluateSchedule({
  now = new Date(),
  state,
  schedule,
  latestFinishedRound,
  currentSeason
}) {
  const plan = {
    rounds: [],          // [{ round, raceName, ageHours, tier1Status, enrichmentStatus, work: bool }]
    work: [],            // subset of rounds[] where work is needed
    anyWork: false,
    summary: ''
  };

  if (latestFinishedRound === null || latestFinishedRound === undefined) {
    plan.summary = 'no finished rounds yet for this season';
    return plan;
  }

  const roundsState = state?.rounds || {};

  for (let round = 1; round <= latestFinishedRound; round++) {
    const sched = schedule.find(s => s.round === round && s.season === currentSeason);
    if (!sched) continue; // schedule missing this round; skip silently

    const ageHours = (now - raceEndEstimate(sched)) / HOUR_MS;
    const rState = roundsState[String(round)] || {};

    // Tier 1: needed if not yet done.
    const tier1Status = rState.tier1_at ? 'done' : 'needed';

    // Enrichment: needed if the race is in the enrichment window AND
    //   either we've never enriched it, OR our last enrichment was before
    //   the latest possible publishing window edge (so a later run can pick
    //   up newly published pieces).
    let enrichmentStatus;
    if (ageHours < ENRICHMENT_MIN_AGE_HOURS) {
      enrichmentStatus = 'too_fresh';
    } else if (ageHours > ENRICHMENT_WINDOW_HOURS) {
      enrichmentStatus = rState.enrichment_last_at ? 'done' : 'window_passed';
    } else {
      // In window. Run enrichment if we haven't yet OR if it's been ≥12h since
      // last enrichment (gives new analysis publications time to land).
      const lastEnrichMs = rState.enrichment_last_at
        ? new Date(rState.enrichment_last_at).getTime()
        : 0;
      const hoursSinceEnrich = (now.getTime() - lastEnrichMs) / HOUR_MS;
      enrichmentStatus = hoursSinceEnrich >= 12 ? 'needed' : 'done_recent';
    }

    const work = tier1Status === 'needed' || enrichmentStatus === 'needed';

    const entry = {
      round,
      raceName: sched.raceName,
      ageHours: +ageHours.toFixed(1),
      tier1Status,
      enrichmentStatus,
      work
    };
    plan.rounds.push(entry);
    if (work) plan.work.push(entry);
  }

  plan.anyWork = plan.work.length > 0;
  plan.summary = plan.anyWork
    ? `${plan.work.length} round(s) need work: ${plan.work.map(w => `R${w.round}[${w.tier1Status === 'needed' ? 'T1' : ''}${w.enrichmentStatus === 'needed' ? 'EN' : ''}]`).join(', ')}`
    : `nothing to do (${plan.rounds.length} rounds scanned, all up to date or out of window)`;

  return plan;
}

/**
 * Migrate legacy state shape ({ season, round }) to the new per-round shape.
 * Idempotent: returns input unchanged if already migrated.
 *
 * Legacy: { season: 2026, round: 5 }
 * New:    { season: 2026, rounds: { "1": { tier1_at: ... }, ..., "5": { ... } }, schema_version: 2 }
 *
 * Migration writes tier1_at=<unknown-past> for each legacy-completed round
 * so Tier 1 isn't redone. Enrichment is left empty so it CAN run if a round
 * still falls within the enrichment window — desirable.
 */
export function migrateState(maybeOldState) {
  if (!maybeOldState || maybeOldState.schema_version === 2) {
    return maybeOldState && maybeOldState.schema_version === 2
      ? maybeOldState
      : { season: 2026, rounds: {}, schema_version: 2 };
  }

  // Legacy shape detected
  const { season, round } = maybeOldState;
  const rounds = {};
  if (typeof round === 'number' && round > 0) {
    const placeholder = new Date(0).toISOString(); // unknown-past
    for (let r = 1; r <= round; r++) {
      rounds[String(r)] = { tier1_at: placeholder, enrichment_last_at: null };
    }
  }
  return { season: season || 2026, rounds, schema_version: 2 };
}
