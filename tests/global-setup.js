'use strict';
const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { purgeInbox } = require('./helpers/email');

require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env'), override: true });

const env = process.env.DEPLOY_ENV || 'test';
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(env);
    process.env.BASE_URL           = process.env.BASE_URL           || cfg.siteUrl;
    process.env.TEST_USER_EMAIL    = process.env.TEST_USER_EMAIL    || cfg.adminEmail;
    process.env.TEST_USER_PASSWORD = process.env.TEST_USER_PASSWORD || cfg.adminPassword;
    process.env.MAILSAC_INBOX           = process.env.MAILSAC_INBOX           || cfg.mailsacInbox;
    process.env.INTEGRATION_SEED_TOKEN  = process.env.INTEGRATION_SEED_TOKEN  || cfg.integrationSeedToken;
    if ((process.env.EMAIL_BACKEND || 'intercept') === 'mailsac') {
        process.env.MAILSAC_API_KEY = process.env.MAILSAC_API_KEY || cfg.mailsacApiKey;
    }
} catch { /* rely on pre-set env vars (e.g. GitHub Actions) */ }

const AUTH_FILE = path.join(__dirname, '../.auth/admin.json');

const MAILSAC_INBOXES = [
    process.env.MAILSAC_INBOX || 'f1betting-preview@mailsac.com',
    'e2e_testing_invite_f1@mailsac.com',
    'e2e_bet_delete_f1@mailsac.com',
    'e2e_testing_testuser_f1@mailsac.com',
    'e2e_auth_f1@mailsac.com',
];

module.exports = async function globalSetup() {
    const EMAIL_BACKEND = process.env.EMAIL_BACKEND || 'intercept';
    if (EMAIL_BACKEND === 'mailsac' && process.env.MAILSAC_API_KEY && env !== 'live') {
        await Promise.all(MAILSAC_INBOXES.map(inbox => purgeInbox(inbox, process.env.MAILSAC_API_KEY)));
        console.log('[setup] Mailsac inboxes purged →', MAILSAC_INBOXES.join(', '));
        const smtpUrl = new URL('/tools/test-seed.php', process.env.BASE_URL);
        smtpUrl.searchParams.set('token', process.env.INTEGRATION_SEED_TOKEN);
        smtpUrl.searchParams.set('action', 'smtp_live_on');
        const r = await fetch(smtpUrl.toString());
        if (!r.ok) throw new Error(`smtp_live_on failed: HTTP ${r.status}`);
        console.log('[setup] SMTP live mode enabled — real emails will be sent');
    } else if (EMAIL_BACKEND === 'intercept' && env !== 'live') {
        await purgeInbox();
        console.log('[setup] Intercepted email log cleared');
    }

    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: process.env.BASE_URL });
    const page    = await context.newPage();
    await page.goto('/login.php');
    await page.fill('input[name="email"]',    process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/, { timeout: 10000 });
    await context.storageState({ path: AUTH_FILE });
    await browser.close();
    console.log('[setup] Admin session saved →', AUTH_FILE);
};
