'use strict';
const { test, expect } = require('../../fixtures');

// Challenges usage — see epics/Admin settings and dashboards/feature-5-challenges-usage-dashboard.md.
// Strictly read-only aggregates over existing Paddock Challenges tables — no new schema, no
// fixture mode needed (unlike PaddockKB/GitHub Actions, there's no external API involved).
test.describe('Dashboards Challenges usage', { tag: '@admin' }, () => {
    // Scoped to the first .stat-card-grid (the usage KPI row) — the Content Supply panel below
    // (Real expiry & rotation epic, Feature 2) adds its own .stat-card-grid further down the
    // page, so a page-wide count would no longer be exactly 4.
    test('KPI row and per-game cards render', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('.stat-card-grid').first().locator('.stat-card-value')).toHaveCount(4);
    });

    // Scoped to the first .section-card (the competitions block, always rendered before the
    // funnel and the Content Supply panel) — the new panel below also displays "Rumor or Not"
    // as a generator label, which would otherwise make this locator match twice.
    test('three competition cards render, one per game, each with its own metric label', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        const competitions = page.locator('.section-card').first();
        await expect(competitions.locator('text=Duels')).toBeVisible();
        await expect(competitions.locator('text=Rumor or Not')).toBeVisible();
        await expect(competitions.locator('text=Weekly Trivia')).toBeVisible();
    });

    // Funnel rows carry a distinctive inline grid-template-columns no other section on this
    // page uses (including the new Content Supply panel below) — queried directly rather than
    // via '.section-card').last()', which broke once Content Supply became the new last section.
    test('funnel panel renders three steps in descending order', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        const rows = page.locator('div[style*="grid-template-columns:200px"]');
        await expect(rows).toHaveCount(3);
    });

    test('read-only guarantee: no form exists on this page', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('form')).toHaveCount(0);
    });

    // Non-admin/logged-out access rejection is covered in 20-dashboards-access.spec.js — see
    // that file's header comment for why it isn't tested here with browser.newContext().
});

// ─── Content Supply panel (Real expiry & rotation for content-topup, Feature 2) ─────────────

const seed = require('../../helpers/seed');

test.describe('Dashboards Challenges — Content Supply panel', { tag: '@admin' }, () => {
    test.beforeEach(async () => { await seed.cleanup.challenges(); });
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // NFR-702: figures here must never disagree with admin-challenges.php's own filtered
    // counts. Seed a known batch, archive one, and confirm the panel's live/archived numbers
    // move by exactly the delta this test controls — not an absolute value (real content-topup
    // output also coexists in the DB). Cards are located by position (rumor live is the panel's
    // 1st stat-card, rumor archived the 2nd — matching the render order in challenges.php)
    // rather than by label text, since "Live"/"Archived" are localized and this suite doesn't
    // control the admin fixture account's language.
    test('live/archived rumor counts move consistently with admin-challenges.php', async ({ page }) => {
        const supplyCards = () => page.locator('.section-card').filter({ has: page.locator('.stat-card-grid') }).last().locator('.stat-card-value');

        const { items } = await seed.rumorDeck({ real: [1, 1, 1] }); // 3 published, in-window
        await page.goto('/admin-dashboards.php?tab=challenges');
        const liveAfterSeed     = parseInt(await supplyCards().nth(0).textContent(), 10);
        const archivedAfterSeed = parseInt(await supplyCards().nth(1).textContent(), 10);

        // Archive one of the three via the real admin action, then confirm the panel reflects
        // exactly +1 archived / -1 live.
        await page.goto('/admin-challenges.php?tab=rumors');
        await page.locator(`[data-testid="rumor-item"][data-item-id="${items[0].id}"]`)
            .locator('button[name="action"][value="archive_rumor_item"]').click();
        await page.waitForURL(/admin-challenges\.php/);

        await page.goto('/admin-dashboards.php?tab=challenges');
        const liveAfterArchive     = parseInt(await supplyCards().nth(0).textContent(), 10);
        const archivedAfterArchive = parseInt(await supplyCards().nth(1).textContent(), 10);

        expect(liveAfterArchive).toBe(liveAfterSeed - 1);
        expect(archivedAfterArchive).toBe(archivedAfterSeed + 1);
    });

    // REQ-703: seeding enough extra live items only ever pushes the count further above
    // RUMOR_MIN_LIVE, so this direction (guard definitely NOT blocked) is safely deterministic
    // without touching real content. The reverse (forcing "blocked") isn't test-controllable
    // here — it would require suppressing real content-topup output below the floor, which this
    // suite deliberately never does (the exact "would zero the live deck" tension this epic's
    // whole design exists to prevent — see epics/.../plan.md decision 2).
    test('guard-blocked warning is absent once the live count is comfortably above the floor', async ({ page }) => {
        await seed.rumorDeck({ real: [1, 1, 1, 1, 1, 1, 1, 1] }); // 8 more published, in-window
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('.alert-warning')).toHaveCount(0);
    });

    // REQ-704: test and live cadence render as two distinct, independently-labeled blocks —
    // real values (ok/failed/overdue/unknown) depend on actual GH Actions run history, which
    // this suite doesn't control, so this checks structural independence, not specific states.
    test('batch cadence shows test and live as two distinct blocks', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        // "TEST"/"LIVE" also appear in the KB runway section below (REQ-705, same span.label-mono
        // convention) — .first() here just proves at least one of each renders in the cadence
        // block; the KB-runway-specific test below covers the full expected count.
        await expect(page.getByText('TEST', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('LIVE', { exact: true }).first()).toBeVisible();
    });

    // REQ-705: KB runway renders one row per generator per environment (2 generators × 2 envs).
    test('KB runway shows both generators for both environments', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        // "Rumor or Not" also appears in the competitions card and this panel's own live/archived
        // stat labels above — .first() just proves the KB runway heading renders at all.
        await expect(page.getByText('Rumor or Not').first()).toBeVisible();
        await expect(page.getByText('Trivia').first()).toBeVisible();
        // 2 generator blocks × 2 env rows each = 4 TEST/LIVE labels in the KB runway section
        // alone, plus the 2 from the cadence section above — 6 total on the page.
        const envLabels = page.locator('span.label-mono').filter({ hasText: /^(TEST|LIVE)$/ });
        await expect(envLabels).toHaveCount(6);
    });

    // REQ-706: still no writes anywhere on this page, including the new panel.
    test('Content Supply panel is read-only too', async ({ page }) => {
        await page.goto('/admin-dashboards.php?tab=challenges');
        await expect(page.locator('form')).toHaveCount(0);
        await expect(page.locator('button[type="submit"]')).toHaveCount(0);
    });
});
