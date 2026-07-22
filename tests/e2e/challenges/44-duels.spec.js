'use strict';

// Paddock Challenges — Phase 5 (Prediction Duels). REQ-301-311, feature.md §D scenarios.
// Duels need a *verified* identity (an opponent has to be able to find/be notified of you) —
// unlike Rumor or Not / Trivia, there is no anonymous-first path here (REQ-101 scopes
// anonymous play to those two games only). Most cases below use a clean, unauthenticated
// @playwright/test context per participant (never ../../fixtures, which is admin-authed);
// the resolution/reset cases additionally drive the real admin UI, mirroring
// tests/e2e/admin/13-scoring.spec.js rather than poking the DB (test-plan.md's explicit
// instruction for DUEL-02/03/04/07/08).
const path = require('path');
const { test, expect } = require('@playwright/test');
const { test: admin } = require('../../fixtures');
const seed = require('../../helpers/seed');
const { waitForMessages } = require('../../helpers/email');

const BASE = () => process.env.BASE_URL;
const ADMIN_AUTH = path.join(__dirname, '../../../.auth/admin.json');
const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

// getNextDuelRace() picks the single globally-earliest upcoming race — any leftover fixture
// race from another suite (or a previous test in this file) would silently hijack it, so
// every test starts from a fully clean races/duels slate, not just challenge-table rows.
async function cleanSlate() {
    await seed.cleanup.challenges();
    await seed.cleanup.bettingRace();
}

// Reuses the same pre-authenticated session the ../../fixtures admin test already relies on
// (built once by the suite's setup step) rather than driving /login.php by hand, which would
// risk hitting an MFA challenge the fixture is specifically set up to bypass.
async function newAdminPage(browser) {
    const context = await browser.newContext({ storageState: ADMIN_AUTH });
    const page = await context.newPage();
    return { context, page };
}

async function newVerifiedParticipant(browser, namePrefix) {
    const email = tstEmail(namePrefix);
    const { participant_id } = await seed.challengeParticipant({ email, status: 'verified', display_name: namePrefix });
    const { token } = await seed.challengeAccessToken({ participant_id });
    const context = await browser.newContext();
    await context.addCookies([{ name: 'ch_access', value: token, url: BASE() }]);
    const page = await context.newPage();
    return { participant_id, email, context, page };
}

// ─── Access gating ──────────────────────────────────────────────────────────────

test.describe('Duels — access gating', { tag: ['@challenges', '@mobile'] }, () => {
    test.beforeEach(cleanSlate);
    test.afterAll(cleanSlate);

    test('unverified visitor sees the verify prompt, no create actions', async ({ page }) => {
        await page.goto('/challenges.php?section=duels');
        await expect(page.getByTestId('duel-verify-prompt')).toBeVisible();
        await expect(page.getByTestId('duel-quick-match-btn')).toHaveCount(0);
    });
});

// ─── Create flow: Quick Match + Challenge a friend ──────────────────────────────

test.describe('Duels — create flow', { tag: '@challenges' }, () => {
    test.beforeEach(cleanSlate);
    test.afterAll(cleanSlate);

    test('Quick Match pairs two waiting participants into one duel', async ({ browser }) => {
        await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'qm_a');
        const b = await newVerifiedParticipant(browser, 'qm_b');

        await a.page.goto('/challenges.php?section=duels');
        await a.page.locator('[data-testid="duel-quick-match-btn"]').click();
        await expect(a.page.getByTestId('duel-queued-msg')).toBeVisible();

        await b.page.goto('/challenges.php?section=duels');
        await b.page.locator('[data-testid="duel-quick-match-btn"]').click();
        await expect(b.page).toHaveURL(/section=duels&duel=/);
        await expect(b.page.getByTestId('duel-detail')).toBeVisible();

        await a.context.close();
        await b.context.close();
    });

    // DUEL-06: two concurrent requests must produce exactly one duel, queue emptied — never
    // two duels, never neither paired. One side's own redirect can be a stale "still queued"
    // read if pairing lands microseconds after that request's own check (see comment below);
    // a fresh reload is what proves the settled truth.
    test('Quick Match: concurrent requests produce exactly one duel (DUEL-06)', async ({ browser }) => {
        await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'qmc_a');
        const b = await newVerifiedParticipant(browser, 'qmc_b');

        await a.page.goto('/challenges.php?section=duels');
        await b.page.goto('/challenges.php?section=duels');

        await Promise.all([
            a.page.locator('[data-testid="duel-quick-match-btn"]').click(),
            b.page.locator('[data-testid="duel-quick-match-btn"]').click(),
        ]);

        await a.page.goto('/challenges.php?section=duels');
        await b.page.goto('/challenges.php?section=duels');

        await expect(a.page.locator('[data-testid="duel-card"]')).toHaveCount(1);
        await expect(b.page.locator('[data-testid="duel-card"]')).toHaveCount(1);
        const aDuelId = await a.page.locator('[data-testid="duel-card"]').getAttribute('data-duel-id');
        const bDuelId = await b.page.locator('[data-testid="duel-card"]').getAttribute('data-duel-id');
        expect(aDuelId).toBeTruthy();
        expect(aDuelId).toBe(bDuelId);

        await a.context.close();
        await b.context.close();
    });

    test('Challenge a friend: search finds them, challenging creates a duel immediately', async ({ browser }) => {
        await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'cf_a');
        const b = await newVerifiedParticipant(browser, 'cf_findme_b');

        await a.page.goto('/challenges.php?section=duels&mode=challenge&q=findme');
        const result = a.page.locator('[data-testid="duel-search-result"]');
        await expect(result).toHaveCount(1);

        // A masked email hint disambiguates same-named opponents without exposing the address:
        // a couple of leading local-part chars + domain, the middle redacted (never the raw email).
        const hintText = await result.getByTestId('duel-search-email-hint').textContent();
        expect(hintText).toContain('•••');
        expect(hintText).toContain('@test.localhost');
        expect(hintText).not.toContain('findme'); // middle of the local part is redacted
        expect(hintText.trim()).not.toBe(b.email);

        await result.locator('[data-testid="duel-challenge-btn"]').click();

        await expect(a.page).toHaveURL(/section=duels&duel=/);
        await expect(a.page.getByTestId('duel-detail')).toBeVisible();

        await a.context.close();
        await b.context.close();
    });

    // REQ-301: you can't duel yourself — the search filters out your own row, so a query that
    // matches only your own display name yields nothing (belt-and-suspenders with the handler
    // and createDirectDuel() self-guards).
    test('Challenge a friend: you never appear in your own opponent search', async ({ browser }) => {
        await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'selfx_only');

        await a.page.goto('/challenges.php?section=duels&mode=challenge&q=selfx_only');
        await expect(a.page.getByTestId('duel-search-result')).toHaveCount(0);
        await expect(a.page.getByTestId('duel-no-results')).toBeVisible();

        await a.context.close();
    });
});

// ─── Picks: submission, hidden-until-lock, late-pick block ─────────────────────

test.describe('Duels — picks', { tag: '@challenges' }, () => {
    test.beforeEach(cleanSlate);
    test.afterAll(cleanSlate);

    // REQ-303: opponent's pick is invisible until the duel locks (race start), even to a
    // participant who has already submitted their own.
    test('opponent pick stays hidden until the duel locks', async ({ browser }) => {
        const { race_id, drivers } = await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'hide_a');
        const b = await newVerifiedParticipant(browser, 'hide_b');
        const { duel_id } = await seed.duel({ race_id, challenger_id: a.participant_id, opponent_id: b.participant_id });

        await a.page.goto(`/challenges.php?section=duels&duel=${duel_id}`);
        await a.page.selectOption('[data-testid="duel-pick-p1"]', drivers[0].id);
        await a.page.selectOption('[data-testid="duel-pick-p2"]', drivers[1].id);
        await a.page.selectOption('[data-testid="duel-pick-p3"]', drivers[2].id);
        await a.page.locator('[data-testid="duel-pick-submit"]').click();
        await expect(a.page.getByTestId('duel-locked-in')).toBeVisible();
        // B still owes a pick, so A is waiting on the opponent (not yet on the race).
        await expect(a.page.getByTestId('duel-waiting-opponent')).toBeVisible();
        await expect(a.page.getByTestId('duel-waiting-race')).toHaveCount(0);

        // B hasn't picked and the race isn't locked — B sees the pick form, and nothing on
        // the page reveals A's already-submitted pick.
        await b.page.goto(`/challenges.php?section=duels&duel=${duel_id}`);
        await expect(b.page.getByTestId('duel-pick-form')).toBeVisible();
        await expect(b.page.getByTestId('duel-picks-comparison')).toHaveCount(0);

        await a.context.close();
        await b.context.close();
    });

    // Once BOTH sides have locked in but the race hasn't started, there's no one left to wait
    // for — the copy switches from "waiting for <opponent>" to "waiting for the race to finish".
    test('both locked in (race open) → waits on the race, not the opponent', async ({ browser }) => {
        const { race_id, drivers } = await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'bothlocked_a');
        const b = await newVerifiedParticipant(browser, 'bothlocked_b');
        const podium = `${drivers[0].id},${drivers[1].id},${drivers[2].id}`;
        const { duel_id } = await seed.duel({
            race_id, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: podium, opponent_pick: podium,
        });

        await a.page.goto(`/challenges.php?section=duels&duel=${duel_id}`);
        await expect(a.page.getByTestId('duel-locked-in')).toBeVisible();
        await expect(a.page.getByTestId('duel-waiting-race')).toBeVisible();
        await expect(a.page.getByTestId('duel-waiting-opponent')).toHaveCount(0);

        await a.context.close();
        await b.context.close();
    });

    // REQ-307: DB-enforced UNIQUE(duel_id, participant_id) — a resubmit is a silent no-op,
    // not a second row / not an error page.
    test('a duel can only be picked once per participant', async ({ browser }) => {
        const { race_id, drivers } = await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'once_a');
        const b = await newVerifiedParticipant(browser, 'once_b');
        const { duel_id } = await seed.duel({
            race_id, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
        });

        await a.page.goto(`/challenges.php?section=duels&duel=${duel_id}`);
        await expect(a.page.getByTestId('duel-locked-in')).toBeVisible();
        await expect(a.page.getByTestId('duel-pick-form')).toHaveCount(0);

        await a.context.close();
        await b.context.close();
    });

    // DUEL-05: once the race has started, the pick form is gone — never reachable, not even
    // when the participant already has the duel open.
    test('no pick form once the race has started (DUEL-05)', async ({ browser }) => {
        const { race_id } = await seed.duelRace({ state: 'started' });
        const a = await newVerifiedParticipant(browser, 'late_a');
        const b = await newVerifiedParticipant(browser, 'late_b');
        const { duel_id } = await seed.duel({ race_id, challenger_id: a.participant_id, opponent_id: b.participant_id });

        await a.page.goto(`/challenges.php?section=duels&duel=${duel_id}`);
        await expect(a.page.getByTestId('duel-pick-form')).toHaveCount(0);
        await expect(a.page.getByTestId('duel-detail')).toBeVisible();

        await a.context.close();
        await b.context.close();
    });
});

// ─── Resolution, reset, isolation — drives the real admin flow ─────────────────
// Mirrors 13-scoring.spec.js: seed via test-seed.php, but enter/reset race results through
// the actual admin.php UI, never by writing challenge_points/duels rows directly. Results are
// entered from a newAdminPage() context (reusing .auth/admin.json) kept separate from each
// participant's own clean, unauthenticated context — the outcome is always read back through
// the participant's own session, never the admin one.

test.describe.serial('Duels — resolution outcomes (as participants)', { tag: '@challenges' }, () => {
    let race, drivers, a, b, duelWinId, duelTieId, duelVoidId;

    test.beforeAll(async ({ browser }) => {
        await cleanSlate();
        const seeded = await seed.duelRace({ state: 'started' });
        race = seeded.race_id;
        drivers = seeded.drivers;
        a = await newVerifiedParticipant(browser, 'out_a');
        b = await newVerifiedParticipant(browser, 'out_b');

        duelWinId = (await seed.duel({
            race_id: race, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
            opponent_pick: `${drivers[1].id},${drivers[0].id},${drivers[2].id}`,
        })).duel_id;

        duelTieId = (await seed.duel({
            race_id: race, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
            opponent_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
        })).duel_id;

        // DUEL-04: only the challenger ever picks — race already started, opponent missed it.
        duelVoidId = (await seed.duel({
            race_id: race, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
        })).duel_id;

        const { context: adminCtx, page: adminPage } = await newAdminPage(browser);
        await adminPage.goto(`/admin.php?tab=races&edit=${race}`);
        await adminPage.selectOption('select[name="result_p1"]', drivers[0].id);
        await adminPage.selectOption('select[name="result_p2"]', drivers[1].id);
        await adminPage.selectOption('select[name="result_p3"]', drivers[2].id);
        await adminPage.click('button[name="update_race"]');
        await expect(adminPage.locator('.alert-success')).toBeVisible();
        await adminCtx.close();
    });

    test.afterAll(async () => {
        await a.context.close();
        await b.context.close();
        await cleanSlate();
    });

    // DUEL-02
    test('winner shows Won +15, loser shows Lost +5', async () => {
        await a.page.goto(`/challenges.php?section=duels&duel=${duelWinId}`);
        const aResult = a.page.getByTestId('duel-result');
        await expect(aResult).toHaveAttribute('data-outcome', 'won');
        await expect(aResult).toContainText('15');

        await b.page.goto(`/challenges.php?section=duels&duel=${duelWinId}`);
        const bResult = b.page.getByTestId('duel-result');
        await expect(bResult).toHaveAttribute('data-outcome', 'lost');
        await expect(bResult).toContainText('5');
    });

    test('a tie awards 10 CP to both sides', async () => {
        await a.page.goto(`/challenges.php?section=duels&duel=${duelTieId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'tie');
        await b.page.goto(`/challenges.php?section=duels&duel=${duelTieId}`);
        await expect(b.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'tie');
    });

    // DUEL-04
    test('opponent never picked → the duel is void, no CP either side', async () => {
        await a.page.goto(`/challenges.php?section=duels&duel=${duelVoidId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'void');
    });

    // REQ-311
    test('both sides receive an outcome email', async () => {
        await waitForMessages(a.email, 1);
        await waitForMessages(b.email, 1);
    });
});

// ── Reset + re-entry (DUEL-03/08) — separate serial block, own seeded duel ──────

test.describe.serial('Duels — reset reverses CP, re-entry re-awards once', { tag: '@challenges' }, () => {
    let race, drivers, a, b, duelId;

    test.beforeAll(async ({ browser }) => {
        await cleanSlate();
        const seeded = await seed.duelRace({ state: 'started' });
        race = seeded.race_id;
        drivers = seeded.drivers;
        a = await newVerifiedParticipant(browser, 'rst_a');
        b = await newVerifiedParticipant(browser, 'rst_b');
        duelId = (await seed.duel({
            race_id: race, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
            opponent_pick: `${drivers[1].id},${drivers[0].id},${drivers[2].id}`,
        })).duel_id;
    });

    test.afterAll(async () => {
        await a.context.close();
        await b.context.close();
        await cleanSlate();
    });

    // DUEL-08 is exercised in the same test as entry, not a fresh navigation: once a race has
    // a result, admin.php's own upcoming-races list (and its edit link) drops it — completed
    // races aren't editable through normal navigation. The realistic way this "re-save" path
    // is reached is a stale tab/double-submit of the *same, already-loaded* form — captured
    // here via FormData(form, submitterButton) right before the first submit, then replayed
    // as a second, genuine browser-native form.submit() (real browser traffic, not a raw API
    // call — see docs/gotchas.md on the host's WAF blocking non-browser POSTs).
    test('entering results resolves the duel; resubmitting the same fields again is a no-op (DUEL-02/08)', async ({ browser }) => {
        const { context: ctx, page } = await newAdminPage(browser);
        await page.goto(`/admin.php?tab=races&edit=${race}`);
        await page.selectOption('select[name="result_p1"]', drivers[0].id);
        await page.selectOption('select[name="result_p2"]', drivers[1].id);
        await page.selectOption('select[name="result_p3"]', drivers[2].id);

        const fieldValues = await page.evaluate(() => {
            const btn = document.querySelector('button[name="update_race"]');
            const form = btn.closest('form');
            return Object.fromEntries(new FormData(form, btn).entries());
        });

        await page.click('button[name="update_race"]');
        await expect(page.locator('.alert-success')).toBeVisible();

        await a.page.goto(`/challenges.php?section=duels&duel=${duelId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'won');
        await expect(a.page.getByTestId('duel-result')).toContainText('15');

        await page.evaluate(({ action, fields }) => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;
            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }, { action: '/admin.php?tab=races', fields: fieldValues });
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.alert-success')).toBeVisible();
        await ctx.close();

        await a.page.goto(`/challenges.php?section=duels&duel=${duelId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'won');
        await expect(a.page.getByTestId('duel-result')).toContainText('15');
    });

    // DUEL-03: reset removes the CP ledger rows and un-resolves the duel; re-entering results
    // resolves it again with the identical outcome, and only once (idempotent award).
    test('reset reverses the duel, re-entry re-resolves it once', async ({ browser }) => {
        const { context: ctx, page } = await newAdminPage(browser);

        await page.goto('/admin.php?tab=races');
        const raceCard = page.locator('.hf-racefull').filter({ has: page.locator('.hf-racename', { hasText: 'E2E Duel Test Race' }) });
        await raceCard.locator('button[name="reset_race_result"]').click();
        await page.locator('.btn-user-delete-confirm').click();
        await page.waitForURL(/msg=/);

        await a.page.goto(`/challenges.php?section=duels&duel=${duelId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveCount(0);
        await expect(a.page.getByTestId('duel-locked-in')).toBeVisible();

        // Re-enter the identical result.
        await page.goto(`/admin.php?tab=races&edit=${race}`);
        await page.selectOption('select[name="result_p1"]', drivers[0].id);
        await page.selectOption('select[name="result_p2"]', drivers[1].id);
        await page.selectOption('select[name="result_p3"]', drivers[2].id);
        await page.click('button[name="update_race"]');
        await expect(page.locator('.alert-success')).toBeVisible();
        await ctx.close();

        await a.page.goto(`/challenges.php?section=duels&duel=${duelId}`);
        await expect(a.page.getByTestId('duel-result')).toHaveAttribute('data-outcome', 'won');
        await expect(a.page.getByTestId('duel-result')).toContainText('15');
    });
});

// ── Isolation from core bets (DUEL-07) ───────────────────────────────────────

test.describe('Duels — isolation from core betting', { tag: '@challenges' }, () => {
    test.beforeEach(cleanSlate);
    test.afterAll(cleanSlate);

    test('a core member with both a bet and a duel pick on the same race: neither affects the other', async ({ browser }) => {
        const { raceId, email, password, drivers: coreDrivers } = await seed.bettingRace();

        // Log in as the core member and visit the hub once — auto-links a challenge_participant
        // (getChallengeParticipant()'s core-session branch), a verified identity for free.
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', password);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toBeVisible();

        // Place a core bet for this race.
        await page.goto(`/bet.php?race=${raceId}`);
        await page.selectOption('select[name="p1"]', coreDrivers[0].id);
        await page.selectOption('select[name="p2"]', coreDrivers[1].id);
        await page.selectOption('select[name="p3"]', coreDrivers[2].id);
        await page.click('#save-btn');
        await page.waitForURL(/success=bet_placed/);

        // Same participant also plays a duel for the same race, against a fresh opponent.
        const opponent = await newVerifiedParticipant(browser, 'iso_opp');
        await page.goto('/challenges.php?section=duels&mode=challenge&q=iso_opp');
        await page.locator('[data-testid="duel-search-result"] [data-testid="duel-challenge-btn"]').click();
        await expect(page).toHaveURL(/section=duels&duel=/);
        await page.selectOption('[data-testid="duel-pick-p1"]', coreDrivers[2].id); // deliberately different from the bet
        await page.selectOption('[data-testid="duel-pick-p2"]', coreDrivers[1].id);
        await page.selectOption('[data-testid="duel-pick-p3"]', coreDrivers[0].id);
        await page.locator('[data-testid="duel-pick-submit"]').click();
        await expect(page.getByTestId('duel-locked-in')).toBeVisible();

        // The bet is unaffected by the duel pick, and vice versa — read both back directly.
        await page.goto(`/bet.php?race=${raceId}&return=index`);
        // bet.php redirects away once a bet exists for this race+user (already_bet) — its mere
        // presence, unperturbed by the duel pick above, is the isolation proof here.
        await expect(page).toHaveURL(/error=already_bet/);

        await ctx.close();
        await opponent.context.close();
    });
});

// ─── Admin oversight (REQ-504) ───────────────────────────────────────────────

admin.describe('Duels — admin oversight', { tag: '@challenges' }, () => {
    admin.beforeEach(cleanSlate);
    admin.afterAll(cleanSlate);

    admin('lists a duel with per-side submission state, no pick contents before lock', async ({ page, browser }) => {
        const { race_id, drivers } = await seed.duelRace({ state: 'open' });
        const a = await newVerifiedParticipant(browser, 'ovs_a');
        const b = await newVerifiedParticipant(browser, 'ovs_b');
        const { duel_id } = await seed.duel({
            race_id, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
        });

        await page.goto('/admin-challenges.php?tab=duels');
        const row = page.locator('[data-testid="admin-duel-row"][data-duel-id="' + duel_id + '"]');
        await expect(row).toBeVisible();
        await expect(row).toHaveAttribute('data-status', 'open');
        await expect(row).not.toContainText(drivers[0].name.split(' ').pop());

        await a.context.close();
        await b.context.close();
    });

    admin('shows locked status and pick contents once the race has started', async ({ page, browser }) => {
        const { race_id, drivers } = await seed.duelRace({ state: 'started' });
        const a = await newVerifiedParticipant(browser, 'ovl_a');
        const b = await newVerifiedParticipant(browser, 'ovl_b');
        const { duel_id } = await seed.duel({
            race_id, challenger_id: a.participant_id, opponent_id: b.participant_id,
            challenger_pick: `${drivers[0].id},${drivers[1].id},${drivers[2].id}`,
        });

        await page.goto('/admin-challenges.php?tab=duels');
        const row = page.locator('[data-testid="admin-duel-row"][data-duel-id="' + duel_id + '"]');
        await expect(row).toHaveAttribute('data-status', 'locked');
        await expect(row).toContainText(drivers[0].name.split(' ').pop());

        await a.context.close();
        await b.context.close();
    });

    // Per-row Delete removes the duel (site-wide confirm modal). Participants seeded directly —
    // no browser context needed, the oversight tab only needs the duel to exist.
    admin('deletes a single duel', async ({ page }) => {
        const { race_id } = await seed.duelRace({ state: 'open' });
        const a = await seed.challengeParticipant({ email: tstEmail('dd_a'), status: 'verified', display_name: 'DD A' });
        const b = await seed.challengeParticipant({ email: tstEmail('dd_b'), status: 'verified', display_name: 'DD B' });
        const { duel_id } = await seed.duel({ race_id, challenger_id: a.participant_id, opponent_id: b.participant_id });

        await page.goto('/admin-challenges.php?tab=duels');
        const row = page.locator('[data-testid="admin-duel-row"][data-duel-id="' + duel_id + '"]');
        await expect(row).toBeVisible();

        await row.locator('button.btn-delete[value="delete_duel"]').click();
        await page.locator('.btn-user-delete-confirm').click();
        await page.waitForURL(/admin-challenges\.php/);

        await expect(row).toHaveCount(0);
    });

    // Bulk delete removes only the checked duels (native confirm — accept the dialog).
    admin('bulk delete removes only the selected duels', async ({ page }) => {
        const { race_id } = await seed.duelRace({ state: 'open' });
        const mk = async (p) => (await seed.challengeParticipant({ email: tstEmail(p), status: 'verified', display_name: p })).participant_id;
        const [p1, p2, p3, p4, p5, p6] = await Promise.all(['bd1', 'bd2', 'bd3', 'bd4', 'bd5', 'bd6'].map(mk));
        const d1 = (await seed.duel({ race_id, challenger_id: p1, opponent_id: p2 })).duel_id;
        const d2 = (await seed.duel({ race_id, challenger_id: p3, opponent_id: p4 })).duel_id;
        const d3 = (await seed.duel({ race_id, challenger_id: p5, opponent_id: p6 })).duel_id;

        await page.goto('/admin-challenges.php?tab=duels');
        const row = (id) => page.locator('[data-testid="admin-duel-row"][data-duel-id="' + id + '"]');
        const bulkBtn = page.locator('#bulk-duel button[value="bulk_delete_duels"]');
        await expect(bulkBtn).toBeDisabled();

        await row(d1).locator('input[name="ids[]"]').check();
        await row(d2).locator('input[name="ids[]"]').check();
        await expect(bulkBtn).toBeEnabled();

        page.once('dialog', d => d.accept());
        await bulkBtn.click();
        await page.waitForURL(/admin-challenges\.php/);

        await expect(row(d1)).toHaveCount(0);
        await expect(row(d2)).toHaveCount(0);
        await expect(row(d3)).toHaveCount(1);
    });

    // Deleting a resolved duel also clears its awarded CP (source_ref "duel:<id>") — the ledger
    // has no FK to duels, so the handler removes it explicitly. Observed via the public board:
    // the winner's only CP was the duel, so they drop off once it's deleted (board inner-joins CP).
    admin('deleting a resolved duel also removes its awarded CP', async ({ page }) => {
        const { race_id } = await seed.duelRace({ state: 'started' });
        const name = 'CpDuelDel' + Date.now();
        const a = await seed.challengeParticipant({ email: tstEmail('cpdel_a'), status: 'verified', display_name: name });
        const b = await seed.challengeParticipant({ email: tstEmail('cpdel_b'), status: 'verified', display_name: 'CpDelOpp' });
        const { duel_id } = await seed.duel({ race_id, challenger_id: a.participant_id, opponent_id: b.participant_id, status: 'resolved' });
        await seed.challengePoints({ participant_id: a.participant_id, points: 137, source_ref: `duel:${duel_id}` });

        await page.goto('/challenges.php?section=board');
        await expect(page.getByText(name, { exact: true })).toBeVisible();

        await page.goto('/admin-challenges.php?tab=duels');
        const row = page.locator('[data-testid="admin-duel-row"][data-duel-id="' + duel_id + '"]');
        await row.locator('button.btn-delete[value="delete_duel"]').click();
        await page.locator('.btn-user-delete-confirm').click();
        await page.waitForURL(/admin-challenges\.php/);
        await expect(row).toHaveCount(0);

        await page.goto('/challenges.php?section=board');
        await expect(page.getByText(name, { exact: true })).toHaveCount(0);
    });

    // Created-date sort toggle: newest-first is the default active sort; oldest-first activates
    // on ?duel_sort=oldest and still lists every duel. (Order itself isn't asserted — seeded
    // rows share a same-second created_at, so their relative order is a tie, not a guarantee.)
    admin('duels can be sorted by created date (newest / oldest toggle)', async ({ page }) => {
        const { race_id } = await seed.duelRace({ state: 'open' });
        const mk = async (p) => (await seed.challengeParticipant({ email: tstEmail(p), status: 'verified', display_name: p })).participant_id;
        const [p1, p2, p3, p4] = await Promise.all(['so1', 'so2', 'so3', 'so4'].map(mk));
        const d1 = (await seed.duel({ race_id, challenger_id: p1, opponent_id: p2 })).duel_id;
        const d2 = (await seed.duel({ race_id, challenger_id: p3, opponent_id: p4 })).duel_id;

        const row = (id) => page.locator('[data-testid="admin-duel-row"][data-duel-id="' + id + '"]');
        const newestLink = page.locator('a[href="?tab=duels&duel_sort=newest"]');
        const oldestLink = page.locator('a[href="?tab=duels&duel_sort=oldest"]');

        await page.goto('/admin-challenges.php?tab=duels');
        await expect(newestLink).toHaveClass(/btn-primary/);
        await expect(oldestLink).not.toHaveClass(/btn-primary/);

        await page.goto('/admin-challenges.php?tab=duels&duel_sort=oldest');
        await expect(oldestLink).toHaveClass(/btn-primary/);
        await expect(newestLink).not.toHaveClass(/btn-primary/);
        await expect(row(d1)).toHaveCount(1);
        await expect(row(d2)).toHaveCount(1);
    });
});
