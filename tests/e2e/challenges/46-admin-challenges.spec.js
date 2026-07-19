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
// .card) and would also match hasText: email since the item card is their descendant. The
// "All participants" roster below uses .hf-racefull rows (not .card.mb-1), so an emailed
// participant listed there too never collides with this locator — those rows are targeted by
// the row() helper in the roster describe block instead.
function cardFor(page, email) {
    return page.locator('.card.mb-1').filter({ hasText: email });
}

// Delete/veto buttons use the site-wide btn-delete confirmation modal (app.js) —
// click opens it, this clicks the confirm button to actually submit.
async function confirmDeleteModal(page) {
    await page.locator('.btn-user-delete-confirm').click();
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

// ─── All-participants roster + delete (single & bulk) ───────────────────────────

test.describe('Admin participant roster & delete', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    const row = (page, id) => page.locator(`[data-testid="participant-row"][data-participant-id="${id}"]`);

    // The roster lists every participant with its columns (email + language render
    // locale-independently), and the per-row Delete removes exactly that participant
    // (site-wide confirm modal, same as suppressions/rumors deletes).
    test('lists a participant and single-delete removes it', async ({ page }) => {
        const email = tstEmail('rost01');
        const { participant_id } = await seed.challengeParticipant({
            email, status: 'verified', language: 'en',
            display_name: 'Roster One', promotion_requested_at: 1,
        });

        await page.goto('/admin-challenges.php?tab=members');
        const r = row(page, participant_id);
        await expect(r).toBeVisible();
        await expect(r).toContainText(email);        // email column
        await expect(r).toContainText('EN');         // language column (raw code, not localized)
        await expect(r).toHaveAttribute('data-kind', 'guest');

        // Delete opens the site-wide confirm modal, then submits.
        await r.locator('button.btn-delete[value="delete_participant"]').click();
        await confirmDeleteModal(page);
        await page.waitForURL(/admin-challenges\.php/);

        await expect(row(page, participant_id)).toHaveCount(0);
    });

    // Bulk delete removes only the checked participants (native confirm — accept the dialog),
    // reusing the generic data-bulk-group multiselect wiring shared with rumors/trivia.
    test('bulk delete removes only the selected participants', async ({ page }) => {
        const a = await seed.challengeParticipant({ email: tstEmail('rost02a'), status: 'verified', display_name: 'Bulk A' });
        const b = await seed.challengeParticipant({ email: tstEmail('rost02b'), status: 'verified', display_name: 'Bulk B' });
        const c = await seed.challengeParticipant({ email: tstEmail('rost02c'), status: 'verified', display_name: 'Bulk C' });

        await page.goto('/admin-challenges.php?tab=members');
        const bulkBtn = page.locator('#bulk-participant button[value="bulk_delete_participants"]');
        await expect(bulkBtn).toBeDisabled();

        await row(page, a.participant_id).locator('input[name="ids[]"]').check();
        await row(page, b.participant_id).locator('input[name="ids[]"]').check();
        await expect(bulkBtn).toBeEnabled();

        page.once('dialog', d => d.accept());
        await bulkBtn.click();
        await page.waitForURL(/admin-challenges\.php/);

        await expect(row(page, a.participant_id)).toHaveCount(0);
        await expect(row(page, b.participant_id)).toHaveCount(0);
        await expect(row(page, c.participant_id)).toHaveCount(1);
    });

    // A promoted full member (linked core_user_id) is badged distinctly and is deletable here;
    // deleting the participant row leaves the core users account intact by FK design (SET NULL),
    // so this only removes their challenge-side record.
    test('a promoted participant is badged and deletable', async ({ page }) => {
        const email = tstEmail('rost03');
        const { participant_id } = await seed.convertedGuest({ email, display_name: 'Promoted One' });

        await page.goto('/admin-challenges.php?tab=members');
        const r = row(page, participant_id);
        await expect(r).toBeVisible();
        await expect(r).toHaveAttribute('data-kind', 'promoted');

        await r.locator('button.btn-delete[value="delete_participant"]').click();
        await confirmDeleteModal(page);
        await page.waitForURL(/admin-challenges\.php/);

        await expect(row(page, participant_id)).toHaveCount(0);
    });
});

// ─── Rumor drafts (Phase 3, REQ-502) ────────────────────────────────────────────

test.describe('Admin rumor drafts', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    function draftCard(page, itemId) {
        return page.locator(`[data-testid="rumor-item"][data-item-id="${itemId}"]`);
    }

    // Draft is listed with its bilingual fields; Save persists edits without publishing.
    test('lists a draft and Save persists edits without publishing', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [1] });

        // Bilingual fields only render inside the expanded edit row (?edit=), not on the
        // compact list — navigate straight there instead of clicking Edit first.
        await page.goto('/admin-challenges.php?tab=rumors&edit=' + draft_item_id);
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

    // Publish makes it immediately playable on the public page — the admin→player pipeline
    // this whole screen exists to feed. The item stays listed (full list, all statuses) but
    // its data-status flips, same convention as the trivia list below.
    test('Publish makes the item playable on the public page', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        // is_real is a field edit, so this needs the expanded edit row, not the compact one.
        await page.goto('/admin-challenges.php?tab=rumors&edit=' + draft_item_id);
        const card = draftCard(page, draft_item_id);
        await card.locator('select[name="is_real"]').selectOption('1');
        await card.locator('button[name="action"][value="publish_rumor_draft"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveAttribute('data-status', 'published');

        await page.context().clearCookies();
        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toContainText('Test draft item');
    });

    // Veto deletes the draft outright — never reaches the public page. Lives on the compact
    // row itself, so no edit mode needed.
    test('Veto deletes the draft', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        await page.goto('/admin-challenges.php?tab=rumors');
        const card = draftCard(page, draft_item_id);
        await card.locator('button[name="action"][value="veto_rumor_draft"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveCount(0);
    });

    // Unpublish takes a published item back to draft — the new escape hatch that didn't
    // exist before this list showed anything beyond drafts.
    test('Unpublish moves a published item back to draft', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        // Publish from the compact row (quick_publish_rumor_item, status-only) — what an
        // admin actually clicks from the list, not the full-field publish_rumor_draft
        // inside the edit form.
        await page.goto('/admin-challenges.php?tab=rumors');
        const card = draftCard(page, draft_item_id);
        await card.locator('button[name="action"][value="quick_publish_rumor_item"]').click();
        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveAttribute('data-status', 'published');

        await draftCard(page, draft_item_id).locator('button[name="action"][value="unpublish_rumor_item"]').click();
        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveAttribute('data-status', 'draft');
    });

    // Delete removes a published item outright (unlike Veto, which only ever touches drafts)
    // — this is the gap that made published rumors permanently stuck once reviewed.
    test('Delete removes a published item', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        await page.goto('/admin-challenges.php?tab=rumors');
        await draftCard(page, draft_item_id).locator('button[name="action"][value="quick_publish_rumor_item"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        const publishedCard = draftCard(page, draft_item_id);
        await expect(publishedCard).toHaveAttribute('data-status', 'published');
        await publishedCard.locator('button[name="action"][value="delete_rumor_item"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/admin-challenges\.php/);
        await expect(draftCard(page, draft_item_id)).toHaveCount(0);
    });

    // The status filter pills narrow the list; a published item shows under "Published"
    // and drops out of "Drafts".
    test('status filter narrows the list', async ({ page }) => {
        const { draft_item_id } = await seed.rumorDeck({ real: [] });

        await page.goto('/admin-challenges.php?tab=rumors');
        await draftCard(page, draft_item_id).locator('button[name="action"][value="quick_publish_rumor_item"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        await page.goto('/admin-challenges.php?tab=rumors&rumor_status=draft');
        await expect(draftCard(page, draft_item_id)).toHaveCount(0);

        await page.goto('/admin-challenges.php?tab=rumors&rumor_status=published');
        await expect(draftCard(page, draft_item_id)).toHaveAttribute('data-status', 'published');
    });
});

// ─── Rumor bulk multiselect (multi-update) ──────────────────────────────────────

test.describe('Admin rumor bulk actions', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    const card = (page, id) => page.locator(`[data-testid="rumor-item"][data-item-id="${id}"]`);

    // Unpublish selected reverts each checked item to draft in one POST; Publish flips them
    // back. Buttons stay disabled until at least one row is checked. Select-all is exercised
    // as a pure UI toggle, then cleared — the bulk submit only touches this test's own seeded
    // rows, since the shared test DB holds unrelated rumor rows a real publish must not disturb.
    test('bulk unpublish then bulk publish flips every selected item', async ({ page }) => {
        const { items } = await seed.rumorDeck({ real: [1, 0, 1] }); // 3 published (+ 1 draft)
        await page.goto('/admin-challenges.php?tab=rumors&rumor_status=all');

        const unpublishBtn = page.locator('#bulk-rumor button[value="bulk_unpublish_rumor"]');
        const publishBtn   = page.locator('#bulk-rumor button[value="bulk_publish_rumor"]');
        const boxFor = (id) => card(page, id).locator('input[name="ids[]"]');
        await expect(unpublishBtn).toBeDisabled();

        // Select-all checks every row and enables the actions; clear it before the real submit.
        const selectAll = page.locator('[data-bulk-toggle="rumor"]');
        await selectAll.check();
        await expect(boxFor(items[0].id)).toBeChecked();
        await expect(unpublishBtn).toBeEnabled();
        await selectAll.uncheck();

        for (const it of items) await boxFor(it.id).check();
        await unpublishBtn.click();
        await page.waitForURL(/admin-challenges\.php/);
        for (const it of items) await expect(card(page, it.id)).toHaveAttribute('data-status', 'draft');

        for (const it of items) await boxFor(it.id).check();
        await publishBtn.click();
        await page.waitForURL(/admin-challenges\.php/);
        for (const it of items) await expect(card(page, it.id)).toHaveAttribute('data-status', 'published');
    });

    // Bulk delete removes only the checked rows (native confirm — accept the dialog).
    test('bulk delete removes only the selected items', async ({ page }) => {
        const { items } = await seed.rumorDeck({ real: [1, 1, 1] });
        await page.goto('/admin-challenges.php?tab=rumors&rumor_status=all');

        await card(page, items[0].id).locator('input[name="ids[]"]').check();
        await card(page, items[1].id).locator('input[name="ids[]"]').check();

        page.once('dialog', d => d.accept());
        await page.locator('#bulk-rumor button[value="bulk_delete_rumor"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        await expect(card(page, items[0].id)).toHaveCount(0);
        await expect(card(page, items[1].id)).toHaveCount(0);
        await expect(card(page, items[2].id)).toHaveCount(1);
    });
});

// ─── Trivia authoring (Phase 4, REQ-503) ────────────────────────────────────────

test.describe('Admin trivia authoring', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // The blank "add question" form always renders first, with an empty question_id.
    function newQuestionForm(page) {
        return page.locator('[data-testid="trivia-question"][data-question-id=""]');
    }

    function questionCard(page, text) {
        return page.locator('[data-testid="trivia-question"]').filter({ hasText: text });
    }

    // topic='e2e-seed' doubles as the cleanup marker (challenge_trivia_questions has no
    // source_ref column to reuse, unlike challenge_items) — same convention seed_trivia_week
    // uses, so admin-authored fixtures here are swept by cleanup.challenges() too.
    async function fillNewQuestion(page, questionText) {
        const form = newQuestionForm(page);
        // "Add new" is a collapsed section by default (races.php's toggleForm pattern) —
        // open it before filling, or the fields are clipped (max-height:0) and .fill() hangs.
        await page.locator('#add-trivia-form .collapsible-header').click();
        await form.locator('textarea[name="question_da"]').fill(questionText);
        await form.locator('textarea[name="question_en"]').fill(questionText);
        await form.locator('input[name="option1_da"]').fill('McLaren');
        await form.locator('input[name="option1_en"]').fill('McLaren');
        await form.locator('input[name="option2_da"]').fill('Ferrari');
        await form.locator('input[name="option2_en"]').fill('Ferrari');
        await form.locator('select[name="correct_option"]').selectOption('1');
        await form.locator('input[name="topic"]').fill('e2e-seed');
        await form.locator('textarea[name="explain_da"]').fill('Test explanation');
        await form.locator('textarea[name="explain_en"]').fill('Test explanation');
        await form.locator('input[name="publish_date"]').fill(new Date().toISOString().slice(0, 10));
        return form;
    }

    // Save without Publish persists a new draft row (visible here, never on the public page).
    test('creating a question saves it as a draft', async ({ page }) => {
        const text = `E2E trivia question ${Date.now()}`;
        await page.goto('/admin-challenges.php?tab=trivia');
        const form = await fillNewQuestion(page, text);
        await form.locator('button[name="action"][value="save_trivia_question"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        const card = questionCard(page, text);
        await expect(card).toHaveCount(1);
        await expect(card).toHaveAttribute('data-status', 'draft');
    });

    // Status filter (like the Rumors tab): draft / published / all narrow the list.
    test('status filter narrows the list to drafts or published', async ({ page }) => {
        const { question_ids } = await seed.triviaWeek(); // 6 published, topic e2e-seed
        const publishedRow = page.locator('[data-testid="trivia-question"][data-question-id="' + question_ids[0] + '"]');

        const draftText = `E2E draft trivia ${Date.now()}`;
        await page.goto('/admin-challenges.php?tab=trivia');
        const form = await fillNewQuestion(page, draftText);
        await form.locator('button[name="action"][value="save_trivia_question"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        // Drafts only: the authored draft shows, a published question does not.
        await page.goto('/admin-challenges.php?tab=trivia&trivia_status=draft');
        await expect(questionCard(page, draftText)).toHaveCount(1);
        await expect(publishedRow).toHaveCount(0);

        // Published only: the reverse.
        await page.goto('/admin-challenges.php?tab=trivia&trivia_status=published');
        await expect(publishedRow).toHaveCount(1);
        await expect(questionCard(page, draftText)).toHaveCount(0);

        // All: both present.
        await page.goto('/admin-challenges.php?tab=trivia&trivia_status=all');
        await expect(publishedRow).toHaveCount(1);
        await expect(questionCard(page, draftText)).toHaveCount(1);
    });

    // Publish makes the question immediately playable on the public page — the admin→player
    // pipeline this screen exists to feed, mirroring the rumor-drafts Publish test above.
    test('Publish makes the question playable on the public page', async ({ page }) => {
        const text = `E2E trivia question ${Date.now()}`;
        await page.goto('/admin-challenges.php?tab=trivia');
        const form = await fillNewQuestion(page, text);
        await form.locator('button[name="action"][value="publish_trivia_question"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        await expect(questionCard(page, text)).toHaveAttribute('data-status', 'published');

        await page.context().clearCookies();
        await page.goto('/challenges.php?section=trivia');
        await expect(page.getByTestId('trivia-card')).toContainText(text);
    });

    // Delete works on any status (not just drafts) — this covers the draft case; removes
    // it outright, never reaches the public page.
    test('Delete removes a draft question', async ({ page }) => {
        const text = `E2E trivia question ${Date.now()}`;
        await page.goto('/admin-challenges.php?tab=trivia');
        const form = await fillNewQuestion(page, text);
        await form.locator('button[name="action"][value="save_trivia_question"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        const card = questionCard(page, text);
        await card.locator('button[name="action"][value="delete_trivia_question"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/admin-challenges\.php/);
        await expect(questionCard(page, text)).toHaveCount(0);
    });

    // A published question can now be deleted too (previously the delete button only ever
    // rendered for drafts) — the gap that left published questions permanently stuck.
    test('Delete removes a published question', async ({ page }) => {
        const text = `E2E trivia question ${Date.now()}`;
        await page.goto('/admin-challenges.php?tab=trivia');
        const form = await fillNewQuestion(page, text);
        await form.locator('button[name="action"][value="publish_trivia_question"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        const card = questionCard(page, text);
        await expect(card).toHaveAttribute('data-status', 'published');
        await card.locator('button[name="action"][value="delete_trivia_question"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/admin-challenges\.php/);
        await expect(questionCard(page, text)).toHaveCount(0);
    });
});

// ─── Trivia bulk multiselect (multi-update) ─────────────────────────────────────

test.describe('Admin trivia bulk actions', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    const card = (page, id) => page.locator(`[data-testid="trivia-question"][data-question-id="${id}"]`);

    // Bulk unpublish is net-new for trivia — before this there was no way to revert a published
    // question to draft at all (only publish or delete). Unpublish flips each checked question;
    // Publish flips them back. Select-all is exercised as a UI toggle then cleared — the submit
    // only touches this test's own seeded rows, not unrelated trivia in the shared test DB.
    test('bulk unpublish then bulk publish flips every question', async ({ page }) => {
        const { question_ids } = await seed.triviaWeek(); // 6 published, topic e2e-seed
        await page.goto('/admin-challenges.php?tab=trivia');

        const unpublishBtn = page.locator('#bulk-trivia button[value="bulk_unpublish_trivia"]');
        const publishBtn   = page.locator('#bulk-trivia button[value="bulk_publish_trivia"]');
        const boxFor = (id) => card(page, id).locator('input[name="ids[]"]');
        await expect(unpublishBtn).toBeDisabled();

        const selectAll = page.locator('[data-bulk-toggle="trivia"]');
        await selectAll.check();
        await expect(boxFor(question_ids[0])).toBeChecked();
        await expect(unpublishBtn).toBeEnabled();
        await selectAll.uncheck();

        for (const id of question_ids) await boxFor(id).check();
        await unpublishBtn.click();
        await page.waitForURL(/admin-challenges\.php/);
        for (const id of question_ids) await expect(card(page, id)).toHaveAttribute('data-status', 'draft');

        for (const id of question_ids) await boxFor(id).check();
        await publishBtn.click();
        await page.waitForURL(/admin-challenges\.php/);
        for (const id of question_ids) await expect(card(page, id)).toHaveAttribute('data-status', 'published');
    });

    // Bulk delete removes only the checked questions (native confirm — accept the dialog).
    test('bulk delete removes only the selected questions', async ({ page }) => {
        const { question_ids } = await seed.triviaWeek();
        await page.goto('/admin-challenges.php?tab=trivia');

        await card(page, question_ids[0]).locator('input[name="ids[]"]').check();
        await card(page, question_ids[1]).locator('input[name="ids[]"]').check();

        page.once('dialog', d => d.accept());
        await page.locator('#bulk-trivia button[value="bulk_delete_trivia"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        await expect(card(page, question_ids[0])).toHaveCount(0);
        await expect(card(page, question_ids[1])).toHaveCount(0);
        await expect(card(page, question_ids[2])).toHaveCount(1);
    });
});

// ─── Email suppressions (Feature 5, REQ-801–812) ────────────────────────────────

test.describe('Admin suppression list', { tag: '@challenges' }, () => {
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    function suppressionRow(page, email) {
        return page.locator(`[data-testid="suppression-row"][data-email="${email.toLowerCase()}"]`);
    }

    test('adding an email lists it, and Remove deletes it', async ({ page }) => {
        const email = tstEmail('suppress').toLowerCase();

        await page.goto('/admin-challenges.php?tab=suppressions');
        await page.fill('input[name="suppress_email"]', email);
        await page.locator('form:has(input[name="suppress_email"]) button[type="submit"]').click();

        await page.waitForURL(/admin-challenges\.php/);
        const row = suppressionRow(page, email);
        await expect(row).toBeVisible();

        await row.locator('button[name="action"][value="remove_suppression"]').click();
        await confirmDeleteModal(page);

        await page.waitForURL(/admin-challenges\.php/);
        await expect(suppressionRow(page, email)).toHaveCount(0);
    });
});
