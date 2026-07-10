'use strict';
// E2E coverage for the single-race focus page (public/race.php).
// Verifies the four-state schedule box, qualifying/race countdowns, login affordances,
// result blocks, and the always-expanded scored bets list — using a dedicated seed
// (seed_race_page) that creates one OPEN race (with qualifying timing) and one COMPLETED race.
const { test, expect } = require('@playwright/test');
const seed = require('../helpers/seed');

test.describe.serial('Single-race page (race.php)', { tag: '@race-page' }, () => {
    let data;
    let anonCtx, anonPage;     // logged-out viewer
    let authCtx, authPage;     // logged-in in-competition viewer

    test.beforeAll(async ({ browser }) => {
        await seed.cleanup.racePage();
        data = await seed.racePage();

        anonCtx  = await browser.newContext();
        anonPage = await anonCtx.newPage();

        authCtx  = await browser.newContext();
        authPage = await authCtx.newPage();
        await authPage.goto(`${process.env.BASE_URL}/login.php`);
        await authPage.fill('input[name="email"]',    data.email);
        await authPage.fill('input[name="password"]', data.password);
        await authPage.click('button[type="submit"]');
        await authPage.waitForURL(/index\.php/, { timeout: 8000 });
    });

    test.afterAll(async () => {
        await anonCtx?.close();
        await authCtx?.close();
        await seed.cleanup.racePage();
    });

    // ── OPEN state ────────────────────────────────────────────────────────────

    test('open race: both qualifying and race countdowns ticking in the schedule box', async () => {
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);

        const schedule = anonPage.locator('.race-schedule');
        await expect(schedule).toBeVisible();

        // Two live countdowns (no results yet) → both carry data-target for the ticker
        await expect(schedule.locator('.countdown-timer[data-target] i.fa-stopwatch')).toBeVisible();      // quali starts
        await expect(schedule.locator('.countdown-timer[data-target] i.fa-flag-checkered')).toBeVisible(); // race starts
        // None are in the finished "done" state yet
        await expect(schedule.locator('.countdown-timer.done')).toHaveCount(0);
    });

    test('open race: qualifying meta line + pool row render', async () => {
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);
        // Qualifying meta line present (quali_date set) — stopwatch icon, no "Kval." prefix
        await expect(anonPage.locator('.hf-racemeta i.fa-stopwatch')).toBeVisible();
        // Pool size shown with the gold treatment, value 250
        await expect(anonPage.locator('.race-schedule .bettingpool_size').last()).toContainText('250');
    });

    test('open race: results show dashed placeholders (never absent)', async () => {
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);
        // Qualifying + race result both pending → two placeholders, no position badges
        await expect(anonPage.locator('.result-pending')).toHaveCount(2);
        await expect(anonPage.locator('.position-badge')).toHaveCount(0);
    });

    test('open race, logged OUT: both login affordances appear', async () => {
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);
        await expect(anonPage.locator('.race-login-mini')).toBeVisible();          // mini, in the schedule box
        await expect(anonPage.locator('.race-login-cta')).toBeVisible();           // banner above the bets
        // Both affordances route to login with a redirect back to this race
        await expect(anonPage.locator('.race-login-mini')).toHaveAttribute('href', /login\.php\?redirect=/);
        await expect(anonPage.locator('.race-login-cta a')).toHaveAttribute('href', /login\.php\?redirect=/);
    });

    test('open race, logged IN competitor: no login affordances, place-bet shown instead', async () => {
        await authPage.goto(`/race.php?id=${data.openRaceId}`);
        await expect(authPage.locator('.race-login-mini')).toHaveCount(0);
        await expect(authPage.locator('.race-login-cta')).toHaveCount(0);
        await expect(authPage.locator('a[href*="bet.php?race="]')).toBeVisible();   // place-bet CTA
    });

    test('open race: no horizontal scroll at 320px', { tag: '@mobile' }, async () => {
        await anonPage.setViewportSize({ width: 320, height: 700 });
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);
        const overflow = await anonPage.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth
        );
        expect(overflow).toBe(false);
        await anonPage.setViewportSize({ width: 1280, height: 800 });
    });

    // ── COMPLETED state ───────────────────────────────────────────────────────

    test('completed race: both countdowns show the finished "done" state', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        const schedule = anonPage.locator('.race-schedule');
        // Quali + race rows both finished, none still counting down
        await expect(schedule.locator('.countdown-timer.done')).toHaveCount(2);
        await expect(schedule.locator('.countdown-timer[data-target]')).toHaveCount(0);
    });

    test('completed race: qualifying + race results render as P1–P3 badges, no placeholders', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        await expect(anonPage.locator('.result-pending')).toHaveCount(0);
        // Two result blocks (qualifying + race) × 3 badges each
        await expect(anonPage.locator('.position-badge')).toHaveCount(6);
        await expect(anonPage.locator('.quali-row')).toHaveCount(2);
    });

    test('completed race: pool-won badge in the title', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        await expect(anonPage.locator('.hf-racename .hf-badge')).toBeVisible();
    });

    test('completed race: bets scored, sorted by points, perfect bet flagged', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);

        const bets = anonPage.locator('.bet-item');
        await expect(bets).toHaveCount(3); // perfect (30) + other (8) + login (0, scored)

        // "sorted by points" hint present once a result is set
        await expect(anonPage.locator('.bets-section .text-muted').first()).toBeVisible();

        // Highest-scoring (perfect, 30 pts) sorts first and keeps its gold ★
        const top = bets.first();
        await expect(top).toHaveClass(/perfect-bet/);
        await expect(top.locator('span.star')).toBeVisible();
        await expect(top.locator('.hf-badge')).toContainText('30');
    });

    // ── v2.4.0 fidelity: bet-row flourishes (race.php only) ─────────────────────

    test('done race: bet predictions show full driver surnames', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        const topPreds = anonPage.locator('.bet-item').first().locator('.bet-pred');
        // Perfect bet = Hamilton / Verstappen / Leclerc → full surnames, not 3-letter codes
        await expect(topPreds.nth(0)).toContainText('Hamilton');
        await expect(topPreds.nth(1)).toContainText('Verstappen');
        await expect(topPreds.nth(2)).toContainText('Leclerc');
        // Accented surname renders intact
        await expect(anonPage.locator('.bet-pred', { hasText: 'Hülkenberg' })).toHaveCount(1);
    });

    test('done race, logged OUT: no YOU tag, scored rows never show "— pts"', async () => {
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        await expect(anonPage.locator('.race-you-tag')).toHaveCount(0);   // nobody is "me" when logged out
        await expect(anonPage.locator('.race-pts-pending')).toHaveCount(0); // race is scored → all show pts badges
    });

    test('done race, logged IN: own row has YOU tag and a "0 pts" badge (scored, not "— pts")', async () => {
        await authPage.goto(`/race.php?id=${data.doneRaceId}`);
        const mine = authPage.locator('.bet-item.my-bet');
        await expect(mine).toHaveCount(1);
        await expect(mine.locator('.race-you-tag')).toBeVisible();
        await expect(mine.locator('.hf-badge')).toContainText('0 pts'); // 0 points but scored → badge, not "— pts"
        await expect(mine.locator('.race-pts-pending')).toHaveCount(0);
    });

    test('open race (unscored): bets show "— pts", no points badge', async () => {
        await anonPage.goto(`/race.php?id=${data.openRaceId}`);
        const bets = anonPage.locator('.bet-item');
        await expect(bets).toHaveCount(2);
        await expect(anonPage.locator('.race-pts-pending')).toHaveCount(2); // one per unscored bet
        await expect(anonPage.locator('.bet-item .hf-badge.soon')).toHaveCount(0); // no points badges yet
    });

    test('done race: results are two-column at >=1024px, stacked below', async () => {
        const cols = anonPage.locator('.race-results-two > div');

        await anonPage.setViewportSize({ width: 1280, height: 900 });
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        const a = await cols.nth(0).boundingBox();
        const b = await cols.nth(1).boundingBox();
        expect(Math.abs(a.y - b.y)).toBeLessThanOrEqual(2);   // same row
        expect(Math.abs(a.x - b.x)).toBeGreaterThan(20);      // different columns

        await anonPage.setViewportSize({ width: 375, height: 900 });
        await anonPage.goto(`/race.php?id=${data.doneRaceId}`);
        const c = await cols.nth(0).boundingBox();
        const d = await cols.nth(1).boundingBox();
        expect(d.y).toBeGreaterThan(c.y);                     // stacked
        await anonPage.setViewportSize({ width: 1280, height: 800 });
    });

    // ── Regression: races.php must NOT inherit the race.php-only flourishes ──────

    test('races.php bet rows are unchanged (no YOU tag / "— pts", full surnames)', async () => {
        await authPage.goto('/races.php');
        // Core leak guard: the flag-gated flourishes must never appear on races.php.
        await expect(authPage.locator('.race-you-tag')).toHaveCount(0);
        await expect(authPage.locator('.race-pts-pending')).toHaveCount(0);
        // Sanity: races.php still renders bet rows with full surnames (default path), not 3-letter codes.
        const surnameChips = await authPage.locator('.bet-pred', { hasText: 'Hamilton' }).count();
        expect(surnameChips).toBeGreaterThan(0);
    });
});
