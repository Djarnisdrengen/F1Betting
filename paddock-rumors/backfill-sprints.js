#!/usr/bin/env node
/**
 * Paddock Rumors — one-off sprint race doc backfill.
 *
 * Synthesises sprint race docs for completed sprint rounds that don't yet
 * have a sprint doc in the KB.
 *
 * 2026 sprint rounds: R2 (China), R4 (Miami), R5 (Canada),
 *                     R9 (Britain), R12 (Netherlands), R16 (Singapore)
 *
 * Usage:
 *   node backfill-sprints.js              backfill all sprint rounds with data
 *   node backfill-sprints.js --force      re-synthesise even if already done
 *   node backfill-sprints.js --dry-run    show plan, no Claude calls
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

import { getSeasonSchedule, getLatestFinishedRound, getSprintResults } from './fetch-results.js';
import { synthesiseSprintDoc } from './synthesise.js';
import { migrateState } from './schedule.js';

const __dirname      = path.dirname(fileURLToPath(import.meta.url));
const KB_PATH        = path.resolve(__dirname, './data/knowledge-base.json');
const STATE_PATH     = path.resolve(__dirname, './state/last_processed_round.json');
const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);
const DRY_RUN        = process.argv.includes('--dry-run');
const FORCE          = process.argv.includes('--force');

// Known sprint rounds for 2026. The pipeline detects them dynamically;
// this list is just for documentation.
const SPRINT_ROUNDS_2026 = [2, 4, 5, 9, 12, 16];

const sleep = ms => new Promise(r => setTimeout(r, ms));

function readState() {
  if (!fs.existsSync(STATE_PATH)) return { season: CURRENT_SEASON, rounds: {}, schema_version: 2 };
  return migrateState(JSON.parse(fs.readFileSync(STATE_PATH, 'utf-8')));
}

function writeState(state) {
  fs.writeFileSync(STATE_PATH, JSON.stringify(state, null, 2));
}

async function main() {
  if (!DRY_RUN && !process.env.ANTHROPIC_API_KEY) {
    console.error('[backfill-sprints] FATAL: ANTHROPIC_API_KEY not set');
    process.exit(1);
  }

  console.log(`[backfill-sprints] season=${CURRENT_SEASON}  dry-run=${DRY_RUN}  force=${FORCE}`);

  const schedule = await getSeasonSchedule(CURRENT_SEASON);
  const latest   = await getLatestFinishedRound(CURRENT_SEASON);

  if (!latest) {
    console.log('[backfill-sprints] no finished rounds yet');
    return;
  }

  let kb    = fs.existsSync(KB_PATH) ? JSON.parse(fs.readFileSync(KB_PATH, 'utf-8')) : [];
  const state = readState();

  // Check every completed round — skip non-sprint rounds automatically
  // (getSprintResults returns null if the round has no sprint)
  const toCheck = [];
  for (let r = 1; r <= latest; r++) {
    const sched     = schedule.find(s => s.round === r && s.season === CURRENT_SEASON);
    const alreadyDone = !!state.rounds?.[String(r)]?.sprint_at;
    if (alreadyDone && !FORCE) {
      console.log(`[backfill-sprints] R${r} — sprint already done, skipping`);
    } else {
      toCheck.push({ round: r, raceName: sched?.raceName || `Round ${r}` });
    }
  }

  if (!toCheck.length) {
    console.log('[backfill-sprints] nothing to process');
    return;
  }

  if (DRY_RUN) {
    console.log(`[backfill-sprints] would check ${toCheck.length} round(s): ${toCheck.map(r => `R${r.round}`).join(', ')}`);
    console.log('[backfill-sprints] --dry-run: stopping here');
    return;
  }

  let added = 0, noSprint = 0;

  for (const { round, raceName } of toCheck) {
    console.log(`\n[backfill-sprints] R${round} ${raceName}`);
    try {
      const sprint = await getSprintResults(CURRENT_SEASON, round);
      if (!sprint?.results?.length) {
        console.log(`[backfill-sprints]   no sprint data — not a sprint round`);
        noSprint++;
        continue;
      }

      console.log(`[backfill-sprints]   sprint winner: ${sprint.results[0]?.driverName} — synthesising`);
      const doc = await synthesiseSprintDoc(sprint);

      const idx = kb.findIndex(d => d.id === doc.id);
      if (idx >= 0) kb[idx] = doc; else kb.push(doc);
      fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));

      state.rounds[String(round)] = state.rounds[String(round)] || {};
      state.rounds[String(round)].sprint_at = new Date().toISOString();
      writeState(state);

      console.log(`[backfill-sprints]   + ${doc.id}`);
      added++;
      await sleep(1500);
    } catch (err) {
      console.warn(`[backfill-sprints]   failed R${round}: ${err.message}`);
    }
  }

  console.log(`\n[backfill-sprints] done. added=${added}  no-sprint=${noSprint}`);
  console.log(`[backfill-sprints] KB now has ${kb.length} docs`);
}

main().catch(err => {
  console.error('[backfill-sprints] FATAL:', err);
  process.exit(1);
});
