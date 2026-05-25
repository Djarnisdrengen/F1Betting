# Plan: Change driver selection to 3 dropdown fields

## Context
The current bet.php and edit_bet.php use a custom interactive modal UI: three position "slots" (P1/P2/P3 buttons) plus a scrollable list of driver buttons, wired together by `bet-modal.js`. The user wants to replace the slot+list picker with three standard `<select>` dropdowns — simpler, faster to use, and removes the JS-heavy slot/pick interaction entirely.

---

## Files to change

| File | What changes |
|---|---|
| `public/bet.php` | Replace slots + driver list + hidden form with 3 visible `<select>` fields inside a real `<form>` |
| `public/edit_bet.php` | Same |
| `public/assets/js/bet-modal.js` | Remove all slot/driver-pick logic; keep close/overlay/Esc/focus-trap; add simple `change` listener to enable Save when all 3 selects have a value; run enable-check on `DOMContentLoaded` so Save starts enabled when selects are pre-populated (edit flow) |
| `public/assets/css/style.css` | Remove `.hf-slots`, `.hf-slot*`, `.hf-driver-list`, `.hf-driver-row*`, `.hf-driver-pill*` rules; add `.hf-bet-selects` layout for the 3 select rows |
| `tests/e2e/04-betting.spec.js` | Replace slot/driver-list selectors with `selectOption` calls; replace `[data-link="saveBet"]` with `#save-btn` |

---

## Implementation

### 1. `bet.php` — replace hf-bet-controls section

Remove the `window.driversById` and `window.betL10n` inline `<script>` blocks (no longer needed by JS).  
Keep `window.betPostBack` — no longer needed either; post-back is now handled server-side via PHP `selected` attributes.  
Remove the separate hidden `<form id="bet-form">` at the bottom.

Replace the `.hf-bet-controls` `<section>` with a `<form>` element:

```html
<form class="hf-bet-controls" method="POST"
      action="bet.php?race=<?= urlencode($raceId) ?>&return=<?= urlencode($returnParam) ?>">
    <?= csrfField() ?>

    <?php if ($error): ?>
        <div class="alert alert-error">...</div>
    <?php endif; ?>

    <div class="hf-bet-selects">
        <?php foreach ([1 => 'p1', 2 => 'p2', 3 => 'p3'] as $pos => $key): ?>
        <div class="hf-bet-select-row">
            <span class="hf-slot-badge pos-<?= $pos ?>">P<?= $pos ?></span>
            <select name="<?= $key ?>" class="form-select hf-bet-select" required
                    aria-label="P<?= $pos ?> — <?= $pts[$key] ?> pts">
                <option value=""><?= t('select_driver') ?></option>
                <?php foreach ($drivers as $d): ?>
                <option value="<?= escape($d['id']) ?>"
                    <?= (($_POST[$key] ?? '') === $d['id']) ? 'selected' : '' ?>>
                    <?= driverLastName($d) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="hf-slot-pts"><?= $pts[$key] ?> pts</span>
        </div>
        <?php endforeach; ?>
    </div>

    <footer class="hf-bet-actions">
        <a href="<?= escape($returnTo) ?>" class="hf-btn-ghost"><?= t('cancel') ?></a>
        <button type="submit" class="hf-btn-primary" id="save-btn" disabled>
            <i class="fas fa-floppy-disk"></i> <?= t('save') ?>
        </button>
    </footer>
</form>
```

### 2. `edit_bet.php` — same pattern

Post-back pre-selection uses `$curP1`, `$curP2`, `$curP3` (already computed at line 74–76).  
Form action: `edit_bet.php?id=<?= urlencode($betId) ?>`.

### 3. `bet-modal.js` — simplify to ~40 lines

Keep:
- `closeModal()` + overlay click handler
- Escape key handler
- `DOMContentLoaded`: focus `.hf-bet-close`, focus trap

Add:
- `checkSelects()` helper: reads all 3 `select[name]` values; enables `#save-btn` when all non-empty, disables otherwise.
- On `change` of any `.hf-bet-select`: call `checkSelects()`.
- On `DOMContentLoaded`: call `checkSelects()` immediately — this ensures Save starts **enabled** on `edit_bet.php` where all 3 selects are pre-populated, and starts **disabled** on `bet.php` where they are empty.

Remove:
- `activeSlot`, `bet` state object
- `activateSlot` click handler
- `pickDriver` click handler  
- `saveBet` click handler (button is now `type="submit"`)
- `render()` function entirely

### 4. `style.css` — swap slot/list CSS for select layout

**Remove** (lines ~2686–2765):
- `.hf-slots`, `.hf-slot`, `.hf-slot:hover`, `.hf-slot.is-empty`, `.hf-slot.is-active`, `.hf-slot-name`
- `.hf-driver-list`, `.hf-driver-list::-webkit-scrollbar*`
- `.hf-driver-row`, `.hf-driver-row:*`, `.hf-driver-num`, `.hf-driver-name`
- `.hf-driver-pill` and its variants

**Keep** (do NOT remove):
- `.hf-slot-badge` and its `.pos-1/.pos-2/.pos-3` colour rules — reused for the P1/P2/P3 badge in the new select rows
- `.hf-slot-pts` (already defined at line 2719 with correct design-system values) — reused as-is in the new layout
- `.hf-btn-primary` and `.hf-btn-ghost` rules — unchanged

**Add** after `.hf-bet-controls`:
```css
.hf-bet-selects { display: flex; flex-direction: column; gap: 16px; }
.hf-bet-select-row { display: flex; align-items: center; gap: 12px; }
.hf-bet-select { flex: 1; }
```

Note: `form-select` is already themed to the design system at line 442 (`--bg-secondary`, `--border-color`, F1-red focus ring). No additional overrides needed.

### 5. `tests/e2e/04-betting.spec.js` — update selectors

**"place a bet" test** — replace driver-list clicks with `selectOption`:
```js
// Remove:
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[0].id}"]`);
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[1].id}"]`);
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[2].id}"]`);
await sharedPage.click('[data-link="saveBet"]');

// Replace with:
await sharedPage.selectOption('select[name="p1"]', seedData.drivers[0].id);
await sharedPage.selectOption('select[name="p2"]', seedData.drivers[1].id);
await sharedPage.selectOption('select[name="p3"]', seedData.drivers[2].id);
await sharedPage.click('#save-btn');
```

**"edit a bet" test** — remove `activateSlot` clicks, use `selectOption` directly:
```js
// Remove:
await sharedPage.click('[data-link="activateSlot"][data-pos="1"]');
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[2].id}"]`);
await sharedPage.click('[data-link="activateSlot"][data-pos="2"]');
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[1].id}"]`);
await sharedPage.click('[data-link="activateSlot"][data-pos="3"]');
await sharedPage.click(`.hf-driver-row[data-driver-id="${seedData.drivers[0].id}"]`);
await sharedPage.click('[data-link="saveBet"]');

// Replace with:
await sharedPage.selectOption('select[name="p1"]', seedData.drivers[2].id);
await sharedPage.selectOption('select[name="p2"]', seedData.drivers[1].id);
await sharedPage.selectOption('select[name="p3"]', seedData.drivers[0].id);
await sharedPage.click('#save-btn');
```

---

## Reuse notes

- `driverLastName($d)` (functions.php:237) — already used in the slot UI, reuse in option labels
- `t('select_driver')` — existing translation key, use as the empty default option
- `validateBetCombination()` — unchanged; still handles duplicate-driver and existing-combo validation server-side
- `fetchDrivers($db)` — unchanged

---

## Verification

1. Deploy to test (`npm run deploy:test`)
2. Visit `bet.php?race=<open-race-id>` — confirm 3 dropdowns render with all drivers; Save button is disabled
3. Select drivers in all 3 positions — confirm Save button enables
4. Submit without selecting all 3 — confirm browser `required` blocks submission
5. Select the same driver in two positions and submit — confirm server returns a validation error and dropdowns retain selected values (post-back)
6. Select 3 distinct drivers and submit — confirm redirect to `index.php?success=bet_placed`
7. Visit `edit_bet.php?id=<bet-id>` — confirm all 3 dropdowns show saved values **and Save button is already enabled** (DOMContentLoaded check)
8. Run `npm run test:e2e:test` and confirm `04-betting.spec.js` ("place a bet", "edit a bet") passes with the updated selectors
