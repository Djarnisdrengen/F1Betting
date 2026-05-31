'use strict';

// Stack B standalone script — run with: node tests/email-preview.js
// When SMTP_INTERCEPT is active (test env), writes HTML files to
// build-deploy/email-previews/{timestamp}/ for local browser review.
// Keeps the 2 most recent runs (same rolling pattern as security-reports).
// Not pass/fail — exit 0 always.

const path = require('path');
const fs   = require('fs');
require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env') });

const PREVIEW_DIR = path.join(__dirname, 'email-previews');
const KEEP_RUNS   = 2;

let siteUrl, seedToken;
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(process.env.DEPLOY_ENV || 'test');
    siteUrl   = cfg.siteUrl;
    seedToken = cfg.integrationSeedToken;
} catch {
    // GitHub Actions / machines without PHP config — fall back to env vars
    siteUrl   = process.env.SITE_URL;
    seedToken = process.env.INTEGRATION_SEED_TOKEN;
}

if (!siteUrl || !seedToken) {
    console.error('email-preview: SITE_URL and INTEGRATION_SEED_TOKEN are required.');
    process.exit(1);
}

function pruneOldRuns() {
    if (!fs.existsSync(PREVIEW_DIR)) return;
    const runs = fs.readdirSync(PREVIEW_DIR, { withFileTypes: true })
        .filter(e => e.isDirectory())
        .map(e => e.name)
        .sort()
        .reverse();
    for (const old of runs.slice(KEEP_RUNS)) {
        fs.rmSync(path.join(PREVIEW_DIR, old), { recursive: true, force: true });
    }
}

async function main() {
    const url = `${siteUrl}/tools/test-seed.php?token=${encodeURIComponent(seedToken)}&action=send_email_preview`;
    console.log(`\nEndpoint: ${url}\n`);

    const res = await fetch(url, { signal: AbortSignal.timeout(120_000) });
    if (!res.ok) {
        console.error(`email-preview: server returned HTTP ${res.status}`);
        process.exit(1);
    }

    const body   = await res.json();
    const emails = body.emails ?? {};

    // Write HTML preview files if the server returned html content (SMTP_INTERCEPT mode)
    const hasHtml = Object.values(emails).some(i => i.html);
    let runDir = null;
    if (hasHtml) {
        const ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
        runDir = path.join(PREVIEW_DIR, ts);
        fs.mkdirSync(runDir, { recursive: true });
        for (const [name, info] of Object.entries(emails)) {
            if (!info.html) continue;
            fs.writeFileSync(path.join(runDir, `${name}.html`), info.html, 'utf8');
        }
        pruneOldRuns();
    }

    const lines = ['── Email preview results ──────────────────────────'];
    for (const [name, info] of Object.entries(emails)) {
        const status = info.sent ? '✓' : '✗ FAILED';
        lines.push(`\n${status}  ${name}`);
        lines.push(`   to:      ${info.to}`);
        lines.push(`   subject: ${info.subject}`);
        const skip = new Set(['sent', 'to', 'subject', 'html']);
        for (const [k, v] of Object.entries(info)) {
            if (!skip.has(k)) lines.push(`   ${k.padEnd(12)}: ${v}`);
        }
    }
    lines.push('\n───────────────────────────────────────────────────\n');
    console.log(lines.join('\n'));

    const total  = Object.keys(emails).length;
    const failed = Object.values(emails).filter(i => !i.sent).length;
    console.log(`${total - failed}/${total} emails rendered.`);
    if (failed > 0) console.log(`${failed} failed — check output above.`);

    if (runDir) {
        console.log(`\n── HTML files written to ${runDir}/ ──`);
        console.log(`Open all:\n  xdg-open ${runDir}/1_password_reset_en.html\n`);
    }
}

main().catch(err => {
    console.error('email-preview error:', err.message);
    process.exit(1);
});
