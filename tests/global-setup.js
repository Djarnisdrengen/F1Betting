'use strict';
const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env'), override: true });

const env = process.env.DEPLOY_ENV || 'test';
try {
    const { readPhpConfig } = require('../build-deploy/php-config');
    const cfg = readPhpConfig(env);
    process.env.BASE_URL           = process.env.BASE_URL           || cfg.siteUrl;
    process.env.TEST_USER_EMAIL    = process.env.TEST_USER_EMAIL    || cfg.adminEmail;
    process.env.TEST_USER_PASSWORD = process.env.TEST_USER_PASSWORD || cfg.adminPassword;
} catch { /* rely on pre-set env vars (e.g. GitHub Actions) */ }

const AUTH_FILE = path.join(__dirname, '../.auth/admin.json');

module.exports = async function globalSetup() {
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
