const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const CRON_SECRET = process.env.CRON_SECRET;

// ─── Cron jobs ────────────────────────────────────────────────────────────────

test.describe("Cron jobs", () => {
    test.describe.serial("import qualifying", () => {
        test.beforeAll(async ({ browser }) => {
            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_cron_qualifying`
            );
            expect(res.status()).toBe(200);
            const body = JSON.parse(await page.textContent("body"));
            expect(body.ok).toBe(true);
            await page.close();
        });

        test("unauthorized without token or test mode", async ({ page }) => {
            await page.goto("/cron/import_qualifying.php");
            const text = await page.textContent("body");
            expect(text).toContain("Unauthorized access");
        });

        test("test mode imports qualifying results", async ({ page }) => {
            await page.goto("/cron/import_qualifying.php?test=true");
            const text = await page.textContent("body");
            expect(text).toContain("[SUCCESS] Updated qualifying results");
            expect(text).toContain("Total races updated: 1");
        });
    });

    test.describe("notifications", () => {
        test("unauthorized without token", async ({ page }) => {
            await page.goto("/cron/notifications.php");
            const text = await page.textContent("body");
            expect(text).toContain("Unauthorized access");
        });

        test("authorized with CRON_SECRET", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}`);
            const text = await page.textContent("body");
            expect(text).toContain("Notification check complete");
        });
    });
});
