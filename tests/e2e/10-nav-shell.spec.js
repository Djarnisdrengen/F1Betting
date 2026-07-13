'use strict';
const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_AUTH = path.join(__dirname, '../../.auth/admin.json');

// Phase 2 nav shell (feature.md §A. Navigation, plan.md P2 acceptance checklist): bottom bar
// becomes four site-wide destinations with Challenges as an accented doorway; Profile and the
// Theme/Language/Font toggles move into the drawer's Preferences block, reachable signed-out.

test.describe('Bottom bar — four destinations', { tag: '@appearance' }, () => {
    test('shows exactly Home, Races, Board, Challenges — no Profile item', async ({ page }) => {
        await page.goto('/');
        const items = page.locator('.hf-bottom > .hf-bb-item');
        await expect(items).toHaveCount(4);
        await expect(items.nth(0)).toHaveAttribute('href', '/');
        await expect(items.nth(1)).toHaveAttribute('href', 'races.php');
        await expect(items.nth(2)).toHaveAttribute('href', 'leaderboard.php');
        await expect(items.nth(3)).toHaveAttribute('href', 'challenges.php');
        await expect(page.locator('.hf-bottom a[href="profile.php"]')).toHaveCount(0);
        await expect(page.locator('.hf-bottom a[href="login.php"]')).toHaveCount(0);
    });

    test('Challenges cell carries the accented red-square icon styling', async ({ page }) => {
        await page.goto('/');
        const icon = page.locator('.hf-bottom a[href="challenges.php"] .hf-bb-icon');
        await expect(icon).toHaveAttribute('style', /border-radius:\s*9px/);
        await expect(icon).toHaveAttribute('style', /var\(--f1-red\)/);
    });

    test('Board label reads "Board" (D5), distinct from the drawer\'s "Leaderboard" wording', async ({ page }) => {
        await page.goto('/');
        // Default language is Danish (t('leaderboard') = 'Rangliste'); the bottom-bar
        // "Board" label uses a dedicated ch_nav_board key so it never matches that wording.
        await expect(page.locator('.hf-bottom a[href="leaderboard.php"]')).toContainText('Stilling');
        await page.click('.hf-hamburger');
        await expect(page.locator('.hf-drawer a[href="leaderboard.php"]')).toContainText('Rangliste');
    });
});

test.describe('Drawer — Challenges + Public CP board rows', { tag: '@appearance' }, () => {
    test('Challenges row carries a New badge and links to the hub', async ({ page }) => {
        await page.goto('/');
        await page.click('.hf-hamburger');
        const row = page.locator('.hf-drawer a[href="challenges.php"]');
        await expect(row).toBeVisible();
        await expect(row.locator('.hf-badge.open')).toBeVisible();
    });

    test('Public CP leaderboard row links to challenges-board.php', async ({ page }) => {
        await page.goto('/');
        await page.click('.hf-hamburger');
        await expect(page.locator('.hf-drawer a[href="challenges-board.php"]')).toBeVisible();
    });
});

test.describe('Drawer — Preferences (signed-out)', { tag: '@appearance' }, () => {
    test('Theme, Language and Font controls are visible and functional', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await page.click('.hf-hamburger');
        await expect(page.locator('.hf-seg a[href="?toggle_theme=1"]')).toHaveCount(2);
        await expect(page.locator('.hf-seg a[href="?toggle_lang=1"]')).toHaveCount(2);
        await expect(page.locator('.hf-seg a[href="?toggle_font=1"]')).toHaveCount(2);

        const wasDark = (await page.locator('body').getAttribute('class') || '').includes('dark');
        await page.click('.hf-seg a[href="?toggle_theme=1"]');
        await expect(page.locator('body')).toHaveClass(new RegExp(`\\b${wasDark ? 'light' : 'dark'}\\b`));
        await ctx.close();
    });

    test('toggling preserves other query params', async ({ page }) => {
        await page.goto('/races.php?tab=upcoming&toggle_theme=1');
        const url = new URL(page.url());
        expect(url.searchParams.get('tab')).toBe('upcoming');
        expect(url.searchParams.has('toggle_theme')).toBe(false);
    });
});

test.describe('Bottom bar exclusions and hub behavior', { tag: '@appearance' }, () => {
    test.use({ storageState: ADMIN_AUTH });

    test('admin panel still excludes the bottom bar', async ({ page }) => {
        await page.goto('/admin.php?tab=races');
        await expect(page.locator('.hf-bottom')).not.toBeAttached();
    });

    test('inside the hub: bottom bar stays put, Challenges active, no double bar', async ({ page }) => {
        await page.goto('/challenges.php');
        await expect(page.locator('.hf-bottom')).toHaveCount(1);
        await expect(page.locator('.hf-bottom a[href="challenges.php"]')).toHaveClass(/active/);
    });
});
