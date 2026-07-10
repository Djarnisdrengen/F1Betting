# Implementation Plan: E2E Test Suite Restructuring

Epic: `epic-e2e-test-restructure.md`
Test plan: `test-plan.md`

Reviewed by `web-architecture-review` (tooling architecture, orchestration, rollback) and
`test-manager` (coverage, reliability, maintainability, live-safety, gaps). Both **approve the
taxonomy + tagging approach** and **approve with conditions** on the multi-process orchestrator —
this plan folds in every MUST FIX / SHOULD FIX from both passes.

Baseline (verified from source, `grep -cE '^\s*test\(' tests/e2e/**/*.spec.js`): **175 tests, 21 spec
files.** Re-derive at implementation time — do not carry forward any other figure.

---

## A. Grouping mechanism — Playwright `tag` + `projects` (zero file moves)

- Add `{ tag: '@<slug>' }` to the ~25–30 existing `test.describe(...)` blocks (and directly on the 3
  `@mobile` tests, see epic's Suite Taxonomy table for the slug list). Zero test bodies touched, zero
  files moved. Fully reversible.
- Add a `projects` array to `tests/playwright.config.js`, one per suite:
  `{ name: '<slug>', grep: /@<slug>\b/, use: { ...devices['Desktop Chrome'] } }`.
- **Leave the live-safety `testMatch` gate byte-for-byte unchanged.** It is evaluated *before* any
  project `grep`, so on `DEPLOY_ENV=live` only `01-smoke.spec.js` is ever discoverable regardless of
  `--project` — defense in depth for free.

## B. npm scripts

- One test-env script per suite: `test:e2e:smoke`, `test:e2e:auth`, `test:e2e:registration`,
  `test:e2e:predictions`, `test:e2e:scoring`, `test:e2e:race-page`, `test:e2e:admin`,
  `test:e2e:profile`, `test:e2e:appearance`, `test:e2e:preferences-editor`, `test:e2e:cron`,
  `test:e2e:mobile`. Each reuses today's env preamble (`EMAIL_BACKEND=intercept`,
  `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE`, `DEPLOY_ENV=test`) + `--project=<slug>`.
- Exactly one `:live` variant: **`test:e2e:smoke:live`** (keeps `confirm-live.js` gate). No other
  suite gets a live script — `npm run test:e2e:predictions:live` fails loudly with "missing script,"
  which is the correct, safe outcome.
- `test:e2e:test` / `test:e2e:live` become thin wrappers over the orchestrator (D), with a
  `test:e2e:test:legacy` escape hatch retained (MUST-4).

## C. Reporter (`tests/reporter.js`)

- Wire up `onBegin(config, suite)`: capture `suite.allTests().length` as the per-invocation total and
  read `DEPLOY_ENV`. Print an opening line: `▶ <Suite name> — env: <env> — <N> tests` (closes an
  existing gap: env is never printed today).
- `onTestBegin`: prefix the existing `⏳ ${title}` line with `[<index>/<total> · <pct>%]`.
- `onTestEnd`: prefix the existing result line the same way. **Per-test duration is unchanged and
  stays exactly as today** — the `(${secs}s)` suffix is preserved, only a progress prefix is added:
  `[3/21 · 14%] ✅ AC-PROF-04 — hero card and 2 chips render (2.3s)`.
- `onEnd`: repeat env + suite name in the summary (`✅ Profile & Stats passed (17/17) — env: test`).
- Reporter stays **suite-count-agnostic** (no "suite 2 of 11") — that framing is the orchestrator's.

## D. Full-run orchestrator (`tests/run-e2e-suites.js`, new)

- Spawns each suite as its own `npx playwright test --project=<slug>` child process, **awaited
  sequentially** (hard non-interleave guarantee). Rejected Playwright `project.dependencies` (it
  auto-runs deps unless `--no-deps`, breaking standalone invocability).
- **Suite execution order is a fixed, explicit literal array — Smoke → Auth → Registration →
  Predictions → Scoring → Race Page → Admin → Profile → Appearance → Preferences Editor → Cron** — not
  alphabetical by slug, not directory-discovery order. Required to preserve
  `docs/test-strategy.md` principle 8 ("fail fast — smoke first, auth second"): cheap, high-signal
  suites fail loudly before the run sinks time into the larger ones. State the order as a literal
  array in the orchestrator so it can't drift.
- Pre-pass: `--list --reporter=json` per suite for **dynamic real counts** (self-checking against
  drift — feeds MUST-1).
- Prints cross-suite framing between legs: `▶ Suite 3 of 11 — Podium Predictions — cumulative 65/175 (37%)`.
- Tracks each leg's result **independently** (via per-leg JSON, MUST-2) so one failing suite does not
  block or skip later suites; orchestrator exit code is non-zero iff any suite failed **or crashed**.

---

## Run-time impact (quantified — see epic Success Metrics)

Splitting one process into 11 sequential legs is required by the sequential + independent-pass/fail +
cross-suite-progress ACs together — a single-process design can't give a hard sequencing guarantee.
Each process pays fixed costs today's single invocation only pays once.

- **Unmitigated** (naive: 11 legs × full setup/teardown each): ~35–75s added on a 5–10 min baseline
  (~6–15% regression) — dominated by 11 separate browser-launch+login round trips and repeated
  inbox-purge/SMTP-intercept toggling.
- **Mitigated** (MUST-5 storageState reuse + MUST-6 LEG-aware standalone default, both required, not
  optional): floor drops to the irreducible Node/Playwright process-bootstrap cost, **~1–2s × 11 legs
  ≈ 11–22s (~2–4% of baseline)**. This is the floor of any design meeting the epic's own ACs, not a
  gap in this one.

---

## MUST FIX (blockers — required before the orchestrator gates `deploy:test`)

- **MUST-1 — Orphaned/untagged test detection.** Once discovery is by `--project` tag instead of the
  `**/*.spec.js` glob, an untagged spec or a tag typo (`@predicitons`) silently never runs — a *silent
  coverage regression*. The orchestrator must diff "tests found by the raw `testDir` glob" vs. "sum
  across all project greps" and **fail the run on any mismatch.** Blocks Phase 2 sign-off.
- **MUST-2 — Orchestrator must distinguish "child crashed" from "tests failed."** Playwright exits
  non-zero for both. Pair the human reporter with a built-in `json` reporter per leg
  (`reporter: [['./reporter.js'], ['json', { outputFile }]]`); the orchestrator reads
  `{ total, failed, crashed }` — never exit code alone. *(Rated Critical by web-architecture-review.)*
- **MUST-3 — Executed live-safety regression (not reasoned).** Actually run
  `DEPLOY_ENV=live npm run test:e2e:predictions` (a mutating suite) and confirm it executes **zero
  tests** *and* prints an unmistakable "0 matched / not a clean pass" signal — **not** a misleading
  `0/0 passed ✅`. This suite can place bets and change passwords; a config-precedence bug here is a
  production-data-mutation risk, the highest severity category.
- **MUST-4 — Tested rollback path.** This repoints `test:e2e:test`, which gates `npm run deploy:test`.
  Ship a `test:e2e:test:legacy` (direct single-invocation) escape hatch and keep it for **≥1 full
  deploy cycle** on the new path before deleting the old one. *(Escalated from Low by test-manager,
  given it gates production deploys.)*
- **MUST-5 — Concurrent-run safety statement + atomic storageState write.** This epic makes
  standalone runs the normal workflow, so two suites hitting the shared test DB / same
  `.auth/admin.json` at once becomes likely. State explicitly whether concurrent runs are supported;
  at minimum write `.auth/admin.json` atomically (temp file + `rename`). Pairs with SHOULD-1's
  liveness check — same code path, two distinct bugs, fix together.
- **MUST-6 — LEG-aware setup/teardown needs an explicit standalone default.** Originally filed as
  SHOULD by both reviews; elevated here because it is a **normal-path** bug, not an edge case: a
  direct `npm run test:e2e:auth` run has no `E2E_SUITE_LEG`/`E2E_SUITE_TOTAL` set at all. If
  `global-setup.js` doesn't treat *unset* as "standalone run → always purge inbox + toggle intercept"
  (today's behavior), then **every single standalone suite run** — the epic's primary adoption
  metric — silently gets stale mail state and unreliable email-dependent assertions (auth OTP, cron
  notifications, invites). Breaks the main feature on day one. Add an explicit regression test for the
  unset-env-var branch. *(Rated High by web-architecture-review.)*
- **MUST-7 — Fix the 2 mobile-tagged tests that are provably broken when run standalone.** Verified
  against actual source: `admin/13-scoring.spec.js`'s and `auth/32-mfa-default-method.spec.js`'s
  `@mobile` tests depend on **sibling test bodies** (an admin-UI scoring submission; a TOTP+email
  enrollment flow) that `--grep @mobile` skips entirely — Playwright only auto-runs
  `beforeAll`/`afterAll` for a selected describe block, never earlier sibling *tests*.
  - `admin/13-scoring.spec.js:99` ("podium is visible on mobile") — `beforeAll` calls
    `seed.scoreRace()`, which per `public/tools/test-seed.php:944-1043` deliberately leaves Race B
    **unscored** ("test enters it via admin UI"). Scoring happens in sibling test #1 (line 45), 5
    tests earlier. Skipped via grep, `/leaderboard.php` has no podium — the mobile assertion fails
    every time.
  - `auth/32-mfa-default-method.spec.js:170` ("AC-MFA-09") — `beforeAll` only creates a bare user;
    TOTP + email-OTP enrollment happens via UI in sibling test #1 (line 77). Without it, login never
    reaches `mfa_challenge.php` — the test's `waitForURL(/mfa_challenge\.php/)` times out standalone.
  - `14-race-page.spec.js:82` is **safe** — depends only on `beforeAll` (seed + login), no
    sibling-body dependency. No fix needed there.
  - **Fix, scoped to just the 2 broken tests:** give each its own data-layer seed instead of relying
    on a sibling's UI mutation — a `prescored` variant of `seed_score_race` that sets Race B's
    `result_p1/p2/p3` directly via SQL, and an equivalent "user with TOTP+email already enrolled"
    seed for the MFA case. Not a reversal of "zero code motion" — it brings these 2 tests into line
    with `docs/test-strategy.md`'s own "each spec owns its data" principle, which their serial-sibling
    reliance already quietly violated. Blocks Phase 3 (mobile suite) sign-off, not Phase 2.
- **MUST-8 — Orchestrator owns intercept cleanup via `try/finally`.** Rated **High** by
  web-architecture-review. A crash between leg-1 `smtp_intercept_on` and the last leg's
  `smtp_intercept_off` leaves interception **stranded ON for the shared test environment**, silently
  corrupting *other developers'* subsequent runs, not just the crashed one, until someone notices and
  manually resets it. Fix: the orchestrator, not the last child's teardown, guarantees the final
  `smtp_intercept_off` via `try/finally`.

## SHOULD FIX (address now — will cause pain later, but not day-one blockers)

- **SHOULD-1 — storageState reuse needs a liveness check, not just file mtime.** If the app session
  TTL is shorter than the freshness window, all legs fail at once with a confusing auth error that
  looks like N unrelated failures. Validate the reused session with one cheap authenticated request,
  or scope reuse to a single orchestrator run (delete `.auth/admin.json` at run start).
- **SHOULD-2 — Docs updated in the same PR.** `docs/testing.md` / `docs/test-strategy.md` don't
  mention tags and the TOC is already missing `15-env-banner.spec.js`. Add the suite→tag lookup table
  and a "which tag does this belong to?" required field in the new-spec checklist (this is what
  prevents MUST-1 from recurring on every new feature). Fix the stale TOC.
- **SHOULD-3 — Orchestrator gets minimal CI coverage.** The orchestrator is the riskiest new
  component and today would ship with zero automated coverage (CI only runs live-smoke). Add a
  `workflow_dispatch` CI job that runs the orchestrator against the test env before it is trusted as
  a deploy gate.
- **SHOULD-4 — Publish measured per-suite durations + a Predictions cross-reference.** After
  implementation, record actual per-suite times (Authentication at 49 serial tests is likely the
  slowest) so "fast feedback" is measured, not assumed. Add a doc note that Predictions (5) covers
  bet *placement*; pre-race display lives in Race Page (16), so the small count isn't a coverage gap.

*(Resolved, not just theorized: whole-file/whole-describe tagging for all 11 primary suites is safe by
construction — a project's grep always selects an entire serial block, never a subset, so no primary
suite can hit the MUST-7 class of bug. The one place partial-block selection happens — the `@mobile`
secondary tag — is exactly where MUST-7 found and fixed the 2 real breakages. Non-blocking NICE TO
HAVE: a one-line check that `confirm-live.js` still fires exactly once post-refactor.)*

**Severity accounting (both review passes, fully reconciled):** every Critical/High finding from
`web-architecture-review` is now MUST FIX — Critical (MUST-2), High/standalone-default (MUST-6),
High/intercept-stranding (MUST-8). Its two Medium findings stay SHOULD FIX (SHOULD-1 storageState
liveness, SHOULD-2 stale docs). Its one Low finding (rollback path) was independently escalated to
MUST-4 by `test-manager` since it gates production deploys.

---

## Phased implementation plan

| Phase | Content | Shippable | Risk |
|---|---|---|---|
| **0 — Epic + tags** | Apply epic taxonomy/contradiction edits (done — see `epic-e2e-test-restructure.md`). Add `@<slug>` tags to ~25–30 describe blocks + 3 `@mobile` tests. **No config/script change** — `test:e2e:test` behaves identically. Fix stale docs (SHOULD-2). | Yes (invisible) | None — pure annotation |
| **1 — Projects + scripts + reporter** | Add `projects` array + all `test:e2e:<suite>` scripts + `test:e2e:smoke:live`. Reporter `onBegin` total/X-of-Y/pct/env (C). Old full-run scripts untouched. | Yes | Low — additive |
| **2 — Orchestrator** | Build `tests/run-e2e-suites.js` (D) with **MUST-1, MUST-2, MUST-5, MUST-6, MUST-8** and **SHOULD-1** baked in, including the fixed literal suite-order array (fail-fast: smoke → auth → ...). Repoint `test:e2e:test`/`test:e2e:live`; keep `test:e2e:test:legacy` (MUST-4). Run **MUST-3** live-safety regression. Add SHOULD-3 CI job. | Yes | Medium — touches the deploy gate |
| **3 — Cleanup** | Apply **MUST-7** seed fixes for the 2 broken mobile tests *before* wiring `test:e2e:mobile` up as advertised-standalone. Publish measured durations (SHOULD-4). Remove `:legacy` hatch only after one clean `deploy:test` cycle on the new path. | Yes | Low |

**Definition of Ready** (before Phase 0): epic taxonomy table (incl. the profile/appearance/
preferences-editor split) and the three resolved contradictions approved; count pinned to 175;
JSON-reporter-alongside-custom-reporter confirmed feasible.

**Definition of Done:** all 12 suites runnable standalone with correct counts and **per-test duration
still printed on every line** (C); full run sequential, in the fixed fail-fast order, with per-suite
**and** cross-suite progress; orphan check green (MUST-1); orchestrator distinguishes crashed vs
failed (MUST-2); executed live-safety regression proves zero-mutation on live (MUST-3); atomic
storageState + concurrent-run statement (MUST-5); standalone-run env-var default verified (MUST-6);
**both mobile-tagged tests pass when run via `npm run test:e2e:mobile` alone, not just as part of
their home suite (MUST-7)**; orchestrator guarantees intercept-off even after a crashed leg (MUST-8);
docs updated (SHOULD-2); `:legacy` hatch removed only after a clean `deploy:test` cycle (MUST-4).

---

## Critical files

- `epics/Optimize test suite structure/epic-e2e-test-restructure.md` — refined epic (taxonomy, resolved contradictions, reworded success metric)
- `tests/playwright.config.js` — `projects` array; live `testMatch` gate untouched
- `tests/reporter.js` — `onBegin` total/X-of-Y/pct/env
- `tests/global-setup.js` / `tests/global-teardown.js` — LEG-aware + standalone default (MUST-6), storageState reuse guard + liveness check (MUST-5, SHOULD-1)
- `tests/run-e2e-suites.js` — **new** orchestrator (D + MUSTs)
- `package.json` — per-suite scripts, `:legacy` hatch
- The 21 spec files under `tests/e2e/**` — additive `{ tag }` annotations only (no body/file moves)
- `tests/helpers/seed.js`, `public/tools/test-seed.php` — new `prescored`/pre-enrolled-MFA seed variants (MUST-7)
- `docs/testing.md`, `docs/test-strategy.md` — suite→tag table, checklist field, stale-TOC fix (SHOULD-2)
- `.github/workflows/nightly-tests.yml` (or new) — `workflow_dispatch` orchestrator job (SHOULD-3)

## Verification

```bash
# Per-suite standalone (each prints "▶ <name> — env: test — N tests" + [x/total · pct%])
DEPLOY_ENV=test npx playwright test --project=profile            --config tests/playwright.config.js
DEPLOY_ENV=test npx playwright test --project=appearance         --config tests/playwright.config.js
DEPLOY_ENV=test npx playwright test --project=preferences-editor --config tests/playwright.config.js

# Full orchestrated run — sequential legs, cross-suite "Suite k of 11", cumulative /175
npm run test:e2e:test

# MUST-1: orphan check — must fail if any spec is untagged (temporarily drop a tag to prove it)
# MUST-3: live-safety — MUST run zero tests, MUST NOT show a misleading "0/0 passed ✅"
DEPLOY_ENV=live npx playwright test --project=predictions --config tests/playwright.config.js

# Count parity — full run must still execute exactly 175 tests
```

## Rollback

All changes additive through Phase 1 (tags + projects + scripts coexist with the old single
invocation). Phase 2 repoints `test:e2e:test`/`test:e2e:live` — `test:e2e:test:legacy` (MUST-4) is the
rollback path, kept for ≥1 full deploy cycle. To fully abandon: remove `projects` array (falls back to
one implicit project), delete `run-e2e-suites.js`, drop `{ tag }` annotations (test bodies unaffected).
