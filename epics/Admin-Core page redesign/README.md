# Handoff: Admin Core Settings Redesign

## Overview
Redesign of the "Core-indstillinger" (Core Settings) page in the Frederikssund F1 Klub admin area. Goal: more logical grouping of settings, streamlined toggle/checkbox UI, and a save action that's always easy to find.

## About the Design Files
The files in this bundle (`Admin Settings.dc.html`, `screenshot.png`) are **design references built as an HTML prototype** — they show the intended look, structure, and interaction behavior. They are NOT production code to copy as-is. The task is to **recreate this design in the target codebase's existing environment** (the F1Betting PHP app, its existing CSS/JS patterns — see `public/assets/css/style.css`, `public/includes/header.php`, admin views) using its established components and conventions.

## Fidelity
**High-fidelity (hifi).** Colors, typography, spacing, and interaction states are final — recreate pixel-close using the site's existing dark theme tokens (`--bg-primary`, `--bg-card`, `--f1-red`, etc. in `colors_and_type.css` from the design system).

## Screens / Views

### Admin → Core-indstillinger (Core Settings)
**Purpose:** Admin edits app-wide config: identity, homepage hero copy (EN/DA), betting rules, and one feature flag; runs two maintenance actions (rank-history rebuild, email delivery toggle).

**Layout:** Single column, `max-width: 1200px` centered container, `24px` side padding. Cards stacked vertically with `16px` gap, each `background: var(--bg-card)`, `border: 1px solid var(--border-color)`, `border-radius: 12px`, `padding: 20px 24px`.

**Top chrome (unchanged from live site):** sticky top bar (56px), test-environment warning stripe banner, "Administration" H1, top-level tab row (Dashboards / Paddock Challenges-indstillinger / Core-indstillinger active), and admin sub-nav row (Løb, drivers, users, invites, Bets, Sikkerhed, Logs, Indstillinger active).

**Reorganized settings — 4 grouped cards, in this order:**
1. **Generelt** — App Titel, År (site identity). Collapsible, collapsed by default.
2. **Hero på forside** — Hero titel + Hero tekst, in EN/Dansk side-by-side columns with language column headers. Collapsible, collapsed by default.
3. **Betting regler** — Timer før løb (with live helper text), P1/P2/P3 point inputs (with podium-gradient chip labels), Forkert position, Indsatsstørrelse. Collapsible, collapsed by default.
4. **Funktioner** — Paddock Challenges visibility, NOT collapsible (single row).

Each collapsible card header is a full-width button: title (left, `font-weight:800`, `15px`, `var(--text-primary)` — must stay white/light in dark mode, not inherit browser default black button text) + chevron icon (right, `fa-chevron-down`/`fa-chevron-up`, `var(--text-muted)`). The one-line description/subheader stays **visible even when collapsed** (sits between the header button and the collapsible content block).

**Sticky save bar:** positioned `sticky; top: 56px` (right below the site header), sits above all setting cards. Shows dirty-state on the left (red dot + "Du har ugemte ændringer" when dirty, green check + "Alt er gemt" otherwise) and two buttons on the right: "Fortryd" (secondary/outline) and "Gem ændringer" (primary red, floppy-disk icon). This bar is the primary/prominent save affordance — always on-screen while scrolling the settings list.

**Maintenance section (below main settings, own container, not gated by the save bar):**
- **Rangordning Vedligehold** — row layout: label + description (left) / "Gendan" secondary button with `fa-rotate` icon (right).
- **E-mail-levering (testmiljø)** — same row layout: status + description (left) / "Slå til" secondary button with `fa-envelope-open` icon (right).
- Both maintenance-card actions and the "Funktioner" toggle use the **same secondary button pattern**: `height:40px`, `padding:0 18px`, `border:1px solid var(--border-color)`, `background:transparent`, `color:var(--text-primary)`, `font-weight:700`, `font-size:13px`, icon + label, hover → `background:var(--bg-hover)`. This consistency was an explicit revision request — keep all three visually/behaviorally identical.

Footer: centered, muted, small text — "Fr.sund F1 Klub · anno 1992 · v3.0.0".

## Interactions & Behavior
- Any field edit (text input, textarea, number input, or the Paddock Challenges toggle) sets a `dirty` flag → sticky save bar switches from "Alt er gemt" to "Du har ugemte ændringer".
- "Gem ændringer" persists changes and clears `dirty`. "Fortryd" discards in-memory edits and clears `dirty` (prototype just clears the flag — wire up an actual revert-to-last-saved in the real implementation).
- Each of the 3 top cards (Generelt, Hero på forside, Betting regler) toggles independently via its own header click; state is per-card, default **collapsed**.
- "Funktioner" toggle button flips Paddock Challenges visibility; label/icon swap between "Vis"/`fa-eye` and "Skjul"/`fa-eye-slash`, status text swaps "Vist"/"Skjult".
- Inputs get a red focus ring on focus: `box-shadow: 0 0 0 3px rgba(225,6,0,0.20)`, border color `var(--f1-red)`.
- No responsive/mobile behavior was designed in this pass — single desktop column only. Flag this to the user before shipping if mobile admin access matters.

## State Management
Prototype state (per component instance):
- `appTitle`, `year`, `heroTitleEn`, `heroTitleDa`, `heroTextEn`, `heroTextDa` — text fields
- `bettingHours`, `p1`, `p2`, `p3`, `wrongPos`, `betSize` — numeric fields
- `showPaddock` — boolean feature flag
- `dirty` — boolean, true after any field edit since last save/discard
- `generalOpen`, `heroOpen`, `bettingOpen` — booleans, collapse state per card (all default `false`)

In production this maps to: load current settings from backend on mount, PATCH/POST full settings object on "Gem ændringer", and a real "Fortryd" should reset all fields to last-loaded server values (not just clear the dirty flag).

## Design Tokens
Pulled from the Frederikssund F1 Klub Design System (`colors_and_type.css` — dark theme):
- `--f1-red: #e10600` / `--f1-red-light: #ff1a14` (hover) / `--f1-red-dark: #b30500`
- `--bg-primary: #131316` (page) / `--bg-secondary: #1c1c20` / `--bg-card: #232328` / `--bg-hover: #2d2d33`
- `--text-primary: #f5f5f7` / `--text-secondary: #b8b8be` / `--text-muted: #8e8e95`
- `--border-color: #34343a`
- `--status-success: #10b981`
- Podium gradients: gold `linear-gradient(135deg,#fbbf24,#d97706)`, silver `linear-gradient(135deg,#9ca3af,#6b7280)`, bronze `linear-gradient(135deg,#cd7c2f,#a15c1d)`
- Radii: `8px` inputs/buttons, `12px` cards
- Shadows: button hover `0 4px 12px rgba(225,6,0,0.30)`, sticky save bar `0 6px 20px rgba(0,0,0,0.35)`
- Type: display font for headings/buttons/labels, body font for inputs; Font Awesome 6 solid icons throughout (no emoji, per brand rules)

## Assets
Font Awesome 6 Free (via cdnjs in the prototype — the live app should use its self-hosted copy in `public/assets/fontawesome/`). No images/photography used.

## Files
- `Admin Settings.dc.html` — full interactive HTML prototype (open directly in a browser)
- `screenshot.png` — static reference of the default (all-collapsed) state
