'use strict';
const { test, expect } = require('../../fixtures');

async function confirmDeleteModal(page) {
    await page.locator('.btn-user-delete-confirm').click();
}

// ─── Race management ───────────────────────────────────────────────────────────

test.describe('Race management', () => {
    test('create and delete a race', async ({ page }) => {
        await page.goto('/admin.php?tab=races');

        await page.click('#race-form-header');
        await page.locator('#race-form-body input[name="race_name"]').waitFor({ state: 'visible' });

        await page.fill('input[name="race_name"]', 'E2E Test Race');
        await page.fill('input[name="race_location"]', 'Test Circuit');
        await page.fill('input[name="race_date"]', '2099-12-01');
        await page.fill('input[name="race_time"]', '14:00');
        await page.locator('button[name="add_race"]').evaluate(el => el.click());

        await expect(page.locator('.alert-success')).toBeVisible();
        const card = page
            .locator('.hf-racefull')
            .filter({ has: page.locator('.hf-racename', { hasText: 'E2E Test Race' }) });
        await expect(card).toBeVisible();

        await card.locator('button.btn-delete').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator('strong', { hasText: 'E2E Test Race' })
        ).toHaveCount(0);
    });
});

// ─── Driver management ─────────────────────────────────────────────────────────

test.describe('Driver management', () => {
    test('create and delete a driver', async ({ page }) => {
        await page.goto('/admin.php?tab=drivers');

        await page.click('#driver-form-header');
        await page.locator('#driver-form-body input[name="driver_name"]').waitFor({ state: 'visible' });

        await page.fill('input[name="driver_name"]', 'E2E Test Driver');
        await page.fill('input[name="driver_team"]', 'Test Team');
        await page.fill('input[name="driver_number"]', '98');
        await page.locator('button[name="add_driver"]').evaluate(el => el.click());

        await expect(page.locator('.alert-success')).toBeVisible();
        const card = page
            .locator('.card')
            .filter({ has: page.locator('strong', { hasText: 'E2E Test Driver' }) });
        await expect(card).toBeVisible();

        await card.locator('button.btn-delete').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator('strong', { hasText: 'E2E Test Driver' })
        ).toHaveCount(0);
    });
});
