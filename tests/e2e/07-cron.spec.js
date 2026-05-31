const { test, expect } = require("@playwright/test");
const seed = require('../helpers/seed');
const { getMessages, waitForNewMessages } = require('../helpers/email');

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const CRON_SECRET = process.env.CRON_SECRET;

// ─── Cron jobs ────────────────────────────────────────────────────────────────

test.describe("Cron jobs", () => {
    test.describe.serial("import qualifying", () => {
        test.beforeAll(async ({ browser }) => {
            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_cron_qualifying`
            );
            expect(res.status()).toBe(200);
            const body = JSON.parse(await page.textContent("body"));
            expect(body.ok).toBe(true);
            await page.close();
        });

        test("unauthorized without token or test mode", async ({ page }) => {
            await page.goto("/cron/import_qualifying.php");
            const text = await page.textContent("body");
            expect(text).toContain("Unauthorized access");
        });

        test("test mode imports qualifying results", async ({ page }) => {
            await page.goto("/cron/import_qualifying.php?test=true");
            const text = await page.textContent("body");
            expect(text).toContain("[SUCCESS] Updated qualifying results");
            expect(text).toContain("Total races updated: 1");
        });
    });

    // ─── Notifications — access control ───────────────────────────────────────

    test.describe("notifications — access control", () => {
        test("unauthorized without token", async ({ page }) => {
            await page.goto("/cron/notifications.php");
            const text = await page.textContent("body");
            expect(text).toContain("Unauthorized access");
        });

        test("authorized with CRON_SECRET completes cleanly", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}`);
            const text = await page.textContent("body");
            expect(text).toContain("Notification check complete");
            expect(text).not.toContain("FAILED to send");
        });
    });

    // ─── Notifications — betting just opened ──────────────────────────────────
    // Race is 47h30m away with a 48h window → bettingOpens fell 30min ago (within the 1-hour trigger).
    // One user has no bet, so the cron should send an open-notification email to them.

    test.describe.serial("notifications — betting just opened", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanup = await browser.newPage();
            await cleanup.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_open`
            );
            await cleanup.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_notification_open`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok, JSON.stringify(seedData)).toBe(true);
            // Verify settings took effect — if this fails the cron window calculation will be wrong
            expect(seedData.bettingWindowHours, `seed: ${JSON.stringify(seedData)}`).toBe(48);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_open`
            );
            await page.close();
        });

        test("sends open notification to in-competition user, pool reminder to non-competing and invited", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}&test=true`);
            const text = await page.textContent("body");
            // Pass full cron output as the error message so failures are self-diagnosing
            expect(text, `Cron output:\n${text}`).toContain("Betting opened for: E2E Notify Open Race");

            // In-competition user — betting-opened email in user's preferred language (en)
            expect(text).toContain(`Sent open notification to: ${seedData.emailCompeting}`);
            expect(text).toContain("[race] E2E Notify Open Race"); // race name in email body
            expect(text).toContain("[window] 48h");               // betting window duration
            expect(text).toContain("[pool] 150");                 // pool size in email body
            expect(text).toContain("bet.php?race=");              // CTA links to bet page
            expect(text).toContain("[lang] en");                  // email uses user's stored language

            // Non-competing registered user — pool reminder, not betting-opened, also in English
            expect(text).not.toContain(`Sent open notification to: ${seedData.emailNonCompeting}`);
            expect(text).toContain(`Sent pool reminder to: ${seedData.emailNonCompeting}`);
            expect(text).toContain("[pool] 150");       // pool amount in email body
            expect(text).toContain("leaderboard.php"); // CTA links to leaderboard

            // Pending invite — pool reminder with personal registration link
            expect(text).toContain(`Sent pool reminder to: ${seedData.emailInvited}`);
            expect(text).toContain("register.php?token=e2e-notify-open-token"); // invite-specific CTA

            expect(text).toContain("Notification check complete");
        });
    });

    // ─── Notifications — betting closing soon ─────────────────────────────────
    // Race is 2h30m away → inside the 2-3h closing window.
    // User A has no bet (should receive notification).
    // User B already placed a bet (must be skipped by the cron).

    test.describe.serial("notifications — betting closing soon", () => {
        let seedData;

        test.beforeAll(async ({ browser }) => {
            const cleanup = await browser.newPage();
            await cleanup.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_close`
            );
            await cleanup.close();

            const page = await browser.newPage();
            const res = await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_notification_close`
            );
            expect(res.status()).toBe(200);
            seedData = JSON.parse(await page.textContent("body"));
            expect(seedData.ok).toBe(true);
            await page.close();
        });

        test.afterAll(async ({ browser }) => {
            const page = await browser.newPage();
            await page.goto(
                `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_notification_close`
            );
            await page.close();
        });

        test("sends closing notification to unbetted user, skips user with existing bet", async ({ page }) => {
            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}&test=true`);
            const text = await page.textContent("body");
            expect(text).toContain("Betting closing soon for: E2E Notify Close Race");

            // Unbetted user — closing notification in user's preferred language (en)
            expect(text).toContain(`Sent closing notification to: ${seedData.emailUnbetted}`);
            expect(text).toContain("[race] E2E Notify Close Race"); // race name in email body
            expect(text).toContain("bet.php?race=");               // CTA links to bet page
            expect(text).toContain("[lang] en");                   // email uses user's stored language

            // Scope to the E2E Notify Close Race block only — a real F1 session falling
            // in the 2-3h window would legitimately notify user B (no bet for real races).
            const closeBlockStart = text.indexOf('Betting closing soon for: E2E Notify Close Race');
            const closeBlockNext  = text.indexOf('\nBetting', closeBlockStart + 1);
            const closeBlock      = closeBlockNext === -1
                ? text.slice(closeBlockStart)
                : text.slice(closeBlockStart, closeBlockNext);
            expect(closeBlock, `Full cron output:\n${text}`).not.toContain(`Sent closing notification to: ${seedData.emailBetted}`);

            expect(text).toContain("Notification check complete");
        });
    });

    // ─── Notifications — betting just opened (real send) ─────────────────────
    // Runs the cron without ?test=true. Emails are captured by SMTP_INTERCEPT.
    // Uses waitForNewMessages (baseline approach) so prior test-mode emails are excluded.

    test.describe.serial('notifications — betting just opened (real send)', () => {
        test.beforeAll(async () => {
            await seed.cleanup.notifyOpen();
            await seed.notifyOpen();
        });

        test.afterAll(async () => {
            await seed.cleanup.notifyOpen();
        });

        test('betting-open email captured by intercept for in-competition inbox', async ({ page }) => {
            const inbox = 'e2e_notify_open_in_f1@test.localhost';
            const baseline = new Set(
                (await getMessages(inbox)).map(m => m._id)
            );

            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}`);
            const cronText = await page.textContent('body');
            expect(cronText, `Cron output:\n${cronText}`).toContain(`Sent open notification to: ${inbox}`);

            const msgs = await waitForNewMessages(inbox, baseline, 1, null, { timeout: 20000 });
            const from = (msgs[0].from ?? []).map(f => f.address).join(' ');
            expect(from).toContain('formula-1.dk');
        });
    });

    // ─── Notifications — betting closing soon (real send) ────────────────────

    test.describe.serial('notifications — betting closing soon (real send)', () => {
        test.beforeAll(async () => {
            await seed.cleanup.notifyClose();
            await seed.notifyClose();
        });

        test.afterAll(async () => {
            await seed.cleanup.notifyClose();
        });

        test('betting-close email captured by intercept for unbetted inbox', async ({ page }) => {
            const inbox = 'e2e_notify_close_a_f1@test.localhost';
            const baseline = new Set(
                (await getMessages(inbox)).map(m => m._id)
            );

            await page.goto(`/cron/notifications.php?token=${CRON_SECRET}`);

            const msgs = await waitForNewMessages(inbox, baseline, 1, null, { timeout: 20000 });
            const from = (msgs[0].from ?? []).map(f => f.address).join(' ');
            expect(from).toContain('formula-1.dk');
        });
    });
});
