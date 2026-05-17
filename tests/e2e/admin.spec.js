const { test, expect } = require("@playwright/test");
const path = require("path");
const { waitForMessages, getEmailBody } = require("../helpers/mailsac");

const ADMIN_AUTH    = path.join(__dirname, "../../.auth/admin.json");
const SEED_TOKEN    = process.env.INTEGRATION_SEED_TOKEN;
const MAILSAC_API_KEY     = process.env.MAILSAC_API_KEY;
const E2E_USER_EMAIL      = "e2e_testing_testuser_f1@mailsac.com";
const E2E_USER_INITIAL_PW = "E2ETestPassword2026!";
const E2E_USER_NEW_PW     = "E2ENewPassword456!";
const E2E_INVITE_EMAIL    = "e2e_testing_invite_f1@mailsac.com";
const E2E_BET_DELETE_EMAIL = "e2e_bet_delete_f1@mailsac.com";

async function confirmDeleteModal(page) {
    await page.locator(".btn-user-delete-confirm").click();
}

function userCard(page) {
    return page
        .locator(".card")
        .filter({ has: page.locator("small", { hasText: E2E_USER_EMAIL }) });
}

// ─── Admin panel ──────────────────────────────────────────────────────────────

test.describe("Admin panel", () => {
    test.use({ storageState: ADMIN_AUTH });

    test("create and delete a race", async ({ page }) => {
        await page.goto("/admin.php?tab=races");

        await page.click("#race-form-header");
        await page.locator('#race-form-body input[name="race_name"]').waitFor({ state: "visible" });

        await page.fill('input[name="race_name"]', "E2E Test Race");
        await page.fill('input[name="race_location"]', "Test Circuit");
        await page.fill('input[name="race_date"]', "2099-12-01");
        await page.fill('input[name="race_time"]', "14:00");
        await page.locator('button[name="add_race"]').evaluate(el => el.click());

        await expect(page.locator(".alert-success")).toBeVisible();
        const card = page
            .locator(".card")
            .filter({ has: page.locator("strong", { hasText: "E2E Test Race" }) });
        await expect(card).toBeVisible();

        await card.locator("button.btn-delete").click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator("strong", { hasText: "E2E Test Race" })
        ).toHaveCount(0);
    });

    test("create and delete a driver", async ({ page }) => {
        await page.goto("/admin.php?tab=drivers");

        await page.click("#driver-form-header");
        await page.locator('#driver-form-body input[name="driver_name"]').waitFor({ state: "visible" });

        await page.fill('input[name="driver_name"]', "E2E Test Driver");
        await page.fill('input[name="driver_team"]', "Test Team");
        await page.fill('input[name="driver_number"]', "98");
        await page.locator('button[name="add_driver"]').evaluate(el => el.click());

        await expect(page.locator(".alert-success")).toBeVisible();
        const card = page
            .locator(".card")
            .filter({ has: page.locator("strong", { hasText: "E2E Test Driver" }) });
        await expect(card).toBeVisible();

        await card.locator("button.btn-delete").click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator("strong", { hasText: "E2E Test Driver" })
        ).toHaveCount(0);
    });

    test.describe("invite CRUD", () => {
        test.beforeAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_e2e_invite`
            );
            await page.close();
        });

        test("invite a user and delete the invitation", async ({ page }) => {
            test.setTimeout(60000);
            await page.goto(`/admin.php?tab=invites&e2e_token=${SEED_TOKEN}`);

            await page.fill('input[name="invite_email"]', E2E_INVITE_EMAIL);
            await page.locator('button[name="create_invite"]').evaluate(el => el.click());

            // Invite creation sends a real email — SMTP may take up to 30s if it falls back to Resend.
            await page.waitForURL(/tab=invites/, { timeout: 50000 });
            await expect(page.locator(".alert-success")).toBeVisible({ timeout: 5000 });

            // Verify email markers emitted in test mode
            const body = await page.textContent("body");
            expect(body).toContain(`[invite-to] ${E2E_INVITE_EMAIL}`);
            expect(body).toContain("[invite-link] ");
            expect(body).toContain("/register.php?token=");
            expect(body, `Invite email failed to send: ${body}`).toContain("[invite-sent] true");

            // Mailsac delivery assertion
            if (MAILSAC_API_KEY) {
                const msgs = await waitForMessages(E2E_INVITE_EMAIL, 1, MAILSAC_API_KEY, { timeout: 20000 });
                expect((msgs[0].from ?? []).map(f => f.address).join()).toContain('info@formula-1.dk');
                const text = await getEmailBody(E2E_INVITE_EMAIL, msgs[0]._id, MAILSAC_API_KEY);
                expect(text, 'Invite email missing register link').toContain('/register.php?token=');
            }

            const card = page.locator(".card").filter({ hasText: E2E_INVITE_EMAIL });
            await expect(card).toBeVisible();

            await card.locator("button.btn-delete").click();
            await confirmDeleteModal(page);

            await page.waitForURL(/msg=deleted/);
            await expect(
                page.locator(".card").filter({ hasText: E2E_INVITE_EMAIL })
            ).toHaveCount(0);
        });
    });

    test.describe.serial("reset race result", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanPage = await browser.newPage();
            await cleanPage.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_reset_result`
            );
            await cleanPage.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_reset_result`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok).toBe(true);
            expect(seedData.points).toBeGreaterThan(0);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_reset_result`
            );
            await page.close();
        });

        test("reset button visible on last completed race", async ({ page }) => {
            await page.goto("/admin.php?tab=races");

            const raceCard = page
                .locator(".card")
                .filter({ has: page.locator("strong", { hasText: "E2E Reset Race" }) });
            await expect(raceCard.locator('button[name="reset_race_result"]')).toBeVisible();
        });

        test("reset clears results and removes points from users", async ({ page }) => {
            await page.goto("/admin.php?tab=races");

            const raceCard = page
                .locator(".card")
                .filter({ has: page.locator("strong", { hasText: "E2E Reset Race" }) });

            await raceCard.locator('button[name="reset_race_result"]').click();
            await page.locator(".btn-user-delete-confirm").click();
            await page.waitForURL(/msg=/);

            const raceCardAfter = page
                .locator(".card")
                .filter({ has: page.locator("strong", { hasText: "E2E Reset Race" }) });
            await expect(raceCardAfter.locator("small.text-accent")).toHaveCount(0);
            await expect(raceCardAfter.locator('button[name="reset_race_result"]')).toHaveCount(0);

            await page.goto("/admin.php?tab=users");
            const card = page
                .locator(".card")
                .filter({ has: page.locator("small", { hasText: "e2e_reset_race_f1@mailsac.com" }) });
            await expect(card.locator(".text-accent")).toContainText("0 pts");
        });
    });

    // ─── Bet deleted notification ──────────────────────────────────────────────
    // Seeds an in-competition user with a bet on an open race (12 h away, 48 h window)
    // so the admin can delete it and we can verify the notification email markers.

    test.describe.serial("bet deleted notification", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanup = await browser.newPage();
            await cleanup.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_bet_deleted`
            );
            await cleanup.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_bet_deleted`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok, JSON.stringify(seedData)).toBe(true);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_bet_deleted`
            );
            await page.close();
        });

        test("admin deletes bet and notification email markers are emitted", async ({ page }) => {
            test.setTimeout(60000);
            await page.goto(`/admin.php?tab=bets&e2e_token=${SEED_TOKEN}`);

            // Find the bet-delete button inside the race card for our seeded race
            const raceCard = page.locator(".card").filter({ hasText: "E2E Bet Delete Race" });
            await expect(raceCard).toBeVisible();
            await raceCard.locator('button[name="delete_bet"]').click();
            await confirmDeleteModal(page);

            await page.waitForURL(/tab=bets/, { timeout: 50000 });

            // Verify email markers passed through the redirect
            const body = await page.textContent("body");
            expect(body, `Admin bets page body:\n${body}`).toContain(`[bet-deleted-to] ${seedData.email}`);
            expect(body).toContain(`[bet-deleted-race] ${seedData.raceName}`);
            expect(body).toContain("[bet-deleted-lang] en"); // email must use bet owner's language
            expect(body, `Bet-delete email failed to send: ${body}`).toContain("[bet-deleted-sent] true");

            // Mailsac delivery assertion
            if (MAILSAC_API_KEY) {
                const msgs = await waitForMessages(E2E_BET_DELETE_EMAIL, 1, MAILSAC_API_KEY, { timeout: 20000 });
                expect((msgs[0].from ?? []).map(f => f.address).join()).toContain('info@formula-1.dk');
            }
        });
    });

    test.describe("test user management", () => {
        test.describe.configure({ mode: "serial" });

        test.beforeAll(async ({ browser }) => {
            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=create_e2e_user&language=en`
            );
            expect(res.status()).toBe(200);
            const body = JSON.parse(await page.textContent("body"));
            expect(body.ok).toBe(true);
            await page.close();
        });

        test("Toggle in competition on test user", async ({ page }) => {
            await page.goto("/admin.php?tab=users");

            const btn = userCard(page).locator('button[name="toggle_competition"]');
            await expect(btn).toContainText(/Not In Competition|Ikke I Konkurrence/);

            await btn.click();
            await page.waitForURL(/tab=users/);

            await expect(
                userCard(page).locator('button[name="toggle_competition"]')
            ).not.toContainText(/Not In Competition|Ikke I Konkurrence/);
        });

        test("Toggle admin role on test user", async ({ page }) => {
            await page.goto("/admin.php?tab=users");

            await expect(userCard(page).locator("span.badge")).toContainText("user");

            await userCard(page).locator('button[name="toggle_role"]').click();
            await page.waitForURL(/tab=users/);
            await expect(userCard(page).locator("span.badge")).toContainText("admin");

            await userCard(page).locator('button[name="toggle_role"]').click();
            await page.waitForURL(/tab=users/);
            await expect(userCard(page).locator("span.badge")).toContainText("user");
        });

        test("Set password on test user", async ({ page }) => {
            test.setTimeout(60000);
            await page.goto(`/admin.php?tab=users&e2e_token=${SEED_TOKEN}`);

            await userCard(page).locator(".btn-reset-pwd").click();
            const pwInput = userCard(page).locator('input[name="new_password"]');
            await pwInput.waitFor({ state: "visible" });
            await pwInput.fill(E2E_USER_NEW_PW);
            await userCard(page).locator('button[name="reset_user_password"]').click();

            // Password reset sends a real email then redirects — SMTP may take up to 30s.
            await page.waitForURL(/tab=users/, { timeout: 50000 });
            await expect(page.locator(".alert-success")).toBeVisible({ timeout: 5000 });

            // Verify email markers emitted in test mode
            const body = await page.textContent("body");
            expect(body).toContain(`[admin-reset-to] ${E2E_USER_EMAIL}`);
            expect(body).toContain(`[admin-reset-new-password] ${E2E_USER_NEW_PW}`);
            expect(body).toContain("[admin-reset-lang] en"); // email must use target user's language
            expect(body, `Admin-reset email failed to send: ${body}`).toContain("[admin-reset-sent] true");

            // Mailsac delivery assertion
            if (MAILSAC_API_KEY) {
                const msgs = await waitForMessages(E2E_USER_EMAIL, 1, MAILSAC_API_KEY, { timeout: 20000 });
                expect((msgs[0].from ?? []).map(f => f.address).join()).toContain('info@formula-1.dk');
            }
        });

        // Needs a fresh context: login.php redirects already-authenticated users.
        test.describe("Update display name on test user profile", () => {
            test.use({ storageState: { cookies: [], origins: [] } });

            test("logs in as E2E user and updates display name", async ({ page }) => {
                await page.goto("/login.php");
                await page.fill('input[name="email"]',    E2E_USER_EMAIL);
                await page.fill('input[name="password"]', E2E_USER_NEW_PW);
                await page.click('button[type="submit"]');
                await page.waitForURL(/index\.php/, { timeout: 5000 });

                await page.goto("/profile.php");
                await page.fill('input[name="display_name"]', "E2E Updated Name");
                await page.click('button[type="submit"]');

                await expect(page.locator(".alert-success")).toBeVisible();
                await expect(page.locator('input[name="display_name"]')).toHaveValue(
                    "E2E Updated Name"
                );
            });
        });

        test("Delete test user", async ({ page }) => {
            await page.goto("/admin.php?tab=users");

            await userCard(page).locator("button.btn-delete").click();
            await confirmDeleteModal(page);

            await page.waitForURL(/msg=deleted/);
            await expect(
                page.locator("small", { hasText: E2E_USER_EMAIL })
            ).toHaveCount(0);
        });
    });
});
