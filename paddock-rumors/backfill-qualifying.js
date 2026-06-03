#!/usr/bin/env node
/**
 * Paddock Rumors — one-off qualifying backfill.
 *
 * Fetches qualifying results from Jolpica for all completed rounds that
 * don't yet have a qualifying doc in the KB, and synthesises them via Claude.
 *
 * Usage:
 *   node backfill-qualifying.js              backfill all missing rounds
 *   node backfill-qualifying.js --force      re-synthesise even if already done
 *   node backfill-qualifying.js --dry-run    show what would run, no Claude calls
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

import { getSeasonSchedule, getLatestFinishedRound, getQualifyingResults } from './fetch-results.js';
import { synthesiseQualiDoc } from './synthesise.js';
import { migrateState } from './schedule.js';

const __dirname  = path.dirname(fileURLToPath(import.meta.url));
const KB_PATH    = path.resolve(__dirname, './data/knowledge-base.json');
const STATE_PATH = path.resolve(__dirname, './state/last_processed_round.json');

const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);
const DRY_RUN        = process.argv.includes('--dry-run');
const FORCE          = process.argv.includes('--force');

const sleep = ms => new Promise(r => setTimeout(r, ms));

function readState() {
  if (!fs.existsSync(STATE_PATH)) return { season: CURRENT_SEASON, rounds: {}, schema_version: 2 };
  return migrateState(JSON.parse(fs.readFileSync(STATE_PATH, 'utf-8')));
}

function writeState(state) {
  fs.writeFileSync(STATE_PATH, JSON.stringify(state, null, 2));
}

function loadKb() {
  if (!fs.existsSync(KB_PATH)) return [];
  return JSON.parse(fs.readFileSync(KB_PATH, 'utf-8'));
}

function saveKb(kb) {
  fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));
}

async function main() {
  if (!DRY_RUN && !process.env.ANTHROPIC_API_KEY) {
    console.error('[backfill-quali] FATAL: ANTHROPIC_API_KEY not set (use --dry-run to skip Claude calls)');
    process.exit(1);
  }

  console.log(`[backfill-quali] season=${CURRENT_SEASON}  dry-run=${DRY_RUN}  force=${FORCE}`);

  const schedule = await getSeasonSchedule(CURRENT_SEASON);
  const latest   = await getLatestFinishedRound(CURRENT_SEASON);

  if (!latest) {
    console.log('[backfill-quali] no finished rounds yet — nothing to do');
    return;
  }

  console.log(`[backfill-quali] latest finished round: R${latest}`);

  const state      = readState();
  const roundState = state.rounds || {};

  // Determine which rounds need qualifying docs
  const toProcess = [];
  for (let r = 1; r <= latest; r++) {
    const alreadyDone = !!roundState[String(r)]?.qualifying_at;
    if (alreadyDone && !FORCE) {
      console.log(`[backfill-quali] R${r} — already done, skipping (use --force to redo)`);
    } else {
      const sched = schedule.find(s => s.round === r && s.season === CURRENT_SEASON);
      toProcess.push({ round: r, raceName: sched?.raceName || `Round ${r}` });
    }
  }

  if (toProcess.length === 0) {
    console.log('[backfill-quali] all rounds already have qualifying docs');
    return;
  }

  console.log(`[backfill-quali] rounds to process: ${toProcess.map(r => `R${r.round}`).join(', ')}`);

  if (DRY_RUN) {
    console.log('[backfill-quali] --dry-run: stopping here');
    return;
  }

  let kb      = loadKb();
  let added   = 0;
  let skipped = 0;

  for (const { round, raceName } of toProcess) {
    console.log(`\n[backfill-quali] R${round} ${raceName}`);
    try {
      const quali = await getQualifyingResults(CURRENT_SEASON, round);
      if (!quali?.qualifying?.length) {
        console.log(`[backfill-quali]   no qualifying data available — skipping`);
        skipped++;
        continue;
      }

      console.log(`[backfill-quali]   synthesising qualifying doc (pole: ${quali.qualifying[0]?.driverName})`);
      const doc = await synthesiseQualiDoc(quali);

      const idx = kb.findIndex(d => d.id === doc.id);
      if (idx >= 0) kb[idx] = doc; else kb.push(doc);
      saveKb(kb);

      state.rounds[String(round)] = state.rounds[String(round)] || {};
      state.rounds[String(round)].qualifying_at = new Date().toISOString();
      writeState(state);

      console.log(`[backfill-quali]   + ${doc.id}`);
      added++;
      await sleep(1500);
    } catch (err) {
      console.warn(`[backfill-quali]   failed R${round}: ${err.message}`);
    }
  }

  console.log(`\n[backfill-quali] done. added=${added}  skipped=${skipped}`);
  console.log(`[backfill-quali] KB now has ${kb.length} docs`);
}

main().catch(err => {
  console.error('[backfill-quali] FATAL:', err);
  process.exit(1);
});
