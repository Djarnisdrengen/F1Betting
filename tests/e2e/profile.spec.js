const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const E2E_USER_EMAIL = "e2e_testing_testuser_f1@helvegpovlsen.dk";
const INITIAL_PW = "E2ETestPassword2026!";
const NEW_PW = "E2EProfileNewPw9!";

test.describe.serial("Profile", () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=create_e2e_user`
        );
        expect(res.status()).toBe(200);
        const body = JSON.parse(await page.textContent("body"));
        expect(body.ok).toBe(true);
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_e2e_user`
        );
        await page.close();
    });

    async function loginAsE2EUser(page, password = INITIAL_PW) {
        await page.goto("/login.php");
        await page.fill('input[name="email"]', E2E_USER_EMAIL);
        await page.fill('input[name="password"]', password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/, { timeout: 5000 });
    }

    test("bet history shows empty state when no bets placed", async ({ page }) => {
        await loginAsE2EUser(page);
        await page.goto("/profile.php");
        // The card-body with "no bets yet" text
        await expect(
            page.locator(".card-body.text-center.text-muted")
        ).toBeVisible();
    });

    // Helper to get the change-password form on profile.php
    // Uses filter() rather than CSS :has() for reliable scoping across Playwright versions
    function pwForm(page) {
        return page.locator('form').filter({ has: page.locator('input[name="current_password"]') });
    }

    test("change password — wrong current password shows error", async ({ page }) => {
        await loginAsE2EUser(page);
        await page.goto("/profile.php");

        await pwForm(page).locator('input[name="current_password"]').fill("wrong-password");
        await pwForm(page).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(page).locator('input[name="confirm_password"]').fill(NEW_PW);
        await pwForm(page).locator('button[type="submit"]').click();

        await expect(page.locator(".alert-error")).toBeVisible();
    });

    test("change password — mismatched new passwords shows error", async ({ page }) => {
        await loginAsE2EUser(page);
        await page.goto("/profile.php");

        await pwForm(page).locator('input[name="current_password"]').fill(INITIAL_PW);
        await pwForm(page).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(page).locator('input[name="confirm_password"]').fill("doesnotmatch99!");
        await pwForm(page).locator('button[type="submit"]').click();

        await expect(page.locator(".alert-error")).toBeVisible();
    });

    test("change password — success with correct inputs", async ({ page }) => {
        await loginAsE2EUser(page);
        await page.goto("/profile.php");

        await pwForm(page).locator('input[name="current_password"]').fill(INITIAL_PW);
        await pwForm(page).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(page).locator('input[name="confirm_password"]').fill(NEW_PW);
        await pwForm(page).locator('button[type="submit"]').click();

        await expect(page.locator(".alert-success")).toBeVisible();
    });

    test("can log in with new password after change", async ({ page }) => {
        await loginAsE2EUser(page, NEW_PW);
        await expect(page.locator('.desktop-only a[href="logout.php"]')).toBeVisible();
    });
});
