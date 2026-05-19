const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;

// ─── Invalid / missing token ───────────────────────────────────────────────

test.describe("Registration — invalid token", () => {
    test("no token shows invite required error", async ({ page }) => {
        await page.goto("/register.php");
        await expect(page.locator(".alert-error")).toBeVisible();
        // Form should not be rendered without a valid token
        await expect(page.locator('input[name="password"]')).toHaveCount(0);
    });

    test("expired / unknown token shows invalid invite error", async ({ page }) => {
        await page.goto("/register.php?token=notarealtokenabc123");
        await expect(page.locator(".alert-error")).toBeVisible();
        await expect(page.locator('input[name="password"]')).toHaveCount(0);
    });
});

// ─── Valid invite flow ─────────────────────────────────────────────────────

test.describe.serial("Registration — valid invite", () => {
    let inviteToken;
    let inviteEmail;

    test.beforeAll(async ({ browser }) => {
        // Idempotent cleanup from any previous failed run
        const cleanPage = await browser.newPage();
        await cleanPage.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_register`
        );
        await cleanPage.close();

        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_register_invite`
        );
        expect(res.status()).toBe(200);
        const body = JSON.parse(await page.textContent("body"));
        expect(body.ok).toBe(true);
        inviteToken = body.token;
        inviteEmail = body.email;
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_register`
        );
        await page.close();
    });

    test("register form renders with email pre-filled from invite", async ({ page }) => {
        await page.goto(`/register.php?token=${inviteToken}`);
        await expect(page.locator('input[name="email"]')).toHaveValue(inviteEmail);
        await expect(page.locator('input[name="password"]')).toBeVisible();
    });

    test("register with valid invite creates account and logs in", async ({ page }) => {
        await page.goto(`/register.php?token=${inviteToken}`);
        await page.fill('input[name="display_name"]', "E2E Registered User");
        await page.fill('input[name="password"]', "E2ERegPassword2026!");
        await page.click('button[type="submit"]');

        await page.waitForURL(/success=welcome/);
        // Should be logged in — logout link visible in desktop controls
        await expect(page.locator('.desktop-only a[href="logout.php"]')).toBeVisible();
    });

    test("used invite token cannot be reused", async ({ page }) => {
        await page.goto(`/register.php?token=${inviteToken}`);
        await expect(page.locator(".alert-error")).toBeVisible();
        await expect(page.locator('input[name="password"]')).toHaveCount(0);
    });
});
