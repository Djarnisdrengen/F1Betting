#!/usr/bin/env node
/**
 * Paddock Rumors — one-off driver doc backfill.
 *
 * Synthesises season-form docs for any driver in the current standings
 * who doesn't yet have a doc in the KB. Useful after raising TOP_N_DRIVERS
 * from 10 to 20 mid-season.
 *
 * Usage:
 *   node backfill-drivers.js              add missing drivers only
 *   node backfill-drivers.js --force      re-synthesise all drivers
 *   node backfill-drivers.js --dry-run    show who is missing, no Claude calls
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

import {
  getSeasonSchedule,
  getLatestFinishedRound,
  getRaceResults,
  getDriverStandings
} from './fetch-results.js';
import { synthesiseDriverDoc } from './synthesise.js';

const __dirname      = path.dirname(fileURLToPath(import.meta.url));
const KB_PATH        = path.resolve(__dirname, './data/knowledge-base.json');
const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);
const DRY_RUN        = process.argv.includes('--dry-run');
const FORCE          = process.argv.includes('--force');

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function main() {
  if (!DRY_RUN && !process.env.ANTHROPIC_API_KEY) {
    console.error('[backfill-drivers] FATAL: ANTHROPIC_API_KEY not set');
    process.exit(1);
  }

  console.log(`[backfill-drivers] season=${CURRENT_SEASON}  dry-run=${DRY_RUN}  force=${FORCE}`);

  const latest = await getLatestFinishedRound(CURRENT_SEASON);
  if (!latest) {
    console.log('[backfill-drivers] no finished rounds yet — nothing to do');
    return;
  }

  // Current standings (all drivers)
  const standings = await getDriverStandings(CURRENT_SEASON, latest);
  console.log(`[backfill-drivers] ${standings.length} drivers in standings after R${latest}`);

  // Load KB and find which driver docs already exist
  let kb = fs.existsSync(KB_PATH) ? JSON.parse(fs.readFileSync(KB_PATH, 'utf-8')) : [];
  const existingIds = new Set(kb.map(d => d.id));

  const missing = standings.filter(s => {
    const id = `driver-${s.driverId}-${CURRENT_SEASON}`;
    return FORCE ? true : !existingIds.has(id);
  });

  if (missing.length === 0) {
    console.log('[backfill-drivers] all drivers already have docs');
    return;
  }

  console.log(`[backfill-drivers] missing docs for: ${missing.map(s => s.driverName).join(', ')}`);

  if (DRY_RUN) {
    console.log('[backfill-drivers] --dry-run: stopping here');
    return;
  }

  // Fetch all race results once (needed by synthesiseDriverDoc)
  console.log('[backfill-drivers] fetching all race results...');
  const allRaces = [];
  for (let r = 1; r <= latest; r++) {
    allRaces.push(await getRaceResults(CURRENT_SEASON, r));
    await sleep(400);
  }

  let added = 0;
  for (const s of missing) {
    const driver = { driverId: s.driverId, driverName: s.driverName, constructor: s.constructor };
    console.log(`[backfill-drivers] synthesising: ${driver.driverName} (P${s.position}, ${s.points} pts)`);
    try {
      const doc = await synthesiseDriverDoc(driver, CURRENT_SEASON, allRaces, standings);
      const idx = kb.findIndex(d => d.id === doc.id);
      if (idx >= 0) kb[idx] = doc; else kb.push(doc);
      fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));
      console.log(`[backfill-drivers]   + ${doc.id}`);
      added++;
      await sleep(1500);
    } catch (err) {
      console.warn(`[backfill-drivers]   failed ${driver.driverName}: ${err.message}`);
    }
  }

  console.log(`\n[backfill-drivers] done. added=${added}`);
  console.log(`[backfill-drivers] KB now has ${kb.length} docs`);
}

main().catch(err => {
  console.error('[backfill-drivers] FATAL:', err);
  process.exit(1);
});
