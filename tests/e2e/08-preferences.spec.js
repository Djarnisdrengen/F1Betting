'use strict';
const { test, expect } = require('@playwright/test');

const SEED_TOKEN  = process.env.INTEGRATION_SEED_TOKEN;
const ALICE_EMAIL = 'alice@test.local';
const BOB_EMAIL   = 'bob@test.local';
const PW          = 'Integration2026!';

async function getPrefs(email) {
    const url = new URL(`${process.env.BASE_URL}/tools/test-seed.php`);
    url.searchParams.set('token', SEED_TOKEN);
    url.searchParams.set('action', 'get_prefs');
    url.searchParams.set('email', email);
    const res = await fetch(url.toString());
    return res.json();
}

async function login(page, email, pw) {
    await page.goto('/login.php');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', pw);
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/, { timeout: 5000 });
}

test.describe.serial('Preferences', () => {
    test.beforeAll(async ({ browser }) => {
        // Reset Alice + Bob + Charlie to NULL prefs via global seed
        const url = new URL(`${process.env.BASE_URL}/tools/test-seed.php`);
        url.searchParams.set('token', SEED_TOKEN);
        await fetch(url.toString());

        // Pre-set Bob: login (NULL → seeds dark/system), then toggle to light.
        // Bob's DB will have theme='light' for AC5.
        const ctx = await browser.newContext();
        const pg  = await ctx.newPage();
        await login(pg, BOB_EMAIL, PW);
        await pg.goto('/?toggle_theme=1');
        await ctx.close();
    });

    // AC1 — new visitor gets defaults; cookies are written on first page load
    test('AC1 — new visitor gets dark/system defaults and cookies are set', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        await expect(page.locator('body')).toHaveClass(/\bfont-system\b/);
        const cookies = await ctx.cookies();
        expect(cookies.find(c => c.name === 'f1_theme')?.value).toBe('dark');
        expect(cookies.find(c => c.name === 'f1_font')?.value).toBe('system');
        await ctx.close();
    });

    // AC2 — returning anonymous visitor: preference cookie drives body class
    test('AC2 — returning anonymous visitor: stored cookie applied', async ({ browser }) => {
        const ctx  = await browser.newContext();
        await ctx.addCookies([{ name: 'f1_theme', value: 'light', url: process.env.BASE_URL }]);
        const page = await ctx.newPage();
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await ctx.close();
    });

    // AC3 — anonymous preference change persists across reload and new session
    test('AC3 — anonymous preference change persists across reload', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        // Toggle to light
        await page.goto('/?toggle_theme=1');
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        // Reload — cookie must preserve it
        await page.reload();
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        const cookies = await ctx.cookies();
        expect(cookies.find(c => c.name === 'f1_theme')?.value).toBe('light');
        await ctx.close();
    });

    // AC4 — first login seeds profile from anonymous session prefs
    test('AC4 — first login seeds profile from anonymous session prefs', async ({ browser }) => {
        // Alice has NULL prefs in DB (from global seed in beforeAll)
        // Fresh context: no cookies → dark/system defaults
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        // Login: NULL profile → should be seeded from session (dark/system)
        await login(page, ALICE_EMAIL, PW);
        // Visible prefs must not change
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        await expect(page.locator('body')).toHaveClass(/\bfont-system\b/);
        // DB must now have values seeded from the anonymous session
        const prefs = await getPrefs(ALICE_EMAIL);
        expect(prefs.theme).toBe('dark');
        expect(prefs.font_stack).toBe('system');
        await ctx.close();
    });

    // AC5 — returning login applies profile prefs, overrides anonymous cookie
    test('AC5 — returning login applies profile prefs, overrides anon cookie', async ({ browser }) => {
        // Bob's DB has theme='light' (set in beforeAll)
        // Context has a dark cookie (different from Bob's profile)
        const ctx  = await browser.newContext();
        await ctx.addCookies([{ name: 'f1_theme', value: 'dark', url: process.env.BASE_URL }]);
        const page = await ctx.newPage();
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\bdark\b/); // anon cookie active
        // Login as Bob: profile (light) must override the dark cookie
        await login(page, BOB_EMAIL, PW);
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        // Cookie must also be updated to match profile
        const cookies = await ctx.cookies();
        expect(cookies.find(c => c.name === 'f1_theme')?.value).toBe('light');
        await ctx.close();
    });

    // AC6 — authenticated preference change is saved to profile and survives re-login
    test('AC6 — authenticated preference change is saved to profile', async ({ browser }) => {
        // Alice has dark in DB from AC4
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        // Toggle to light while authenticated
        await page.goto('/?toggle_theme=1');
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        // DB must reflect the change immediately
        const prefs = await getPrefs(ALICE_EMAIL);
        expect(prefs.theme).toBe('light');
        // Logout and re-login: profile pref must survive
        await page.goto('/logout.php');
        await login(page, ALICE_EMAIL, PW);
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await ctx.close();
    });

    // AC7 + AC8 — logout preserves cookies; continued browsing is unchanged
    test('AC7+AC8 — logout preserves preference cookies; browsing after logout unchanged', async ({ browser }) => {
        // Alice DB = light from AC6
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await page.goto('/logout.php');
        // Cookie must still be present and correct after logout
        const cookies = await ctx.cookies();
        expect(cookies.find(c => c.name === 'f1_theme')?.value).toBe('light');
        // AC8: continue browsing — class must be unchanged
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await ctx.close();
    });

    // AC9 — return visit after logout applies last-known prefs via cookie
    test('AC9 — return visit after logout applies last-known prefs', async ({ browser }) => {
        // Build a context with light pref cookie after logout
        const ctx1 = await browser.newContext();
        const pg1  = await ctx1.newPage();
        await login(pg1, ALICE_EMAIL, PW); // light from AC6
        await pg1.goto('/logout.php');
        const state = await ctx1.storageState();
        await ctx1.close();
        // New context from snapshot simulates returning visit on same device
        const ctx2 = await browser.newContext({ storageState: state });
        const pg2  = await ctx2.newPage();
        await pg2.goto('/');
        await expect(pg2.locator('body')).toHaveClass(/\blight\b/);
        await ctx2.close();
    });

    // AC10 — device prefs overwritten between logout and return: last write wins
    test('AC10 — overwritten cookie wins on return visit', async ({ browser }) => {
        const ctx1 = await browser.newContext();
        const pg1  = await ctx1.newPage();
        await login(pg1, ALICE_EMAIL, PW);
        await pg1.goto('/logout.php');
        const state = await ctx1.storageState();
        await ctx1.close();
        // Overwrite f1_theme cookie to dark in the saved state
        const overriddenState = {
            ...state,
            cookies: state.cookies.map(c =>
                c.name === 'f1_theme' ? { ...c, value: 'dark' } : c
            ),
        };
        const ctx2 = await browser.newContext({ storageState: overriddenState });
        const pg2  = await ctx2.newPage();
        await pg2.goto('/');
        await expect(pg2.locator('body')).toHaveClass(/\bdark\b/);
        await ctx2.close();
    });

    // AC11 — theme icon reflects current state (moon = dark, sun = light)
    test('AC11 — theme icon reflects current state', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        // Dark mode: moon icon visible
        await page.goto('/');
        await expect(page.locator('body')).toHaveClass(/\bdark\b/);
        await expect(page.locator('.hf-bb-item[title="Theme"] i')).toHaveClass(/fa-moon/);
        // Toggle to light: sun icon visible
        await page.goto('/?toggle_theme=1');
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await expect(page.locator('.hf-bb-item[title="Theme"] i')).toHaveClass(/fa-sun/);
        await ctx.close();
    });
});
