const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
const CRON_SECRET = process.env.CRON_SECRET;

// ─── Email preview ────────────────────────────────────────────────────────────
// Sends one real email of every implemented type to F1_ADMIN_EMAIL so the
// visual layout and content can be reviewed manually.  Run selectively:
//   npx playwright test cron.spec.js --grep "email preview"

test.describe("email preview", () => {
    test("sends one of each email type to F1_ADMIN_EMAIL", async ({ page }) => {
        test.setTimeout(90000);
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${SEED_TOKEN}&action=send_email_preview`,
            { timeout: 75000 }
        );
        expect(res.status()).toBe(200);
        const body = JSON.parse(await page.textContent("body"));

        const detailLines = ["\n── Email preview results ──────────────────────────"];
        for (const [name, info] of Object.entries(body.emails ?? {})) {
            const status = info.sent ? "✓ SENT" : "✗ FAILED";
            detailLines.push(`\n${status}  ${name}`);
            detailLines.push(`   to:      ${info.to}`);
            detailLines.push(`   subject: ${info.subject}`);
            const skip = new Set(["sent", "to", "subject"]);
            for (const [k, v] of Object.entries(info)) {
                if (!skip.has(k)) detailLines.push(`   ${k.padEnd(12)}: ${v}`);
            }
        }
        detailLines.push("────────────────────────────────────────────────\n");
        console.log(detailLines.join("\n"));

        for (const [name, info] of Object.entries(body.emails ?? {})) {
            expect(info.sent, `Email "${name}" failed to send`).toBe(true);
        }
        expect(body.ok, JSON.stringify(body)).toBe(true);
    });
});

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

            // User who already placed a bet — skipped entirely
            expect(text).not.toContain(`Sent closing notification to: ${seedData.emailBetted}`);

            expect(text).toContain("Notification check complete");
        });
    });
});
