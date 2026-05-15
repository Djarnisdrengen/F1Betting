const { defineConfig, devices } = require("@playwright/test");
require("dotenv").config({ path: require("path").join(__dirname, "../build-deploy/.env") });

const { readPhpConfig } = require("../build-deploy/php-config");
const cfg = readPhpConfig("test");
process.env.INTEGRATION_SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN || cfg.integrationSeedToken;

module.exports = defineConfig({
    testDir: "./e2e",
    testMatch: "**/integration.spec.js",
    timeout: 15000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: cfg.siteUrl,
        screenshot: "only-on-failure",
        userAgent: "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        extraHTTPHeaders: {
            "Accept-Language": "en-US,en;q=0.9",
        },
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
    workers: 1,
});
