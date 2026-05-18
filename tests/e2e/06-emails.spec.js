const { test, expect } = require('@playwright/test');
const path = require('path');
const { waitForMessages, getEmailBody } = require('../helpers/mailsac');

const ADMIN_AUTH      = path.join(__dirname, '../../.auth/admin.json');
const SEED_TOKEN      = process.env.INTEGRATION_SEED_TOKEN;
const TEST_USER_EMAIL = process.env.TEST_USER_EMAIL;
const MAILSAC_API_KEY = process.env.MAILSAC_API_KEY;
const MAILSAC_INBOX   = process.env.MAILSAC_INBOX;

// ─── Email preview ────────────────────────────────────────────────────────────
// Sends one real email of every implemented type to MAILSAC_INBOX and verifies:
//
//   Tier 1 (all 16): PHP mailer reports sent:true — SMTP accepted every email.
//
//   Tier 2 (all 16): inbox is purged before the run (requires MAILSAC_INBOX to
//   be an owned inbox on the Mailsac Indie Plan), then all 16 are waited for.
//   Assertions: correct sender on every message; invite body contains site
//   domain and register link; betting-open delivery confirmed by subject match.
//
// Requires MAILSAC_API_KEY and MAILSAC_INBOX in config.test.php.
// Skips cleanly if MAILSAC_API_KEY is not set.
//
// Run selectively:
//   npx playwright test 06-emails.spec.js --grep "email preview"

test.describe("email preview", () => {
    test("sends one of each email type to MAILSAC_INBOX and verifies delivery and content", async ({ page }) => {
        test.skip(!MAILSAC_API_KEY, 'MAILSAC_API_KEY not set — skipping Mailsac delivery assertions');
        test.setTimeout(180000);

        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${SEED_TOKEN}&action=send_email_preview`,
            { timeout: 150000 }
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

        // Tier 1: every email was accepted by SMTP
        for (const [name, info] of Object.entries(body.emails ?? {})) {
            expect(info.sent, `Email "${name}" failed to send`).toBe(true);
        }
        expect(body.ok, JSON.stringify(body)).toBe(true);

        // Tier 2: all emails must arrive in Mailsac (inbox was purged before send)
        const expectedCount = Object.keys(body.emails ?? {}).length;
        const messages = await waitForMessages(MAILSAC_INBOX, expectedCount, MAILSAC_API_KEY, { timeout: 90000 });

        console.log(`Mailsac: ${messages.length}/${expectedCount} emails arrived`);
        expect(messages.length, `Expected ${expectedCount} emails in Mailsac, got ${messages.length}`).toBe(expectedCount);

        // Every arrived message must be from the configured sender
        for (const msg of messages) {
            const fromAddr = (msg.from ?? []).map(f => f.address).join(', ');
            expect(fromAddr, `Unexpected sender on "${msg.subject}"`).toContain('info@formula-1.dk');
        }

        // Spot-check body of invite EN — sendInviteEmail() includes a text/plain part
        // so Mailsac's /text/ endpoint returns the full URL.
        const siteHost = new URL(process.env.BASE_URL).hostname;
        const inviteEnMsg = messages.find(m => m.subject === body.emails['3_invite_en']?.subject);
        if (inviteEnMsg) {
            const text = await getEmailBody(MAILSAC_INBOX, inviteEnMsg._id, MAILSAC_API_KEY);
            expect(text, 'Invite email body missing site domain').toContain(siteHost);
            expect(text, 'Invite email body missing register link').toContain('/register.php?token=');
        }

        // Betting-open email has no text/plain alternative (href URLs stripped by auto-text)
        // — assert delivery only via subject match.
        const bettingOpenEnMsg = messages.find(m => m.subject === body.emails['4_betting_open_en']?.subject);
        expect(bettingOpenEnMsg, 'Betting-open EN email did not arrive in Mailsac').toBeDefined();
    });
});

// ─── SMTP / Resend config (test_smtp.php) ─────────────────────────────────────

// Unauthenticated check runs first in its own isolated context.
test.describe('SMTP / Resend config — access control', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('denies access to unauthenticated users', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        await expect(page.locator('body')).toContainText('Access denied');
    });
});

test.describe('SMTP / Resend config (test_smtp.php)', () => {
    test.use({ storageState: ADMIN_AUTH });

    test('admin can access the page', async ({ page }) => {
        const res = await page.goto('/tools/test_smtp.php');
        expect(res.status()).toBe(200);
        await expect(page.locator('body')).toContainText('Current SMTP Configuration');
    });

    test('config table shows all required keys', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        for (const key of ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_FROM_EMAIL', 'RESEND_API_KEY']) {
            await expect(page.locator('td').filter({ hasText: key }).first()).toBeVisible();
        }
    });

    test('RESEND_API_KEY is configured', async ({ page }) => {
        await page.goto('/tools/test_smtp.php');
        const resendRow = page.locator('tr').filter({ has: page.locator('td', { hasText: 'RESEND_API_KEY' }) });
        await expect(resendRow).toContainText('********');
        await expect(resendRow).not.toContainText('Not defined');
    });
});

// ─── Password reset email ──────────────────────────────────────────────────────
// Moved to 02-auth.spec.js in step 7.

test.describe('Password reset email', () => {
    test('forgot_password page renders form', async ({ page }) => {
        await page.goto('/forgot_password.php');
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('submitting known email triggers email and hides form', async ({ page }) => {
        await page.goto(`/forgot_password.php?e2e_token=${SEED_TOKEN}`);
        await page.fill('input[name="email"]', TEST_USER_EMAIL);
        await page.click('button[type="submit"]');
        await expect(page.locator('.alert-success')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('input[name="email"]')).not.toBeVisible();

        const body = await page.textContent('body');
        expect(body).toContain(`[forgot-pwd-to] ${TEST_USER_EMAIL}`);
        expect(body).toContain('[forgot-pwd-link] ');
        expect(body).toContain('/reset_password.php?token=');
    });

    test('submitting unknown email shows no error (does not reveal user existence)', async ({ page }) => {
        await page.goto('/forgot_password.php');
        await page.fill('input[name="email"]', 'nonexistent@example.com');
        await page.click('button[type="submit"]');
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-error')).not.toBeVisible();
    });
});
