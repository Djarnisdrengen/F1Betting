'use strict';
const { test, expect } = require('@playwright/test');
const seed = require('../../helpers/seed');

// Bypass and enumeration-parity negatives for webauthn.php. No virtual
// authenticator needed — every case must FAIL before crypto matters. POSTs go
// through in-page fetch() (page.evaluate), NOT page.request: the Simply.com WAF
// challenges non-browser network stacks with a 454 page (see docs/gotchas.md /
// memory "no curl"), while the browser's own fetch is already cleared. True
// valid-assertion replay is covered by challenge single-use in
// tests/unit/passkey-harness.php; here we prove the server never grants a
// session and never leaks which failure mode occurred.

async function csrfTokenFrom(page, path) {
    await page.goto(path);
    return page.locator('input[name="csrf_token"]').first().inputValue();
}

async function postWebauthn(page, form) {
    return page.evaluate(async (fields) => {
        const fd = new FormData();
        for (const [k, v] of Object.entries(fields)) fd.append(k, v);
        const r = await fetch('/webauthn.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return { status: r.status, body: await r.text() };
    }, form);
}

async function expectNoSession(page) {
    await page.goto('/profile.php');
    await expect(page).toHaveURL(/login\.php/);
}

test.describe('Passkey (WebAuthn) negatives', { tag: '@auth' }, () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(async () => { await seed.authUser(); });
    test.afterAll(async () => {
        await seed.cleanup.authUser();
        // The garbage login_verify posts above each record a failed attempt.
        // Clear the budget so a re-run (or global-setup's admin login) within
        // the 15-minute window isn't rate-limited.
        await seed.cleanup.loginAttempts();
    });

    test('missing CSRF token is blocked on every action (SEC-03)', async ({ page }) => {
        await page.goto('/login.php'); // establish a session
        for (const action of ['register_options', 'register_verify', 'challenge_options',
                              'challenge_verify', 'login_options', 'login_verify']) {
            const { body } = await postWebauthn(page, { action });
            expect(body).toContain('CSRF');
        }
        await expectNoSession(page);
    });

    test('challenge_verify without mfa_pending grants nothing (CHA-03)', async ({ page }) => {
        const token = await csrfTokenFrom(page, '/login.php');
        const { body } = await postWebauthn(page, {
            action: 'challenge_verify', csrf_token: token,
            rawId: 'AAAA', clientDataJSON: 'AAAA', authenticatorData: 'AAAA', signature: 'AAAA',
        });
        expect(JSON.parse(body).error).toBeTruthy();
        await expectNoSession(page);
    });

    test('login_verify without a prior challenge grants nothing (PWL-02)', async ({ page }) => {
        const token = await csrfTokenFrom(page, '/login.php');
        const { body } = await postWebauthn(page, {
            action: 'login_verify', csrf_token: token,
            rawId: 'AAAA', clientDataJSON: 'AAAA', authenticatorData: 'AAAA', signature: 'AAAA', userHandle: 'AAAA',
        });
        expect(JSON.parse(body).error).toBeTruthy();
        await expectNoSession(page);
    });

    test('challenge is single-use and garbage assertions fail after valid options (PWL-03)', async ({ page }) => {
        const token = await csrfTokenFrom(page, '/login.php');

        const opts = await postWebauthn(page, { action: 'login_options', csrf_token: token });
        expect(JSON.parse(opts.body).options.publicKey.challenge).toBeTruthy();

        const garbage = {
            action: 'login_verify', csrf_token: token,
            rawId: 'AAAA', clientDataJSON: 'AAAA', authenticatorData: 'AAAA', signature: 'AAAA', userHandle: 'AAAA',
        };
        const first = await postWebauthn(page, garbage);   // consumes the challenge
        const second = await postWebauthn(page, garbage);  // nothing left to consume
        expect(JSON.parse(first.body).error).toBeTruthy();
        expect(JSON.parse(second.body).error).toBeTruthy();
        await expectNoSession(page);
    });

    test('register_options requires a logged-in session (REG-04)', async ({ page }) => {
        const token = await csrfTokenFrom(page, '/login.php');
        const { body } = await postWebauthn(page, { action: 'register_options', csrf_token: token });
        expect(JSON.parse(body).error).toBeTruthy();
    });

    test('all failure modes return the byte-identical generic body (PWL-04 parity)', async ({ page }) => {
        const token = await csrfTokenFrom(page, '/login.php');
        const garbage = { rawId: 'AAAA', clientDataJSON: 'AAAA', authenticatorData: 'AAAA', signature: 'AAAA', userHandle: 'AAAA' };

        const bodies = [];
        // no-pending challenge_verify
        bodies.push((await postWebauthn(page, { action: 'challenge_verify', csrf_token: token, ...garbage })).body);
        // no-challenge login_verify
        bodies.push((await postWebauthn(page, { action: 'login_verify', csrf_token: token, ...garbage })).body);
        // garbage after valid options (bad credential path)
        await postWebauthn(page, { action: 'login_options', csrf_token: token });
        bodies.push((await postWebauthn(page, { action: 'login_verify', csrf_token: token, ...garbage })).body);
        // unknown action
        bodies.push((await postWebauthn(page, { action: 'no_such_action', csrf_token: token })).body);
        // logged-out register_options
        bodies.push((await postWebauthn(page, { action: 'register_options', csrf_token: token })).body);

        for (const b of bodies) {
            expect(b).toBe(bodies[0]); // byte-identical — nothing enumerable
        }
    });
});
