'use strict';

// Paddock Challenges — Feature 5, invite guardrails & consent
// (feature-5-invite-guardrails.md). INV-01..07 from test-plan.md's Feature 3/4/5 addendum.
// Base import (not ../../fixtures) → a clean, unauthenticated context; guardrails apply
// regardless of session identity.
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');
const { getMessages, assertDelivered } = require('../../helpers/email');

const BASE = () => process.env.BASE_URL;
const tstEmail = (p) => `${p}_${Date.now()}_${Math.floor(Math.random() * 1e4)}@test.localhost`;

async function establishSession(page, participantId) {
    // getChallengeParticipant() checks the PHP session marker before falling back to the
    // ch_access cookie — clear cookies (dropping PHPSESSID too) so a second call mid-test
    // genuinely switches identity instead of resolving the previous participant's stale
    // session marker.
    await page.context().clearCookies();
    const { token } = await seed.challengeAccessToken({ participant_id: participantId });
    await page.context().addCookies([{ name: 'ch_access', value: token, url: BASE() }]);
}

// A verified participant with an email already on file (skips the anonymous-owner branch)
// and one played item (so playedSet() is non-empty and the send path is reachable at all).
async function readyChallenger(page, prefix) {
    const email = tstEmail(prefix);
    const { participant_id } = await seed.challengeParticipant({ email, status: 'verified' });
    await seed.challengeAnswer({ participant_id });
    await establishSession(page, participant_id);
    return participant_id;
}

async function submitInvite(page, friendEmail, ownEmail) {
    await page.goto('/challenges-invite.php');
    if (ownEmail) {
        await page.fill('input[name="own_email"]', ownEmail);
    }
    await page.fill('input[name="friend_email"]', friendEmail);
    await page.click('button[type="submit"]');
}

test.describe('Invite guardrails & consent (Feature 5)', { tag: '@challenges' }, () => {
    // The 'invite' rate-limit scope shares one IP-wide bucket (threshold 5 / 15 min) across
    // every test below — reset first so a same-session rerun never trips it on a stale count,
    // same convention as auth/36-passkey-negative.spec.js.
    test.beforeAll(async () => { await seed.cleanup.loginAttempts(); });
    test.afterAll(async () => {
        await seed.cleanup.challenges();
        await seed.cleanup.loginAttempts();
    });

    // INV-01: suppressed friend address — canSendInvite() false, no friend email, response
    // still the success view (silent block, REQ-810).
    test('invite to a suppressed address sends no email but looks successful', async ({ page }) => {
        await readyChallenger(page, 'inv01owner');
        const friendEmail = tstEmail('inv01friend');
        await seed.challengeSuppression({ email: friendEmail });

        await submitInvite(page, friendEmail);
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-error')).toHaveCount(0);
        expect((await getMessages(friendEmail)).length).toBe(0);
    });

    // INV-02: a real opt-out link (extracted from a genuinely-sent invite email, same
    // pattern as the magic-link specs) suppresses the address; a repeat click is idempotent;
    // a later invite to the same address is then blocked.
    test('a valid opt-out link suppresses the address, idempotently', async ({ page }) => {
        await readyChallenger(page, 'inv02owner');
        const friendEmail = tstEmail('inv02friend');

        await submitInvite(page, friendEmail);
        await expect(page.locator('.alert-success')).toBeVisible();

        const msgs = await assertDelivered(friendEmail);
        // getEmailBody() prefers the plain-text alternative, which strips hrefs entirely —
        // the opt-out link only survives in the HTML part.
        const match = msgs[0].html.match(/challenges-optout\.php\?e=[^"&\s]+&(?:amp;)?t=[a-f0-9]+/);
        expect(match, 'invite email missing an opt-out link').toBeTruthy();
        const optoutUrl = '/' + match[0].replace('&amp;', '&');

        await page.goto(optoutUrl);
        await expect(page.locator('.hf-auth-wrap .fa-check')).toBeVisible();

        // Idempotent repeat click.
        await page.goto(optoutUrl);
        await expect(page.locator('.hf-auth-wrap .fa-check')).toBeVisible();

        // Now suppressed — a fresh invite to the same address sends nothing new.
        await readyChallenger(page, 'inv02owner2');
        await submitInvite(page, friendEmail);
        await expect(page.locator('.alert-success')).toBeVisible();
        expect((await getMessages(friendEmail)).length).toBe(1); // still just the original send
    });

    // INV-03: a mismatched opt-out token (a valid token's e swapped for a different, untouched
    // address — the "tampered or mismatched" case) writes no suppression row and shows a
    // neutral invalid page. Proven via a fresh invite to the untouched address, which avoids
    // colliding with the per-friend dedupe window a same-address resend would trip.
    test('a mismatched opt-out token is rejected and suppresses nothing', async ({ page }) => {
        await readyChallenger(page, 'inv03owner');
        const friendEmail1 = tstEmail('inv03friend1'); // real send target, only to obtain a valid t
        const friendEmail2 = tstEmail('inv03friend2'); // never invited — the one under test

        await submitInvite(page, friendEmail1);
        const msgs = await assertDelivered(friendEmail1);
        const match = msgs[0].html.match(/challenges-optout\.php\?e=[^"&\s]+&(?:amp;)?t=([a-f0-9]+)/);
        expect(match, 'invite email missing an opt-out link').toBeTruthy();
        const validToken = match[1]; // valid for friendEmail1, not friendEmail2

        await page.goto(`/challenges-optout.php?e=${encodeURIComponent(friendEmail2)}&t=${validToken}`);
        // .fa-triangle-exclamation also appears in the site-wide test-environment banner —
        // scope to the opt-out card itself.
        await expect(page.locator('.hf-auth-wrap .fa-triangle-exclamation')).toBeVisible();
        await expect(page.locator('.hf-auth-wrap .fa-check')).toHaveCount(0);

        // Not suppressed — a fresh invite to friendEmail2 (untouched, no dedupe collision) still sends.
        await readyChallenger(page, 'inv03owner2');
        await submitInvite(page, friendEmail2);
        await expect(page.locator('.alert-success')).toBeVisible();
        expect((await getMessages(friendEmail2)).length).toBe(1);
    });

    // INV-04: a second invite to the same friend while an unexpired 'sent' invite already
    // exists is blocked by the per-friend dedupe window, regardless of sender.
    test('a duplicate invite within the dedupe window sends no second email', async ({ page }) => {
        const seedChallenger = await seed.challengeParticipant({ email: tstEmail('inv04seed'), status: 'verified' });
        const friendEmail = tstEmail('inv04friend');
        await seed.challengeInvite({ challenger_id: seedChallenger.participant_id, friend_email: friendEmail, status: 'sent' });

        await readyChallenger(page, 'inv04owner');
        await submitInvite(page, friendEmail);
        await expect(page.locator('.alert-success')).toBeVisible();
        expect((await getMessages(friendEmail)).length).toBe(0);
    });

    // INV-05: a sender already at the daily cap is blocked; once the oldest of the N prior
    // sends is >24h old, a new invite succeeds again.
    test('the per-sender daily cap blocks the 6th send, clears after 24h', async ({ page }) => {
        const participantId = await readyChallenger(page, 'inv05owner');
        await seed.challengeInvite({ challenger_id: participantId, friend_email: 'capfill@test.localhost', count: 5 });

        const blockedFriend = tstEmail('inv05blocked');
        await submitInvite(page, blockedFriend);
        await expect(page.locator('.alert-success')).toBeVisible();
        expect((await getMessages(blockedFriend)).length).toBe(0);

        // A different participant whose 5 prior sends are already >24h old — cap doesn't apply.
        const participantId2 = await readyChallenger(page, 'inv05owner2');
        await seed.challengeInvite({
            challenger_id: participantId2, friend_email: 'capfillold@test.localhost', count: 5, created_hours_ago: 25,
        });
        const okFriend = tstEmail('inv05ok');
        await submitInvite(page, okFriend);
        await expect(page.locator('.alert-success')).toBeVisible();
        expect((await getMessages(okFriend)).length).toBe(1);
    });

    // INV-06: the owner's own confirmation email is independent of the friend-side
    // guardrails — it still sends even when the friend address is suppressed (REQ-809).
    // Needs an owner with no email on file (the only path that sends an owner-confirm at all).
    test('owner confirmation still sends when the friend side is blocked', async ({ page }) => {
        const { participant_id } = await seed.challengeParticipant({ email: '', status: 'pending' });
        await seed.challengeAnswer({ participant_id });
        await establishSession(page, participant_id);

        const ownEmail = tstEmail('inv06owner');
        const friendEmail = tstEmail('inv06friend');
        await seed.challengeSuppression({ email: friendEmail });

        await submitInvite(page, friendEmail, ownEmail);
        await expect(page.locator('.alert-error')).toHaveCount(0);

        expect((await assertDelivered(ownEmail)).length).toBeGreaterThan(0);
        expect((await getMessages(friendEmail)).length).toBe(0);
    });

    // INV-07: an allowed send and a suppressed send return byte-identical response bodies to
    // the owner (NFR-106 extended to Feature 5) — compared on the invite card's own markup,
    // excluding the header/footer's per-request CSP nonce.
    test('responses for an allowed and a blocked send are identical', async ({ page }) => {
        await readyChallenger(page, 'inv07owner');
        const okFriend = tstEmail('inv07ok');
        await submitInvite(page, okFriend);
        await expect(page.locator('.alert-success')).toBeVisible();
        const okHtml = await page.locator('.hf-auth-wrap .card-body').innerHTML();

        await readyChallenger(page, 'inv07owner2');
        const blockedFriend = tstEmail('inv07blocked');
        await seed.challengeSuppression({ email: blockedFriend });
        await submitInvite(page, blockedFriend);
        await expect(page.locator('.alert-success')).toBeVisible();
        const blockedHtml = await page.locator('.hf-auth-wrap .card-body').innerHTML();

        expect(blockedHtml).toBe(okHtml);
    });
});
