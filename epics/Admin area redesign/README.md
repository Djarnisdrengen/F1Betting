# Handoff: Admin Area Navigation & Layout Redesign

## Overview
Redesign of the Frederikssund Formel 1 Klub admin area (`/admin`). Covers all three top-level admin sections — **Dashboards**, **Paddock Challenges settings**, and **Core settings** — and every sub-tab under them (17 screens total). The goals were: (1) a navigation framework that works cleanly on mobile instead of the old overflowing top-nav pill row, (2) a consistent, reusable set of layout primitives (page header, stat card grid, section card, list row) applied across every admin screen, and (3) no new data, features, or admin capabilities — this is a layout/navigation rebuild only, all copy and numbers are carried over unchanged from the current site.

## About the Design Files
The bundled file (`Admin Settings.dc.html`) is a **design reference built in HTML** — an interactive, click-through prototype showing the intended navigation, layout, and responsive behavior. It is not production code to copy verbatim. The task is to **recreate this design in the target codebase's real stack** (this app is vanilla PHP + MySQL/SQLite + plain JS, per the F1Betting repo) — i.e. reimplement the markup as PHP admin views/partials and the interaction logic as small vanilla-JS enhancements (native `<details>` needs no JS at all), following the patterns already established in `public/includes/header.php`, `public/assets/css/style.css`, and the existing `admin.php`-style pages.

## Fidelity
**High-fidelity.** Colors, type, spacing, and copy are final and traceable to the design system's tokens (see Design Tokens below) and to the current live admin screens (all data reproduced from the client's own screenshots — nothing invented). Interaction behavior (tab switching, dropdown collapse, accordion toggles) is also final and should be implemented as specified, not reinterpreted.

## Navigation framework (the core deliverable)
This is the reusable piece going forward — every future admin page should use it, not reinvent it.

**Pattern source:** the design system ships a dedicated reference card for exactly this problem — `preview/nav-responsive.html` ("Responsive navigation — container-query top-nav · admin dropdown on mobile"). This handoff's nav is a direct implementation of that card's "Admin menu · tabs → dropdown" pattern, applied at **two levels** (top-level section, and within-section tabs), not just one.

- **Structure:** each nav level is wrapped in a `.admin-shell` container with `container-type: inline-size; container-name: admin-vp;`. Inside it sits both a `.admin-tabs` row (desktop) and an `.admin-dropdown` (mobile) — both always in the DOM; CSS decides which is visible.
- **Desktop (≥720px of the shell's own width):** `.admin-tabs` is a `display:flex; flex-wrap:wrap` row of `.admin-tab` pills. Tabs **wrap to a second line** if they don't fit — they never horizontal-scroll and never shrink to illegible sizes. Active tab: red text + red bottom border (`border-bottom: 2px solid var(--f1-red)`). Tabs with counts show a `.tab-count` pill (dark chip, `var(--bg-hover)` background; red when the parent tab is active).
- **Mobile (<720px of the shell's own width):** `.admin-tabs` is hidden and a native `<details class="admin-dropdown">` is shown instead — **no JS required** for open/close. The `<summary>` shows the current section/tab (icon + label + chevron); tapping it reveals a `.menu` list of all sibling items as `<a>` rows, each with its icon, label, and count pill; the active one is highlighted. Selecting an item is a real click handler that also causes the `<details>` to visually close (implemented in the prototype by re-keying/remounting the element on navigation — in production this can simply be `<a href="?section=...">` links plus closing the details in an onclick, or letting native `<details>` behavior stand).
- **Why container queries, not viewport media queries:** the nav's actual available width depends on where it's embedded (inside the `.container` max-width, sidebars, etc.), which can diverge from viewport width. `@container admin-vp (max-width: 720px) { .admin-tabs { display:none } .admin-dropdown { display:block } }` reacts to the shell's real width. Browser support: Chrome/Edge 105+, Safari 16+, Firefox 110+ (all 2022+, safe to ship).
- **No JS-driven breakpoint state, no slide-in drawer overlay** — deliberately avoided in favor of the native `<details>` approach, which can't get stuck open mid-animation and needs zero event wiring for the open/close mechanic itself (only the navigation click still needs a handler).

## Reusable layout primitives
Applied consistently on every one of the 17 admin screens so screens feel like one system rather than 17 one-offs:

- **Page header:** `<h1>` with a leading colored icon (`<i class="fas fa-gear" style="color:var(--f1-red)">`), 28px (`--fs-h1`), weight 700.
- **Stat card grid:** `display:grid; grid-template-columns:repeat(auto-fit,minmax(150–240px,1fr)); gap:14–16px;`. Each card: `var(--bg-card)` background, `1px solid var(--border-color)` border, `12px` radius, `16–20px` padding, small muted label on top + large bold value below. The `auto-fit/minmax` grid is what makes these reflow to 1–2 columns on mobile with **no media query needed**.
- **Section card:** same card chrome as stat cards, used for grouped content blocks (e.g. "Rotation history", "Content by category", "Duel oversight"). Header row = bold 15px title + colored leading icon.
- **List/table row:** a repeating pattern of icon or avatar + primary/secondary text block + right-aligned status/action cluster. On narrow content (e.g. "Secrets & passwords", "Access tokens") this was explicitly restructured mid-review into **3 stacked lines** (identity → progress/status → action button) rather than one wrapping flex row, because a single row with 5+ inline elements (icon, name, progress bar, day count, OK badge, button) became illegible once it wrapped on mobile. Use that 3-line stacked pattern as the template for any future dense data row: line 1 = identity, line 2 = the primary metric/visualization, line 3 = status text (left) + action button (right), `justify-content:space-between`.
- **Empty states:** centered muted 13px text inside a section card, no icon, no illustration (e.g. "No duels yet.", "No invites yet").
- **Badges:** small pill, 800 weight, 10–11px, uppercase where the live site uses uppercase (`CORE USER`, `VERIFIED`, `ADMIN`), colored per status (`var(--status-success)` green / `var(--f1-red)` red / `var(--bg-hover)` neutral).

## Screens covered
All under the shared `.admin-shell` nav + page shell described above:

**Dashboards:** Overview (4-card grid + action-required banner), Keys & Rotation (health gauge, 6 stat cards, Access tokens, Secrets & passwords list, Rotation history), PaddockKB (last-update/next-run cards, 3 stat cards, category breakdown bars, recent ingest runs), Challenges (4 stat cards, Competitions list with per-competition stat trio, Visitor→member funnel bars), Actions (4 stat cards, Runs·last-12h panel, All workflows panel + filter input).

**Paddock Challenges settings:** Members (full-membership requests, converted guests, all-participants list w/ select-all + bulk delete), Rumor or Not (publish/draft toggle + row list), Trivia (same row-list pattern), Duels (quick match queue, duel oversight w/ sort toggle), Suppressions (opted-out emails list + add form).

**Core settings:** Races (add + list), Drivers (add + numbered list w/ team), Users (avatar rows w/ role badge, points, competition status, make-admin/reset/delete actions), Invites (send-invite form + list), Bets (grouped by race, per-bet P1/P2/P3 chips + points pill, gold star for a perfect bet), Security (by-IP / by-account attempt panels), Logs (App/Mail/Cron sub-tabs, monospace scrollable log viewer), Settings (General, Homepage hero EN/DA, Betting rules, Feature toggles, Ranking maintenance, Email delivery — collapsible sections with a sticky save/discard bar).

## Interactions & Behavior
- **Tab/section switching:** plain click → state change → re-render of the content area below the nav. No transitions/animations on switch.
- **Collapsible sections (Settings tab):** click header → chevron rotates (`fa-chevron-down` ↔ `fa-chevron-up`) → content shows/hides. No animation.
- **Sticky save bar (Settings tab only):** stays pinned under the header while scrolling; shows "You have unsaved changes" (red dot) vs "All changes saved" (green check) based on dirty state; any field edit sets dirty=true.
- **Mobile admin dropdown:** native `<details>`/`<summary>` disclosure — browser-native open/close animation (none) and keyboard/a11y behavior for free.
- **Logs sub-tabs:** switching sub-tab swaps the content panel; only "App" has real log content in this handoff, others show a neutral empty state.
- No page-load animations; no hover-lift on cards (per design system: only cards elsewhere on the site get the red-tinted hover lift — admin data rows stay flat).

## State Management
Minimal, page-local UI state only:
- `section` (`dashboards` | `challenges` | `core`) + one active-tab value per section (`dashTab`, `chTab`, `coreTab`) and `logsTab` for the Logs sub-nav.
- Collapsible-section booleans for the Settings tab (`generalOpen`, `heroOpen`, `bettingOpen`).
- Form field values + a `dirty` boolean for the Settings tab's save bar.
- `duelSort` (`newest` | `oldest`) toggle on the Duels screen.
- No async data fetching in the prototype — all content is static/hardcoded from the client's screenshots; production should back these with the app's existing PHP data (drivers, users, bets, secrets, logs, etc.) — no schema changes implied by this redesign.

## Design Tokens
Pulled from the bound design system's `colors_and_type.css` — do not invent new values:
- **Brand red:** `#e10600` (`--f1-red`), hover `#ff1a14` (`--f1-red-light`), pressed `#b30500` (`--f1-red-dark`)
- **Status:** success `#059669`, warning `#d97706`, danger `#dc2626`, neutral `#6b7280`
- **Podium:** gold `#fbbf24`/`#f59e0b`, silver `#9ca3af`/`#6b7280`, bronze `#cd7c2f`/`#a15c1d`
- **Dark theme surfaces:** page `#131316`, header/inset `#1c1c20`, card `#232328`, hover `#2d2d33`
- **Text:** primary `#f5f5f7`, secondary `#b8b8be`, muted `#8e8e95`
- **Border:** `#34343a`
- **Radii:** sm 6px, md 8px (buttons/inputs), lg 12px (cards), xl 16px (hero/modal), pill 9999px
- **Type:** display/body = system UI stack; `--font-mono` (Courier Prime) for log lines, timestamps, tab counts
- **Shadows:** all colored, never neutral black — card hover `0 8px 24px rgba(225,6,0,0.15)`, button hover `0 4px 12px rgba(225,6,0,0.30)`

## Assets
Icons: Font Awesome 6 Free (solid + brands), loaded via CDN in the prototype — production should use the repo's self-hosted copy at `public/assets/fontawesome/`. No photography or custom illustration used, per brand guidelines (flat color only).

## Files
- `Admin Settings.dc.html` — the full interactive prototype (all 17 screens + nav framework). Open directly in a browser.
