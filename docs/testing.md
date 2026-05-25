# Testing

## Contents

- [Overview](#overview)
- [Smoke Tests](#smoke-tests)
- [E2E Tests (Playwright)](#e2e-tests-playwright)
  - [Mailsac mode](#mailsac-mode-email_backendmailsac)
  - [Architecture layers](#architecture-layers)
  - [01-smoke.spec.js](#01-smokespecjs)
  - [02-auth.spec.js](#02-authspecjs)
  - [03-registration.spec.js](#03-registrationspecjs)
  - [04-betting.spec.js](#04-bettingspecjs)
  - [05-profile.spec.js](#05-profilespecjs)
  - [06-emails.spec.js](#06-emailsspecjs)
  - [07-cron.spec.js](#07-cronspecjs)
  - [08-preferences.spec.js](#08-preferencesspecjs)
  - [09-profile-preferences.spec.js](#09-profile-preferencesspecjs)
  - [admin/10-content.spec.js](#admin10-contentspecjs)
  - [admin/11-invites.spec.js](#admin11-invitesspecjs)
  - [admin/12-users.spec.js](#admin12-usersspecjs)
  - [admin/13-scoring.spec.js](#admin13-scoringspecjs)
- [Email Preview](#email-preview)
- [Security Tests](#security-tests)
- [Test Email Addresses](#test-email-addresses)
  - [Inboxes asserted in test:e2e:test:mailsac](#inboxes-asserted-in-teste2etestmailsac)
  - [All seeded inbox addresses](#all-seeded-inbox-addresses)
- [How tests find credentials](#how-tests-find-credentials)

---

All tests run against the deployed site over HTTP — there is no local test server.

---

## Overview

| Command | Stack | What it checks | Target | Duration |
|---|---|---|---|---|
| `npm run test:smoke` | B | Key pages return 200 and contain expected content | test or live | ~5s |
| `npm run test:unit` | B | Mailer transport logic (no network) | local | ~1s |
| `npm run test:e2e:test` | A | Full user journeys — login, betting, admin, scoring, email delivery | test | ~5–10 min |
| `npm run test:e2e:test:mailsac` | A | Same as above, but sends real emails via SMTP and asserts delivery in Mailsac | test | ~10–15 min |
| `npm run test:e2e:live` | A | `01-smoke.spec.js` only — read-only live health check | live | ~30s |
| `npm run test:email:preview` | B | Sends all 16 email types to Mailsac for manual visual review | test | ~2 min |
| `npm run test:security` | B | OWASP headers, cookies, access control, CWE Top 25 | test or live | ~30s |
| `npm run test:all` | B+A | smoke + unit + e2e:test | test | ~10 min |

Stack A = Playwright (browser-based, reads config via `playwright.config.js`).
Stack B = standalone Node scripts (read config directly from `php-config.js`, fall back to `process.env` on GitHub Actions).

---

## Smoke Tests

```bash
npm run test:smoke
```

Fires HTTP GET requests, asserts 200 and content. Fast, no browser, no seeds.

**Unauthenticated checks:**

| Page | Asserts |
|---|---|
| `/` | HTML renders |
| `/login.php` | Email input present; "Adgangskode" label visible (default language DA) |
| `/leaderboard.php` | Page contains "leaderboard" |
| `/races.php` | HTML renders |

**Authenticated checks** (skipped if credentials unavailable):

| Page | Asserts |
|---|---|
| `/profile.php` | Betting history heading visible (DA or EN) |
| `/profile.php` | Change-password heading visible (DA or EN) |

Runs automatically at the end of every `deploy:test` and `deploy:live`.

---

## E2E Tests (Playwright)

```bash
npm run test:e2e:test           # full suite against test env (intercept mode)
npm run test:e2e:test:mailsac   # same suite, real SMTP + Mailsac delivery assertions
npm run test:e2e:live           # 01-smoke.spec.js only, against live
```

Config: `tests/playwright.config.js`. Screenshots on failure: `build-deploy/screenshots/`.

### Mailsac mode (`EMAIL_BACKEND=mailsac`)

Sets `EMAIL_BACKEND=mailsac`, which activates real SMTP delivery during the suite:

1. `global-setup.js` purges the 4 owned Mailsac inboxes used by the suite, then calls `test-seed.php?action=smtp_live_on` to create a flag file (`/tmp/f1betting_smtp_live`) on the test server.
2. `smtp.php` detects the flag file and bypasses the normal intercept — emails are sent via real SMTP (Proton Mail → Resend fallback).
3. Tests that assert email delivery poll Mailsac via the API instead of reading the local intercept log.
4. `global-teardown.js` calls `smtp_live_off` to remove the flag file, restoring intercept mode for the next regular run.

Requires `MAILSAC_API_KEY` in `config.test.php`. Run this mode before a production deploy or after changes to email templates, SMTP configuration, or the Resend fallback path.

**On test:** all `tests/e2e/**/*.spec.js` and `tests/e2e/admin/**/*.spec.js` files matching the numbered glob are run.
**On live:** `01-smoke.spec.js` only.

### Architecture layers

```
tests/fixtures/index.js      — Playwright fixture: adminPage (applies admin storageState)
tests/helpers/seed.js        — typed wrappers for all test-seed.php actions (Node fetch, no browser)
tests/helpers/mailsac.js     — Mailsac polling: waitForMessages, waitForNewMessages, assertDelivered
tests/helpers/markers.js     — parses e2e_markers strings emitted by admin.php in test mode
```

`seed.js` is Stack A only — it reads `process.env.BASE_URL` set by `playwright.config.js`. Do not import it from standalone scripts.

---

### `01-smoke.spec.js`

Runs on both test and live. No seeds.

**Public pages**

| Test | Asserts |
|---|---|
| Pages load | `/`, `/login.php`, `/leaderboard.php`, `/races.php` all return 200 |
| Login form renders | Email and password inputs visible |
| Leaderboard has rows | At least one `tbody tr` visible with non-zero points |
| Races page loads | Body visible |
| Index page renders upcoming races section | `.races-section` element visible |

**Translations**

| Test | Asserts |
|---|---|
| Default language is Danish | Submit button reads "Log ind"; "Adgangskode" label visible |
| Language toggle DA ↔ EN | Button text switches between "Log ind" and "Login" |

**Protected pages**

| Test | Asserts |
|---|---|
| Authenticated index | Logout link visible in desktop nav |
| Rules page | `/rules.php` returns 200 |
| Bet page | `/bet.php` returns 200 |
| Profile page | Edit Profile, Change Password, and Betting History headings visible |
| Admin panel | `/admin.php?tab=races` renders at least one card |
| Logout | Clicking logout → login link visible |

---

### `02-auth.spec.js`

Test env only. Serial. Seeds a dedicated user (`seed.authUser()` / `seed.cleanup.authUser()`). Real forgot-password email sent and asserted via Mailsac.

**Login**

| Test | Asserts |
|---|---|
| Wrong password | Error alert visible |
| Correct credentials | Redirect to `index.php` |

**Forgot password**

| Test | Asserts |
|---|---|
| Form renders | Forgot-password form visible |
| Unknown email | Success message shown; no user enumeration |
| Known email | `[reset-sent] true` marker; Mailsac delivery asserted |
| Reset via token link | Navigate to reset link from marker; set new password; login succeeds |

**Password change via profile**

| Test | Asserts |
|---|---|
| Wrong current password | Error alert visible |
| Mismatched confirm | Error alert visible |
| Correct inputs | Success alert visible |

---

### `03-registration.spec.js`

Test env only.

**Invalid token**

| Test | Asserts |
|---|---|
| No token | Error alert visible; password input absent |
| Unknown/expired token | Error alert visible; password input absent |

**Valid invite flow** (serial, seeded)

| Test | Asserts |
|---|---|
| Form pre-fills email | Email matches invite; password input visible |
| Successful registration | Redirect to `index.php?success=welcome`; logout link visible |
| Used token rejected | Same token URL → error alert |

---

### `04-betting.spec.js`

Test env only. Serial. Seeds a race and in-competition user (`seed.bettingRace()`).

| Test | Asserts |
|---|---|
| Place a bet | Submit → redirect to `index.php?success=bet_placed`; success alert |
| Attempt to bet again | Redirect contains `already_bet` |
| Edit a bet | Swap P1/P3 → redirect to `index.php?success=bet_updated`; success alert |
| Duplicate driver | Same driver in two positions → validation error |

---

### `05-profile.spec.js`

Test env only. Serial. Seeds a dedicated user (`seed.e2eUser()`).

Password tests click the Security tab before filling fields (tab panel is hidden by default). Language tests use the Preferences tab toggle; language is no longer a select in the Profile form. All password-change redirects land on `?tab=tab-security` so the correct tab is active after submit.

| Test | Asserts |
|---|---|
| Empty bet history | No-bets card visible |
| Wrong current password | Security tab → error alert |
| Mismatched new passwords | Security tab → error alert |
| Correct password change | Security tab → success alert |
| Login with new password | Logout link visible |
| Language — switch to English | Preferences tab → English toggle → `html[lang]="en"` |
| Language — survives re-login | After logout/login `html[lang]="en"` |
| Language — switch back to Danish | Preferences tab → Danish toggle → `html[lang]="da"` |

---

### `06-emails.spec.js`

Test env only. Verifies the SMTP/Resend config page. No email-sending assertions (those live in the spec that triggers the action).

| Test | Asserts |
|---|---|
| Unauthenticated access denied | Body contains "Access denied" |
| Admin can access page | HTTP 200 |
| Config table shows required keys | SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_FROM_EMAIL, RESEND_API_KEY visible |
| RESEND_API_KEY is configured | Row shows masked value; "Not defined" absent |

---

### `07-cron.spec.js`

Test env only.

**Import qualifying** (serial, seeded)

| Test | Asserts |
|---|---|
| Unauthorized without token | "Unauthorized access" in body |
| Test mode imports results | "[SUCCESS] Updated qualifying results"; "Total races updated: 1" |

**Notifications — access control**

| Test | Asserts |
|---|---|
| Unauthorized without token | "Unauthorized access" in body |
| Authorized with CRON_SECRET | "Notification check complete"; no "FAILED to send" |

**Notifications — betting just opened** (serial, seeded, `?test=true`)

| Test | Asserts |
|---|---|
| In-competition user notified; others get pool reminder | "Betting opened for: E2E Notify Open Race"; competing user sent open notification; non-competing user sent pool reminder; pending invite sent pool reminder with registration link; `[pool] 150`; `[lang] en` |

**Notifications — betting closing soon** (serial, seeded, `?test=true`)

| Test | Asserts |
|---|---|
| Unbetted user notified; betted user skipped | "Betting closing soon for: E2E Notify Close Race"; unbetted user in output; betted user absent; `[lang] en` |

**Notifications — betting just opened (real send)** (serial, seeded)

Runs real cron without `?test=true`. Skips if `MAILSAC_API_KEY` absent.

| Test | Asserts |
|---|---|
| Betting-open email delivered to in-competition inbox | Cron output confirms send; Mailsac `e2e_notify_open_in_f1@mailsac.com` receives 1 message from `formula-1.dk` |

**Notifications — betting closing soon (real send)** (serial, seeded)

| Test | Asserts |
|---|---|
| Betting-close email delivered to unbetted inbox | Cron output confirms send; Mailsac `e2e_notify_close_a_f1@mailsac.com` receives 1 message from `formula-1.dk` |

---

### `08-preferences.spec.js`

Test env only. Covers the full preference lifecycle: new-visitor defaults (AC1), returning anonymous visitor via cookie (AC2–AC3), first-login profile seeding (AC4), returning-login profile override (AC5), authenticated in-session sync (AC6), logout continuity (AC7–AC9), last-write-wins on overwritten cookie (AC10), and theme icon correctness (AC11).

`beforeAll` triggers the global seed (resets Alice + Bob + Charlie to NULL prefs), then pre-sets Bob's theme to `light` for the AC5 override test.

| Test | Asserts |
|---|---|
| AC1 — new visitor defaults | Body class `dark font-system`; `f1_theme=dark` and `f1_font=system` cookies set |
| AC2 — returning anonymous visitor | Pre-set `f1_theme=light` cookie → body class `light` |
| AC3 — anonymous toggle persists | Toggle → cookie updated → survives reload |
| AC4 — first login seeds profile | Login as Alice (NULL prefs) → DB seeded with session values; body class unchanged |
| AC5 — returning login overrides | Login as Bob (DB `light`) with dark cookie → body class `light`; cookie updated |
| AC6 — authenticated toggle syncs to DB | Toggle while logged in → `get_prefs` confirms DB updated; survives re-login |
| AC7+AC8 — logout preserves cookies | After logout: `f1_theme` cookie still present; body class unchanged on next page |
| AC9 — return visit after logout | `storageState` snapshot → new context → body class matches pre-logout prefs |
| AC10 — overwritten cookie wins | Overwrite cookie in saved state → body class matches overwritten value |
| AC11 — theme icon current state | Dark → `fa-moon`; light → `fa-sun` in theme toggle |

**Test-seed action used:** `get_prefs` — returns `{theme, font_stack, language}` from the DB for a given email. Used to assert server-side state without re-logging in.

---

### `09-profile-preferences.spec.js`

Test env only. Covers the Profile Page Preferences Management feature: bottom nav hidden on profile page, Preferences tab visible and pre-populated with toggle buttons, saving theme+font+language via form updates body class/cookies/DB immediately, and full regression coverage confirming the bottom nav remains functional on all other pages.

Profile page uses a tabbed layout (Profile / Security / Preferences). Tests that interact with form fields first click the relevant tab button to reveal the hidden panel. Preference selects have been replaced with segmented toggle buttons backed by hidden inputs; tests click toggles and assert `#pref_theme` / `#pref_font` hidden input values post-redirect. Language is now saved via the Preferences tab (same form as theme+font), not the Profile tab. Saving preferences redirects to `?tab=tab-preferences`; saving on Security tab redirects to `?tab=tab-security`.

`beforeAll` (serial group) triggers the global seed to reset Alice to NULL prefs before the state-dependent tests.

| Test | Asserts |
|---|---|
| PP1 — bottom nav hidden on profile | `.hf-bottom` not attached on `/profile.php` (authenticated) |
| PP2 — preferences tab visible | Click Preferences tab → panel visible; theme and font toggle buttons visible |
| PP3 — save light+editorial | Click Preferences tab → toggle light+editorial → body class `light font-editorial`; flash visible; `#pref_theme` / `#pref_font` hidden inputs show updated values |
| PP4 — DB updated | `get_prefs(alice)` → `theme='light'`, `font_stack='editorial'` |
| PP5 — cookies updated | `f1_theme=light`, `f1_font=editorial` after save |
| PP6 — survives re-login | Fresh login as Alice → body class still `light` |
| PP7 — bottom nav on / | `.hf-bottom` visible on `/` (regression) |
| PP8 — bottom nav on races | `.hf-bottom` visible on `/races.php` (regression) |
| PP9 — theme toggle on / | `?toggle_theme=1` changes body class on non-profile page (regression) |
| PP10 — unauthenticated visitor | `.hf-bottom` visible on `/`; contains login link |
| PP-NEW-1 — special chars in display name | Click Profile tab → fill name → stored and rendered without double-encoding |
| PP-NEW-2 — PRG: no resubmit on reload | Click Profile tab → save → reload → no success flash |
| PP-NEW-3 — tampered pref_theme rejected | Click Preferences tab → tamper `#pref_theme` hidden input → body class is `dark` or `light`, not `malicious` |
| PP-NEW-4 — display name max-length | Click Profile tab → 101-char name → error alert, no success alert |
| PP-NEW-5 — language via preferences toggle | Click Preferences tab → English toggle → `html[lang]="en"`; DB `language='en'` |

**Test-seed action used:** `get_prefs` (same as `08-preferences.spec.js`).

```
GET /tools/test-seed.php?token=...&action=get_prefs&email=alice@test.local
→ {"theme":"dark","font_stack":"system","language":"da"}
```

---

### `admin/10-content.spec.js`

Test env only. Admin auth applied via fixture.

| Test | Asserts |
|---|---|
| Create and delete a race | Form → success alert; race card appears; delete → card gone |
| Create and delete a driver | Form → success alert; driver card appears; delete → card gone |

---

### `admin/11-invites.spec.js`

Test env only. Real invite email sent and asserted via Mailsac.

| Test | Asserts |
|---|---|
| Invite a user and delete | Success alert; `[invite-sent] true`; Mailsac delivery asserted; delete → invite gone |

---

### `admin/12-users.spec.js`

Test env only. Serial. Seeds a user (`seed.e2eUser(language=en)`). Real emails sent for password reset.

| Test | Asserts |
|---|---|
| Toggle in competition | Button state flips |
| Toggle admin role | Badge cycles `user → admin → user` |
| Set password | Success alert; `[admin-reset-lang] en`; `[admin-reset-sent] true`; Mailsac delivery asserted |
| Update display name | User logs in, updates name → success alert; input reflects new name |
| Delete user | Confirm-modal delete → user card gone |

---

### `admin/13-scoring.spec.js`

Test env only. Serial. Seeds two races and 3 users (`seed.scoreRace()` / `seed.cleanup.scoreRace()`).

Race A: future date, result already set by seed (no perfect bet, pool carries to Race B).
Race B: day after Race A, no result yet — test enters it via admin UI.

| Test | Asserts |
|---|---|
| Enter Race B result via admin | Select P1/P2/P3 → success alert |
| Leaderboard points after Race B | Each user's total points matches expected from seed |
| Star badge for perfect bet | Alice's leaderboard row shows star badge |
| Race B pool includes Race A carryover | `poolA + poolB` shown on Race B card |
| Race A pool unchanged | `poolA` shown on Race A card |
| Reset button scope | Race A: no reset button; Race B: reset button visible |
| Reset Race B | Confirm → Race B result gone, reset button gone; leaderboard rolled back to Race A baseline |

---

## Email Preview

```bash
npm run test:email:preview
```

Standalone Stack B script. Calls `test-seed.php?action=send_email_preview` which sends all 8 email types in DA + EN (16 total) to `MAILSAC_INBOX`. Prints a formatted summary of every email (name, to, subject, extra fields). Not pass/fail — exit 0 always. Use it for manual visual review of email templates after copy or layout changes.

Open `MAILSAC_INBOX` (`f1betting-preview@mailsac.com`) in the Mailsac web UI to inspect the rendered emails.

---

## Security Tests

```bash
npm run test:security                    # basic (test env)
npm run test:security:ratelimit          # + rate-limit test
npm run test:security:ssllabs            # + SSL Labs TLS grade
npm run test:security:full               # all three
npm run test:security:live               # basic (live env)
npm run test:security:live:ratelimit
npm run test:security:live:ssllabs
npm run test:security:live:full
```

**Section A — Transport Security**

- HTTP → HTTPS redirect enforced
- HSTS header present with adequate `max-age`

**Section B — Security Headers**

- `X-Frame-Options` present
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy` set
- `Permissions-Policy` set
- CSP header present and non-trivial
- Server header does not expose version numbers

**Section C — Cookie Flags**

- Session cookie has `Secure`, `HttpOnly`, `SameSite` flags

**Section D — Access Control**

- `public/logs/` directory not browsable
- `config.php` not directly accessible
- `tools/test-seed.php` blocked without valid token; also blocked on live regardless of token (`APP_ENV` guard)
- Admin endpoints reject non-admin users

**Section E — CSRF**

- Login and POST forms contain a hidden CSRF token field

**Section F — Information Disclosure**

- No PHP error messages or stack traces in HTTP responses
- No sensitive keywords in page source

**Section H — Outdated Components**

- PHP version (from headers if exposed) is not end-of-life

**Section I — Account Enumeration**

- Login error responses identical for unknown email vs. wrong password

**Section J — DNS Security**

- SPF record present and valid
- DKIM record present

**Section K — Application Hardening**

- Unauthenticated POST to protected endpoints blocked
- Change-password requires correct current password (CWE-620)
- External scripts checked for `integrity` (SRI) attributes
- Session ID rotates on login (session fixation prevention)

**Section L — CWE Top 25**

| CWE | Check |
|---|---|
| CWE-89 (SQL Injection) | Login form with SQL payloads → no DB errors |
| CWE-79 (Reflected XSS) | Query-string injection → payload not reflected unescaped |
| CWE-22 (Path Traversal) | `../` sequences → no `/etc/passwd` content |
| CWE-287 (Improper Auth) | Empty credentials → login rejected |
| CWE-269 (Privilege Escalation) | Regular user cannot access admin endpoints |
| CWE-434 (File Upload) | No unprotected file upload inputs exposed |

**Rate-limit test** *(optional)*: 6 rapid failed login attempts → expects `429`.
**SSL Labs** *(optional)*: Qualys SSL Labs API TLS grade. Takes 60–90s.

Reports saved to `build-deploy/security-reports/` as `.md` and `.json` (two most recent per environment).

---

## Test Email Addresses

All seeded test users use `@mailsac.com` addresses so that any email accidentally triggered on the test site never reaches real inboxes.

**Which commands send real emails to Mailsac:**

| Command | Mailsac delivery |
|---|---|
| `test:e2e:test` | None — intercept mode captures emails server-side |
| `test:e2e:test:mailsac` | Yes — real SMTP; owned inboxes purged before suite |
| `test:security` | None — HTTP scanner only, no emails triggered |

### Inboxes asserted in test:e2e:test:mailsac

The 4 owned inboxes (★) are purged by `global-setup.js` before the suite runs. The 2 non-owned inboxes use a baseline-snapshot approach (`waitForNewMessages`).

| Inbox | Triggered by | Email type | Count |
|---|---|---|---|
| ★ `e2e_auth_f1@mailsac.com` | `02-auth.spec.js` | Password reset link | 1 |
| ★ `e2e_testing_invite_f1@mailsac.com` | `admin/11-invites.spec.js` | Invite to register | 1 |
| ★ `e2e_testing_testuser_f1@mailsac.com` | `admin/12-users.spec.js` | Admin-issued password reset | 1 |
| ★ `e2e_bet_delete_f1@mailsac.com` | `admin/12-users.spec.js` | Bet deletion notification | 1 |
| `e2e_notify_open_in_f1@mailsac.com` | `07-cron.spec.js` | Betting window open | 1 |
| `e2e_notify_close_a_f1@mailsac.com` | `07-cron.spec.js` | Betting window closing soon | 1 |

`f1betting-preview@mailsac.com` (★ owned) is populated by `npm run test:email:preview` (Stack B, run separately). No E2E spec asserts delivery to it and it is not purged at suite start.

The Mailsac Indie Plan allows 5 owned inboxes — 4 are used by the suite. Any new assertion requiring a purged inbox can use the 5th (`f1betting-preview`) or a plan upgrade; new tests that tolerate non-purged inboxes should use `waitForNewMessages` (baseline-snapshot approach) on non-owned inboxes.

### All seeded inbox addresses

These addresses are assigned to seeded test users. They are `@mailsac.com` so accidental emails are visible, but the suite does not assert Mailsac delivery for them.

| Inbox | Spec | Role |
|---|---|---|
| `e2e_register_f1@mailsac.com` | `03-registration.spec.js` | Invite recipient / registering user |
| `e2e_bet_user_f1@mailsac.com` | `04-betting.spec.js` | Betting user |
| `e2e_score_alice_f1@mailsac.com` | `admin/13-scoring.spec.js` | Alice (perfect-bet user) |
| `e2e_score_bob_f1@mailsac.com` | `admin/13-scoring.spec.js` | Bob |
| `e2e_score_charlie_f1@mailsac.com` | `admin/13-scoring.spec.js` | Charlie |
| `e2e_notify_open_out_f1@mailsac.com` | `07-cron.spec.js` | Non-competing user (pool reminder) |
| `e2e_notify_open_invite_f1@mailsac.com` | `07-cron.spec.js` | Pending invite (pool reminder) |
| `e2e_notify_close_b_f1@mailsac.com` | `07-cron.spec.js` | Already-bet user (notification skipped) |

Users synced from live via `sync:live` have their email addresses rewritten to `@mailsac.com`. The admin account (`F1_ADMIN_EMAIL`) is restored unchanged.

---

## How tests find credentials

All test scripts use `build-deploy/php-config.js` to read `config.test.php` or `config.live.php` directly. No `.env` file or environment variables needed when running locally.

On GitHub Actions (no PHP config files), tests fall back to `process.env` variables set as GitHub Secrets. See [GitHub Actions](github-actions.md).
