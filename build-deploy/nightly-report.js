#!/usr/bin/env node
'use strict';

/**
 * Nightly test runner — runs E2E + security (SSL Labs + rate-limit) against
 * the live environment, then emails the report to REPORT_TO.
 *
 * Requires in build-deploy/.env:
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, REPORT_TO
 */

const { spawn } = require('child_process');
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

function runProcess(label, cmd, args, env) {
    return new Promise(resolve => {
        console.log(`[nightly] ${label}…`);
        const proc = spawn(cmd, args, { cwd: ROOT, env: { ...process.env, ...env } });
        let stdout = '', stderr = '';
        proc.stdout?.on('data', d => { stdout += d; });
        proc.stderr?.on('data', d => { stderr += d; });
        proc.on('error', err => resolve({ exitCode: 1, output: err.message }));
        proc.on('close', code => {
            console.log(`[nightly] ${label} exit code: ${code}`);
            resolve({ exitCode: code ?? 1, output: stdout + (stderr ? '\n' + stderr : '') });
        });
    });
}

function runE2E() {
    return runProcess(
        'E2E tests (live)',
        'npx', ['playwright', 'test', '--config', 'tests/playwright.config.js'],
        { DEPLOY_ENV: 'live' }
    );
}

function runSecurity() {
    return runProcess(
        'Security scan (live, --ssllabs --ratelimit)',
        'node', ['tests/security/security.js', '--ssllabs', '--ratelimit'],
        { DEPLOY_ENV: 'live' }
    );
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
    return str.replace(/\x1b\[[0-9;]*m/g, '').replace(/\r/g, '');
}

function htmlEscape(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function buildEmail(e2e, sec, report) {
    const date    = new Date().toISOString().slice(0, 10);
    const ts      = new Date().toISOString().slice(0, 19).replace('T', ' ') + ' UTC';
    const e2eOk   = e2e.exitCode === 0;
    const sec     = report?.summary ?? null;
    const secFail = sec ? sec.fail > 0 : false;
    const overall = e2eOk && !secFail ? 'ALL OK' : 'ISSUES FOUND';
    const ovColor = overall === 'ALL OK' ? '#27ae60' : '#e10600';

    // ── E2E counts ─────────────────────────────────────────────────────
    // Reporter format: "✅ E2E tests passed (10/10)" or "❌ E2E tests failed (3/10 failed)"
    const cleanE2e = stripAnsi(e2e.output);
    const summaryM = cleanE2e.match(/E2E tests (?:passed|failed) \((\d+)\/(\d+)/);
    const failedM  = cleanE2e.match(/E2E tests failed \((\d+)\/(\d+)/);
    const e2eTotal = summaryM ? +summaryM[2] : 0;
    const e2eFail  = failedM  ? +failedM[1]  : 0;
    const e2ePass  = e2eTotal - e2eFail;

    // ── Security findings ──────────────────────────────────────────────
    const findings = report
        ? report.checks.filter(c => c.status === 'FAIL' || c.status === 'WARN')
        : [];

    const findingsRows = findings.map(f => {
        const col = f.status === 'FAIL' ? '#e10600' : '#f39c12';
        const ico = f.status === 'FAIL' ? '&#x274C;' : '&#x26A0;&#xFE0F;';
        const det = htmlEscape(f.detail || '');
        const rem = f.remediation
            ? `<br><span style="color:#666;font-size:12px;">&#x21B3; ${htmlEscape(f.remediation)}</span>`
            : '';
        const cwe = f.cwe
            ? `<br><span style="color:#555;font-size:11px;">${htmlEscape(f.cwe)}</span>`
            : '';
        return `<tr>
          <td style="padding:8px 10px;border-bottom:1px solid #2a2a2a;color:${col};white-space:nowrap;vertical-align:top;">${ico} ${f.section}</td>
          <td style="padding:8px 10px;border-bottom:1px solid #2a2a2a;color:#ccc;vertical-align:top;">${htmlEscape(f.check)}${cwe}</td>
          <td style="padding:8px 10px;border-bottom:1px solid #2a2a2a;color:#888;font-size:12px;vertical-align:top;">${det}${rem}</td>
        </tr>`;
    }).join('');

    // ── SSL Labs grade ─────────────────────────────────────────────────
    const sslChecks = report ? report.checks.filter(c => c.section === 'G' && c.check.includes('grade')) : [];
    const sslText   = sslChecks.length
        ? sslChecks.map(c => `${c.detail || c.check}`).join(', ')
        : 'N/A';
    const sslColor  = sslChecks.some(c => c.status === 'FAIL') ? '#e10600'
        : sslChecks.some(c => c.status === 'WARN') ? '#f39c12' : '#27ae60';

    // ── E2E output block (strip ANSI, keep ✅ / ❌ lines) ─────────────
    const e2eLines = cleanE2e
        .split('\n')
        .filter(l => l.trim())
        .slice(0, 80)
        .map(l => {
            const col = l.includes('❌') ? '#e10600'
                : l.includes('✅') ? '#27ae60'
                : '#888';
            return `<div style="color:${col};font-family:monospace;font-size:12px;white-space:pre-wrap;margin:1px 0;">${htmlEscape(l)}</div>`;
        })
        .join('');

    // ── Security output block ──────────────────────────────────────────
    const cleanSec = stripAnsi(sec.output || '');
    const secLines = cleanSec
        .split('\n')
        .filter(l => l.trim())
        .slice(0, 120)
        .map(l => {
            const col = l.includes('❌') || l.includes('FAIL') ? '#e10600'
                : l.includes('✅') || l.includes('PASS') ? '#27ae60'
                : l.includes('⚠') || l.includes('WARN') ? '#f39c12'
                : l.startsWith('[') ? '#7a8fa6'
                : '#888';
            return `<div style="color:${col};font-family:monospace;font-size:12px;white-space:pre-wrap;margin:1px 0;">${htmlEscape(l)}</div>`;
        })
        .join('');

    return `<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#1a1a1a;">
<table role="presentation" style="width:100%;border-collapse:collapse;">
<tr><td style="padding:32px 16px;">
<table role="presentation" style="max-width:620px;margin:0 auto;background:#242424;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.4);">

<!-- Header -->
<tr><td style="padding:28px 36px 24px;text-align:center;background:#141414;border-bottom:3px solid #e10600;">
  <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;letter-spacing:.5px;">&#127937; F1Betting — Nightly Report</h1>
  <p style="margin:6px 0 0;color:#666;font-size:13px;">${ts}</p>
  <p style="margin:3px 0 0;font-size:12px;color:#444;">${BASE_URL}</p>
</td></tr>

<!-- Overall -->
<tr><td style="padding:20px 36px;text-align:center;border-bottom:1px solid #2a2a2a;">
  <span style="font-size:28px;font-weight:700;color:${ovColor};letter-spacing:1px;">${overall}</span>
</td></tr>

<!-- Score cards -->
<tr><td style="padding:20px 36px;border-bottom:1px solid #2a2a2a;">
  <table style="width:100%;border-collapse:separate;border-spacing:8px;">
  <tr>
    <td style="text-align:center;padding:16px 10px;background:#1a1a1a;border-radius:10px;">
      <div style="color:#555;font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">E2E Tests</div>
      <div style="font-size:26px;">${e2eOk ? '&#x2705;' : '&#x274C;'}</div>
      <div style="color:#aaa;font-size:13px;margin-top:4px;">${e2ePass}/${e2eTotal} passed</div>
    </td>
    <td style="text-align:center;padding:16px 10px;background:#1a1a1a;border-radius:10px;">
      <div style="color:#555;font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">Security</div>
      <div style="font-size:26px;">${sec ? (sec.fail === 0 ? '&#x2705;' : '&#x274C;') : '&#x2753;'}</div>
      <div style="color:#aaa;font-size:13px;margin-top:4px;">
        ${sec ? `${sec.pass}&#x2705; ${sec.fail}&#x274C; ${sec.warn}&#x26A0;&#xFE0F;` : 'N/A'}
      </div>
    </td>
    <td style="text-align:center;padding:16px 10px;background:#1a1a1a;border-radius:10px;">
      <div style="color:#555;font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;">SSL Labs</div>
      <div style="font-size:18px;font-weight:700;color:${sslColor};margin:4px 0;">${sslText}</div>
    </td>
  </tr>
  </table>
</td></tr>

<!-- Findings -->
${findings.length ? `
<tr><td style="padding:20px 36px;border-bottom:1px solid #2a2a2a;">
  <h3 style="margin:0 0 14px;color:#fff;font-size:15px;">Findings (${findings.length})</h3>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <tr>
      <th style="padding:6px 10px;text-align:left;color:#555;font-size:11px;border-bottom:1px solid #333;font-weight:500;">SEC</th>
      <th style="padding:6px 10px;text-align:left;color:#555;font-size:11px;border-bottom:1px solid #333;font-weight:500;">Check</th>
      <th style="padding:6px 10px;text-align:left;color:#555;font-size:11px;border-bottom:1px solid #333;font-weight:500;">Detail / Remediation</th>
    </tr>
    ${findingsRows}
  </table>
</td></tr>` : `
<tr><td style="padding:20px 36px;border-bottom:1px solid #2a2a2a;">
  <p style="margin:0;color:#27ae60;font-size:15px;">&#x2705; No security findings.</p>
</td></tr>`}

<!-- E2E output -->
<tr><td style="padding:20px 36px;border-bottom:1px solid #2a2a2a;">
  <h3 style="margin:0 0 12px;color:#fff;font-size:15px;">E2E Output</h3>
  <div style="background:#111;border-radius:8px;padding:14px;overflow:auto;">
    ${e2eLines || '<div style="color:#555;font-size:12px;">No output captured.</div>'}
  </div>
</td></tr>

<!-- Security output -->
<tr><td style="padding:20px 36px;border-bottom:1px solid #2a2a2a;">
  <h3 style="margin:0 0 12px;color:#fff;font-size:15px;">Security Scan Output</h3>
  <div style="background:#111;border-radius:8px;padding:14px;overflow:auto;">
    ${secLines || '<div style="color:#555;font-size:12px;">No output captured.</div>'}
  </div>
</td></tr>

<!-- Footer -->
<tr><td style="padding:16px 36px;text-align:center;">
  <p style="margin:0;color:#333;font-size:11px;">F1Betting Nightly · ${date}</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>`;
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
    console.log(`[nightly] Started at ${new Date().toISOString()}`);

    const [e2e, sec] = await Promise.all([runE2E(), runSecurity()]);
    const report = readLatestSecurityReport();

    const e2eOk   = e2e.exitCode === 0;
    const secFail = report ? report.summary.fail > 0 : false;
    const overall = e2eOk && !secFail ? 'ALL OK' : 'ISSUES FOUND';
    const date    = new Date().toISOString().slice(0, 10);

    const subject = `[F1Betting] Nightly — ${overall} — ${date}`;
    const html    = buildEmail(e2e, sec, report);

    await sendEmail(subject, html);
    console.log(`[nightly] Finished at ${new Date().toISOString()}`);
}

main().catch(err => { console.error('[nightly] Fatal:', err); process.exit(1); });
