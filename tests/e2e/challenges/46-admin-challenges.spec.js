'use strict';

// Paddock Challenges — Feature 4, admin promotion queue + converted guests
// (feature-4-core-member-request.md). ADM-01..07 from test-plan.md's Feature 3/4/5 addendum,
// filling a gap where ADM-01/02 had cases but no listed spec file.
const { test, expect } = require('../../fixtures'); // admin-authed page — most cases run here
const { test: guest } = require('@playwright/test'); // clean, unauthenticated context — access gating only
const seed = require('../../helpers/seed');
const { assertDelivered } = require('../../helpers/email');

const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

// .card.mb-1 is the per-item card; the section wrappers around it are .card.mb-3 (or plain
// .card) and would also match hasText: email since the item card is their descendant.
function cardFor(page, email) {
    return page.locator('.card.mb-1').filter({ hasText: email });
}

// ─── ADM-02: access gating ──────────────────────────────────────────────────────

guest.describe('Admin access gating', { tag: '@challenges' }, () => {
    guest.afterAll(async () => { await seed.cleanup.challenges(); });

    // ADM-02: anonymous visitor denied like admin.php.
    guest('anonymous visitor is redirected to login', async ({ page }) => {
        await page.goto('/admin-challenges.php');
        await expect(page).toHaveURL(/login\.php/);
    });

    // ADM-02: non-admin core session denied.
    guest('non-admin core session is redirected away', async ({ page }) => {
        const email = tstEmail('adm02b');
        await seed.convertedGuest({ email });

        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', 'Integration2026!');
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);

        await page.goto('/admin-challenges.php');
        await expect(page).not.toHaveURL(/admin-challenges\.php/);
    });
});

// ─── Admin-authenticated cases ──────────────────────────────────────────────────

test.describe('Admin promotion queue & converted guests', { tag: '@challenges' }, () => {
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // ADM-01: converted guest listed with CP + conversion date; in_competition toggle flips.
    test('converted guest is listed with CP, and the in_competition toggle flips', async ({ page }) => {
        const email = tstEmail('adm01');
        const { participant_id } = await seed.convertedGuest({ email, in_competition: 1, display_name: 'Adm One' });
        await seed.challengePoints({ participant_id, points: 12, source_ref: 'test:adm01' });

        await page.goto('/admin-challenges.php');
        const card = cardFor(page, email);
        await expect(card).toBeVisible();
        await expect(card).toContainText('12 CP');
        // Labels are localized (da/en per admin session); the button's bound inline color
        // is not, so assert on that instead of the text.
        const toggleBtn = card.locator('form:has(input[name="action"][value="toggle_guest_competition"]) button[type="submit"]');
        await expect(toggleBtn).toHaveAttribute('style', /f1-red/);

        await toggleBtn.click();
        await page.waitForURL(/admin-challenges\.php/);
        const toggledBtn = cardFor(page, email).locator('form:has(input[name="action"][value="toggle_guest_competition"]) button[type="submit"]');
        await expect(toggledBtn).toHaveAttribute('style', /bg-secondary/);
    });

    // ADM-02 (users-tab exclusion half): converted guest absent from the core Users tab.
    test('converted guest is absent from the core users tab', async ({ page }) => {
        const email = tstEmail('adm02c');
        await seed.convertedGuest({ email });

        await page.goto('/admin.php?tab=users');
        await expect(cardFor(page, email)).toHaveCount(0);
    });

    // ADM-03: approve a permanent participant (password_hash set), CP seeded at 45 — atomic,
    // password carried over, CP preserved under the same participant_id.
    test('approves a permanent participant: atomic, password carried over, CP preserved', async ({ page }) => {
        const email = tstEmail('adm03');
        const pw = 'Adm03Pass2026!';
        const { participant_id } = await seed.challengeParticipant({
            email, status: 'verified', password: pw, promotion_requested_at: 1, display_name: 'Adm Three',
        });
        await seed.challengePoints({ participant_id, points: 45, source_ref: 'test:adm03' });

        await page.goto('/admin-challenges.php');
        const pendingCard = cardFor(page, email);
        await expect(pendingCard).toContainText('Permanent');
        await pendingCard.locator('form:has(input[name="action"][value="approve_promotion"]) button[type="submit"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(cardFor(page, email).filter({ hasText: 'Permanent' })).toHaveCount(0);
        await expect(cardFor(page, email)).toContainText('45 CP');

        // Password carried over — the participant's existing plaintext still authenticates
        // the new core account.
        await page.context().clearCookies();
        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', pw);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);
    });

    // ADM-04: approve a verified-only participant (no password) — unusable password + a
    // set-password email with a working reset_password.php link.
    test('approves a verified-only participant: set-password email issued and works', async ({ page }) => {
        const email = tstEmail('adm04');
        await seed.challengeParticipant({ email, status: 'verified', promotion_requested_at: 1, display_name: 'Adm Four' });

        await page.goto('/admin-challenges.php');
        const pendingCard = cardFor(page, email);
        await pendingCard.locator('form:has(input[name="action"][value="approve_promotion"]) button[type="submit"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(cardFor(page, email)).toBeVisible();

        const msgs = await assertDelivered(email);
        // getEmailBody() prefers the plain-text alternative, which strips hrefs entirely —
        // the token link only survives in the HTML part.
        const match = msgs[0].html.match(/reset_password\.php\?token=([a-f0-9]+)/);
        expect(match, 'set-password email missing a reset_password.php link').toBeTruthy();

        // reset_password.php bounces an authenticated session straight to index.php —
        // drop the admin session before following the link.
        await page.context().clearCookies();
        const newPw = 'Adm04SetPass2026!';
        await page.goto(`/reset_password.php?token=${match[1]}`);
        await page.fill('input[name="password"]', newPw);
        await page.fill('input[name="confirm_password"]', newPw);
        await page.click('button[type="submit"]');

        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', newPw);
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);
    });

    // ADM-05: reject clears the flag and writes nothing to users.
    test('rejects a pending promotion request', async ({ page }) => {
        const email = tstEmail('adm05');
        await seed.challengeParticipant({ email, status: 'verified', promotion_requested_at: 1, display_name: 'Adm Five' });

        await page.goto('/admin-challenges.php');
        const pendingCard = cardFor(page, email);
        await pendingCard.locator('form:has(input[name="action"][value="reject_promotion"]) button[type="submit"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(cardFor(page, email)).toHaveCount(0);
    });

    // ADM-06: double-submit approve for the same participant no-ops the second time (the
    // core_user_id IS NOT NULL guard) — no second users row / converted-guest card.
    test('double-submitting approve does not create a second account', async ({ page }) => {
        const email = tstEmail('adm06');
        const pw = 'Adm06Pass2026!';
        const { participant_id } = await seed.challengeParticipant({
            email, status: 'verified', password: pw, promotion_requested_at: 1, display_name: 'Adm Six',
        });

        await page.goto('/admin-challenges.php');
        const csrfToken = await cardFor(page, email)
            .locator('form:has(input[name="action"][value="approve_promotion"]) input[name="csrf_token"]')
            .getAttribute('value');

        const postApprove = () => page.request.post('/admin-challenges.php', {
            form: { action: 'approve_promotion', participant_id, csrf_token: csrfToken },
        });
        await postApprove();
        await postApprove();

        await page.goto('/admin-challenges.php');
        await expect(cardFor(page, email)).toHaveCount(1);
        await expect(cardFor(page, email)).toContainText('CP');
    });

    // ADM-07: approving into a colliding email rolls back before any write; request stays
    // pending; admin sees a conflict notice.
    test('approve rolls back on an email collision', async ({ page }) => {
        const email = tstEmail('adm07');
        await seed.convertedGuest({ email, link_participant: 0 }); // users row only — the collision
        const pw = 'Adm07Pass2026!';
        await seed.challengeParticipant({ email, status: 'verified', password: pw, promotion_requested_at: 1 });

        await page.goto('/admin-challenges.php');
        const pendingCard = cardFor(page, email);
        await pendingCard.locator('form:has(input[name="action"][value="approve_promotion"]) button[type="submit"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(page.locator('.alert-danger')).toBeVisible();
        // Still exactly one card for this email — the pending request, untouched (still
        // "Permanent" per the password seeded above — identical text in da/en).
        await expect(cardFor(page, email)).toHaveCount(1);
        await expect(cardFor(page, email)).toContainText('Permanent');
    });
});

// ─── Rumor drafts (Phase 3, REQ-502) ────────────────────────────────────────────

test.describe('Admin rumor drafts', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    function draftCard(page, itemId) {
        return page.locator(`[data-testid="rumor-draft"][data-item-id="${itemId}"]`);
    }

    // Draft is listed with its bilingual fields; Save persists edits without publishing.
    test('lists a draft and Save persists edits without publishing', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [1] });

        await page.goto('/admin-challenges.php');
        const card = draftCard(page, draft_item_id);
        await expect(card).toBeVisible();
        await expect(card.locator('textarea[name="text_da"]')).toHaveValue('Test draft item');

        await card.locator('textarea[name="text_en"]').fill('Edited claim text');
        await card.locator('button[name="action"][value="save_rumor_draft"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        const savedCard = draftCard(page, draft_item_id);
        await expect(savedCard).toBeVisible();
        await expect(savedCard.locator('textarea[name="text_en"]')).toHaveValue('Edited claim text');
    });

    // Publish removes it from the drafts list and makes it immediately playable on the
    // public page — the admin→player pipeline this whole screen exists to feed.
    test('Publish makes the item playable on the public page', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        await page.goto('/admin-challenges.php');
        const card = draftCard(page, draft_item_id);
        await card.locator('select[name="is_real"]').selectOption('1');
        await card.locator('button[name="action"][value="publish_rumor_draft"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveCount(0);

        await page.context().clearCookies();
        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toContainText('Test draft item');
    });

    // Veto deletes the draft outright — never reaches the public page.
    test('Veto deletes the draft', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        await page.goto('/admin-challenges.php');
        const card = draftCard(page, draft_item_id);
        await card.locator('button[name="action"][value="veto_rumor_draft"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveCount(0);
    });
});
