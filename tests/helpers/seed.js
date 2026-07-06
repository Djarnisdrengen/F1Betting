'use strict';

// Stack A only — process.env vars are set by playwright.config.js before specs run.
// Do not import this from standalone scripts (smoke.js, security.js, email-preview.js).

async function call(action, params = {}) {
    const url = new URL(`${process.env.BASE_URL}/tools/test-seed.php`);
    url.searchParams.set('token', process.env.INTEGRATION_SEED_TOKEN);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }

    let res;
    try {
        res = await fetch(url.toString());
    } catch (e) {
        throw new Error(`seed.${action}: network error — ${e.message}`);
    }

    if (!res.ok) {
        throw new Error(`seed.${action}: HTTP ${res.status}`);
    }

    let body;
    try {
        body = await res.json();
    } catch (e) {
        throw new Error(`seed.${action}: response is not valid JSON`);
    }

    if (!body.ok) {
        throw new Error(`seed.${action} failed: ${JSON.stringify(body)}`);
    }

    return body;
}

module.exports = {
    // ── Seeds ──────────────────────────────────────────────────────────────────

    bettingRace:    ()       => call('seed_betting_race'),
    // → { ok, raceId, email, password, drivers: [{id, name}] }

    racePage:       ()       => call('seed_race_page'),
    // → { ok, openRaceId, doneRaceId, email, password, drivers: {p1, p2, p3} }

    authUser:       ()       => call('seed_auth_user'),
    // → { ok, email, password }

    scoreRace:      ()       => call('seed_score_race'),
    // → { ok, raceAId, raceBId, driverIds: {p1, p2, p3},
    //     expectedPoints: [{email, ptsAfterB, ptsAfterReset, star}],
    //     poolA, poolB }

    resetResult:    ()       => call('seed_reset_result'),
    // → { ok, points, stars, raceId }

    betDeleted:     ()       => call('seed_bet_deleted'),
    // → { ok, email, raceName }

    notifyOpen:     ()       => call('seed_notification_open'),
    // → { ok, raceId, emailCompeting, emailNonCompeting, emailInvited,
    //     bettingWindowHours, raceAt, bettingOpensAt, nowAt }

    notifyClose:    ()       => call('seed_notification_close'),
    // → { ok, raceId, emailUnbetted, emailBetted }

    cronQualifying: ()       => call('seed_cron_qualifying'),
    // → { ok }

    registerInvite: ()       => call('seed_register_invite'),
    // → { ok, token, email }

    e2eUser:        (params) => call('create_e2e_user', params),
    // params: { language?: 'da'|'en' }  → { ok }

    emailPreview:   ()       => call('send_email_preview'),
    // → { ok, emails: { "<key>_<lang>": { sent, to, subject, ... } } }

    setPasskeySignCount: (email, count) => call('set_passkey_sign_count', { email, count }),
    // → { ok, updated }

    // ── Cleanup ────────────────────────────────────────────────────────────────

    cleanup: {
        bettingRace:    () => call('cleanup_betting_race'),
        racePage:       () => call('cleanup_race_page'),
        authUser:       () => call('cleanup_auth_user'),
        scoreRace:      () => call('cleanup_score_race'),
        resetResult:    () => call('cleanup_reset_result'),
        betDeleted:     () => call('cleanup_bet_deleted'),
        notifyOpen:     () => call('cleanup_notification_open'),
        notifyClose:    () => call('cleanup_notification_close'),
        cronQualifying: () => call('cleanup_cron_qualifying'),
        register:       () => call('cleanup_register'),
        e2eUser:        () => call('cleanup_e2e_user'),
        e2eInvite:      () => call('cleanup_e2e_invite'),
        passkeys:       (email) => call('cleanup_passkeys', email ? { email } : {}),
        loginAttempts:  () => call('clear_login_attempts'),
    },
};
