'use strict';

// Paddock Challenges — Phase 3 (Rumor or Not). REQ-201-207, feature.md §C scenarios.
// Anonymous play (REQ-101/CH-13): each test uses a fresh, cookie-less context so
// getOrCreateAnonymousParticipant() fires on the first answer — no join/verify flow needed.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

test.describe('Rumor or Not', { tag: ['@challenges', '@mobile'] }, () => {
    // Deck items aren't participant-scoped (rollover reads the whole published table), so a
    // leftover item from one test would pollute the next fresh participant's queue — clean
    // before AND after every test, not just once per file.
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // RUM-01: correct guess on a rumor (is_real=false) item → +10 CP, stamp+reveal.
    test('correct guess awards 10 CP and reveals the stamp', async ({ page }) => {
        await seed.rumorDeck({ real: [0] });

        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toBeVisible();

        await page.getByTestId('rumor-guess-rumor').click();
        await page.waitForURL(/section=rumors&revealed=/);

        const result = page.getByTestId('rumor-result');
        await expect(result).toHaveAttribute('data-correct', '1');
        await expect(page.getByTestId('rumor-stamp')).toHaveAttribute('data-is-real', '0');
        // Anonymous participants get no CP chip in the header (REQ-005 — chip is
        // verified/core-only); the award itself is proven by the reveal panel's own CP text.
        await expect(result).toContainText('10 CP');
    });

    // RUM-02: wrong guess on a real (is_real=true) item → 0 CP, correct answer revealed.
    test('wrong guess awards nothing and reveals the correct answer', async ({ page }) => {
        await seed.rumorDeck({ real: [1] });

        await page.goto('/challenges.php?section=rumors');
        await page.getByTestId('rumor-guess-rumor').click();
        await page.waitForURL(/section=rumors&revealed=/);

        const result = page.getByTestId('rumor-result');
        await expect(result).toHaveAttribute('data-correct', '0');
        await expect(page.getByTestId('rumor-stamp')).toHaveAttribute('data-is-real', '1');
        // No CP line at all on a miss (REQ-205) — the template only prints it when correct.
        await expect(result).not.toContainText('CP');
    });

    // RUM-03: an answered item never reappears in the queue; deck end shows the done state,
    // and it stays done on a subsequent visit (not just immediately after the last card).
    test('answered item drops out of the queue and the deck-cleared state persists', async ({ page }) => {
        await seed.rumorDeck({ real: [1] });

        await page.goto('/challenges.php?section=rumors');
        await page.getByTestId('rumor-guess-real').click();
        await page.waitForURL(/section=rumors&revealed=/);

        await page.getByTestId('rumor-next').click();
        await expect(page.getByTestId('rumor-done')).toBeVisible();

        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-done')).toBeVisible();
    });

    // RUM-04: an item published yesterday (rollover — no race this week) is still playable today.
    test('yesterday\'s unanswered item still plays today', async ({ page }) => {
        const yesterday = new Date(Date.now() - 24 * 3600 * 1000).toISOString().slice(0, 10);
        await seed.rumorDeck({ real: [1], publish_date: yesterday });

        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toBeVisible();
        await expect(page.getByTestId('rumor-done')).toHaveCount(0);
    });
});
