# Test Plan: Paddock Challenges

Feature: `feature.md` · Plan: `plan.md`
Participant-model feature specs: `feature-3-participant-profile.md` · `feature-4-core-member-request.md`
· `feature-5-invite-guardrails.md`
Stack: **Playwright (Node.js) E2E + `node --test`** unit harness (no PHPUnit). Email assertions via
server-side SMTP interception (`tests/helpers/intercepted-mail.js` — Mailsac is removed).

---

## 1. Scope & objectives

- **Highest risk — identity separation:** a magic link, an **access token/cookie**, **or a permanent-participant password login** must only ever grant `$_SESSION['challenge_participant_id']`, never `user_id`. Any path that lets a challenge session reach core-auth pages is an account-takeover-shaped bug. Test hardest here.
- **Highest risk — persistent-token handling (new, D13):** the `ch_access` device cookie is long-lived, so it must store only the `sha256` server-side, **rotate** on every cookie re-establishment, and **revoke** cleanly on sign-out (one device vs everywhere). A stale/un-rotated or un-revoked token is a session-fixation-shaped bug.
- **Second — CP ledger correctness:** every award idempotent (`UNIQUE(participant_id, source_ref)`), duel resolution/reversal round-trips exactly, no arithmetic ever mixes CP with betting points.
- **Third — site-wide regression:** Phase 2 moves the theme/language/font toggles and Profile out of the bottom bar. Existing suites that drive those controls (`appearance`, `preferences-editor`, `profile`, plus any spec tapping `.hf-bottom`) **will break and must be updated in the same phase** — budget for it, don't discover it.
- **Fourth — third-party consent (Feature 5):** the friend-invite email reaches someone who never signed up for anything. `canSendInvite()`'s guardrails (suppression, dedupe, cap, rate limit) and the opt-out endpoint are a **release gate**, not polish — a bypassable or spoofable path here is a compliance-shaped bug, not a UX one. Test the negative paths (blocked sends) as hard as the happy path.
- **Fifth — admin-approval atomicity (Feature 4):** the promotion Approve action is the **only** participant-adjacent path that writes a `users` row. It must be transactional (no half-linked identity on failure), idempotent under double-submit, and must preserve CP with zero data movement (`participant_id` stays stable — only `core_user_id` changes).
- **Success criteria:** security negatives green; ledger invariants proven including under double-submit; full existing suite green after each phase; nightly E2E unchanged on live (smoke only).
- **Out of scope (v1):** generator output *quality* (admin review is the gate — test the pipeline mechanics only), Jolpica auto-trivia, load testing (~10 users).

## 2. Test types

| Type | Tool | Scope |
| --- | --- | --- |
| Unit | `node --test` + small PHP CLI harness (MFA precedent) | `scoreDuelPrediction()` 5/2/0 matrix; streak derivation incl. Copenhagen midnight + DST boundaries; ISO-week bucketing (`trivia_week:<iso-week>`); magic-token TTL/single-use predicates |
| Integration | Playwright → test env + seeded DB | join/verify/conversion lifecycle, answer→ledger writes, duel resolve/reset round-trip, cron idempotency |
| E2E | Playwright, `DEPLOY_ENV=test`, new `@challenges` suite | guest journey, member auto-link, all three games' happy paths, nav shell, context hero |
| Security | `npm run test:security` additions + `@challenges` negatives | session separation, enumeration parity, rate limiting, CSRF, XSS via content/display names |
| Regression | existing suites re-run per phase | shell change fallout; core scoring untouched (run `scoring` suite after Phase 5 hooks) |

Suite wiring: add `challenges` to `SUITE_ORDER` (`tests/run-e2e-suites.js:19-31`) and
`PRIMARY_SUITES` (`tests/playwright.config.js:45-48`); specs in `tests/e2e/challenges/`
(`40-guest-access`, `41-nav-shell`, `42-rumor`, `43-trivia`, `44-duels`, `45-cp-board`,
**`46-admin-challenges`** — ADM-\* incl. the Feature 4 promotion queue, **`47-participant-profile`** —
PROF-\* (Feature 3), **`48-invite-guardrails`** — INV-\* (Feature 5)), tagged `@challenges`
(+ `@mobile` on the hub/arena specs — the whole area is mobile-first).

## 3. Test data (test env only — all seeding via `public/tools/test-seed.php`)

New seed actions (token + `APP_ENV==='test'` gated like the existing ~40):

| Action | Purpose |
| --- | --- |
| `seed_challenge_participant` | participant in any tier — **anonymous** (pending, `email NULL`), verified, **permanent** (`password_hash` set), or core-linked; optional display name, language, **and optional `promotion_requested_at`** (Feature 4 queue fixtures — pending-review participants without a round trip through the Account tab) |
| `seed_challenge_magic_link` | owner-confirm link with **arbitrary `expires_at`/`used`** — expiry tested by backdating, never by waiting |
| `seed_challenge_access_token` | `challenge_access_tokens` row with **arbitrary `expires_at`** (backdated = expired) and known raw token, to drive the `ch_access` cookie in return/rotation tests |
| `seed_challenge_invite` | `challenge_invites` row in a chosen state (sent / accepted / completed) with a known `friend_token` and item set, for owner-confirm + friend-join + beat-my-score assertions; **accepts a count** to pre-seed N prior sends from one challenger inside the last 24h, for the Feature 5 daily-cap test (INV-05) without actually sending N emails |
| `seed_challenge_suppression` | insert a `challenge_email_suppressions` row directly (email + reason) — lets Feature 5 dedupe/suppression tests (INV-01/04) skip the full opt-out round trip |
| `seed_challenge_points` | arbitrary ledger rows for board/chip/streak assertions |
| `seed_rumor_deck` | N published items with known `is_real` + a draft item (admin-tab tests) |
| `seed_trivia_week` | 6 questions publish-dated across a chosen ISO week (current or previous, for cron tests) |
| `seed_duel` | duel in a chosen state (pending / active / locked-with-picks) against a seeded race |
| `seed_challenge_actions` | backdated answers for streak fixtures (yesterday / 2-days-ago patterns) |
| `seed_converted_guest` | admin-approved participant linked to a fresh core user (`in_competition=0`) — for the **Feature 4** converted-guests list / users-tab-exclusion cases (ADM-01/02); a shortcut that skips the Approve transaction itself, which ADM-03/04 exercise directly |
| `cleanup_challenges` | delete all challenge-table rows for e2e fixtures (`@test.localhost` participants) — now also clears `challenge_email_suppressions` |

- **Fixtures:** participant emails on `@test.localhost` (interception convention); magic-link emails read via `waitForMessages()` / `getEmailBody()` and the link extracted by regex, exactly like the password-reset specs.
- **Determinism:** the Perfect Week cron is exercised by seeding a *previous* ISO week (questions + 6/6 correct answers backdated) and invoking `public/cron/challenge_weekly.php` over HTTP with the test Bearer secret — twice, asserting one ledger row.
- **Duel resolution:** reuse `seed_betting_race` + the existing `seed_score_race`/`seed_reset_result` style actions to drive `update_race`/`reset_race_result` through the admin flow, not by poking the DB.
- **Invite rate limiting (Feature 5):** the `'invite'`-scope IP/email buckets (REQ-807) are driven the same way the existing MFA/password-reset rate-limit specs are — real repeated requests, no synthetic `login_attempts` rows — and reset with the existing `clear_login_attempts` action between cases. The **daily cap** (`challenge_invite_daily_cap`, a separate counter over `challenge_invites.created_at`) is what `seed_challenge_invite`'s new count param exists for, since firing 5 real sends per test case would be slow and email-flaky.
- **Cleanup:** `cleanup_challenges` in suite teardown; the SMTP intercept log is cleared per run (existing behavior).

## 4. Acceptance criteria

Full gherkin in `feature.md` §Acceptance Criteria (areas A–F), plus the per-feature gherkin in
`feature-3-participant-profile.md`, `feature-4-core-member-request.md`, and
`feature-5-invite-guardrails.md`. Critical scenarios: challenge session (magic link / access cookie /
password login) grants no core access and never sets `user_id` · enumeration-safe invite & confirm ·
owner-confirm link single-use + 30-min expiry · access token/cookie return with rotation + clean
revocation · silent core auto-link · promotion request is admin-gated (no participant-initiated
`users` write) · **admin Approve is atomic, idempotent, email-collision-safe, and preserves CP with no
data migration (Feature 4)** · **no friend-invite email is sent without passing suppression / dedupe /
cap / rate-limit, and a suppressed address is never re-emailed by anyone (Feature 5)** · 5/2/0 duel
arithmetic · void · reset-reversal round-trip · Perfect Week awarded exactly once · tracker = bonus
condition · nav swap scenarios · CP/betting isolation · **a "challenge a friend" win never awards
bonus CP, permanently (D15)**.

## 5. Test cases

| ID | Case | Expected | Pri | Type |
| --- | --- | --- | --- | --- |
| CH-01 | Challenge session requests `profile.php`, `bet.php`, `admin.php` | treated as logged out (redirect), zero core access | Critical | Sec |
| CH-02 | Join with existing vs unknown email | byte-identical HTTP status + body | Critical | Sec |
| CH-03 | Owner-confirm magic link: valid → verified + session + access token/cookie; reused → refused; backdated 31 min → refused | per feature AC | Critical | E2E |
| CH-04 | 6th owner-confirm request in window (scope `'magic'`) | throttled, `Retry-After: 900`, not HTTP 429 | High | Sec |
| CH-05 | POST endpoints without CSRF token (invite, answer, set-password, promotion request) | rejected | Critical | Sec |
| CH-06 | Core member first hub visit | participant row auto-created, linked, display name reused | High | E2E |
| CH-07 | Verified participant sets a password → permanent; then logs in at `/login.php` | `password_hash` stored; login sets `challenge_participant_id` **only**, never `user_id`; access token/cookie issued; no `users` row, no core access | Critical | E2E+Sec |
| CH-07b | Participant submits a "become a core member" request | request recorded; **no `users` row written** by any participant path (admin approval + CP-preserving conversion is a Feature 4 case) | Critical | Sec |
| CH-08 | Double-submit same rumor answer (rapid re-POST) | one `challenge_answers` row, one ledger row | Critical | Integration |
| CH-09 | CP board shows guest with hostile display name (`<script>…`) | escaped, renders inert | High | Sec |
| CH-10 | CP board + chip totals vs seeded ledger | exact sums; betting points absent | High | E2E |
| CH-11 | Streak fixtures: today+yesterday / gap / none | 2 · 0 · 0 (per D6, incl. yesterday-grace) | Med | Unit+E2E |
| CH-12 | Owner/friend email that belongs to a core member | identical response, no participant row, "log in" email — not a magic link/invite (REQ-111) | Critical | Sec |
| CH-13 | First game answer with no prior session | anonymous participant created (pending, `email NULL`); answer/CP/streak accrue; not on CP board | High | Integration |
| CH-14 | Return via emailed access link, and separately via a valid `ch_access` cookie (session expired) | re-established as the same participant with no new magic link | Critical | E2E |
| CH-15 | Cookie re-establishment rotates the token | old `token_hash` invalidated, new one stored; backdated (expired) token refused | Critical | Sec |
| CH-16 | Sign out on one of two seeded devices; then "sign out everywhere" | first revokes only that device's token; second revokes all | High | Sec |
| CH-17 | Challenge a friend: submit own + friend email after a seeded played set | owner-confirm email + friend invite sent; `challenge_invites` row stores item set + owner score; responses byte-identical for known/unknown addresses | High | E2E |
| CH-18 | Friend clicks invite, replays the same set, finishes | friend created verified + access token/cookie; invite `completed`; both scores stored; head-to-head shown; **each earns only normal per-game CP — no head-to-head bonus, permanently (D15)** | High | E2E |
| CH-19 | Nth friend-invite send in window (scope `'invite'`) | throttled per rate limit, `Retry-After: 900`, never HTTP 429; full guardrail matrix (suppression, dedupe, daily cap) is **INV-01..07** below | High | Sec |
| PROF-01 | Participant edits display name on the profile Profile tab | `challenge_participants.display_name` updated; `users` untouched; CP board reflects the new name | High | E2E |
| PROF-02 | Permanent participant changes password: wrong current / mismatched confirm / valid | wrong current rejected; mismatch rejected; success rehashes and old password stops working | High | E2E |
| PROF-03 | Anonymous visitor, then a core member, request `challenges-profile.php` | anonymous sees a save-your-spot prompt with no tabs; core member is redirected to `/profile.php` | Med | E2E |
| PROF-04 | Scan every tab of the participant profile | no bets, pool, or `total_points` value anywhere; no History tab present | Med | Sec |
| PROF-05 *(cross-ref)* | Set-password / sign-out (device + everywhere) / request-core-membership, driven through the Account tab UI rather than a bare POST | same server-side assertions as **CH-07**, **CH-16**, **CH-07b** respectively — no new logic, verifies the tab wires to the existing handlers | Low | E2E |
| I18N-01 | Rumor + trivia content in da and en sessions | stored bilingual text follows the participant's language, switch included | Med | E2E |
| NAV-01 | Bottom bar on every non-admin page | Home/Races/Board/Challenges, accented cell, active states | High | E2E |
| NAV-02 | Signed-out drawer preferences | theme/lang/font toggle round-trips work, params preserved | High | E2E |
| NAV-03 | Existing appearance/preferences/profile suites after Phase 2 | green after spec updates; **prerequisite:** grep-inventory of every spec touching `.hf-bottom`/toggle selectors *before* Phase 2 starts | Critical | Regression |
| NAV-04 | Hub: no second bottom bar; arena tint applied | per REQ-008 | Med | E2E |
| HERO-01 | Hero at D9 boundaries: race seeded so now = windowOpen−25h / −23h / start+2h / start+4h | Challenges / race / race / Challenges hero (`isRaceHeroWindow()` unit + E2E spot-check) | High | Unit+E2E |
| ADM-01 | Converted guest (seeded) on the Challenges admin page | listed with CP + conversion date; `in_competition` toggle flips pool/leaderboard membership | High | E2E |
| ADM-02 | Admin-page access + users-tab exclusion | non-admin/guest session denied like `admin.php`; converted guest absent from core users tab | Critical | Sec |
| ADM-03 | Admin approves a pending promotion request from a **permanent** participant (password_hash set), CP total seeded at 45 | atomic: `users` row created (`in_competition=0`, `points=0`, `stars=0`), `password` = the participant's existing hash, `core_user_id` links back, `promotion_requested_at` cleared, CP total still 45 under the same `participant_id` (no ledger rows moved) | Critical | Integration |
| ADM-04 | Admin approves a pending promotion request from a **verified-only** participant (no password) | `users` row created with an unusable password; a `password_resets` token is issued and a set-password email sent | High | Integration |
| ADM-05 | Admin rejects a pending promotion request | `promotion_requested_at` cleared; no `users` row created; the participant can submit a new request afterward | High | E2E |
| ADM-06 | Admin double-submits Approve for the same participant | no second `users` row (the `core_user_id IS NOT NULL` guard no-ops the re-post) | Critical | Sec |
| ADM-07 | Approve where the participant's email already exists in `users` (forced collision) | transaction rolls back before any write; the request stays pending; admin sees a conflict notice | Critical | Sec |
| INV-01 | Owner submits a friend invite to a **suppressed** address | `canSendInvite()` returns false; no friend email is sent; the owner's HTTP response is byte-identical to a successful send | Critical | Sec |
| INV-02 | Friend opens the opt-out link with a valid `hmac_sha256(email, secret)` token | address inserted into `challenge_email_suppressions` (reason `opt_out`); a repeat click is idempotent (no duplicate row) | Critical | E2E |
| INV-03 | Opt-out link opened with a tampered or mismatched token | no suppression row written; a neutral "link not valid" page is shown | Critical | Sec |
| INV-04 | Second invite to the same friend address while an unexpired `sent` invite already exists | blocked by the per-friend dedupe window; no second email | High | Integration |
| INV-05 | Sender at `challenge_invite_daily_cap` (5 prior invites seeded in the last 24h) attempts one more | blocked; a new invite succeeds once 24h have elapsed since the oldest of the 5 | High | Integration |
| INV-06 | Owner-confirmation email when the friend-side send is blocked (e.g. friend suppressed) | the owner's own confirmation email still sends — it is not gated by the friend guardrails (REQ-809) | High | Integration |
| INV-07 | Two submissions, one that passes every guardrail and one blocked on suppression | the two HTTP responses returned to the owner are byte-for-byte identical (NFR-106 extended to Feature 5) | Critical | Sec |
| RUM-01 | Correct guess on seeded `is_real=0` item | +10 CP, `source_ref rumor_or_not:<id>`, stamp+reveal | High | E2E |
| RUM-02 | Wrong guess | 0 CP, correct answer revealed | High | E2E |
| RUM-03 | Answered item absent from queue; done state at deck end | per AC | Med | E2E |
| RUM-04 | Yesterday's unanswered item | still playable (rollover) | Med | Integration |
| TRIV-01 | Correct / wrong option flows | +5/0 CP, option state colors, once-only | High | E2E |
| TRIV-02 | Cron on seeded 6/6 previous week, run twice | exactly one +20 `trivia_week:<wk>` row | Critical | Integration |
| TRIV-03 | Cron on 5/6 week and on empty week | no bonus row; no evaluation | High | Integration |
| TRIV-04 | Tracker segments vs correct-this-week count | 1:1 | Med | E2E |
| TRIV-05 | Answer to last ISO week's question on Monday | rejected, no answer/ledger row (REQ-402) | High | Integration |
| DUEL-01 | `scoreDuelPrediction()` matrix (exact/wrong-pos/none/tie permutations) | 5/2/0 arithmetic incl. the 15-vs-4 example | Critical | Unit |
| DUEL-02 | Resolve via admin `update_race` | 15/5 (or 10/10) CP, emails to both, settled row | Critical | E2E |
| DUEL-03 | `reset_race_result` then re-enter results | ledger rows removed, duel unresolved, re-award identical, once | Critical | Integration |
| DUEL-04 | Opponent never picks → race starts | void, zero CP | High | Integration |
| DUEL-05 | Pick after race start | rejected "race started" | High | E2E |
| DUEL-06 | Quick Match: two waiting participants, requests fired concurrently (`Promise.all`) | exactly one duel, queue emptied (REQ-302 transaction) | High | Integration |
| DUEL-08 | Re-save results over a resolved duel | no-op: outcome + CP rows byte-identical (REQ-309) | High | Integration |
| DUEL-07 | Core bet + duel pick same race same user | fully independent reads/writes/scores | Critical | Integration |
| SEC-01 | Core `scoring` suite after Phase 5 | unchanged results (hooks are additive) | Critical | Regression |

## 6. Live environment safety

- All seed actions require `INTEGRATION_SEED_TOKEN` **and** `APP_ENV==='test'` (403 otherwise) — unchanged mechanism.
- `deploy:live` runs smoke only; the `@challenges` suite runs on test only. Nightly E2E stays double-run-guarded per the recent fix.
- The cron workflow gets the `_TEST` secret variant like `cron-notifications.yml`; live cron cannot be triggered from test config.
- The generator writes drafts only (`status='draft'`) — nothing reaches players without admin publish; it never runs on the production host.

---

## 7. Test-manager review (2026-07-12)

Reviewed: `feature.md` (requirements + gherkin) and this test plan, via the test-manager skill.

### 🔴 MUST FIX — all applied to the docs on 2026-07-12

| # | Finding | Fix applied |
| --- | --- | --- |
| R1 | **Guest join with a core member's email** was unspecified: it would create a duplicate identity (guest + core-linked participant for the same person) and a guaranteed email collision at conversion time (`users.email` unique). | New **REQ-111** (no participant created; identical response; "log in" email; conversion refuses the post-hoc collision edge) + AC scenario + **CH-12**. |
| R2 | **Quick Match had a pairing race**: two concurrent requests could double-pair or orphan a queue row; nothing enforced one open request per participant. | **REQ-302** rewritten (dedicated `duel_quickmatch` table, `UNIQUE(race_id, participant_id)`, transactional `SELECT … FOR UPDATE` pairing) + data-model addition + AC scenario + **DUEL-06** made concurrent. |
| R3 | **Editing already-entered results silently kept stale duel outcomes**: the ledger's UNIQUE key would block re-awards, freezing the old winner with no signal. | **REQ-309** amended: resolution skips settled duels (explicit no-op); changed results require `reset_race_result` → re-entry, matching the core guard. AC scenario + **DUEL-08**. |
| R4 | **Perfect Week eligibility window was ambiguous**: answers landing between Sunday midnight and the Monday cron (or later) had undefined bonus semantics — a flaky-test and player-dispute factory. | **REQ-402** amended: questions lock at ISO-week end (Sun 23:59:59 Europe/Copenhagen); late answers rejected. AC scenario + **TRIV-05**. |

### 🟡 SHOULD FIX — applied

| # | Finding | Fix applied |
| --- | --- | --- |
| R5 | Phase 2 will break existing suites that drive the old bottom-bar toggles; discovering which ones mid-phase is avoidable pain. | **NAV-03** now requires a selector grep-inventory *before* Phase 2 starts. |
| R6 | "Fully bilingual content" (D3) had zero test coverage. | AC scenario + **I18N-01**. |
| R7 | Converted guests (`in_competition=0`) weren't asserted absent from betting leaderboard/pool — a silent-scoring-bug vector (cf. the admin `in_competition` gotcha). | **CH-07** extended + AC line. |
| R8 | Duel state wording used "locked" as if it were a status; the enum has no such value (lock is derived from race start). | **REQ-310** wording fixed to `active`. |

### 🟢 NICE TO HAVE — noted, not blocking

- CP-chip query runs on every page render; fine at ~10 users, consider a session-cached total if the member count ever grows.
- Log generator runs and cron evaluations via `logToFile()` for postmortems (implementation detail, add during Phase 3/4).
- CH-09 covers hostile display names; hostile *content* (AI-drafted rumor text) goes through the same `htmlspecialchars()` discipline — spot-check one seeded item with markup in it when writing RUM-01.

### Strengths worth keeping

Expiry tested by backdating (no clock waits); cron idempotency proven by double-run; duel resolution
driven through the real admin flow rather than DB pokes; identity separation given the top severity
it deserves; regression budget for the shell swap acknowledged up front.

### Verdict

**⚠ APPROVE WITH CONDITIONS** — conditions were the four 🔴 items above; all were applied to
`feature.md` and this plan on 2026-07-12, so the documents as they now stand are approved for
implementation.

### Post-review addendum (2026-07-12, later the same day)

Three user decisions arrived after the review and were folded into all four docs without affecting
the verdict (they are additive clarifications): **D4 confirmed** (`in_competition=0` for converted
guests — CH-07 unchanged), **D9** hero windows (new HERO-01 boundary case), and **D10** separate
Challenges admin page with converted-guest segregation (new ADM-01/ADM-02 cases,
`seed_converted_guest` action). ADM-02 is Critical: the users-tab exclusion and the page's
admin-only gate are both access-control assertions.

### Participant-model refinement addendum (2026-07-12, decisions D11–D14)

Features 1 (invite loop) & 2 (persistent return / permanent password) reshaped the identity model
(feature.md §B rewritten). Test-plan deltas, all applied above:

- **Scope:** added persistent-token handling (D13) as a top-severity risk alongside identity
  separation; the `ch_access` cookie must store only `sha256`, rotate on use, and revoke cleanly.
- **Seeds:** `seed_challenge_participant` now spans anonymous/verified/permanent/core tiers; added
  `seed_challenge_access_token` and `seed_challenge_invite`; `seed_converted_guest` is now a
  **Feature 4** (admin-approved) fixture, not self-serve.
- **Cases:** **CH-07** repurposed to permanent-password set + participant login (marker only, no
  `users` row); **CH-07b** asserts promotion is admin-gated; new **CH-13** (anonymous play),
  **CH-14** (access-link + cookie return), **CH-15** (token rotation / expiry), **CH-16**
  (per-device vs global revocation), **CH-17** (challenge-a-friend two-email send), **CH-18**
  (friend join + beat-my-score, no bonus CP), **CH-19** (invite rate-limit scope `'invite'`).
- **Sequencing:** identity/persistence cases (CH-07/07b/13/14/15/16) are **game-independent** and are
  the slice verified in Phase 1 before Section C; the full invite-loop E2E (CH-17/18) needs the first
  game's deck-done CTA, so it runs once Rumor or Not (Phase 3) exists, over Phase 1's plumbing.
- **Deferred to Feature 4:** the CP-preserving admin conversion (`users` row, `in_competition=0`) and
  ADM-01/02 converted-guest cases move to the admin-approval feature; only the *request* is tested now.

No new 🔴 items; these are additive to the approved plan.

### Feature 3/4/5 full specs addendum (2026-07-13)

The three participant-model features previously named but only stubbed (`feature.md`'s "Deferred to
Feature 4" note above, and the Phase 1 TODO on invite guardrails) now have full specs:
`feature-3-participant-profile.md`, `feature-4-core-member-request.md`,
`feature-5-invite-guardrails.md`. All three are scheduled into **Phase 1** in `plan.md` (steps 10–12)
— none has a game dependency, and Feature 5 is a hard gate on Phase 3 (real third parties become
reachable the moment the deck-done "Challenge a friend" CTA ships).

- **D15 (2026-07-13):** a "challenge a friend" win pays no bonus CP — confirmed **permanently**, not
  just deferred for v1. Closes the "open decision if desired later" note that used to hang off
  REQ-117/CH-18. No test behavior changes (CH-18 already asserted no bonus); wording tightened only.
- **New cases:** **PROF-01..05** (participant profile — display name, password change, anonymous/core
  gating, no-betting-surface scan, plus cross-refs to CH-07/CH-16/CH-07b for the parts of the Account
  tab that just wrap existing handlers) · **ADM-03..07** (the admin Approve/Reject transaction itself
  — atomicity, permanent-vs-verified-only password handling, idempotent double-submit, email-collision
  abort) · **INV-01..07** (Feature 5's guardrail matrix — suppression, HMAC opt-out validity/idempotency/
  tamper-resistance, per-friend dedupe, per-sender daily cap, owner-email independence, response
  byte-identity under a block).
- **CH-19 tightened:** no longer describes suppression as a "guardrail hook" — the full matrix is now
  INV-01..07; CH-19 itself stays scoped to the `'invite'`-scope rate-limit mechanics.
- **Seeds:** `seed_challenge_participant` gained an optional `promotion_requested_at` param (Feature 4
  queue fixtures without a UI round trip); `seed_challenge_invite` gained a count param (Feature 5
  daily-cap fixture, avoids sending 5 real emails per test); new `seed_challenge_suppression` (direct
  insert, skips the opt-out round trip for dedupe/suppression cases).
- **Suites:** three new spec files — `46-admin-challenges` (ADM-\*, filling a gap where ADM-01/02 had
  cases but no listed spec file), `47-participant-profile` (PROF-\*), `48-invite-guardrails` (INV-\*).
- **Risk register:** added two top-level risks — third-party consent (Feature 5, a compliance-shaped
  release gate) and admin-approval atomicity (Feature 4, the only participant-adjacent path that writes
  `users`).

No new 🔴 items; these are additive to the approved plan and do not change the APPROVE WITH CONDITIONS
verdict above (conditions were already satisfied on 2026-07-12).

### Step 13 implementation notes (2026-07-13)

The specs and seed work above are landed: `46-admin-challenges`, `47-participant-profile`,
`48-invite-guardrails` (29 cases total incl. the pre-existing `40-participant-access`), all green on
test, twice in a row. Two gaps surfaced during implementation that weren't anticipated by the seed
table above:

- **`seed_challenge_answer`** (new) — a single played item (real `challenge_answers` row against a
  shared, idempotently-inserted `challenge_items` fixture row) for a given `participant_id`. Needed
  because `challenges-invite.php`'s `playedSet()` requires a non-empty answered set before the send
  path is reachable at all, and Phase 3 (the real play UI that would create these rows) hasn't shipped
  yet — every INV-\* case needs this as a precondition.
- **`seed_challenge_invite` gained `created_hours_ago`** (backdates `created_at`, on top of the count
  param already listed above) — INV-05's second half ("succeeds once 24h have elapsed") needs the N
  prior sends to be genuinely >24h old, not just seeded at `count` with `NOW()`.
- **`seed_converted_guest` gained `link_participant=0`** — an unlinked `users`-row-only fixture (no
  paired `challenge_participants` row) for ADM-07's email-collision case; the normal linked shape can't
  be reused there since `challenge_participants.email` is UNIQUE and the pending participant under test
  needs to own that email itself.
- **`cleanup_challenges` broadened**: now also deletes `users WHERE email LIKE '%@test.localhost'`
  (Feature 4's approve flow and `seed_converted_guest` both write `users` rows; `@test.localhost` is
  exclusively a challenges-suite domain — verified no other suite's fixtures use it — so this is safe).
- **Test-writing pitfall worth flagging for future `@challenges` specs:** `getChallengeParticipant()`
  checks the PHP session marker (`$_SESSION['challenge_participant_id']`) *before* falling back to the
  `ch_access` cookie. A test helper that re-seeds a device cookie to switch identity mid-test must also
  `clearCookies()` first (dropping `PHPSESSID`) — otherwise the stale session marker keeps resolving the
  *previous* participant. Cost real debugging time on INV-05 (the daily-cap "clears after 24h" half)
  before being traced to this.
