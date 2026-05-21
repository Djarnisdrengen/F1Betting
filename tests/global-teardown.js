'use strict';
const path = require('path');

require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env'), override: true });

const env = process.env.DEPLOY_ENV || 'test';
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(env);
    process.env.BASE_URL               = process.env.BASE_URL               || cfg.siteUrl;
    process.env.INTEGRATION_SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN || cfg.integrationSeedToken;
} catch { /* rely on pre-set env vars (e.g. GitHub Actions) */ }

module.exports = async function globalTeardown() {
    const EMAIL_BACKEND = process.env.EMAIL_BACKEND || 'intercept';
    if (EMAIL_BACKEND !== 'mailsac' || env === 'live') return;

    try {
        const smtpUrl = new URL('/tools/test-seed.php', process.env.BASE_URL);
        smtpUrl.searchParams.set('token', process.env.INTEGRATION_SEED_TOKEN);
        smtpUrl.searchParams.set('action', 'smtp_live_off');
        const r = await fetch(smtpUrl.toString());
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        console.log('[teardown] SMTP intercept restored');
    } catch (e) {
        console.warn('[teardown] Could not restore SMTP intercept:', e.message);
    }
};
