# Testing

The project has four test types. They run against the deployed site over HTTP — there is no local test server.

---

## Overview

| Command | Type | What it checks | Target |
|---|---|---|---|
| `npm run test:smoke` | HTTP | Key pages return 200 | test or live |
| `npm run test:e2e:test` | Playwright | Login, navigation, UI | test |
| `npm run test:e2e:live` | Playwright | Public pages + login | live |
| `npm run test:integration` | Playwright | Points, leaderboard, pool size | test only |
| `npm run test:security` | HTTP/TLS | OWASP headers, cookies, access control | test |
| `npm run test:security:live` | HTTP/TLS | Same | live |

---

## Smoke Tests

```bash
npm run test:smoke
```

Fires HTTP GET requests at a list of URLs and asserts each returns HTTP 200. Fast (seconds), no browser. The URL is read from `config.test.php` or `config.live.php` depending on `DEPLOY_ENV`.

Runs automatically at the end of every `deploy:test` and `deploy:live`.

---

## E2E Tests (Playwright)

```bash
npm run test:e2e:test    # against hpovlsen.dk
npm run test:e2e:live    # against formula-1.dk
```

Runs browser tests using Chromium. Config is in `tests/playwright.config.js`.

**Specs:**

| Spec file | What it tests |
|---|---|
| `e2e/smoke.spec.js` | Public pages load, language toggle, login, protected pages |
| `e2e/admin.spec.js` | Admin login, driver/race CRUD, settings (test only) |
| `e2e/cron.spec.js` | Cron endpoint authentication and response format (test only) |

**On test:** all three specs run.  
**On live:** `smoke.spec.js` only (admin + cron specs are excluded).

**Config is read from `config.*.php`** — no `.env` variables needed for credentials or the base URL.

Screenshots on failure are saved to `build-deploy/screenshots/`.

---

## Integration Tests

```bash
npm run test:integration
```

**Never run this against live.** It calls `tools/test-seed.php` which destroys the current test database and replaces it with 5 races of deterministic data (3 users, 10 drivers, 15 bets).

After seeding, the tests assert:
- Leaderboard order: Alice (220 pts, 1 star) → Bob (140 pts) → Charlie (65 pts)
- Correct points per user per race
- Betting pool sizes (race 2: 60 kr, race 3: 90 kr, race 4: 30 kr, race 5: 60 kr)

Config is in `tests/playwright.integration.config.js`. The seed token is read from `config.test.php` (`INTEGRATION_SEED_TOKEN`).

---

## Security Tests

```bash
npm run test:security                    # basic (test env)
npm run test:security:ratelimit          # + rate-limit test (6 rapid logins → expect 429)
npm run test:security:ssllabs            # + SSL Labs TLS grade (60–90 s)
npm run test:security:full               # all three
npm run test:security:live               # basic (live env)
npm run test:security:live:ratelimit     # + rate-limit (live)
npm run test:security:live:ssllabs       # + SSL Labs (live)
npm run test:security:live:full          # all (live)
```

**Checks include:**

- HTTPS redirect
- HSTS header
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`
- CSP header present and non-trivial
- Cookie flags: `Secure`, `HttpOnly`, `SameSite`
- Access control: `public/logs/`, `config.php`, `tools/` endpoints without token
- CSRF token required on POST
- No sensitive info in HTTP responses (PHP errors, stack traces, server headers)
- DNS: SPF, DKIM records
- CWE Top 25 checks
- Session hardening

**Rate-limit test:** sends 6 rapid failed login attempts and expects a `429` response. Only enable if the scan IP is not already blocked.

**SSL Labs:** queries the Qualys SSL Labs API for a full TLS certificate grade. Requires internet access and takes 60–90 seconds.

Reports are saved to `build-deploy/security-reports/` as `.md` and `.json`. The two most recent reports per environment are kept.

---

## Running everything

```bash
npm run test:all    # smoke + e2e (same as what deploy:live runs automatically)
```

---

## How tests find credentials

All test scripts use `build-deploy/php-config.js` to read `config.test.php` or `config.live.php` directly. You do not need credentials in `.env` or environment variables when running locally.

When running in GitHub Actions (where PHP config files are not available), tests fall back to `process.env` variables that must be set as GitHub secrets. See [GitHub Actions](github-actions.md).
