const { test, expect } = require("@playwright/test");
const path = require("path");

const ADMIN_AUTH = path.join(__dirname, "../../.auth/admin.json");

// Test-environment banner (epics/design_handoff_test_banner). Server-side gated
// by APP_ENV === 'test' in header.php, so this whole spec only makes sense on
// the test env — which is also the only env whose testMatch runs it.

const BANNER_TEXT = /Dette er en testhjemmeside|This is a test website/;

test.describe("Test-environment banner", () => {
    test.skip(process.env.DEPLOY_ENV === "live", "banner is test-env only");

    test("renders on public pages (AC-TB-01)", async ({ page }) => {
        for (const url of ["/", "/login.php", "/races.php"]) {
            await page.goto(url);
            await expect(page.locator(".test-banner")).toBeVisible();
            await expect(page.locator(".test-banner")).toContainText(BANNER_TEXT);
        }
    });

    test("stays pinned directly below the header on scroll (AC-TB-02)", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 480 });
        await page.goto("/races.php");

        const header = page.locator(".hf-top");
        const banner = page.locator(".test-banner");

        await page.evaluate(() => window.scrollTo(0, 1000));
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);

        const headerBox = await header.boundingBox();
        const bannerBox = await banner.boundingBox();
        expect(headerBox.y).toBe(0);
        // Banner pins at top:56px = header height; header and banner move as one unit.
        expect(Math.round(bannerBox.y)).toBe(Math.round(headerBox.y + headerBox.height));
    });

    test("no horizontal scroll and single-line plate at 320px (AC-TB-05, AC-TB-06)", async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 568 });
        await page.goto("/");

        const hasHScroll = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth
        );
        expect(hasHScroll).toBe(false);

        // A wrapped plate would be ~2 line-heights tall; single line stays well under 36px.
        const plateBox = await page.locator(".test-banner-plate").boundingBox();
        expect(plateBox.height).toBeLessThan(36);
    });

    test("open mobile drawer stacks above the banner (AC-TB-06)", async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 700 });
        await page.goto("/");
        await page.click(".hf-hamburger");
        await expect(page.locator(".hf-drawer")).toBeVisible();

        const [drawerZ, bannerZ] = await page.evaluate(() => [
            parseInt(getComputedStyle(document.querySelector(".hf-drawer")).zIndex, 10),
            parseInt(getComputedStyle(document.querySelector(".test-banner")).zIndex, 10),
        ]);
        expect(drawerZ).toBeGreaterThan(bannerZ);
    });
});

test.describe("Test-environment banner — admin pages", () => {
    test.skip(process.env.DEPLOY_ENV === "live", "banner is test-env only");
    test.use({ storageState: ADMIN_AUTH });

    test("renders on the admin panel (AC-TB-01)", async ({ page }) => {
        await page.goto("/admin.php?tab=races");
        await expect(page.locator(".test-banner")).toBeVisible();
        await expect(page.locator(".test-banner")).toContainText(BANNER_TEXT);
    });
});
