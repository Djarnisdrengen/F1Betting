const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
let seedData;
let sharedContext;
let sharedPage;

test.describe.serial("Betting", () => {
    test.beforeAll(async ({ browser }) => {
        // Idempotent cleanup in case a previous run left state
        const cleanPage = await browser.newPage();
        await cleanPage.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_betting_race`
        );
        await cleanPage.close();

        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_betting_race`
        );
        expect(res.status()).toBe(200);
        seedData = JSON.parse(await page.textContent("body"));
        expect(seedData.ok).toBe(true);
        expect(seedData.drivers).toHaveLength(3);
        await page.close();

        // Log in once — all tests reuse this session
        sharedContext = await browser.newContext();
        sharedPage    = await sharedContext.newPage();
        await sharedPage.goto("/login.php");
        await sharedPage.fill('input[name="email"]',    seedData.email);
        await sharedPage.fill('input[name="password"]', seedData.password);
        await sharedPage.click('button[type="submit"]');
        await sharedPage.waitForURL(/index\.php/, { timeout: 5000 });
    });

    test.afterAll(async ({ browser }) => {
        await sharedContext?.close();
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_betting_race`
        );
        await page.close();
    });

    test("place a bet", async () => {
        await sharedPage.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        await expect(sharedPage.locator('.hf-modal-overlay')).toBeVisible();

        // P1 slot is auto-active on open; pick drivers in order (auto-advance P1→P2→P3)
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[0].id}"]`);
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[1].id}"]`);
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[2].id}"]`);
        await sharedPage.click('[data-link="saveBet"]');

        await sharedPage.waitForURL(/success=bet_placed/);
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
    });

    test("attempting to bet again redirects with already_bet error", async () => {
        await sharedPage.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        await sharedPage.waitForURL(/already_bet/);
    });

    test("edit a bet", async () => {
        await sharedPage.goto("/");

        const editLink = sharedPage.locator('a[href*="edit_bet.php"]').first();
        await expect(editLink).toBeVisible();
        await editLink.click();
        await sharedPage.waitForURL(/edit_bet\.php/);

        // All positions pre-filled — activate each slot explicitly before picking
        await sharedPage.click('[data-link="activateSlot"][data-pos="1"]');
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[2].id}"]`);
        await sharedPage.click('[data-link="activateSlot"][data-pos="2"]');
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[1].id}"]`);
        await sharedPage.click('[data-link="activateSlot"][data-pos="3"]');
        await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[0].id}"]`);
        await sharedPage.click('[data-link="saveBet"]');

        await sharedPage.waitForURL(/success=bet_updated/);
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
    });
});
