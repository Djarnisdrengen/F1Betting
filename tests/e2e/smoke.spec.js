const { test, expect } = require("@playwright/test");

async function login(page) {
    await page.goto("/login.php");
    await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await expect(page.locator('a[href="logout.php"]').first()).toBeVisible({ timeout: 5000 });
}

test("Public pages load", async ({ page }) => {
    for (const url of ["/", "/login.php", "/leaderboard.php", "/races.php"]) {
        const res = await page.goto(url);
        expect(res.status()).toBe(200);
    }
});

test("Login form renders", async ({ page }) => {
    await page.goto("/login.php");
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
});

test("Login succeeds", async ({ page }) => {
    await login(page);
});

test("Authenticated index visible", async ({ page }) => {
    await login(page);
    await page.goto("/");
    await expect(page.locator('a[href="logout.php"]').first()).toBeVisible();
});

test("Leaderboard has rows", async ({ page }) => {
    await page.goto("/leaderboard.php");
    await expect(page.locator("table.leaderboard-table tbody tr").first()).toBeVisible();
});

test("Races page loads", async ({ page }) => {
    await page.goto("/races.php");
    await expect(page.locator("body")).toBeVisible();
});

test("Rules page accessible", async ({ page }) => {
    await login(page);
    const res = await page.goto("/rules.php");
    expect(res.status()).toBe(200);
});

test("Bet page accessible", async ({ page }) => {
    await login(page);
    const res = await page.goto("/bet.php");
    expect(res.status()).toBe(200);
});
