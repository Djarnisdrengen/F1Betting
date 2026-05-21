# Handover guide — v1.3.4 (Font toggle)

Step-by-step for taking the v1.3.4 supplement and turning it into a shipped font toggle in the F1Betting PHP repo. ~2–3 hours of work for one developer. Independently shippable on top of any v1.3.x base.

---

## 0 · Before you start

- [ ] **Source files** (in this bundle):
  - `README.md` — the full v1.3.4 spec (same as `claude-design-system-v1.3.4.md` in the design system project)
  - `colors_and_type.css` — canonical token file, includes Stack A + Stack B + 5 utility classes
- [ ] **Target repo:** F1Betting on a fresh branch — suggested `redesign/v1.3.4-font-toggle`.
- [ ] **Baseline:** v1.3.0 / v1.3.2 / v1.3.3 shell + bet modal + toasts must already be in place. v1.3.4 sits on top.
- [ ] **A test browser** with DevTools — you'll verify Network tab requests for AC-FONT-02.

---

## 1 · Read §0 and §1 of `README.md` (10 min)

Two things you need to internalise:

1. **The "either-or, never mix" rule.** Either Stack A loads or Stack B loads. No half-states. No "use Chivo headlines with system body". The toggle swaps the whole stack at once.
2. **The four `--font-*` tokens are the only path.** If you ever write `font-family: 'Chivo'` outside a `--font-*` declaration, you're bypassing the toggle. The CI grep (AC-FONT-04) will fail.

---

## 2 · Patch tokens in `public/assets/css/style.css` (20 min)

Open the live stylesheet. Find the `:root` block at the top.

**Add or update the 4 canonical font tokens.** They probably exist already but may be missing Kalam or Courier Prime, or have Patrick Hand in the fallback:

```css
:root {
    /* …existing tokens… */

    --font-display: 'Chivo',         system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    --font-body:    'Manrope',       -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    --font-accent:  'Kalam',         cursive;
    --font-mono:    'Courier Prime', ui-monospace, monospace;

    /* Short aliases — kept for backwards compat. New code uses --font-*. */
    --display: var(--font-display);
    --body:    var(--font-body);
    --accent:  var(--font-accent);
    --mono:    var(--font-mono);
}
```

**Verify the Google Fonts `@import`** at the top includes all 4 families. If it only covers Chivo + Manrope, replace with:

```css
@import url('https://fonts.googleapis.com/css2?family=Chivo:wght@400;500;600;700;800;900&family=Manrope:wght@400;500;600;700&family=Kalam:wght@300;400;700&family=Courier+Prime:ital,wght@0,400;0,700;1,400&display=swap');
```

**Append the Stack B override.** Anywhere after `:root`:

```css
/* Font toggle Stack B — system fonts only, zero Google Fonts requests. */
body.font-system {
    --font-display: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-body:    system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-accent:  system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-mono:    ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
```

Because the short aliases (`--display`, etc.) are declared with `var(--font-*)`, they inherit the swap automatically. No second override needed.

---

## 3 · Sweep hardcoded font-family literals (30–45 min)

Run the grep audit:

```bash
grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier|Patrick Hand|Special Elite)" public/assets/ \
  | grep -v -- "--font-"
```

For each hit, replace the literal with the right token:

| If the literal is… | …replace with |
|---|---|
| `'Chivo', sans-serif` (or similar) | `var(--font-display)` |
| `'Manrope', sans-serif` | `var(--font-body)` |
| `'Kalam', cursive` | `var(--font-accent)` |
| `'Courier Prime', ui-monospace, monospace` | `var(--font-mono)` |
| Anything with Patrick Hand or Special Elite | **Delete** — those are wireframe-only |

Re-run the grep. **It must return zero hits before you continue.**

Also sweep any inline styles in PHP / Twig templates (e.g. `<div style="font-family: 'Chivo'...">`) — same rule, replace with `var(--font-*)`.

---

## 4 · Append the 5 utility classes to `style.css` (10 min)

Drop these at the bottom of `public/assets/css/style.css` (or wherever you keep label utilities):

```css
/* All-caps pill labels — "BETTING ÅBEN", "DIG", "PERFECT" */
.label-badge {
    font-family: var(--font-display);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

/* Position badges, driver numbers — P1/P2/P3, #23 */
.label-position {
    font-family: var(--font-display);
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.01em;
}

/* Editorial lede after a page H1 — Kalam handwriting */
.label-lede {
    font-family: var(--font-accent);
    font-weight: 400;
    font-size: 1.125rem;
    line-height: 1.45;
    color: var(--text-secondary);
}

/* Typewriter mono-tabular — Courier Prime in Stack A, ui-monospace in Stack B.
   Use for countdowns, prediction strings, timestamps, code, masked passwords. */
.label-mono {
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    letter-spacing: 0.01em;
}

/* Version chip — Courier Prime, smaller, on a muted pill background. */
.label-version {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 0.625rem;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    letter-spacing: 0.02em;
}
```

Then optionally **refactor existing component CSS to use these classes** where it makes sense (e.g. `.hf-footer .v` becomes `.label-version`). Not strictly required for the toggle to work — `var(--font-mono)` direct reference works just as well — but consolidates the type system.

---

## 5 · Wire the toggle handler in `header.php` (10 min)

Add alongside the existing `toggle_theme` and `toggle_lang` handlers, **before any output**:

```php
if (isset($_GET['toggle_font'])) {
    $newFont = ($_COOKIE['font_stack'] ?? 'editorial') === 'editorial' ? 'system' : 'editorial';
    setcookie('font_stack', $newFont, time() + 31536000, '/', '', true, true);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$fontStack = $_COOKIE['font_stack'] ?? 'editorial';
```

Update the `<body>` tag wherever it's rendered (likely also in `header.php`):

```php
<body class="<?= escape($theme) ?> font-<?= escape($fontStack) ?>">
```

Only `font-system` is meaningful CSS-wise; `font-editorial` is the default state and matches the unprefixed `:root`.

---

## 6 · Update the Font cell in `bottom_bar.php` (5 min)

Replace the stub "Aa / FONT" cell with the live version:

```php
<a href="?toggle_font=1" class="hf-bb-item">
    <div class="hf-bb-icon" style="font-family: var(--font-display);">Aa</div>
    <span><?= $fontStack === 'editorial' ? 'EDIT' : 'SYS' ?></span>
</a>
```

The label tells the user the current stack name, matching the THEME / DANSK / JESPE pattern of the other 3 cells.

---

## 7 · Add the AC-FONT-04 grep audit to CI (15 min)

Add a CI step (GitHub Actions, GitLab CI, Husky pre-commit, whatever you use):

```bash
#!/bin/bash
# .ci/font-family-audit.sh

set -e

HITS=$(grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier Prime|Patrick Hand|Special Elite)" public/ \
       2>/dev/null | grep -v -- "--font-" || true)

if [ -n "$HITS" ]; then
    echo "✗ AC-FONT-04 violation: hardcoded brand font-family outside --font-* variable:"
    echo "$HITS"
    exit 1
fi

echo "✓ AC-FONT-04 clean"
exit 0
```

Make it executable, wire it into the lint/test pipeline.

---

## 8 · Verify the 5 acceptance criteria (15 min)

In staging, work through each AC:

- [ ] **AC-FONT-01** — Tap the "Aa" cell. UI flips. Tap again. Flips back. Refresh the page. State persists. Label below the cell shows EDIT or SYS to match.
- [ ] **AC-FONT-02** — Open DevTools → Network → filter to "Font". Reload the page in Stack B (`body.font-system`). Should be **zero** requests to `fonts.gstatic.com` or `fonts.googleapis.com`. Disable cache when testing.
- [ ] **AC-FONT-03** — Spot-check 6 pages in both stacks: Home, Race detail, Bet modal (open it), Leaderboard, Profile, Login. No invisible text, no broken alignments. The Kalam lede becomes sans in Stack B (intentional). The Courier countdown becomes `ui-monospace` in Stack B (still tabular).
- [ ] **AC-FONT-04** — Run the CI script. Returns 0.
- [ ] **AC-FONT-05** — Open DevTools → Elements → click any styled element. Computed `font-family` should resolve to the full stack (e.g. `Chivo, system-ui, …`), never just `Chivo, sans-serif`.

---

## 9 · Merge checklist

- [ ] All 5 ACs ticked in the PR description.
- [ ] Screenshots: 3 pages × 2 stacks = 6 screenshots, showing the Home / Race / Profile in EDIT and SYS modes.
- [ ] DevTools Network screenshot showing zero font requests in Stack B.
- [ ] Grep output showing zero AC-FONT-04 violations.
- [ ] `CHANGELOG.md` entry in F1Betting repo matches v1.3.4.
- [ ] Footer version chip on the live site bumped to `v1.3.4`.

---

## 10 · If something goes wrong

| Symptom | Most likely cause | Fix |
|---|---|---|
| Toggle button does nothing | `toggle_font` handler placed after output started | Move it above any `<?php` echo / HTML output in `header.php` |
| Page flickers / wrong font on first paint | Cookie read after `<head>` | Read cookie before any output; pass to `<body>` class |
| Stack B still loads Google Fonts | `@import` in CSS always loads regardless of `body.font-system` | Acceptable — the fonts download but aren't *used*. To zero requests, lazy-load the `@import` via JS only when stack is editorial |
| Kalam still showing in Stack B | A component has `font-family: 'Kalam'` literal | Run AC-FONT-04 grep, sweep the hit |
| Countdown changes width digit-by-digit in Stack B | `tabular-nums` missing on that selector | Add `font-variant-numeric: tabular-nums` — most monos default to non-tabular |

For anything else, re-read §0 of `README.md` (the spec). The four-token rule answers most questions.

---

## What's after v1.3.4

Likely v1.4.0 is the next milestone — minor bump, probably adds a new palette (Clubhouse warm) toggle similar to font-stack, or extends the toast system with action buttons. The font infrastructure shipped here is the template for that work: tokens + override class + bottom-bar cell + AC IDs.
