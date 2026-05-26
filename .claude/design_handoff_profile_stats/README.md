# Handoff: Profile stats — points hero + status chips · v2.2.0

> **Design System version:** `v2.2.0` · 2026-05-26 · see [CHANGELOG.md](./CHANGELOG.md)
>
> Minor bump. Replaces the four equal-width stat cells on `profile.php` with a "points hero + 2 status chips" layout (wireframe Option B). Mobile-first. No tokens removed, no classes renamed.

---

## 1 · Visual intent (read first)

The current top of `profile.php` has 4 equal-width cells: **Points · Stars · Role · In competition**. At XS the cells get ~64px each, forcing labels like "IN COMPETITION" to wrap to two lines and putting numeric KPIs ("0 points") in the same visual treatment as identity strings ("Admin"). The shape doesn't match the content.

v2.2.0 splits the four pieces by content type:

- **Points + Stars are KPIs.** They get a single hero card with the points number as the headline, stars rendered inline (`0 pts ★ 0` / `142 pts ★★★`), and a small "Season" eyebrow label.
- **Role + In competition are status.** They get two narrow chips below the hero, each with a coloured dot, a small `ROLE` / `COMPETING` key, and a value (`Admin` / `Yes` or `Not in competition`).

What the user sees on mobile:
1. Profile head (avatar + name + email) — unchanged.
2. **Hero card** (full width): big tabular `0` or `142` with `pts` sub-unit, gold stars inline, "Season" label right-aligned.
3. **2 chip row** (50/50 grid): role chip, competing chip.

The hero gets the visual weight; status sits as supporting detail. On a fresh user with `0 pts ★ 0`, the chips still tell them why they're at zero (Admin · Not competing).

**Mobile-first.** At XS (320px): hero card 296px wide, big number 38px, chips ~144px each. No label wrapping at any width.

At MD+ (≥768px): hero scales up (number 44px, more padding), chips become slightly taller, but the layout shape is the same. Doesn't reflow into 4 columns — that would re-introduce the original problem.

---

## 2 · Selectors that consume tokens

| Selector | Token | Notes |
|---|---|---|
| `.hf-stats-hero` | `--bg-card`, `--border-color` | Container |
| `.hf-stats-hero-num` | `--font-display`, `--text-primary` | Big number, `font-variant-numeric: tabular-nums` |
| `.hf-stats-hero-unit` | `--font-display`, `--text-muted` | "pts" suffix |
| `.hf-stats-hero-stars` | `--gold` | Filled stars; muted variant when 0 |
| `.hf-stats-hero-eyebrow` | `--font-display`, `--text-muted` | "Season" / "Sæson" label, uppercase 10px |
| `.hf-stats-chip` | `--bg-card`, `--border-color` | Each chip container |
| `.hf-stats-chip .dot` | colour per status state | 6px dot, see §5 |
| `.hf-stats-chip .k` | `--font-display`, `--text-muted` | "ROLE" / "COMPETING" key, uppercase 10px |
| `.hf-stats-chip .v` | `--font-display`, `--text-primary` | Value text |

Every selector reads `var(--font-display)` — toggle-safe per the v2.0.1 rules in `CLAUDE.md`.

---

## 3 · Markup template

```php
<?php // public/partials/profile_stats.php ?>
<section class="hf-stats" aria-label="<?= t('your_stats') ?>">

    <!-- Hero — Points + Stars -->
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

    <!-- 2 status chips -->
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

In `profile.php`, replace the existing 4-cell `<div class="...stats...">` block with `<?php include 'partials/profile_stats.php'; ?>`.

---

## 4 · CSS — append to `public/assets/css/style.css`

```css
/* ============================================================
   v2.2.0 — Profile stats (points hero + 2 status chips)
   ============================================================ */

.hf-stats {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 16px 0 20px;
}

/* Hero card — Points + Stars */
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
    font-size: 18px;
    color: var(--gold);
    letter-spacing: 0.5px;
}
.hf-stats-hero-stars.empty {
    color: var(--gold);
    font-size: 14px;
    font-weight: 700;
    opacity: 0.85;
}
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

/* Status dot colours — meaningful, not decorative */
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
    .hf-stats-hero-stars { font-size: 22px; }
    .hf-stats-chip { padding: 10px 14px; min-height: 52px; }
    .hf-stats-chip .v { font-size: 14px; }
}
```

---

## 5 · Status semantics

The dot colour communicates state at a glance. Three rules:

| State | Dot colour | Use when |
|---|---|---|
| `.role-admin` | red (`--f1-red-light`) | User has admin privileges |
| `.role-player` | grey | Regular member |
| `.in` | green (`--status-success-light`) | User is in this season's competition |
| `.out` | muted grey | User is NOT competing (admin, paused, retired) |

The dot is the **only** decorative use of colour in v2.2.0 — keep it small (6px) so it reads as a status indicator, not a theme accent.

---

## 6 · Acceptance criteria

These **replace** any existing profile-stats AC from earlier versions.

- [ ] **AC-PROF-04** (re-spec) — Profile stats render as: (a) a hero card with points + stars + season eyebrow, (b) a 2-up row of role + competing chips. No 4-equal-cells layout at any breakpoint.
- [ ] **AC-PROF-05** — At XS (320px), no label wraps to a second line. Specifically "IN COMPETITION" → renders as "COMPETING" key + "No" value, both single line.
- [ ] **AC-PROF-06** — Points number uses `font-variant-numeric: tabular-nums`. Verify by changing points from `0` to `142` in DevTools — adjacent text shouldn't shift horizontally.
- [ ] **AC-PROF-07** — Star glyph and count are **gold** in both states. When `stars === 0`, render `★ 0` in gold at slightly reduced size (14px) and 0.85 opacity so it reads as "no stars yet" without losing the gold identity. When `stars > 0`, render `★` repeated `stars` times in full-strength gold (18px).
- [ ] **AC-PROF-08** — Role chip dot is red when `role === 'admin'`, grey otherwise. Competing chip dot is green when `in_competition === true`, muted grey when false.
- [ ] **AC-PROF-09** — At MD+ the shape doesn't change — same hero + 2 chips, just bigger. **Specifically must NOT** reflow into 4 equal cells at any breakpoint (that's the regression this release prevents).
- [ ] **AC-PROF-10** — Both themes (dark + light) read correctly. Both font stacks (Stack A + Stack B) read correctly — verify the hero number tabular alignment in Stack B (`ui-monospace` fallback).
- [ ] **AC-PROF-11** — No horizontal scroll at 320px viewport. Chip minimum tap target is 44px tall.

---

## 7 · Migration sweep

1. **`profile.php`** — find the existing 4-cell stats block. Replace with `<?php include 'partials/profile_stats.php'; ?>`. Delete the old markup; don't leave it under a feature flag — there's no opt-out for the new layout.
2. **CSS** — paste §4 at the bottom of `public/assets/css/style.css`. All selectors `.hf-stats-*` namespaced, no collisions.
3. **i18n** — confirm these keys exist (DA / EN), add if missing:
   - `season` → "Sæson" / "Season"
   - `role` → "Rolle" / "Role"
   - `competing` → "Konkurrence" / "Competing"
   - `yes` → "Ja" / "Yes"
   - `no` → "Nej" / "No"
   - `your_stats` → "Dine tal" / "Your stats"
4. **`$user` object** — confirm it carries `points`, `stars`, `role`, `in_competition`. If `in_competition` doesn't exist as a column yet, derive it: `$user['in_competition'] = ($user['role'] !== 'admin' && $user['active']);`
5. **Run AC-PROF-04 → AC-PROF-11** on staging before merge.

---

## 8 · What's NOT in this release

- No changes to the profile head (avatar + name + email) — unchanged.
- No changes to the tabs (`Profile / Security / Preferences`) — unchanged.
- No changes to the bet history list below — unchanged.
- No new tokens. Uses existing `--bg-card`, `--border-color`, `--text-*`, `--gold`, `--status-success-light`, `--f1-red-light`, `--font-display`.
- No JavaScript. Pure markup + CSS.

---

## 9 · Implementation time

~30 minutes for a clean implementation:

- 5 min · Paste §4 CSS into `style.css`
- 10 min · Create `partials/profile_stats.php`, include in `profile.php`, delete old markup
- 5 min · Confirm i18n keys exist
- 10 min · Run AC-PROF-04 → AC-PROF-11 across XS/SM/MD/LG, dark/light, both font stacks
