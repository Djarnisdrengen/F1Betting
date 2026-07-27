'use strict';
const { test, expect } = require('../../fixtures');

test.describe('Settings form — sticky save bar', { tag: '@admin' }, () => {
    let originalAppTitle;

    test.beforeEach(async ({ page }) => {
        await page.goto('/admin.php?tab=settings');
        originalAppTitle = await page.inputValue('input[name="app_title"]');
    });

    // Whatever a test does to app_title, put it back — other specs/pages read this value.
    test.afterEach(async ({ page }) => {
        await page.goto('/admin.php?tab=settings');
        await page.click('[data-testid="settings-toggle-general"]');
        const current = await page.inputValue('input[name="app_title"]');
        if (current !== originalAppTitle) {
            await page.fill('input[name="app_title"]', originalAppTitle);
            await page.click('[data-testid="settings-save-btn"]');
        }
    });

    test('loads in "all changes saved" state, collapsed with no Save/Discard actions, collapsible sections closed, static sections expanded', async ({ page }) => {
        const savebar = page.locator('[data-testid="settings-savebar"]');
        await expect(savebar).toBeVisible();
        await expect(savebar).not.toHaveClass(/dirty/);
        await expect(page.locator('.admin-savebar-saved-only')).toBeVisible();
        await expect(page.locator('[data-testid="settings-save-btn"]')).toBeHidden();
        await expect(page.locator('[data-testid="settings-discard-btn"]')).toBeHidden();

        await expect(page.locator('#settings-section-general')).toBeHidden();
        await expect(page.locator('#settings-section-hero')).toBeHidden();
        await expect(page.locator('#settings-section-betting')).toBeHidden();

        // Features / Ranking maintenance / Email delivery are static section cards — no
        // accordion, always visible, so these controls must be reachable without any click.
        await expect(page.locator('[data-testid="challenges-enabled-toggle"]')).toBeVisible();
        await expect(page.locator('[data-testid="email-delivery-toggle"]')).toBeVisible();
    });

    test('editing a text field sets dirty; Save persists and returns to the saved state', async ({ page }) => {
        const savebar = page.locator('[data-testid="settings-savebar"]');
        await page.click('[data-testid="settings-toggle-general"]');

        const newTitle = originalAppTitle + ' E2E';
        await page.fill('input[name="app_title"]', newTitle);
        await expect(savebar).toHaveClass(/dirty/);
        await expect(page.locator('[data-testid="settings-save-btn"]')).toBeVisible();
        await expect(page.locator('[data-testid="settings-discard-btn"]')).toBeVisible();

        await page.click('[data-testid="settings-save-btn"]');
        await expect(page).toHaveURL(/tab=settings/);
        await expect(savebar).not.toHaveClass(/dirty/);
        await expect(page.locator('[data-testid="settings-save-btn"]')).toBeHidden();
        await expect(page.locator('input[name="app_title"]')).toHaveValue(newTitle);
    });

    test('editing a checkbox also sets dirty', async ({ page }) => {
        const savebar = page.locator('[data-testid="settings-savebar"]');
        await page.locator('[data-testid="challenges-enabled-toggle"]').click();
        await expect(savebar).toHaveClass(/dirty/);
        // Revert client-side only — this test doesn't need the change persisted.
        await page.click('[data-testid="settings-discard-btn"]');
    });

    test('Discard reverts the field and clears dirty without saving', async ({ page }) => {
        const savebar = page.locator('[data-testid="settings-savebar"]');
        await page.click('[data-testid="settings-toggle-general"]');
        await page.fill('input[name="app_title"]', originalAppTitle + ' UNSAVED');
        await expect(savebar).toHaveClass(/dirty/);

        await page.click('[data-testid="settings-discard-btn"]');
        await expect(savebar).not.toHaveClass(/dirty/);
        await expect(page.locator('input[name="app_title"]')).toHaveValue(originalAppTitle);
    });

    test('editing without saving does not persist across reload (no beforeunload guard)', async ({ page }) => {
        await page.click('[data-testid="settings-toggle-general"]');
        await page.fill('input[name="app_title"]', originalAppTitle + ' RELOAD-TEST');
        await page.reload();
        await expect(page.locator('input[name="app_title"]')).toHaveValue(originalAppTitle);
    });
});

// ─── home_recap_count (Real recap carousel epic) — persistence + server-side clamp ──────────
test.describe('Settings form — home recap count', { tag: '@admin' }, () => {
    let original;

    test.beforeEach(async ({ page }) => {
        await page.goto('/admin.php?tab=settings');
        await page.click('[data-testid="settings-toggle-hero"]');
        original = await page.inputValue('input[name="home_recap_count"]');
    });

    test.afterEach(async ({ page }) => {
        await page.goto('/admin.php?tab=settings');
        await page.click('[data-testid="settings-toggle-hero"]');
        const current = await page.inputValue('input[name="home_recap_count"]');
        if (current !== original) {
            await page.fill('input[name="home_recap_count"]', original);
            await page.click('[data-testid="settings-save-btn"]');
        }
    });

    test('a value inside 3-10 persists across reload', async ({ page }) => {
        await page.fill('input[name="home_recap_count"]', '7');
        await page.click('[data-testid="settings-save-btn"]');
        await expect(page).toHaveURL(/tab=settings/);

        await page.click('[data-testid="settings-toggle-hero"]');
        await expect(page.locator('input[name="home_recap_count"]')).toHaveValue('7');
    });

    // The HTML min/max attribute is decorative only (functions.php's sanitizeInt() is the
    // real gate) — removing it here proves the *server* clamps 3-10, not just the browser's
    // native number-input validity check silently blocking the submit before it ever posts.
    test('an out-of-range value is clamped server-side, not just blocked client-side', async ({ page }) => {
        const input = page.locator('input[name="home_recap_count"]');
        await input.evaluate(el => el.removeAttribute('max'));
        await input.fill('999');
        await page.click('[data-testid="settings-save-btn"]');
        await expect(page).toHaveURL(/tab=settings/);

        await page.click('[data-testid="settings-toggle-hero"]');
        const clamped = Number(await page.locator('input[name="home_recap_count"]').inputValue());
        expect(clamped).toBeGreaterThanOrEqual(3);
        expect(clamped).toBeLessThanOrEqual(10);
    });
});
