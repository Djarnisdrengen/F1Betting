# Admin Dashboards

## Contents

- [Overview](#overview)
- [Two-tier nav](#two-tier-nav)
- [Oversigt](#oversigt)
- [Nøgler & Rotation](#nøgler--rotation)
  - [Why most secrets are record-only, not auto-rotated](#why-most-secrets-are-record-only-not-auto-rotated)
  - [Schema](#schema)
  - [The config-file writer](#the-config-file-writer)
  - [After a rotation: what still needs manual follow-up](#after-a-rotation-what-still-needs-manual-follow-up)
  - [No live environment toggle](#no-live-environment-toggle)
- [PaddockKB](#paddockkb)
- [Challenges usage](#challenges-usage)
- [E2E fixture modes](#e2e-fixture-modes)

---

## Overview

The admin area is organized into three top-level areas — **Core** (`admin.php`), **Paddock
Challenges** (`admin-challenges.php`), and **Dashboards** (`admin-dashboards.php`) — via a shared
`<nav class="admin-area-nav">` (`public/includes/admin-area-nav.php`). Core and Paddock Challenges
are unchanged from before this feature; only their nav chrome was restyled.

Dashboards has five section tabs, all served by `public/admin-dashboards.php?tab=`:

| Tab | File | Purpose |
|---|---|---|
| `oversigt` (default) | `includes/admin-dashboards/oversigt.php` | Cross-cutting overview — composes the other four tabs' own snapshot functions |
| `keys` | `includes/admin-dashboards/keys.php` + `nogler-rotation-lib.php` | Token/secret age tracking + rotation |
| `paddockkb` | `includes/admin-dashboards/paddockkb.php` + `paddockkb-lib.php` | PaddockKB ingest health |
| `challenges` | `includes/admin-dashboards/challenges.php` + `challenges-usage-lib.php` | Paddock Challenges usage analytics (read-only) |
| `actions` | `includes/admin-dashboards/actions.php` (+ `actions-ajax.php`) | GitHub Actions ops dashboard (see `docs/github-actions.md`) |

Full design/architecture history: `epics/Admin settings and dashboards/` (epic, 5 feature docs,
`plan.md`).

Of the five tabs, only **Nøgler & Rotation** writes anything — the other four are strictly
read-only aggregations, composed without any duplicate computation (each has its own
`*GetHealthSnapshot()`/`*GetUsageSnapshot()` function, required unconditionally from the router
so Oversigt can call them regardless of which tab is actually active).

## Two-tier nav

`renderAdminAreaNav(string $activeArea, ?int $challengesPromoCount = null)` renders the shared
Level-1 row. Each of the three top-level pages (`admin.php`, `admin-challenges.php`,
`admin-dashboards.php`) then renders its own Level-2 `<nav class="admin-nav">` tab row exactly as
before (Core/Paddock Challenges: unchanged markup; Dashboards: its own 5-tab row).

`admin-actions.php` is now a thin 302 redirect to `admin-dashboards.php?tab=actions`, preserving
the query string — old bookmarks and the `?ajax=run_jobs` endpoint both keep working.

## Oversigt

Pure composition — no independent computation of any figure. Calls, in order:
`nrGetHealthSnapshot($db)`, `ghGetHealthSnapshot()`, `kbGetHealthSnapshot()`,
`chGetUsageSnapshot($db)`. A tile whose backing function isn't reachable renders whatever that
function returns for the "no data yet" case rather than fataling.

`ghGetHealthSnapshot()` (and the GitHub Actions tab itself) used to be the slow part of this
page — each fetched its 9 workflows' recent runs with one sequential GitHub API call per
workflow. Both now read `public/cache/github-actions/*.json`, kept warm by a scheduled cron
(`docs/github-actions.md` → Cache Warming Workflow) so a normal page load hits no GitHub API at
all; a cache miss falls back to `ghListWorkflowRunsMulti()`, which fetches every stale workflow
concurrently in one round trip rather than 9 sequential ones. See `docs/github-actions.md`'s
Actions Dashboard section for the full mechanism — this tab and the GitHub Actions tab share it.

## Nøgler & Rotation

### Why most secrets are record-only, not auto-rotated

The original design assumed "Roter nu" could safely generate a new value and write it for every
tracked secret. Auditing what rotating each real secret in `config.example.php` would actually do
found that's true for almost none of them:

- `MFA_KEY` seals every user's TOTP secret at rest — rotating it makes existing TOTP secrets
  permanently undecryptable (2FA lockout).
- `PASSWORD_PEPPER` is mixed into every stored password hash — rotating it makes every existing
  hash fail to verify (mass lockout) unless paired with a rehash-on-login migration this feature
  doesn't implement.
- `DB_PASS` / `SMTP_PASS` / `RESEND_API_KEY` are credentials for an external system (MySQL /
  Proton Mail / the Resend fallback provider, see `public/includes/smtp.php`'s
  `sendViaResend()`) — a fresh random local value breaks the connection immediately unless
  changed there too.
- `INTEGRATION_SEED_TOKEN` / `CRON_SECRET` are each paired with a matching GitHub Actions repo
  secret — rotating only the local copy breaks CI/cron until that's updated too.

`CHALLENGE_INVITE_SECRET` (a stateless HMAC key, no persisted state, no external pairing) is
genuinely side-effect-free to regenerate. Each secret's static config (`nrSecretConfig()` in
`nogler-rotation-lib.php`) carries a `mode`:

- `'auto'` — "Roter nu" actually generates a new value and writes it: `CHALLENGE_INVITE_SECRET`,
  and — per Djarnis's explicit 2026-07-23 call after reviewing the risk breakdown above —
  `INTEGRATION_SEED_TOKEN` and `CRON_SECRET` too. Their risk (breaks CI/cron until the paired
  GitHub secret is manually updated to match) was judged an acceptable, same-day, no-user-impact
  tradeoff.
- `'record'` — the human rotates it via the real channel (MySQL, Proton, Resend's own dashboard,
  the paired GitHub secret, or a dedicated pepper/key migration) and this UI just records that it
  happened — same "Roteret — indtast dato" flow access tokens already use: `DB_PASSWORD`,
  `SMTP_PASSWORD`, `RESEND_API_KEY`, `PASSWORD_PEPPER`, `MFA_KEY`. The latter two were **not**
  extended to `'auto'` — their risk
  (immediate mass password-reset / 2FA-re-enrollment for every member) is categorically worse
  than a CI break and wasn't approved.

Age tracking, the health score, and the audit log apply identically regardless of mode — only the
auto-write button is scoped to the secrets it's actually safe (or explicitly approved) for.

The one real access token this app holds is `GITHUB_TOKEN` (used by the Actions/PaddockKB tabs).
"Anthropic"/"OpenAI" in the original design handoff don't correspond to any credential in
`config.php` — Anthropic's key is a GitHub Actions repo secret used only inside CI runners, and
there's no OpenAI key anywhere in this codebase (it belongs to the separate `f1-intelligence`
Vercel deployment — do not confuse the two, see `CLAUDE.md`).

### Schema

`database/add_admin_dashboards.sql` (folded into `schema.sql`, registered in
`migrations.json`):

- `admin_secret_state` — one row per tracked item (`item_key` unique). No `env` column: each
  environment's own database only ever holds that environment's own rows (see below).
- `admin_audit_log` — append-only; every token-record, secret-record, and secret-rotation writes
  one row here, including failed rotation attempts.

On first load with no rows yet, `nrEnsureSeeded()` seeds one row per configured item — tokens get
no guessed expiry (shown as "unknown" until an admin records one), secrets get the config file's
own mtime as a conservative assumed last-rotation point, so the health score is never computed
against undefined data.

### The config-file writer

`nrRotateSecret()` (auto-mode only): non-blocking `flock()` (prevents two concurrent requests
from both regenerating the same secret) → read → `nrReplaceConfigConst()` (pure function, targets
exactly one `define('CONST', '...');` line; 0 or 2+ matches both fail closed, never guesses which
occurrence to touch) → backup copy (`config.php.bak.<timestamp>`, hard precondition — no write is
attempted if this fails) → write to a temp file in the same directory → atomic `rename()` →
`opcache_invalidate()` if available (without this, a rotated secret can keep serving the old value
from OPcache on hosts with `opcache.validate_timestamps=0`). Every failure mode is logged to
`admin_audit_log` distinctly (backup vs. write vs. rename vs. ambiguous-target).

The writer always targets `config.php` — the runtime-loaded file every page already requires —
never `config.test.php`/`config.live.php` directly (those are only the pre-deploy source files).

### After a rotation: what still needs manual follow-up

"Rotate now" only ever writes the new value to that host's `config.php`. Two things it deliberately
does **not** do, both surfaced to the admin in a reveal-once panel on `admin-dashboards.php?tab=keys`
right after a successful rotation (`$_SESSION['flash_nr_rotated']`, set in `admin-dashboards.php`'s
`nr_rotate_secret` handler, read-and-unset once in `keys.php` — same one-time pattern as
`$_SESSION['flash_recovery_codes']` in `profile.php`):

- **Update the matching GitHub Actions secret**, for `CRON_SECRET` and `INTEGRATION_SEED_TOKEN`
  only (`nrGithubSecretName()` resolves the env-aware name — bare on live, `_TEST`-suffixed on
  test — or `null` for `CHALLENGE_INVITE_SECRET`, which no workflow reads). Until the GitHub secret
  is updated to match, every cron-trigger workflow and the nightly backup/E2E orchestrator fail
  auth against that environment. Repo → Settings → Secrets and variables → Actions.
- **Update the local deploy-source file**, `config.test.php` or `config.live.php` (whichever
  matches the environment just rotated), with the same new value. The rotation only touches the
  server's `config.php`; the **next** `npm run deploy:test` / `deploy:live` re-uploads the local
  file over it unconditionally (`deploy.js`'s `client.uploadFrom(configSrc, ...)`) and will
  silently revert the rotation back to the old value if that local file wasn't updated too. This
  applies to all three `'auto'` secrets, including `CHALLENGE_INVITE_SECRET`.

The new value itself is never persisted or logged anywhere beyond that one reveal — `nrRotateSecret()`
returns it only in its return array; `nrLogAudit()`'s detail column stays a fixed `'auto-rotated'`
string, and `admin_secret_state` only ever stores rotation metadata (`rotated_at`/`rotated_by`),
never the value.

### No live environment toggle

Verified there is no shared filesystem, database, or API channel between the test
(hpovlsen.dk) and live (formula-1.dk) hosts — separate config files, FTP-based one-way deploys
(`build-deploy/deploy.js`), one-way DB sync (`build-deploy/sync.js`). Building a cross-host write
path would be new infrastructure risk disproportionate to a hobby-scale tool. Each deployed
instance of Nøgler & Rotation manages only its own host's environment, implicitly (`APP_ENV`) — no
toggle, no cross-host mirage. A future read-only Test↔Prod drift comparison would need `sync.js`
to also carry secret-age metadata; not built.

## PaddockKB

Reuses the GitHub Actions dashboard's own API client (`ghListWorkflowRuns()`) for the `kb-update`
workflow's (`paddock-rumors.yml`) run history — no second run-history mechanism. Entry/category/
index-size KPIs read `public/paddock-rumors/knowledge-base.json` — the deployed, web-root copy
`public/paddock-rumors/query.php` also reads, not the git-repo/CI-only master at
`paddock-rumors/data/knowledge-base.json` (never deployed — only `public/` is uploaded) — directly
and live (currently under
100 docs — cheap on every page load). "Kør opdatering nu" calls `ghTriggerWorkflowDispatch()`,
which needs a `GITHUB_TOKEN` with `actions:write` (a scope bump from the read-only token the
GitHub Actions dashboard itself needs — see `config.example.php`).

The handoff's "entries added / source count per run" and "queries in the last 7 days" KPIs were
dropped during implementation — neither has a real data source (the former needs GitHub Actions
log-text parsing, out of scope; the latter would need query logging that doesn't exist and, even
if added, would only measure admin manual testing via `public/paddock-rumors/query.php`, not real
usage). See `epics/Admin settings and dashboards/feature-4-paddockkb-dashboard.md`.

## Challenges usage

Read-only SQL aggregates over existing `challenge_*` tables — no new schema.
`chGetActiveParticipantsCount()` (verified participants with ≥1 `challenge_points` row) is the
first "active participants" figure this admin area has ever shown — nothing existed to reuse.
Per-game "completion %" is real-metric-per-game rather than one forced uniform label: Duels shows
resolved rate (a genuine unresolved→resolved lifecycle), Rumor or Not/Trivia show correct-answer
rate (their answers are scored the instant they're submitted, so a literal "completion %" would be
trivially 100%). See `epics/Admin settings and dashboards/feature-5-challenges-usage-dashboard.md`.

## E2E fixture modes

Two independent fixture gates, both requiring `INTEGRATION_SEED_TOKEN` to match `e2e_token` first
(so neither is reachable on live without the matching token):

- `e2e_gh_fixture` — GitHub Actions dashboard + PaddockKB's run-history reads (existing, see
  `docs/github-actions.md`).
- `e2e_nr_fixture` — Nøgler & Rotation's config-file writer redirects to a self-seeding fixture
  file (`sys_get_temp_dir() . '/f1betting-nr-fixture-config.php'`) instead of the real
  `config.php`. **Hard-blocked whenever `APP_ENV === 'live'`, independent of token validity** —
  `nrRotationFixtureModeActive()` checks `APP_ENV` before anything else. Seeded per-const, not
  once per file: `nrConfigWritePath()` adds a `define('CONST', 'fixture-placeholder-value');`
  line for every `'auto'`-mode `configConst` in `nrSecretConfig()` that the fixture file doesn't
  already have (`CHALLENGE_INVITE_SECRET`, `CRON_SECRET`, `INTEGRATION_SEED_TOKEN` as of this
  writing) — so E2E can exercise the real rotation path for all of them, not just the one the
  fixture happened to be created for first. A future `'auto'` addition self-heals the same way,
  and a stale fixture file left over from before this addition still gets the missing lines
  appended rather than needing manual deletion.
