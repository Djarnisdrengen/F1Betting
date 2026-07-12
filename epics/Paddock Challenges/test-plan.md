# Test Plan: Paddock Challenges

Feature: `feature.md` · Plan: `plan.md`
Stack: **Playwright (Node.js) E2E + `node --test`** unit harness (no PHPUnit). Email assertions via
server-side SMTP interception (`tests/helpers/intercepted-mail.js` — Mailsac is removed).

---

## 1. Scope & objectives

- **Highest risk — identity separation:** a magic link must only ever grant `$_SESSION['challenge_participant_id']`. Any path that lets a challenge session reach core-auth pages is an account-takeover-shaped bug. Test hardest here.
- **Second — CP ledger correctness:** every award idempotent (`UNIQUE(participant_id, source_ref)`), duel resolution/reversal round-trips exactly, no arithmetic ever mixes CP with betting points.
- **Third — site-wide regression:** Phase 2 moves the theme/language/font toggles and Profile out of the bottom bar. Existing suites that drive those controls (`appearance`, `preferences-editor`, `profile`, plus any spec tapping `.hf-bottom`) **will break and must be updated in the same phase** — budget for it, don't discover it.
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
(`40-guest-access`, `41-nav-shell`, `42-rumor`, `43-trivia`, `44-duels`, `45-cp-board`), tagged
`@challenges` (+ `@mobile` on the hub/arena specs — the whole area is mobile-first).

## 3. Test data (test env only — all seeding via `public/tools/test-seed.php`)

New seed actions (token + `APP_ENV==='test'` gated like the existing ~40):

| Action | Purpose |
| --- | --- |
| `seed_challenge_participant` | guest (pending/verified) or core-linked participant; optional display name, language |
| `seed_challenge_magic_link` | link with **arbitrary `expires_at`/`used`** — expiry tested by backdating, never by waiting |
| `seed_challenge_points` | arbitrary ledger rows for board/chip/streak assertions |
| `seed_rumor_deck` | N published items with known `is_real` + a draft item (admin-tab tests) |
| `seed_trivia_week` | 6 questions publish-dated across a chosen ISO week (current or previous, for cron tests) |
| `seed_duel` | duel in a chosen state (pending / active / locked-with-picks) against a seeded race |
| `seed_challenge_actions` | backdated answers for streak fixtures (yesterday / 2-days-ago patterns) |
| `seed_converted_guest` | guest-origin participant already linked to a fresh core user (`in_competition=0`) for the admin-page cases |
| `cleanup_challenges` | delete all challenge-table rows for e2e fixtures (`@test.localhost` participants) |

- **Fixtures:** participant emails on `@test.localhost` (interception convention); magic-link emails read via `waitForMessages()` / `getEmailBody()` and the link extracted by regex, exactly like the password-reset specs.
- **Determinism:** the Perfect Week cron is exercised by seeding a *previous* ISO week (questions + 6/6 correct answers backdated) and invoking `public/cron/challenge_weekly.php` over HTTP with the test Bearer secret — twice, asserting one ledger row.
- **Duel resolution:** reuse `seed_betting_race` + the existing `seed_score_race`/`seed_reset_result` style actions to drive `update_race`/`reset_race_result` through the admin flow, not by poking the DB.
- **Cleanup:** `cleanup_challenges` in suite teardown; the SMTP intercept log is cleared per run (existing behavior).

## 4. Acceptance criteria

Full gherkin in `feature.md` §Acceptance Criteria (areas A–F). Critical scenarios: guest session
grants no core access · enumeration-safe join · magic link single-use + 30-min expiry · silent core
auto-link · conversion preserves CP with `in_competition=0` · 5/2/0 duel arithmetic · void ·
reset-reversal round-trip · Perfect Week awarded exactly once · tracker = bonus condition · nav swap
scenarios · CP/betting isolation.

## 5. Test cases

| ID | Case | Expected | Pri | Type |
| --- | --- | --- | --- | --- |
| CH-01 | Challenge session requests `profile.php`, `bet.php`, `admin.php` | treated as logged out (redirect), zero core access | Critical | Sec |
| CH-02 | Join with existing vs unknown email | byte-identical HTTP status + body | Critical | Sec |
| CH-03 | Magic link: valid → verified + session; reused → refused; backdated 31 min → refused | per feature AC | Critical | E2E |
| CH-04 | 6th magic-link request in window (scope `'magic'`) | throttled, `Retry-After: 900`, not HTTP 429 | High | Sec |
| CH-05 | POST endpoints without CSRF token | rejected | Critical | Sec |
| CH-06 | Core member first hub visit | participant row auto-created, linked, display name reused | High | E2E |
| CH-07 | Conversion of guest with 45 CP | users row `in_competition=0`, same CP total, `establishSession()` fired, absent from betting leaderboard/pool | High | E2E |
| CH-08 | Double-submit same rumor answer (rapid re-POST) | one `challenge_answers` row, one ledger row | Critical | Integration |
| CH-09 | CP board shows guest with hostile display name (`<script>…`) | escaped, renders inert | High | Sec |
| CH-10 | CP board + chip totals vs seeded ledger | exact sums; betting points absent | High | E2E |
| CH-11 | Streak fixtures: today+yesterday / gap / none | 2 · 0 · 0 (per D6, incl. yesterday-grace) | Med | Unit+E2E |
| CH-12 | Join with a core member's email | identical response, no participant row, "log in" email — not a magic link (REQ-111) | Critical | Sec |
| I18N-01 | Rumor + trivia content in da and en sessions | stored bilingual text follows the participant's language, switch included | Med | E2E |
| NAV-01 | Bottom bar on every non-admin page | Home/Races/Board/Challenges, accented cell, active states | High | E2E |
| NAV-02 | Signed-out drawer preferences | theme/lang/font toggle round-trips work, params preserved | High | E2E |
| NAV-03 | Existing appearance/preferences/profile suites after Phase 2 | green after spec updates; **prerequisite:** grep-inventory of every spec touching `.hf-bottom`/toggle selectors *before* Phase 2 starts | Critical | Regression |
| NAV-04 | Hub: no second bottom bar; arena tint applied | per REQ-008 | Med | E2E |
| HERO-01 | Hero at D9 boundaries: race seeded so now = windowOpen−25h / −23h / start+2h / start+4h | Challenges / race / race / Challenges hero (`isRaceHeroWindow()` unit + E2E spot-check) | High | Unit+E2E |
| ADM-01 | Converted guest (seeded) on the Challenges admin page | listed with CP + conversion date; `in_competition` toggle flips pool/leaderboard membership | High | E2E |
| ADM-02 | Admin-page access + users-tab exclusion | non-admin/guest session denied like `admin.php`; converted guest absent from core users tab | Critical | Sec |
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
