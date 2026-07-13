'use strict';

// Paddock Challenges — Phase 1 participant refactoring (feature.md §B, D11–D14).
// Game-independent identity & persistence: verified here before Section C. The full
// invite-loop E2E (owner sends after a played deck) lands with Rumor or Not (Phase 3).

// Base import (not ../../fixtures) → a clean, unauthenticated context. Participant identity
// must never inherit a core session; several cases assert exactly that.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

const BASE = () => process.env.BASE_URL;
const PW = 'E2ETestPassword2026!';
const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

test.describe('Participant access & persistence', { tag: '@challenges' }, () => {
    test.afterAll(async () => { await seed.cleanup.challenges(); });

    // CH-07: a permanent participant logs in with email + password, lands in the hub, but
    // gains no core access (the session marker is challenge_participant_id, never user_id).
    test('permanent participant logs in, gets no core access', async ({ page }) => {
        const email = tstEmail('perm');
        await seed.challengeParticipant({ email, status: 'verified', password: PW });

        await page.goto('/login.php');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', PW);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/challenges.php');
        await expect(page.getByTestId('cp-chip')).toBeVisible();

        await page.goto('/profile.php');
        await expect(page).toHaveURL(/login\.php/);
    });

    // CH-01: a challenge session must never reach core-auth pages.
    test('challenge session cannot reach core pages', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('sep'), status: 'verified' });
        const { token } = await seed.challengeAccessToken({ participant_id });
        await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);

        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toBeVisible();

        for (const path of ['/profile.php', '/bet.php', '/admin.php']) {
            await page.goto(path);
            await expect(page).toHaveURL(/login\.php|index\.php/);
        }
    });

    // CH-14 + CH-16: return via the device cookie; sign-out revokes it so the same raw
    // token no longer re-establishes.
    test('device cookie returns the participant, sign-out revokes it', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('ret'), status: 'verified' });
        const { token } = await seed.challengeAccessToken({ participant_id });

        await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toBeVisible();

        // Grab the current (rotated) cookie so the assertion targets the live token, not the
        // seeded one that rotation already retired.
        const current = (await page.context().cookies()).find(c => c.name === 'ch_access');
        expect(current && current.value).toBeTruthy();

        await page.goto('/logout.php');
        await page.context().addCookies([{ name: 'ch_access', value: current.value, url: BASE() }]);
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toHaveCount(0);
    });

    // CH-15: an expired access token is refused.
    test('expired access cookie does not re-establish', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: tstEmail('exp'), status: 'verified' });
        const { token } = await seed.challengeAccessToken({ participant_id, expires_in: -3600 });

        await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);
        await page.goto('/challenges.php');
        await expect(page.getByTestId('cp-chip')).toHaveCount(0);
    });

    // CH-18 (friend side): clicking a seeded invite creates the friend as a verified
    // participant and establishes their session. Owner-send side lands with Phase 3.
    test('friend accepts a seeded invite and is verified', async ({ page }) => {
        const challenger = await seed.challengeParticipant({ email: tstEmail('chal'), status: 'verified', display_name: 'Chal' });
        const { friend_token } = await seed.challengeInvite({
            challenger_id: challenger.participant_id,
            friend_email: tstEmail('frnd'),
            score: 3,
        });

        await page.goto(`/challenges-verify.php?invite=${friend_token}`);
        await page.waitForURL(/challenges\.php/);
        await expect(page.getByTestId('cp-chip')).toBeVisible();
    });
});
