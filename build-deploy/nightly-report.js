#!/usr/bin/env node
'use strict';

/**
 * Nightly test runner — runs E2E + security (SSL Labs + rate-limit) against
 * the live environment, then emails the report to REPORT_TO.
 *
 * Requires in build-deploy/.env:
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, REPORT_TO
 */

const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const fs   = require('fs');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const ROOT       = path.join(__dirname, '..');
const SMTP_HOST  = process.env.SMTP_HOST;
const SMTP_PORT  = parseInt(process.env.SMTP_PORT || '587', 10);
const SMTP_USER  = process.env.SMTP_USER;
const SMTP_PASS  = process.env.SMTP_PASS;
const SMTP_FROM  = process.env.SMTP_FROM || SMTP_USER;
const REPORT_TO  = process.env.REPORT_TO || 'thomas@helvegpovlsen.dk';
const BASE_URL   = process.env.BASE_URL_LIVE || 'https://www.formula-1.dk';

const SECTION_NAMES = {
    A: 'Transport Security',  B: 'Security Headers',
    C: 'Cookie Security',     D: 'Access Control',
    E: 'CSRF',                F: 'Information Disclosure',
    G: 'SSL Labs',            H: 'Outdated Components',
    I: 'Account Enumeration', J: 'DNS Security',
    K: 'Application Hardening', L: 'CWE Top 25',
};

// ─── Runners ────────────────────────────────────────────────────────────────

async function runE2E() {
    console.log('[nightly] E2E tests (live)…');
    try {
        const { stdout, stderr } = await execFileAsync(
            'npx', ['playwright', 'test', '--config', 'tests/playwright.config.js'],
            { cwd: ROOT, env: { ...process.env, DEPLOY_ENV: 'live' }, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, timeout: 180_000 }
        );
        const output = stdout + (stderr ? '\n' + stderr : '');
        console.log('[nightly] E2E exit code: 0 | output length:', output.length);
        return { exitCode: 0, output };
    } catch (err) {
        const output = (err.stdout || '') + (err.stderr ? '\n' + err.stderr : '');
        console.log('[nightly] E2E exit code:', err.code ?? 1, '| output length:', output.length);
        return { exitCode: err.code ?? 1, output };
    }
}

async function runSecurity() {
    console.log('[nightly] Security scan (live)…');
    try {
        const { stdout, stderr } = await execFileAsync(
            'node', ['tests/security/security.js', '--ssllabs', '--ratelimit'],
            { cwd: ROOT, env: { ...process.env, DEPLOY_ENV: 'live' }, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, timeout: 360_000 }
        );
        const output = stdout + (stderr ? '\n' + stderr : '');
        console.log('[nightly] Security exit code: 0 | output length:', output.length);
        return { exitCode: 0, output };
    } catch (err) {
        const output = (err.stdout || '') + (err.stderr ? '\n' + err.stderr : '');
        console.log('[nightly] Security exit code:', err.code ?? 1, '| output length:', output.length);
        return { exitCode: err.code ?? 1, output };
    }
}

function readLatestSecurityReport() {
    const dir = path.join(__dirname, 'security-reports');
    if (!fs.existsSync(dir)) return null;
    const file = fs.readdirSync(dir)
        .filter(f => f.endsWith('-live-security.json'))
        .sort()
        .reverse()[0];
    if (!file) return null;
    try { return JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')); }
    catch { return null; }
}

// ─── Email builder ───────────────────────────────────────────────────────────

function stripAnsi(str) {
    str = str.replace(/\x1b\[[0-9;]*m/g, '');
    return str.split('\n').map(line => line.split('\r').pop()).join('\n');
}

function htmlEscape(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Parse merged ⏳/✅/❌ reporter output into structured rows
function parseE2ERows(cleanText) {
    const rows = [];
    for (const line of cleanText.split('\n')) {
        if (!line.trim() || line.includes('E2E tests ')) continue;
        const indent = line.search(/\S/);
        const t = line.trim();
        if (t.includes('⏳') && t.includes('✅')) {
            rows.push({ kind: 'pass', name: t.replace(/^.*✅\s*/, '').trim(), indent });
        } else if (t.includes('⏳') && t.includes('❌')) {
            const raw  = t.replace(/^.*❌\s*/, '').trim();
            const name = raw.replace(/\s*→.*$/, '').trim();
            const msg  = raw.includes('→') ? raw.split(/\s*→\s*/).slice(1).join(' → ') : '';
            rows.push({ kind: 'fail', name, msg, indent });
        } else if (!t.includes('⏳')) {
            rows.push({ kind: 'header', name: t, indent });
        }
    }
    return rows;
}

function e2eRowsToHtml(rows) {
    return rows.map(row => {
        if (row.kind === 'header') {
            if (row.indent <= 0) {
                return `<div style="padding:8px 0 4px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.7px;border-bottom:1px solid #2a2a2a;">${htmlEscape(row.name)}</div>`;
            }
            return `<div style="padding:10px 0 3px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.6px;">${htmlEscape(row.name)}</div>`;
        }
        const col = row.kind === 'pass' ? '#27ae60' : '#e10600';
        const ico = row.kind === 'pass' ? '&#x2705;' : '&#x274C;';
        const msg = row.msg
            ? `<div style="padding:1px 0 4px 22px;font-size:11px;color:#666;">&#x21B3; ${htmlEscape(row.msg)}</div>`
            : '';
        return `<div style="padding:3px 0;font-size:13px;color:${col};">${ico} ${htmlEscape(row.name)}</div>${msg}`;
    }).join('');
}

function secChecksToHtml(checks) {
    if (!checks?.length) return '<p style="color:#666;font-size:13px;padding:8px 0;">No security data available.</p>';

    const grouped = {};
    for (const c of checks) {
        if (!grouped[c.section]) grouped[c.section] = [];
        grouped[c.section].push(c);
    }

    return Object.entries(grouped).map(([letter, items]) => {
        const name  = SECTION_NAMES[letter] || letter;
        const pass  = items.filter(c => c.status === 'PASS').length;
        const fail  = items.filter(c => c.status === 'FAIL').length;
        const warn  = items.filter(c => c.status === 'WARN').length;
        const hasIssue = fail > 0 || warn > 0;
        const col   = fail > 0 ? '#e10600' : warn > 0 ? '#f39c12' : '#27ae60';
        const badge = [
            pass ? `${pass}&#x2705;` : '',
            fail ? `${fail}&#x274C;` : '',
            warn ? `${warn}&#x26A0;&#xFE0F;` : '',
        ].filter(Boolean).join('&nbsp;');

        const checkRows = items.map(c => {
            const cc  = c.status === 'FAIL' ? '#e10600' : c.status === 'WARN' ? '#f39c12' : '#4a9960';
            const ico = c.status === 'FAIL' ? '&#x274C;' : c.status === 'WARN' ? '&#x26A0;&#xFE0F;' : '&#x2705;';
            const det = c.detail
                ? `<div style="padding:1px 0 2px 22px;font-size:11px;color:#666;">&#x21B3; ${htmlEscape(c.detail)}</div>`
                : '';
            const rem = c.remediation
                ? `<div style="padding:1px 0 4px 22px;font-size:11px;color:#555;">&#x2699;&#xFE0F; ${htmlEscape(c.remediation)}</div>`
                : '';
            return `<div style="padding:3px 0;font-size:13px;color:${cc};">${ico} ${htmlEscape(c.check)}</div>${det}${rem}`;
        }).join('');

        return `
<details class="sec-section"${hasIssue ? ' open' : ''}>
  <summary style="padding:10px 0 10px 12px;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #2a2a2a;">
    <span style="font-size:13px;font-weight:600;color:${col};">[${letter}] ${htmlEscape(name)}</span>
    <span style="font-size:11px;color:#666;">${badge}</span>
  </summary>
  <div style="padding:0 0 8px 28px;">${checkRows}</div>
</details>`;
    }).join('');
}

function buildEmail(e2e, _sec, report, startedAt) {
    const dkFmt  = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Copenhagen', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    const dkStr  = dkFmt.format(startedAt);   // "2026-05-15 23:50:53"
    const date   = dkStr.slice(0, 10);
    const ts     = dkStr + ' (dansk tid)';
    const e2eOk      = e2e.exitCode === 0;
    const secSummary = report?.summary ?? null;
    const secFail    = secSummary ? secSummary.fail > 0 : false;
    const overall    = e2eOk && !secFail ? 'ALL OK' : 'ISSUES FOUND';
    const ovColor    = overall === 'ALL OK' ? '#27ae60' : '#e10600';

    // E2E — parse structured rows
    const cleanE2e  = stripAnsi(e2e.output);
    const e2eRows   = parseE2ERows(cleanE2e);
    const e2ePass   = e2eRows.filter(r => r.kind === 'pass').length;
    const e2eFail   = e2eRows.filter(r => r.kind === 'fail').length;
    const e2eTotal  = e2ePass + e2eFail;
    const e2eHtml   = e2eRowsToHtml(e2eRows) || '<p style="color:#666;font-size:13px;">No output captured.</p>';

    // Security — structured from JSON report
    const allChecks = report?.checks ?? [];
    const secHtml   = secChecksToHtml(allChecks);

    // SSL Labs grade from checks
    const sslChecks = allChecks.filter(c => c.section === 'G' && c.check.includes('grade'));
    const sslText   = sslChecks.length ? sslChecks.map(c => c.detail || c.check).join(', ') : 'N/A';
    const sslColor  = sslChecks.some(c => c.status === 'FAIL') ? '#e10600'
        : sslChecks.some(c => c.status === 'WARN') ? '#f39c12' : '#27ae60';

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
  body{margin:0;padding:0;background:#1a1a1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
  .outer{padding:24px 12px;}
  .card{max-width:620px;margin:0 auto;background:#242424;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.4);}
  .scores{width:100%;border-collapse:separate;border-spacing:8px;}
  .scores td{width:33%;text-align:center;padding:16px 10px;background:#1a1a1a;border-radius:10px;vertical-align:middle;}
  details>summary{padding:15px 28px;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;}
  details>summary::-webkit-details-marker{display:none;}
  details>summary::before{content:'▸';margin-right:10px;color:#555;transition:transform .15s;display:inline-block;}
  details[open]>summary::before{transform:rotate(90deg);}
  .sec-section>summary{padding:10px 0 10px 12px;}
  .sec-section>summary::before{color:#444;}
  @media(max-width:540px){
    .scores td{display:block;width:auto!important;margin-bottom:6px;}
    details>summary{padding:14px 16px;}
    .body-pad{padding:0 16px 14px!important;}
  }
</style>
</head>
<body>
<div class="outer">
<div class="card">

<!-- Header -->
<div style="padding:28px 28px 20px;text-align:center;background:#141414;border-bottom:3px solid #e10600;">
  <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;letter-spacing:.5px;">&#127937; F1Betting &mdash; Nightly Report</h1>
  <p style="margin:8px 0 0;color:#666;font-size:13px;">${ts}</p>
  <p style="margin:3px 0 0;font-size:12px;color:#3a3a3a;">${htmlEscape(BASE_URL)}</p>
</div>

<!-- Status -->
<div style="padding:20px 28px;text-align:center;border-bottom:1px solid #2a2a2a;">
  <div style="font-size:28px;font-weight:800;color:${ovColor};letter-spacing:1px;">${overall}</div>
</div>

<!-- Score cards -->
<div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;">
  <table class="scores">
  <tr>
    <td>
      <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">E2E Tests</div>
      <div style="font-size:24px;">${e2eOk ? '&#x2705;' : '&#x274C;'}</div>
      <div style="font-size:13px;color:#aaa;margin-top:4px;">${e2ePass}/${e2eTotal} passed</div>
    </td>
    <td>
      <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">Security</div>
      <div style="font-size:24px;">${secSummary ? (secSummary.fail === 0 ? '&#x2705;' : '&#x274C;') : '&#x2753;'}</div>
      <div style="font-size:12px;color:#aaa;margin-top:4px;">${secSummary ? `${secSummary.pass}&#x2705;&nbsp;${secSummary.fail}&#x274C;&nbsp;${secSummary.warn}&#x26A0;&#xFE0F;` : 'N/A'}</div>
    </td>
    <td>
      <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">SSL Labs</div>
      <div style="font-size:20px;font-weight:700;color:${sslColor};margin:6px 0;">${htmlEscape(sslText)}</div>
    </td>
  </tr>
  </table>
</div>

<!-- E2E Tests accordion -->
<details open>
  <summary>
    <span style="font-size:14px;font-weight:600;color:#fff;">E2E Tests</span>
    <span style="font-size:12px;color:#666;">${e2ePass}/${e2eTotal} passed</span>
  </summary>
  <div class="body-pad" style="padding:0 28px 16px;">
    ${e2eHtml}
  </div>
</details>

<!-- Security accordion -->
<details${secFail ? ' open' : ''}>
  <summary>
    <span style="font-size:14px;font-weight:600;color:#fff;">Security Checks</span>
    <span style="font-size:12px;color:#666;">${secSummary ? `${secSummary.pass}&#x2705;&nbsp;${secSummary.fail}&#x274C;&nbsp;${secSummary.warn}&#x26A0;&#xFE0F;` : 'N/A'}</span>
  </summary>
  <div class="body-pad" style="padding:0 28px 16px;">
    ${secHtml}
  </div>
</details>

<!-- Footer -->
<div style="padding:14px 28px;text-align:center;border-top:1px solid #1a1a1a;">
  <p style="margin:0;color:#333;font-size:11px;">F1Betting Nightly &middot; ${date}</p>
</div>

</div>
</div>
</body>
</html>`;
}

// ─── Report saver ────────────────────────────────────────────────────────────

function saveReport(html, startedAt) {
    const dir  = path.join(__dirname, 'nightly-reports');
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    const ts   = startedAt.toISOString().replace(/:/g, '-').slice(0, 19);
    const file = path.join(dir, `${ts}-live-nightly.html`);
    fs.writeFileSync(file, html, 'utf8');
    console.log(`[nightly] Report saved → ${file}`);
}

// ─── Email sender ────────────────────────────────────────────────────────────

async function sendEmail(subject, html) {
    if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
        console.error('[nightly] SMTP not configured — set SMTP_HOST, SMTP_USER, SMTP_PASS in build-deploy/.env');
        process.exit(1);
    }
    const nodemailer = require('nodemailer');
    const transporter = nodemailer.createTransport({
        host: SMTP_HOST,
        port: SMTP_PORT,
        secure: SMTP_PORT === 465,
        auth: { user: SMTP_USER, pass: SMTP_PASS },
    });
    await transporter.sendMail({
        from: SMTP_FROM,
        to: REPORT_TO,
        subject,
        html,
        text: `F1Betting Nightly Report — view as HTML.\n\nTarget: ${BASE_URL}`,
    });
    console.log(`[nightly] Report sent → ${REPORT_TO}`);
}

// ─── Main ────────────────────────────────────────────────────────────────────

async function main() {
    const startedAt = new Date();
    console.log(`[nightly] Started at ${startedAt.toISOString()}`);

    const [e2e, sec] = await Promise.all([runE2E(), runSecurity()]);
    const report = readLatestSecurityReport();

    const e2eOk   = e2e.exitCode === 0;
    const secFail = report ? report.summary.fail > 0 : false;
    const overall = e2eOk && !secFail ? 'ALL OK' : 'ISSUES FOUND';
    const date    = startedAt.toISOString().slice(0, 10);

    const subject = `[F1Betting] Nightly — ${overall} — ${date}`;
    const html    = buildEmail(e2e, sec, report, startedAt);

    saveReport(html, startedAt);
    await sendEmail(subject, html);
    console.log(`[nightly] Finished at ${new Date().toISOString()}`);
}

main().catch(err => { console.error('[nightly] Fatal:', err); process.exit(1); });
