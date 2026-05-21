# Handover guide — v2.0.0 (brand stack simplification)

Step-by-step for taking the v2.0.0 supplement and removing Chivo + Manrope from the F1Betting PHP repo. **~75 minutes of work** for one developer. Breaking visual change — coordinate with the team before merging.

---

## 0 · Before you start

- [ ] **Source files** (in this bundle):
  - `README.md` — the full v2.0.0 spec (same as `claude-design-system-v2.0.0.md` in the design system project)
  - `colors_and_type.css` — canonical token file, post-v2.0.0
- [ ] **Target repo:** F1Betting on a fresh branch — suggested `redesign/v2.0.0-brand-stack`.
- [ ] **Baseline:** v1.3.3 (toasts + bet modal move-feedback) must already be in place. v1.3.4 was an interim doc that never shipped — v2.0.0 supersedes it.
- [ ] **Heads-up to stakeholders.** The visual change is intentional but noticeable. Send a Loom of the before/after to the club before merging so there are no surprises.

---

## 1 · Read §1 and §6 of `README.md` (10 min)

§1 has the new token block — that's what you'll paste into `style.css`.
§6 explains *why* the brand fonts were dropped. Useful if anyone pushes back.

---

## 2 · Patch tokens in `public/assets/css/style.css` (10 min)

Find the `@import url('https://fonts.googleapis.com/css2?...')` line at the top. Replace it with:

```css
@import url('https://fonts.googleapis.com/css2?family=Kalam:wght@300;400;700&family=Courier+Prime:ital,wght@0,400;0,700;1,400&display=swap');
```

Then find the `--font-*` block in `:root` and replace with:

```css
:root {
    /* …existing tokens… */

    --font-display: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-body:    system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-accent:  'Kalam',         cursive;
    --font-mono:    'Courier Prime', ui-monospace, monospace;

    --display: var(--font-display);
    --body:    var(--font-body);
    --accent:  var(--font-accent);
    --mono:    var(--font-mono);
}
```

Find the `body.font-system` override (if present from v1.3.4) and replace with the simpler v2.0.0 version:

```css
body.font-system {
    --font-accent:  system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --font-mono:    ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}
```

Display + body are already system in Stack A; they don't need to be overridden in Stack B.

---

## 3 · Sweep legacy font-family literals (30 min)

Run the grep audit from §3 of the spec:

```bash
grep -rE "font-family:\s*['\"]?(Chivo|Manrope|Kalam|Courier Prime|Patrick Hand|Special Elite)" public/ \
  | grep -v -- "--font-"
```

For each hit, replace the literal with the right token. The legacy mapping (used here only because the strings need to be found in the source code being migrated):

| If the literal references… | …replace the whole `font-family:` value with |
|---|---|
| (the legacy display font) | `var(--font-display)` |
| (the legacy body font) | `var(--font-body)` |
| Kalam | `var(--font-accent)` |
| Courier Prime | `var(--font-mono)` |
| Patrick Hand or Special Elite | **Delete the rule** — those are wireframe-only |

Also check inline styles in PHP templates (`<div style="font-family:...">`) — same rule, same replacements.

---

## 4 · Confirm legacy brand-font names are gone (5 min)

After the sweep, run the v2.0.0 audit (AC-FONT-06):

```bash
grep -rE "(Chivo|Manrope)" public/ 2>/dev/null \
  | grep -v "^Binary file" \
  | grep -v -- "--font-"
```

**Must return zero hits.** If anything remains, finish the sweep before continuing.

Don't forget:
- Email templates in `public/emails/`
- Inline styles in PHP files
- JS files that build `style.cssText` strings
- The CHANGELOG or other docs that reference the old fonts (those can stay — they're historical record, not production)

---

## 5 · Reskin email templates (15 min)

Email clients don't support CSS variables, so email templates can't go through the token system. They stay on a literal system-font stack:

```css
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
```

For each of the 5 templates (invite, password-reset, race-reminder, results-posted, welcome):

1. Replace any `font-family: 'Chivo', sans-serif;` or `font-family: 'Manrope', ...;` with the literal system stack above.
2. Remove any `<link>` to Google Fonts in `<head>`.
3. Verify the email still renders cleanly in Outlook on Windows (the failure case).

---

## 6 · Update the AC-FONT-04 audit in CI (5 min)

If you wired the v1.3.4 grep into CI, update it to include the v2.0.0 AC-FONT-06 check. See §3 of the spec for the full script.

---

## 7 · Bump the footer version chip (5 min)

Wherever the version chip renders (footer partial, login card, email footer):

- Replace `v1.3.x` → `v2.0.0`

---

## 8 · Verify all 6 ACs (10 min)

In staging:

- [ ] **AC-FONT-01** — Toggle the "Aa" cell. Site flips. Persists across navigation.
- [ ] **AC-FONT-02** — DevTools Network in Stack B. Zero `fonts.gstatic.com` requests.
- [ ] **AC-FONT-03** — Spot-check Home / Race detail / Bet modal / Leaderboard / Profile / Login in both stacks. No invisible text.
- [ ] **AC-FONT-04** — Grep returns zero hits for hardcoded brand-font literals outside `--font-*`.
- [ ] **AC-FONT-05** — Short aliases resolve correctly in DevTools Computed pane.
- [ ] **AC-FONT-06** — Grep returns zero hits for legacy brand-font names anywhere in `public/`.

---

## 9 · Merge checklist

- [ ] All 6 ACs ticked in PR.
- [ ] Screenshots: 3 pages × 2 stacks = 6 screenshots showing the new look.
- [ ] DevTools Network screenshot proving zero font requests in Stack B.
- [ ] Loom or screen-recording of the before/after, sent to the club for sign-off.
- [ ] `CHANGELOG.md` entry in F1Betting repo matches v2.0.0.
- [ ] Footer + email + login chips bumped to v2.0.0.

---

## 10 · Rollback plan

If the new look gets rejected:

1. Revert the PR. The change is contained to `style.css` + email templates + the footer chip.
2. JSX/PHP component code is unaffected (it all goes through `var(--font-*)` tokens; reverting the tokens restores the old look without touching any markup).
3. CI audit stays in place — it's still valid policy even if the token values revert.

The rollback is genuinely 1 commit. That's the upside of having every typographic decision go through tokens.
