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

// ─── Recap carousel (home hero fallback: no race hero, Challenges disabled) ─────────────────
//
// Reuses the same "outside the window" fixture (offset_hours: 26, betting_window_hours: 1) the
// suite above already relies on to guarantee !$showRaceHero — see that describe block's own
// header comment for why this specific offset/window combination is deterministic. Additionally
// disables Challenges, which no existing home-hero case did (every prior case runs with the real
// default, enabled) — this is the only place that branch is reachable at all.
// Note: there's no "zero completed races" case here — this shared test DB always carries a
// full real season's worth of completed races (11+ as of this writing), so that state is
// structurally unreachable without deleting real race results, which this suite never does.
// The underlying guard (`!empty($recapRaces)` in index.php) is a trivial, unambiguous PHP
// check on an empty array and doesn't need e2e coverage to be trusted.
test.describe('Recap carousel', { tag: ['@challenges', '@mobile'] }, () => {
    // Snapshot the REAL pre-test values of the two settings this block mutates and restore
    // exactly those in afterEach — never a hardcoded "restore to enabled=1 / count=5". This is
    // a shared environment (test-server real usage, not just this suite), so its actual
    // settings can legitimately differ from the schema defaults between runs. An earlier version
    // of this file hardcoded the restore values, which silently clobbered a real off/non-default
    // setting on every deploy+verify cycle — wrongly blamed on `deploy:test` itself, which never
    // touches the settings table (see build-deploy/deploy.js: FTP upload + read-only schema
    // check + smoke tests only).
    let originalSettings;
    test.beforeEach(async () => {
        originalSettings = await seed.getSettingsSnapshot();
        await seed.heroRace({ offset_hours: 26, betting_window_hours: 1 });
        await seed.challengesEnabled(false);
    });
    test.afterEach(async () => {
        await seed.cleanup.heroRace();
        await seed.cleanup.recapRaces();
        await seed.cleanup.recapBets();
        await seed.challengesEnabled(!!originalSettings.challenges_enabled);
        await seed.homeRecapCount(originalSettings.home_recap_count);
    });

    test('default count (5) → 5 slides, only the first visible, 5 dots, first marked current', async ({ page }) => {
        await seed.recapRaces({ count: 5 });

        await page.goto('/');
        await expect(page.getByTestId('recap-hero')).toBeVisible();
        const slides = page.getByTestId('recap-slide');
        await expect(slides).toHaveCount(5);
        await expect(slides.nth(0)).not.toHaveClass(/hidden/);
        for (let i = 1; i < 5; i++) {
            await expect(slides.nth(i)).toHaveClass(/hidden/);
        }
        await expect(page.getByTestId('recap-dot')).toHaveCount(5);
        await expect(page.getByTestId('recap-dot').nth(0)).toHaveAttribute('aria-current', 'true');
    });

    // Real completed-race volume in this shared environment always exceeds the 3-10 setting
    // range, so "shrinks below the configured count" isn't independently observable here —
    // this instead proves the setting itself is wired end-to-end (exactly K slides for a
    // chosen K), which is the part that's actually environment-independent.
    test('home_recap_count setting controls how many slides render', async ({ page }) => {
        await seed.homeRecapCount(3);

        await page.goto('/');
        await expect(page.getByTestId('recap-slide')).toHaveCount(3);
        await expect(page.getByTestId('recap-dot')).toHaveCount(3);
    });

    test('manual next/prev arrows move the active slide, prev wraps from the first to the last', async ({ page }) => {
        // Pin both the setting and the seeded fixtures to exactly 3, so slide 2 is
        // deterministically "the last slide" regardless of real completed-race volume.
        await seed.homeRecapCount(3);
        await seed.recapRaces({ count: 3 });
        await page.goto('/');

        const slides = page.getByTestId('recap-slide');
        const dots   = page.getByTestId('recap-dot');
        await expect(slides).toHaveCount(3);

        await page.getByTestId('recap-next').click();
        await expect(slides.nth(1)).not.toHaveClass(/hidden/);
        await expect(slides.nth(0)).toHaveClass(/hidden/);
        await expect(dots.nth(1)).toHaveAttribute('aria-current', 'true');

        // Back to slide 0, then prev from slide 0 wraps to the last slide (index 2).
        await page.getByTestId('recap-prev').click();
        await expect(slides.nth(0)).not.toHaveClass(/hidden/);
        await page.getByTestId('recap-prev').click();
        await expect(slides.nth(2)).not.toHaveClass(/hidden/);
        await expect(dots.nth(2)).toHaveAttribute('aria-current', 'true');
    });

    test('dot click jumps directly to that slide', async ({ page }) => {
        await seed.recapRaces({ count: 3 });
        await page.goto('/');

        await page.getByTestId('recap-dot').nth(2).click();
        await expect(page.getByTestId('recap-slide').nth(2)).not.toHaveClass(/hidden/);
        await expect(page.getByTestId('recap-dot').nth(2)).toHaveAttribute('aria-current', 'true');
    });

    test('auto-advances after the interval without manual interaction', async ({ page }) => {
        await seed.recapRaces({ count: 3 });
        await page.clock.install();
        await page.goto('/');

        await expect(page.getByTestId('recap-slide').nth(0)).not.toHaveClass(/hidden/);
        await page.clock.fastForward(6500);
        await expect(page.getByTestId('recap-slide').nth(1)).not.toHaveClass(/hidden/);
    });

    test('prefers-reduced-motion: autoplay never starts, manual controls still work', async ({ page }) => {
        await seed.recapRaces({ count: 3 });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.clock.install();
        await page.goto('/');

        await page.clock.fastForward(20000);
        await expect(page.getByTestId('recap-slide').nth(0)).not.toHaveClass(/hidden/);

        await page.getByTestId('recap-next').click();
        await expect(page.getByTestId('recap-slide').nth(1)).not.toHaveClass(/hidden/);
    });

    // Regression guard for the if/elseif ordering in index.php — Challenges enabled must
    // still win over the recap hero even when completed races exist.
    test('Challenges enabled takes priority over the recap hero', async ({ page }) => {
        await seed.recapRaces({ count: 3 });
        await seed.challengesEnabled(true);

        await page.goto('/');
        await expect(page.getByTestId('challenges-hero')).toBeVisible();
        await expect(page.getByTestId('recap-hero')).toHaveCount(0);
    });

    // The "Upcoming races" card (which links to the next race) otherwise follows every hero
    // variant — suppressed specifically while the recap hero is showing (index.php's
    // $isRecapHero flag), so the recap stays the sole focus rather than being immediately
    // followed by a forward-looking next-race link.
    test('the "Upcoming races" card is hidden while the recap hero is showing', async ({ page }) => {
        await seed.recapRaces({ count: 3 });

        await page.goto('/');
        await expect(page.getByTestId('recap-hero')).toBeVisible();
        await expect(page.getByTestId('next-race-card')).toHaveCount(0);
    });

    // Diverse per-slide highlights (KB insight + betting upset, public/includes/home-recap.php).
    // Only the betting-upset path is e2e-testable — fixture races named "E2E Recap Race N"
    // never match real Paddock Rumors KB content by design (see the plan's coverage-gap note);
    // the KB tier and computeBettingUpset()'s surprise/snub selection logic are unit-tested
    // directly in tests/unit/home-recap-harness.php.
    test('a clear betting upset renders the highlight line on that slide', async ({ page }) => {
        const { race_ids } = await seed.recapRaces({ count: 3 });

        // race_date has no time component (DATE, not DATETIME) and these 3 fixtures are seeded
        // only hours apart, so they land on the same calendar date and tie in getRaces()'
        // `ORDER BY race_date ASC` — which of them actually renders as slide 0 isn't guaranteed
        // by insertion order. Discover the real slide-0 race rather than assuming race_ids[0].
        await page.goto('/');
        const visibleSlide = page.locator('[data-testid="recap-slide"]:not(.hidden)');
        const href = await visibleSlide.getAttribute('data-href');
        const raceId = new URL(href, page.url()).searchParams.get('id');
        expect(race_ids).toContain(raceId);

        await seed.recapBets(raceId);
        await page.goto('/');
        const highlight = page.locator('[data-testid="recap-slide"]:not(.hidden)').getByTestId('recap-highlight');
        await expect(highlight).toBeVisible();
        // Anonymous visitors default to Danish (this describe block uses the base, unauthenticated
        // import — see the file-header comment) — asserting on the Danish home_recap_upset_surprise
        // template, not the English one.
        await expect(highlight).toContainText('Kun 1 ud af 5 satsede på');
        await expect(highlight).toContainText('P1');
    });

    test('fewer than 2 bets → no highlight line (graceful no-op)', async ({ page }) => {
        await seed.recapRaces({ count: 3 }); // no bets seeded for these fixture races

        await page.goto('/');
        const slide0 = page.getByTestId('recap-slide').nth(0);
        await expect(slide0).toBeVisible();
        await expect(slide0.getByTestId('recap-highlight')).toHaveCount(0);
    });
});
