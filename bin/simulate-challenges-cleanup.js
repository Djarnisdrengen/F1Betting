#!/usr/bin/env node
'use strict';
/**
 * Removes Rumor or Not / Trivia content a bin/simulate-challenges.js run published, via the
 * real admin-challenges.php bulk-delete endpoints (not direct DB access). Does NOT touch
 * participants/core users/duels — those are few enough (one roster's worth) to remove by hand
 * on admin-challenges.php's Members/Duels tabs, checkboxes work fine there; this script exists
 * because clicking through 50-100+ content rows by hand does not.
 *
 * Deliberately builds the POST body with `ids[]=` (bracket notation) for every id, not a
 * repeated bare `ids=` — see docs/gotchas.md #24 for why a naive hand-rolled POST here silently
 * only deletes one row. Verifies the actual before/after count rather than trusting the HTTP
 * status, for the same reason.
 *
 * Usage:
 *   node bin/simulate-challenges-cleanup.js --from=2026-03-02 --to=2026-05-03
 *   node bin/simulate-challenges-cleanup.js            (reads the date range from the latest
 *                                                        bin/simulate-runs/<timestamp>/data.json)
 *   node bin/simulate-challenges-cleanup.js --dry-run   (report what would be deleted, no writes)
 */
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const REPO = path.join(__dirname, '..');
const { readPhpConfig } = require(path.join(REPO, 'build-deploy/php-config'));
const cfg = readPhpConfig('test');

function parseArgs() {
    const args = { dryRun: false };
    for (const arg of process.argv.slice(2)) {
        const m = arg.match(/^--(\w+)=(.*)$/);
        if (m) { args[m[1]] = m[2]; continue; }
        if (arg === '--dry-run') args.dryRun = true;
    }
    return args;
}

function latestRunDateRange() {
    const runsDir = path.join(REPO, 'bin/simulate-runs');
    if (!fs.existsSync(runsDir)) return null;
    const dirs = fs.readdirSync(runsDir).sort().reverse();
    for (const d of dirs) {
        const dataFile = path.join(runsDir, d, 'data.json');
        if (!fs.existsSync(dataFile)) continue;
        const data = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
        const mondays = (data.weeks || []).map((w) => w.monday).sort();
        if (!mondays.length) continue;
        const from = mondays[0];
        const to = new Date(new Date(mondays[mondays.length - 1] + 'T00:00:00Z').getTime() + 7 * 86400000)
            .toISOString().slice(0, 10);
        return { from, to, source: dataFile };
    }
    return null;
}

function httpGet(url, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const req = mod.request({ hostname: parsed.hostname, port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80), path: parsed.pathname + parsed.search, method: 'GET', headers: { 'User-Agent': 'F1Betting-Sim/1.0', ...(cookieStr ? { Cookie: cookieStr } : {}) } }, (res) => {
            let body = ''; res.on('data', (c) => (body += c)); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject); req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); }); req.end();
    });
}
function httpPostRaw(url, bodyStr, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const req = mod.request({ hostname: parsed.hostname, port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80), path: parsed.pathname, method: 'POST', headers: { 'User-Agent': 'F1Betting-Sim/1.0', 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(bodyStr), ...(cookieStr ? { Cookie: cookieStr } : {}) } }, (res) => {
            let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: d }));
        });
        req.on('error', reject); req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); }); req.write(bodyStr); req.end();
    });
}
function pickCookies(h) { const arr = Array.isArray(h) ? h : (h ? [h] : []); return arr.map((c) => c.split(';')[0]).join('; '); }
function extractCsrf(html) { return (html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i) || html.match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i) || [])[1] || ''; }

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
    const args = parseArgs();
    let from = args.from, to = args.to;
    if (!from || !to) {
        const latest = latestRunDateRange();
        if (!latest) throw new Error('No --from/--to given and no bin/simulate-runs/*/data.json found to infer a range from.');
        from = latest.from; to = latest.to;
        console.log(`Using date range from latest run (${latest.source}): ${from} .. ${to}`);
    }
    const inRange = (d) => d && d >= from && d <= to;

    const rumor = await seed('list_challenge_content', { kind: 'rumor' });
    const trivia = await seed('list_challenge_content', { kind: 'trivia' });
    const rumorIds = rumor.items.filter((i) => inRange(i.publish_date)).map((i) => i.id);
    const triviaIds = trivia.items.filter((i) => inRange(i.publish_date)).map((i) => i.id);
    console.log(`Found ${rumorIds.length} rumor item(s) and ${triviaIds.length} trivia question(s) published ${from}..${to}.`);

    if (args.dryRun) { console.log('--dry-run: not deleting anything.'); return; }
    if (!rumorIds.length && !triviaIds.length) { console.log('Nothing to delete.'); return; }

    const page = await httpGet(`${cfg.siteUrl}/login.php`);
    const cookies = pickCookies(page.headers['set-cookie']);
    const csrf = extractCsrf(page.body);
    const loginRes = await httpPostRaw(`${cfg.siteUrl}/login.php`, `email=${encodeURIComponent(cfg.adminEmail)}&password=${encodeURIComponent(cfg.adminPassword)}&csrf_token=${encodeURIComponent(csrf)}`, cookies);
    const sessionCookie = pickCookies(loginRes.headers['set-cookie']);
    if (!sessionCookie) throw new Error('admin login failed — no session cookie returned');
    const adminPage = await httpGet(`${cfg.siteUrl}/admin-challenges.php?tab=rumors`, sessionCookie);
    const adminCsrf = extractCsrf(adminPage.body);
    if (!adminCsrf) throw new Error('could not extract admin CSRF token');

    // NOTE: ids[] bracket notation, not repeated bare `ids=` — see docs/gotchas.md #24.
    const buildBody = (action, ids) => `action=${encodeURIComponent(action)}&csrf_token=${encodeURIComponent(adminCsrf)}&` +
        ids.map((id) => `ids%5B%5D=${encodeURIComponent(id)}`).join('&');

    if (rumorIds.length) {
        const res = await httpPostRaw(`${cfg.siteUrl}/admin-challenges.php`, buildBody('bulk_delete_rumor', rumorIds), sessionCookie);
        console.log(`bulk_delete_rumor -> HTTP ${res.status}`);
    }
    if (triviaIds.length) {
        const res = await httpPostRaw(`${cfg.siteUrl}/admin-challenges.php`, buildBody('bulk_delete_trivia', triviaIds), sessionCookie);
        console.log(`bulk_delete_trivia -> HTTP ${res.status}`);
    }

    // Verify the actual effect — the endpoint returns 200/302 even when the naive encoding bug
    // silently deleted only one row, so a status check alone proves nothing.
    const rumorAfter = await seed('list_challenge_content', { kind: 'rumor' });
    const triviaAfter = await seed('list_challenge_content', { kind: 'trivia' });
    const rumorLeft = rumorAfter.items.filter((i) => inRange(i.publish_date)).length;
    const triviaLeft = triviaAfter.items.filter((i) => inRange(i.publish_date)).length;
    console.log(`After delete: ${rumorLeft} rumor item(s), ${triviaLeft} trivia question(s) still in range (expect 0).`);
    if (rumorLeft > 0 || triviaLeft > 0) {
        console.error('Some rows were not deleted — check admin-challenges.php manually.');
        process.exit(1);
    }
    console.log('Content cleanup complete.');
    console.log('Remember: participants/core users/duels are not touched by this script — remove');
    console.log('those on admin-challenges.php (Members/Duels tabs) and the core admin Users tab.');
}

main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
