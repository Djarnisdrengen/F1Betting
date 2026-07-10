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
} catch {
    // PHP config not available — rely on pre-set environment variables (e.g. GitHub Actions).
    process.env.BASE_URL           = process.env[`BASE_URL_${env.toUpperCase()}`]           || process.env.BASE_URL;
    process.env.TEST_USER_EMAIL    = process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`]    || process.env.TEST_USER_EMAIL;
    process.env.TEST_USER_PASSWORD = process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`] || process.env.TEST_USER_PASSWORD;
}

const isLive = env === "live";

// Reporter is human-readable by default. The orchestrator (run-e2e-suites.js) sets
// E2E_LEG_JSON_OUTPUT per leg so it can distinguish "leg crashed" from "tests failed"
// without parsing stdout (MUST-2) — standalone/manual runs never set this.
const reporters = [["./reporter.js"]];
if (process.env.E2E_LEG_JSON_OUTPUT) {
    reporters.push(["json", { outputFile: process.env.E2E_LEG_JSON_OUTPUT }]);
}

// 11 UX-oriented suites (see epics/Optimize test suite structure/epic-e2e-test-restructure.md
// for the taxonomy) partition all tests via Playwright's native `{ tag }` — zero file moves.
// `mobile` is a secondary, standalone-only cross-cutting suite that reuses 3 already-counted
// tests (excluded from the primary list on purpose — see the epic's "Resolved Contradictions").
// `all` has no grep — it is (a) the orchestrator's raw baseline for the orphan/drift check
// (MUST-1) and (b) what `test:e2e:test:legacy` runs, so the pre-refactor behavior stays
// reachable byte-for-byte regardless of how the tagged projects evolve.
const PRIMARY_SUITES = [
    "smoke", "auth", "registration", "predictions", "scoring", "race-page",
    "admin", "profile", "appearance", "preferences-editor", "cron",
];

module.exports = defineConfig({
    globalSetup: require.resolve("./global-setup"),
    globalTeardown: require.resolve("./global-teardown"),
    testDir: "./e2e",
    testMatch: isLive
        ? ["**/01-smoke.spec.js"]
        : ["**/*.spec.js"],
    timeout: 10000,
    outputDir: "../build-deploy/screenshots",
    reporter: reporters,
    use: {
        baseURL: process.env.BASE_URL,
        screenshot: "only-on-failure",
    },
    workers: 1,
    projects: [
        ...PRIMARY_SUITES.map(slug => ({
            name: slug,
            grep: new RegExp(`@${slug}\\b`),
            use: { ...devices["Desktop Chrome"] },
        })),
        { name: "mobile", grep: /@mobile\b/, use: { ...devices["Desktop Chrome"] } },
        { name: "all", use: { ...devices["Desktop Chrome"] } },
    ],
});
