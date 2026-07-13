'use strict';

// Paddock Challenges — Phase 4 (Trivia). REQ-401-407, feature.md §E scenarios.
// Anonymous play (REQ-101/CH-13): each test uses a fresh, cookie-less context so
// getOrCreateAnonymousParticipant() fires on the first answer — no join/verify flow needed,
// same convention as 42-rumor.spec.js.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

const BASE = () => process.env.BASE_URL;
const CRON_SECRET = process.env.CRON_SECRET;
const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

test.describe('Trivia', { tag: ['@challenges', '@mobile'] }, () => {
    // Trivia questions aren't participant-scoped (the current-week query reads the whole
    // published table), so a leftover question from one test would pollute the next fresh
    // participant's queue — clean before AND after every test, not just once per file.
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // TRIV-01: correct option (index 0 — seed's fixed correct_option) → +5 CP, check icon.
    test('correct option awards 5 CP and reveals the check', async ({ page }) => {
        await seed.triviaWeek({ week_offset: 0 });

        await page.goto('/challenges.php?section=trivia');
        await expect(page.getByTestId('trivia-card')).toBeVisible();

        await page.locator('[data-testid="trivia-option"][data-idx="0"]').click();
        await page.waitForURL(/section=trivia&revealed=/);

        const result = page.getByTestId('trivia-result');
        await expect(result).toHaveAttribute('data-correct', '1');
        // Anonymous participants get no CP chip in the header (REQ-005 — chip is
        // verified/core-only); the award itself is proven by the reveal panel's own CP text.
        await expect(result).toContainText('5 CP');
    });

    // TRIV-01: wrong option → 0 CP, correct option (idx 0) highlighted alongside the miss.
    test('wrong option awards nothing and reveals the correct option', async ({ page }) => {
        await seed.triviaWeek({ week_offset: 0 });

        await page.goto('/challenges.php?section=trivia');
        await page.locator('[data-testid="trivia-option"][data-idx="1"]').click();
        await page.waitForURL(/section=trivia&revealed=/);

        const result = page.getByTestId('trivia-result');
        await expect(result).toHaveAttribute('data-correct', '0');
        // No CP line at all on a miss (mirrors REQ-205's rumor equivalent) — the template
        // only prints it when correct.
        await expect(result).not.toContainText('CP');
        await expect(page.locator('[data-testid="trivia-option"][data-idx="0"]')).toContainText('A');
    });

    // TRIV-01 (once-only): revisiting an answered question's reveal URL shows the original
    // result rather than re-prompting — DB-enforced UNIQUE(participant_id, question_id)
    // rejects a resubmission and the POST handler just redirects back to the same reveal.
    test('an answered question stays answered on revisit', async ({ page }) => {
        const { question_ids } = await seed.triviaWeek({ week_offset: 0 });

        await page.goto('/challenges.php?section=trivia');
        await page.locator('[data-testid="trivia-option"][data-idx="0"]').click();
        await page.waitForURL(/section=trivia&revealed=/);

        await page.goto(`/challenges.php?section=trivia&revealed=${question_ids[0]}`);
        const result = page.getByTestId('trivia-result');
        await expect(result).toHaveAttribute('data-correct', '1');
        await expect(result).toContainText('5 CP');
    });

    // TRIV-05/REQ-402: a question from last ISO week is never playable this week — a stale
    // form (e.g. a tab left open since last week) that still posts an old question_id is
    // rejected, not recorded, and this week's own card is left untouched.
    test("a question from last ISO week is rejected, this week's card is unaffected", async ({ page }) => {
        const { question_ids: staleIds } = await seed.triviaWeek({ week_offset: -1 });
        await seed.triviaWeek({ week_offset: 0 });

        await page.goto('/challenges.php?section=trivia');
        await expect(page.getByTestId('trivia-card')).toBeVisible();

        // Simulate a stale form (a tab left open since last week) by rewriting the live form's
        // hidden question_id to last week's id, then submitting via a real browser click — a
        // raw APIRequestContext POST gets blocked by the host's WAF as non-browser traffic
        // (see docs/gotchas.md), unrelated to the app logic this test is actually exercising.
        const optionZero = page.locator('[data-testid="trivia-option"][data-idx="0"]');
        await optionZero.locator('xpath=ancestor::form').evaluate((form, staleId) => {
            form.querySelector('input[name="question_id"]').value = staleId;
        }, staleIds[0]);
        await optionZero.click();

        // Rejected — no reveal, redirected back to the plain (still-unanswered) trivia section.
        await page.waitForURL(/section=trivia$/);
        await expect(page.getByTestId('trivia-card')).toBeVisible();
    });

    // TRIV-04: the Overview Perfect Week tracker's filled-segment count is exactly this
    // participant's correct-answer count for the current ISO week.
    test('Perfect Week tracker fill matches correct answers this week', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('trivia_pw'), status: 'verified' });
        const { token } = await seed.challengeAccessToken({ participant_id });
        await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);

        await seed.triviaWeek({ week_offset: 0, participant_id, correct: '1,1,1,0,0,0' });

        await page.goto('/challenges.php?section=overview');
        await expect(page.getByTestId('perfect-week-tracker')).toHaveAttribute('data-filled', '3');
    });
});

// ─── Weekly cron (REQ-405/406) ───────────────────────────────────────────────

test.describe('Trivia weekly cron', { tag: '@challenges' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // No explicit status code here, matching notifications.php/import_qualifying.php: the
    // message is echoed before any status could be set, so headers are already committed to
    // the PHP default 200 by then — same reason those two scripts' own "unauthorized" tests
    // (07-cron.spec.js) only assert on body text, never on res.status().
    test('unauthorized without a valid CRON_SECRET', async ({ page }) => {
        await page.goto('/cron/challenge_weekly.php');
        const text = await page.textContent('body');
        expect(text).toContain('Unauthorized access');
    });

    // TRIV-02: a participant who went 6/6 on the previous ISO week gets exactly one +20 CP
    // ledger entry when the cron runs — and a second run is a no-op (idempotent).
    test('6/6 previous week awards Perfect Week once, a second run adds nothing', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('cron_perfect'), status: 'verified' });
        await seed.triviaWeek({ week_offset: -1, participant_id, correct: '1,1,1,1,1,1' });

        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${CRON_SECRET}` });

        const first = await page.goto('/cron/challenge_weekly.php');
        expect(first.status()).toBe(200);
        let text = await page.textContent('body');
        expect(text).toContain('Weekly challenge cron complete');
        expect(text).toContain('1 newly awarded');

        const second = await page.goto('/cron/challenge_weekly.php');
        expect(second.status()).toBe(200);
        text = await page.textContent('body');
        expect(text).toContain('0 newly awarded');
    });

    // TRIV-03: 5 of 6 correct → no Perfect Week bonus.
    test('5/6 previous week awards no Perfect Week bonus', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('cron_5of6'), status: 'verified' });
        await seed.triviaWeek({ week_offset: -1, participant_id, correct: '1,1,1,1,1,0' });

        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${CRON_SECRET}` });
        const res = await page.goto('/cron/challenge_weekly.php');
        expect(res.status()).toBe(200);
        const text = await page.textContent('body');
        expect(text).toContain('0 perfect, 0 newly awarded');
    });

    // TRIV-03: an ISO week with no published questions is skipped outright — no award, no denial.
    test('an empty previous week is skipped, not evaluated', async ({ page }) => {
        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${CRON_SECRET}` });
        const res = await page.goto('/cron/challenge_weekly.php');
        expect(res.status()).toBe(200);
        const text = await page.textContent('body');
        expect(text).toMatch(/no published questions, skipped/);
    });
});
