/**
 * Synthesise KB documents from structured Jolpica data via Claude.
 *
 * Two generators:
 *   - synthesiseRaceDoc:    one doc per race weekend (result + analysis)
 *   - synthesiseDriverDoc:  one doc per driver, refreshed each round
 *
 * All produced docs carry season/type tags so the retrieval layer can
 * weight the current season highest (see api/api/intelligence.js update).
 *
 * Important: Claude is fed STRUCTURED DATA only, never article prose.
 * The prompts force neutral, factual output and a hard word cap so the
 * embedded chunks stay focused.
 */

import fetch from 'node-fetch';
import { createHash } from 'crypto';

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-4-6';

async function claude(prompt, maxTokens = 800) {
  if (!ANTHROPIC_API_KEY) throw new Error('ANTHROPIC_API_KEY not set');
  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01'
    },
    body: JSON.stringify({
      model: CLAUDE_MODEL,
      max_tokens: maxTokens,
      messages: [{ role: 'user', content: prompt }]
    })
  });
  const data = await res.json();
  if (!res.ok) throw new Error(`Claude API: ${JSON.stringify(data).slice(0, 400)}`);
  return data.content[0].text;
}

function contentHash(content) {
  return createHash('sha256').update(content).digest('hex').slice(0, 16);
}

function slugify(s) {
  return String(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
}

function isDnf(status) {
  if (!status) return false;
  // Finished / +N Lap(s) are classified finishers.
  return !/^(Finished|\+\d+\s+Laps?)$/.test(status);
}

// ─────────────────────────────────────────────────────────────────────
// Race document
// ─────────────────────────────────────────────────────────────────────

/**
 * @param {object} raceResults     - from getRaceResults()
 * @param {object|null} qualifying - from getQualifyingResults()
 * @param {object[]} standingsBefore - driver standings BEFORE this round
 * @param {object[]} standingsAfter  - driver standings AFTER this round
 * @returns {Promise<object>} a KB document ready to be upserted
 */
export async function synthesiseRaceDoc(raceResults, qualifying, standingsBefore, standingsAfter) {
  const { season, round, raceName, circuitName, date, results } = raceResults;

  const podium = results.slice(0, 3).map(r => ({
    pos: r.position,
    driver: r.driverName,
    team: r.constructor,
    grid: r.grid,
    time: r.time,
    status: r.status
  }));
  const top10 = results
    .filter(r => r.position <= 10)
    .map(r => ({
      pos: r.position,
      driver: r.driverName,
      team: r.constructor,
      grid: r.grid,
      gridDelta: r.grid - r.position,
      pts: r.points,
      status: r.status
    }));
  const dnfs = results
    .filter(r => isDnf(r.status))
    .map(r => ({ driver: r.driverName, team: r.constructor, lap: r.laps, status: r.status }));

  const fastest = results.find(r => r.fastestLap?.rank === 1);
  const pole = qualifying?.qualifying?.[0] || null;

  // Standings deltas for top 10 drivers (championship impact)
  const beforeById = new Map(standingsBefore.map(s => [s.driverId, s]));
  const deltas = standingsAfter.slice(0, 10).map(after => {
    const before = beforeById.get(after.driverId);
    return {
      driver: after.driverName,
      pos: after.position,
      posChange: before ? before.position - after.position : null,
      pts: after.points,
      ptsGained: before ? +(after.points - before.points).toFixed(1) : after.points
    };
  });

  const facts = {
    race: { season, round, name: raceName, circuit: circuitName, date },
    podium,
    top10,
    pole: pole
      ? { driver: pole.driverName, team: pole.constructor, time: pole.q3 || pole.q2 || pole.q1 }
      : null,
    fastestLap: fastest
      ? {
          driver: fastest.driverName,
          team: fastest.constructor,
          lap: fastest.fastestLap.lap,
          time: fastest.fastestLap.time
        }
      : null,
    dnfs,
    standingsAfter_top10: deltas
  };

  const prompt = `You are generating ONE Knowledge Base entry for an F1 prediction app.

Below is the structured result data for one Grand Prix. Write a neutral, factual KB document body of 180–220 words covering, in this order:
1. Podium and result (winner, key gaps, grid-vs-finish notable cases)
2. Pole and fastest lap (who, brief context if it changed strategy)
3. Notable DNFs/penalties implied by the data
4. Championship implications using the standings delta (who gained, who lost ground)

Tone: analytical, factual. No opinion-as-fact, no quotes, no speculation. Use full driver names and team names. Do not add a heading. Output ONLY the prose body — no markdown, no preamble.

STRUCTURED DATA:
${JSON.stringify(facts, null, 2)}`;

  const body = (await claude(prompt, 800)).trim();

  const id = `race-${season}-r${String(round).padStart(2, '0')}-${slugify(
    circuitName.replace(/Circuit|International|Park/gi, '').trim()
  )}`;

  // Lead with the season string so the embedding vector encodes the season strongly.
  const content = `Season ${season}, Round ${round}: ${raceName}. ${body}`;

  return {
    id,
    title: `${raceName} ${season} — Race Result & Analysis`,
    content,
    tags: {
      season,
      type: 'race',
      round,
      circuit: slugify(circuitName),
      drivers_top10: top10.map(r => slugify(r.driver))
    },
    source_url: raceResults.url || null,
    updated_at: new Date().toISOString(),
    content_hash: contentHash(content)
  };
}

// ─────────────────────────────────────────────────────────────────────
// Driver document (per-driver season form, refreshed each round)
// ─────────────────────────────────────────────────────────────────────

/**
 * @param {object} driver - { driverId, driverName, constructor }
 * @param {number} season
 * @param {object[]} allSeasonRaceResults - getRaceResults() for every completed round
 * @param {object[]} currentStandings     - getDriverStandings() after the latest round
 */
export async function synthesiseDriverDoc(driver, season, allSeasonRaceResults, currentStandings) {
  const myStanding = currentStandings.find(s => s.driverId === driver.driverId);

  const raceByRace = allSeasonRaceResults
    .map(r => {
      const me = r.results.find(res => res.driverId === driver.driverId);
      if (!me) return null;
      return {
        round: r.round,
        race: r.raceName,
        grid: me.grid,
        finish: me.position,
        pts: me.points,
        gridDelta: me.grid - me.position,
        status: me.status,
        team: me.constructor
      };
    })
    .filter(Boolean);

  // Light precomputed aggregates Claude can use without doing arithmetic.
  const finished = raceByRace.filter(r => !isDnf(r.status));
  const podiums = raceByRace.filter(r => r.finish <= 3).length;
  const wins = raceByRace.filter(r => r.finish === 1).length;
  const dnfCount = raceByRace.length - finished.length;
  const avgFinish = finished.length
    ? +(finished.reduce((a, b) => a + b.finish, 0) / finished.length).toFixed(2)
    : null;
  const avgGridDelta = finished.length
    ? +(finished.reduce((a, b) => a + b.gridDelta, 0) / finished.length).toFixed(2)
    : null;

  const facts = {
    driver: driver.driverName,
    driverId: driver.driverId,
    team: driver.constructor,
    season,
    standing: myStanding
      ? { position: myStanding.position, points: myStanding.points, wins: myStanding.wins }
      : null,
    aggregates: { wins, podiums, dnfCount, avgFinish, avgGridDelta, racesEntered: raceByRace.length },
    raceByRace
  };

  const prompt = `You are updating a Knowledge Base entry on ${driver.driverName}'s ${season} season form.

Below is structured data. Write a 120–160 word KB document body covering, in this order:
1. Championship position and points so far
2. Wins / podiums and any standout result
3. Grid-vs-finish trend (gainer or loser; cite avg if useful)
4. Reliability (DNFs and any pattern)
5. Team context briefly

Tone: analytical, factual. No quotes, no opinion-as-fact, no future predictions. Use full names. No heading. Output ONLY the prose body.

STRUCTURED DATA:
${JSON.stringify(facts, null, 2)}`;

  const body = (await claude(prompt, 600)).trim();

  const content = `Season ${season} driver form — ${driver.driverName} (${driver.constructor}). ${body}`;

  return {
    id: `driver-${driver.driverId}-${season}`,
    title: `${driver.driverName} — ${season} Season Form`,
    content,
    tags: {
      season,
      type: 'driver',
      driver: driver.driverId,
      team: slugify(driver.constructor)
    },
    source_url: null,
    updated_at: new Date().toISOString(),
    content_hash: contentHash(content)
  };
}
