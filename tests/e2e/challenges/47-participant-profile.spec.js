'use strict';

// Paddock Challenges — Feature 3, participant profile (feature-3-participant-profile.md).
// PROF-01..05 from test-plan.md's Feature 3/4/5 addendum. Base import (not ../../fixtures) →
// a clean, unauthenticated context; each test establishes its own participant session via a
// seeded ch_access device cookie, exactly like 40-participant-access.spec.js.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

const BASE = () => process.env.BASE_URL;
const PW = 'E2ETestPassword2026!';
const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

async function establishSession(page, participantId) {
    // getChallengeParticipant() checks the PHP session marker before falling back to the
    // ch_access cookie — clear cookies (dropping PHPSESSID too) so a second call mid-test
    // genuinely switches identity instead of resolving the previous participant's stale
    // session marker.
    await page.context().clearCookies();
    const { token } = await seed.challengeAccessToken({ participant_id: participantId });
    await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);
    return token;
}

test.describe('Participant profile (Feature 3)', { tag: '@challenges' }, () => {
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // PROF-01: display name edit persists to challenge_participants, not users, and shows
    // up on the public CP board (which requires at least one CP row to be listed at all).
    test('edits display name on the Profile tab', async ({ page }) => {
        const email = tstEmail('prof01');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified', display_name: 'Old Name' });
        await seed.challengePoints({ participant_id, points: 10, source_ref: 'test:prof01' });
        await establishSession(page, participant_id);

        await page.goto('/challenges-profile.php?tab=tab-profile');
        await expect(page.getByTestId('tab-profile-panel')).toBeVisible();

        const newName = `New Name ${Date.now()}`;
        await page.fill('[data-testid="display-name-input"]', newName);
        await page.locator('form:has(input[name="action"][value="update_display_name"]) button[type="submit"]').click();

        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('[data-testid="display-name-input"]')).toHaveValue(newName);

        await page.goto('/challenges.php?section=board');
        await expect(page.locator('body')).toContainText(newName);
    });

    // PROF-02: wrong current password rejected, mismatched confirm rejected, valid change
    // rehashes and the old password stops working (login.php's participant branch).
    test('changes password: wrong current, mismatch, then valid', async ({ page }) => {
        const seeded = await seed.challengeParticipant({ email: tstEmail('prof02'), status: 'verified', password: PW });
        const email2 = seeded.email;
        await establishSession(page, seeded.participant_id);

        await page.goto('/challenges-profile.php?tab=tab-account');
        let form = page.locator('form:has(input[name="action"][value="change_password"])');
        await expect(form).toBeVisible();

        await form.locator('input[name="current_password"]').fill('WrongPassword2026!');
        await form.locator('input[name="new_password"]').fill('NewStrongPass2026!');
        await form.locator('input[name="confirm_password"]').fill('NewStrongPass2026!');
        await form.locator('button[type="submit"]').click();
        await expect(page.locator('.alert-error')).toBeVisible();

        await page.goto('/challenges-profile.php?tab=tab-account');
        form = page.locator('form:has(input[name="action"][value="change_password"])');
        await form.locator('input[name="current_password"]').fill(PW);
        await form.locator('input[name="new_password"]').fill('NewStrongPass2026!');
        await form.locator('input[name="confirm_password"]').fill('Mismatch2026!');
        await form.locator('button[type="submit"]').click();
        await expect(page.locator('.alert-error')).toBeVisible();

        await page.goto('/challenges-profile.php?tab=tab-account');
        form = page.locator('form:has(input[name="action"][value="change_password"])');
        await form.locator('input[name="current_password"]').fill(PW);
        await form.locator('input[name="new_password"]').fill('NewStrongPass2026!');
        await form.locator('input[name="confirm_password"]').fill('NewStrongPass2026!');
        await form.locator('button[type="submit"]').click();
        await expect(page.locator('.alert-success')).toBeVisible();

        await page.goto('/logout.php');
        await page.goto('/login.php');
        await page.fill('input[name="email"]', email2);
        await page.fill('input[name="password"]', PW);
        await page.click('button[type="submit"]');
        await expect(page).toHaveURL(/login\.php/);

        await page.fill('input[name="email"]', email2);
        await page.fill('input[name="password"]', 'NewStrongPass2026!');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/challenges.php');
    });

    // PROF-03: anonymous participants see the save-your-spot prompt with no tabs; a core
    // member requesting the page is redirected to the core profile page instead.
    test('anonymous visitor sees the save-your-spot prompt, no tabs', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: '', status: 'pending' });
        await establishSession(page, participant_id);

        await page.goto('/challenges-profile.php');
        await expect(page.getByTestId('profile-tabs')).toHaveCount(0);
        await expect(page.locator('a[href="/challenges-invite.php"]')).toBeVisible();
    });

    test('core member is redirected to /profile.php', async ({ page }) => {
        const email = tstEmail('prof03core');
        await seed.convertedGuest({ email });

        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', 'Integration2026!');
        await page.click('button[type="submit"]');
        await page.waitForURL(/index\.php/);

        await page.goto('/challenges-profile.php');
        await expect(page).toHaveURL(/profile\.php/);
    });

    // PROF-04: no bets/pool/total_points surface anywhere on the page, and no History tab
    // (REQ-128) — scanned via the raw tabs markup so hidden panels are checked too.
    test('has no bet/pool surface and no History tab', async ({ page }) => {
        const email = tstEmail('prof04');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
        await establishSession(page, participant_id);

        await page.goto('/challenges-profile.php');
        await expect(page.locator('.hf-tab-nav .hf-tab-btn')).toHaveCount(3);
        await expect(page.locator('[data-testid="tab-history-btn"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="tab-history-panel"]')).toHaveCount(0);

        const html = (await page.locator('[data-testid="profile-tabs"]').innerHTML()).toLowerCase();
        for (const term of ['total_points', 'bet history', 'pool', 'name="bet', 'leaderboard-points']) {
            expect(html).not.toContain(term);
        }
    });

    // PROF-05 (cross-ref, Low): the same server-side assertions as CH-07/CH-16/CH-07b, but
    // driven through the Account tab UI rather than a bare POST — verifies the tab wires to
    // the existing handlers, no new logic.
    test('Account tab: sets a password via the UI, then logs in with it', async ({ page }) => {
        const email = tstEmail('prof05a');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
        await establishSession(page, participant_id);

        await page.goto('/challenges-profile.php?tab=tab-account');
        const form = page.locator('form:has(input[name="action"][value="set_password"])');
        await expect(form).toBeVisible();
        const newPw = 'FreshSetPass2026!';
        await form.locator('input[name="new_password"]').fill(newPw);
        await form.locator('input[name="confirm_password"]').fill(newPw);
        await form.locator('button[type="submit"]').click();
        await expect(page.locator('.alert-success')).toBeVisible();

        await page.goto('/logout.php');
        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', newPw);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/challenges.php');
    });

    test('Account tab: sign out on one device, then sign out everywhere revokes all', async ({ page }) => {
        const email = tstEmail('prof05b');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
        const deviceA = await seed.challengeAccessToken({ participant_id });
        const deviceC = await seed.challengeAccessToken({ participant_id });
        const deviceD = await seed.challengeAccessToken({ participant_id });

        await page.context().addCookies([{ name: 'ch_access', value: deviceA.token, url: BASE() }]);
        await page.goto('/challenges-profile.php?tab=tab-account');
        await page.locator('form:has(input[name="action"][value="signout"]) button[type="submit"]').click();
        await page.waitForURL('**/challenges.php');
        await expect(page.getByTestId('cp-chip')).toHaveCount(0);

        // Device C is unaffected by A's single-device sign-out.
        await page.context().clearCookies();
        await page.context().addCookies([{ name: 'ch_access', value: deviceC.token, url: BASE() }]);
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toBeVisible();

        await page.goto('/challenges-profile.php?tab=tab-account');
        await page.locator('form:has(input[name="action"][value="signout_all"]) button[type="submit"]').click();
        await page.waitForURL('**/challenges.php');
        await expect(page.getByTestId('cp-chip')).toHaveCount(0);

        // Device D, never touched by this browser, is also revoked.
        await page.context().clearCookies();
        await page.context().addCookies([{ name: 'ch_access', value: deviceD.token, url: BASE() }]);
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toHaveCount(0);
    });

    test('Account tab: requesting core membership shows the pending state', async ({ page }) => {
        const email = tstEmail('prof05c');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
        await establishSession(page, participant_id);

        await page.goto('/challenges-profile.php?tab=tab-account');
        const requestForm = page.locator('form:has(input[name="action"][value="request_core"])');
        await expect(requestForm).toBeVisible();
        await expect(page.getByTestId('account-tab-promo-dot')).toBeVisible();
        await requestForm.locator('button[type="submit"]').click();

        await page.waitForURL(/tab=tab-account/);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-info')).toBeVisible();
        await expect(page.locator('form:has(input[name="action"][value="request_core"])')).toHaveCount(0);
        await expect(page.getByTestId('account-tab-promo-dot')).toHaveCount(0);
    });

    // Owner-confirm magic link (challenges-verify.php ?token=) now lands directly on the
    // Account tab instead of the general hub — that's where set-password and
    // request-membership live, so a freshly-verified guest sees them immediately.
    test('owner-confirm magic link lands on the Account tab', async ({ page }) => {
        const email = tstEmail('prof06verify');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'pending' });
        const { token } = await seed.challengeMagicLink({ participant_id });

        await page.goto(`/challenges-verify.php?token=${token}`);
        await page.waitForURL(/challenges-profile\.php\?tab=tab-account/);
        await expect(page.getByTestId('tab-account-panel')).toBeVisible();
        await expect(page.locator('form:has(input[name="action"][value="set_password"])')).toBeVisible();
        await expect(page.getByTestId('account-tab-promo-dot')).toBeVisible();
    });

    // Nav-drawer "Profile" row carries a promo dot while the guest is verified, not
    // core-linked, and hasn't already requested membership — and loses it as soon as
    // a request is filed.
    test('nav Profile row shows a promo dot only while eligible to request membership', async ({ page }) => {
        const email = tstEmail('prof07nav');
        const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
        await establishSession(page, participant_id);

        await page.goto('/challenges.php');
        await page.click('.hf-hamburger');
        await expect(page.getByTestId('nav-profile-promo-dot')).toBeVisible();

        await page.goto('/challenges-profile.php?tab=tab-account');
        await page.locator('form:has(input[name="action"][value="request_core"]) button[type="submit"]').click();
        await page.waitForURL(/tab=tab-account/);

        await page.goto('/challenges.php');
        await page.click('.hf-hamburger');
        await expect(page.getByTestId('nav-profile-promo-dot')).toHaveCount(0);
    });

    // An already-promoted guest (core_user_id set) never sees the dot, even browsing under
    // their old ch_access session before they've logged into the new core account.
    test('nav Profile row hides the promo dot for an already-promoted guest', async ({ page }) => {
        const email = tstEmail('prof08promoted');
        const { participant_id } = await seed.convertedGuest({ email });
        await establishSession(page, participant_id);

        await page.goto('/challenges.php');
        await page.click('.hf-hamburger');
        await expect(page.getByTestId('nav-profile-promo-dot')).toHaveCount(0);
    });
});
