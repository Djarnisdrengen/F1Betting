# Design system migration — v1.3.0

## Handoff location
`/home/thomas-helveg-povlsen/Downloads/design_handoff_redesign_v1.3.0`

## Branch
`redesign/v1.3.0` — all implementation commits go here; merge to `main` only after all §7 ACs pass.

## Implementation phases
A — CSS tokens & shell
B — Header + drawer nav
C — Bottom bar partial
D — Per-page templates (8 pages)
E — Admin + Bet modal
F — Backend changes (leaderboard rank delta, pool size DKK)
G — Email templates (5 transactional emails)

## Test notes per phase

### Before Phase B (DONE)
- `01-smoke.spec.js`, `03-registration.spec.js`, `05-profile.spec.js` — all `.desktop-only` selectors replaced with `.hf-hamburger` + drawer link interactions.
- `toggle_palette` grep across all specs confirmed clean — no spec references it.
- Full selector sweep complete: no remaining `.desktop-only`, `.mobile-nav`, `.nav-overlay`, `.controls` selectors in any spec.

### Phase C — Bottom bar
- Lang toggle in bottom bar uses `<a href="?toggle_lang=1">` (confirmed in README §3). Existing translation test remains valid — no changes needed.
- Font toggle cell must be a no-op stub per AC-FONT-01. Add `// TODO: implement font toggle — see AC-FONT-01` comment in `bottom_bar.php`.
- Bottom bar is **not** added to `admin.php` — confirmed absent from `hifi/admin.jsx`.

### Phase D — Per-page templates
- Run `npm run test:e2e:test` after each page is ported. Fix broken selectors before moving to the next page.
- When fixing broken selectors, **prefer stable selectors** over CSS class swaps:
  - Alerts: use `[role="alert"]` or `data-testid` instead of `.alert-success` / `.alert-danger`
  - Cards: use `hasText` heading content instead of `.card` / `.card-header`
  - Form fields: `input[name="x"]` and `button[type="submit"]` are already stable — keep them

### Phase E — Bet modal (5-step)
- `04-betting.spec.js` interaction rewrite:
  - Identify driver cells by `data-driver-id` attribute (not DOM position — order can change)
  - After picking P1, assert that same driver's cell is non-interactive: `aria-disabled="true"` or `pointer-events: none` computed style
  - Confirm button only enabled after all 3 drivers selected — assert it's disabled before P3 is picked
- Duplicate-driver validation test must be rewritten: old `<select>` constraint is replaced by UI lock-out. Test that clicking an already-picked driver cell has no effect.

### Phase F — Backend (leaderboard rank delta)
- `seed_score_race` must be extended to return `expectedRankDelta` per user — e.g. `[{ email, ptsAfterB, ptsAfterReset, star, rankDeltaAfterB }]`
- Assertion in `13-scoring.spec.js` must verify the **value**, not just presence:
  - A user who moved up: assert `.hf-rank-delta` contains `↑N` or equivalent positive indicator
  - A user who dropped: assert negative indicator
  - First-ever result (no previous snapshot): assert delta renders as `—` not `NaN` or `null`
- The snapshot table must be written immediately after `calculateRacePoints()` is called — verify timing in the PHP implementation.

### Phase G — Email templates
- `npm run test:email:preview` for manual visual review (all 16 email types)
- Existing specs already assert delivery + from-address for all 5 email types — no spec changes needed
- Manually verify sentence-case on all subject lines during preview review

---

## Post-migration cleanup tasks
- [ ] **Delete obsolete language keys** — audit `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php` for keys no longer referenced anywhere in the codebase and remove them.
- [ ] **Merge `bet.php` and `edit_bet.php`** — the 5-step bet modal design assumes a single page. After the redesign ships, merge the two pages into one to eliminate the duplicated modal UI/JS.
- [ ] **Add subject-line format check to `test:email:preview`** — the preview script already logs subject lines; add a sentence-case format assertion so regressions are caught automatically rather than requiring manual review.
- [ ] **Add drawer-state cleanup to serial describes** — any `test.describe.serial` block that opens the drawer should add a `beforeEach` step to close it, so a mid-test failure doesn't leave the drawer open for subsequent tests in the same block.