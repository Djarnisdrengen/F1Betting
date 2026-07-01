'use strict';
const { test, expect } = require('../../fixtures');

// Re-enable interception no matter how the test ends, so later specs in this run keep capturing
// (global-setup turned it on; this test flips it off then on again).
async function forceCapture() {
    try {
        await fetch(`${process.env.BASE_URL}/tools/test-seed.php`
            + `?token=${encodeURIComponent(process.env.INTEGRATION_SEED_TOKEN)}&action=smtp_intercept_on`);
    } catch { /* best effort */ }
}

test.describe('Admin email-delivery toggle (test env)', () => {
    test.afterAll(forceCapture);

    test('toggle flips capture ↔ live and reflects status', async ({ page }) => {
        await page.goto('/admin.php?tab=settings');

        const status = page.locator('[data-testid="email-delivery-status"]');
        await expect(status).toBeVisible();
        // global-setup forces capture at the start of the run.
        await expect(status).toContainText(/Capturing|Opsamler/);

        await page.click('[data-testid="email-delivery-toggle"]');
        await expect(status).toContainText(/Sending real|Sender rigtige/);

        await page.click('[data-testid="email-delivery-toggle"]');
        await expect(status).toContainText(/Capturing|Opsamler/);
    });
});
