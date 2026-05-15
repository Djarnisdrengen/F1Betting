const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
let seedData; // { raceId, email, password, drivers: [{id, name}] }

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
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_betting_race`
        );
        await page.close();
    });

    async function loginAsBetUser(page) {
        await page.goto("/login.php");
        await page.fill('input[name="email"]', seedData.email);
        await page.fill('input[name="password"]', seedData.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/, { timeout: 5000 });
    }

    test("place a bet", async ({ page }) => {
        await loginAsBetUser(page);
        await page.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        // Fail fast if bet.php redirected away (closed window, wrong race id, not in competition)
        await expect(page).toHaveURL(/bet\.php/);

        await page.selectOption('select[name="p1"]', seedData.drivers[0].id);
        await page.selectOption('select[name="p2"]', seedData.drivers[1].id);
        await page.selectOption('select[name="p3"]', seedData.drivers[2].id);
        await page.locator('button[type="submit"]').click();

        await page.waitForURL(/success=bet_placed/);
        await expect(page.locator(".alert-success")).toBeVisible();
    });

    test("attempting to bet again redirects with already_bet error", async ({ page }) => {
        await loginAsBetUser(page);
        await page.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        await page.waitForURL(/already_bet/);
    });

    test("edit a bet", async ({ page }) => {
        await loginAsBetUser(page);
        await page.goto("/");

        const editLink = page.locator('a[href*="edit_bet.php"]').first();
        await expect(editLink).toBeVisible();
        await editLink.click();
        await page.waitForURL(/edit_bet\.php/);

        // Swap P1 and P3 — different combo, still valid
        await page.selectOption('select[name="p1"]', seedData.drivers[2].id);
        await page.selectOption('select[name="p2"]', seedData.drivers[1].id);
        await page.selectOption('select[name="p3"]', seedData.drivers[0].id);
        await page.locator('button[type="submit"]').click();

        await page.waitForURL(/success=bet_updated/);
        await expect(page.locator(".alert-success")).toBeVisible();
    });

    test("same driver in two positions shows validation error", async ({ page }) => {
        await loginAsBetUser(page);
        await page.goto("/");

        const editLink = page.locator('a[href*="edit_bet.php"]').first();
        await editLink.click();
        await page.waitForURL(/edit_bet\.php/);

        await page.selectOption('select[name="p1"]', seedData.drivers[0].id);
        await page.selectOption('select[name="p2"]', seedData.drivers[0].id); // duplicate
        await page.selectOption('select[name="p3"]', seedData.drivers[2].id);
        await page.locator('button[type="submit"]').click();

        await expect(page.locator(".alert-error")).toBeVisible();
    });
});
