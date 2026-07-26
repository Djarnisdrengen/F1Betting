# Epic: Admin Settings & Operations Dashboards

Refined 2026-07-23 against the design handoff in this folder (`README.md`, `Admin Dashboard Ideas.dc.html`,
`screenshots/`). Detailed specs: `feature-1-two-tier-nav.md` · `feature-2-dashboards-oversigt.md` ·
`feature-3-nogler-rotation.md` · `feature-4-paddockkb-dashboard.md` · `feature-5-challenges-usage-dashboard.md`

---

## User Value

Paddock Picks' admin surface has grown organically: race/driver/user management (`admin.php`), Paddock
Challenges moderation (`admin-challenges.php`), and — as of this branch — a GitHub Actions ops view
(`admin-actions.php`), each a flat sibling page with its own nav. That's fine at three pages; it stops being
fine once four more ops screens land, because there's nowhere for them to live without the top-level switcher
becoming an unreadable row of seven buttons.

This epic gives the admin (Djarnis, occasionally a co-admin) a single place to see "is anything broken right
now" across the whole operation — expired API tokens, an overdue DB password, a failed nightly ingest, a
stuck workflow — instead of having to remember to separately check GitHub's Actions tab, `config.*.php`'s
secret ages, and PaddockKB's ingest logs. It also fixes the one genuinely risky manual chore in that list:
rotating secrets by hand (SSH in, edit `config.live.php`, redeploy) with no record of who did it or when,
replaced by a button that does the same thing safely and leaves an audit trail.

- One "Dashboards" home answers "is anything broken?" in one glance instead of three separate check-ins.
- Secret/token rotation becomes a tracked, in-app action instead of an unaudited manual file edit.
- Existing Core and Paddock Challenges admin pages get consistent nav chrome as the area count grows —
  restyle only, their data and workflows don't change.
- Nothing here is player-facing; it's pure ops tooling for whoever runs the league.

## User Experience

- The admin area reorganizes into **three top-level areas** — Core, Paddock Challenges, Dashboards — each
  with its own row of section tabs underneath, replacing today's flat switcher
  (`admin.php` / `admin-challenges.php` / `admin-actions.php` as three equal siblings).
- **Core** (Races · drivers · users · invites · Bets · Security · Settings) and **Paddock Challenges**
  (Members · Rumor or Not · Trivia · Duels · Suppressions) keep exactly the behavior they have today —
  this epic only touches their nav chrome and tab-row styling to match the new design tokens.
- **Dashboards** is the new area, with five tabs:
  1. **Oversigt** (overview) — four clickable summary tiles (one per other dashboard) plus a cross-cutting
     "needs attention" strip that deep-links straight to the offending tab.
  2. **Nøgler & Rotation** — expiring access tokens (GitHub/Anthropic/OpenAI) and config secrets, a health
     score, and the **one privileged action in this epic**: "Roter nu," which generates a new secret value,
     writes it to the target environment's config, and logs who/when.
  3. **PaddockKB** — ingest pipeline health: last/next run, entries by category with freshness, recent run
     log, top queries.
  4. **Challenges** — read-only usage analytics across Duels / Rumor or Not / Trivia (participants, plays,
     completion, a visitor→member funnel).
  5. **GitHub Actions** — **already shipped** on this branch (`admin-actions.php`); this epic re-parents it
     as Dashboards' fifth tab rather than rebuilding it.
- Environment toggle (Production / Test) on Nøgler & Rotation — every figure on that tab is per-environment,
  matching how the rest of the admin area already treats test vs. live as separate contexts.
- Bilingual (da default / en), dark/light theme, matching the rest of the admin area — no new toggle system,
  reuses the site's existing cookie-persisted theme/lang/font mechanism.

## Success Metrics

This is an internal ops tool for a ~2-person admin team, not a player-facing feature — "engagement" isn't the
right frame. Success looks like:

- Secret/token ages never silently cross their rotation policy unnoticed — Nøgler & Rotation's health score
  and action queue are the thing that gets checked instead of nothing.
- Every secret rotation from this branch onward has an audit-log row (who, when, which env) — replacing zero
  records today.
- A failed nightly PaddockKB ingest or GitHub Actions workflow is visible from the Oversigt tile within one
  admin session of it happening, instead of being discovered days later by a downstream symptom (stale
  rumors, missing trivia).
- Fewer than 3 clicks from "something might be wrong" to the specific broken thing, via Oversigt's deep links.

## Acceptance Criteria

```gherkin
Feature: Admin Settings & Operations Dashboards

  Scenario: Two-tier nav replaces the flat switcher without changing existing pages' behavior
    Given an admin is on any admin page
    When they view the top-level area row
    Then they see exactly three areas — Core, Paddock Challenges, Dashboards
    And selecting Core or Paddock Challenges shows the same tabs, data and forms as today
    And no query, calculation or write path in Core or Paddock Challenges has changed

  Scenario: GitHub Actions is reachable as a Dashboards tab, not a sibling area
    Given an admin opens the Dashboards area
    When they view its section tabs
    Then "GitHub Actions" is the fifth tab, alongside Oversigt, Nøgler & Rotation, PaddockKB, Challenges
    And its content and behavior are unchanged from the already-shipped admin-actions.php

  Scenario: Oversigt surfaces a cross-cutting problem with a working deep link
    Given a GitHub Actions workflow's latest run failed
    When the admin opens Dashboards → Oversigt
    Then the "needs attention" strip lists that failure
    And clicking its link opens Dashboards → GitHub Actions with that workflow selected

  Scenario: Rotating a secret is a tracked, audited action
    Given an admin is on the Test host's Dashboards → Nøgler & Rotation page
    And a secret's age has crossed its rotation policy
    When the admin clicks "Roter nu" for that secret and confirms
    Then a new value is generated and written to the Test config
    And the secret's age resets to 0 and its badge changes to OK
    And a new row appears in Rotations-historik recording who rotated it and when

  Scenario: The other three dashboards are read-only
    Given an admin is on PaddockKB, or Challenges, or viewing token/secret listings on Nøgler & Rotation
    When they interact with any control other than "Kør opdatering nu" (PaddockKB) or "Roter nu" (Nøgler)
    Then no write occurs against the underlying data — these views only read and aggregate
```

---

## Decisions log (signed off)

**2026-07-23** (refinement against the design handoff, cross-checked against the shipped GitHub Actions
sub-epic and the current `admin.php`/`admin-challenges.php`/`admin-actions.php` source):

| # | Decision | Why |
| --- | --- | --- |
| D1 | **GitHub Actions dashboard is treated as already shipped**, not re-specified. This epic's scope for it is limited to re-parenting it under Dashboards' nav | It was independently designed, reviewed and built (`epics/github_actions_dashboard/`) on this same branch before this epic was refined. Redoing its spec would be redundant and risks drifting from what's actually running. |
| D2 | **Core and Paddock Challenges are nav/chrome-only** in this epic — no query, scoring, or workflow changes | The handoff README says this explicitly ("recreate the nav shell and styling, keep the real page content"); both areas are live in production serving real predictions/moderation, so behavior changes are out of scope by design, not by oversight. |
| D3 | **Nøgler & Rotation is the one feature with a real privileged side effect** (secret rotation writes to a live config file); the other three net-new dashboards (Oversigt, PaddockKB, Challenges) are **strictly read-only aggregates** | Mixing write-risk into a "just a dashboard" mental model is how an ops tool grows an unaudited backdoor. Calling it out here means the architecture pass treats it with materially more scrutiny (re-auth/confirm step, audit log, admin-only gate) than the read-only three. |
| D4 | **Exact routing/file structure for the Dashboards area is deferred to the `/web-architecture-review` pass**, not locked in this epic | Today each of the three existing admin pages duplicates its own `<nav class="admin-area-nav">` block (confirmed in `admin.php`, `admin-challenges.php`, `admin-actions.php`); adding a fourth/fifth copy of that duplication while also re-parenting `admin-actions.php` under a new area is a structural question (shared include? one `admin-dashboards.php` with `?tab=` like `admin-challenges.php` already does? keep `admin-actions.php` as the file behind the "GitHub Actions" tab?) that belongs in the architecture review, not the product epic. |
| D5 | **No new engagement-style success metrics** — this is a ~2-admin internal tool, not a player-facing feature | Framing this epic's success around "active users" or "session length" would be cargo-culting the Paddock Challenges epic's metrics style onto a context where it doesn't apply. Success here is operational: nothing silently expires, everything rotated is logged. |
| D6 | **Test/Prod drift detection (Nøgler & Rotation) and Query-usage/coverage-gap panel (PaddockKB) ship as documented "idea" panels, not committed v1 scope** | The handoff itself marks both with a dashed border + "Idé" chip, distinguishing them from the rest of the mock as explicitly aspirational. Feature docs carry them as an explicit "Deferred / nice-to-have" note rather than silently dropping or silently committing to them. |

**2026-07-23** (`/test-manager` review of the refined epic + `plan.md` — verdict APPROVE WITH CONDITIONS):

| # | Decision | Why |
| --- | --- | --- |
| D7 | **No live environment toggle on Nøgler & Rotation in v1** — supersedes feature-3's original REQ-301. Each deployed instance manages only its own host's environment, implicitly | `plan.md` architecture decision 4: verified there is no shared filesystem/DB/API channel between the test and live hosts in this codebase (separate config files, FTP-based one-way deploys, one-way DB sync) — a cross-host write path would be new infrastructure risk disproportionate to a hobby-scale tool, not a gap in the original design. Feature-3, its test scenarios, and this epic's own acceptance criteria were amended to match. |
| D8 | **The "Roter nu" endpoint must not be reachable in any deployed environment until confirmation, audit logging, and the double-submit guard all ship together** | Test-manager review flagged that shipping the write route ahead of its safeguards — even briefly, even on test — creates a window where a real config file could be mutated with no record and no protection against a double-fire. `plan.md`'s Phase 3 now states this as a hard gate on deploy/merge, not just a suggested order. |

**2026-07-23** (implementation-time audit of the real secrets in `config.example.php` against feature-3's REQ-308):

| # | Decision | Why |
| --- | --- | --- |
| D9 | **"Roter nu" (auto-generate + write) is opt-in per secret, not the default.** Every real secret's write-vs-record behavior was assessed individually rather than assuming REQ-308's original "Roter nu writes for everything" | Checking what rotating each one would actually do found it would be an active bug for most, not a hypothetical risk: `MFA_KEY`/`PASSWORD_PEPPER` rotation makes existing TOTP secrets/password hashes unverifiable (mass lockout); `DB_PASS`/`SMTP_PASS` are external-system credentials that break immediately if only the local copy changes; `INTEGRATION_SEED_TOKEN`/`CRON_SECRET` are each paired with a matching GitHub Actions secret and break CI/cron until that's updated too. Age tracking, the health score, and the audit log — this epic's real success metric — are unaffected regardless of mode. Also folds in REQ-305's own correction: only `GITHUB_TOKEN` is a real token this app holds — "Anthropic"/"OpenAI" in the handoff don't correspond to any credential in `config.php`. |
| D10 | **Djarnis's explicit follow-up call: `INTEGRATION_SEED_TOKEN`/`CRON_SECRET` move to `'auto'` alongside `CHALLENGE_INVITE_SECRET`; `MFA_KEY`/`PASSWORD_PEPPER` stay `'record'`; `DB_PASSWORD`/`SMTP_PASSWORD` stay `'record'`.** Final v1 split: 3 of 7 secrets auto-rotatable, 4 record-only | After the risk breakdown in D9 was presented explicitly (CI-breakage-only vs. mass user lockout), Djarnis judged the CI-breakage risk on `INTEGRATION_SEED_TOKEN`/`CRON_SECRET` acceptable (same-day fix, no user impact) but did not extend that to `MFA_KEY`/`PASSWORD_PEPPER`, whose risk is categorically worse (immediate mass password-reset/2FA-re-enrollment for every member). |
