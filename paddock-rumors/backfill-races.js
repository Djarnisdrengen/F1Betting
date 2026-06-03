#!/usr/bin/env node
/**
 * Paddock Rumors — one-off race doc backfill / refresh.
 *
 * Re-synthesises race docs for completed rounds. Use --force to regenerate
 * existing docs (e.g. after expanding full_grid from top-10 to all drivers).
 *
 * Usage:
 *   node backfill-races.js              synthesise any rounds missing a race doc
 *   node backfill-races.js --force      re-synthesise all completed rounds
 *   node backfill-races.js --dry-run    show plan, no Claude calls
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

import {
  getSeasonSchedule,
  getLatestFinishedRound,
  getRaceResults,
  getQualifyingResults,
  getDriverStandings
} from './fetch-results.js';
import { synthesiseRaceDoc } from './synthesise.js';
import { migrateState } from './schedule.js';

const __dirname      = path.dirname(fileURLToPath(import.meta.url));
const KB_PATH        = path.resolve(__dirname, './data/knowledge-base.json');
const STATE_PATH     = path.resolve(__dirname, './state/last_processed_round.json');
const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);
const DRY_RUN        = process.argv.includes('--dry-run');
const FORCE          = process.argv.includes('--force');

const sleep = ms => new Promise(r => setTimeout(r, ms));

function readState() {
  if (!fs.existsSync(STATE_PATH)) return { season: CURRENT_SEASON, rounds: {}, schema_version: 2 };
  return migrateState(JSON.parse(fs.readFileSync(STATE_PATH, 'utf-8')));
}

async function main() {
  if (!DRY_RUN && !process.env.ANTHROPIC_API_KEY) {
    console.error('[backfill-races] FATAL: ANTHROPIC_API_KEY not set');
    process.exit(1);
  }

  console.log(`[backfill-races] season=${CURRENT_SEASON}  dry-run=${DRY_RUN}  force=${FORCE}`);

  const schedule = await getSeasonSchedule(CURRENT_SEASON);
  const latest   = await getLatestFinishedRound(CURRENT_SEASON);

  if (!latest) {
    console.log('[backfill-races] no finished rounds yet — nothing to do');
    return;
  }

  console.log(`[backfill-races] latest finished round: R${latest}`);

  let kb = fs.existsSync(KB_PATH) ? JSON.parse(fs.readFileSync(KB_PATH, 'utf-8')) : [];
  const existingRaceIds = new Set(
    kb.filter(d => d.tags?.type === 'race').map(d => d.id)
  );

  // Determine which rounds to process
  const toProcess = [];
  for (let r = 1; r <= latest; r++) {
    const sched = schedule.find(s => s.round === r && s.season === CURRENT_SEASON);
    if (!sched) continue;
    // Race doc IDs follow the pattern race-YYYY-rNN-<circuit-slug>
    const hasDoc = [...existingRaceIds].some(id => id.startsWith(`race-${CURRENT_SEASON}-r${String(r).padStart(2, '0')}-`));
    if (hasDoc && !FORCE) {
      console.log(`[backfill-races] R${r} ${sched.raceName} — already done, skipping`);
    } else {
      toProcess.push({ round: r, raceName: sched.raceName });
    }
  }

  if (toProcess.length === 0) {
    console.log('[backfill-races] all rounds already have race docs (use --force to regenerate)');
    return;
  }

  console.log(`[backfill-races] rounds to process: ${toProcess.map(r => `R${r.round}`).join(', ')}`);

  if (DRY_RUN) {
    console.log('[backfill-races] --dry-run: stopping here');
    return;
  }

  // Cache standings to avoid re-fetching (needed for before/after deltas)
  const standingsCache = new Map();
  async function getStandings(round) {
    if (round === 0) return [];
    if (standingsCache.has(round)) return standingsCache.get(round);
    const s = await getDriverStandings(CURRENT_SEASON, round);
    standingsCache.set(round, s);
    return s;
  }

  let updated = 0;

  for (const { round, raceName } of toProcess) {
    console.log(`\n[backfill-races] R${round} ${raceName}`);
    try {
      const [race, quali, standingsBefore, standingsAfter] = await Promise.all([
        getRaceResults(CURRENT_SEASON, round),
        getQualifyingResults(CURRENT_SEASON, round),
        getStandings(round - 1),
        getStandings(round),
      ]);

      console.log(`[backfill-races]   synthesising race doc`);
      const doc = await synthesiseRaceDoc(race, quali, standingsBefore, standingsAfter);

      const idx = kb.findIndex(d => d.id === doc.id);
      if (idx >= 0) kb[idx] = doc; else kb.push(doc);
      fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));

      console.log(`[backfill-races]   + ${doc.id}`);
      updated++;
      await sleep(1500);
    } catch (err) {
      console.warn(`[backfill-races]   failed R${round}: ${err.message}`);
    }
  }

  console.log(`\n[backfill-races] done. updated=${updated}`);
  console.log(`[backfill-races] KB now has ${kb.length} docs`);
}

main().catch(err => {
  console.error('[backfill-races] FATAL:', err);
  process.exit(1);
});
