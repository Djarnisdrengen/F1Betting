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

    // ─── Notifications — access control ───────────────────────────────────────

    test.describe("notifications — access control", () => {
        test("unauthorized without token", async ({ page }) => {
            await page.goto("/cron/notifications.php");
            const text = await page.textContent("body");
            expect(text).toContain("Unauthorized access");
        });

        test("authorized with CRON_SECRET completes cleanly", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}`);
            const text = await page.textContent("body");
            expect(text).toContain("Notification check complete");
        });
    });

    // ─── Notifications — betting just opened ──────────────────────────────────
    // Race is 47h30m away with a 48h window → bettingOpens fell 30min ago (within the 1-hour trigger).
    // One user has no bet, so the cron should send an open-notification email to them.

    test.describe.serial("notifications — betting just opened", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanup = await browser.newPage();
            await cleanup.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_open`
            );
            await cleanup.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_notification_open`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok).toBe(true);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_open`
            );
            await page.close();
        });

        test("sends open notification to in-competition user, skips non-competing user", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}&test=true`);
            const text = await page.textContent("body");
            expect(text).toContain("Betting opened for: E2E Notify Open Race");
            expect(text).toContain(`Sent open notification to: ${seedData.emailCompeting}`);
            expect(text).not.toContain(seedData.emailNonCompeting);
            expect(text).toContain("Notification check complete");
        });
    });

    // ─── Notifications — betting closing soon ─────────────────────────────────
    // Race is 2h30m away → inside the 2-3h closing window.
    // User A has no bet (should receive notification).
    // User B already placed a bet (must be skipped by the cron).

    test.describe.serial("notifications — betting closing soon", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanup = await browser.newPage();
            await cleanup.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_close`
            );
            await cleanup.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_notification_close`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok).toBe(true);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_close`
            );
            await page.close();
        });

        test("sends closing notification to unbetted user, skips user with existing bet", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}&test=true`);
            const text = await page.textContent("body");
            expect(text).toContain("Betting closing soon for: E2E Notify Close Race");
            expect(text).toContain(`Sent closing notification to: ${seedData.emailUnbetted}`);
            expect(text).not.toContain(`Sent closing notification to: ${seedData.emailBetted}`);
            expect(text).toContain("Notification check complete");
        });
    });
});
