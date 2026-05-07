const { defineConfig, devices } = require("@playwright/test");
require("dotenv").config({ path: require("path").join(__dirname, "../build-deploy/.env") });

module.exports = defineConfig({
    testDir: "./e2e",
    testMatch: "**/integration.spec.js",
    timeout: 15000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: process.env.INTEGRATION_BASE_URL,
        screenshot: "only-on-failure",
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
    workers: 1,
});
