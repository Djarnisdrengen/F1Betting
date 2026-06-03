#!/usr/bin/env node
/**
 * Paddock Rumors — main pipeline orchestrator.
 *
 * Per-round, two-phase model:
 *
 *   Tier 1 (race + driver synthesis)
 *     Runs once per round, as soon as Jolpica has results.
 *
 *   Enrichment (F1Technical analysis summarisation)
 *     Runs repeatedly during the T+36h → T+96h window after race finish,
 *     so new analysis pieces published days later still get captured.
 *
 * Output:
 *   By default writes to ./data/knowledge-base.json — fully isolated from
 *   any Phase 1 setup in f1-intelligence/api/data/. When you're ready to
 *   feed the live API, set KB_OUTPUT_PATH to point at the live KB file
 *   (typically ../f1-intelligence/api/data/f1-knowledge-base.json from
 *   here). See ROADMAP.md for the integration story.
 *
 * State is persisted per-round to state/last_processed_round.json after
 * each successful phase, so partial failures never lose progress.
 *
 * Env:
 *   F1_SEASON          (default 2026)
 *   TOP_N_DRIVERS      (default 10)
 *   F1TECH_ENRICH      "0" disables enrichment (useful for backfill)
 *   KB_OUTPUT_PATH     override the default ./data/knowledge-base.json
 *   ANTHROPIC_API_KEY  required for synthesis
 *
 * Run:
 *   node update-kb.js
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
import { synthesiseRaceDoc, synthesiseDriverDoc, synthesiseQualiDoc } from './synthesise.js';
import { enrichFromF1Technical } from './enrich-f1technical.js';
import { evaluateSchedule, migrateState } from './schedule.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_KB_PATH = path.resolve(__dirname, './data/knowledge-base.json');
const KB_PATH = process.env.KB_OUTPUT_PATH
  ? path.resolve(process.env.KB_OUTPUT_PATH)
  : DEFAULT_KB_PATH;
const STATE_PATH = path.resolve(__dirname, './state/last_processed_round.json');

const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);
const TOP_N = parseInt(process.env.TOP_N_DRIVERS || '10', 10);
const ENRICH = process.env.F1TECH_ENRICH !== '0';
const FORCE_QUALI = process.env.FORCE_QUALI === 'true';

// ── State + KB I/O ──────────────────────────────────────────────────

function readState() {
  if (!fs.existsSync(STATE_PATH)) {
    return { season: CURRENT_SEASON, rounds: {}, schema_version: 2 };
  }
  const raw = JSON.parse(fs.readFileSync(STATE_PATH, 'utf-8'));
  return migrateState(raw);
}

function writeState(state) {
  fs.mkdirSync(path.dirname(STATE_PATH), { recursive: true });
  fs.writeFileSync(STATE_PATH, JSON.stringify(state, null, 2));
}

function loadKb() {
  if (!fs.existsSync(KB_PATH)) {
    fs.mkdirSync(path.dirname(KB_PATH), { recursive: true });
    return [];
  }
  return JSON.parse(fs.readFileSync(KB_PATH, 'utf-8'));
}

function saveKb(kb) {
  fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));
}

function upsert(kb, docs) {
  const byId = new Map(kb.map(d => [d.id, d]));
  for (const d of docs) byId.set(d.id, d);
  return Array.from(byId.values());
}

function markRound(state, round, phase) {
  state.rounds = state.rounds || {};
  state.rounds[String(round)] = state.rounds[String(round)] || {};
  state.rounds[String(round)][`${phase}_at`] = new Date().toISOString();
  if (phase === 'enrichment') {
    state.rounds[String(round)].enrichment_last_at = new Date().toISOString();
  }
}

// ── Standings helper ────────────────────────────────────────────────

async function getStandingsCached(round, cache) {
  if (round === 0) return [];
  if (cache.has(round)) return cache.get(round);
  const s = await getDriverStandings(CURRENT_SEASON, round);
  cache.set(round, s);
  return s;
}

// ── Tier 1: race doc + top-N driver docs ────────────────────────────

async function doTier1(round, raceCache, standingsCache, state) {
  console.log(`[paddock-rumors]   Tier 1: round ${round}`);
  const race = await raceCache.get(round);
  const quali = await getQualifyingResults(CURRENT_SEASON, round);

  const standingsBefore = await getStandingsCached(round - 1, standingsCache);
  const standingsAfter = await getStandingsCached(round, standingsCache);

  const generated = [];

  console.log(`[paddock-rumors]     race doc: ${race.raceName}`);
  generated.push(await synthesiseRaceDoc(race, quali, standingsBefore, standingsAfter));

  const allCompletedSoFar = [];
  for (let r = 1; r <= round; r++) allCompletedSoFar.push(await raceCache.get(r));

  for (const s of standingsAfter.slice(0, TOP_N)) {
    const driver = { driverId: s.driverId, driverName: s.driverName, constructor: s.constructor };
    console.log(`[paddock-rumors]     driver doc: ${driver.driverName}`);
    generated.push(
      await synthesiseDriverDoc(driver, CURRENT_SEASON, allCompletedSoFar, standingsAfter)
    );
  }

  markRound(state, round, 'tier1');
  return generated;
}

// ── Enrichment: F1Technical (non-blocking) ───────────────────────────

async function doEnrichment(round, raceCache, state) {
  if (!ENRICH) {
    console.log(`[paddock-rumors]   enrichment: SKIPPED (F1TECH_ENRICH=0)`);
    return [];
  }
  console.log(`[paddock-rumors]   enrichment: round ${round}`);
  const race = await raceCache.get(round);
  try {
    const docs = await enrichFromF1Technical({
      season: CURRENT_SEASON,
      round,
      raceName: race.raceName,
      circuit: race.circuitName
    });
    console.log(`[paddock-rumors]     added ${docs.length} enrichment doc(s)`);
    markRound(state, round, 'enrichment');
    return docs;
  } catch (err) {
    console.warn(`[paddock-rumors]   enrichment FAILED (non-blocking): ${err.message}`);
    return [];
  }
}

// ── Main ─────────────────────────────────────────────────────────────

async function main() {
  if (!process.env.ANTHROPIC_API_KEY) {
    console.error('[paddock-rumors] FATAL: ANTHROPIC_API_KEY not set');
    process.exit(1);
  }

  console.log(`[paddock-rumors] season=${CURRENT_SEASON} topN=${TOP_N} enrich=${ENRICH}`);
  console.log(`[paddock-rumors] KB output: ${KB_PATH}`);
  if (KB_PATH !== DEFAULT_KB_PATH) {
    console.log(`[paddock-rumors] (KB_OUTPUT_PATH override active)`);
  }

  let state = readState();
  if (state.season !== CURRENT_SEASON) {
    console.log(`[paddock-rumors] season rollover ${state.season} -> ${CURRENT_SEASON}`);
    state = { season: CURRENT_SEASON, rounds: {}, schema_version: 2 };
    writeState(state);
  }

  const schedule = await getSeasonSchedule(CURRENT_SEASON);
  const latest = await getLatestFinishedRound(CURRENT_SEASON);

  const plan = evaluateSchedule({
    state,
    schedule,
    latestFinishedRound: latest,
    currentSeason: CURRENT_SEASON
  });

  console.log('[paddock-rumors] schedule plan:');
  for (const r of plan.rounds) {
    console.log(
      `[paddock-rumors]   R${r.round} ${r.raceName.padEnd(30)} ` +
      `age=${String(r.ageHours).padStart(7)}h  ` +
      `tier1=${r.tier1Status.padEnd(6)}  ` +
      `enrich=${r.enrichmentStatus}`
    );
  }
  console.log(`[paddock-rumors] summary: ${plan.summary}`);

  // ── Qualifying check: next upcoming round ───────────────────────────
  // Runs independently of the race/enrichment plan. Checks whether the
  // round after the latest finished race has qualifying data available
  // yet (Jolpica publishes it within ~1h of the session ending).
  const nextRound = latest !== null ? latest + 1 : 1;
  const nextSched = schedule.find(s => s.round === nextRound && s.season === CURRENT_SEASON);
  if (nextSched) {
    const qRoundState = (state.rounds?.[String(nextRound)] || {});
    if (!qRoundState.qualifying_at || FORCE_QUALI) {
      console.log(`[paddock-rumors] qualifying check: R${nextRound} ${nextSched.raceName}`);
      try {
        const qualiResult = await getQualifyingResults(CURRENT_SEASON, nextRound);
        if (qualiResult?.qualifying?.length) {
          let kb = loadKb();
          console.log(`[paddock-rumors] qualifying data found — synthesising doc`);
          const qualiDoc = await synthesiseQualiDoc(qualiResult);
          kb = upsert(kb, [qualiDoc]);
          saveKb(kb);
          markRound(state, nextRound, 'qualifying');
          writeState(state);
          console.log(`[paddock-rumors] qualifying doc committed: ${qualiDoc.id}`);
        } else {
          console.log(`[paddock-rumors] no qualifying data yet for R${nextRound}`);
        }
      } catch (err) {
        console.warn(`[paddock-rumors] qualifying check failed (non-blocking): ${err.message}`);
      }
    } else {
      console.log(`[paddock-rumors] R${nextRound} qualifying already processed`);
    }
  }

  if (!plan.anyWork) {
    console.log('[paddock-rumors] exit — no work');
    return;
  }

  let kb = loadKb();
  const raceResultsCache = new Map();
  const raceCache = {
    get: async r => {
      if (!raceResultsCache.has(r)) {
        raceResultsCache.set(r, await getRaceResults(CURRENT_SEASON, r));
      }
      return raceResultsCache.get(r);
    }
  };
  const standingsCache = new Map();

  for (const r of plan.work) {
    console.log(`\n[paddock-rumors] === round ${r.round}: ${r.raceName} ===`);
    try {
      const docs = [];

      if (r.tier1Status === 'needed') {
        docs.push(...(await doTier1(r.round, raceCache, standingsCache, state)));
        kb = upsert(kb, docs);
        saveKb(kb);
        writeState(state);
      }

      if (r.enrichmentStatus === 'needed') {
        const enrichDocs = await doEnrichment(r.round, raceCache, state);
        if (enrichDocs.length > 0) {
          kb = upsert(kb, enrichDocs);
          saveKb(kb);
        }
        writeState(state);
      }

      console.log(`[paddock-rumors] round ${r.round}: committed`);
    } catch (err) {
      console.error(`[paddock-rumors] round ${r.round} FAILED: ${err.message}`);
      console.error('[paddock-rumors] stopping; KB and state reflect rounds completed so far.');
      process.exit(2);
    }
  }

  console.log(`\n[paddock-rumors] done. KB now has ${kb.length} docs at ${KB_PATH}`);
  console.log('[paddock-rumors] next steps depend on your integration mode — see ROADMAP.md');
}

main().catch(err => {
  console.error('[paddock-rumors] FATAL:', err);
  process.exit(1);
});
