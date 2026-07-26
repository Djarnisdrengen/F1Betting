# Test Plan: E2E Test Suite Restructuring

Epic: `epic-e2e-test-restructure.md` · Plan: `plan.md`
Reviewed by the `test-manager` skill. This plan tests the **test infrastructure itself** (tagging,
projects, reporter, orchestrator) — not an application feature, so the object under test is
`tests/playwright.config.js`, `tests/reporter.js`, `tests/run-e2e-suites.js`, and the tag annotations
on the 21 existing spec files.

---

## 1. Scope & objectives

- **Testing:** that restructuring the E2E suite into 11 tagged, independently-runnable projects plus
  a sequential orchestrator does not silently drop coverage, does not leak live-mutation risk, and
  does not introduce new flakiness — while genuinely delivering standalone suites + live progress.
- **Why it matters:** this repoints `test:e2e:test`, which gates `npm run deploy:test`. A bug in the
  orchestrator or the tag/project wiring is a deploy-gate bug, and a bug in the live `testMatch`
  interaction is a production-data-mutation risk — the two highest-severity categories for this repo.
- **Success criteria:** all 175 tests still execute exactly once in the full run; every suite runs
  correctly standalone (including the 2 mobile tests that are provably broken today under naive
  `--grep` selection, MUST-7); live-safety gate proven empirically, not just reasoned about (MUST-3);
  orchestrator distinguishes a crashed leg from a failed leg (MUST-2).
- **Out of scope:** the application behavior each of the 175 tests exercises is unchanged and
  out of scope here — this plan only covers the *infrastructure* wrapping them.

## 2. Test types

| Type | Tool | Scope |
|---|---|---|
| Static/self-check | orchestrator pre-pass (`--list --reporter=json`) | orphan/untagged-test detection (MUST-1), dynamic per-suite counts |
| Integration | manual + scripted per-suite runs | each of the 12 `test:e2e:<suite>` scripts runs the correct, exact test set |
| Regression | executed against `DEPLOY_ENV=live` | live-safety gate still excludes every non-smoke suite (MUST-3) |
| Process/orchestration | orchestrator run against test env | sequential (non-interleaved) execution, correct fixed suite order, independent per-leg pass/fail, crash vs. fail distinction (MUST-2), intercept cleanup on crash (MUST-8) |
| Concurrency | two parallel invocations | storageState write is atomic and doesn't corrupt a concurrent run (MUST-5) |
| CI | `workflow_dispatch` job | orchestrator gets at least one automated run before being trusted as a deploy gate (SHOULD-3) |

## 3. Test data / fixtures

| Fixture | Purpose |
|---|---|
| Existing `seed.authUser()`, `seed.scoreRace()` (unmodified) | used by 20 of 21 spec files, unaffected by tagging |
| **New:** `seed.scoreRace({ prescored: true })` (or equivalent) | sets Race B's `result_p1/p2/p3` directly via SQL — unblocks `admin/13-scoring`'s mobile test standalone (MUST-7) |
| **New:** `seed.authUser({ mfaEnrolled: 'totp+email' })` (or equivalent) | pre-enrolls TOTP+email so `mfa_challenge.php` is reachable without the sibling enrollment test — unblocks `auth/32`'s AC-MFA-09 mobile test standalone (MUST-7) |
| `.auth/admin.json` (storageState) | reused across orchestrated legs (MUST-5); needs atomic write + liveness check, not bare mtime (SHOULD-1) |
| `E2E_SUITE_LEG` / `E2E_SUITE_TOTAL` env vars | orchestrator-only; unset = standalone default (always purge inbox / toggle intercept) — MUST-6 |

## 4. Acceptance criteria

See epic Gherkin (all 6 scenarios). Critical scenarios for this test plan specifically:
**zero-mutation on live for every non-smoke suite** (MUST-3) · **exact 175-test count preserved,
no orphaned/untagged test** (MUST-1) · **all 12 suites pass standalone, including both previously-
broken mobile tests** (MUST-7) · **crashed leg reported distinctly from failed leg** (MUST-2) ·
**fixed fail-fast suite order preserved in the full run** · **per-test duration still printed on
every line** (unchanged reporter behavior, not a regression).

## 5. Test cases

| ID | Case | Expected | Pri | Type |
|---|---|---|---|---|
| INFRA-01 | Full run (`npm run test:e2e:test`) executes all 175 tests | count matches source-verified 175, no duplicates, no gaps | Critical | Integration |
| INFRA-02 | Orphan/untagged-spec detection (temporarily drop a tag) | orchestrator fails the run with an explicit orphan-test error | Critical | Static/self-check (MUST-1) |
| INFRA-03 | `DEPLOY_ENV=live npx playwright test --project=predictions` | zero tests executed, unambiguous "0 matched" signal — not a misleading `0/0 passed ✅` | Critical | Regression (MUST-3) |
| INFRA-04 | `DEPLOY_ENV=live npm run test:e2e:smoke:live` | runs only smoke tests, `confirm-live.js` gate still fires exactly once | High | Regression |
| INFRA-05 | Each of the 11 primary `test:e2e:<suite>` scripts, run standalone | executes exactly that suite's tagged tests, correct count, independent pass/fail | High | Integration |
| INFRA-06 | `npm run test:e2e:mobile` (all 3 tests) run standalone, cold (no prior sibling run) | all 3 pass, including `admin/13-scoring` and `auth/32` cases that fail today under naive `--grep` | Critical | Integration (MUST-7) |
| INFRA-07 | Orchestrator: kill a child process mid-leg (simulated crash) | reported as "crashed," not folded into "N tests failed"; exit code non-zero | Critical | Process (MUST-2) |
| INFRA-08 | Orchestrator: one leg has real test failures, later legs still run | later legs execute independently; overall exit code non-zero; failed leg clearly attributed | High | Process |
| INFRA-09 | Orchestrator: kill a child mid-chain, inspect SMTP-intercept state after | intercept is OFF post-run (try/finally in orchestrator, not last-child teardown) | High | Process (MUST-8) |
| INFRA-10 | Full run executes suites in fixed order (smoke → auth → registration → ... → cron) | order matches literal array, not alphabetical/discovery order | Med | Process |
| INFRA-11 | Two orchestrator runs launched concurrently against the test env | no corrupted/partial `.auth/admin.json`; both runs complete or fail cleanly, no cross-contamination | High | Concurrency (MUST-5) |
| INFRA-12 | Reused `storageState` from a run whose session has since expired | detected via liveness check, not just accepted on mtime freshness — clear re-login, not N confusing auth failures | Med | Concurrency (SHOULD-1) |
| INFRA-13 | Reporter output for any suite run | prints `▶ <suite> — env: <env> — N tests` header, `[i/total · pct%]` prefix per test, unchanged `(Xs)` duration suffix, summary line with env+suite | High | Integration |
| INFRA-14 | Standalone suite run with no `E2E_SUITE_LEG`/`E2E_SUITE_TOTAL` set | inbox purge + intercept toggle behave exactly as today's full run (unset = standalone default) | Critical | Integration (MUST-6) |
| INFRA-15 | `npm run test:e2e:test:legacy` | single-invocation legacy path still works, unchanged from pre-restructure behavior | Med | Regression (MUST-4) |
| INFRA-16 | CI `workflow_dispatch` orchestrator job | runs the orchestrator against test env, reports pass/fail in Actions UI | Low | CI (SHOULD-3) |

## 6. Risk assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Untagged/mistyped-tag spec silently never runs | Med | Critical | MUST-1 orphan check; INFRA-01/02 |
| Live `testMatch` gate bypassed via `--project` selection | Low | Critical | `testMatch` evaluated before project `grep` (defense in depth); INFRA-03/04 |
| Orchestrator reports "N failed" for an actually-crashed leg | Med | High | MUST-2 paired JSON reporter; INFRA-07/08 |
| Standalone mobile suite fails deterministically (sibling-body dependency) | High (proven) | Med | MUST-7 scoped data seeds; INFRA-06 |
| Intercept stranded ON after a crashed orchestrator run, corrupting shared test env | Med | High | MUST-8 orchestrator-owned `try/finally`; INFRA-09 |
| Concurrent runs corrupt shared `storageState` file | Med | Med | MUST-5 atomic write; INFRA-11 |
| Reused session expired mid-run, all legs fail with confusing auth errors | Low | Med | SHOULD-1 liveness check; INFRA-12 |
| Standalone run missing today's "always purge/toggle" behavior | High (normal path) | High | MUST-6 explicit unset-default; INFRA-14 |
| Run-time regression beyond epic's tolerance | Med | Low | MUST-5 + MUST-6 bound it to ~11–22s / ~2–4% (see `plan.md`); measured post-implementation (SHOULD-4) |

## 7. Definition of Done (testing)

☐ INFRA-01 through INFRA-16 green · ☐ full run proven to execute exactly 175 tests, no
orphans/duplicates · ☐ live-safety proven empirically for a mutating suite, not just smoke · ☐ both
previously-broken mobile tests pass standalone · ☐ crash-vs-fail distinction proven with a real
simulated crash · ☐ intercept cleanup proven after a real simulated crash · ☐ concurrent-run
behavior stated and proven safe · ☐ standalone unset-env-var default proven · ☐ per-test duration
output unchanged · ☐ legacy escape hatch verified working · ☐ `:legacy` hatch removed only after
one full clean `deploy:test` cycle on the new path.
