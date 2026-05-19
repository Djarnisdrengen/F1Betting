# Implementation Plan — Redesign v1.3.0

Produced by: design-handoff-implementer
Reviewed by: web-architecture-review + test-manager

Handoff: `/home/thomas-helveg-povlsen/Downloads/design_handoff_redesign_v1.3.0`
Branch: `redesign/v1.3.0`

---

## Prerequisites

- [x] Download and unzip `design_handoff_redesign_v1.3.0` — available at handoff location above
- [x] Create branch `redesign/v1.3.0`
- [x] Pre-Phase-B selector sweep complete — all `.desktop-only` selectors replaced in `01-smoke.spec.js`, `03-registration.spec.js`, `05-profile.spec.js`. `toggle_palette` confirmed absent from all specs.
- [ ] Smoke-test existing app baseline: `npm run test:smoke`

---

## Phase A — CSS tokens & shell (~2h)

**File:** `public/assets/css/style.css` (1,518 lines, zero `.hf-*` classes)

1. Append full contents of `hifi/style.css` at the bottom — `.hf-*` namespace means zero collision risk
2. Add the 4-font Google Fonts `@import` at the top (see handoff README "Typography — production stack")
3. Add font custom properties to `:root`
4. Reload app — nothing should look different. Run `npm run test:smoke` — must still pass.

**Gate:** No CSS errors in devtools. All existing pages pixel-identical.
**Test:** `npm run test:smoke` — no spec changes required.

---

## Phase B — Header + drawer (~2h)

**File:** `public/includes/header.php`

Current structure to replace:
- `<nav class="nav" id="main-nav">` block (~line 115)
- `.mobile-nav-extras` block
- Desktop `.controls` row with `toggle_theme` / `toggle_lang` buttons
- `toggle_palette` handler (lines ~55–58) — **removed in this design, not wired to bottom bar**

Replace with:
- `<header class="hf-top">` — logo + hamburger only
- `<nav class="hf-drawer">` — slide-in overlay with all nav links

**Critical:** The `toggle_theme` and `toggle_lang` handlers (lines 37–54 of `header.php`) run **before any output** — keep them exactly where they are. Do not move them. Removing only the `toggle_palette` handler.

After new markup, delete from `style.css`:
- `.controls.desktop-only` block
- `.mobile-nav-extras` block
- `.mobile-controls` block
- `.nav-overlay` block
- Second `@media (max-width: 768px)` nav block

**Gate:** AC-SHELL-01 through AC-SHELL-05.
**Test:** `npm run test:e2e:test` — pre-sweep complete, all specs ready:
- `01-smoke.spec.js` ✅ updated
- `03-registration.spec.js` ✅ updated
- `05-profile.spec.js` ✅ updated

---

## Phase C — Bottom bar partial (~1h)

**New file:** `public/includes/bottom_bar.php`

4 cells: Profile / Theme / Language / Font. All use `<a href>` query params matching existing handlers:
- Profile → `profile.php`
- Theme → `?toggle_theme=1`
- Language → `?toggle_lang=1`
- Font → `?toggle_font=1` (no-op stub — add `// TODO: implement font toggle — see AC-FONT-01`)

Add to every page **after `</main>` but before `</body>`** — **except `admin.php`** (confirmed absent from `hifi/admin.jsx`):

```php
<?php include __DIR__ . '/includes/bottom_bar.php'; ?>
```

Pages: `index.php`, `races.php`, `bet.php`, `edit_bet.php`, `leaderboard.php`, `profile.php`, `rules.php`, `login.php`

**Gate:** AC-SHELL-06 through AC-SHELL-08, AC-THEME-01/02, AC-LANG-01/02, AC-FONT-01.
**Test:** `npm run test:e2e:test`. Lang cell uses `<a href="?toggle_lang=1">` — existing translation test remains valid, no changes needed.

---

## Phase D — Per-page templates (~6–8h)

Port 8 pages in order, ticking per-page ACs before moving on:

| Page | PHP file | JSX ref | Key ACs |
|---|---|---|---|
| Home | `index.php` | `hifi/home.jsx` | AC-HOME-01–06 |
| Races | `races.php` | `hifi/races.jsx` | AC-RACE-01–03 |
| Race detail | `bet.php` | `hifi/race-detail.jsx` | AC-RACE-04–08 |
| Bet modal | `bet.php` + `edit_bet.php` | `hifi/bet-flow.jsx`, `hifi/bet-modal.jsx` | AC-RACE-04–08 |
| Leaderboard | `leaderboard.php` | `hifi/leaderboard.jsx` | AC-LB-01–04 |
| Profile | `profile.php` | `hifi/profile.jsx` | AC-PROF-01–03 |
| Rules | `rules.php` | `hifi/rules.jsx` | AC-RULES-01–02 |
| Login | `login.php` | `hifi/login.jsx` | AC-AUTH-01–03 |

Translation: use existing `t('key')` and `getLang()` — do not create new lang files. Add new strings to `public/lang/user.php`.

**Gate per page:** side-by-side with canvas artboard at XS (320px), MD (768px), LG (1024px). Both themes. Both languages.
**Test:** `npm run test:e2e:test` after each page. When fixing broken selectors, prefer stable over CSS class swaps:
- Alerts → `[role="alert"]` or `data-testid` instead of `.alert-success` / `.alert-danger`
- Cards → `hasText` heading content instead of `.card` / `.card-header h3`
- Form fields → `input[name="x"]` and `button[type="submit"]` are already stable — keep them

---

## Phase E — Admin + Bet modal (~2h)

**Files:** `admin.php` + `public/includes/admin/*.php`

- Wrap admin content in `<div class="admin-shell">`
- `<details class="admin-dropdown">` for XS/SM; tabs for MD+
- Race table: 6-col grid at LG+ per `hifi/admin.jsx`
- Bet modal: 5-step flow (empty → P1 → P2 → P3 → Confirm → Success)
  - Already-picked drivers: `opacity: 0.4; pointer-events: none`
  - XS/SM: fullscreen modal. MD+: 560px centered card

**Gate:** AC-ADMIN-01–04.
**Test:** Update `04-betting.spec.js` interaction steps:
- Identify driver cells by `data-driver-id` attribute — **not DOM position** (order can change)
- After picking P1, assert that driver's cell is non-interactive (`aria-disabled="true"` or `pointer-events: none`)
- Assert confirm button is disabled until all 3 drivers are picked
- Rewrite duplicate-driver validation test: old `<select>` constraint → UI lock-out. Assert clicking an already-picked cell has no effect.

---

## Phase F — Backend changes (~3–4h)

### 1. Leaderboard rank delta — Option A (snapshot table)

New table: `leaderboard_snapshots (id, user_id, race_id, rank, points, created_at)`

- Write snapshot immediately after `calculateRacePoints()` in `scoring.php`
- Update `leaderboard.php` query: LEFT JOIN snapshots, compute delta
- Handle first-ever race: delta = `null`, render as `—`

Migration: write as a PHP script in `database/` with both apply and rollback sections. Add `CREATE TABLE` to `database/schema.sql` as canonical record. Apply manually via CLI on test then live after merge.

### 2. Pool size in DKK

Read from `settings` table or derive from `bettingpool_size × participant_count`. Render on home stats strip: `number_format($pool, 0, ',', '.')` + ` kr`.

**Gate:** AC-BE-01–04, AC-LB-03, AC-HOME-05.
**Test:** Extend `seed_score_race` to return `rankDeltaAfterB` per user. Update `13-scoring.spec.js` to assert the **value**, not just presence:
- User who moved up → `.hf-rank-delta` contains positive indicator (e.g. `↑2`)
- User who dropped → negative indicator
- First-ever result → renders as `—`, not `NaN` or `null`

---

## Phase G — Email templates (~2–3h)

**File:** `public/includes/smtp.php` + `public/lang/email.php`

5 templates to reskin (generated inline in `smtp.php`): invite, password-reset, admin-password-reset, bet-deleted, betting-open/close notifications.

Rules:
- `<table>` layout only — no flexbox, no `<link>` to Google Fonts
- System font stack inline on every `<td>`: `-apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif`
- New 32px red logo mark + display name header (inline `<table>`)
- Footer: `Frederikssund F1 Klub · v1.3.0 · Sæson 2026`
- Sentence-case all subject lines

**Gate:** AC-EMAIL-01–06. Send live test to Gmail + Outlook on Windows + Apple Mail.
**Test:** `npm run test:email:preview` for manual visual review of all 16 email types. Existing specs already assert delivery + from-address — no spec changes needed. Manually verify sentence-case on all subject lines during preview.

---

## Architecture review findings (web-architecture-review)

### Resolved before implementation
- ✅ **Smoke spec nav selectors** — updated before Phase B (`01-smoke`, `03-registration`, `05-profile`)
- ✅ **`toggle_palette` removal** — confirmed absent from all specs; removed in Phase B
- ✅ **Bottom bar on `admin.php`** — confirmed absent from `hifi/admin.jsx`; not added
- ✅ **Lang toggle query param** — bottom bar uses `<a href="?toggle_lang=1">`; existing test valid

### Decisions deferred to post-merge
- **Merge `bet.php` / `edit_bet.php`** — keep two pages for this release, apply the same 5-step modal UI to both. Merge in v1.4.0. See post-migration tasks.
- **Phase F migration runner** — no formal runner; apply manually via CLI. Document steps in PR description.

### Open before Phase D
- **`toggle_font` bottom bar** — stub as no-op per AC-FONT-01. Leave `// TODO` comment.

---

## Post-migration tasks (after merge to main)

- [ ] **Delete obsolete language keys** — audit `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php` for keys no longer referenced anywhere in the codebase and remove them.
- [ ] **Merge `bet.php` and `edit_bet.php`** — the 5-step modal design assumes a single page. Merge into one to eliminate duplicated modal UI/JS.
- [ ] **Add subject-line format check to `test:email:preview`** — the preview script already logs subject lines; add a sentence-case assertion so regressions are caught automatically rather than requiring manual review.
- [ ] **Add drawer-state cleanup to serial describes** — any `test.describe.serial` block that opens the drawer should add a `beforeEach` step to close it, so a mid-test failure doesn't leave the drawer open for subsequent tests in the same block.

---

## Merge checklist (§8 of handoff guide)

- [ ] All §7.1–§7.12 acceptance criteria ticked in PR description
- [ ] 18+ screenshots attached (9 pages × XS + MD + LG, both themes)
- [ ] Bet-flow Loom recording attached
- [ ] `CHANGELOG.md` entry written with date + bullet list
- [ ] Migrations tested with apply and rollback on a fresh DB
- [ ] Email templates tested live in Gmail, Outlook on Windows, Apple Mail
- [ ] One real club member has clicked through staging on their phone and approved
- [ ] No new console errors on any page in any of the 4 target browsers
- [ ] `npm run test:e2e:test` passes (70/70)
- [ ] `npm run test:smoke` passes
