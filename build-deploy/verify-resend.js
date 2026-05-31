'use strict';
const { makeResendSender } = require('./mailer');

const RESEND_API_KEY = process.env.RESEND_API_KEY;
const SMTP_FROM      = process.env.SMTP_FROM;
const REPORT_TO      = process.env.REPORT_TO;

if (!RESEND_API_KEY || !SMTP_FROM || !REPORT_TO) {
    console.error('[verify-resend] Missing required env vars: RESEND_API_KEY, SMTP_FROM, REPORT_TO');
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
