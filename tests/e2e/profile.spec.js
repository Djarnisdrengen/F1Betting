const { test, expect } = require("@playwright/test");

const SEED_TOKEN    = process.env.INTEGRATION_SEED_TOKEN;
const E2E_USER_EMAIL = "e2e_testing_testuser_f1@helvegpovlsen.dk";
const INITIAL_PW    = "E2ETestPassword2026!";
const NEW_PW        = "E2EProfileNewPw9!";

let sharedContext;
let sharedPage;

function pwForm(page) {
    return page.locator('form').filter({ has: page.locator('input[name="current_password"]') });
}

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

        // Log in once — tests 1-4 reuse this session
        sharedContext = await browser.newContext();
        sharedPage    = await sharedContext.newPage();
        await sharedPage.goto("/login.php");
        await sharedPage.fill('input[name="email"]',    E2E_USER_EMAIL);
        await sharedPage.fill('input[name="password"]', INITIAL_PW);
        await sharedPage.click('button[type="submit"]');
        await sharedPage.waitForURL(/index\.php/, { timeout: 5000 });
    });

    test.afterAll(async ({ browser }) => {
        await sharedContext?.close();
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_e2e_user`
        );
        await page.close();
    });

    test("bet history shows empty state when no bets placed", async () => {
        await sharedPage.goto("/profile.php");
        await expect(sharedPage.locator(".card-body.text-center.text-muted")).toBeVisible();
    });

    test("change password — wrong current password shows error", async () => {
        await sharedPage.goto("/profile.php");

        await pwForm(sharedPage).locator('input[name="current_password"]').fill("wrong-password");
        await pwForm(sharedPage).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('input[name="confirm_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('button[type="submit"]').click();

        await expect(sharedPage.locator(".alert-error")).toBeVisible();
    });

    test("change password — mismatched new passwords shows error", async () => {
        await sharedPage.goto("/profile.php");

        await pwForm(sharedPage).locator('input[name="current_password"]').fill(INITIAL_PW);
        await pwForm(sharedPage).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('input[name="confirm_password"]').fill("doesnotmatch99!");
        await pwForm(sharedPage).locator('button[type="submit"]').click();

        await expect(sharedPage.locator(".alert-error")).toBeVisible();
    });

    test("change password — success with correct inputs", async () => {
        await sharedPage.goto("/profile.php");

        await pwForm(sharedPage).locator('input[name="current_password"]').fill(INITIAL_PW);
        await pwForm(sharedPage).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('input[name="confirm_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('button[type="submit"]').click();

        await expect(sharedPage.locator(".alert-success")).toBeVisible();
    });

    // Fresh context required: login.php redirects authenticated users.
    test("can log in with new password after change", async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto("/login.php");
        await page.fill('input[name="email"]',    E2E_USER_EMAIL);
        await page.fill('input[name="password"]', NEW_PW);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/, { timeout: 5000 });
        await expect(page.locator('.desktop-only a[href="logout.php"]')).toBeVisible();
        await ctx.close();
    });
});
