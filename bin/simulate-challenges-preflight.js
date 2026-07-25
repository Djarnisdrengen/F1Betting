#!/usr/bin/env node
'use strict';
/**
 * Cheap sanity check before a full bin/simulate-challenges.js run: admin login/CSRF against
 * TEST, the recon read endpoints, and one real (tiny, backdated-to-2099 so it's never
 * player-visible) content-generation + import round trip. Takes well under a minute; catches a
 * bad config/token/API-key before the roster gets seeded and real API credits get spent on a
 * run that can't finish. Usage: node bin/simulate-challenges-preflight.js
 *
 * Needs the same build-deploy/.env ANTHROPIC_API_KEY_SIMULATION as bin/simulate-challenges.js —
 * see that file's header comment for why it's a dedicated key, not the shared ANTHROPIC_API_KEY.
 */
const path = require('path');
const http = require('http');
const https = require('https');
const { URL } = require('url');
const { execFileSync } = require('child_process');

const REPO = path.join(__dirname, '..');
require('dotenv').config({ path: path.join(REPO, 'build-deploy/.env') });
const { readPhpConfig } = require(path.join(REPO, 'build-deploy/php-config'));
const cfg = readPhpConfig('test');

const SIMULATION_ANTHROPIC_KEY = process.env.ANTHROPIC_API_KEY_SIMULATION;
if (!SIMULATION_ANTHROPIC_KEY) {
    console.error('ANTHROPIC_API_KEY_SIMULATION not set in build-deploy/.env — see bin/simulate-challenges.js\'s header comment.');
    process.exit(1);
}

function httpGet(url, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const req = mod.request({ hostname: parsed.hostname, port: parsed.port || 443, path: parsed.pathname + parsed.search, method: 'GET', headers: { 'User-Agent': 'F1Betting-Sim/1.0', ...(cookieStr ? { Cookie: cookieStr } : {}) } }, (res) => {
            let body = ''; res.on('data', (c) => (body += c)); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject); req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); }); req.end();
    });
}
function httpPost(url, fields, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const mod = parsed.protocol === 'https:' ? https : http;
        const body = Object.entries(fields).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
        const req = mod.request({ hostname: parsed.hostname, port: parsed.port || 443, path: parsed.pathname, method: 'POST', headers: { 'User-Agent': 'F1Betting-Sim/1.0', 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body), ...(cookieStr ? { Cookie: cookieStr } : {}) } }, (res) => {
            let data = ''; res.on('data', (c) => (data += c)); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
        });
        req.on('error', reject); req.setTimeout(20000, () => { req.destroy(); reject(new Error('timeout')); }); req.write(body); req.end();
    });
}
function pickCookies(h) { const arr = Array.isArray(h) ? h : (h ? [h] : []); return arr.map((c) => c.split(';')[0]).join('; '); }
function extractCsrf(html) { return (html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i) || html.match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i) || [])[1] || ''; }

async function main() {
    console.log('1. Admin login...');
    const page = await httpGet(`${cfg.siteUrl}/login.php`);
    const cookies = pickCookies(page.headers['set-cookie']);
    const csrf = extractCsrf(page.body);
    console.log('   got login page, csrf len', csrf.length, 'cookie?', !!cookies);
    const res = await httpPost(`${cfg.siteUrl}/login.php`, { email: cfg.adminEmail, password: cfg.adminPassword, csrf_token: csrf }, cookies);
    const newCookies = pickCookies(res.headers['set-cookie']);
    console.log('   login POST status', res.status, 'new cookie?', !!newCookies);
    if (!newCookies) throw new Error('no session cookie after login — body snippet: ' + res.body.slice(0, 300));

    console.log('2. Admin page + CSRF...');
    const adminPage = await httpGet(`${cfg.siteUrl}/admin.php?tab=races`, newCookies);
    const adminCsrf = extractCsrf(adminPage.body);
    console.log('   admin.php status', adminPage.status, 'csrf len', adminCsrf.length, 'contains "Races"?', /race/i.test(adminPage.body));

    console.log('3. test-seed.php list_races / list_drivers...');
    const url1 = new URL(`${cfg.siteUrl}/tools/test-seed.php`); url1.searchParams.set('token', cfg.integrationSeedToken); url1.searchParams.set('action', 'list_races');
    const races = await (await fetch(url1)).json();
    console.log('   races ok?', races.ok, 'count', races.races && races.races.length);

    console.log('4. One real generator call (rumor, count=1, publish, backdated)...');
    const out = execFileSync('node', ['bin/generate-rumor-items.js', '--env=test', '--count=1', '--publish', '--publish-date=2099-01-05'], {
        cwd: REPO, encoding: 'utf8', timeout: 120000,
        env: { ...process.env, ANTHROPIC_API_KEY: SIMULATION_ANTHROPIC_KEY },
    });
    console.log(out);
    const idsLine = out.split('\n').find((l) => l.startsWith('IDS:'));
    console.log('   parsed ids:', idsLine);

    console.log('\nPREFLIGHT OK');
}
main().catch((e) => { console.error('PREFLIGHT FAILED:', e.message); process.exit(1); });
