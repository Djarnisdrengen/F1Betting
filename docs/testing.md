# Testing

The project has four test types. They run against the deployed site over HTTP — there is no local test server.

---

## Overview

| Command | Type | What it checks | Target |
|---|---|---|---|
| `npm run test:smoke` | HTTP | Key pages return 200 and render expected content | test or live |
| `npm run test:unit` | Node | mailer transport logic (no network) | local |
| `npm run test:e2e:test` | Playwright | Login, navigation, UI, user flows | test |
| `npm run test:e2e:live` | Playwright | Public pages + login only | live |
| `npm run test:integration` | Playwright | Points, leaderboard, pool size | test only |
| `npm run test:security` | HTTP/TLS | OWASP headers, cookies, access control | test |
| `npm run test:security:live` | HTTP/TLS | Same | live |

---

## Smoke Tests (`tests/smoke.js`)

```bash
npm run test:smoke
```

Fires HTTP GET requests and asserts pages return 200 and contain expected content. Fast (seconds), no browser.

**Unauthenticated checks:**

| Page | Asserts |
|---|---|
| `/` | HTML renders |
| `/login.php` | Email input present; Danish label "Adgangskode" visible (default language DA) |
| `/leaderboard.php` | Page contains "leaderboard" |
| `/races.php` | HTML renders |

**Authenticated checks** (skipped if credentials unavailable):

| Page | Asserts |
|---|---|
| `/profile.php` | Danish heading "Din Betting Historik" visible |
| `/profile.php` | Danish heading "Skift Adgangskode" visible |

Runs automatically at the end of every `deploy:test` and `deploy:live`.

---

## E2E Tests (Playwright)

```bash
npm run test:e2e:test    # against test env
npm run test:e2e:live    # against live env (smoke.spec.js only)
```

Runs browser tests using Chromium. Config is in `tests/playwright.config.js`. Screenshots on failure are saved to `build-deploy/screenshots/`.

**On test:** all spec files run.
**On live:** `smoke.spec.js` only.

---

### `smoke.spec.js`

Runs on both test and live.

**Public pages**

| Test | Asserts |
|---|---|
| Pages load | `/`, `/login.php`, `/leaderboard.php`, `/races.php` all return 200 |
| Login form renders | Email and password inputs visible |
| Leaderboard has rows | At least one `tbody tr` visible |
| Races page loads | Body visible |

**Translations**

| Test | Asserts |
|---|---|
| Default language is Danish | Submit button reads "Log ind"; "Adgangskode" label visible |
| Language toggle DA ↔ EN | Toggle switches button text between "Log ind" and "Login"; state restored after test |

**Protected pages**

| Test | Asserts |
|---|---|
| Login succeeds | Admin credentials → redirects to `index.php` |
| Authenticated index | Logout link visible in desktop nav |
| Rules page accessible | `/rules.php` returns 200 |
| Bet page accessible | `/bet.php` returns 200 |
| Logout | Clicking logout → redirects to `index.php` → login link visible in desktop nav |

---

### `admin.spec.js`

Test env only. Uses the admin account. Several sub-groups use `test-seed.php` for setup/teardown.

**Race management**

| Test | Asserts |
|---|---|
| Create race | Form submission → success alert; race card appears |
| Delete race | Confirm-modal delete → URL contains `msg=deleted`; race card gone |

**Driver management**

| Test | Asserts |
|---|---|
| Create driver | Form submission → success alert; driver card appears |
| Delete driver | Confirm-modal delete → URL contains `msg=deleted`; driver card gone |

**Invite management**

| Test | Asserts |
|---|---|
| Create invite | Email submitted → invite card appears |
| Delete invite | Confirm-modal delete → invite card gone |

**Reset race result** (serial, seeded)

| Test | Asserts |
|---|---|
| Reset button visible | Admin sees reset button on most-recently-completed race |
| Reset clears data | After reset: race result labels gone, reset button gone, user points back to 0 |

**User management** (serial, seeded)

| Test | Asserts |
|---|---|
| Toggle in-competition | Button state flips between "In Competition" and "Not In Competition" |
| Toggle admin role | Badge cycles `user → admin → user` |
| Admin sets user password | New password accepted → success alert |
| Update display name | User logs in with new password, updates display name → success alert; input reflects new name |
| Delete user | Confirm-modal delete → user card gone |

---

### `cron.spec.js`

Test env only.

**Import qualifying** (serial, seeded)

| Test | Asserts |
|---|---|
| Unauthorized without token | Response body contains "Unauthorized access" |
| Test mode imports results | Response contains "[SUCCESS] Updated qualifying results" and "Total races updated: 1" |

**Notifications — access control**

| Test | Asserts |
|---|---|
| Unauthorized without token | Response body contains "Unauthorized access" |
| Authorized with cron secret | Response contains "Notification check complete" |

**Notifications — betting just opened** (serial, seeded — race 47 h 30 min away, 48 h window, pool 150 kr)

| Test | Asserts |
|---|---|
| In-competition user notified; non-competing user and pending invite get pool reminder | "Betting opened for: E2E Notify Open Race" present; competing user gets open notification; non-competing user gets pool reminder (not open notification); pending invite gets pool reminder; test mode echoes `[pool] 150` and `[cta] …`; non-competing CTA contains `leaderboard.php`; invite CTA contains `register.php?token=…` |

**Notifications — betting closing soon** (serial, seeded — race 2 h 30 min away)

| Test | Asserts |
|---|---|
| Unbetted user notified, user with existing bet skipped | "Betting closing soon for: E2E Notify Close Race" present; unbetted user's email present; betted user's email absent |

All notification scenario tests use `?test=true` so SMTP is skipped — the logic and output are identical to a live run but no emails are actually sent.

---

### `betting.spec.js`

Test env only. Serial. Seeds a dedicated race (open, 2 h from now, 48 h window) and a user with `in_competition = 1`.

| Test | Asserts |
|---|---|
| Place a bet | Select P1/P2/P3, submit → redirect to `index.php?success=bet_placed` → success alert visible |
| Attempt to bet again | Going to bet.php with existing bet → redirects to URL containing `already_bet` |
| Edit a bet | Edit link visible on index; swap P1/P3, submit → redirect to `index.php?success=bet_updated` → success alert visible |
| Duplicate driver validation | Submitting same driver in two positions → error alert visible on edit form |

---

### `profile.spec.js`

Test env only. Serial. Seeds a dedicated test user.

| Test | Asserts |
|---|---|
| Empty bet history | No-bets-yet card body visible |
| Wrong current password | Error alert visible |
| Mismatched new passwords | Error alert visible |
| Correct password change | Success alert visible |
| Login with new password | Logout link visible after logging in with the changed password |

---

### `registration.spec.js`

Test env only. Invalid-token tests are independent; valid-invite flow is serial and seeded.

**Invalid token**

| Test | Asserts |
|---|---|
| No token | Error alert visible; password input absent |
| Unknown/expired token | Error alert visible; password input absent |

**Valid invite flow** (serial, seeded)

| Test | Asserts |
|---|---|
| Form pre-fills email | Email field matches invite email; password input visible |
| Successful registration | Submit → redirect to `index.php?success=welcome` → logout link visible (auto-logged in) |
| Used token rejected | Revisiting same token URL → error alert visible; password input absent |

---

## Integration Tests (`tests/e2e/integration.spec.js`)

```bash
npm run test:integration
```

**Never run against live.** Calls `tools/test-seed.php` to replace the test database with 5 races of deterministic data (3 users, 10 drivers, 15 bets), then verifies scoring correctness.

**Scope:**

| Area | Asserts |
|---|---|
| Leaderboard order | Alice (220 pts, 1 star) → Bob (140 pts) → Charlie (65 pts) |
| Per-user points per race | Correct points awarded for each bet outcome |
| Betting pool sizes | Race 2: 60 kr, Race 3: 90 kr, Race 4: 30 kr, Race 5: 60 kr |

Config is in `tests/playwright.integration.config.js`. Seed token is read from `config.test.php`.

---

## Security Tests (`tests/security/security.js`)

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

- `public/logs/` directory not browsable (non-200 response)
- `config.php` not directly accessible
- `tools/test-seed.php` blocked without valid token (returns 403)
- Admin endpoints reject non-admin users

**Section E — CSRF**

- Login and other POST forms contain a hidden CSRF token field

**Section F — Information Disclosure**

- No PHP error messages or stack traces in HTTP responses
- No sensitive keywords (passwords, tokens) in page source

**Section H — Outdated Components**

- PHP version (from headers if exposed) is not end-of-life

**Section I — Account Enumeration**

- Login error responses are identical for unknown email vs. wrong password (no user enumeration)

**Section J — DNS Security**

- SPF record present and valid
- DKIM record present

**Section K — Application Hardening**

- Unauthenticated POST to protected endpoints is blocked
- Change-password endpoint requires correct current password (CWE-620)
- External scripts checked for `integrity` (SRI) attributes
- Session fixation: session ID rotates on login

**Section L — CWE Top 25**

| CWE | Check |
|---|---|
| CWE-89 (SQL Injection) | Login form with SQL payloads → no DB errors in response |
| CWE-79 (Reflected XSS) | Query-string injection → payload not reflected unescaped |
| CWE-22 (Path Traversal) | `../` sequences in inputs → no `/etc/passwd` content in response |
| CWE-287 (Improper Auth) | Empty credentials → login rejected |
| CWE-269 (Privilege Escalation) | Regular user cannot access admin endpoints |
| CWE-434 (File Upload) | No unprotected file upload inputs exposed |

**Rate-limit test** *(optional)*: sends 6 rapid failed login attempts and expects `429`. Only enable if the scan IP is not already blocked.

**SSL Labs** *(optional)*: queries Qualys SSL Labs API for a full TLS grade. Requires internet access, takes 60–90 s.

Reports are saved to `build-deploy/security-reports/` as `.md` and `.json`. The two most recent reports per environment are kept.

---

## Running everything

```bash
npm run test:all    # smoke + unit + e2e (same as what deploy:live runs automatically)
```

---

## How tests find credentials

All test scripts use `build-deploy/php-config.js` to read `config.test.php` or `config.live.php` directly. You do not need credentials in `.env` or environment variables when running locally.

When running in GitHub Actions (where PHP config files are not available), tests fall back to `process.env` variables that must be set as GitHub secrets. See [GitHub Actions](github-actions.md).
