# Handoff: Test-environment banner · v2.4.1 (refined)

> **Design System version:** `v2.4.0` · 2026-07-05 · see [CHANGELOG.md](./CHANGELOG.md)
>
> **Revision v2.4.1** · 2026-07-05 · refined after architecture review against the live codebase (per §0 rule: "the live implementation's class names and behaviour win"). Original v2.4.0 assumptions that did not match the code are corrected below; the visual design in `Test Banner.html` is unchanged and remains the reference.
>
> **Scope: exactly one thing** — add a **sticky, permanent "This is a test website" banner** directly below the top navigation, shown **only in the test environment** (hpovlsen.dk). Nothing else is in scope. No page, no other component, no token, font, theme, or existing-class change.

---

## 0 · Ground rules (read first, non-negotiable)

- **This is additive only.** You add: one guarded banner element, one block of new CSS, and one i18n key. You do **not** modify, restyle, rename, or remove any existing class, token, font, or rule.
- **Do not touch the existing header.** The real header is `<header class="hf-top">` (`public/includes/header.php:94-105`) — `position: sticky; top: 0; z-index: 20; height: 56px`. It stays exactly as it is. *(v2.4.0 referenced `<header class="header">` with `z-index: 100`; that markup does not exist — the `.header` CSS rule at `style.css:132` is legacy and unused.)*
- **No wrapper element.** *(v2.4.0's `.sticky-topbar` wrapper is dropped.)* A wrapper at `z-index: 100` would stack above the mobile drawer (`.hf-drawer`, `position: fixed; top: 56px; z-index: 30`) and the banner would poke through the open menu. Instead the banner itself is sticky at `top: 56px` — the same header-height constant the drawer already uses (`style.css:1633`) — with `z-index: 20` so the drawer covers it.
- **The environment guard is mandatory, server-side, and uses `APP_ENV`** — not `$_SERVER['HTTP_HOST']`, which is client-controlled. `config.test.php` defines `APP_ENV = 'test'`, `config.live.php` defines `'live'`; this is the established gating pattern (`public/tools/test-seed.php:14`, `public/forgot_password.php:19`). The banner must be impossible to render on formula-1.dk regardless of request headers.
- **Colours are intentionally theme-independent.** The banner must stay loud on dark, light, and clubhouse themes, so it uses fixed hazard colours (amber `#ffcf00` / near-black `#1a1a00`), **not** design-system tokens. This is deliberate — do not "fix" it to use `var(--...)`.
- **No `role="alert"`.** *(Removed from v2.4.0.)* A permanent banner is not a live-region event; `role="alert"` makes screen readers announce it assertively on every page load.

---

## 1 · What it is

A full-width horizontal strip that reads **"Dette er en testhjemmeside"** (EN: "This is a test website"), rendered as a yellow-on-black hazard band with a solid yellow plate behind the text for legibility. It sits **immediately below the top navigation** and is **sticky/permanent**: it pins to the top together with the header and never scrolls away, and it cannot be dismissed. It renders **only when `APP_ENV === 'test'`**.

See `Test Banner.html` in this folder for the exact visual across dark, light, and clubhouse themes, and scroll any panel to confirm the sticky behaviour.

---

## 2 · Markup — `public/includes/header.php`

`header.php` is the single shared header include (all user pages and `admin.php` use it), so one insertion covers every page. Insert the guarded banner **immediately after `</header>`** (line 105), before `<nav class="hf-drawer">`:

```php
</header>

<?php if (defined('APP_ENV') && APP_ENV === 'test'): ?>
<!-- Test-environment banner (v2.4.1) — only ever rendered when APP_ENV === 'test' -->
<div class="test-banner">
    <span class="test-banner-plate">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <?= t('test_site_banner') ?>
    </span>
</div>
<?php endif; ?>

<nav class="hf-drawer" id="hf-drawer">
```

- The `<header class="hf-top">…</header>` block is untouched — its inner markup stays byte-identical.
- Font Awesome (`fas fa-triangle-exclamation`) is already loaded site-wide; no new icon dependency.
- The guard is **not optional** (site-owner decision 2026-07-05): the banner is only ever allowed on hpovlsen.dk.

---

## 3 · CSS — append to `public/assets/css/style.css`

Paste at the end of the file (repo convention: additive, versioned section comments). Every selector below is **new**; nothing overrides an existing rule.

```css
/* ============================================================
   v2.4.1 — Test-environment banner. Additive only.
   Fixed hazard colours on purpose — must stay loud on every theme.
   Sticky at top:56px = .hf-top height (same constant .hf-drawer uses);
   z-index 20 keeps it level with the header and *below* the drawer (30).
   ============================================================ */

.test-banner {
    position: sticky;
    top: 56px;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 9px 16px;
    color: #1a1a00;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-weight: 800;
    font-size: 14px;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: center;
    border-top: 2px solid rgba(0,0,0,.55);
    border-bottom: 3px solid rgba(0,0,0,.55);
    /* hazard stripes */
    background-color: #ffcf00;
    background-image: repeating-linear-gradient(45deg, #1a1a00 0 14px, transparent 14px 28px);
}

/* Solid plate keeps the message readable over the stripes */
.test-banner .test-banner-plate {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    background: #ffcf00;
    padding: 3px 16px;
    border-radius: 4px;
    box-shadow: 0 0 0 2px #1a1a00;
}
.test-banner i { font-size: 15px; }

@media (max-width: 480px) {
    .test-banner { font-size: 11.5px; padding: 7px 10px; }
    .test-banner .test-banner-plate { padding: 3px 10px; gap: 7px; }
}

@media print {
    .test-banner { display: none; }
}
```

> **Why `var(--font-mono, …)`?** Only the font family reads the existing mono token (`style.css:11`) so the banner matches site typography; it falls back to a system mono if the token is absent. This is the *one* token reference and it is safe — no colour/theme token is used, by design.

---

## 4 · i18n

Add one key in both languages in `public/lang/user.php` (the `da` array and the `en` array):

| Key                | DA                            | EN                        |
|--------------------|-------------------------------|---------------------------|
| `test_site_banner` | `Dette er en testhjemmeside`  | `This is a test website`  |

---

## 5 · Acceptance criteria

- [ ] **AC-TB-01** — On the test environment, a banner reading "Dette er en testhjemmeside" (EN: "This is a test website") renders on every page, **directly below the top navigation**.
- [ ] **AC-TB-02** — The banner is **sticky**: on scroll it stays pinned immediately beneath the header and never scrolls out of view. The header and banner move as one pinned unit.
- [ ] **AC-TB-03** — The banner is **highly visible in all three themes** (dark, light, clubhouse) — verified against `Test Banner.html`. Contrast of the plate text ≥ 7:1. *(Manual check — no a11y tooling in the repo.)*
- [ ] **AC-TB-04** — No existing rule, class, token, font, or the existing `.hf-top` declaration was modified. The header's inner markup is byte-identical to before.
- [ ] **AC-TB-05** — The message stays on **one line** inside the plate at ≥320px width; the hazard band may wrap around it but the plate text never breaks.
- [ ] **AC-TB-06** — No horizontal scroll introduced at 320px. On mobile the open nav drawer (`.hf-drawer`, z-index 30) covers the banner (z-index 20) — no overlap or z-fighting.
- [ ] **AC-TB-07** — The banner appears on hpovlsen.dk and is **absent** on formula-1.dk. Enforced by the `APP_ENV === 'test'` guard and asserted by the live smoke E2E suite.

See [TEST-PLAN.md](./TEST-PLAN.md) for the test-manager review: automated coverage mapping, test data, and manual checks.

---

## 6 · Notes / edge cases

- **Sticky mechanics:** `.hf-top` pins at `top: 0` (height 56px); the banner pins at `top: 56px`, so the pair move as one pinned unit with no wrapper and no JS.
- **Mobile menu:** the drawer (`.hf-drawer`, fixed `top: 56px; z-index: 30`) opens exactly over the banner's band and covers it (30 > 20). Do not raise the banner above 30.
- **Print:** `@media print` hides the banner (included in §3).
- **Live safety:** the guard means live (`APP_ENV === 'live'`) never emits the banner markup at all — there is nothing to hide with CSS and no Host-header spoof can summon it.

---

## 7 · Implementation time

~15 minutes: drop in the guarded banner element, paste the CSS, add the i18n key, run AC-TB-01 → AC-TB-06 across the three themes at mobile + desktop; AC-TB-07 is asserted automatically by the live smoke suite on deploy.
