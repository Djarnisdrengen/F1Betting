'use strict';
// E2E coverage for the single-race focus page (public/race.php).
// Verifies the four-state schedule box, qualifying/race countdowns, login affordances,
// result blocks, and the always-expanded scored bets list — using a dedicated seed
// (seed_race_page) that creates one OPEN race (with qualifying timing) and one COMPLETED race.
const { test, expect } = require('@playwright/test');
const seed = require('../helpers/seed');

test.describe.serial('Single-race page (race.php)', () => {
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

    test('open race: no horizontal scroll at 320px', async () => {
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
        await expect(bets).toHaveCount(2);

        // "sorted by points" hint present once a result is set
        await expect(anonPage.locator('.bets-section .text-muted').first()).toBeVisible();

        // Highest-scoring (perfect, 30 pts) sorts first and keeps its gold ★
        const top = bets.first();
        await expect(top).toHaveClass(/perfect-bet/);
        await expect(top.locator('span.star')).toBeVisible();
        await expect(top.locator('.hf-badge')).toContainText('30');
    });
});
