#!/usr/bin/env node
'use strict';

/**
 * Monthly security review — compares OWASP Top 10 and CWE Top 25 reference lists
 * against what tests/security/security.js actually covers, then emails a gap report.
 *
 * Reference lists are hardcoded (reliable, no runtime scraping). When OWASP or MITRE
 * publish an update, bump the version date and update the list below.
 *
 * Run manually:  node build-deploy/security-review.js
 * Scheduled via: .github/workflows/monthly-security-review.yml (1st of each month)
 */

const fs   = require('fs');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '.env') });

const { sendEmail, makeSmtpSender, makeResendSender } = require('./mailer');

const SMTP_HOST      = process.env.SMTP_HOST;
const SMTP_PORT      = parseInt(process.env.SMTP_PORT || '587', 10);
const SMTP_USER      = process.env.SMTP_USER;
const SMTP_PASS      = process.env.SMTP_PASS;
const SMTP_FROM      = process.env.SMTP_FROM || SMTP_USER;
const REPORT_TO      = process.env.REPORT_TO || 'thomas@helvegpovlsen.dk';
const RESEND_API_KEY = process.env.RESEND_API_KEY;

const SECURITY_JS = path.join(__dirname, '../tests/security/security.js');

// ─── Reference lists ─────────────────────────────────────────────────────────
// When OWASP or MITRE publish an update, bump the version and update the list.
// OWASP Top 10: https://owasp.org/Top10/  (last updated 2021; next expected ~2025)
// CWE Top 25:   https://cwe.mitre.org/top25/ (updated annually, last verified 2024-05-21)

const OWASP_VERSION = '2021';
const OWASP_TOP10 = [
    { id: 'A01', name: 'Broken Access Control' },
    { id: 'A02', name: 'Cryptographic Failures' },
    { id: 'A03', name: 'Injection' },
    { id: 'A04', name: 'Insecure Design' },
    { id: 'A05', name: 'Security Misconfiguration' },
    { id: 'A06', name: 'Vulnerable and Outdated Components' },
    { id: 'A07', name: 'Identification and Authentication Failures' },
    { id: 'A08', name: 'Software and Data Integrity Failures' },
    { id: 'A09', name: 'Security Logging and Monitoring Failures' },
    { id: 'A10', name: 'Server-Side Request Forgery (SSRF)' },
];

const CWE_VERSION = '2024';
// Web-application-relevant subset of the 2024 CWE Top 25.
// Non-web items (buffer overflows, null pointer, integer overflow) are excluded.
const CWE_TOP25_WEB = [
    { rank:  1, id: 'CWE-79',  name: 'Cross-site Scripting (XSS)' },
    { rank:  3, id: 'CWE-89',  name: 'SQL Injection' },
    { rank:  8, id: 'CWE-22',  name: 'Path Traversal' },
    { rank:  9, id: 'CWE-352', name: 'Cross-Site Request Forgery (CSRF)' },
    { rank: 10, id: 'CWE-434', name: 'Unrestricted Upload of File with Dangerous Type' },
    { rank: 11, id: 'CWE-862', name: 'Missing Authorization' },
    { rank: 13, id: 'CWE-287', name: 'Improper Authentication' },
    { rank: 15, id: 'CWE-502', name: 'Deserialization of Untrusted Data' },
    { rank: 16, id: 'CWE-77',  name: 'Command Injection' },
    { rank: 18, id: 'CWE-798', name: 'Use of Hard-coded Credentials' },
    { rank: 19, id: 'CWE-918', name: 'Server-Side Request Forgery (SSRF)' },
    { rank: 20, id: 'CWE-306', name: 'Missing Authentication for Critical Function' },
    { rank: 22, id: 'CWE-269', name: 'Improper Privilege Management' },
    { rank: 23, id: 'CWE-94',  name: 'Code Injection' },
    { rank: 24, id: 'CWE-863', name: 'Incorrect Authorization' },
    { rank: 25, id: 'CWE-276', name: 'Incorrect Default Permissions' },
];

// ─── Parse security.js for covered CWEs and OWASP section tags ──────────────
function parseCoverage() {
    const src = fs.readFileSync(SECURITY_JS, 'utf8');
    const cwes      = new Set([...src.matchAll(/'(CWE-\d+)'/g)].map(m => m[1]));
    const owaspRefs = new Set([...src.matchAll(/OWASP (A\d{2})/g)].map(m => m[1]));
    return { cwes, owaspRefs };
}

// ─── Build HTML email ────────────────────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderList(items, color) {
    return items.length
        ? items.map(i => `<li style="margin:4px 0;color:${color};">${esc(i)}</li>`).join('')
        : '<li style="color:#22c55e;">None — all items covered ✓</li>';
}

function buildEmail({ coveredCwes, owaspRefs, owaspGaps, cweGaps }) {
    const date        = new Date().toISOString().slice(0, 10);
    const totalGaps   = owaspGaps.length + cweGaps.length;
    const statusColor = totalGaps === 0 ? '#22c55e' : '#f59e0b';
    const statusText  = totalGaps === 0
        ? '✅ No gaps detected — security.js covers all reference items'
        : `⚠️ ${totalGaps} gap(s) found — review and update security.js`;

    return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Monthly Security Review</title></head>
<body style="margin:0;padding:0;background:#111;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#111;padding:32px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#1a1a1e;border-radius:12px;overflow:hidden;">
      <tr>
        <td style="padding:24px 28px 16px;border-bottom:1px solid #2a2a2a;">
          <table cellpadding="0" cellspacing="0"><tr>
            <td width="36" height="36" style="background:#e10600;border-radius:8px;text-align:center;color:#fff;font-weight:900;font-size:14px;vertical-align:middle;">F1</td>
            <td style="padding-left:10px;font-weight:700;font-size:15px;color:#fff;">F1 Betting · Monthly Security Review</td>
          </tr></table>
          <p style="margin:8px 0 0;font-size:12px;color:#666;">${esc(date)}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:24px 28px;">

          <div style="background:#111;border-radius:8px;padding:14px 18px;margin-bottom:24px;">
            <span style="font-size:15px;font-weight:700;color:${statusColor};">${statusText}</span>
          </div>

          <h3 style="margin:0 0 8px;font-size:13px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.6px;">
            OWASP Top 10 (${esc(OWASP_VERSION)}) — Uncovered categories
          </h3>
          <ul style="margin:0 0 6px;padding-left:20px;font-size:13px;">
            ${renderList(owaspGaps, '#f59e0b')}
          </ul>
          <p style="font-size:11px;color:#555;margin:4px 0 22px;">
            Covered: ${[...owaspRefs].sort().join(', ')} &nbsp;·&nbsp;
            <a href="https://owasp.org/Top10/" style="color:#555;">owasp.org/Top10</a>
          </p>

          <h3 style="margin:0 0 8px;font-size:13px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.6px;">
            CWE Top 25 web subset (${esc(CWE_VERSION)}) — Uncovered items
          </h3>
          <ul style="margin:0 0 6px;padding-left:20px;font-size:13px;">
            ${renderList(cweGaps, '#f59e0b')}
          </ul>
          <p style="font-size:11px;color:#555;margin:4px 0 22px;">
            Covered CWEs: ${[...coveredCwes].sort().join(', ')}<br>
            <a href="https://cwe.mitre.org/top25/" style="color:#555;">cwe.mitre.org/top25</a>
            — verify the reference list in <code>build-deploy/security-review.js</code> matches the current year's publication.
          </p>

          <hr style="border:none;border-top:1px solid #2a2a2a;margin:20px 0;">
          <p style="font-size:12px;color:#555;margin:0;line-height:1.6;">
            <strong style="color:#888;">Action if gaps found:</strong>
            Add checks to <code style="background:#222;padding:1px 4px;border-radius:3px;">tests/security/security.js</code>
            referencing the CWE/OWASP ID in the <code>cwe</code> field.<br>
            <strong style="color:#888;">Action if lists updated:</strong>
            Bump the version constants and update the reference arrays in
            <code style="background:#222;padding:1px 4px;border-radius:3px;">build-deploy/security-review.js</code>.
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>`;
}

// ─── Main ────────────────────────────────────────────────────────────────────
async function main() {
    console.log('[security-review] Parsing security.js…');
    const { cwes: coveredCwes, owaspRefs } = parseCoverage();
    console.log(`[security-review] Covered CWEs:  ${[...coveredCwes].sort().join(', ')}`);
    console.log(`[security-review] Covered OWASP: ${[...owaspRefs].sort().join(', ')}`);

    const owaspGaps = OWASP_TOP10
        .filter(o => !owaspRefs.has(o.id))
        .map(o => `${o.id}: ${o.name}`);

    const cweGaps = CWE_TOP25_WEB
        .filter(e => !coveredCwes.has(e.id))
        .map(e => `#${e.rank} ${e.id}: ${e.name}`);

    console.log(`[security-review] OWASP gaps (${owaspGaps.length}): ${owaspGaps.join(' | ') || 'none'}`);
    console.log(`[security-review] CWE gaps   (${cweGaps.length}): ${cweGaps.join(' | ') || 'none'}`);

    const html    = buildEmail({ coveredCwes, owaspRefs, owaspGaps, cweGaps });
    const subject = `[F1Betting] Monthly Security Review — ${new Date().toISOString().slice(0, 7)}`;

    const reportDir  = path.join(__dirname, 'security-reports');
    const reportFile = path.join(reportDir, `${new Date().toISOString().slice(0, 10)}-security-review.html`);
    if (!fs.existsSync(reportDir)) fs.mkdirSync(reportDir, { recursive: true });
    fs.writeFileSync(reportFile, html, 'utf8');
    console.log(`[security-review] Report saved → ${reportFile}`);

    if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
        console.warn('[security-review] SMTP not configured — skipping email. Report saved locally.');
        process.exit(0);
    }

    const primary  = makeSmtpSender({ host: SMTP_HOST, port: SMTP_PORT, user: SMTP_USER, pass: SMTP_PASS });
    const fallback = RESEND_API_KEY ? makeResendSender({ apiKey: RESEND_API_KEY }) : null;
    await sendEmail(primary, fallback, { from: SMTP_FROM, to: REPORT_TO, subject, html });
    console.log(`[security-review] Email sent to ${REPORT_TO}`);
}

main().catch(e => { console.error('[security-review] Fatal:', e.message); process.exit(1); });
