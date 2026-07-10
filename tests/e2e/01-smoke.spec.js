const { test, expect } = require("@playwright/test");
const path = require("path");

const ADMIN_AUTH = path.join(__dirname, "../../.auth/admin.json");

// ─── Public pages ─────────────────────────────────────────────────────────────

test.describe("Public pages", { tag: "@smoke" }, () => {
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
        await expect(page.locator(".hf-row").first()).toBeVisible();
        await expect(page.locator(".hf-row").first()).toContainText(/[1-9]\d*/);
    });

    test("races page loads", async ({ page }) => {
        await page.goto("/races.php");
        await expect(page.locator("body")).toBeVisible();
    });

    test("index page renders upcoming races section", async ({ page }) => {
        await page.goto("/");
        await expect(page.locator('[data-testid="home-results"]')).toBeVisible();
    });

    // Banner is gated server-side by APP_ENV === 'test' (header.php); this is
    // the only spec the live testMatch gate runs, so the absence branch is the
    // deploy:live safety net (deploy.js rolls back on failure).
    test("test-environment banner: present on test, absent on live", async ({ page }) => {
        await page.goto("/");
        const banner = page.locator(".test-banner");
        if (process.env.DEPLOY_ENV === "live") {
            // Guard against env mix-ups: only trust an "absent" pass if we are
            // genuinely pointed at the live host.
            expect(new URL(page.url()).hostname).toContain("formula-1.dk");
            await expect(banner).toHaveCount(0);
        } else {
            // Exact strings, both languages: t() falls back to the raw key, so a
            // missing i18n entry must fail here, not render "test_site_banner".
            await expect(banner).toBeVisible();
            await expect(banner).toContainText(/Dette er en testhjemmeside|This is a test website/);
        }
    });
});

// ─── Translations ─────────────────────────────────────────────────────────────

test.describe("Translations", { tag: "@smoke" }, () => {
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

    test("font toggle switches SYS ↔ EDIT and persists via session", async ({ page }) => {
        await page.goto("/login.php");
        await expect(page.locator('body')).toHaveClass(/font-system/);

        await page.goto("/login.php?toggle_font=1");
        await expect(page.locator('body')).toHaveClass(/font-editorial/);

        // restore so session state does not bleed into other tests
        await page.goto("/login.php?toggle_font=1");
        await expect(page.locator('body')).toHaveClass(/font-system/);
    });
});

// ─── Protected pages ──────────────────────────────────────────────────────────

test.describe("Protected pages", { tag: "@smoke" }, () => {
    test.use({ storageState: ADMIN_AUTH });

    test("authenticated index visible", async ({ page }) => {
        await page.goto("/");
        await page.click('.hf-hamburger');
        await expect(page.locator('a[href="logout.php"]')).toBeVisible();
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
        await expect(page.locator('[data-testid="tab-profile-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="tab-security-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="tab-preferences-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="tab-history-btn"]')).toBeVisible();
        // Betting history now lives in its own tab — open it before asserting the heading.
        await page.click('[data-testid="tab-history-btn"]');
        await expect(page.locator("h3").filter({ hasText: /Betting History|Din Betting Historik/ })).toBeVisible();
    });

    test("admin panel loads with races tab", async ({ page }) => {
        await page.goto("/admin.php?tab=races");
        await expect(page.locator(".card").first()).toBeVisible();
    });

    test("bottom bar visible on authenticated pages", async ({ page }) => {
        await page.goto("/");
        await expect(page.locator('.hf-bottom')).toBeVisible();
    });

});

// ─── Logout ───────────────────────────────────────────────────────────────────
// Uses a fresh login (not the shared admin session) so that logging out does
// not destroy the PHP session stored in .auth/admin.json, which all subsequent
// admin specs depend on.

test.describe("Logout", { tag: "@smoke" }, () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test("logout clears session and shows login button", async ({ page }) => {
        await page.goto("/login.php");
        await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
        await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);

        await page.click('.hf-hamburger');
        await page.click('a[href="logout.php"]');
        await page.waitForURL(/index\.php/);
        await expect(page.locator('.hf-bottom a[href="login.php"]')).toBeVisible();
    });
});
