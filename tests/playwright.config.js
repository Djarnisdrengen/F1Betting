const { defineConfig, devices } = require("@playwright/test");

module.exports = defineConfig({
    testDir: "./e2e",
    timeout: 10000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: process.env.BASE_URL,
        screenshot: "only-on-failure",
    },
    projects: [
        { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    ],
});
