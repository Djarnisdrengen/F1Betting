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
    process.env.INTEGRATION_SEED_TOKEN  = process.env.INTEGRATION_SEED_TOKEN  || cfg.integrationSeedToken;
} catch { /* rely on pre-set env vars (e.g. GitHub Actions) */ }

const AUTH_FILE = path.join(__dirname, '../.auth/admin.json');

// Orchestrator-only. Unset = standalone run (today's behavior, always). Leg "1" = first
// orchestrated leg, behaves like standalone. Leg >1 = later orchestrated leg — skip the
// purge/intercept toggle (leg 1 already did it) and try to reuse the saved session.
const leg = process.env.E2E_SUITE_LEG;
const isLaterLeg = leg !== undefined && leg !== '1';

async function freshLogin() {
    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: process.env.BASE_URL });
    const page    = await context.newPage();
    await page.goto('/login.php');
    await page.fill('input[name="email"]',    process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/, { timeout: 10000 });
    // Atomic write: a leg reading AUTH_FILE concurrently never sees a half-written file.
    const tmpFile = `${AUTH_FILE}.tmp.${process.pid}`;
    await context.storageState({ path: tmpFile });
    fs.renameSync(tmpFile, AUTH_FILE);
    await browser.close();
    console.log('[setup] Admin session saved →', AUTH_FILE);
}

// One cheap authenticated request — a redirect to login.php means the cached session is
// stale. Cheaper and more reliable than trusting the file's mtime.
async function reusedSessionIsLive() {
    if (!fs.existsSync(AUTH_FILE)) return false;
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL: process.env.BASE_URL, storageState: AUTH_FILE });
        const page = await context.newPage();
        await page.goto('/profile.php');
        const stillAuthed = !/login\.php/.test(page.url());
        await context.close();
        return stillAuthed;
    } finally {
        await browser.close();
    }
}

module.exports = async function globalSetup() {
    if (env !== 'live' && !isLaterLeg) {
        await purgeInbox();
        // Real delivery is the default on the test env; turn interception ON for the duration
        // of this run so specs capture email instead of sending it. Cleanup of this toggle is
        // owned by global-teardown.js (standalone) or the orchestrator's try/finally (MUST-8).
        try {
            const url = `${process.env.BASE_URL}/tools/test-seed.php`
                + `?token=${encodeURIComponent(process.env.INTEGRATION_SEED_TOKEN)}&action=smtp_intercept_on`;
            await fetch(url);
        } catch { /* best-effort */ }
        console.log('[setup] Intercepted email log cleared, interception enabled for the run');
    }

    if (isLaterLeg && await reusedSessionIsLive()) {
        console.log('[setup] Reusing admin session from leg 1 →', AUTH_FILE);
        return;
    }

    await freshLogin();
};
