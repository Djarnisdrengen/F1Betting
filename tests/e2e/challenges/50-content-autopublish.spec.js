'use strict';

// Paddock Challenges — content-generator import endpoints (auto-publish pipeline).
// cron-content-topup.yml now runs the generators with --publish, so the import endpoints insert
// status='published' items dated the upcoming Monday and they go live with no admin review. These
// tests lock down that contract directly against the import endpoints (which had no coverage):
//   - status='published' → item is immediately playable (proves the flag AND that publish_date is
//     written non-NULL; a NULL date would silently fail nextRumorItem's `publish_date <= today`).
//   - no status → still defaults to 'draft' (the safe, reviewable behaviour is preserved).
// Anonymous play (fresh cookie-less context), same convention as 42-rumor / 43-trivia.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

// Import endpoints take the same Bearer token as the seed helper (set by playwright.config.js).
async function importItems(endpoint, items, status) {
    const res = await fetch(`${process.env.BASE_URL}/tools/${endpoint}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${process.env.INTEGRATION_SEED_TOKEN}`,
        },
        body: JSON.stringify(status ? { items, status } : { items }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok || !body.ok) throw new Error(`import ${endpoint} failed: ${JSON.stringify(body)}`);
    return body;
}

const today = () => new Date().toISOString().slice(0, 10);
// Monday of the current ISO week — what the generator stamps (upcomingMonday), but pinned to the
// current week so the trivia is playable now regardless of which weekday the test runs.
function currentMonday() {
    const d = new Date();
    const day = d.getDay(); // 0 Sun … 6 Sat
    d.setDate(d.getDate() + (day === 0 ? -6 : 1 - day));
    return d.toISOString().slice(0, 10);
}

test.describe('Content auto-publish import', { tag: ['@challenges'] }, () => {
    // Imported items aren't participant-scoped; source_ref/topic='e2e-seed' IS the cleanup marker.
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // A status='published' rumor import is live immediately — no admin publish step.
    test('published rumor import is immediately playable', async ({ page }) => {
        const r = await importItems('import-rumor-drafts.php', [{
            text_da: 'Auto-publish rumor DA', text_en: 'Auto-publish rumor EN',
            is_real: false, source_ref: 'e2e-seed', publish_date: today(),
        }], 'published');
        expect(r.inserted).toBe(1);

        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toBeVisible();
        await expect(page.getByTestId('rumor-done')).toHaveCount(0);
    });

    // Backward-compat safety: omitting status still imports as an inert draft (not playable).
    test('rumor import without a status stays a draft', async ({ page }) => {
        await importItems('import-rumor-drafts.php', [{
            text_da: 'Draft rumor DA', text_en: 'Draft rumor EN',
            is_real: false, source_ref: 'e2e-seed', publish_date: today(),
        }]);

        await page.goto('/challenges.php?section=rumors');
        await expect(page.getByTestId('rumor-card')).toHaveCount(0);
        await expect(page.getByTestId('rumor-done')).toBeVisible();
    });

    // A status='published' trivia question dated the current week's Monday is playable that week —
    // the generator stamps the upcoming Monday, which is the current week once it's live.
    test('published trivia import is playable in its Monday-stamped week', async ({ page }) => {
        const r = await importItems('import-trivia-drafts.php', [{
            question_da: 'Auto-publish trivia DA', question_en: 'Auto-publish trivia EN',
            options_da: ['A', 'B', 'C'], options_en: ['A', 'B', 'C'],
            correct_option: 0, topic: 'e2e-seed',
            explain_da: 'x', explain_en: 'x', publish_date: currentMonday(),
        }], 'published');
        expect(r.inserted).toBe(1);

        await page.goto('/challenges.php?section=trivia');
        await expect(page.getByTestId('trivia-card')).toBeVisible();
        await expect(page.getByTestId('trivia-done')).toHaveCount(0);
    });
});
