# Feature 1: Hosting, DNS & Config Cutover

The mechanism half of the epic — get `formula-1.helvegpovlsen.dk` actually serving the test site
from FTP path `/test.formula-1.dk`, and handle the one dependency this repo already enforces at
runtime: `public/includes/passkey.php` throws a `RuntimeException` on every authenticated request
if `PASSKEY_RPID` doesn't match the host derived from `SITE_URL`. Two config files change together
(`config.test.php` and `build-deploy/.env`), and — new in this revision — the old `/hpovlsen.dk`
FTP directory is deleted once the new site is proven, per Djarnis's explicit ask.

**Revision note:** the original draft targeted `formula-1.hpovlsen.dk`. Corrected to
`formula-1.helvegpovlsen.dk` — Simply.com only allows creating subdomains under `helvegpovlsen.dk`,
not `hpovlsen.dk` (a hosting-panel constraint, discovered after the first draft). This also revealed
the FTP document root the new subdomain will serve from (`/test.formula-1.dk`, per
`build-deploy/.env`'s `FTP_ROOT_TEST`) is a **different setting from `SITE_URL`/`PASSKEY_RPID`**,
and deliberately doesn't match the new hostname string — that's a new requirement below (REQ-801a),
not something the first draft covered at all.

## Requirements

### Functional Requirements

- [REQ-801] Provision `formula-1.helvegpovlsen.dk` on Simply.com (manual, per the existing
  `project_hosting.md` convention that DNS/hosting for this project lives in Simply.com's control
  panel, not in this repo) — a DNS record for the subdomain, with its document root set to
  `/test.formula-1.dk` on the FTP server (`linux350.unoeuro.com`, same host live already uses).
  This folder does not need to pre-exist with content — `deploy.js`'s FTP `ensureDir` calls create
  `/test.formula-1.dk/public` (and `/config.php`, `/config.shared.php`, `/bin/state` as applicable)
  on first upload — but confirm in the Simply.com panel whether it expects the folder to exist
  before a subdomain can be pointed at it, or whether pointing-then-uploading works either order.
- [REQ-801a] Update `build-deploy/.env`'s `FTP_ROOT_TEST` from `/hpovlsen.dk` to
  `/test.formula-1.dk`. This is a separate setting from `SITE_URL`/`PASSKEY_RPID` (REQ-804) — it
  lives in `build-deploy/.env` (gitignored, local-only, never a GitHub secret since no CI workflow
  runs FTP deploys), and it's the one setting in this feature that isn't part of the
  `build-deploy/php-config.js` single-source-of-truth chain (`deploy.js` reads it directly via
  `process.env.FTP_ROOT_TEST`, per `deploy.js:81`). **Both this and REQ-804 must change before the
  next `npm run deploy:test` run** — change one without the other and either the new config gets
  uploaded to the old folder (harmless but pointless — nothing serves that folder under the new
  domain) or the old config keeps getting uploaded to the new folder (the new subdomain would come
  up still pointed at the old domain, tripping the `passkey.php` RP-ID guard the moment anyone hits
  an authenticated page). `FTP_ROOT_LIVE` is untouched.
- [REQ-802] Issue/confirm a TLS certificate covering **`www.formula-1.helvegpovlsen.dk`**
  specifically, not just the bare `formula-1.helvegpovlsen.dk` (refined during
  `/test-strategy-manager` review — `public/.htaccess:10-13`'s www-forcing `RewriteRule` is
  host-agnostic (`RewriteCond %{HTTP_HOST} !^www\.` / `RewriteRule ^(.*)$ https://www.%1/$1`), so it
  applies to *any* host including this subdomain — the site will almost certainly end up served
  from the `www.`-prefixed, fifth-level hostname, exactly as `www.hpovlsen.dk` and
  `www.formula-1.dk` are today). A cert covering only the bare subdomain would pass a casual check
  and still fail the actual traffic pattern. Confirm via
  `openssl s_client -connect www.formula-1.helvegpovlsen.dk:443
  -servername www.formula-1.helvegpovlsen.dk` that the returned cert's SAN list includes that exact
  host before moving to REQ-803.
- [REQ-802a] Verify DNS resolution before doing anything TLS/config-related (refined during
  `/test-strategy-manager` review — cheap to check, and every later step is meaningless if this
  hasn't propagated): `dig +short formula-1.helvegpovlsen.dk` and
  `dig +short www.formula-1.helvegpovlsen.dk` both resolve to the expected host/IP from at least one
  external resolver, not just from Djarnis's own machine (local resolver caching can hide a record
  that hasn't actually propagated).
- [REQ-803] Before picking the exact `SITE_URL` value, verify www vs. non-www behavior on the new
  subdomain with a real POST request (e.g. `curl -X POST`) to both
  `https://formula-1.helvegpovlsen.dk` and `https://www.formula-1.helvegpovlsen.dk`.
  `docs/gotchas.md`'s existing "Apache redirects non-www → www with 301, drops POST bodies" gotcha
  was discovered against the bare `hpovlsen.dk` domain. **Update (test-strategy-manager review):**
  given REQ-802's finding that the redirect rule is generic rather than domain-specific, expect this
  to reproduce identically — treat the POST probe as confirmation of the already-likely outcome
  (`SITE_URL = https://www.formula-1.helvegpovlsen.dk`), not a genuinely open question, but still run
  it before committing the config change rather than skipping straight to REQ-804 on the strength of
  reading the rule alone.
- [REQ-804] Update `config.test.php`: `SITE_URL` → the form confirmed safe under REQ-803;
  `PASSKEY_RPID` → the bare host with any `www.` prefix stripped (matching the existing
  `preg_replace('/^www\./i', '', SITE_DOMAIN)` logic the passkey code already applies at
  `public/includes/passkey.php:27`). These two values must change in the same commit/deploy — a
  partial edit leaves `PASSKEY_RPID` mismatched against `SITE_DOMAIN`, and
  `public/includes/passkey.php:28-29` throws on every page that touches passkey auth, not just
  passkey login flows.
- [REQ-805] Deploy via `npm run deploy:test` **after both REQ-801a and REQ-804 are in place**
  (uploads `config.shared.php` + the edited `config.test.php` as the server's `config.php`, per
  `docs/deployment.md`, now to `/test.formula-1.dk` instead of `/hpovlsen.dk`) — no separate
  hand-edit of the server's `config.php` is needed or wanted; that would just get overwritten by the
  next deploy anyway (same drift class the `nogler-rotation-deploy-drift` memory already documents
  for rotated secrets). Because `FTP_ROOT_TEST` changes in the same step, this deploy is the single
  atomic action that cuts over both the upload destination and the served config together — the old
  `/hpovlsen.dk` folder is not touched by this or any later `deploy:test` run, and keeps serving the
  old domain completely undisturbed as a fallback until REQ-809's deliberate cleanup.
- [REQ-806] Passkey credentials registered on the old test domain become unusable the moment
  `PASSKEY_RPID` changes — this is documented, expected behavior, not something this epic
  discovers fresh: `docs/gotchas.md` **gotcha #20** ("Passkeys are bound to `PASSKEY_RPID` — a
  one-way door per environment") already states changing the constant "orphans every passkey
  silently" and that test/live credentials "are not interchangeable." Gotcha #20 also already notes
  `sync:live` **already** clears `user_passkeys` on every run (verified fail-loud by `sync.js`) — so
  testers already experience "my passkey stopped working on test" as routine behavior today,
  independent of this migration; this isn't a new failure mode this epic introduces, just one more
  trigger for an already-known-and-handled one. **Scope, per `/test-strategy-manager` review:** this
  only matters for a real, human-registered credential (e.g. one Djarnis registered on his own
  device/authenticator against the old domain) — it is *not* something the e2e suite exercises.
  `tests/e2e/auth/{35-passkey,36-passkey-negative}.spec.js` attach a Playwright virtual authenticator
  per test ("credentials live and die with this page", per the spec's own comment) and derive their
  RP ID from whatever `BASE_URL`/origin is active at test time — they need no fixture changes and
  will pass unmodified against the new domain, covering the "new credential succeeds" half
  automatically. The "old credential now fails" half is a one-time **manual** check only — if
  Djarnis has a real passkey registered against `hpovlsen.dk`, confirm it's rejected post-cutover;
  there is nothing to automate here since no fixture persists an old credential across runs.
  TOTP-based MFA (`MFA_KEY`) and recovery codes are unaffected — neither is domain-bound.
- [REQ-807] Confirm SMTP test interception is unaffected: `SMTP_INTERCEPT`, `EMAIL_INTERCEPT_FILE`,
  and the `e2e_*@hpovlsen.dk` e2e-fixture addresses (`docs/testing.md` "Test Email Addresses",
  `docs/gotchas.md` gotcha #15) are keyed off `APP_ENV` and a local temp-file path, not `SITE_URL` —
  this is a verification step, not a code change. These fixture emails stay on `@hpovlsen.dk`
  regardless of this migration (see Feature 2, REQ-900) — the *website's* hostname moving to
  `formula-1.helvegpovlsen.dk` has no bearing on where fixture/synced-user email lives.
- [REQ-807a] No change needed to the Content-Security-Policy header
  (`public/includes/header.php:10-21`) — confirmed during `/test-strategy-manager` review by
  reading it: every directive is `'self'`/`'nonce-...'`/relative, with no hardcoded domain string
  to update. Listed here so this isn't silently re-investigated later; nothing to do.
- [REQ-808] `hpovlsen.dk`'s post-cutover disposition is decided, not left open: the domain itself is
  **not** decommissioned (it remains Djarnis's real email domain for `sync-from-live.php`/e2e
  fixtures, gotcha #15) — only its role as the Paddock Picks test site's file host ends. Record this
  explicitly in Feature 2's convention doc (REQ-906/REQ-907) so "what happened to hpovlsen.dk" has
  one written answer instead of needing to be re-derived from this epic's history later.
- [REQ-809] **Clean up `/hpovlsen.dk` on the FTP server** — added per Djarnis's explicit request.
  Delete the old FTP directory (server `linux350.unoeuro.com`, path `/hpovlsen.dk`) only after all
  of this feature's Test Scenarios pass against `formula-1.helvegpovlsen.dk` and Djarnis has
  explicitly signed off — this is a destructive, essentially unrecoverable action (there is no
  automated backup for the *test* FTP tree; `build-deploy/backup.js`/`rollback.js` only ever target
  `FTP_ROOT_LIVE`, per `backup.js:68-69` and `rollback.js:30`) and there is no scripted "delete
  remote directory" tool in `build-deploy/` today — `deploy.js` only ever uploads/`ensureDir`s, never
  deletes. Concretely:
  1. Before deleting, list `/hpovlsen.dk`'s full contents and confirm every item is one this repo's
     tooling put there — per `deploy.js`, that's `public/`, `config.php`, `config.shared.php`, and
     conditionally `bin/state/`. If anything else is present, stop and ask Djarnis before deleting —
     it may be something unrelated to this repo that happens to live in the same folder.
  2. Deleting `/hpovlsen.dk` removes that path's deployed copy of `public/f1-intelligence/` — the
     PHP client for the live-serving RAG system `CLAUDE.md` flags as requiring explicit approval
     before any modification. Get that explicit nod specifically for this deletion, not just for the
     doc edits in Feature 2 REQ-905 — deleting a deployed instance is a bigger action than editing a
     reference doc about it.
  3. Deleting FTP files has **no effect on the test database** — it's a filesystem-only action;
     `sync:live`/`test-seed.php`/scoring data are entirely unaffected either way.
  4. Execute via an FTP client (or Simply.com's File Manager) — a one-off deletion, run by Djarnis or
     by an assistant only after Djarnis has explicitly confirmed readiness in that session; this is
     not something to fold into any automated script or run unattended.

### Non-Functional Requirements

- [NFR-801] Zero changes to `config.live.php`, `formula-1.dk` DNS, `FTP_ROOT_LIVE`, or any
  `*_LIVE`-suffixed (or unsuffixed live) GitHub Actions secret/variable.
- [NFR-802] Strict cutover order: DNS + hosting + TLS must be confirmed live (REQ-801/801a/802)
  *before* `config.test.php` and `build-deploy/.env` are edited together (REQ-804/801a), which must
  be deployed and smoke-tested *before* the GitHub Actions `BASE_URL_TEST` variable changes (Feature
  2, REQ-901). `/hpovlsen.dk`'s cleanup (REQ-809) comes last of all, strictly after this feature's
  own Test Scenarios pass — never in parallel with, or before, cutover validation.
- [NFR-803] The cutover must be revertible within one `deploy:test` cycle right up until REQ-809
  runs — if REQ-803's probe or post-cutover smoke tests reveal a problem, reverting
  `config.test.php` + `build-deploy/.env` and redeploying restores the `hpovlsen.dk`/`/hpovlsen.dk`
  behavior with no lingering state (no code branches on the domain/path beyond
  `SITE_URL`/`PASSKEY_RPID`/`SITE_DOMAIN`/`FTP_ROOT_TEST`). **A revert must also flip the
  `BASE_URL_TEST` GitHub Actions variable back** (Feature 2, REQ-901) — reverting only the
  code/config side while CI still points at the new domain reproduces the exact "points at a domain
  not actually serving the current config" failure NFR-802 exists to prevent, just in the opposite
  direction. Once REQ-809 has actually run, this revertibility window is closed — another reason
  REQ-809 must be strictly last.
- [NFR-804] A genuinely quiet cutover window doesn't exist on this repo's CI schedule — added
  during `/test-strategy-manager` review after checking `.github/workflows/*.yml`: `cron-session-gc`
  and `cron-notifications` both fire *hourly* against test, so some `trigger-test` job will likely
  overlap any cutover regardless of timing. This is an accepted, low-severity risk, not something
  to engineer around: both workflows already describe themselves as tolerant of exactly this kind
  of transient miss (`notifications.php is itself time-window-gated`; the parity-with-live tradeoff
  comments already accept occasional test-side noise). One skipped or failed hourly run during the
  exact cutover minute is acceptable and needs no retry logic; only a *sustained* post-cutover
  failure (i.e. the new `BASE_URL_TEST` value doesn't work at all) is a real problem.

## User Story

**As** Djarnis, running the only environment that exercises Paddock Picks before it reaches friends
on formula-1.dk
**I want** the test site on its own project-named subdomain instead of my bare personal domain, and
the old test files actually cleaned up afterward
**So that** it's unambiguous which project's test environment I'm looking at, `helvegpovlsen.dk`
becomes the shared test-hosting base future projects reuse, and I'm not left with an abandoned,
never-cleaned-up FTP directory once the migration is "done"

## Functionality

### User Flow

1. Djarnis creates the `formula-1.helvegpovlsen.dk` DNS record (document root
   `/test.formula-1.dk`) + TLS cert in the Simply.com control panel (REQ-801/802).
2. A DNS-propagation check and a POST probe against both www/non-www forms settle the exact
   `SITE_URL` value (REQ-802a/803).
3. `build-deploy/.env`'s `FTP_ROOT_TEST` and `config.test.php`'s `SITE_URL`/`PASSKEY_RPID` are
   edited together and deployed via `npm run deploy:test` in one action (REQ-801a/804/805) — this
   both cuts over the upload destination and the served config; `/hpovlsen.dk` is left untouched and
   keeps working as a fallback.
4. Smoke tests + the full e2e suite run against the new domain to confirm parity with the old one.
5. One passkey credential is deliberately re-registered to confirm REQ-806's expected-breakage
   story holds, rather than assumed from reading the code.
6. Only once all of the above is green and Djarnis has explicitly signed off, `/hpovlsen.dk` is
   deleted from the FTP server (REQ-809) — after a contents check and the f1-intelligence approval
   gate.

### Technical Implementation

- `config.test.php`: `SITE_URL`, `PASSKEY_RPID` (the only two lines this feature touches in that
  file — `DB_*`, `SMTP_*`, and every secret stay exactly as they are).
- `build-deploy/.env`: `FTP_ROOT_TEST` (the only line this feature touches in that file —
  `FTP_HOST`/`FTP_USER`/`FTP_PASS`/`FTP_ROOT_LIVE`/everything else stays as-is).
- `public/includes/passkey.php:20-31` — no code change; this is the existing guard this feature
  must satisfy, not extend.
- `build-deploy/php-config.js` — no code change; it already exposes `siteUrl` generically from
  whichever `config.{env}.php` it's pointed at, which is why every Node-side tool
  (`build-deploy/deploy.js`, `build-deploy/sync.js`, `build-deploy/backup.js`,
  `tests/playwright.config.js`, `tests/global-setup.js`, `tests/global-teardown.js`,
  `tests/email-preview.js`, `build-deploy/schema-check.js`) picks up the new domain automatically
  once `config.test.php` changes. `FTP_ROOT_TEST` is the one exception to this chain — `deploy.js`
  reads it directly from `process.env`, not through `php-config.js`.

## Test Scenarios

```gherkin
Feature: Hosting, DNS & config cutover

  Scenario: New subdomain serves the test site over HTTPS from the new FTP path
    Given DNS and a TLS cert are provisioned for formula-1.helvegpovlsen.dk, document root
      /test.formula-1.dk
    When a request is made to the confirmed SITE_URL
    Then it returns the Paddock Picks test site with a valid certificate, not a DNS or TLS error

  Scenario: POST bodies survive on the chosen host form
    Given the www vs non-www probe from REQ-803 has been run
    When a POST request is sent to the winning form of the URL
    Then the request body is not dropped by an intermediate redirect

  Scenario: FTP_ROOT_TEST and SITE_URL/PASSKEY_RPID are updated together, in one deploy
    Given build-deploy/.env and config.test.php are both edited for the domain migration
    When the file is deployed via npm run deploy:test
    Then the upload lands in /test.formula-1.dk, SITE_URL and PASSKEY_RPID both reflect the new
      host, and no authenticated page load throws the passkey.php RuntimeException

  Scenario: The old FTP directory is left untouched by the cutover deploy
    Given FTP_ROOT_TEST has just been changed to /test.formula-1.dk
    When npm run deploy:test runs
    Then /hpovlsen.dk on the server is not written to, and the old domain continues serving from it
      exactly as before, unaffected by the cutover

  Scenario: Full test suite passes against the new domain
    Given config.test.php and build-deploy/.env point at formula-1.helvegpovlsen.dk/
      /test.formula-1.dk and the site is deployed
    When npm run test:e2e:test, npm run test:smoke, and npm run test:security are run
    Then all suites pass with no test-code changes

  Scenario: Old passkey credentials fail closed, new ones work
    Given a passkey was registered while SITE_URL pointed at the old domain
    When that credential is used to log in after the cutover
    Then authentication fails
    And registering a brand-new passkey on the new domain succeeds

  Scenario: The old FTP directory is deleted only after full validation and explicit sign-off
    Given every scenario above has passed
    When Djarnis explicitly confirms readiness
    Then /hpovlsen.dk's contents are checked against the expected list (public/, config.php,
      config.shared.php, bin/state/), the f1-intelligence deletion is separately approved, and only
      then is the directory deleted
```

## Test Cases

```gherkin
Feature: Hosting, DNS & config cutover — detailed cases

  Scenario: DNS has actually propagated before TLS/config work starts
    Given the formula-1.helvegpovlsen.dk DNS record was just created
    When dig +short is run against both formula-1.helvegpovlsen.dk and
      www.formula-1.helvegpovlsen.dk from an external resolver (not just the machine that created
      the record)
    Then both resolve to the expected target, before any TLS or config.test.php work begins

  Scenario: TLS certificate covers the actual serving hostname, not just the bare subdomain
    Given public/.htaccess's www-forcing rule is host-agnostic and will apply to this subdomain too
    When openssl s_client is used to inspect the certificate presented for
      www.formula-1.helvegpovlsen.dk:443
    Then www.formula-1.helvegpovlsen.dk appears in the certificate's SAN list

  Scenario: Mismatched SITE_URL/PASSKEY_RPID is a hard failure, demonstrating why REQ-804 is atomic
    Given config.test.php has SITE_URL updated to the new host but PASSKEY_RPID left as the old value
    When any page that calls the passkey RP-ID check is requested
    Then a RuntimeException is thrown ("PASSKEY_RPID (...) does not match the domain derived from
      SITE_URL (...)"), not a silent fallback

  Scenario: FTP_ROOT_TEST changed without config.test.php changed is a harmless no-op, not a fix
    Given only build-deploy/.env's FTP_ROOT_TEST is updated to /test.formula-1.dk
    When npm run deploy:test runs
    Then the old config.test.php content (old SITE_URL/PASSKEY_RPID) is uploaded into the new,
      otherwise-empty folder — the new subdomain would serve a site still configured for the old
      domain, which is not useful on its own; both settings must change together (REQ-801a + REQ-804)

  Scenario: Non-www to www redirect (if Simply.com applies one to the subdomain) preserves POST data
    Given the subdomain is configured
    When a POST request to the non-preferred form is redirected to the preferred form
    Then either no redirect occurs, or the redirect preserves the method and body (307/308), not a
      302/301 that downgrades to GET and drops the body

  Scenario: deploy:test uploads the new config to the new path and passes its own post-deploy
  smoke gate
    Given config.test.php has the new SITE_URL/PASSKEY_RPID and build-deploy/.env has the new
      FTP_ROOT_TEST
    When npm run deploy:test runs
    Then the upload lands in /test.formula-1.dk and the built-in post-deploy HTTP smoke check
      passes against the new domain

  Scenario: SMTP interception is unaffected by the domain change
    Given SMTP_INTERCEPT is enabled and an e2e run creates an @hpovlsen.dk-addressed e2e_* fixture
      account
    When a triggered email is captured
    Then it lands in EMAIL_INTERCEPT_FILE exactly as before, with no dependency on SITE_URL, and the
      fixture's email domain is unaffected by the website hostname change

  Scenario: Admin login and core betting flow work end-to-end on the new domain
    Given the migration is complete
    When an admin logs in and a test user places a podium prediction against
      formula-1.helvegpovlsen.dk
    Then both succeed with no behavior different from hpovlsen.dk pre-migration

  Scenario: Live site is an unaffected negative control
    Given config.live.php and FTP_ROOT_LIVE are untouched
    When formula-1.dk's smoke test is run before and after this feature ships
    Then the results are identical

  Scenario: Passkey e2e specs pass unmodified against the new domain
    Given 35-passkey.spec.js and 36-passkey-negative.spec.js attach a fresh virtual authenticator
      per test rather than reusing any persisted credential
    When they run against formula-1.helvegpovlsen.dk with BASE_URL/PASSKEY_RPID both updated
    Then both specs pass with zero fixture or test-code changes

  Scenario: A real, previously-registered passkey is rejected post-cutover (manual check)
    Given Djarnis has a passkey registered against the old hpovlsen.dk test site on his own device
    When he attempts to use it to log in to formula-1.helvegpovlsen.dk after the cutover
    Then authentication is rejected, and registering a new passkey on the new domain succeeds
    And this check is understood to be manual/exploratory — no automated test can exercise it, since
      no e2e fixture persists a credential across runs

  Scenario: Reverting reverts config, FTP root, and the CI variable together
    Given a problem is found post-cutover and BASE_URL_TEST has already been flipped
    When config.test.php and build-deploy/.env are reverted to their old values and redeployed
    Then BASE_URL_TEST is reverted to the old domain in the same action
    And the test site is fully functional again on hpovlsen.dk/-hpovlsen.dk with no leftover broken
      state and no CI job left pointing at a domain the current config no longer serves

  Scenario: /hpovlsen.dk's contents match the expected, tooling-managed set before deletion
    Given the directory listing of /hpovlsen.dk is taken just before REQ-809 executes
    When compared against the expected set (public/, config.php, config.shared.php, bin/state/)
    Then no unexpected file or folder is present — if one is found, deletion is paused and Djarnis
      is asked before proceeding

  Scenario: Deleting /hpovlsen.dk does not affect the test database
    Given the test database has live seeded/synced data at the time of deletion
    When /hpovlsen.dk is deleted from the FTP server
    Then every table's row count and content is unchanged — this is a filesystem-only action
```
