const { test, expect } = require("@playwright/test");
const path = require("path");

const ADMIN_AUTH = path.join(__dirname, "../../.auth/admin.json");

// ─── Public pages ─────────────────────────────────────────────────────────────

test.describe("Public pages", () => {
    test("pages load", async ({ page }) => {
        for (const url of ["/", "/login.php", "/leaderboard.php", "/races.php"]) {
            const res = await page.goto(url);
            expect(res.status()).toBe(200);
        }
    });

    test("login form renders", async ({ page }) => {
        await page.goto("/login.php");
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
    });

    test("leaderboard has rows", async ({ page }) => {
        await page.goto("/leaderboard.php");
        await expect(page.locator("table.leaderboard-table tbody tr").first()).toBeVisible();
    });

    test("races page loads", async ({ page }) => {
        await page.goto("/races.php");
        await expect(page.locator("body")).toBeVisible();
    });
});

// ─── Translations ─────────────────────────────────────────────────────────────

test.describe("Translations", () => {
    test("login page renders in default language (DA)", async ({ page }) => {
        await page.goto("/login.php");
        await expect(page.locator('button[type="submit"]')).toContainText("Log ind");
        await expect(page.locator(".form-label").filter({ hasText: "Adgangskode" })).toBeVisible();
    });

    test("language toggle switches DA ↔ EN", async ({ page }) => {
        await page.goto("/login.php");
        await expect(page.locator('button[type="submit"]')).toContainText("Log ind");

        await page.goto("/login.php?toggle_lang=1");
        await expect(page.locator('button[type="submit"]')).toContainText("Login");

        // restore DA so this test does not bleed into others
        await page.goto("/login.php?toggle_lang=1");
    });
});

// ─── Protected pages ──────────────────────────────────────────────────────────

test.describe("Protected pages", () => {
    test.use({ storageState: ADMIN_AUTH });

    test("admin session is active", async ({ page }) => {
        await page.goto("/");
        await expect(page.locator('.desktop-only a[href="logout.php"]')).toBeVisible();
    });

    test("authenticated index visible", async ({ page }) => {
        await page.goto("/");
        await expect(page.locator('.desktop-only a[href="logout.php"]')).toBeVisible();
    });

    test("rules page accessible", async ({ page }) => {
        const res = await page.goto("/rules.php");
        expect(res.status()).toBe(200);
    });

    test("bet page accessible", async ({ page }) => {
        const res = await page.goto("/bet.php");
        expect(res.status()).toBe(200);
    });

    test("logout clears session and shows login button", async ({ page }) => {
        await page.goto("/");
        await page.click('.desktop-only a[href="logout.php"]');
        await page.waitForURL(/index\.php/);
        await expect(page.locator('.desktop-only a[href="login.php"]')).toBeVisible();
    });
});
