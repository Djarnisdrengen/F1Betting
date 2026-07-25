#!/usr/bin/env node
'use strict';
/**
 * Independently cross-checks a bin/simulate-challenges.js run's CP ledger against the raw
 * answers/scores it was built from, instead of just trusting the leaderboard because it came
 * from the real awardChallengePoints()/resolveDuelsForRace() functions. "Real function" isn't
 * the same as "verified" — this recomputes expected CP from first principles per game and
 * compares:
 *   - Rumor or Not: correct_answers * 10 must equal the ledger's rumor_or_not points exactly.
 *   - Trivia:        correct_answers * 5 + 20*k must equal the ledger's trivia points, for some
 *                     non-negative integer k (Perfect Week bonuses share the 'trivia' game and
 *                     are +20 each) — k is reported per participant.
 *   - Duels:          recomputed independently from each duel's raw prediction scores using the
 *                      documented rule (win 15 / loss 5 / tie 10-10), summed per participant,
 *                      and compared against the ledger's duel points.
 *   - total_cp must equal the sum of the three.
 *
 * Usage: node bin/simulate-challenges-verify.js [path/to/data.json]
 *        (defaults to the latest bin/simulate-runs/<timestamp>/data.json)
 */
const path = require('path');
const fs = require('fs');

const REPO = path.join(__dirname, '..');
const { readPhpConfig } = require(path.join(REPO, 'build-deploy/php-config'));
const cfg = readPhpConfig('test');

function latestDataFile() {
    const runsDir = path.join(REPO, 'bin/simulate-runs');
    if (!fs.existsSync(runsDir)) return null;
    const dirs = fs.readdirSync(runsDir).sort().reverse();
    for (const d of dirs) {
        const f = path.join(runsDir, d, 'data.json');
        if (fs.existsSync(f)) return f;
    }
    return null;
}

async function seed(action, params = {}) {
    const url = new URL(`${cfg.siteUrl}/tools/test-seed.php`);
    url.searchParams.set('token', cfg.integrationSeedToken);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
    const body = await (await fetch(url.toString())).json();
    if (!body.ok) throw new Error(`seed.${action} failed: ${JSON.stringify(body)}`);
    return body;
}

async function main() {
    const dataFile = process.argv[2] || latestDataFile();
    if (!dataFile) throw new Error('No data.json path given and none found under bin/simulate-runs/.');
    console.log(`Verifying ${dataFile}`);
    const data = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
    const roster = data.roster || [];
    if (!roster.length) throw new Error('data.json has no roster — nothing to verify (already cleaned up?).');

    const ids = roster.map((p) => p.id);
    const status = await seed('simulation_status', { participant_ids: ids.join(',') });
    if (!status.leaderboard.length) {
        console.log('No participants found on TEST for this run — already cleaned up. Nothing to verify.');
        return;
    }

    // Recompute expected duel CP independently from raw scores, per participant, across every
    // race week the run touched (race ids looked up by name — races aren't cleaned up between
    // runs, so this works even long after the participants/content are gone).
    const raceNames = [...new Set((data.weeks || []).filter((w) => w.race).map((w) => w.race))];
    const racesResp = await seed('list_races');
    const findRace = (name) => racesResp.races.find((r) => r.name === name || r.name === `test: ${name}` || r.name.endsWith(name));
    const expectedDuelCp = {};
    for (const raceName of raceNames) {
        const race = findRace(raceName);
        if (!race) { console.warn(`  (race "${raceName}" not found on TEST — skipping its duels in verification)`); continue; }
        const raceStatus = await seed('simulation_status', { race_id: race.id });
        for (const d of raceStatus.duels) {
            if (d.status !== 'resolved') continue;
            const c = d.c_score, o = d.o_score;
            let cCp, oCp;
            if (c === o) { cCp = 10; oCp = 10; }
            else if (c > o) { cCp = 15; oCp = 5; }
            else { cCp = 5; oCp = 15; }
            expectedDuelCp[d.challenger_name] = (expectedDuelCp[d.challenger_name] || 0) + cCp;
            expectedDuelCp[d.opponent_name] = (expectedDuelCp[d.opponent_name] || 0) + oCp;
        }
    }

    let allOk = true;
    for (const row of status.leaderboard) {
        const byGame = Object.fromEntries(row.by_game.map((g) => [g.game, { pts: +g.pts, awards: +g.awards }]));
        const rumor = byGame.rumor_or_not || { pts: 0, awards: 0 };
        const trivia = byGame.trivia || { pts: 0, awards: 0 };
        const duel = byGame.duel || { pts: 0, awards: 0 };
        const rumorCorrect = +row.rumor_answers_raw.correct || 0;
        const triviaCorrect = +row.trivia_answers_raw.correct || 0;

        const rumorOk = rumorCorrect * 10 === rumor.pts && rumorCorrect === rumor.awards;

        const remainder = trivia.pts - triviaCorrect * 5;
        const k = remainder / 20;
        const triviaOk = Number.isInteger(k) && k >= 0 && trivia.awards === triviaCorrect + k;

        const expectedDuel = expectedDuelCp[row.display_name] || 0;
        const duelOk = raceNames.length === 0 || expectedDuel === duel.pts;

        const totalOk = rumor.pts + trivia.pts + duel.pts === +row.total_cp;

        const ok = rumorOk && triviaOk && duelOk && totalOk;
        allOk = allOk && ok;
        console.log(
            (ok ? 'OK  ' : 'FAIL'), row.display_name.padEnd(16),
            `rumor ${rumorCorrect}->${rumor.pts}CP${rumorOk ? '' : ' [MISMATCH]'}`,
            `| trivia ${triviaCorrect}->${trivia.pts}CP,perfectWeeks=${k}${triviaOk ? '' : ' [MISMATCH]'}`,
            `| duel ${duel.pts}CP (expected ${expectedDuel})${duelOk ? '' : ' [MISMATCH]'}`,
            `| total ${row.total_cp}${totalOk ? '' : ' [MISMATCH]'}`,
        );
    }
    console.log(`\nALL CONSISTENT: ${allOk}`);
    if (!allOk) process.exit(1);
}

main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
