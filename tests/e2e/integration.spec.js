const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;

test.beforeAll(async ({ request }) => {
    const res = await request.get(`/test-seed.php?token=${SEED_TOKEN}`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.ok).toBe(true);
});

test("Leaderboard row order", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const rows = page.locator("table.leaderboard-table tbody tr");
    await expect(rows.nth(0)).toContainText("Alice");
    await expect(rows.nth(1)).toContainText("Bob");
    await expect(rows.nth(2)).toContainText("Charlie");
});

test("Alice — 220 points, 1 star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(0);
    await expect(row.locator("span.text-accent")).toHaveText("220");
    await expect(row.locator("span.star")).toHaveText("★1");
});

test("Bob — 140 points, no star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(1);
    await expect(row.locator("span.text-accent")).toHaveText("140");
    await expect(row.locator("span.star")).toHaveCount(0);
});

test("Charlie — 65 points, no star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(2);
    await expect(row.locator("span.text-accent")).toHaveText("65");
    await expect(row.locator("span.star")).toHaveCount(0);
});

test("Race 2 pool size is 60", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 2" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("60");
});

test("Race 3 pool size is 90", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 3" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("90");
});

test("Race 4 pool size is 30 — reset after perfect bet", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 4" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("30");
});

test("Race 5 pool size is 60", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 5" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("60");
});
