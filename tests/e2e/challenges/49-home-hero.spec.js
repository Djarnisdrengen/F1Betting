'use strict';

// Paddock Challenges — Phase 6: context-aware home hero (feature.md REQ-006/007/109, D9).
// isRaceHeroWindow()'s exact hour-boundary arithmetic is proven exhaustively by
// tests/unit/hero-window-harness.php; this file is the "Unit+E2E spot-check" HERO-01 calls
// for — confirming index.php actually wires the two hero branches (and the CP/rank/streak
// stats) correctly, not re-deriving the D9 math.
//
// "now" is real wall-clock time and can't be injected into index.php, so each case seeds the
// race's start time relative to the (fixed) current time instead of the other way around —
// e.g. "now = windowOpen-25h" becomes a race dated windowOpen+25h from now, since
// windowOpen = raceStart - betting_window_hours. A narrow betting_window_hours keeps the
// race offsets small (safely earlier than any real season race) for the windowStart cases;
// the raceStart+Nh cases don't depend on betting_window_hours at all (raceEnd = raceStart+3h
// always), so they use small negative offsets (race already started a couple of hours ago —
// still counts as "upcoming" under index.php's existing 8h grace window).
//
// Base import (not ../../fixtures) → a clean, unauthenticated context, so the seeded
// ch_access cookie is the only identity in play.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

test.describe('Context-aware home hero', { tag: ['@challenges', '@mobile'] }, () => {
    test.afterEach(async () => {
        await seed.cleanup.heroRace();
        await seed.cleanup.challenges();
    });

    // HERO-01: now = windowOpen-25h (1h before windowStart) → Challenges hero, no race hero.
    // Also the CP/rank/streak stat row spot-check (REQ-109) — CP and streak are exact
    // (scoped to this one fresh participant); rank only asserts *some* value renders, since
    // the CP leaderboard is global shared state other specs also write to.
    test('outside the window (before windowStart) → Challenges hero with stats', async ({ page }) => {
        await seed.heroRace({ offset_hours: 26, betting_window_hours: 1 });

        const { participant_id } = await seed.challengeParticipant({
            email: tstEmail('hero'), status: 'verified', display_name: 'Hero Tester',
        });
        await seed.challengePoints({ participant_id, points: 42, source_ref: 'test:hero-cp' });
        await seed.challengeAnswer({ participant_id, correct: 1 }); // this week's action → streak 1 (streak is weekly, not daily)
        const { token } = await seed.challengeAccessToken({ participant_id });
        await page.context().addCookies([{ name: 'ch_access', value: token, url: process.env.BASE_URL }]);

        await page.goto('/');
        await expect(page.getByTestId('challenges-hero')).toBeVisible();
        await expect(page.getByTestId('hero-race')).toHaveCount(0);
        await expect(page.getByTestId('challenges-strip')).toHaveCount(0);

        await expect(page.getByTestId('challenges-hero-stats')).toBeVisible();
        await expect(page.getByTestId('hero-stat-cp')).toHaveText('42');
        await expect(page.getByTestId('hero-stat-rank')).toHaveText(/^P\d+$|^—$/);
        // Exact match (not a substring check) — a loose containment check here would pass
        // vacuously whenever the rank happens to render "P1" (a real bug this exposed once).
        await expect(page.getByTestId('hero-stat-streak')).toHaveText('1'); // fa-fire icon has no text
    });

    // HERO-01: now = windowOpen-23h (1h inside windowStart) → race hero + Challenges strip.
    test('just inside the window (after windowStart) → race hero + strip', async ({ page }) => {
        await seed.heroRace({ offset_hours: 24, betting_window_hours: 1 });

        await page.goto('/');
        await expect(page.getByTestId('hero-race')).toBeVisible();
        await expect(page.getByTestId('challenges-strip')).toBeVisible();
        await expect(page.getByTestId('challenges-hero')).toHaveCount(0);
    });

    // HERO-01: now = raceStart+2h (before raceEnd) → still the race hero + strip.
    test('inside the window (race already started, before raceEnd) → race hero + strip', async ({ page }) => {
        await seed.heroRace({ offset_hours: -2 });

        await page.goto('/');
        await expect(page.getByTestId('hero-race')).toBeVisible();
        await expect(page.getByTestId('challenges-strip')).toBeVisible();
        await expect(page.getByTestId('challenges-hero')).toHaveCount(0);
    });

    // HERO-01: now = raceStart+4h (past raceEnd) → back to the Challenges hero.
    test('outside the window (past raceEnd) → Challenges hero', async ({ page }) => {
        await seed.heroRace({ offset_hours: -4 });

        await page.goto('/');
        await expect(page.getByTestId('challenges-hero')).toBeVisible();
        await expect(page.getByTestId('hero-race')).toHaveCount(0);
        await expect(page.getByTestId('challenges-strip')).toHaveCount(0);
    });
});
