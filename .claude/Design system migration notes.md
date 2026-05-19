# Design system migration — v1.3.0

## Handoff location
`/home/thomas-helveg-povlsen/Downloads/design_handoff_redesign_v1.3.0`

## Branch
`redesign/v1.3.0` — all implementation commits go here; merge to `main` only after all §7 ACs pass.

---

## Implementation phases

### Phase A — CSS tokens & shell
Append `hifi/style.css` to `public/assets/css/style.css`. Add 4-font `@import` and font custom properties to `:root`. Nothing should look different yet — `.hf-*` classes are unused.

**Test:** `npm run test:smoke` — must pass. No spec changes.

---

### Phase B — Header + drawer nav
Replace `<nav class="nav">` in `public/includes/header.php` with `<header class="hf-top">` + `<nav class="hf-drawer">`. Wire drawer toggle JS. Delete old `.controls`, `.mobile-nav-extras`, `.mobile-controls`, `.nav-overlay` blocks and the `toggle_palette` handler from `header.php` and `style.css`.

**Test:** `npm run test:e2e:test` — pre-sweep complete, all `.desktop-only` selectors already replaced:
- `01-smoke.spec.js` ✅
- `03-registration.spec.js` ✅
- `05-profile.spec.js` ✅
- `toggle_palette` confirmed absent from all specs ✅

---

### Phase C — Bottom bar partial
Create `public/includes/bottom_bar.php` with 4 cells: Profile / Theme / Language / Font. Include on every page **except `admin.php`** (confirmed absent from `hifi/admin.jsx`). Font toggle is a no-op stub — add `// TODO: implement font toggle — see AC-FONT-01`.

**Test:** `npm run test:e2e:test`. Lang cell uses `<a href="?toggle_lang=1">` (confirmed in handoff README §3) — existing translation test remains valid, no changes needed.

---

### Phase D — Per-page templates (8 pages)
Port each page from its JSX reference to PHP in this order: Home → Races → Race detail → Bet modal → Leaderboard → Profile → Rules → Login.

**Test:** Run `npm run test:e2e:test` after each page. Fix broken selectors before moving to the next page. When fixing, **prefer stable selectors** over CSS class swaps:
- Alerts → `[role="alert"]` or `data-testid` instead of `.alert-success` / `.alert-danger`
- Cards → `hasText` heading content instead of `.card` / `.card-header h3`
- Form fields → `input[name="x"]` and `button[type="submit"]` are already stable

---

### Phase E — Admin + Bet modal (5-step)
Wrap admin in `<div class="admin-shell">`. Implement 5-step bet picker replacing the old `<select>` fields.

**Test:** Update `04-betting.spec.js` interaction steps:
- Identify driver cells by `data-driver-id` attribute — not DOM position (order can change)
- After picking P1, assert that driver's cell is non-interactive (`aria-disabled="true"` or `pointer-events: none`)
- Assert confirm button is disabled until all 3 drivers are picked
- Rewrite duplicate-driver validation test: old `<select>` constraint → UI lock-out. Assert clicking an already-picked cell has no effect.

---

### Phase F — Backend (leaderboard rank delta + pool size DKK)
Add `leaderboard_snapshots` table (Option A recommended). Update leaderboard query to compute delta. Render pool size in DKK with Danish formatting.

**Test:** Extend `seed_score_race` to return `rankDeltaAfterB` per user. Update `13-scoring.spec.js` to assert the **value**, not just presence:
- User who moved up → `.hf-rank-delta` contains positive indicator (e.g. `↑2`)
- User who dropped → negative indicator
- First-ever result (no previous snapshot) → renders as `—`, not `NaN` or `null`

---

### Phase G — Email templates (5 transactional emails)
Reskin invite, password-reset, admin-password-reset, bet-deleted, betting-open/close notifications in `smtp.php`. `<table>` layout only, system font stack, new logo lockup, sentence-case subject lines.

**Test:** `npm run test:email:preview` for manual visual review of all 16 email types. Existing specs already assert delivery + from-address — no spec changes needed. Manually verify sentence-case on all subject lines during preview.

---

## Post-migration cleanup tasks
- [ ] **Delete obsolete language keys** — audit `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php` for keys no longer referenced anywhere in the codebase and remove them.
- [ ] **Merge `bet.php` and `edit_bet.php`** — the 5-step bet modal design assumes a single page. After the redesign ships, merge the two pages into one to eliminate the duplicated modal UI/JS.
- [ ] **Add subject-line format check to `test:email:preview`** — the preview script already logs subject lines; add a sentence-case format assertion so regressions are caught automatically rather than requiring manual review.
- [ ] **Add drawer-state cleanup to serial describes** — any `test.describe.serial` block that opens the drawer should add a `beforeEach` step to close it, so a mid-test failure doesn't leave the drawer open for subsequent tests in the same block.
