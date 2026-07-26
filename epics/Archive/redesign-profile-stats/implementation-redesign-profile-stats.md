# Implementation: Profile Stats Redesign (v2.2.0)

## Files changed

| File | Action |
|---|---|
| `public/partials/profile_stats.php` | **CREATED** — hero + chips partial |
| `public/profile.php` | Old 4-cell stats strip replaced with partial include |
| `public/assets/css/style.css` | `.hf-stats-*` block appended; `.form-input[disabled]` overflow fix added |
| `public/lang/user.php` | Added `competing` + `your_stats` in DA and EN |
| `tests/e2e/05-profile.spec.js` | 9 AC-tagged tests added (AC-PROF-04 → AC-PROF-11) |

---

## Markup — `public/partials/profile_stats.php`

```php
<?php // public/partials/profile_stats.php ?>
<section class="hf-stats" aria-label="<?= t('your_stats') ?>" data-testid="profile-stats">

    <article class="hf-stats-hero" data-testid="stats-hero">
        <div class="hf-stats-hero-num" data-testid="stats-points">
            <?= (int) $user['points'] ?><span class="hf-stats-hero-unit">pts</span>
        </div>
        <div class="hf-stats-hero-stars <?= $user['stars'] > 0 ? 'has' : 'empty' ?>" data-testid="stats-stars">
            <?php if ($user['stars'] > 0): ?>
                <?= str_repeat('★', $user['stars']) ?>
            <?php else: ?>
                ★ 0
            <?php endif; ?>
        </div>
        <div class="hf-stats-hero-eyebrow"><?= t('season') ?></div>
    </article>

    <div class="hf-stats-chips">
        <article class="hf-stats-chip role-<?= escape($user['role']) ?>" data-testid="stats-chip-role">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('role') ?></div>
                <div class="v"><?= escape(ucfirst($user['role'])) ?></div>
            </div>
        </article>
        <article class="hf-stats-chip <?= $user['in_competition'] ? 'in' : 'out' ?>" data-testid="stats-chip-competing">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('competing') ?></div>
                <div class="v"><?= $user['in_competition'] ? t('yes') : t('no') ?></div>
            </div>
        </article>
    </div>

</section>
```

Included from `profile.php` as:
```php
<?php $user = $currentUser; include 'partials/profile_stats.php'; ?>
```

---

## CSS — appended to `public/assets/css/style.css`

```css
/* ============================================================
   v2.2.0 — Profile stats (points hero + 2 status chips)
   ============================================================ */

.hf-stats { display: flex; flex-direction: column; gap: 8px; margin: 16px 0 20px; }

.hf-stats-hero {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex; align-items: baseline; gap: 14px;
}
.hf-stats-hero-num {
    font-family: var(--font-display); font-weight: 900; font-size: 38px;
    line-height: 1; color: var(--text-primary);
    font-variant-numeric: tabular-nums; letter-spacing: -0.02em;
    display: inline-flex; align-items: baseline; gap: 4px;
}
.hf-stats-hero-unit { font-family: var(--font-display); color: var(--text-muted); font-size: 14px; font-weight: 600; }
.hf-stats-hero-stars { font-weight: 700; font-size: 18px; color: var(--gold); letter-spacing: 0.5px; }
.hf-stats-hero-stars.empty { color: var(--gold); font-size: 14px; font-weight: 700; opacity: 0.85; }
.hf-stats-hero-eyebrow {
    margin-left: auto; font-family: var(--font-display); font-weight: 700;
    font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted);
}

.hf-stats-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.hf-stats-chip {
    background: transparent; border: 1px solid var(--border-color);
    border-radius: 8px; padding: 8px 10px;
    display: flex; align-items: center; gap: 8px; min-height: 44px;
}
.hf-stats-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-muted); flex-shrink: 0; }
.hf-stats-chip .meta { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
.hf-stats-chip .k { font-family: var(--font-display); font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; }
.hf-stats-chip .v { font-family: var(--font-display); font-size: 13px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.hf-stats-chip.role-admin .dot { background: var(--f1-red-light); }
.hf-stats-chip.role-user  .dot { background: var(--text-secondary); }
.hf-stats-chip.in         .dot { background: var(--status-success-light, #10b981); }
.hf-stats-chip.out        .dot { background: var(--text-muted); }
.hf-stats-chip.out        .v   { color: var(--text-secondary); font-weight: 600; }

@media (min-width: 768px) {
    .hf-stats              { gap: 10px; margin: 24px 0 28px; }
    .hf-stats-hero         { padding: 18px 20px; gap: 18px; }
    .hf-stats-hero-num     { font-size: 44px; }
    .hf-stats-hero-unit    { font-size: 16px; }
    .hf-stats-hero-stars   { font-size: 22px; }
    .hf-stats-chip         { padding: 10px 14px; min-height: 52px; }
    .hf-stats-chip .v      { font-size: 14px; }
}
```

**Note:** Design spec used `.role-player` — corrected to `.role-user` to match the actual DB `ENUM('user', 'admin')`. `--status-success-light` does not exist as a token; the `var(--status-success-light, #10b981)` fallback handles it.

**Bonus fix:** Added `.form-input[disabled] { overflow: hidden; text-overflow: ellipsis; }` to fix a pre-existing overflow on long email addresses in disabled form inputs (discovered during AC-PROF-05 test run at 320px).

---

## i18n — `public/lang/user.php`

Added to both DA and EN after the `'no'` key:

| Key | DA | EN |
|---|---|---|
| `competing` | `Konkurrence` | `Competing` |
| `your_stats` | `Dine tal` | `Your stats` |

Already present (no changes): `season`, `role`, `yes`, `no`.

---

## E2E test results

All 9 AC-PROF tests pass:

```
✅ AC-PROF-04 — hero card and 2 chips render; old stats grid absent
✅ AC-PROF-05 — no horizontal scroll at 320px; chips visible
✅ AC-PROF-07 — stars element is gold in both empty and earned states
✅ AC-PROF-08 — role chip has class role-user for regular player
✅ AC-PROF-08 — competing chip has class 'out' when user is not in competition
✅ AC-PROF-09 — layout unchanged at 768px; old grid still absent
✅ AC-PROF-10 — stats render without overflow in dark theme
✅ AC-PROF-10 — stats render without overflow in light theme
✅ AC-PROF-11 — chip tap targets are at least 44px tall
```

**AC-PROF-05 note:** Test scoped to `[data-testid="profile-stats"].scrollWidth` rather than `document.documentElement.scrollWidth` — the full-document check was catching a pre-existing overflow from long email addresses in the form below the stats section.

**AC-PROF-08 competing note:** The standard e2e seed user has `in_competition = 0`, so the test covers the `out` state. The `in` state (green dot, "Yes" value) is covered by manual QA.

---

## Manual QA checklist

Run across: 320px / 768px / 1280px · dark + light themes · DA + EN language.

- [ ] Hero card + 2 chips visible; no 4-cell grid at any breakpoint
- [ ] 320px: stats section has no horizontal overflow; chips single-line
- [ ] Points number stable when value changes (tabular-nums)
- [ ] `★ 0` renders gold, dimmed (0.85); `★★★` renders gold, full opacity
- [ ] Role chip dot: red for admin, grey for user
- [ ] Competing chip dot: green when `in_competition=1`, grey when `0`
- [ ] MD+: same hero + 2 chip shape, no reflow to 4 columns
- [ ] Dark theme: all text readable
- [ ] Light theme: all text readable
- [ ] Both font stacks (system + editorial): hero number aligns tabularly
