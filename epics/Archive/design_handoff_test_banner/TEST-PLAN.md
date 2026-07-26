# Test plan & QA review — Test-environment banner (v2.4.1)

Test-manager review of the proposed coverage for [README.md](./README.md), 2026-07-05.

## Proposed coverage (as reviewed)

1. Env-conditional test in `tests/e2e/01-smoke.spec.js` — banner **present** when `DEPLOY_ENV === 'test'`, **absent** (`toHaveCount(0)`) when `'live'`. This spec is the only one the `testMatch` gate (`tests/playwright.config.js:33-36`) runs against live, and `deploy:live` runs it automatically after upload with rollback on failure.
2. New `tests/e2e/15-env-banner.spec.js` (full suite, test env only): sticky-after-scroll, position directly below `.hf-top`, no horizontal scroll at 320px, one-line plate, drawer-covers-banner stacking.

## Review findings

### 🔴 MUST FIX (addressed before implementation)

1. **The live gate is only as good as `DEPLOY_ENV` propagation.** If `deploy.js` didn't export `DEPLOY_ENV=live` to its Playwright child, the conditional would silently take the "present" branch. **Verified resolved:** `build-deploy/deploy.js:55-61` sets `DEPLOY_ENV: env` and `BASE_URL` explicitly. No change needed, but this coupling is now documented here — do not remove those env vars from `deploy.js`.
2. **The absence test must not trust `DEPLOY_ENV` alone.** A mis-set env var must not be able to produce a false pass (asserting "absent" against the *test* site, or "present" against *live*). The live branch must first assert the page host is actually `formula-1.dk` before asserting absence. Implemented in `01-smoke.spec.js`.

### 🟡 SHOULD FIX (addressed)

3. **Text assertions must use the exact DA/EN strings, not a loose substring.** `t()` falls back to returning the raw key (`functions.php:85-102`), so a missing `test_site_banner` key would render `test_site_banner` in the banner — a `.test-banner` visibility check would still pass. Asserting the exact text `Dette er en testhjemmeside|This is a test website` makes a missing i18n key fail loudly. (Alternation, not one fixed string: the shared admin session's language preference is not pinned, so a single-language assertion would be flaky.)
4. **Sample an admin page, not just public pages.** `admin.php` includes the same header (`admin.php:573`), but "renders on every page" (AC-TB-01) deserves one authenticated + one admin data point, not just `/`. Implemented in `15-env-banner.spec.js` using the shared `.auth/admin.json` state.
5. **No hardcoded waits.** Sticky behaviour asserted via `boundingBox()` after `mouse.wheel` + a bounded `expect.poll`/locator auto-wait, not `waitForTimeout`.

### 🟢 NICE TO HAVE (noted, not done)

6. `tests/smoke.js` only supports positive `contains` checks and shares one `CHECKS` list across envs, so it cannot assert banner absence on live without a runner change. Not worth modifying — the Playwright live gate covers it. If the runner ever grows an `absent:` concept, add `{ path: "/", absent: "test-banner" }` for live.
7. Visual/contrast automation (axe/screenshot diff) doesn't exist in this repo; AC-TB-03 stays manual.

## Coverage map

| AC | Layer | Where |
|----------|------------------|--------------------------------------------------|
| AC-TB-01 | E2E (test env) | `15-env-banner.spec.js` — `/`, `/login.php`, `/races.php`, `/admin.php` |
| AC-TB-02 | E2E (test env) | `15-env-banner.spec.js` — sticky after scroll |
| AC-TB-03 | Manual | Compare against `Test Banner.html`, 3 themes; plate contrast #1a1a00 on #ffcf00 ≈ 13:1 |
| AC-TB-04 | Code review | `git diff` shows only additions to style.css / header.php |
| AC-TB-05 | E2E (test env) | `15-env-banner.spec.js` — plate single-line at 320px |
| AC-TB-06 | E2E (test env) | `15-env-banner.spec.js` — no h-scroll at 320px; drawer z-index > banner |
| AC-TB-07 | E2E (live gate) | `01-smoke.spec.js` — host-verified absence on formula-1.dk, runs during `deploy:live` with auto-rollback |

## Test data / environment notes

- No DB seeding required — the banner is stateless and unauthenticated-visible; only the admin.php sample uses the existing shared admin session from `global-setup.js`.
- The full suite (including `15-env-banner.spec.js`) never runs against live (`testMatch` gate); no live-mutation risk from these tests.
- Failure mode if live config were ever wrong (`APP_ENV = 'test'` on live): the `deploy:live` E2E gate fails the host-verified absence test and `deploy.js` rolls live back to the pre-deploy backup.

## Verdict

⚠️ **APPROVE WITH CONDITIONS** — both 🔴 items resolved (1 verified in deploy.js, 2 implemented as host-verified absence), 🟡 items folded into the specs as described above.
