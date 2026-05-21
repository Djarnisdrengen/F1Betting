# Handoff: Font toggle (Stack A / Stack B) · v1.3.4

> **Design System version:** `v1.3.4` · 2026-05-21 · see [CHANGELOG.md](./CHANGELOG.md)
>
> Focused supplement to v1.3.3. Three changes:
> 1. **The 4-font stack is now fully tokenised + toggleable.** No font-family literal anywhere in the codebase; everything goes through CSS variables.
> 2. **AC-FONT-01 is no longer a stub.** The bottom-bar "Aa" cell swaps Stack A (Editorial: Chivo + Manrope + Kalam + Courier Prime) for Stack B (System: native OS fonts, zero Google Fonts).
> 3. **AC-FONT-04 grep audit** — a hard gate that fails the build if any component CSS hardcodes a brand-font name outside a `--font-*` variable.
>
> Everything else in v1.3.0 / v1.3.2 / v1.3.3 stands.

---

## 1 · What's broken today

Before this release, the design system had two concurrent failures that conspired to make the font toggle impossible:

1. **Token name mismatch.** Canonical `colors_and_type.css` declared `--font-display` / `--font-body` / `--font-accent` / `--font-mono`. The hi-fi component sheet `hifi/style.css` declared short aliases (`--display` / `--body` / `--accent` / `--mono`) that diverged in their fallback ladders — `--accent` still listed Patrick Hand (the wireframe-only font we banned in §0 of the v1.3.3 doc).

2. **Hardcoded font-family literals.** `ui_kits/website/index.html` had 7 rules with `font-family: 'Chivo', sans-serif;` baked in. The live `public/assets/css/style.css` has at least 5 more. Any rule that hardcodes `'Chivo'` bypasses whatever token system is in place — the toggle would only flip headlines and body text, leaving 30%+ of the UI on Chivo regardless of state.

This release fixes both, and adds a grep audit so the build catches the next regression.

---

## 2 · Token alignment (alias the short names)

In `public/assets/css/style.css` (and any other file that needs the short aliases for backwards compatibility), add this block immediately after the canonical token declarations:

```css
:root {
    /* Canonical type tokens — these are the ones the font toggle flips. */
    --font-display: 'Chivo',         system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    --font-body:    'Manrope',       -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    --font-accent:  'Kalam',         cursive;
    --font-mono:    'Courier Prime', ui-monospace, monospace;

    /* Short aliases — backwards compat for any rule using the short name.
       New code should use --font-* directly. */
    --display: var(--font-display);
    --body:    var(--font-body);
    --accent:  var(--font-accent);
    --mono:    var(--font-mono);
}
```

Patrick Hand has been removed from the `--font-accent` fallback. If Kalam fails to load, browsers fall to generic `cursive`. See §0 of the v1.3.3 handoff for the policy.

---

## 3 · Stack B override

Add this block to `public/assets/css/style.css` (or wherever the toggle's target lives). It defines what every token resolves to when `<body>` has the `font-system` class:

```css
/* Font toggle Stack B — system fonts only, zero Google Fonts requests.
   AC-FONT-01: the bottom-bar Aa cell toggles `font-system` on <body>. */
body.font-system {
    --font-display: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-body:    system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-accent:  system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-mono:    ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
```

Because the short aliases (`--display`, etc.) are declared with `var(--font-*)`, they inherit the swap automatically. No second override needed.

---

## 4 · PHP toggle handler

Mirror the existing `toggle_theme` and `toggle_lang` pattern in `public/includes/header.php`:

```php
// Place alongside existing toggle handlers, BEFORE any output:
if (isset($_GET['toggle_font'])) {
    $newFont = ($_COOKIE['font_stack'] ?? 'editorial') === 'editorial' ? 'system' : 'editorial';
    setcookie('font_stack', $newFont, time() + 31536000, '/', '', true, true);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$fontStack = $_COOKIE['font_stack'] ?? 'editorial';
```

On the `<body>` element:

```php
<body class="<?= escape($theme) ?> font-<?= escape($fontStack) ?>">
```

(Only the `font-system` class is meaningful; `font-editorial` is the default and matches the unprefixed `:root`.)

In `public/includes/bottom_bar.php`, update the Font cell:

```php
<a href="?toggle_font=1" class="hf-bb-item">
    <div class="hf-bb-icon" style="font-family: var(--font-display);">Aa</div>
    <span><?= $fontStack === 'editorial' ? 'EDIT' : 'SYS' ?></span>
</a>
```

The label tells the user the current stack name, matching the THEME / DANSK / JESPE pattern of the other 3 cells.

---

## 5 · AC-FONT-04 grep audit (the build gate)

This is what enforces the rule "no `font-family:` to a literal brand name outside `--font-*`". Add as a CI step or pre-commit hook:

```bash
# Fail the build if any component CSS hardcodes a brand font name.
# Allowed: rules inside a CSS variable declaration (e.g. `--font-display: 'Chivo'...`).
# Forbidden: rules like `font-family: 'Chivo', sans-serif;` on a component selector.

set -e
HITS=$(grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier Prime|Patrick Hand|Special Elite)" public/ \
       | grep -v -- "--font-" \
       | grep -v "^Binary file")

if [ -n "$HITS" ]; then
    echo "✗ AC-FONT-04 violation: hardcoded brand font-family found outside --font-* variable declaration:"
    echo "$HITS"
    exit 1
fi
echo "✓ AC-FONT-04 clean"
```

For local development:

```bash
# Manual check before committing:
grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier)" public/assets/ \
  | grep -v -- "--font-"
# Should return nothing.
```

The current state of `public/assets/css/style.css` has at least 5 hits that need sweeping — see §6.

---

## 6 · Migration sweep — files to touch in F1Betting repo

Apply these find/replace operations:

| File | From | To | Count |
|---|---|---|---|
| `public/assets/css/style.css` | `font-family: 'Chivo', sans-serif;` | `font-family: var(--font-display);` | ~5 |
| `public/assets/css/style.css` | `font-family: 'Manrope', ...;` | `font-family: var(--font-body);` | ~1 |
| `ui_kits/website/index.html` | (already swept in design-system project — apply same to F1Betting copy if it exists) | | 7 |
| `public/includes/header.php` | n/a — add `toggle_font` handler | (see §4) | new |
| `public/includes/bottom_bar.php` | Font cell label `FONT` | `EDIT` / `SYS` (dynamic) | 1 |
| `<body>` tag (wherever rendered) | `class="$theme"` | `class="$theme font-$fontStack"` | 1 |

After the sweep, run the AC-FONT-04 grep — it must return zero hits before the PR can merge.

---

## 7 · Canonical utility classes

`colors_and_type.css` ships 5 utility classes. Where the hi-fi designs hand-roll a `font-family:` declaration, swap to one of these:

| Class | Wraps | Use for |
|---|---|---|
| `.label-badge` | `var(--font-display)` + uppercase | Pill labels: "BETTING ÅBEN", "DIG", "PERFECT" |
| `.label-position` | `var(--font-display)` + tabular-nums | Position badges, driver numbers (P1, #23) |
| `.label-lede` | `var(--font-accent)` | Kalam handwriting lede after a page H1 |
| `.label-mono` | `var(--font-mono)` + tabular-nums | Countdowns, prediction strings, timestamps, code |
| `.label-version` | `var(--font-mono)` | The `v1.3.4` chip in footers |

The 5 classes route every typographic decision through one of the four tokens. If you find yourself wanting to write `font-family:` directly, write a 6th utility class instead, and add it to the table above.

---

## 8 · Acceptance criteria

These **replace** AC-FONT-01 from v1.3.0 §7.3 and **add** to §7.3.

- [ ] **AC-FONT-01** — Tapping the "Aa" cell in the bottom bar toggles between Stack A (Editorial: Chivo + Manrope + Kalam + Courier Prime) and Stack B (System: native OS fonts). State persists across page navigation via the `font_stack` cookie. The cell label below shows "EDIT" (Stack A) or "SYS" (Stack B).
- [ ] **AC-FONT-02** — In Stack B, the DevTools Network tab shows **zero** requests to `fonts.gstatic.com` or `fonts.googleapis.com` on page load. Verify with cache disabled.
- [ ] **AC-FONT-03** — Every page renders correctly in both stacks. Spot-check Home, Race detail, Bet modal (open), Leaderboard, Profile, Login in both. No invisible text, no broken alignments.
- [ ] **AC-FONT-04** — Running `grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier Prime|Patrick Hand|Special Elite)" public/assets/ | grep -v -- "--font-"` returns **zero hits**. If new code adds a hit, the CI step fails the build.
- [ ] **AC-FONT-05** — The `--display`, `--body`, `--accent`, `--mono` short aliases all resolve to their `--font-*` canonical equivalents. Verify by inspecting any styled element in DevTools — the computed `font-family` should match Stack A or Stack B per the toggle state, never `Chivo, sans-serif` literally.

---

## 9 · What this release does NOT include

- **No new fonts.** Stack A is the same 4 fonts from v1.2.3. Stack B uses only OS fonts already on the user's machine.
- **No tokens added.** Only `body.font-system` declarations + 5 utility class declarations are appended.
- **No visual changes to anything in Stack A.** If a user never toggles, they see the exact same UI as v1.3.3.
- **No accessibility / browser matrix changes.** The 5-breakpoint system, focus rings, browser matrix (Safari/Chrome/Firefox/Edge), perf targets — all unchanged.

This is purely additive infrastructure. The visible feature is the working font toggle; the invisible feature is that every future component automatically inherits the toggle without the implementer remembering to wire it up.
