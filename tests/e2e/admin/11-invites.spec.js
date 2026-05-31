'use strict';
const { test, expect } = require('../../fixtures');
const seed = require('../../helpers/seed');
const { assertDelivered, getEmailBody } = require('../../helpers/email');
const { expectMarker } = require('../../helpers/markers');

const SEED_TOKEN       = process.env.INTEGRATION_SEED_TOKEN;
const E2E_INVITE_EMAIL = 'e2e_testing_invite_f1@test.localhost';

async function confirmDeleteModal(page) {
    await page.locator('.btn-user-delete-confirm').click();
}

// ─── Invite management ─────────────────────────────────────────────────────────

test.describe('Invite management', () => {
    test.beforeAll(async () => {
        await seed.cleanup.e2eInvite();
    });

    test('invite a user and delete the invitation', async ({ page }) => {
        test.setTimeout(60000);
        await page.goto(`/admin.php?tab=invites&e2e_token=${SEED_TOKEN}`);

        await page.fill('input[name="invite_email"]', E2E_INVITE_EMAIL);
        await page.locator('button[name="create_invite"]').click({ timeout: 50000 });
        await expect(page.locator('.alert-success')).toBeVisible({ timeout: 5000 });

        const body = await page.textContent('body');
        expectMarker(body, 'invite-to', E2E_INVITE_EMAIL);
        expectMarker(body, 'invite-sent', 'true');
        expect(body).toContain('/register.php?token=');

        const msgs = await assertDelivered(E2E_INVITE_EMAIL);
        if (msgs.length > 0) {
            const text = await getEmailBody(E2E_INVITE_EMAIL, msgs[0]._id);
            expect(text, 'Invite email missing register link').toContain('/register.php?token=');
        }

        const card = page.locator('.card').filter({ hasText: E2E_INVITE_EMAIL });
        await expect(card).toBeVisible();

        await card.locator('button.btn-delete').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator('.card').filter({ hasText: E2E_INVITE_EMAIL })
        ).toHaveCount(0);
    });
});
