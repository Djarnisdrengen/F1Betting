'use strict';
const path = require('path');
const { makeResendSender } = require('./mailer');

// Env vars win (GitHub Actions injects them); otherwise fall back to config.<env>.php,
// the same source the app uses — so `npm run test:resend` works locally without a .env.
require('dotenv').config({ path: path.join(__dirname, '.env') });
let cfg = {};
try {
    cfg = require('./php-config').readPhpConfig(process.env.DEPLOY_ENV || 'test');
} catch { /* fall back to env vars only */ }

const RESEND_API_KEY = process.env.RESEND_API_KEY || cfg.resendApiKey;
const SMTP_FROM      = process.env.SMTP_FROM      || cfg.smtpFromEmail;
const REPORT_TO      = process.env.REPORT_TO      || cfg.reportTo || cfg.adminEmail;

if (!RESEND_API_KEY || !SMTP_FROM || !REPORT_TO) {
    console.error('[verify-resend] Missing RESEND_API_KEY / SMTP_FROM / REPORT_TO — set them as env vars or in config.' + (process.env.DEPLOY_ENV || 'test') + '.php');
    process.exit(1);
}

const date = new Date().toISOString().slice(0, 10);
const send = makeResendSender({ apiKey: RESEND_API_KEY });

send({
    from:    SMTP_FROM,
    to:      REPORT_TO,
    subject: `[F1Betting] Resend health check — ${date}`,
    html:    `<p>Nightly Resend verification. Sent at ${new Date().toISOString()}.</p>`,
}).then(() => {
    console.log('[verify-resend] OK — email delivered via Resend');
}).catch(err => {
    console.error('[verify-resend] FAILED:', err.message);
    process.exit(1);
});
