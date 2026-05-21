# Handoff supplement: Bet modal · v1.3.2

> **Design System version:** `v1.3.2` · 2026-05-20 · see [CHANGELOG.md](./CHANGELOG.md)
>
> This is a **focused supplement** to the v1.3.0 handoff. It replaces §5 "Bet modal" in `claude-design-system-v1.3.0.md` and re-specifies the interaction pattern, layout, and the page-context background. Everything else in v1.3.0 stands.

The v1.3.0 spec described an inline-picker pattern (tap a position row → driver picker appears below that row). **That was wrong** — the live site already uses a cleaner "two-card list" pattern, and we should preserve it, not replace it. This doc re-specifies the modal in line with the existing implementation.

It also fixes a related issue: the modal artboards in the canvas previously sat on a near-empty background. They now show the race detail page underneath the overlay. **Your implementation must do the same** — the modal is *on top of* the race detail page, never on a void.

---

## 1 · Interaction pattern (the spec)

The bet modal is a **single screen** with two side-by-side concerns:

1. **The three position slots (P1 / P2 / P3)** — always visible at the top of the betting card.
2. **The full driver list** — always visible below, scrollable.

The user flow is:

```
1. Tap the P1, P2, or P3 slot to make it the active target.
2. Tap a driver in the list — they get assigned to the active slot.
3. Active target auto-advances to the next empty slot
   (P1→P2→P3, then stops when all three are filled).
4. Tap "Gem" to save the bet, or "Annuller" to discard.
```

**Key behavioural rules:**

- The active slot is indicated by a **2px red border + soft red glow** around the slot card.
- Already-assigned drivers in the list show a coloured `P1` / `P2` / `P3` pill on the right side of their row.
- Tapping a driver who is already assigned to one slot, while a different slot is active, **moves** them — clears the old slot, fills the new one.
- Tapping a driver who is already assigned to the active slot is a no-op (or deselects, designer's choice — but be consistent across the 3 slots).
- The "Gem" button is **disabled** (visually + `aria-disabled`) until all 3 slots are filled.
- Tapping "Annuller" closes the modal with no save; tapping the overlay does the same; pressing Esc does the same.

This pattern is faster than the previous nested-picker design because: (a) the driver list is always on screen, (b) the user controls assignment order, (c) the same driver list serves all 3 slots.

---

## 2 · Layout — two stacked cards

The modal contents are **two distinct cards**, stacked with a 16px gap, both on the same `var(--bg-card)` background as the modal itself:

### Card 1 — Race info header

A single-row card with the race context. Fixed height, not scrollable.

```
┌──────────────────────────────────────────────────────────┐
│  ┌─────┐                                                  │
│  │ MON │  test: Canadian Grand Prix                       │
│  │     │  Montreal · 21 May 2026 · 22:00 CET          ✕   │
│  └─────┘  ⌚ Betting lukker om: 23t 16m 9s                 │
│                                              [BETTING ÅBEN]│
└──────────────────────────────────────────────────────────┘
```

| Element | Spec |
|---|---|
| Avatar | 48×48, circle, `var(--bg-secondary)` fill, 1px `var(--border-color)` border, 3-letter race code in Chivo 800 (e.g. "MON" for Monaco, "CAN" for Canadian GP). Take from the first 3 letters of the city, uppercased. |
| Race name | Chivo 800, 18px, `var(--text-primary)`. Prefix with "test: " if the race is in test mode (existing convention). |
| Meta row | Manrope 500, 13px, `var(--text-muted)`. `{location} · {DD MMM YYYY} · {HH:mm} CET`, dot separators are `·` U+00B7. |
| Countdown row | Manrope 500, 13px. Stopwatch glyph (⌚ or fontawesome stopwatch) in muted, label "Betting lukker om:" in muted, countdown value `{NNt NNm NNs}` in **`var(--status-success)` green**, Courier Prime, tabular-nums. Updates every second client-side. |
| Status badge | Pill, 4px×10px padding, 11px Chivo 700 uppercase, letter-spacing 0.08em. `BETTING ÅBEN` = green (`rgba(16,185,129,0.15)` bg + `var(--status-success)` text + 1px `rgba(16,185,129,0.35)` border). `BETTING LUKKET` = red equivalent. |
| Close button | 32×32 ghost, "✕" glyph, top-right of card. `aria-label="Luk"`. |

When **betting is closed**, the badge flips to `BETTING LUKKET` (red), the countdown row is replaced with "Lukket — kvalifikation kører" or similar, and the entire betting card (Card 2) is replaced with the read-only "Dit bud" panel.

### Card 2 — Betting controls

A taller card with three sections, separated only by spacing (no rules, no dividers):

**Section 2a — Position slots (top of card)**

Three rows, 12px gap between them, full width of the card:

```
┌──────────────────────────────────────────────────────────┐
│  [P1] Alonso                                       25 pts │ ← active: 2px red border + red glow
├──────────────────────────────────────────────────────────┤
│  [P2] Antonelli                                    18 pts │
├──────────────────────────────────────────────────────────┤
│  [P3] Bearman                                      15 pts │
└──────────────────────────────────────────────────────────┘
```

| Element | Spec |
|---|---|
| Slot row | 56px tall, `var(--bg-secondary)` background, 10px border-radius, 16px horizontal padding, full width. |
| Active slot border | `2px solid var(--f1-red)`, plus `box-shadow: 0 0 0 4px rgba(225,6,0,0.15)`. |
| Inactive slot border | `1px solid var(--border-color)`. |
| Empty slot border | `1px dashed var(--border-color)`, and the name reads "Vælg kører" in `var(--text-muted)`. |
| Position badge | 28×28, 6px radius, Chivo 800 white "P1"/"P2"/"P3" centered. **P1 = `var(--gold)` / #FBBF24**, **P2 = `var(--silver)` / #C0C0C8**, **P3 = `var(--bronze)` / #CD7F32**. Use existing tokens if defined; add to `:root` if not. |
| Driver name | Chivo 700, 15px, `var(--text-primary)`. Surname only (matches existing convention in the screenshot — "Alonso", not "Fernando Alonso"). |
| Points value (right side) | Manrope 600, 13px, `var(--text-muted)`. "25 pts" / "18 pts" / "15 pts" — these are the **points awarded for getting that position right** (not the user's current points). Pull from settings or hardcode if the scoring is fixed. |

The whole row is a `<button>` — tapping it makes that slot the active target.

**Section 2b — Driver list (below the 3 slots, 16px gap)**

A scrollable list of all drivers in the championship, sorted alphabetically by surname. **Not** filtered by team or position — the full grid.

```
┌──────────────────────────────────────────────────────────┐
│  #23  Albon                                               │
│  #14  Alonso                                       [P1]   │ ← selected for active slot: darker bg
│  #12  Antonelli                                    [P2]   │
│  #87  Bearman                                      [P3]   │
│  #5   Bortoleto                                           │
│  #77  Bottas                                              │
│  #43  Colapinto                                           │
│  #10  Gasly                                               │
│  #6   Hadjar                                              │
│  #44  Hamilton                                            │
│  ↓ (scrollable)                                           │
└──────────────────────────────────────────────────────────┘
```

| Element | Spec |
|---|---|
| List container | `var(--bg-secondary)` background, 10px border-radius, max-height `min(360px, 40vh)`, `overflow-y: auto`, scrollbar styled (see below). |
| Row | 40px tall, 12px horizontal padding, full width. Border-bottom `1px solid var(--border-soft)` between rows (skip on last). |
| Row hover | `background: var(--bg-hover)`. |
| Row when driver is assigned to the active slot | `background: rgba(225,6,0,0.10)`, the row text stays normal weight. |
| Car number | Manrope 500, 13px, `var(--text-muted)`, fixed width `52px`, left-aligned with `#` prefix in muted (e.g. `#23`). |
| Driver name | Chivo 700, 14px, `var(--text-primary)`. Surname only. |
| Position pill (right side, only if assigned) | 22px tall, 8px radius, 6px×4px padding, 11px Chivo 800 uppercase. Same colour rules as the position badge — gold/silver/bronze. Margin-left auto. |
| Scrollbar | Width 6px, track transparent, thumb `var(--border-color)` → `var(--text-muted)` on hover, 3px radius. |

**Section 2c — Footer actions (bottom of card, 16px gap above)**

Two buttons in a `flex-direction: row` with `gap: 12px`:

| Button | Spec |
|---|---|
| Annuller | Ghost: transparent bg, 1px `var(--border-color)` border, `var(--text-primary)` text. Flex 1. Height 48. 10px radius. Chivo 600 15px. |
| Gem | Primary: `var(--f1-red)` bg, white text, no border. Flex 2 (so it's ~2× wider than Annuller, matching the screenshot). Height 48. 10px radius. Chivo 700 15px. **Prefix with floppy-disk icon** (`💾` or `<i class="fa fa-floppy-disk">` to match the existing icon stack). |
| Gem disabled | `opacity: 0.5`, `cursor: not-allowed`, `pointer-events: none`. Use when any slot is still empty. |

---

## 3 · Modal chrome — **must sit on top of the race detail page**

The previous design canvas showed the modal on a near-empty background. **That was a canvas-artefact bug, not the spec.** Production must:

1. **Keep the race detail page rendered behind the modal.** Do not unmount it, do not replace it. The user opened the modal *from* the race detail page; closing the modal returns them there.
2. **Cover the page with an overlay.** `position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100;`. The overlay element is clickable and dismisses the modal.
3. **The modal sits on top of the overlay.** Centered on MD+, fullscreen on XS/SM (per AC-RACE-04 — that part of v1.3.0 is unchanged).
4. **Scroll-lock the body** while the modal is open (`document.body.style.overflow = 'hidden'` on open, restore on close). Otherwise the background page scrolls when the modal's driver list reaches its end.

Concretely:

```html
<!-- existing page content sits here, untouched -->
<main class="hf-body">
  ...race detail page...
</main>

<!-- modal appended to <body>, AFTER <main>, only when open -->
<div class="hf-modal-overlay" data-link="closeBetModal">
  <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">
    <!-- Card 1: race info -->
    <!-- Card 2: betting controls -->
  </div>
</div>
```

```css
.hf-modal-overlay {
    position: fixed; inset: 0; z-index: 100;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 32px 16px;
    animation: hf-overlay-in 180ms ease-out;
}
.hf-modal-card {
    position: relative; z-index: 1;
    width: 100%; max-width: 560px;
    max-height: calc(100vh - 64px);
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 16px;
    /* No background — children are the two cards on their own backgrounds */
}
@keyframes hf-overlay-in { from { opacity: 0; } to { opacity: 1; } }

/* Fullscreen at XS/SM per AC-RACE-04 */
@media (max-width: 767px) {
    .hf-modal-overlay {
        padding: 0; background: var(--bg-primary); backdrop-filter: none;
    }
    .hf-modal-card {
        max-width: none; max-height: none; min-height: 100vh; border-radius: 0; gap: 1px;
    }
}
```

Click handler on `[data-link="closeBetModal"]` must check `e.target === e.currentTarget` so clicks **inside** the modal card don't close it — only clicks on the overlay itself do.

---

## 4 · Markup template (PHP)

A complete, drop-in template you can adapt to your existing variable names:

```php
<?php // public/partials/bet_modal.php ?>
<?php if ($showBetModal): ?>
<div class="hf-modal-overlay" data-link="closeBetModal" role="presentation">
    <div class="hf-modal-card" role="dialog" aria-modal="true" aria-labelledby="bet-modal-title">

        <!-- Card 1: race info header -->
        <section class="hf-bet-header">
            <div class="hf-bet-avatar"><?= strtoupper(substr($race['location'], 0, 3)) ?></div>
            <div class="hf-bet-meta">
                <h2 id="bet-modal-title" class="hf-bet-title">
                    <?php if ($race['is_test']): ?><span class="hf-bet-test">test: </span><?php endif; ?>
                    <?= escape($race['name']) ?>
                </h2>
                <div class="hf-bet-submeta">
                    <?= escape($race['location']) ?> · <?= date('j M Y · H:i', strtotime($race['start_at'])) ?> CET
                </div>
                <div class="hf-bet-countdown">
                    <i class="fa fa-stopwatch"></i>
                    <?= t('betting_closes_in') ?>:
                    <span class="hf-bet-countdown-val" data-countdown="<?= $race['betting_closes_at'] ?>">
                        <?= countdown_format($race['betting_closes_at']) ?>
                    </span>
                </div>
            </div>
            <div class="hf-bet-badge <?= $race['betting_open'] ? 'open' : 'closed' ?>">
                <?= $race['betting_open'] ? t('betting_open') : t('betting_closed') ?>
            </div>
            <button class="hf-bet-close" data-link="closeBetModal" aria-label="<?= t('close') ?>">✕</button>
        </section>

        <!-- Card 2: betting controls -->
        <section class="hf-bet-controls">

            <!-- Position slots -->
            <div class="hf-slots">
                <?php foreach ([1, 2, 3] as $pos): ?>
                    <?php $driver = $bet['pos_' . $pos] ?? null; ?>
                    <button
                        class="hf-slot <?= $activeSlot === $pos ? 'is-active' : '' ?> <?= $driver ? 'is-filled' : 'is-empty' ?>"
                        data-link="activateSlot" data-pos="<?= $pos ?>">
                        <span class="hf-slot-badge pos-<?= $pos ?>">P<?= $pos ?></span>
                        <span class="hf-slot-name">
                            <?= $driver ? escape($driver['surname']) : t('pick_driver') ?>
                        </span>
                        <span class="hf-slot-pts"><?= $pointsFor[$pos] ?> pts</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Driver list -->
            <div class="hf-driver-list">
                <?php foreach ($drivers as $d): ?>
                    <?php $assignedTo = $assignedDrivers[$d['id']] ?? null; ?>
                    <button
                        class="hf-driver-row <?= $assignedTo && $activeSlot && $assignedTo == $activeSlot ? 'is-selected' : '' ?>"
                        data-link="pickDriver" data-driver-id="<?= $d['id'] ?>"
                        <?= !$activeSlot ? 'disabled' : '' ?>>
                        <span class="hf-driver-num">#<?= escape($d['car_number']) ?></span>
                        <span class="hf-driver-name"><?= escape($d['surname']) ?></span>
                        <?php if ($assignedTo): ?>
                            <span class="hf-driver-pill pos-<?= $assignedTo ?>">P<?= $assignedTo ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Footer actions -->
            <footer class="hf-bet-actions">
                <button class="hf-btn-ghost" data-link="closeBetModal"><?= t('cancel') ?></button>
                <button class="hf-btn-primary <?= $allFilled ? '' : 'is-disabled' ?>"
                        data-link="saveBet"
                        <?= $allFilled ? '' : 'aria-disabled="true"' ?>>
                    <i class="fa fa-floppy-disk"></i> <?= t('save') ?>
                </button>
            </footer>

        </section>

    </div>
</div>
<?php endif; ?>
```

---

## 5 · JS — the four handlers

Vanilla JS, no framework, ~40 lines total. Add to `public/assets/js/bet-modal.js` and include on the race detail page.

```js
(function() {
    let activeSlot = null;        // 1, 2, or 3, or null
    const bet = { 1: null, 2: null, 3: null };

    function $(sel, root = document) { return root.querySelector(sel); }
    function $$(sel, root = document) { return [...root.querySelectorAll(sel)]; }

    // 1. Open modal — wire from the "Læg dit bud" CTA on the race detail page
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-link="openBetModal"]')) {
            $('.hf-modal-overlay').hidden = false;
            document.body.style.overflow = 'hidden';
            // Set initial active slot to first empty one
            activeSlot = [1, 2, 3].find(p => !bet[p]) || null;
            render();
        }
    });

    // 2. Close modal — overlay click, ✕ button, Esc key
    function closeModal() {
        $('.hf-modal-overlay').hidden = true;
        document.body.style.overflow = '';
        activeSlot = null;
    }
    document.addEventListener('click', (e) => {
        const closer = e.target.closest('[data-link="closeBetModal"]');
        if (!closer) return;
        // Only close on overlay-self click, not on clicks inside the card
        if (closer.classList.contains('hf-modal-overlay') && e.target !== closer) return;
        closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('.hf-modal-overlay').hidden) closeModal();
    });

    // 3. Activate a slot when its row is tapped
    document.addEventListener('click', (e) => {
        const slot = e.target.closest('[data-link="activateSlot"]');
        if (!slot) return;
        activeSlot = parseInt(slot.dataset.pos, 10);
        render();
    });

    // 4. Pick a driver from the list — assign to active slot
    document.addEventListener('click', (e) => {
        const row = e.target.closest('[data-link="pickDriver"]');
        if (!row || row.disabled || activeSlot === null) return;
        const driverId = parseInt(row.dataset.driverId, 10);

        // If this driver is already in a different slot, clear that slot first (move)
        for (const p of [1, 2, 3]) {
            if (bet[p] === driverId && p !== activeSlot) bet[p] = null;
        }
        bet[activeSlot] = driverId;

        // Auto-advance to the next empty slot
        const next = [1, 2, 3].find(p => p !== activeSlot && !bet[p]);
        activeSlot = next || activeSlot;
        render();
    });

    // Re-render the modal: which slot is active, which drivers are assigned, gem-enabled?
    function render() {
        $$('.hf-slot').forEach(s => {
            const p = parseInt(s.dataset.pos, 10);
            s.classList.toggle('is-active', p === activeSlot);
            s.classList.toggle('is-filled', !!bet[p]);
            s.classList.toggle('is-empty', !bet[p]);
            $('.hf-slot-name', s).textContent = bet[p]
                ? window.driversById[bet[p]].surname
                : t('pick_driver');
        });
        $$('.hf-driver-row').forEach(r => {
            const id = parseInt(r.dataset.driverId, 10);
            const assignedTo = [1, 2, 3].find(p => bet[p] === id);
            r.classList.toggle('is-selected', assignedTo === activeSlot);
            const pill = $('.hf-driver-pill', r);
            if (assignedTo) {
                if (!pill) {
                    const newPill = document.createElement('span');
                    newPill.className = 'hf-driver-pill pos-' + assignedTo;
                    newPill.textContent = 'P' + assignedTo;
                    r.appendChild(newPill);
                } else {
                    pill.className = 'hf-driver-pill pos-' + assignedTo;
                    pill.textContent = 'P' + assignedTo;
                }
            } else if (pill) pill.remove();
            r.disabled = activeSlot === null;
        });
        const allFilled = [1, 2, 3].every(p => bet[p]);
        const gem = $('.hf-btn-primary');
        gem.classList.toggle('is-disabled', !allFilled);
        gem.setAttribute('aria-disabled', !allFilled);
    }

    // Initial render in case the modal opens with an existing bet
    window.addEventListener('DOMContentLoaded', render);
})();
```

The `data-link` attribute pattern matches the existing convention in `header.php`'s drawer toggle.

---

## 6 · CSS (append to `public/assets/css/style.css`)

```css
/* ============================================================
   v1.3.2 — Bet modal (full re-spec)
   ============================================================ */

.hf-modal-overlay {
    position: fixed; inset: 0; z-index: 100;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 32px 16px;
    animation: hf-overlay-in 180ms ease-out;
}
.hf-modal-overlay[hidden] { display: none; }
.hf-modal-card {
    position: relative; z-index: 1;
    width: 100%; max-width: 560px;
    max-height: calc(100vh - 64px);
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 16px;
    animation: hf-modal-in 220ms cubic-bezier(.2,.7,.3,1.1);
}
@keyframes hf-overlay-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes hf-modal-in { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }

@media (max-width: 767px) {
    .hf-modal-overlay { padding: 0; background: var(--bg-primary); backdrop-filter: none; }
    .hf-modal-card { max-width: none; max-height: none; min-height: 100vh; gap: 1px; }
}

/* Card 1 — race info */
.hf-bet-header {
    position: relative;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px 48px 16px 16px;
    display: flex; align-items: center; gap: 14px;
}
.hf-bet-avatar {
    flex-shrink: 0;
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--bg-secondary); border: 1px solid var(--border-color);
    display: inline-flex; align-items: center; justify-content: center;
    font-family: 'Chivo', sans-serif; font-weight: 800; font-size: 13px;
    color: var(--text-secondary); letter-spacing: 0.04em;
}
.hf-bet-meta { flex: 1; min-width: 0; }
.hf-bet-title {
    font-family: 'Chivo', sans-serif; font-weight: 800; font-size: 18px;
    letter-spacing: -0.01em; color: var(--text-primary); margin: 0;
}
.hf-bet-test { color: var(--text-muted); font-weight: 500; }
.hf-bet-submeta {
    color: var(--text-muted); font-size: 13px; margin-top: 2px;
}
.hf-bet-countdown {
    margin-top: 6px;
    color: var(--text-muted); font-size: 13px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.hf-bet-countdown i { font-size: 12px; }
.hf-bet-countdown-val {
    color: var(--status-success);
    font-family: 'Courier Prime', ui-monospace, monospace;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}
.hf-bet-badge {
    position: absolute; top: 16px; right: 48px;
    padding: 4px 10px; border-radius: 999px;
    font-family: 'Chivo', sans-serif; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em; white-space: nowrap;
}
.hf-bet-badge.open {
    background: rgba(16,185,129,0.15);
    color: var(--status-success);
    border: 1px solid rgba(16,185,129,0.35);
}
.hf-bet-badge.closed {
    background: rgba(225,6,0,0.12);
    color: var(--f1-red-light);
    border: 1px solid rgba(225,6,0,0.40);
}
.hf-bet-close {
    position: absolute; top: 8px; right: 8px;
    width: 32px; height: 32px; border-radius: 8px;
    background: transparent; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 16px;
}
.hf-bet-close:hover { background: var(--bg-hover); color: var(--text-primary); }

/* Card 2 — betting controls */
.hf-bet-controls {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px;
    display: flex; flex-direction: column; gap: 16px;
}

/* Position slots */
.hf-slots { display: flex; flex-direction: column; gap: 12px; }
.hf-slot {
    height: 56px; padding: 0 16px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    display: flex; align-items: center; gap: 14px;
    cursor: pointer; color: var(--text-primary);
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    width: 100%; text-align: left;
}
.hf-slot:hover { background: var(--bg-hover); }
.hf-slot.is-empty { border-style: dashed; }
.hf-slot.is-empty .hf-slot-name { color: var(--text-muted); }
.hf-slot.is-active {
    border: 2px solid var(--f1-red);
    box-shadow: 0 0 0 4px rgba(225,6,0,0.15);
    padding: 0 15px;  /* compensate for the +1px border */
}
.hf-slot-badge {
    width: 28px; height: 28px; border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
    font-family: 'Chivo', sans-serif; font-weight: 800; font-size: 12px;
    color: white; flex-shrink: 0;
}
.hf-slot-badge.pos-1 { background: var(--gold,   #FBBF24); color: #1c1c1c; }
.hf-slot-badge.pos-2 { background: var(--silver, #C0C0C8); color: #1c1c1c; }
.hf-slot-badge.pos-3 { background: var(--bronze, #CD7F32); color: #ffffff; }
.hf-slot-name {
    flex: 1; min-width: 0;
    font-family: 'Chivo', sans-serif; font-weight: 700; font-size: 15px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.hf-slot-pts {
    color: var(--text-muted); font-size: 13px; font-weight: 500;
    flex-shrink: 0;
}

/* Driver list */
.hf-driver-list {
    background: var(--bg-secondary);
    border-radius: 10px;
    max-height: min(360px, 40vh);
    overflow-y: auto;
}
.hf-driver-list::-webkit-scrollbar { width: 6px; }
.hf-driver-list::-webkit-scrollbar-track { background: transparent; }
.hf-driver-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }
.hf-driver-list::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

.hf-driver-row {
    width: 100%; height: 40px; padding: 0 12px;
    background: transparent; border: none;
    border-bottom: 1px solid var(--border-soft);
    display: flex; align-items: center; gap: 8px;
    cursor: pointer; color: var(--text-primary);
    text-align: left; transition: background 0.12s;
}
.hf-driver-row:last-child { border-bottom: none; }
.hf-driver-row:hover:not(:disabled) { background: var(--bg-hover); }
.hf-driver-row.is-selected { background: rgba(225,6,0,0.10); }
.hf-driver-row:disabled { cursor: default; }
.hf-driver-num {
    width: 52px; flex-shrink: 0;
    color: var(--text-muted); font-size: 13px;
    font-family: 'Manrope', sans-serif; font-weight: 500;
}
.hf-driver-name {
    flex: 1; min-width: 0;
    font-family: 'Chivo', sans-serif; font-weight: 700; font-size: 14px;
    color: var(--text-primary);
}
.hf-driver-pill {
    padding: 3px 7px; border-radius: 6px;
    font-family: 'Chivo', sans-serif; font-weight: 800; font-size: 11px;
    letter-spacing: 0.04em; flex-shrink: 0;
}
.hf-driver-pill.pos-1 { background: var(--gold,   #FBBF24); color: #1c1c1c; }
.hf-driver-pill.pos-2 { background: var(--silver, #C0C0C8); color: #1c1c1c; }
.hf-driver-pill.pos-3 { background: var(--bronze, #CD7F32); color: #ffffff; }

/* Footer actions */
.hf-bet-actions {
    display: flex; gap: 12px;
    padding-top: 4px;
}
.hf-btn-ghost {
    flex: 1; height: 48px; border-radius: 10px;
    background: transparent; border: 1px solid var(--border-color);
    color: var(--text-primary);
    font-family: 'Chivo', sans-serif; font-weight: 600; font-size: 15px;
    cursor: pointer;
}
.hf-btn-ghost:hover { background: var(--bg-hover); }
.hf-btn-primary {
    flex: 2; height: 48px; border-radius: 10px;
    background: var(--f1-red); border: none; color: white;
    font-family: 'Chivo', sans-serif; font-weight: 700; font-size: 15px;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 14px rgba(225,6,0,0.35);
    transition: background 0.15s, transform 0.1s;
}
.hf-btn-primary:hover { background: var(--f1-red-light); }
.hf-btn-primary:active { transform: translateY(1px); }
.hf-btn-primary.is-disabled,
.hf-btn-primary[aria-disabled="true"] {
    opacity: 0.5; cursor: not-allowed; pointer-events: none;
    box-shadow: none;
}
```

Add to `:root` if not already present:

```css
:root {
    /* …existing tokens… */
    --gold:   #FBBF24;
    --silver: #C0C0C8;
    --bronze: #CD7F32;
}
```

---

## 7 · Updated acceptance criteria

These **replace** AC-RACE-04 through AC-RACE-08 in §7.5 of `claude-design-system-v1.3.0.md`.

- [ ] **AC-BET-01** — Modal opens on top of the race detail page; the page is visible behind the overlay (60% black + 4px blur). Body is scroll-locked while the modal is open.
- [ ] **AC-BET-02** — Modal closes via: ✕ button, "Annuller" button, overlay click (but **not** clicks inside the modal card), `Esc` key. All four paths restore body scroll.
- [ ] **AC-BET-03** — On XS/SM (<768px), modal fills the viewport (no border-radius, no margins, no visible overlay behind). On MD+ (≥768px), modal is centered with `max-width: 560px`, 16px gap between the two cards, 60% black + 4px blur overlay behind.
- [ ] **AC-BET-04** — Race info card shows: 3-letter avatar (derived from `substr(location, 0, 3)`), race name (with "test: " prefix if applicable), location · date · time meta row, stopwatch + "Betting lukker om: NNt NNm NNs" countdown in green Courier Prime tabular-nums, "BETTING ÅBEN" / "BETTING LUKKET" pill in the appropriate colour, ✕ close button.
- [ ] **AC-BET-05** — Three position slot buttons (P1/P2/P3) are stacked above the driver list, each 56px tall, 12px gap between them. P1 badge is gold, P2 silver, P3 bronze.
- [ ] **AC-BET-06** — Tapping a position slot makes it the active target — it gains a 2px red border + 4px red glow. Only one slot is active at a time.
- [ ] **AC-BET-07** — When the modal opens, the first empty slot is auto-activated. If all 3 are filled, no slot is active (taps required to change one).
- [ ] **AC-BET-08** — Driver list shows every driver in the championship, alphabetical by surname, with `#carNumber` (52px fixed width) then surname. List is scrollable with `max-height: min(360px, 40vh)`.
- [ ] **AC-BET-09** — Tapping a driver row assigns that driver to the active slot. If the driver was already assigned to a different slot, that other slot is cleared (move semantics, no duplicates).
- [ ] **AC-BET-10** — After a pick, the active slot auto-advances to the next empty slot (P1→P2→P3). If all 3 are now filled, the last-touched slot stays active so the user can change their mind.
- [ ] **AC-BET-11** — Driver rows show a P1/P2/P3 coloured pill on the right side when assigned. The pill colour matches the slot it's assigned to.
- [ ] **AC-BET-12** — The driver row whose driver is currently assigned to the **active** slot has a subtle red-tinted background (`rgba(225,6,0,0.10)`).
- [ ] **AC-BET-13** — While no slot is active (modal first opened with all slots filled, or after edge cases), driver rows are `disabled` (no hover, no click).
- [ ] **AC-BET-14** — "Gem" button is disabled until all 3 slots are filled. Disabled state: 50% opacity, `cursor: not-allowed`, `aria-disabled="true"`, no shadow.
- [ ] **AC-BET-15** — "Annuller" button is ghost (transparent + border); "Gem" is primary red with floppy-disk icon prefix. Flex ratio is 1:2 (Gem is ~2× wider).
- [ ] **AC-BET-16** — When betting is closed, the betting card (Card 2) is replaced with the read-only "Dit bud" display. Card 1 still shows the race info, but with the red `BETTING LUKKET` pill and the countdown row replaced with status text.
- [ ] **AC-BET-17** — Focus trap: `Tab` cycles within the modal, `Shift+Tab` cycles backward. On open, focus moves to the ✕ button; on close, focus returns to the trigger ("Læg dit bud →" CTA).
- [ ] **AC-BET-18** — Submitting a bet that matches the qualification result shows "Bet kan ikke matche kvalifikationsresultatet" as a banner inside the modal (above the footer actions), in red. The "Gem" button re-enables and the modal stays open.

---

## 8 · Migration from the v1.3.0 spec

If you already implemented the v1.3.0 bet modal:

1. **Delete** the inline driver-picker pattern (the picker that appeared *under* an active position row).
2. **Add** a separate `.hf-driver-list` element below the 3 slots, populated with all drivers.
3. **Move** the assignment logic from "tap a row in the picker that's positioned below slot X" to "tap any driver row, it assigns to whichever slot is active". The active-slot indicator is the only state that ties the two halves together.
4. **Make sure** the page underneath stays rendered — the modal should be a sibling of the page content, appended to `<body>`, not replacing it.

Everything else from v1.3.0 (the overall shell, the bottom bar, the 5-breakpoint responsive system, the typography stack, the email templates, the leaderboard delta backend work) is unchanged.
