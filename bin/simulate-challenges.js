#!/usr/bin/env node
'use strict';
/**
 * Paddock Challenges simulation harness — plays out N weeks of Rumor or Not, Weekly Trivia and
 * Duels for a synthetic roster, end to end: real Claude-drafted content (bin/generate-*.js,
 * backdated via --publish-date), real answer/CP scoring (test-seed.php's seed_rumor_answer /
 * seed_trivia_answer, which call the real awardChallengePoints()), real Duel resolution (real
 * admin.php update_race, so resolveDuelsForRace() runs for real), and the real weekly cron
 * (cron/challenge_weekly.php's ?score_week= override) for Perfect Week bonuses. Runs against
 * TEST only — never touches LIVE.
 *
 * Currently configured for Q1 2026 (Rounds 1-6, Mon 2026-03-02 -> Mon 2026-04-27) with a
 * 9-person roster (7 regular + 2 core members) — see WEEKS and ROSTER below. To reuse for a
 * different quarter/season: update WEEKS (Monday dates + race name/location/date/time per the
 * target season file, e.g. database/seasons/data_YYYY.sql) and ROSTER as needed; everything
 * else (content gen, play simulation, duels, cron scoring, reporting) is unchanged.
 *
 * Needs locally: config.test.php (deploy target), build-deploy/.env with ANTHROPIC_API_KEY
 * (real content generation costs real API credits and draws down the shared paddock-rumors KB
 * for the test environment — see docs/paddock-challenges-reference.md).
 *
 * Before a full run, `node bin/simulate-challenges-preflight.js` is worth running first — it
 * checks admin login/CSRF and does one real (tiny) content-generation call in under a minute,
 * far cheaper than discovering a config problem after the roster is already seeded.
 *
 * Usage: node bin/simulate-challenges.js
 * Output: bin/simulate-runs/<timestamp>/{log.jsonl,report.md,data.json} (gitignored — this
 * script is checked in, its run output is not).
 */
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const { URL } = require('url');
const { execFileSync } = require('child_process');

const REPO = path.join(__dirname, '..');
const { readPhpConfig } = require(path.join(REPO, 'build-deploy/php-config'));
const cfg = readPhpConfig('test');

const OUT_DIR = path.join(REPO, 'bin/simulate-runs', new Date().toISOString().replace(/[:.]/g, '-'));
fs.mkdirSync(OUT_DIR, { recursive: true });
const LOG_FILE = path.join(OUT_DIR, 'log.jsonl');
const REPORT_FILE = path.join(OUT_DIR, 'report.md');
const DATA_FILE = path.join(OUT_DIR, 'data.json');

fs.writeFileSync(LOG_FILE, '');
fs.writeFileSync(REPORT_FILE, '# Paddock Challenges simulation\n\n');

function log(event, data) {
    const line = { t: new Date().toISOString(), event, ...data };
    fs.appendFileSync(LOG_FILE, JSON.stringify(line) + '\n');
    console.log(`[${event}]`, JSON.stringify(data).slice(0, 300));
}
function report(md) {
    fs.appendFileSync(REPORT_FILE, md + '\n');
}

// ─── cookie-aware HTTP helpers (mirrors tests/smoke.js) ──────────────────────
function httpGet(url, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const req = mod.request({
            hostname: parsed.hostname,
            port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
            path: parsed.pathname + parsed.search,
            method: 'GET',
            headers: { 'User-Agent': 'F1Betting-Sim/1.0', ...(cookieStr ? { Cookie: cookieStr } : {}) },
        }, (res) => {
            let body = '';
            res.on('data', (c) => (body += c));
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject);
        req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); });
        req.end();
    });
}
function httpPost(url, fields, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const body = Object.entries(fields).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
        const req = mod.request({
            hostname: parsed.hostname,
            port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
            path: parsed.pathname,
            method: 'POST',
            headers: {
                'User-Agent': 'F1Betting-Sim/1.0',
                'Content-Type': 'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(body),
                ...(cookieStr ? { Cookie: cookieStr } : {}),
            },
        }, (res) => {
            let data = '';
            res.on('data', (c) => (data += c));
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
        });
        req.on('error', reject);
        req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); });
        req.write(body);
        req.end();
    });
}
function pickCookies(h) {
    const arr = Array.isArray(h) ? h : (h ? [h] : []);
    return arr.map((c) => c.split(';')[0]).join('; ');
}
function extractCsrf(html) {
    return (
        html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i) ||
        html.match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i) ||
        []
    )[1] || '';
}

async function loginAdmin() {
    const page = await httpGet(`${cfg.siteUrl}/login.php`);
    const cookies = pickCookies(page.headers['set-cookie']);
    const csrf = extractCsrf(page.body);
    const res = await httpPost(`${cfg.siteUrl}/login.php`, { email: cfg.adminEmail, password: cfg.adminPassword, csrf_token: csrf }, cookies);
    const newCookies = pickCookies(res.headers['set-cookie']);
    if (!newCookies) throw new Error('admin login failed — no session cookie returned');
    const adminPage = await httpGet(`${cfg.siteUrl}/admin.php?tab=races`, newCookies);
    const adminCsrf = extractCsrf(adminPage.body);
    if (!adminCsrf) throw new Error('could not extract admin CSRF token');
    return { cookie: newCookies, csrf: adminCsrf };
}

// ─── test-seed.php helper ──────────────────────────────────────────────────
async function seed(action, params = {}) {
    const url = new URL(`${cfg.siteUrl}/tools/test-seed.php`);
    url.searchParams.set('token', cfg.integrationSeedToken);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
    const res = await fetch(url.toString());
    let body;
    try { body = await res.json(); } catch (e) { throw new Error(`seed.${action}: non-JSON response (HTTP ${res.status})`); }
    if (!body.ok) throw new Error(`seed.${action} failed: ${JSON.stringify(body)}`);
    return body;
}

async function scoreCronWeek(monday) {
    const res = await fetch(`${cfg.siteUrl}/cron/challenge_weekly.php?score_week=${monday}`, {
        headers: { Authorization: `Bearer ${cfg.cronSecret}` },
    });
    return res.text();
}

function generateContent(kind, monday, count = 6) {
    const script = kind === 'rumor' ? 'bin/generate-rumor-items.js' : 'bin/generate-trivia-questions.js';
    let out;
    try {
        out = execFileSync('node', [script, '--env=test', `--count=${count}`, '--publish', `--publish-date=${monday}`], {
            cwd: REPO, encoding: 'utf8', timeout: 300000,
        });
    } catch (e) {
        return { ids: [], log: (e.stdout || '') + (e.stderr || ''), error: e.message };
    }
    const idsLine = out.split('\n').find((l) => l.startsWith('IDS:'));
    const ids = idsLine ? idsLine.slice(4).split(',').filter(Boolean) : [];
    return { ids, log: out };
}

// ─── RNG helpers ────────────────────────────────────────────────────────────
function chance(p) { return Math.random() < p; }
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
function shuffled(arr) { return [...arr].sort(() => Math.random() - 0.5); }
function sample(arr, n) { return shuffled(arr).slice(0, n); }

// ─── Season data ────────────────────────────────────────────────────────────
const WEEKS = [
    { monday: '2026-03-02', race: { name: 'Australian Grand Prix', location: 'Melbourne', date: '2026-03-08', time: '05:00', existing: true } },
    { monday: '2026-03-09', race: { name: 'Chinese Grand Prix', location: 'Shanghai', date: '2026-03-15', time: '08:00' } },
    { monday: '2026-03-16', race: null },
    { monday: '2026-03-23', race: { name: 'Japanese Grand Prix', location: 'Suzuka', date: '2026-03-29', time: '07:00' } },
    { monday: '2026-03-30', race: null },
    { monday: '2026-04-06', race: { name: 'Bahrain Grand Prix', location: 'Sakhir', date: '2026-04-12', time: '17:00' } },
    { monday: '2026-04-13', race: { name: 'Saudi Arabian Grand Prix', location: 'Jeddah', date: '2026-04-19', time: '19:00' } },
    { monday: '2026-04-20', race: null },
    { monday: '2026-04-27', race: { name: 'Miami Grand Prix', location: 'Miami', date: '2026-05-03', time: '22:00' } },
];

// 9 participants: 7 regular (seed_challenge_participant) + 2 core members (seed_converted_guest).
// Archetype knobs: playProb (engages with rumor/trivia this week at all), catchup (fraction of
// backlog cleared when engaged), correct (per-answer accuracy), duel (likelihood of joining a
// duel when picked for one), churnAfterWeek (index after which playProb collapses, 0 = never).
const ROSTER = [
    { name: 'Freja Holm', email: 'sim-q1-freja@test.localhost', lang: 'da', core: false, archetype: 'engaged', playProb: 0.95, catchup: 0.9, correct: 0.8, duel: 0.8, churnAfterWeek: 0 },
    { name: 'Mikkel Sand', email: 'sim-q1-mikkel@test.localhost', lang: 'da', core: false, archetype: 'casual', playProb: 0.6, catchup: 0.5, correct: 0.55, duel: 0.4, churnAfterWeek: 0 },
    { name: 'Sofie Winther', email: 'sim-q1-sofie@test.localhost', lang: 'da', core: false, archetype: 'streak-chaser', playProb: 0.98, catchup: 1.0, correct: 0.6, duel: 0.6, churnAfterWeek: 0 },
    { name: 'Oliver Reed', email: 'sim-q1-oliver@test.localhost', lang: 'en', core: false, archetype: 'sporadic', playProb: 0.35, catchup: 0.3, correct: 0.5, duel: 0.3, churnAfterWeek: 0 },
    { name: 'Ida Bruun', email: 'sim-q1-ida@test.localhost', lang: 'da', core: false, archetype: 'drops-off', playProb: 0.9, catchup: 0.8, correct: 0.65, duel: 0.5, churnAfterWeek: 5 },
    { name: 'William Cross', email: 'sim-q1-william@test.localhost', lang: 'en', core: false, archetype: 'casual', playProb: 0.55, catchup: 0.4, correct: 0.5, duel: 0.4, churnAfterWeek: 0 },
    { name: 'Clara Dahl', email: 'sim-q1-clara@test.localhost', lang: 'da', core: false, archetype: 'engaged', playProb: 0.9, catchup: 0.8, correct: 0.75, duel: 0.7, churnAfterWeek: 0 },
    { name: 'Anders Kold', email: 'sim-q1-anders@test.localhost', lang: 'da', core: true, archetype: 'core-engaged', playProb: 0.85, catchup: 0.7, correct: 0.65, duel: 0.7, churnAfterWeek: 0 },
    { name: 'Emma Voss', email: 'sim-q1-emma@test.localhost', lang: 'en', core: true, archetype: 'core-duelist', playProb: 0.5, catchup: 0.4, correct: 0.5, duel: 0.9, churnAfterWeek: 0 },
];

async function main() {
    console.log('== Paddock Challenges Q1 2026 simulation ==');
    console.log('Target:', cfg.siteUrl);

    // ── Admin session ──
    const admin = await loginAdmin();
    log('admin_login', { ok: true });

    // ── Recon: races + drivers ──
    const racesResp = await seed('list_races');
    const driversResp = await seed('list_drivers');
    const drivers = driversResp.drivers;
    log('recon', { raceCount: racesResp.races.length, driverCount: drivers.length });

    // sync:live prefixes copied race names with "test: " — match by suffix so this works whether
    // a race was synced from live or created natively on TEST.
    const findRace = (races, name) => races.find((r) => r.name === name || r.name === `test: ${name}` || r.name.endsWith(name));

    for (const wk of WEEKS) {
        if (!wk.race) continue;
        const existing = findRace(racesResp.races, wk.race.name);
        if (existing) {
            wk.race.id = existing.id;
            wk.race.row = existing;
            wk.race.hadResult = !!(existing.result_p1 && existing.result_p2 && existing.result_p3);
            wk.race.result = wk.race.hadResult ? [existing.result_p1, existing.result_p2, existing.result_p3] : null;
            continue;
        }
        // Create the missing race
        const postRes = await httpPost(`${cfg.siteUrl}/admin.php`, {
            add_race: '1', csrf_token: admin.csrf,
            race_name: wk.race.name, race_location: wk.race.location,
            race_date: wk.race.date, race_time: wk.race.time,
        }, admin.cookie);
        if (postRes.status !== 200) throw new Error(`add_race failed for ${wk.race.name}: HTTP ${postRes.status}`);
        log('race_created', { name: wk.race.name, date: wk.race.date });
    }
    // Re-fetch to pick up newly created ids
    const racesResp2 = await seed('list_races');
    for (const wk of WEEKS) {
        if (!wk.race) continue;
        const row = findRace(racesResp2.races, wk.race.name);
        if (!row) throw new Error(`race not found after creation attempt: ${wk.race.name}`);
        wk.race.id = row.id;
        wk.race.row = row;
        wk.race.hadResult = !!(row.result_p1 && row.result_p2 && row.result_p3);
        wk.race.result = wk.race.hadResult ? [row.result_p1, row.result_p2, row.result_p3] : null;
    }
    report('## Races\n\n' + WEEKS.filter((w) => w.race).map((w) => {
        const mismatch = w.race.row.race_date !== w.race.date ? ` (⚠️ actual stored date is ${w.race.row.race_date}, not ${w.race.date} — pre-existing data issue, untouched)` : '';
        return `- **${w.race.row.name}** — id \`${w.race.id}\`${mismatch}${w.race.hadResult ? ' — already had a real result (resubmitted as-is, idempotent)' : ' — no result yet, one will be submitted'}`;
    }).join('\n') + '\n');

    // ── Roster ──
    const roster = [];
    for (const person of ROSTER) {
        let participantId;
        if (person.core) {
            const r = await seed('seed_converted_guest', { email: person.email, display_name: person.name, in_competition: 1 });
            participantId = r.participant_id;
        } else {
            const r = await seed('seed_challenge_participant', { email: person.email, display_name: person.name, language: person.lang, status: 'verified' });
            participantId = r.participant_id;
        }
        roster.push({ ...person, id: participantId, answeredRumorIds: new Set() });
        log('participant_created', { name: person.name, id: participantId, core: person.core });
    }
    report('## Roster\n\n' + roster.map((p) => `- **${p.name}** (${p.archetype}${p.core ? ', core member' : ''}) — \`${p.id}\``).join('\n') + '\n');

    const participantIdsCsv = roster.map((p) => p.id).join(',');

    // ── Weekly loop ──
    const allRumorItemIds = [];
    const weeklySummaries = [];

    for (let wi = 0; wi < WEEKS.length; wi++) {
        const wk = WEEKS[wi];
        console.log(`\n=== Week ${wi + 1}/9 — ${wk.monday}${wk.race ? ` — ${wk.race.name}` : ''} ===`);
        const summary = { week: wi + 1, monday: wk.monday, race: wk.race ? wk.race.name : null, notes: [] };

        // 1. Generate + publish content for this week
        const rumorGen = generateContent('rumor', wk.monday, 6);
        const triviaGen = generateContent('trivia', wk.monday, 6);
        if (rumorGen.error) summary.notes.push(`Rumor generation error: ${rumorGen.error}`);
        if (triviaGen.error) summary.notes.push(`Trivia generation error: ${triviaGen.error}`);
        allRumorItemIds.push(...rumorGen.ids);
        summary.rumorPublished = rumorGen.ids.length;
        summary.triviaPublished = triviaGen.ids.length;
        log('content_generated', { week: wi + 1, rumor: rumorGen.ids.length, trivia: triviaGen.ids.length });

        // 2. Participants play
        let rumorAnswers = 0, triviaAnswers = 0, enginesEngaged = 0;
        for (const p of roster) {
            const churned = p.churnAfterWeek > 0 && wi + 1 > p.churnAfterWeek;
            const effectivePlayProb = churned ? 0.05 : p.playProb;
            if (!chance(effectivePlayProb)) continue;
            enginesEngaged++;

            // Rumor backlog (rolls forward — includes older unanswered items too)
            const backlog = allRumorItemIds.filter((id) => !p.answeredRumorIds.has(id));
            const toAnswer = sample(backlog, Math.round(backlog.length * p.catchup));
            for (const itemId of toAnswer) {
                const correct = chance(p.correct) ? 1 : 0;
                try {
                    await seed('seed_rumor_answer', { participant_id: p.id, item_id: itemId, correct });
                    p.answeredRumorIds.add(itemId);
                    rumorAnswers++;
                } catch (e) { log('rumor_answer_error', { participant: p.name, itemId, error: e.message }); }
            }

            // This week's trivia
            for (const qId of triviaGen.ids) {
                if (!chance(p.catchup + 0.1)) continue; // slightly more likely to attempt fresh trivia than old rumor backlog
                const correct = chance(p.correct) ? 1 : 0;
                try {
                    await seed('seed_trivia_answer', { participant_id: p.id, question_id: qId, correct });
                    triviaAnswers++;
                } catch (e) { log('trivia_answer_error', { participant: p.name, qId, error: e.message }); }
            }
        }
        summary.engaged = enginesEngaged;
        summary.rumorAnswers = rumorAnswers;
        summary.triviaAnswers = triviaAnswers;

        // 3. Race week: duels + result
        let duelSummaries = [];
        if (wk.race) {
            const raceId = wk.race.id;
            const pairPool = shuffled(roster);
            const pairs = [];
            for (let i = 0; i + 1 < pairPool.length && pairs.length < 3; i += 2) pairs.push([pairPool[i], pairPool[i + 1]]);

            const createdDuels = [];
            for (const [a, b] of pairs) {
                if (!chance(a.duel) && !chance(b.duel)) continue; // both would skip → no duel
                const pickA = sample(drivers, 3).map((d) => d.id).join(',');
                const pickB = sample(drivers, 3).map((d) => d.id).join(',');
                try {
                    const d = await seed('seed_duel', { race_id: raceId, challenger_id: a.id, opponent_id: b.id, status: 'active', challenger_pick: pickA, opponent_pick: pickB });
                    createdDuels.push(d.duel_id);
                    log('duel_created', { week: wi + 1, race: wk.race.name, a: a.name, b: b.name });
                } catch (e) { log('duel_create_error', { error: e.message }); }
            }

            // Result: use the real one if it already existed, otherwise fabricate a plausible top-3.
            // Always resubmit every real field from the existing row untouched (update_race's
            // UPDATE is unconditional on every posted field, not just the result) — only
            // result_p1-3 ever changes here. race_time/quali_time trimmed to H:i since the form
            // parses with that exact format and the DB may return H:i:s.
            const row = wk.race.row;
            const hhmm = (t) => (t ? String(t).slice(0, 5) : '');
            const wasAlreadyResulted = !!wk.race.result;
            const result = wk.race.result || sample(drivers, 3).map((d) => d.id);
            if (wasAlreadyResulted) {
                summary.notes.push(`${wk.race.name} already had a real result on TEST — resubmitting the same result (no-op for real scores, delta-based) so the new duels resolve against it.`);
            } else {
                log('race_result_submitted', { race: wk.race.name, result });
            }
            const postRes = await httpPost(`${cfg.siteUrl}/admin.php`, {
                update_race: '1', csrf_token: admin.csrf, race_id: raceId,
                race_name: row.name, race_location: row.location,
                race_date: row.race_date, race_time: hhmm(row.race_time),
                quali_date: row.quali_date || '', quali_time: hhmm(row.quali_time),
                quali_p1: row.quali_p1 || '', quali_p2: row.quali_p2 || '', quali_p3: row.quali_p3 || '',
                result_p1: result[0], result_p2: result[1], result_p3: result[2],
            }, admin.cookie);
            if (postRes.status !== 200) summary.notes.push(`update_race HTTP ${postRes.status} for ${wk.race.name}`);
            wk.race.result = result;

            const driverName = (id) => (drivers.find((d) => d.id === id) || {}).name || id;
            summary.resultNames = result.map(driverName);

            if (createdDuels.length) {
                const status = await seed('simulation_status', { race_id: raceId });
                duelSummaries = status.duels.map((d) => ({
                    challenger: d.challenger_name, opponent: d.opponent_name,
                    status: d.status, cScore: d.c_score, oScore: d.o_score,
                }));
            }
        }
        summary.duels = duelSummaries;

        // 4. Perfect Week / GDPR cron for this week
        const cronOut = await scoreCronWeek(wk.monday);
        summary.cronOut = cronOut.trim();
        log('cron_scored', { week: wi + 1, monday: wk.monday, out: cronOut.trim() });

        // 5. Snapshot leaderboard
        const status = await seed('simulation_status', { participant_ids: participantIdsCsv });
        summary.leaderboardTop3 = status.leaderboard.slice(0, 3).map((r) => `${r.display_name} (${r.total_cp} CP, streak ${r.streak})`);

        weeklySummaries.push(summary);
        fs.writeFileSync(DATA_FILE, JSON.stringify({ weeks: weeklySummaries, roster: roster.map(({ answeredRumorIds, ...rest }) => rest) }, null, 2));

        // Write this week's markdown section immediately (survives a crash on a later week)
        report(`## Week ${summary.week} — ${summary.monday}${summary.race ? ` (${summary.race})` : ''}\n`);
        report(`- Content: ${summary.rumorPublished} rumor cards, ${summary.triviaPublished} trivia questions published.`);
        report(`- Participation: ${summary.engaged}/9 engaged, ${summary.rumorAnswers} rumor answers, ${summary.triviaAnswers} trivia answers.`);
        if (summary.race) {
            report(`- Race result: ${summary.resultNames ? summary.resultNames.join(' / ') : 'n/a'}.`);
            if (summary.duels.length) {
                report(`- Duels: ` + summary.duels.map((d) => `${d.challenger} vs ${d.opponent} (${d.status}, ${d.cScore ?? '-'} : ${d.oScore ?? '-'})`).join('; ') + '.');
            } else {
                report(`- Duels: none created this week.`);
            }
        }
        report(`- Perfect Week / GDPR cron: \`${summary.cronOut.replace(/\n/g, ' | ')}\``);
        report(`- Leaderboard top 3: ${summary.leaderboardTop3.join(', ') || 'no CP yet'}.`);
        if (summary.notes.length) report(`- Notes: ${summary.notes.join(' ')}`);
        report('');

        console.log(`Week ${wi + 1} done. Engaged ${summary.engaged}/9, rumor ${summary.rumorAnswers}, trivia ${summary.triviaAnswers}.`);
    }

    // ── Final report ──
    const finalStatus = await seed('simulation_status', { participant_ids: participantIdsCsv });
    report('## Final standings (after week 9)\n');
    for (const row of finalStatus.leaderboard) {
        const byGame = row.by_game.map((g) => `${g.game}: ${g.pts}`).join(', ');
        report(`- **${row.display_name}** — ${row.total_cp} CP total (streak ${row.streak}) — ${byGame}`);
    }
    fs.writeFileSync(DATA_FILE, JSON.stringify({ weeks: weeklySummaries, finalLeaderboard: finalStatus.leaderboard, roster: roster.map(({ answeredRumorIds, ...rest }) => rest) }, null, 2));

    console.log('\n== Simulation complete ==');
    console.log('Report:', REPORT_FILE);
    console.log('Data:', DATA_FILE);
}

main().catch((e) => {
    console.error('FATAL', e);
    log('fatal_error', { error: e.message, stack: e.stack });
    process.exit(1);
});
