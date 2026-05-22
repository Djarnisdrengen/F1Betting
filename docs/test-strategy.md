# Test Strategy & Architecture — F1Betting

## Contents

- [Strategy Principles](#strategy-principles)
- [The Two Stacks](#the-two-stacks)
- [Architecture Layers (Stack A only)](#architecture-layers-stack-a-only)
  - [Layer 1 — Config](#layer-1--config)
  - [Layer 2 — Helpers](#layer-2--helpers)
  - [Layer 3 — Fixtures](#layer-3--fixtures)
- [Mailsac Inboxes](#mailsac-inboxes)
- [Adding a New Test](#adding-a-new-test)
  - [Which stack?](#which-stack)
  - [New user-facing feature (Stack A)](#new-user-facing-feature-stack-a)
  - [New admin feature (Stack A)](#new-admin-feature-stack-a)
  - [New email type (Stack A)](#new-email-type-stack-a)
  - [New security check (Stack B)](#new-security-check-stack-b)
  - [New standalone script (Stack B)](#new-standalone-script-stack-b)
- [CWE Top 25 Coverage](#cwe-top-25-coverage)

---

Answers the question: *How do we think about testing, and how do I add a new test?*

For commands and what each spec covers, see [testing.md](testing.md).

---

## Strategy Principles

These govern every decision when adding or changing a test.

1. **Test at the right layer** — test the smallest unit that can meaningfully fail. Don't write E2E to cover what a unit test can prove.
2. **Each spec owns its data** — seed in `beforeAll`, clean up in `afterAll`. No spec depends on another spec's data or execution order.
3. **No DB wipes** — the full-wipe integration approach is banned. Targeted seed/cleanup only.
4. **Test env only for mutations** — nothing that creates, changes, or deletes data ever runs against Live.
5. **One assertion per email type** — each email type is tested once, in the spec that triggers it. No separate "email spec" that duplicates assertions from other specs.
6. **Shared code, not shared state** — helpers are pure functions shared via `require`. State (`seedData`, sessions) stays local to the spec that needs it.
7. **Numbered filenames = explicit order** — specs run in filename order. Numbers document intent and dependency.
8. **Fail fast** — smoke first, auth second. If the app is down or login is broken, later specs won't mislead.

---

## The Two Stacks

The test suite is not one unified system — it is two separate stacks that share a single config source but nothing else. Understanding which stack you are working in tells you exactly how config, credentials, and shared code flow.

```
┌─────────────────────────────────────────────────────────────────────┐
│  SINGLE SOURCE OF TRUTH                                             │
│  config.test.php / config.live.php  (not in git)                   │
│  build-deploy/php-config.js  (reads the PHP constants into Node)    │
└────────────────┬───────────────────────────┬────────────────────────┘
                 │                           │
                 ▼                           ▼
┌───────────────────────────┐   ┌───────────────────────────────────┐
│  STACK A — Playwright E2E │   │  STACK B — Standalone scripts     │
│                           │   │                                   │
│  playwright.config.js     │   │  smoke.js                         │
│    ↓ sets process.env.*   │   │  security/security.js             │
│  global-setup.js          │   │  email-preview.js                 │
│    ↓ (also reads          │   │  nightly-report.js                │
│       php-config directly │   │                                   │
│       as a safety net)    │   │  Each reads php-config.js         │
│  fixtures/index.js        │   │  directly. No shared helpers.     │
│  helpers/seed.js          │   │  Self-contained by design.        │
│  helpers/mailsac.js       │   │                                   │
│  helpers/markers.js       │   │  On GitHub Actions: falls back    │
│  e2e/**/*.spec.js         │   │  to process.env (GH Secrets)      │
│                           │   │  since php-config is absent.      │
│  On GitHub Actions:       │   │                                   │
│  playwright.config.js     │   └───────────────────────────────────┘
│  falls back to GH Secrets │
└───────────────────────────┘
```

**Rule: helpers in Stack A are not usable from Stack B**, and vice versa.
`helpers/seed.js` reads `process.env.BASE_URL` and `process.env.INTEGRATION_SEED_TOKEN` which are only set after `playwright.config.js` evaluates. Calling `seed.js` from a standalone script leaves those vars empty. It is Playwright-stack-only by design.

`email-preview.js` runs as `node tests/email-preview.js` — it is Stack B. It reads `php-config.js` directly, the same way `smoke.js` does. It must not import `helpers/seed.js`.

---

## Architecture Layers (Stack A only)

```
Layer 4 — Specs          tests/e2e/**/*.spec.js
                         ↓ use
Layer 3 — Fixtures       tests/fixtures/index.js      (Playwright fixture extensions)
                         ↓ use
Layer 2 — Helpers        tests/helpers/seed.js        (seed API wrapper)
                         tests/helpers/mailsac.js     (email polling + assertDelivered)
                         tests/helpers/markers.js     (e2e_markers parsing)
                         ↓ use
Layer 1 — Config         playwright.config.js         (reads php-config → sets process.env)
                         global-setup.js              (also reads php-config directly — safety net)
```

All four layers run identically in all three Stack A contexts:
- **Terminal** (`npm run test:e2e:test`): php-config.js reads config.test.php
- **Deploy pipeline** (`deploy.js` → `test:e2e:test`): same as terminal
- **GitHub Actions (nightly)**: playwright.config.js falls back to GH Secrets when php-config.js is absent

### Layer 1 — Config

`playwright.config.js` reads `php-config.js` and sets `process.env.*` before any spec or fixture runs. `global-setup.js` also reads `php-config.js` directly as a safety net, then sets the same env vars. Both fall back to `process.env` for GitHub Actions where PHP config files are absent.

No spec or helper ever reads `php-config.js` directly — they only read `process.env.*`.

### Layer 2 — Helpers

**`tests/helpers/seed.js`**

Centralises seed calls as a pure Node `fetch` wrapper — no browser page needed for API calls.

```js
// Every seed call — 1 line:
seedData = await seed.bettingRace();

// API shape:
seed.bettingRace()     → { ok, raceId, email, password, drivers }
seed.authUser()        → { ok, email, password }
seed.scoreRace()       → { ok, raceId, driverIds, expectedPoints }
seed.notifyOpen()      → { ok, raceId, emails, bettingWindowHours }
seed.notifyClose()     → { ok, raceId, emails }
seed.registerInvite()  → { ok, email, token }
seed.e2eUser(params)   → { ok, email, password }
// + seed.cleanup.* matching every seed above
```

**Error contract:** All functions throw if the HTTP response is non-200 or `body.ok !== true`. Callers never check `ok` — a failed seed throws immediately, failing `beforeAll` with a clear message. Cleanup functions also throw on non-200 so broken teardowns are never silently swallowed.

**`tests/helpers/mailsac.js`**

```js
// Assert real email delivery in one call:
assertDelivered(inbox, apiKey, { count=1, timeout=20000, fromDomain='formula-1.dk' })
// Returns msgs array, or skips silently if apiKey not set (CI without Mailsac)

// For non-owned inboxes (can't purge) — baseline approach:
waitForNewMessages(inbox, baselineIds, count, apiKey, { timeout })
// Take a baseline snapshot before triggering the action, poll for new IDs only
```

**`tests/helpers/markers.js`**

```js
parseMarkers(text)              → { 'invite-sent': 'true', 'invite-to': 'email@...', ... }
expectMarker(text, key, value)  → throws with clear message if missing or wrong
```

### Layer 3 — Fixtures

`tests/fixtures/index.js` extends Playwright's `test` with an `adminPage` fixture that applies admin `storageState` automatically. Admin specs import from `../../fixtures` instead of declaring `storageState` themselves.

**What is NOT a fixture:** seed data, user sessions created mid-test. Those stay local to the spec using `beforeAll`/`afterAll`.

**Unauthenticated context override:** Tests inside an admin spec that need a fresh session use `test.use({ storageState: { cookies: [], origins: [] } })` inside a nested `test.describe`. Playwright resolves `storageState` at the innermost scope — the admin fixture does not interfere.

---

## Mailsac Inboxes

**Owned inboxes** (Mailsac Indie Plan — supports purge before suite run):

| Inbox | Spec | Purged by |
|---|---|---|
| `f1betting-preview@mailsac.com` | `test:email:preview` — 16 email types | `global-setup.js` |
| `e2e_testing_invite_f1@mailsac.com` | `admin/11-invites.spec.js` — invite email | `global-setup.js` |
| `e2e_bet_delete_f1@mailsac.com` | `admin/12-users.spec.js` — bet-deleted email | `global-setup.js` |
| `e2e_testing_testuser_f1@mailsac.com` | `admin/12-users.spec.js` — admin password reset | `global-setup.js` |
| `e2e_auth_f1@mailsac.com` | `02-auth.spec.js` — forgot-password reset email | `global-setup.js` |

5 owned inboxes total — this fills the Indie Plan limit. Any future owned inbox requires a plan upgrade; design new tests to use `waitForNewMessages` (baseline approach) on non-owned inboxes instead.

**Non-owned inboxes** (public; use `waitForNewMessages` with a pre-run baseline snapshot):

| Inbox | Spec | Strategy |
|---|---|---|
| `e2e_notify_open_in_f1@mailsac.com` | `07-cron.spec.js` real-run — betting open | Baseline before cron run |
| `e2e_notify_close_a_f1@mailsac.com` | `07-cron.spec.js` real-run — betting close | Baseline before cron run |

---

## Adding a New Test

### Which stack?

- Writing a Playwright spec (`*.spec.js`)? → **Stack A.** Use `helpers/seed.js`, `helpers/mailsac.js`, `helpers/markers.js`, `fixtures/index.js`.
- Writing a standalone Node script (`node tests/mything.js`)? → **Stack B.** Read `php-config.js` directly. Do not import Stack A helpers.

### New user-facing feature (Stack A)

1. Add seed/cleanup action to `test-seed.php` if data is needed
2. Add typed wrapper to `helpers/seed.js`
3. Add a numbered spec (e.g. `08-newfeature.spec.js`) — picked up automatically by `testMatch` glob
4. If the feature sends email: add a Mailsac inbox to `global-setup.js` purge list; assert with `assertDelivered()` in the feature's spec

### New admin feature (Stack A)

1. Same `seed.js` pattern
2. Add `admin/14-newfeature.spec.js`
3. Import `{ test, expect }` from `../../fixtures` — admin auth applied automatically

### New email type (Stack A)

1. Assert markers with `expectMarker()` + `assertDelivered()` in the spec that triggers the action — not in a separate email spec
2. For visual review (Stack B): update `send_email_preview` in `test-seed.php`, re-run `npm run test:email:preview`

### New security check (Stack B)

1. Add to `tests/security/security.js` in the appropriate existing section (A–L)
2. No new file needed

### New standalone script (Stack B)

1. Read credentials via `php-config.js` directly (see `smoke.js` as the pattern)
2. Fall back to `process.env` for GitHub Actions
3. Do not import `helpers/seed.js` or `fixtures/index.js`

---

## CWE Top 25 Coverage

Maps the 2024 CWE Top 25 against current test coverage in `tests/security/security.js`.

| CWE | Name | Status | Test approach |
|---|---|---|---|
| CWE-79 | Reflected XSS | ✅ covered | Inject `<script>` payload in URL params and login form; assert not reflected unescaped |
| CWE-89 | SQL Injection | ✅ covered | Classic SQLi payloads in login form; assert no DB error in response |
| CWE-352 | CSRF | ✅ covered (Section E) | Assert hidden token field present in login and POST forms |
| CWE-22 | Path Traversal | ✅ covered | `../` sequences in common param names; assert `/etc/passwd` not in response |
| CWE-287 | Improper Authentication | ✅ covered | Empty credentials rejected; login required for protected pages |
| CWE-862 | Missing Authorization | ✅ covered (Section D) | Unauthenticated access to `/admin.php` returns 403/redirect |
| CWE-306 | Missing Auth for Critical Function | ✅ covered (Section D) | Admin endpoints and sensitive files blocked without session |
| CWE-269 | Improper Privilege Management | ✅ covered | Regular user cannot POST to admin-only actions |
| CWE-434 | Unrestricted File Upload | ✅ covered | No unprotected file-upload inputs found on checked pages |
| CWE-78 | OS Command Injection | ❌ add | Inject shell metacharacters (`;`, `\|`, `&&`, backtick) in user-facing inputs; assert shell output not in response |
| CWE-94 | Code Injection | ❌ add | Inject PHP code patterns (`<?php`, `eval(`, `base64_decode(`) in inputs; assert not executed |
| CWE-798 | Hard-coded Credentials | ❌ add | Scan response bodies and headers for API keys, passwords, SMTP credentials |
| CWE-502 | Insecure Deserialization | ❌ add | Check cookies/POST params for PHP-serialized data (`O:`, `a:`); inject malformed payload |
| CWE-918 | SSRF | ❌ add | Submit URL-like values (`http://localhost`, `http://169.254.169.254`) in URL-accepting params |
| CWE-20 | Improper Input Validation | ⚠️ partial | Add: betting form rejects non-existent driver IDs; race date field rejects invalid dates |
| CWE-787 | Out-of-bounds Write | — | Not applicable — C/C++ memory issue |
| CWE-125 | Out-of-bounds Read | — | Not applicable — C/C++ memory issue |
| CWE-416 | Use After Free | — | Not applicable — C/C++ memory issue |
| CWE-476 | NULL Pointer Dereference | — | Not applicable — C/C++ memory issue |
| CWE-119 | Improper Memory Buffer | — | Not applicable — C/C++ memory issue |
| CWE-190 | Integer Overflow | — | PHP handles integer bounds gracefully |
| CWE-77 | Command Injection (generic) | — | Covered by CWE-78 above |
| CWE-362 | Race Condition | — | Not practically testable via HTTP without a load-testing framework |
| CWE-863 | Incorrect Authorization | ⚠️ partial | Covered by Section D access control checks |
| CWE-276 | Incorrect Default Permissions | ⚠️ partial | Section D checks `public/logs/` not browsable and sensitive files blocked |

**5 checks to add to Section L of `security.js`:** CWE-78, CWE-94, CWE-798, CWE-502, CWE-918. Each follows the existing pattern — HTTP request(s), assertion on response, `pass`/`fail`/`warn` call with CWE tag and remediation text. No new files or dependencies needed.
