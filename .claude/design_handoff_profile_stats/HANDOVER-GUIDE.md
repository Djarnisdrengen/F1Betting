# Profile stats redesign — handover

A drop-in redesign for the 4 stat boxes at the top of `profile.php`. ~30 minutes of work. Mobile-first. No new tokens, no class renames, no JS.

---

## The problem

The profile page currently shows four equal-width cells at the top:

`[ 0 / POINTS ]  [ 0 / STARS ]  [ Admin / ROLE ]  [ No / IN COMPETITION ]`

At 320px each cell is ~64px wide. That's wide enough for numbers but not for the labels — "IN COMPETITION" wraps to two lines, the value "No" sits unbalanced above it. The deeper problem is that all four pieces share the same visual treatment, even though they're different kinds of data:

- **Points** and **Stars** are KPIs (numbers, growing over time).
- **Role** and **In competition** are identity / status (rarely changing strings).

Tile shape should reflect that. It doesn't.

---

## The fix — hero card + 2 status chips

Replace the 4 equal cells with a hero card and a 2-up chip row:

**Hero** — Points as a big tabular number with `pts` suffix, gold stars inline (always gold — full-strength when earned, dimmed when zero), `SÆSON` eyebrow right-aligned.

**Chip row** — Role chip and Competing chip side by side. Each chip is a coloured dot + small uppercase key + value. The dot communicates state at a glance:
- Role: red for admin, grey for player.
- Competing: green for yes, muted grey for no.

On a fresh admin account (`0 pts ★ 0` · `Admin` · `Not competing`), the chips immediately explain why the numbers are at zero.

---

## Markup

Create `public/partials/profile_stats.php`:

```php
<section class="hf-stats" aria-label="<?= t('your_stats') ?>">

    <article class="hf-stats-hero">
        <div class="hf-stats-hero-num">
            <?= (int) $user['points'] ?><span class="hf-stats-hero-unit">pts</span>
        </div>
        <div class="hf-stats-hero-stars <?= $user['stars'] > 0 ? 'has' : 'empty' ?>">
            <?php if ($user['stars'] > 0): ?>
                <?= str_repeat('★', $user['stars']) ?>
            <?php else: ?>
                ★ 0
            <?php endif; ?>
        </div>
        <div class="hf-stats-hero-eyebrow"><?= t('season') ?></div>
    </article>

    <div class="hf-stats-chips">
        <article class="hf-stats-chip role-<?= escape($user['role']) ?>">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('role') ?></div>
                <div class="v"><?= escape(ucfirst($user['role'])) ?></div>
            </div>
        </article>
        <article class="hf-stats-chip <?= $user['in_competition'] ? 'in' : 'out' ?>">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('competing') ?></div>
                <div class="v"><?= $user['in_competition'] ? t('yes') : t('no') ?></div>
            </div>
        </article>
    </div>

</section>
```

In `profile.php`, find the existing 4-cell stats block and replace with:

```php
<?php include 'partials/profile_stats.php'; ?>
```

Delete the old markup. Don't leave it under a flag.

---

## CSS

Append to `public/assets/css/style.css`:

```css
/* Profile stats — hero card + 2 status chips */

.hf-stats {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 16px 0 20px;
}

/* Hero — Points + Stars */
.hf-stats-hero {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: baseline;
    gap: 14px;
}
.hf-stats-hero-num {
    font-family: var(--font-display);
    font-weight: 900;
    font-size: 38px;
    line-height: 1;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.02em;
    display: inline-flex;
    align-items: baseline;
    gap: 4px;
}
.hf-stats-hero-unit {
    font-family: var(--font-display);
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 600;
}
.hf-stats-hero-stars {
    font-weight: 700;
    color: var(--gold);
    letter-spacing: 0.5px;
}
.hf-stats-hero-stars.has   { font-size: 18px; }
.hf-stats-hero-stars.empty { font-size: 14px; opacity: 0.85; }
.hf-stats-hero-eyebrow {
    margin-left: auto;
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}

/* 2-up chip row */
.hf-stats-chips {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.hf-stats-chip {
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
}
.hf-stats-chip .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--text-muted);
    flex-shrink: 0;
}
.hf-stats-chip .meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.25;
}
.hf-stats-chip .k {
    font-family: var(--font-display);
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 600;
}
.hf-stats-chip .v {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Status dot colours */
.hf-stats-chip.role-admin   .dot { background: var(--f1-red-light); }
.hf-stats-chip.role-player  .dot { background: var(--text-secondary); }
.hf-stats-chip.in           .dot { background: var(--status-success-light, #10b981); }
.hf-stats-chip.out          .dot { background: var(--text-muted); }
.hf-stats-chip.out          .v   { color: var(--text-secondary); font-weight: 600; }

/* MD+ scale-up — same shape, more breathing room */
@media (min-width: 768px) {
    .hf-stats {
        gap: 10px;
        margin: 24px 0 28px;
    }
    .hf-stats-hero {
        padding: 18px 20px;
        gap: 18px;
    }
    .hf-stats-hero-num { font-size: 44px; }
    .hf-stats-hero-unit { font-size: 16px; }
    .hf-stats-hero-stars.has   { font-size: 22px; }
    .hf-stats-hero-stars.empty { font-size: 16px; }
    .hf-stats-chip { padding: 10px 14px; min-height: 52px; }
    .hf-stats-chip .v { font-size: 14px; }
}
```

All selectors are `.hf-stats-*` namespaced — no collisions with anything else in `style.css`.

---

## i18n keys

The partial calls `t()` for these 6 keys. Add any that aren't already in your translation files:

| Key | DA | EN |
|---|---|---|
| `season` | Sæson | Season |
| `role` | Rolle | Role |
| `competing` | Konkurrence | Competing |
| `yes` | Ja | Yes |
| `no` | Nej | No |
| `your_stats` | Dine tal | Your stats |

---

## `$user` shape

The partial reads four fields:

```php
$user = [
    'points'         => 142,    // int
    'stars'          => 3,      // int (count of stars earned this season)
    'role'           => 'admin', // 'admin' | 'player'
    'in_competition' => false,   // bool
];
```

If `in_competition` doesn't exist as a column yet, derive it:

```php
$user['in_competition'] = ($user['role'] !== 'admin' && $user['active']);
```

(Adjust to whatever your actual "is this user playing the season" logic is.)

---

## Acceptance criteria

Pass/fail check before merging:

- [ ] Profile stats render as a hero card + 2 status chips. No 4-equal-cells layout at any breakpoint.
- [ ] At 320px no label wraps to a second line. "IN COMPETITION" renders as "COMPETING" key + "No" value, both single line.
- [ ] Points number uses `font-variant-numeric: tabular-nums`. Verify by changing points from `0` to `142` in DevTools — adjacent text shouldn't shift horizontally.
- [ ] The star glyph and count are **gold** in both states. `★ 0` renders in gold at 14px / 0.85 opacity; `★★★` renders in gold at 18px / full opacity.
- [ ] Role chip dot is red when `role === 'admin'`, grey otherwise. Competing chip dot is green when `in_competition === true`, muted grey when false.
- [ ] At MD+ the shape doesn't change — same hero + 2 chips, just bigger. Must NOT reflow into 4 equal cells at any breakpoint.
- [ ] Both themes (dark + light) read correctly.
- [ ] No horizontal scroll at 320px. Chip minimum tap target is 44px tall.

---

## What this doesn't touch

- The profile head (avatar + name + email) above the stats — unchanged.
- The tabs (`Profile / Security / Preferences`) below the stats — unchanged.
- The bet history list further down the page — unchanged.

Just the four-cell strip.

---

## Implementation order

1. Paste the CSS into `style.css` (5 min)
2. Create `partials/profile_stats.php` and include it in `profile.php`; delete the old 4-cell markup (10 min)
3. Confirm the 6 i18n keys exist (5 min)
4. Confirm `$user['in_competition']` resolves (or derive it from `role` + `active`) (5 min)
5. Run the 8 acceptance criteria across XS / MD / LG, in dark + light themes (5 min)

Total: ~30 min.

---

## If something goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| Old 4-cell layout still visible | `profile.php` still has the old markup | Delete it — the new partial replaces it entirely |
| Dot colour wrong | `role-admin` / `in` / `out` class not on the chip | Check the conditional in the partial |
| Number reflows when value changes | Missing `font-variant-numeric: tabular-nums` | Confirm the CSS pasted in full |
| Hero overflows at 320px | Eyebrow label pushing layout | Confirm `margin-left: auto` is on `.hf-stats-hero-eyebrow` |
| Star is grey when 0 | Old "muted empty" rule still applied | Confirm `.hf-stats-hero-stars.empty` uses `color: var(--gold)` |
