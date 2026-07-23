'use strict';
const { test, expect } = require('@playwright/test');

// Separate file, plain @playwright/test (not ../../fixtures) — deliberately unauthenticated,
// matching 14-actions-dashboard-access.spec.js's own precedent. A browser.newContext() created
// inside a spec file that imports ../../fixtures inherits the admin-authed storageState (see
// project memory: Playwright manual context leak) — it does NOT start logged-out, so these
// checks must live in their own plain-fixture file to be meaningful.
test.describe('Dashboards area — access control', { tag: '@admin' }, () => {
    for (const tab of ['oversigt', 'keys', 'paddockkb', 'challenges']) {
        test(`logged-out visitor is redirected away from ?tab=${tab}`, async ({ page }) => {
            await page.goto(`/admin-dashboards.php?tab=${tab}`);
            await expect(page).toHaveURL(/login\.php/);
        });
    }

    // Nøgler & Rotation's and PaddockKB's write actions are a separate code path from the
    // page's own GET render — each needs its own admin gate, not just "the page is gated" by
    // implication. Posted via a real page (page.evaluate + form.submit()), not
    // request.post()/curl — this host's WAF blocks non-browser HTTP clients outright (project
    // memory: Simply.com WAF blocks curl with 454/455), which would otherwise make these tests
    // fail for the wrong reason.
    async function postAndExpectLoginRedirect(page, action) {
        await page.goto('/login.php'); // same-origin page to submit a cross-page form from
        await page.evaluate((actionValue) => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/admin-dashboards.php';
            const input = document.createElement('input');
            input.name = 'action';
            input.value = actionValue;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }, action);
        await page.waitForLoadState();
        await expect(page).toHaveURL(/login\.php/);
    }

    test('nr_rotate_secret POST is rejected for a logged-out visitor', async ({ page }) => {
        await postAndExpectLoginRedirect(page, 'nr_rotate_secret');
    });

    test('kb_trigger_update POST is rejected for a logged-out visitor', async ({ page }) => {
        await postAndExpectLoginRedirect(page, 'kb_trigger_update');
    });
});
