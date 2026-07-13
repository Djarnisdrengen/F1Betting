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

    scoreRace:      (params={}) => call('seed_score_race', params),
    // → { ok, raceAId, raceBId, driverIds: {p1, p2, p3},
    //     expectedPoints: [{email, ptsAfterB, ptsAfterReset, star}],
    //     poolA, poolB }
    // params: { prescored?: true } — also scores Race B directly (no admin-UI step needed)

    mfaEnrolledUser: ()      => call('seed_mfa_enrolled_user'),
    // → { ok, email, password } — TOTP + email OTP already active, recovery codes generated

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

    // ── Challenges: participant model (Phase 1) ─────────────────────────────────
    challengeParticipant: (params = {}) => call('seed_challenge_participant', params),
    // params: { email?, status?('pending'|'verified'), password?, display_name?, language?,
    //           core_user_id?, promotion_requested_at? (truthy → NOW(), Feature 4 queue fixture) }
    //   email='' → anonymous; password set → permanent. → { ok, participant_id, email }

    challengeAccessToken: (params = {}) => call('seed_challenge_access_token', params),
    // params: { participant_id, expires_in? (secs; negative = already expired) } → { ok, token }

    challengeInvite: (params = {}) => call('seed_challenge_invite', params),
    // params: { challenger_id, friend_email?, game?, score?, status?, item_ids?, expires_in?,
    //           count? (>1 → N prior sends, each to a distinct friend_email — Feature 5
    //           daily-cap fixture, INV-05), created_hours_ago? (backdates created_at — pair
    //           with count to prove the daily cap clears after 24h) }
    //   → { ok, invite_id, friend_token, invite_ids, friend_tokens }

    challengeMagicLink: (params = {}) => call('seed_challenge_magic_link', params),
    // params: { participant_id, expires_in?, used? } → { ok, token }

    challengePoints: (params = {}) => call('seed_challenge_points', params),
    // params: { participant_id, points, game?, source_ref? } → { ok, cp_id }

    challengeSuppression: (params = {}) => call('seed_challenge_suppression', params),
    // params: { email, reason? ('opt_out'|'complaint'|'bounce'|'admin') } → { ok }
    //   Direct insert — skips the opt-out round trip (Feature 5 dedupe/suppression fixtures).

    challengeAnswer: (params = {}) => call('seed_challenge_answer', params),
    // params: { participant_id, correct? (0|1, default 1) } → { ok, item_id }
    //   One played item (real challenge_answers row against a shared e2e-fixture item), so
    //   challenges-invite.php's playedSet() sees a non-empty set.

    rumorDeck: (params = {}) => call('seed_rumor_deck', {
        ...params,
        ...(params.real ? { real: params.real.join(',') } : {}),
    }),
    // params: { real?: number[] (is_real per item, default [1,0,1]), publish_date? (YYYY-MM-DD,
    //           default today — pass yesterday for RUM-04 rollover) }
    //   → { ok, items: [{id, is_real}], draft_item_id }
    //   N published Rumor or Not items in deck order, plus one status='draft' item (never
    //   playable). Fresh ids every call — not participant-scoped, not swept by cleanup.challenges.

    triviaWeek: (params = {}) => call('seed_trivia_week', params),
    // params: { week_offset? (0=current ISO week [default], -1=previous week — cron tests),
    //           participant_id?, correct? (comma list of 0/1, one per day, e.g. '1,1,1,1,1,0' —
    //           only takes effect together with participant_id, backdating that participant's
    //           answers directly, bypassing the play-flow POST handler) }
    //   → { ok, question_ids: [...] (6, Mon-Sat order), iso_week }
    //   6 published questions, fixed options ['A','B','C'] with correct_option=0 (A), topic
    //   'e2e-seed'. Not participant-scoped — not swept by cleanup.challenges by participant,
    //   but topic='e2e-seed' IS the cleanup marker (the table has no source_ref column).

    duelRace: (params = {}) => call('seed_duel_race', params),
    // params: { state?: 'open' (default, 2h future) | 'started' (10min past, no result yet —
    //           locked but unresolved, for DUEL-04/DUEL-05) } → { ok, race_id }
    //   Distinct from seed_betting_race, which drives the actual update_race/reset_race_result
    //   admin flow (DUEL-02/03/08) — use that one instead when you need real resolution.

    duel: (params = {}) => call('seed_duel', params),
    // params: { race_id, challenger_id, opponent_id, status?: 'active' (default)|'pending'|
    //           'resolved'|'void', challenger_pick?: 'p1id,p2id,p3id', opponent_pick?: same }
    //   → { ok, duel_id }
    //   Composable with challengeParticipant/duelRace/seed_betting_race rather than a
    //   monolithic fixture.

    heroRace: (params = {}) => call('seed_hero_race', params),
    // params: { offset_hours? (int, default 2; negative = race already started),
    //           betting_window_hours? (int, default 48) } → { ok, race_id }
    //   Single named race ('E2E Hero Race') at an arbitrary hour offset from now — for
    //   isRaceHeroWindow() boundary checks (HERO-01) on the home page. Idempotent (deletes
    //   any prior fixture race). Since "now" can't be injected into index.php, boundary
    //   cases are hit by choosing the race offset relative to the (real) current time, not
    //   the other way around — see 49-home-hero.spec.js for the exact offsets used.

    convertedGuest: (params = {}) => call('seed_converted_guest', params),
    // params: { email?, display_name?, in_competition?, link_participant? (0 → users row only,
    //           an email-collision fixture for ADM-07) } → { ok, user_id, participant_id, email }
    //   Admin-approved participant already linked to a fresh core user (in_competition=0),
    //   skipping the Approve transaction itself (ADM-01/02 fixtures).

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
        mfaEnrolledUser: () => call('cleanup_mfa_enrolled_user'),
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
        challenges:     () => call('cleanup_challenges'),
        heroRace:       () => call('cleanup_hero_race'),
    },
};
