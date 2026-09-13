# Epic: Move the Test Site from hpovlsen.dk to formula-1.helvegpovlsen.dk

Created via `/f1betting-product-owner`, grounded in this repo's actual deployment/config
architecture (single-source-of-truth `config.test.php` read by both PHP and the Node
build-deploy/test tooling via `build-deploy/php-config.js`) rather than the skill's generic
F1-prediction-feature template. **Revised** after Djarnis clarified two things the first draft got
wrong: (1) Simply.com only allows creating subdomains under `helvegpovlsen.dk`, not `hpovlsen.dk`
— so the new test hostname is `formula-1.helvegpovlsen.dk`, not `formula-1.hpovlsen.dk`; (2) the
FTP document root the new subdomain serves from is `/test.formula-1.dk` — a folder name that does
**not** match the new hostname, mirroring how live's FTP root (`/formula-1.dk`) already doesn't
need to match anything but its own domain. Two features:

1. **Feature 1 — Hosting, DNS & Config Cutover.** The mechanism: stand up
   `formula-1.helvegpovlsen.dk` on Simply.com serving from FTP path `/test.formula-1.dk`, point
   `config.test.php` and `build-deploy/.env` at the new host/path, handle the one hard-fail
   dependency this repo already enforces at runtime (`PASSKEY_RPID` must match the host derived
   from `SITE_URL`), and clean up the old `/hpovlsen.dk` FTP directory once the new site is
   confirmed working.
2. **Feature 2 — Reference Sweep, CI Wiring & Convention Doc.** The cleanup and the actual ask
   behind "make this the future pattern": update the GitHub Actions variable and every doc/script
   that still says `hpovlsen.dk` as a *hostname* (never the many that use it as an *email domain*
   — see Feature 2, REQ-900), and write down `<project>.helvegpovlsen.dk` as the standing
   test-subdomain convention somewhere Djarnis will find it for the *next* project, not just this
   one.

---

## Origin

Today `hpovlsen.dk` (Djarnis's personal domain) doubles as the Paddock Picks test environment,
while `formula-1.dk` is live (`docs/deployment.md`'s Environments table). This repo's own
`CLAUDE.md` already flags the risk of a bare, generic-looking domain/path pattern causing
wrong-project confusion — the sibling Robinsonklubben project is a structural near-twin, and a
2026-09 incident happened because file paths alone didn't disambiguate which repo was in scope.
A project-named test subdomain is a small, permanent fix to the same class of problem, and Djarnis
wants it applied as the template every future project's test site follows, rather than a one-off
rename. The subdomain lands on `helvegpovlsen.dk` rather than `hpovlsen.dk` specifically because
Simply.com's control panel only permits creating subdomains under the former — this is a hosting
constraint discovered while scoping the epic, not a preference.

**Two separate domains stay in play throughout, and this distinction matters everywhere below:**
`hpovlsen.dk` is not being retired — it remains Djarnis's real personal email domain (catch-all
forwarding), which `sync-from-live.php` and 30+ `test-seed.php` e2e fixtures deliberately keep using
as an **email** domain regardless of this migration (`docs/gotchas.md` gotcha #15). Only its role as
the test *website's* file host goes away.

## User Value

**For Djarnis (the only real user of the test environment):** a test URL that visibly says which
project it belongs to, instead of a bare personal domain that could be any project's test site (or
mistaken for whatever else lives on `hpovlsen.dk`/`helvegpovlsen.dk`). This directly extends the
disambiguation habit `CLAUDE.md` already asks Claude Code to apply at the repo level, into the one
place it was still missing — the URL testers and CI actually hit.

**For future projects:** `helvegpovlsen.dk` becomes reusable test-hosting infrastructure
(`<project>.helvegpovlsen.dk` per project) instead of registering — and paying for — a new bare
domain every time a new project needs a test environment.

**For players (indirect):** none directly — this only touches the test environment. The
acceptance criteria below exist specifically to guarantee `formula-1.dk` (live) sees zero change
as a side effect.

## User Experience

- `formula-1.dk` is untouched: no change to `config.live.php`, live DNS, `FTP_ROOT_LIVE`, or any
  GitHub Actions secret/variable suffixed (or not) for live.
- `hpovlsen.dk` stops hosting the Paddock Picks test site's files — the old FTP directory
  (`/hpovlsen.dk`, per `build-deploy/.env`'s current `FTP_ROOT_TEST`) is deleted once the new site
  is confirmed working (Feature 1, REQ-809). Djarnis and CI use
  `https://formula-1.helvegpovlsen.dk` going forward (exact `www.`/non-`www.` form to be confirmed
  empirically — see Feature 1, REQ-803 — this repo has a documented Apache non-www→www redirect
  gotcha for the bare domain that must not be assumed to carry over unchanged to a subdomain).
  `hpovlsen.dk` itself is **not** decommissioned as a domain — it keeps serving as the real email
  address domain for `sync-from-live.php`/e2e fixtures (gotcha #15); only its file-hosting role ends.
- The new site's public hostname (`formula-1.helvegpovlsen.dk`) and the FTP folder it's served from
  (`/test.formula-1.dk`) are deliberately different strings — unlike live, where both happen to
  match (`formula-1.dk` domain, `/formula-1.dk` FTP root). Anyone deploying by hand should not
  assume the two always line up going forward.
- Every existing local command that already reads `SITE_URL` from `config.test.php`
  (`deploy:test`, `test:e2e:test`, `test:smoke`, `schema:check`, `sync:live`, …) keeps working with
  zero code changes — the domain swap is fully driven by editing one file, because
  `build-deploy/php-config.js` is already the single source of truth `tests/playwright.config.js`,
  `tests/global-setup.js`, `build-deploy/deploy.js`, `build-deploy/sync.js`, `build-deploy/backup.js`
  and `tests/email-preview.js` all read from. `deploy:test`'s upload *destination*, separately, is
  controlled by `FTP_ROOT_TEST` in `build-deploy/.env` (Feature 1, REQ-801a) — this is the one
  setting that isn't part of that single-source-of-truth chain and must be edited on its own.
- CI workflows that hit the test site (the `trigger-test` jobs in the cron-trigger workflows, the
  E2E orchestrator) keep passing once the paired `BASE_URL_TEST` **repository Variable** (not
  Secret — `docs/github-actions.md` already documents why storing a URL as a Secret silently breaks
  `vars.*` lookups) is updated to match.
- Every WebAuthn/passkey credential registered on the old test domain stops working the moment
  `PASSKEY_RPID` changes — expected and test-only (RP ID is baked into a credential at registration
  per the WebAuthn spec, and already documented behavior per gotcha #20), not a defect.
  TOTP/recovery-code MFA is unaffected (`MFA_KEY` isn't domain-bound).
- The `<project>.helvegpovlsen.dk` pattern this epic establishes gets written down once, in a place
  that's still findable from a brand-new project's repo — not just buried in this repo's
  `CLAUDE.md`/memory, which a fresh project's Claude Code session won't have access to.

## Success Metrics

- `npm run deploy:test`, `npm run test:e2e:test`, `npm run test:smoke`, and `npm run test:security`
  all pass against `https://formula-1.helvegpovlsen.dk` with zero application code changes — only
  `config.test.php`, `build-deploy/.env`, and CI variable values differ from before.
- `formula-1.dk`'s live smoke/security gate shows no behavior change attributable to this epic (run
  it before/after as a negative control).
- A final `grep -rn "hpovlsen\.dk"` sweep across the repo turns up only (a) intentionally-preserved
  `@hpovlsen.dk` email addresses/mail-routing checks — this domain is also Djarnis's real personal
  email domain, deliberately reused by `sync-from-live.php` and 30+ `e2e_*@hpovlsen.dk` test-seed
  fixtures, and those must **not** change (see Feature 2, REQ-900) — or (b) intentionally-excluded
  historical paths (`epics/Archive/**`, completed disaster-recovery drill logs, closed
  session-handover notes). No hit should remain that denotes the *website's hostname*.
- The old `/hpovlsen.dk` FTP directory no longer exists on the server, confirmed by directory
  listing, not assumed from having run a delete command once.
- The test-subdomain naming convention is written down somewhere Djarnis confirms he'll actually
  reference when the next project needs a test environment — this epic doesn't count as "done" if
  the only artifact is the domain change itself.

## Acceptance Criteria

```gherkin
Feature: Test site migrated to formula-1.helvegpovlsen.dk

  Scenario: Test site is reachable on the new subdomain, served from the new FTP path
    Given DNS, hosting, and a TLS certificate are configured for formula-1.helvegpovlsen.dk on
      Simply.com, pointed at FTP document root /test.formula-1.dk
    And config.test.php's SITE_URL/PASSKEY_RPID and build-deploy/.env's FTP_ROOT_TEST all point at
      the new host/path
    When a browser or test suite requests the confirmed SITE_URL
    Then it receives the Paddock Picks test site, not a DNS/TLS error, a 301 that drops POST bodies,
      or the bare hpovlsen.dk content

  Scenario: Full test suite passes against the new domain
    Given the new subdomain is live and config.test.php, build-deploy/.env, and the BASE_URL_TEST
      GitHub variable are all updated to match
    When npm run deploy:test, npm run test:e2e:test, npm run test:smoke, and npm run test:security
      are run
    Then all suites pass exactly as they did against hpovlsen.dk before the migration

  Scenario: Live site is provably untouched
    Given config.live.php, FTP_ROOT_LIVE, and every *_LIVE GitHub secret/variable are unchanged by
      this epic
    When formula-1.dk's smoke/E2E gate is run before and after this migration
    Then the outcome is identical — no code path in this epic conditions on the live domain

  Scenario: Passkey credentials are handled, not silently broken
    Given a tester has a passkey registered on the old test domain
    When PASSKEY_RPID changes to the new host
    Then the old credential fails to authenticate (expected) and the tester can register a new one
      successfully on the new domain — verified once, not assumed

  Scenario: The old FTP directory is cleaned up deliberately, after the new site is proven, not
  before
    Given formula-1.helvegpovlsen.dk has passed its full validation pass (Feature 1)
    When the old /hpovlsen.dk FTP directory is deleted
    Then it happens as a deliberate, confirmed step with nothing still depending on those files —
      never as an accidental side effect of some other action, and never before validation

  Scenario: hpovlsen.dk the domain survives this epic, only its file-hosting role ends
    Given the migration and cleanup are complete
    When sync:live next runs or an e2e "real-run" notification spec fires
    Then emails still land at @hpovlsen.dk exactly as before — deleting the FTP directory did not
      touch DNS/MX for the domain itself

  Scenario: The naming pattern outlives this one migration
    Given a hypothetical next project needs its own test site
    When Djarnis looks for how test subdomains should be named
    Then a written convention — not this conversation's memory — tells them to use
      <project>.helvegpovlsen.dk
```

## Out of Scope

- Any change to `f1-intelligence/` or `public/f1-intelligence/` behavior. Per `CLAUDE.md`, that RAG
  system is live and requires explicit approval for any modification; this epic's research found no
  functional dependency on the test domain (CORS is already `Access-Control-Allow-Origin: *`,
  `F1_INTELLIGENCE_DEBUG` keys off `APP_ENV`, not the domain string) — only prose mentions in its
  reference docs need updating, and even that should get an explicit nod first (Feature 2, REQ-905).
  Note this now also covers the fact that deleting `/hpovlsen.dk` removes that domain's deployed
  `f1-intelligence/` PHP client instance (Feature 1, REQ-809) — get the same explicit nod before
  that deletion, not just before editing docs about it.
- Renaming/rotating any secret (`CRON_SECRET`, `INTEGRATION_SEED_TOKEN`, `MFA_KEY`,
  `PASSWORD_PEPPER`, …). This is a hostname/FTP-path change only; nothing here calls for touching
  cryptographic material.
- Anything about `hpovlsen.dk`'s DNS/MX/email setup. That domain keeps functioning exactly as it
  does today for `sync-from-live.php`/e2e-fixture email purposes — this epic only removes its
  file-hosting role and the files at `/hpovlsen.dk`, never its DNS records or mail routing.
- A generalized "test subdomain provisioning" tool or script. One manual Simply.com setup plus one
  written convention is the right amount of process for how rarely new projects start.
