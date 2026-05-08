const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const E2E_USER_EMAIL = "e2e_testing_testuser_f1@helvegpovlsen.dk";
const E2E_USER_INITIAL_PW = "E2ETestPassword2026!";
const E2E_USER_NEW_PW = "E2ENewPassword456!";
const E2E_INVITE_EMAIL = "e2e_testing_invite_f1@helvegpovlsen.dk";

async function loginAsAdmin(page) {
    await page.goto("/login.php");
    await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/, { timeout: 5000 });
}

async function confirmDeleteModal(page) {
    await page.locator(".btn-user-delete-confirm").click();
}

// Finds a user card by the user's email in the small tag
function userCard(page) {
    return page
        .locator(".card")
        .filter({ has: page.locator("small", { hasText: E2E_USER_EMAIL }) });
}

// ─── Race CRUD ────────────────────────────────────────────────────────────────

test("Admin: create and delete a race", async ({ page }) => {
    await loginAsAdmin(page);
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

// ─── Driver CRUD ──────────────────────────────────────────────────────────────

test("Admin: create and delete a driver", async ({ page }) => {
    await loginAsAdmin(page);
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

// ─── Invite CRUD ──────────────────────────────────────────────────────────────

test.describe("Admin: invite CRUD", () => {
    test.beforeAll(async ({ request }) => {
        // Remove any stale invite from a previous failed run
        await request.get(
            `/test-seed.php?token=${SEED_TOKEN}&action=cleanup_e2e_invite`
        );
    });

    test("invite a user and delete the invitation", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin.php?tab=invites");

        await page.fill('input[name="invite_email"]', E2E_INVITE_EMAIL);
        await page.locator('button[name="create_invite"]').evaluate(el => el.click());

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

// ─── Test user management (serial — each step depends on the previous) ────────

test.describe("Admin: test user management", () => {
    test.describe.configure({ mode: "serial" });

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=create_e2e_user`
        );
        expect(res.status()).toBe(200);
        const body = JSON.parse(await page.textContent("body"));
        expect(body.ok).toBe(true);
        await page.close();
    });

    test("Toggle in competition on test user", async ({ page }) => {
        await loginAsAdmin(page);
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
        await loginAsAdmin(page);
        await page.goto("/admin.php?tab=users");

        await expect(userCard(page).locator("span.badge")).toContainText("user");

        await userCard(page).locator('button[name="toggle_role"]').click();
        await page.waitForURL(/tab=users/);
        await expect(userCard(page).locator("span.badge")).toContainText("admin");

        // Toggle back to user
        await userCard(page).locator('button[name="toggle_role"]').click();
        await page.waitForURL(/tab=users/);
        await expect(userCard(page).locator("span.badge")).toContainText("user");
    });

    test("Set password on test user", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin.php?tab=users");

        await userCard(page).locator(".btn-reset-pwd").click();
        const pwInput = userCard(page).locator('input[name="new_password"]');
        await pwInput.waitFor({ state: "visible" });
        await pwInput.fill(E2E_USER_NEW_PW);
        await userCard(page).locator('button[name="reset_user_password"]').click();

        await expect(page.locator(".alert-success")).toBeVisible();
    });

    test("Update display name on test user profile", async ({ page }) => {
        await page.goto("/login.php");
        await page.fill('input[name="email"]', E2E_USER_EMAIL);
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

    test("Delete test user", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin.php?tab=users");

        await userCard(page).locator("button.btn-delete").click();
        await confirmDeleteModal(page);

        await page.waitForURL(/msg=deleted/);
        await expect(
            page.locator("small", { hasText: E2E_USER_EMAIL })
        ).toHaveCount(0);
    });
});
