'use strict';
const path = require('path');

require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env'), override: true });

const env = process.env.DEPLOY_ENV || 'test';
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(env);
    process.env.BASE_URL               = process.env.BASE_URL               || cfg.siteUrl;
    process.env.INTEGRATION_SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN || cfg.integrationSeedToken;
} catch { /* rely on env vars set during the run */ }

module.exports = async function globalTeardown() {
    // Restore the test env's default of real delivery — interception was only on for this run.
    if (env !== 'live') {
        try {
            const url = `${process.env.BASE_URL}/tools/test-seed.php`
                + `?token=${encodeURIComponent(process.env.INTEGRATION_SEED_TOKEN)}&action=smtp_intercept_off`;
            await fetch(url);
            console.log('[teardown] Interception disabled — real delivery restored');
        } catch { /* best-effort */ }
    }
};
