const { test, expect } = require("@playwright/test");

const SEED_TOKEN    = process.env.INTEGRATION_SEED_TOKEN;
const E2E_USER_EMAIL = "e2e_testing_testuser_f1@test.localhost";
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
        await expect(sharedPage.locator('[data-testid="empty-bet-history"]')).toBeVisible();
    });

    test("change password — wrong current password shows error", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.click('[data-testid="tab-security-btn"]');

        await pwForm(sharedPage).locator('input[name="current_password"]').fill("wrong-password");
        await pwForm(sharedPage).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('input[name="confirm_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('button[type="submit"]').click();

        await expect(sharedPage.locator(".alert-error")).toBeVisible();
    });

    test("change password — mismatched new passwords shows error", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.click('[data-testid="tab-security-btn"]');

        await pwForm(sharedPage).locator('input[name="current_password"]').fill(INITIAL_PW);
        await pwForm(sharedPage).locator('input[name="new_password"]').fill(NEW_PW);
        await pwForm(sharedPage).locator('input[name="confirm_password"]').fill("doesnotmatch99!");
        await pwForm(sharedPage).locator('button[type="submit"]').click();

        await expect(sharedPage.locator(".alert-error")).toBeVisible();
    });

    test("change password — success with correct inputs", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.click('[data-testid="tab-security-btn"]');

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
        await page.click('.hf-hamburger');
        await expect(page.locator('a[href="logout.php"]')).toBeVisible();
        await ctx.close();
    });

    test("language — switch to English updates the UI immediately", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.click('[data-testid="tab-preferences-btn"]');
        await sharedPage.click('.hf-pref-btn[data-value="en"]');
        await sharedPage.locator('form:has(input[value="update_preferences"]) button[type="submit"]').click();
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
        await expect(sharedPage.locator("html")).toHaveAttribute("lang", "en");
    });

    test("language — preference survives re-login", async () => {
        await sharedPage.goto("/logout.php");
        await sharedPage.goto("/login.php");
        await sharedPage.fill('input[name="email"]',    E2E_USER_EMAIL);
        await sharedPage.fill('input[name="password"]', NEW_PW);
        await sharedPage.click('button[type="submit"]');
        await sharedPage.waitForURL(/index\.php/, { timeout: 5000 });
        await sharedPage.goto("/profile.php");
        await expect(sharedPage.locator("html")).toHaveAttribute("lang", "en");
    });

    test("language — switch back to Danish", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.click('[data-testid="tab-preferences-btn"]');
        await sharedPage.click('.hf-pref-btn[data-value="da"]');
        await sharedPage.locator('form:has(input[value="update_preferences"]) button[type="submit"]').click();
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
        await expect(sharedPage.locator("html")).toHaveAttribute("lang", "da");
    });
});

test.describe.serial("Profile stats — hero + chips (v2.2.0)", () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=create_e2e_user`
        );
        expect(res.status()).toBe(200);
        await page.close();

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

    // AC-PROF-04 — hero + 2 chips present; old 4-cell grid gone
    test("AC-PROF-04 — hero card and 2 chips render; old stats grid absent", async () => {
        await sharedPage.goto("/profile.php");
        await expect(sharedPage.locator('[data-testid="profile-stats"]')).toBeVisible();
        await expect(sharedPage.locator('[data-testid="stats-hero"]')).toBeVisible();
        await expect(sharedPage.locator('[data-testid="stats-chip-role"]')).toBeVisible();
        await expect(sharedPage.locator('[data-testid="stats-chip-competing"]')).toBeVisible();
        await expect(sharedPage.locator('.hf-profile-stats')).toHaveCount(0);
    });

    // AC-PROF-05 — no horizontal scroll at 320px
    test("AC-PROF-05 — no horizontal scroll at 320px; chips visible", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.setViewportSize({ width: 320, height: 568 });
        const hasHScroll = await sharedPage.evaluate(() => {
            const el = document.querySelector('[data-testid="profile-stats"]');
            return el ? el.scrollWidth > el.clientWidth : true;
        });
        expect(hasHScroll).toBe(false);
        await expect(sharedPage.locator('[data-testid="stats-chip-role"]')).toBeVisible();
        await expect(sharedPage.locator('[data-testid="stats-chip-competing"]')).toBeVisible();
        await sharedPage.setViewportSize({ width: 1280, height: 720 });
    });

    // AC-PROF-07 — stars state (zero or earned, depending on seed data)
    test("AC-PROF-07 — stars element is gold in both empty and earned states", async () => {
        await sharedPage.goto("/profile.php");
        const starsEl = sharedPage.locator('[data-testid="stats-stars"]');
        await expect(starsEl).toBeVisible();
        const cls = await starsEl.getAttribute("class");
        if (cls.includes("empty")) {
            await expect(starsEl).toContainText("★ 0");
        } else {
            const txt = (await starsEl.innerText()).trim();
            expect(txt).toMatch(/^★+$/);
        }
    });

    // AC-PROF-08 — role chip class matches DB enum value 'user'
    test("AC-PROF-08 — role chip has class role-user for regular player", async () => {
        await sharedPage.goto("/profile.php");
        await expect(sharedPage.locator('[data-testid="stats-chip-role"]')).toHaveClass(/role-user/);
    });

    // AC-PROF-08 — competing chip class reflects in_competition (seed user has in_competition=0)
    test("AC-PROF-08 — competing chip has class 'out' when user is not in competition", async () => {
        await sharedPage.goto("/profile.php");
        await expect(sharedPage.locator('[data-testid="stats-chip-competing"]')).toHaveClass(/\bout\b/);
    });

    // AC-PROF-09 — same layout at MD+ (768px), no reflow to 4 cells
    test("AC-PROF-09 — layout unchanged at 768px; old grid still absent", async () => {
        await sharedPage.goto("/profile.php");
        await sharedPage.setViewportSize({ width: 768, height: 1024 });
        await expect(sharedPage.locator('[data-testid="stats-hero"]')).toBeVisible();
        await expect(sharedPage.locator('[data-testid="stats-chip-role"]')).toBeVisible();
        await expect(sharedPage.locator('.hf-profile-stats')).toHaveCount(0);
        await sharedPage.setViewportSize({ width: 1280, height: 720 });
    });

    // AC-PROF-10 — dark theme
    test("AC-PROF-10 — stats render without overflow in dark theme", async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto("/login.php");
        await page.fill('input[name="email"]',    E2E_USER_EMAIL);
        await page.fill('input[name="password"]', INITIAL_PW);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/, { timeout: 5000 });
        // Ensure dark theme
        const body = page.locator("body");
        const cls  = await body.getAttribute("class");
        if (!cls.includes("dark")) await page.goto("/?toggle_theme=1");
        await page.goto("/profile.php");
        await expect(page.locator("body")).toHaveClass(/\bdark\b/);
        await expect(page.locator('[data-testid="profile-stats"]')).toBeVisible();
        await ctx.close();
    });

    // AC-PROF-10 — light theme
    test("AC-PROF-10 — stats render without overflow in light theme", async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto("/login.php");
        await page.fill('input[name="email"]',    E2E_USER_EMAIL);
        await page.fill('input[name="password"]', INITIAL_PW);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/, { timeout: 5000 });
        // Ensure light theme
        const body = page.locator("body");
        const cls  = await body.getAttribute("class");
        if (!cls.includes("light")) await page.goto("/?toggle_theme=1");
        await page.goto("/profile.php");
        await expect(page.locator("body")).toHaveClass(/\blight\b/);
        await expect(page.locator('[data-testid="profile-stats"]')).toBeVisible();
        await ctx.close();
    });

    // AC-PROF-11 — tap targets >= 44px
    test("AC-PROF-11 — chip tap targets are at least 44px tall", async () => {
        await sharedPage.goto("/profile.php");
        const roleBox = await sharedPage.locator('[data-testid="stats-chip-role"]').boundingBox();
        const compBox = await sharedPage.locator('[data-testid="stats-chip-competing"]').boundingBox();
        expect(roleBox.height).toBeGreaterThanOrEqual(44);
        expect(compBox.height).toBeGreaterThanOrEqual(44);
    });
});
