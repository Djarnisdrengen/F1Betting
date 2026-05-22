const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../build-deploy/.env"), override: true });

const env = process.env.DEPLOY_ENV || "test";

// Prefer values from the PHP config file (single source of truth).
// Falls back to process.env so GitHub Actions (which injects its own vars) works unchanged.
try {
    const { readPhpConfig } = require("../build-deploy/php-config");
    const cfg = readPhpConfig(env);
    process.env.BASE_URL              = process.env.BASE_URL              || cfg.siteUrl;
    process.env.TEST_USER_EMAIL       = process.env.TEST_USER_EMAIL       || cfg.adminEmail;
    process.env.TEST_USER_PASSWORD    = process.env.TEST_USER_PASSWORD    || cfg.adminPassword;
    process.env.INTEGRATION_SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN || cfg.integrationSeedToken;
    process.env.CRON_SECRET           = process.env.CRON_SECRET           || cfg.cronSecret;
    if (cfg.smtpFromEmail) {
        const domain = cfg.smtpFromEmail.split('@')[1];
        if (domain) process.env.SMTP_FROM_DOMAIN = process.env.SMTP_FROM_DOMAIN || domain;
    }
    // API key only needed for real Mailsac delivery checks.
    if ((process.env.EMAIL_BACKEND || 'intercept') === 'mailsac') {
        process.env.MAILSAC_API_KEY = process.env.MAILSAC_API_KEY || cfg.mailsacApiKey;
    }
} catch {
    // PHP config not available — rely on pre-set environment variables (e.g. GitHub Actions).
    process.env.BASE_URL           = process.env[`BASE_URL_${env.toUpperCase()}`]           || process.env.BASE_URL;
    process.env.TEST_USER_EMAIL    = process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`]    || process.env.TEST_USER_EMAIL;
    process.env.TEST_USER_PASSWORD = process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`] || process.env.TEST_USER_PASSWORD;
}

const isLive = env === "live";

module.exports = defineConfig({
    globalSetup: require.resolve("./global-setup"),
    globalTeardown: require.resolve("./global-teardown"),
    testDir: "./e2e",
    testMatch: isLive
        ? ["**/01-smoke.spec.js"]
        : ["**/*.spec.js"],
    timeout: 10000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: process.env.BASE_URL,
        screenshot: "only-on-failure",
    },
    workers: 1,
    projects: [
        { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    ],
});
