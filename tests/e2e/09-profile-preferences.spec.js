'use strict';
const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_AUTH  = path.join(__dirname, '../../.auth/admin.json');
const SEED_TOKEN  = process.env.INTEGRATION_SEED_TOKEN;
const ALICE_EMAIL = 'alice@test.local';
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

// ─── Layout checks (PP1–PP2): admin auth, no state dependency ────────────────

test.describe('Profile preferences — layout', () => {
    test.use({ storageState: ADMIN_AUTH });

    // PP1 — bottom nav absent on profile page
    test('PP1 — bottom nav is hidden on /profile.php', async ({ page }) => {
        await page.goto('/profile.php');
        await expect(page.locator('.hf-bottom')).not.toBeAttached();
    });

    // PP2 — preferences card visible
    test('PP2 — preferences card visible with selects pre-populated', async ({ page }) => {
        await page.goto('/profile.php');
        await expect(page.locator('h3').filter({ hasText: /Preferences|Præferencer/ })).toBeVisible();
        await expect(page.locator('select[name="pref_theme"]')).toBeVisible();
        await expect(page.locator('select[name="pref_font"]')).toBeVisible();
    });
});

// ─── State tests (PP3–PP6): Alice, serial, DB assertions ─────────────────────

test.describe.serial('Profile preferences — state', () => {
    test.beforeAll(async () => {
        // Reset all test users to NULL prefs
        const url = new URL(`${process.env.BASE_URL}/tools/test-seed.php`);
        url.searchParams.set('token', SEED_TOKEN);
        await fetch(url.toString());
    });

    // PP3 — submit light+editorial: body classes, flash message, selects updated
    test('PP3 — saving light+editorial updates page immediately', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.selectOption('select[name="pref_theme"]', 'light');
        await page.selectOption('select[name="pref_font"]', 'editorial');
        await page.locator('form:has(input[value="update_preferences"]) button[type="submit"]').click();
        await page.waitForURL(/profile\.php/);
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await expect(page.locator('body')).toHaveClass(/\bfont-editorial\b/);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('select[name="pref_theme"]')).toHaveValue('light');
        await expect(page.locator('select[name="pref_font"]')).toHaveValue('editorial');
        await ctx.close();
    });

    // PP4 — DB reflects saved preferences
    test('PP4 — DB updated after save', async () => {
        const prefs = await getPrefs(ALICE_EMAIL);
        expect(prefs.theme).toBe('light');
        expect(prefs.font_stack).toBe('editorial');
    });

    // PP5 — cookies updated
    test('PP5 — preference cookies updated after save', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        const cookies = await ctx.cookies();
        expect(cookies.find(c => c.name === 'f1_theme')?.value).toBe('light');
        expect(cookies.find(c => c.name === 'f1_font')?.value).toBe('editorial');
        await ctx.close();
    });

    // PP6 — preferences survive logout + re-login (theme AND font)
    test('PP6 — preferences persist after logout + re-login', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await expect(page.locator('body')).toHaveClass(/\blight\b/);
        await expect(page.locator('body')).toHaveClass(/\bfont-editorial\b/);
        await ctx.close();
    });
});

// ─── Regression (PP7–PP9): authenticated, independent ────────────────────────

test.describe('Profile preferences — regression', () => {
    test.use({ storageState: ADMIN_AUTH });

    // PP7 — bottom nav present on home
    test('PP7 — bottom nav visible on /', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.hf-bottom')).toBeVisible();
    });

    // PP8 — bottom nav present on races
    test('PP8 — bottom nav visible on /races.php', async ({ page }) => {
        await page.goto('/races.php');
        await expect(page.locator('.hf-bottom')).toBeVisible();
    });

    // PP9 — bottom nav theme toggle still works on non-profile page
    test('PP9 — theme toggle works on / (not profile page)', async ({ page }) => {
        await page.goto('/');
        const cls = await page.locator('body').getAttribute('class');
        const current = cls?.includes('dark') ? 'dark' : 'light';
        const expected = current === 'dark' ? 'light' : 'dark';
        await page.goto('/?toggle_theme=1');
        await expect(page.locator('body')).toHaveClass(new RegExp(`\\b${expected}\\b`));
        await page.goto('/?toggle_theme=1'); // restore
    });
});

// ─── Defect regression (PP-NEW-1 – PP-NEW-5) ─────────────────────────────────

test.describe.serial('Profile preferences — defect regression', () => {
    test.beforeAll(async () => {
        const url = new URL(`${process.env.BASE_URL}/tools/test-seed.php`);
        url.searchParams.set('token', SEED_TOKEN);
        await fetch(url.toString());
    });

    // PP-NEW-1 — sanitizeString fix: special chars stored and rendered raw
    test('PP-NEW-1 — display_name with special chars is stored and rendered without double-encoding', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.fill('input[name="display_name"]', "O'Brien & Co.");
        await page.locator('form:has(input[value="update_profile"]) button[type="submit"]').click();
        await page.waitForURL(/profile\.php/);
        const rendered = await page.locator('.hf-profile-name').textContent();
        expect(rendered.trim()).toBe("O'Brien & Co.");
        const prefs = await getPrefs(ALICE_EMAIL);
        expect(prefs.display_name).toBe("O'Brien & Co.");
        await ctx.close();
    });

    // PP-NEW-2 — PRG fix: reload after profile save does not resubmit form
    test('PP-NEW-2 — refresh after profile update does not resubmit form', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.fill('input[name="display_name"]', 'Alice Refresh');
        await page.locator('form:has(input[value="update_profile"]) button[type="submit"]').click();
        await page.waitForURL(/profile\.php/);
        await page.reload();
        await expect(page.locator('.alert-success')).not.toBeVisible();
        await ctx.close();
    });

    // PP-NEW-3 — allow-list guard: tampered pref_theme value is sanitised to valid default
    test('PP-NEW-3 — invalid pref_theme value is rejected and a valid class applied', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.evaluate(() => {
            const sel = document.querySelector('select[name="pref_theme"]');
            const opt = document.createElement('option');
            opt.value = 'malicious<script>alert(1)</script>';
            opt.selected = true;
            sel.appendChild(opt);
        });
        await page.locator('form:has(input[value="update_preferences"]) button[type="submit"]').click();
        await page.waitForURL(/profile\.php/);
        const cls = await page.locator('body').getAttribute('class');
        expect(cls).toMatch(/\b(dark|light)\b/);
        expect(cls).not.toContain('malicious');
        await ctx.close();
    });

    // PP-NEW-4 — max-length guard: display_name over 100 chars shows validation error
    test('PP-NEW-4 — display_name over 100 chars shows validation error, not success', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.fill('input[name="display_name"]', 'A'.repeat(101));
        await page.locator('form:has(input[value="update_profile"]) button[type="submit"]').click();
        await expect(page.locator('.alert-error')).toBeVisible();
        await expect(page.locator('.alert-success')).not.toBeVisible();
        await ctx.close();
    });

    // PP-NEW-5 — language change via profile form updates UI and DB
    test('PP-NEW-5 — language change via profile form updates html[lang] and DB', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        await login(page, ALICE_EMAIL, PW);
        await page.goto('/profile.php');
        await page.selectOption('select[name="language"]', 'en');
        await page.locator('form:has(input[value="update_profile"]) button[type="submit"]').click();
        await page.waitForURL(/profile\.php/);
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        const prefs = await getPrefs(ALICE_EMAIL);
        expect(prefs.language).toBe('en');
        await ctx.close();
    });
});

// ─── Unauthenticated (PP10) ───────────────────────────────────────────────────

test.describe('Profile preferences — unauthenticated', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    // PP10 — bottom nav visible with login link for anonymous visitors
    test('PP10 — bottom nav visible with login link for anonymous visitor', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.hf-bottom')).toBeVisible();
        await expect(page.locator('.hf-bottom a[href="login.php"]')).toBeVisible();
    });
});
