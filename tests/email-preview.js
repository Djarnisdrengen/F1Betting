'use strict';

// Stack B standalone script — run with: node tests/email-preview.js
// Sends all email types (DA + EN) to MAILSAC_INBOX for manual visual review.
// Not pass/fail — exit 0 always. A human reads the Mailsac inbox to verify templates.

const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env') });

let siteUrl, seedToken, mailsacInbox;
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(process.env.DEPLOY_ENV || 'test');
    siteUrl      = cfg.siteUrl;
    seedToken    = cfg.integrationSeedToken;
    mailsacInbox = cfg.mailsacInbox;
} catch {
    // GitHub Actions / machines without PHP config — fall back to env vars
    siteUrl      = process.env.SITE_URL;
    seedToken    = process.env.INTEGRATION_SEED_TOKEN;
    mailsacInbox = process.env.MAILSAC_INBOX;
}

if (!siteUrl || !seedToken) {
    console.error('email-preview: SITE_URL and INTEGRATION_SEED_TOKEN are required.');
    process.exit(1);
}

async function main() {
    const url = `${siteUrl}/tools/test-seed.php?token=${encodeURIComponent(seedToken)}&action=send_email_preview`;
    console.log(`\nSending email preview to: ${mailsacInbox ?? '(MAILSAC_INBOX not set)'}`);
    console.log(`Endpoint: ${url}\n`);

    const res = await fetch(url, { signal: AbortSignal.timeout(120_000) });
    if (!res.ok) {
        console.error(`email-preview: server returned HTTP ${res.status}`);
        process.exit(1);
    }

    const body = await res.json();

    const lines = ['── Email preview results ──────────────────────────'];
    for (const [name, info] of Object.entries(body.emails ?? {})) {
        const status = info.sent ? '✓ SENT  ' : '✗ FAILED';
        lines.push(`\n${status}  ${name}`);
        lines.push(`   to:      ${info.to}`);
        lines.push(`   subject: ${info.subject}`);
        const skip = new Set(['sent', 'to', 'subject']);
        for (const [k, v] of Object.entries(info)) {
            if (!skip.has(k)) lines.push(`   ${k.padEnd(12)}: ${v}`);
        }
    }
    lines.push('\n───────────────────────────────────────────────────\n');
    console.log(lines.join('\n'));

    const total  = Object.keys(body.emails ?? {}).length;
    const failed = Object.values(body.emails ?? {}).filter(i => !i.sent).length;
    console.log(`${total - failed}/${total} emails accepted by SMTP.`);
    if (failed > 0) console.log(`${failed} failed — check output above.`);
}

main().catch(err => {
    console.error('email-preview error:', err.message);
    process.exit(1);
});
