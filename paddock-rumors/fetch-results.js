/**
 * Jolpica-F1 API client (Ergast successor).
 *
 * Free, no API key. Endpoints follow Ergast's URL conventions.
 * Docs: https://github.com/jolpica/jolpica-f1
 *
 * Exposes structured fetchers used by update-kb.js. Each function returns
 * plain JS objects (not raw Ergast envelopes) so synthesise.js can hand
 * them straight to Claude as JSON.
 */

import fetch from 'node-fetch';

const JOLPICA_BASE = 'https://api.jolpi.ca/ergast/f1';
const UA = 'paddock-picks-kb-updater/1.0 (https://formula-1.dk)';

async function jolpica(pathSegment, attempt = 1) {
  const url = `${JOLPICA_BASE}/${pathSegment}`;
  const res = await fetch(url, { headers: { 'User-Agent': UA } });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    // Retry transient server errors (502/503/504) up to 3 times with backoff.
    if (attempt < 3 && res.status >= 500) {
      const delay = attempt * 4000;
      console.warn(`[fetch-results] Jolpica HTTP ${res.status} — retry ${attempt}/2 in ${delay / 1000}s`);
      await new Promise(r => setTimeout(r, delay));
      return jolpica(pathSegment, attempt + 1);
    }
    throw new Error(`Jolpica ${pathSegment}: HTTP ${res.status} ${text.slice(0, 200)}`);
  }
  const data = await res.json();
  if (!data?.MRData) throw new Error(`Jolpica ${pathSegment}: unexpected response shape`);
  return data.MRData;
}

/**
 * Season schedule (all rounds, in calendar order).
 */
export async function getSeasonSchedule(season) {
  const mr = await jolpica(`${season}.json`);
  return (mr.RaceTable?.Races || []).map(r => ({
    season: parseInt(r.season, 10),
    round: parseInt(r.round, 10),
    raceName: r.raceName,
    circuitId: r.Circuit.circuitId,
    circuitName: r.Circuit.circuitName,
    locality: r.Circuit.Location.locality,
    country: r.Circuit.Location.country,
    date: r.date,
    time: r.time || null,
    url: r.url
  }));
}

/**
 * Returns the round number of the most recent race in the season that
 * has finished AND has results published, or null if no rounds done yet.
 */
export async function getLatestFinishedRound(season) {
  try {
    const mr = await jolpica(`${season}/last/results.json`);
    const race = mr.RaceTable?.Races?.[0];
    if (!race) return null;
    return parseInt(race.round, 10);
  } catch (err) {
    // No results yet for this season → endpoint returns 404 / empty.
    if (/HTTP 404/.test(err.message)) return null;
    throw err;
  }
}

/**
 * Race results for one specific round.
 */
export async function getRaceResults(season, round) {
  const mr = await jolpica(`${season}/${round}/results.json`);
  const race = mr.RaceTable?.Races?.[0];
  if (!race) throw new Error(`No race found for ${season} R${round}`);

  return {
    season: parseInt(race.season, 10),
    round: parseInt(race.round, 10),
    raceName: race.raceName,
    circuitId: race.Circuit.circuitId,
    circuitName: race.Circuit.circuitName,
    locality: race.Circuit.Location.locality,
    country: race.Circuit.Location.country,
    date: race.date,
    url: race.url,
    results: (race.Results || []).map(r => ({
      position: parseInt(r.position, 10),
      points: parseFloat(r.points),
      driverId: r.Driver.driverId,
      driverCode: r.Driver.code || null,
      driverName: `${r.Driver.givenName} ${r.Driver.familyName}`,
      constructorId: r.Constructor.constructorId,
      constructor: r.Constructor.name,
      grid: parseInt(r.grid, 10),
      laps: parseInt(r.laps, 10),
      status: r.status,
      time: r.Time?.time || null,
      fastestLap: r.FastestLap
        ? {
            rank: parseInt(r.FastestLap.rank, 10),
            lap: parseInt(r.FastestLap.lap, 10),
            time: r.FastestLap.Time?.time || null,
            avgSpeedKph: r.FastestLap.AverageSpeed?.speed
              ? parseFloat(r.FastestLap.AverageSpeed.speed)
              : null
          }
        : null
    }))
  };
}

/**
 * Qualifying results for one specific round (may be null if not yet available).
 */
export async function getQualifyingResults(season, round) {
  try {
    const mr = await jolpica(`${season}/${round}/qualifying.json`);
    const race = mr.RaceTable?.Races?.[0];
    if (!race) return null;
    return {
      season: parseInt(race.season, 10),
      round: parseInt(race.round, 10),
      raceName: race.raceName,
      qualifying: (race.QualifyingResults || []).map(q => ({
        position: parseInt(q.position, 10),
        driverId: q.Driver.driverId,
        driverName: `${q.Driver.givenName} ${q.Driver.familyName}`,
        constructor: q.Constructor.name,
        q1: q.Q1 || null,
        q2: q.Q2 || null,
        q3: q.Q3 || null
      }))
    };
  } catch (err) {
    if (/HTTP 404/.test(err.message)) return null;
    throw err;
  }
}

/**
 * Driver standings, optionally pinned to "after round N" of the season.
 */
export async function getDriverStandings(season, round = null) {
  const path = round
    ? `${season}/${round}/driverstandings.json`
    : `${season}/driverstandings.json`;
  const mr = await jolpica(path);
  const list = mr.StandingsTable?.StandingsLists?.[0];
  if (!list) return [];
  return (list.DriverStandings || []).map(s => ({
    position: parseInt(s.position, 10),
    points: parseFloat(s.points),
    wins: parseInt(s.wins, 10),
    driverId: s.Driver.driverId,
    driverName: `${s.Driver.givenName} ${s.Driver.familyName}`,
    constructor: s.Constructors?.[0]?.name || null,
    constructorId: s.Constructors?.[0]?.constructorId || null
  }));
}

/**
 * Constructor standings, optionally pinned to "after round N".
 */
export async function getConstructorStandings(season, round = null) {
  const path = round
    ? `${season}/${round}/constructorstandings.json`
    : `${season}/constructorstandings.json`;
  const mr = await jolpica(path);
  const list = mr.StandingsTable?.StandingsLists?.[0];
  if (!list) return [];
  return (list.ConstructorStandings || []).map(s => ({
    position: parseInt(s.position, 10),
    points: parseFloat(s.points),
    wins: parseInt(s.wins, 10),
    constructorId: s.Constructor.constructorId,
    constructor: s.Constructor.name
  }));
}
