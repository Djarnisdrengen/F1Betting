const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../build-deploy/.env") });

const env = process.env.DEPLOY_ENV || "test";
process.env.BASE_URL = process.env[`BASE_URL_${env.toUpperCase()}`] || process.env.BASE_URL;
process.env.TEST_USER_EMAIL = process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`] || process.env.TEST_USER_EMAIL;
process.env.TEST_USER_PASSWORD = process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`] || process.env.TEST_USER_PASSWORD;

const isLive = env === "live";

module.exports = defineConfig({
    testDir: "./e2e",
    testMatch: isLive
        ? ["**/smoke.spec.js"]
        : ["**/smoke.spec.js", "**/admin.spec.js"],
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
