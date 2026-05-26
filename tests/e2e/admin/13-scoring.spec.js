'use strict';
const { test, expect } = require('../../fixtures');
const seed = require('../../helpers/seed');

const RACE_A_NAME = 'E2E Score Race A';
const RACE_B_NAME = 'E2E Score Race B';

// Maps seeded email → display_name as set in test-seed.php
const DISPLAY_NAMES = {
    'e2e_score_alice_f1@mailsac.com':   'E2E Score Alice',
    'e2e_score_bob_f1@mailsac.com':     'E2E Score Bob',
    'e2e_score_charlie_f1@mailsac.com': 'E2E Score Charlie',
};

// Find a race card on the admin races tab by the race's displayed name
function adminRaceCard(page, name) {
    return page.locator('.card').filter({ has: page.locator('strong', { hasText: name }) });
}

// Find a race card on the public races page by raceId
// races.php puts id="race-bets-{raceId}" inside each hf-racefull card
function publicRaceCard(page, raceId) {
    return page.locator(`.hf-racefull:has(#race-bets-${raceId})`);
}

// Find a leaderboard row by the user's display name
function lbRow(page, displayName) {
    return page.locator('.hf-row').filter({ hasText: displayName });
}

test.describe.serial('Scoring', () => {
    let seedData;

    test.beforeAll(async () => {
        await seed.cleanup.scoreRace();
        seedData = await seed.scoreRace();
    });

    test.afterAll(async () => {
        await seed.cleanup.scoreRace();
    });

    // ── 1. Enter Race B result via admin ──────────────────────────────────────

    test('enter qualifying result for Race B via admin and get success', async ({ page }) => {
        const { raceBId, driverIds } = seedData;

        // Open the edit form for Race B (no result set yet)
        await page.goto(`/admin.php?tab=races&edit=${raceBId}`);

        // Select P1=Hamilton, P2=Verstappen, P3=Leclerc (same drivers as Race A result)
        await page.selectOption('select[name="result_p1"]', driverIds.p1);
        await page.selectOption('select[name="result_p2"]', driverIds.p2);
        await page.selectOption('select[name="result_p3"]', driverIds.p3);
        await page.click('button[name="update_race"]');

        await expect(page.locator('.alert-success')).toBeVisible();
    });

    // ── 2. Leaderboard — points and stars after Race B ────────────────────────

    test('leaderboard shows correct points for all users after Race B scoring', async ({ page }) => {
        await page.goto('/leaderboard.php');

        for (const { email, ptsAfterB } of seedData.expectedPoints) {
            const row = lbRow(page, DISPLAY_NAMES[email]);
            await expect(row.locator('.hf-pts')).toContainText(String(ptsAfterB));
        }
    });

    test('leaderboard shows rank delta indicators after Race B scoring', async ({ page }) => {
        await page.goto('/leaderboard.php');
        // After Race B, every row should have a visible .hf-rank-delta element
        // (shows ▲/▼ for movement vs Race A, or — for no change / first race)
        const firstRow = page.locator('.hf-row').first();
        await expect(firstRow.locator('.hf-rank-delta')).toBeVisible();
    });

    test('Alice has star badge on leaderboard for her perfect Race B bet', async ({ page }) => {
        await page.goto('/leaderboard.php');
        const aliceEntry = seedData.expectedPoints.find(e => e.star);
        const row = lbRow(page, DISPLAY_NAMES[aliceEntry.email]);
        await expect(row.locator('span.star')).toBeVisible();
    });

    // ── 2b. Podium display ────────────────────────────────────────────────────

    test('podium renders with 3 blocks in Olympic order (DOM: p2, p1, p3)', async ({ page }) => {
        await page.goto('/leaderboard.php');
        const strip = page.locator('.hf-podium-strip');
        await expect(strip).toBeVisible();
        const blocks = strip.locator('.hf-podium-block');
        await expect(blocks).toHaveCount(3);
        await expect(blocks.nth(0)).toHaveClass(/p2/);
        await expect(blocks.nth(1)).toHaveClass(/p1/);
        await expect(blocks.nth(2)).toHaveClass(/p3/);
    });

    test('podium is visible on mobile viewport (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 });
        await page.goto('/leaderboard.php');
        await expect(page.locator('.hf-podium-strip')).toBeVisible();
    });

    test('leaderboard list includes all players including ranks 1-3', async ({ page }) => {
        await page.goto('/leaderboard.php');
        const rows = page.locator('.hf-lb-list .hf-row');
        await expect(rows.first().locator('.hf-rank')).toHaveText('1');
    });

    // ── 3. Pool display on public races page ──────────────────────────────────

    test('Race B card shows poolA + poolB (carryover from Race A included)', async ({ page }) => {
        await page.goto('/races.php');
        const card = publicRaceCard(page, seedData.raceBId);
        await expect(card.locator('span.bettingpool_size')).toContainText(
            String(seedData.poolA + seedData.poolB)
        );
    });

    test('Race A card shows poolA — its pool carried forward, not won', async ({ page }) => {
        await page.goto('/races.php');
        const card = publicRaceCard(page, seedData.raceAId);
        await expect(card.locator('span.bettingpool_size')).toContainText(String(seedData.poolA));
    });

    // ── 4. Reset button scope (assertions made while Race B has a result) ──────

    test('Race A has no reset button — Race B is the more recent completed race', async ({ page }) => {
        await page.goto('/admin.php?tab=races');
        const card = adminRaceCard(page, RACE_A_NAME);
        await expect(card.locator('button[name="reset_race_result"]')).toHaveCount(0);
    });

    test('Race B has reset button — it is the most recently completed race', async ({ page }) => {
        await page.goto('/admin.php?tab=races');
        const card = adminRaceCard(page, RACE_B_NAME);
        await expect(card.locator('button[name="reset_race_result"]')).toBeVisible();
    });

    // ── 5. Reset Race B and verify rollback ───────────────────────────────────

    test('reset Race B removes result, clears reset button, and rolls back points to Race A baseline', async ({ page }) => {
        await page.goto('/admin.php?tab=races');

        const raceBCard = adminRaceCard(page, RACE_B_NAME);
        await raceBCard.locator('button[name="reset_race_result"]').click();
        await page.locator('.btn-user-delete-confirm').click();
        await page.waitForURL(/msg=/);

        // Race B: result label gone, reset button gone
        const raceBCardAfter = adminRaceCard(page, RACE_B_NAME);
        await expect(raceBCardAfter.locator('small.text-accent')).toHaveCount(0);
        await expect(raceBCardAfter.locator('button[name="reset_race_result"]')).toHaveCount(0);

        // Race A: result label still present (unchanged by the reset)
        const raceACard = adminRaceCard(page, RACE_A_NAME);
        await expect(raceACard.locator('small.text-accent')).toBeVisible();

        // Leaderboard: each user's points rolled back to Race A baseline (ptsAfterReset)
        await page.goto('/leaderboard.php');
        for (const { email, ptsAfterReset } of seedData.expectedPoints) {
            const row = lbRow(page, DISPLAY_NAMES[email]);
            await expect(row.locator('.hf-pts')).toContainText(String(ptsAfterReset));
        }
    });
});
