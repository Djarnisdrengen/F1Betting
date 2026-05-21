# Handoff: Brand stack simplification · v2.0.0

> **Design System version:** `v2.0.0` · 2026-05-21 · see [CHANGELOG.md](./CHANGELOG.md)
>
> **Breaking change.** Two brand fonts removed from the system. Display and body now fall to the OS UI font. The brand identity is carried entirely by two accent surfaces: handwriting (Kalam) and typewriter (Courier Prime). Everything else in v1.3.0 / v1.3.2 / v1.3.3 stands.

This is a MAJOR bump because removing brand fonts changes the visual identity of the entire site. Consumers who relied on the prior display + body fonts will see different glyphs. No CSS classes are renamed, no tokens are removed — only the **values** of `--font-display` and `--font-body` change. The token names and utility classes are stable.

---

## 1 · What changes

### Stack A — Editorial (default, post-v2.0.0)

```css
@import url('https://fonts.googleapis.com/css2?family=Kalam:wght@300;400;700&family=Courier+Prime:ital,wght@0,400;0,700;1,400&display=swap');

:root {
    --font-display: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-body:    system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-accent:  'Kalam',         cursive;
    --font-mono:    'Courier Prime', ui-monospace, monospace;
}
```

| Token | Resolves to | Use for |
|---|---|---|
| `--font-display` | System UI | H1s, button labels, badges, big numerals, position chips |
| `--font-body` | System UI | All paragraph copy, descriptions, default UI text |
| `--font-accent` | Kalam (handwriting) | Page ledes, friendly empty-state copy, "no upcoming races" callouts |
| `--font-mono` | Courier Prime (typewriter) | Timestamps, countdown values, prediction strings, bet-modal countdown, version chips, code, masked passwords, admin tab-count chips |

### Stack B — System-only (font toggle off-state)

```css
body.font-system {
    --font-accent:  system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-mono:    ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
```

Display and body are already system in Stack A — only `--font-accent` and `--font-mono` swap. Stack B loads zero Google Fonts. The toggle is still meaningful (the two accent surfaces lose their character) but the difference is now smaller and more tasteful.

### Fallback policy — unchanged

Either the brand font loads or we fall to `cursive` / `ui-monospace` / `system-ui`. No Patrick Hand, no Comic Sans, no Courier New, no Arial Black. A bad substitute pretends to be the brand and isn't.

---

## 2 · Migration sweep — files to touch in F1Betting repo

Apply these find/replace operations to `public/assets/css/style.css`:

| From | To | Approx count |
|---|---|---|
| `@import url('...Chivo:wght@.. Manrope:wght@.. Kalam:wght@.. Courier+Prime:..')` | `@import url('...Kalam:wght@300;400;700&family=Courier+Prime:ital,wght@0,400;0,700;1,400&display=swap')` | 1 |
| `--font-display: 'Chivo', ...` | `--font-display: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;` | 1 |
| `--font-body: 'Manrope', ...` | `--font-body: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;` | 1 |
| Any rule with `font-family: 'Chivo', sans-serif;` (component selectors, not the variable declaration) | `font-family: var(--font-display);` | ~5 |
| Any rule with `font-family: 'Manrope', sans-serif;` | `font-family: var(--font-body);` | ~1 |

Update the `body.font-system` block in the same file:

```css
/* BEFORE — v1.3.4 */
body.font-system {
    --font-display: system-ui, ...;
    --font-body:    system-ui, ...;
    --font-accent:  system-ui, ...;
    --font-mono:    ui-monospace, ...;
}

/* AFTER — v2.0.0 */
body.font-system {
    --font-accent:  system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-mono:    ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
```

(The `--font-display` and `--font-body` overrides disappear — they're already system in Stack A.)

Sweep every email template too:

| File | From | To |
|---|---|---|
| `public/emails/*.html` | Any `font-family:` with a brand font literal | `font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif;` |

Email templates can't use CSS variables (mail clients drop them), so they stay on the system stack literally. That matches v2.0.0 anyway.

---

## 3 · AC-FONT-04 grep audit — tightened

The audit now also flags `Chivo` and `Manrope` literals as forbidden. They should not appear anywhere in `public/`:

```bash
#!/bin/bash
set -e

HITS=$(grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier Prime|Patrick Hand|Special Elite)" public/ \
       2>/dev/null | grep -v -- "--font-" || true)

if [ -n "$HITS" ]; then
    echo "✗ AC-FONT-04 violation: hardcoded brand font-family outside --font-* variable:"
    echo "$HITS"
    exit 1
fi

# v2.0.0 addition: Chivo and Manrope must not appear anywhere in production code.
LEGACY=$(grep -rE "(Chivo|Manrope)" public/ 2>/dev/null \
         | grep -v "^Binary file" \
         | grep -v -- "--font-" || true)

if [ -n "$LEGACY" ]; then
    echo "✗ AC-FONT-06 violation: Chivo or Manrope referenced in production code (removed in v2.0.0):"
    echo "$LEGACY"
    exit 1
fi

echo "✓ AC-FONT-04 / AC-FONT-06 clean"
```

---

## 4 · Updated acceptance criteria

These **replace** AC-FONT-01 through AC-FONT-05 from v1.3.3 §0.

- [ ] **AC-FONT-01** — Tapping the "Aa" cell in the bottom bar toggles between Stack A and Stack B. State persists across page navigation via the `font_stack` cookie. The cell label below shows "EDIT" (Stack A) or "SYS" (Stack B).
- [ ] **AC-FONT-02** — In Stack B, the DevTools Network tab shows **zero** requests to `fonts.gstatic.com` or `fonts.googleapis.com` on page load. Verify with cache disabled.
- [ ] **AC-FONT-03** — Every page renders correctly in both stacks. The version chip, countdown values, prediction strings stay tabular in Stack B (`ui-monospace`); Kalam-styled ledes lose their handwriting character in Stack B but are still readable.
- [ ] **AC-FONT-04** — Running `grep -rE "font-family:\s*['\"]?(Kalam|Courier Prime|Patrick Hand|Special Elite)" public/assets/ | grep -v -- "--font-"` returns **zero hits**.
- [ ] **AC-FONT-05** — `--display`, `--body`, `--accent`, `--mono` short aliases all resolve to their `--font-*` canonical equivalents.
- [ ] **AC-FONT-06** — `grep -rE "(Chivo|Manrope)" public/` returns **zero hits** in production code (CSS, PHP, templates, emails). Both fonts were removed in v2.0.0 and must not be referenced anywhere.

---

## 5 · What this release does NOT include

- **No layout changes.** Spacing, breakpoints, components, responsive system — all unchanged.
- **No colour changes.** Palette, themes, accents — all unchanged.
- **No new tokens.** Only the resolved values of `--font-display` and `--font-body` change. Token names + utility classes are stable from v1.3.4.
- **No JSX edits required.** Every JSX file already references tokens (`var(--display)`, `var(--font-display)`) — they'll inherit the new resolved values automatically.

---

## 6 · Visual impact (read this before shipping)

v2.0.0 changes the brand voice noticeably:

- **Before (v1.3.x):** Sharp display headlines + tight body — "broadcast graphics package", F1-on-TV vibe.
- **After (v2.0.0):** System headlines + system body + handwriting/typewriter accents — "clubhouse mailing list" vibe. The system UI font picks up Apple's SF Pro, Windows' Segoe UI, Android's Roboto. The character now lives entirely in the Kalam ledes and the Courier Prime numerals.

This is intentional. The brand is one of 10 friends running a season-long bet pool, not a TV broadcaster. The handmade character on the accent surfaces is the brand; everything else gets out of the way.

If a stakeholder pushes back on the loss of Chivo/Manrope, the answer is: those fonts were doing the same work the OS already does for free, and adding a download cost + GDPR friction on every page load for no brand differentiation. The accent fonts (Kalam + Courier Prime) are the brand — they're the parts a reader will remember a week later.

---

## 7 · Migration order

1. **Patch tokens** in `public/assets/css/style.css` (§2). ~10 min.
2. **Sweep hardcoded brand-font literals** with the AC-FONT-04 grep (§3). ~30 min.
3. **Reskin email templates** to the system stack (§2). ~15 min.
4. **Run AC-FONT-04 + AC-FONT-06 audits.** Must be clean before merge. ~5 min.
5. **Bump footer version chip** to v2.0.0 wherever rendered. ~5 min.
6. **Spot-check 6 pages** in both stacks (AC-FONT-03). ~10 min.

Total: ~75 minutes for a clean implementation. Add 30 min if the F1Betting repo has more hardcoded literals than estimated.
