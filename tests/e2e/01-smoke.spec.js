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

    test("leaderboard has rows with non-zero points", async ({ page }) => {
        await page.goto("/leaderboard.php");
        await expect(page.locator("table.leaderboard-table tbody tr").first()).toBeVisible();
        await expect(page.locator("table.leaderboard-table tbody tr").first()).toContainText(/[1-9]\d*/);
    });

    test("races page loads", async ({ page }) => {
        await page.goto("/races.php");
        await expect(page.locator("body")).toBeVisible();
    });

    test("index page renders upcoming races section", async ({ page }) => {
        await page.goto("/");
        await expect(page.locator(".races-section")).toBeVisible();
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

    test("profile page shows all section headings", async ({ page }) => {
        await page.goto("/profile.php");
        await expect(page.locator(".card-header h3").filter({ hasText: /Edit Profile|Rediger Profil/ })).toBeVisible();
        await expect(page.locator(".card-header h3").filter({ hasText: /Change Password|Skift Adgangskode/ })).toBeVisible();
        await expect(page.locator(".card-header h3").filter({ hasText: /Betting History|Din Betting Historik/ })).toBeVisible();
    });

    test("admin panel loads with races tab", async ({ page }) => {
        await page.goto("/admin.php?tab=races");
        await expect(page.locator(".card").first()).toBeVisible();
    });

});

// ─── Logout ───────────────────────────────────────────────────────────────────
// Uses a fresh login (not the shared admin session) so that logging out does
// not destroy the PHP session stored in .auth/admin.json, which all subsequent
// admin specs depend on.

test.describe("Logout", () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test("logout clears session and shows login button", async ({ page }) => {
        await page.goto("/login.php");
        await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
        await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);

        await page.click('.desktop-only a[href="logout.php"]');
        await page.waitForURL(/index\.php/);
        await expect(page.locator('.desktop-only a[href="login.php"]')).toBeVisible();
    });
});
