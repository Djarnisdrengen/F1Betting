# Handoff: Paddock Challenges

## Overview
**Paddock Challenges** is a new engagement feature for the *Frederikssund Formel 1 Klub* betting site (`Djarnisdrengen/F1Betting` — PHP + vanilla JS, MySQL/SQLite). It gives the ~10 club members something to do **between races**: three quick games that earn **Challenge Points (CP)**, a season-long parallel currency to the existing betting points.

The three games:
- **Rumor or Not** — is this F1 headline a real fact or an AI-generated rumor? +10 CP per correct call.
- **Prediction Duels** — a head-to-head podium-prediction bet against another member. 15 / 10 / 5 CP by finish.
- **Paddock Trivia** — a weekly multiple-choice F1 quiz. +5 CP per correct answer, plus a **+20 CP "Perfect Week"** bonus for sweeping all questions.

This handoff also resolves the **navigation question** — how Challenges fits into the existing site — and specifies a **context-aware home screen** and a distinctly-skinned **"arena" hub**.

## About the Design Files
The files in this bundle are **design references authored in HTML** (as Design Components — a live-preview HTML format). They are prototypes showing the intended **look and behavior**, not production code to lift directly.

The task is to **recreate these designs inside the existing F1Betting codebase** using its established patterns — PHP page templates, the live `style.css`, its CSS custom properties, Font Awesome 6, and vanilla JS. Do **not** port the Design-Component runtime, the `<x-dc>`/`<sc-if>` template tags, `_ds_bundle.js`, or the inline React-style logic class. Those are prototyping scaffolding. Read them for the exact markup, values, copy, and state logic, then rebuild with server-rendered PHP + progressive-enhancement JS the way the rest of the site is built.

Everything is styled against the design system's existing tokens (`var(--f1-red)`, `var(--bg-card)`, etc.), so it should drop into the current stylesheet with almost no new color/spacing values — the few net-new tokens are listed under **Design Tokens** below.

## Fidelity
**High-fidelity (hifi).** Final colors, typography, spacing, copy, and interactions are all specified. Recreate the UI to match, reusing the site's existing components (`.card`, `.badge`, `.btn`, leaderboard rows, the header, the mobile drawer) rather than introducing parallel ones. The one net-new visual treatment is the **arena skin** on the Challenges hub (§ Screens → Challenges Hub) — that is intentional and specified precisely.

---

## Navigation Model (read this first)

The core problem this design solves: Challenges is a whole sub-area, not a single page, and the site already has a "leaderboard." The rules:

1. **The bottom nav bar is always the members' site.** Four items: **Home · Races · Board · Challenges**. (Profile is **not** in the bar — it lives in the burger menu.)
2. **Challenges is one accented doorway**, not a peer utility. Its bottom-bar icon is a **filled red rounded square** (30×30, `border-radius: 9px`, red background, white gamepad glyph, red glow shadow) so it reads as "enter the games."
3. **The hub's own sub-sections never get a second bottom bar.** Inside Challenges, the four sections (**Overview · Rumors · Duels · Trivia**) ride a **top segment control**. The bottom bar stays put with Challenges lit.
4. **No duplicate "Board".** The bottom bar's **Board** = existing season betting standings. The hub's **Ranking / CP leaderboard** = Challenge Points. Keep the names distinct.
5. **Settings for everyone.** Theme / Language / Font live in a **Preferences** block at the bottom of the **burger menu** — the one surface every visitor can open regardless of auth. Signed-out users see **Sign in** where Profile would be; the bottom bar is unchanged for them.
6. **The homepage hero is context-aware** (see below) — this is how Challenges gets discovered without a marketing banner.

---

## Screens / Views

### 1. Home — Between Races (default state)
**Purpose:** When no race betting window is open, pull members toward the games.

**Layout:** Single scrolling column inside the phone frame. Sticky site top bar → scrollable content → sticky bottom nav.
- **Top bar** (`.hf-top` equivalent, 56px tall): F1 logo mark + "Frederikssund F1 '26" wordmark on the left; on the right a **CP chip** (pill, `rgba(225,6,0,.14)` bg, `1px` red border, `--f1-red-light` text, bolt icon + "215 CP", `--mono` font) and the hamburger.
- **Challenges hero** (full-bleed, `padding: 32px 16px 26px`): background is a layered radial glow —
  `radial-gradient(circle at 84% -15%, rgba(225,6,0,.4), transparent 55%)`,
  `radial-gradient(circle at 5% 125%, rgba(251,191,36,.14), transparent 50%)`, over `var(--bg-secondary)`; `border-bottom: 1px solid var(--border-soft)`.
  - Eyebrow: "No race this week · Challenges live" (`.hf-hero-eyebrow`).
  - Title: "Paddock Challenges" — Chivo (`--display`), weight 900, 36px, `line-height:.98`, `letter-spacing:-.02em`, two lines.
  - Sub: "Three quick games, no race needed. Keep your points climbing." — `--text-secondary`, 14px, max-width 30ch.
  - **Stat row** (only when signed in): three stats in a `gap:20px` flex — CP (215, gold `--gold`, 26px Chivo 900), Rank (P4), Day streak (fire icon + 5, `--f1-red-light`). Labels use `.hf-stat-l` (uppercase micro-label).
  - CTA: primary button "Play now →" (`.hf-cta-primary`).
- **Next race** section (`.hf-section-h` header "Next race" + "Races" link): one race card (`.hf-racecard`) — "Hungarian GP · Hungaroring · in 9 days", with a grey **"Not open"** badge (`.hf-badge.done`).
- **Leaderboard** section: top-3 CP rows (`.hf-row`, grid `32px 32px 1fr auto`) with rank medal classes `.r1/.r2/.r3`, avatar initials, name, CP. The signed-in user's row gets `.self`.

### 2. Home — Race Weekend
**Purpose:** When betting is open, the race leads and Challenges recedes.
- Same chrome. **Race hero** on top instead (`.hf-hero`): eyebrow "Betting open · Round 12", title "British Grand Prix", meta (map-marker "Silverstone" · "6 Jul · 15:00"), a **countdown** row (`.hf-countdown` → four `.hf-cd-cell`, each a big Chivo number + micro label: Days/Hrs/Min/Sec), and a "Place your bet →" primary CTA.
- Below the hero, Challenges is a **slim strip** (not the hero): a 12px-radius `--bg-card` row, 36px red-tinted gamepad icon, "Challenges" title, "215 CP · trivia live · a duel waiting" sub, chevron-right. Tapping it opens the hub.
- Then "Upcoming races" with race cards.

> The switch is driven by whether a race betting window is open. In the prototype it's the `raceWeekend` boolean prop; in production derive it from the same logic that decides `BETTING OPEN` vs `BETTING CLOSED` on the existing homepage.

### 3. Races (stub)
Existing races list — shown here only so bottom-nav routing is complete. Reuse the current races page. Cards with status badges: `.open` (green "Open"), `.soon` (orange "Soon"), `.done` (grey "Done").

### 4. Board (stub)
Existing season leaderboard — reuse the current leaderboard page. Full-width `.hf-row` list with medal ranks, avatar, name (+ `YOU`/`DIG` self-tag), star count (`★N`), points. This is the **betting** board, distinct from the hub's CP ranking.

### 5. Challenges Hub — the "arena" (net-new skin)
**Purpose:** The destination behind the Challenges tab. Must feel like a different room from the calm site pages.

**Container background** (the whole hub area): layered —
`radial-gradient(circle at 100% -4%, rgba(225,6,0,.30), transparent 44%)`,
`radial-gradient(circle at -12% 110%, rgba(251,191,36,.10), transparent 46%)`,
`repeating-linear-gradient(135deg, transparent 0 23px, rgba(255,255,255,.014) 23px 24px)`,
over base `#0b0b0d` (deeper than the site's `--bg-primary`). Note the hub keeps the **same bottom bar** and applies `background: rgba(13,13,16,.95)` to it.

**Signature elements (top to bottom):**
1. **Checkered strip** — 7px tall, full width: `repeating-conic-gradient(#f5f5f7 0 25%, #0b0b0d 0 50%) 0 0 / 14px 14px`.
2. **Broadcast header band** — `padding: 11px 14px`, `background: linear-gradient(90deg,#17171b,#0d0d10)`, `border-bottom: 2px solid var(--f1-red)`. Contents: a back chevron (→ Home), a 30×30 red rounded-square gamepad mark (red glow), a two-line title — micro eyebrow "GAMES ZONE · LIVE" (Chivo 700, 9px, `letter-spacing:.14em`, `--f1-red-light`) over "Paddock Challenges" (Chivo 900, 17px, `#f5f5f7`) — and a hamburger on the right.
3. **Top segment control** — a 4-col grid (`gap:6px`). Active tab: no border, `background: var(--f1-red)`, white, Chivo 700, red glow (`box-shadow: 0 3px 10px rgba(225,6,0,.4)`). Inactive: `1px solid var(--border-color)`, `background: rgba(255,255,255,.03)`, `--text-secondary`, Chivo 600. Radius 8px, `padding: 8px 4px`, 12px. Sections: **Overview · Rumors · Duels · Trivia**.
4. **Scrolling content** (`padding: 2px 14px 16px`, y-scroll).

**Overview section:**
- **CP scoreboard tower** — `border-radius:16px`, `border:1px solid rgba(225,6,0,.4)`, `background: linear-gradient(160deg, rgba(225,6,0,.20), rgba(22,22,26,.55))`, `box-shadow: 0 0 34px rgba(225,6,0,.16)`. Three stacked rows separated by hairline borders:
  - Header row: "YOUR STANDING" micro-label (`#ff8a86`) + a red pill "P4 / 8" (Chivo 900, white on `--f1-red`).
  - Big number row: **215** in `--gold`, Chivo 900, 50px, `line-height:.8`, `text-shadow: 0 0 24px rgba(251,191,36,.4)`, `font-variant-numeric: tabular-nums`; beside it "CHALLENGE POINTS" label + "↗ +40 this week" in `--status-success`.
  - Streak row: fire icon + "5-day streak · keep it alive tonight" in `--f1-red-light`, Chivo 700, 12px.
- **Perfect Week tracker** — gold-tinted card (`linear-gradient(120deg, rgba(251,191,36,.14), rgba(22,22,26,.5))`, `border:1px solid rgba(251,191,36,.32)`). Star icon + "Perfect Week" title, "N / 6" count, and a **6-segment progress bar** (flex of 6 equal bars, 7px tall, 4px radius; filled = `--gold`, empty = `rgba(255,255,255,.08)`).
- **"GAMES LIVE NOW"** label, then three **game tiles**, each `border-radius:14px`, `background: rgba(35,35,40,.62)`, `1px` border, with a **3px accent bar pinned to the top edge**:
  - Rumor or Not — red accent (`--f1-red`), red-tinted question-circle icon, "+10 CP each", "N/3" progress, red fill progress bar.
  - Paddock Trivia — blue accent (`#3b82f6`), blue brain icon (`#7fb2ff` on `rgba(59,130,246,.18)`), "+5 CP · +20 perfect week", "N/3", blue progress bar.
  - Prediction Duels — amber accent (`--status-warning`), amber bolt icon, "Henrik challenged you", amber "Your move" badge. (Tiles are tappable → jump to that section.)

**Rumors section:**
- Header "Today's deck" + "N/3" progress.
- **Card** (`rgba(35,35,40,.7)`, `1.5px` border that turns green/red once answered): context badge (red-tinted, e.g. "Grid expansion"), the claim text (Chivo 800, 18px, `line-height:1.32`, `#f5f5f7`). When unanswered, two big buttons below (grid `1fr 1fr`, 56px tall): **Rumor** (red-outlined, `rgba(225,6,0,.12)`, `--f1-red-light`) and **Real** (green-outlined, `rgba(16,185,129,.12)`, `#34d399`).
- On answer: a **REAL/RUMOR stamp** appears top-right of the card (white on green/red), and a reveal block appears (hairline-separated) — check/x icon + "Correct · +10 CP" or "Missed it · +0 CP" + an explanation sentence. Then a primary "Next card →" (or "Finish deck →" on the last).
- Done state: champagne icon, "Deck cleared", "Fresh cards drop tomorrow.", back-to-overview button.

**Trivia section:**
- Header "This week's quiz" + "N/3".
- **Question card** (`rgba(35,35,40,.7)`): blue-tinted context badge, question (Chivo 800, 18px), then a vertical list of **option buttons** (2–4). Each: `padding:13px 14px`, `border-radius:12px`, `1.5px` border, a 22px letter chip (A/B/C…), the option text.
  - **Before answering:** neutral (`rgba(255,255,255,.03)` bg, `--border-color`).
  - **After answering (immediate):** the **correct** option turns green (`rgba(16,185,129,.14)`, green border, `#34d399`, check icon); if the user picked wrong, **their** pick turns red (red border, x icon); other options dim to `--text-muted`. All options become non-interactive (`pointer-events:none`).
  - A reveal block follows: "Correct · +5 CP" / "Not quite · +0 CP" (green/red) + explanation. Then "Next question →" / "Finish quiz →".
- Done state: **Perfect Week** if all correct → star icon, "Perfect Week", "All correct — including the +20 bonus."; otherwise clipboard icon, "Quiz complete", "You got N of 3 right."

**Duels section:**
- **Active duel card** (amber-bordered): "British GP" label + "Your move" badge. A **VS layout** — two columns each with a 44px avatar circle (opponent grey, you red), name, and a `--mono` podium pick line ("VER · NOR · LEC"). Center "VS" in gold. Primary CTA "Accept & lock your podium →"; after responding it reads "Locked in" and the pick line fills in.
- **"Settled"** label + a compact resolved row: opponent avatar, "vs Søren · Austrian GP", and a green "Won +15".

### 6. Burger Menu (drawer)
**Purpose:** Secondary nav + the home for all preferences, reachable by anyone.
- Overlay `rgba(0,0,0,.55)`; the drawer slides from the right (existing `.hf-drawer`, `transition: right 0.3s ease`), `max-height: calc(100% - 24px)`, scrolls.
- **Nav rows** (`.hf-drawer-row`, icon + label, active row gets the red treatment): Home, Races, Leaderboard, Rules, **Challenges** (gamepad icon in red + a "New" badge), Public CP leaderboard (external-link icon).
- Divider, then **Preferences** block (`.hf-toc-title` heading):
  - **Theme** — half-circle icon + segmented control [moon | sun], moon active by default (dark).
  - **Language** — globe icon + segmented [DA | EN].
  - **Font** — font icon + segmented [Brand | System].
  - Each control is the existing `.hf-seg` segmented control; the active button gets `.active`.
- Divider, then account: **signed-in** → Profile + Sign out; **signed-out** → Sign in (red).

---

## Interactions & Behavior

- **Bottom-nav routing:** tapping Home/Races/Board/Challenges swaps the active screen and lights the tab; also closes the menu. Challenges deep-links to the hub's current section (defaults to Overview).
- **Hub section switch:** the top segment swaps the visible section. Tapping a game tile in Overview jumps to that section.
- **Rumor answering:** first tap of Rumor/Real locks the card, reveals REAL/RUMOR stamp + result + explanation, credits +10 CP on a correct call, and shows a CP toast. "Next card" advances; after the last card, a done state.
- **Trivia answering:** tapping an option immediately colors correct (green) and, if wrong, the chosen option (red); credits +5 CP if correct; options lock. "Next question" advances. On finishing, if all correct award the **+20 Perfect Week** bonus and show the Perfect Week done state; otherwise the standard summary. (In the full spec the +20 fires once when the final answer completes a clean sweep.)
- **Duel:** "Accept & lock" sets the user's podium pick, disables the button ("Locked in"), toasts "Duel locked in".
- **Preferences:**
  - Theme toggles the existing dark/light class on `<body>` (the site already ships both themes).
  - Language swaps all copy between Danish and English (the repo already has `lang/user.php` string tables — extend those; **Danish is the default**). In the prototype every visible string is keyed; use the same keys.
  - Font toggles Brand (Chivo/Manrope) vs System stack.
- **Toast:** a pill toast slides in near the bottom (`bottom: 84px`, centered), `--bg-card` with a gold border + bolt icon, auto-dismisses ~1.6s. Used for CP gains and confirmations.
- **Sign in/out:** menu action; signed-out hides the CP chip and the stat row, and shows "Sign in" in the menu.

### Animations (keep them short — house style is calm)
- Section/hub enter: `pp-fade` (opacity, .25s) and `pp-pop` (opacity + translateY(12px) + scale(.98) → none, .28s ease) on freshly-shown cards.
- `pp-drop` (translateY(-8px) → none) available for dropdowns.
- Reuse the design system's existing `star-pulse` for the gold ★, and card hover lift (`translateY(-2px)` + red-tinted shadow). No springs/bounces/parallax. Transitions ~0.2–0.3s, browser-default `ease`.

## State Management
Per-user, per-week/day server state (the prototype holds these in component state — persist server-side in production):
- `cp` — running Challenge Points total; `earned` — CP earned this week (drives "+N this week").
- **Rumors:** `rIdx` (current card), `rAns` map of `cardId → wasCorrect`. Daily deck.
- **Trivia:** `tIdx` (current question), `tAns` map of `questionId → chosenOptionIndex`. Weekly set; award +20 once when all correct.
- **Perfect Week tracker:** derived — count of games/answers completed this week toward the 6 segments (prototype feeds it from rumor+trivia counts; production should define the six qualifying games explicitly).
- **Duels:** `duelResponded` and the chosen podium; plus resolved history.
- **UI/session:** `tab` (home/races/board/challenges), `hubSeg` (overview/rumors/duels/trivia), `menuOpen`, `theme`, `lang`, `signedIn`, transient `toast`.
- **Context flag:** `raceWeekend` — from the existing betting-window logic; selects which home hero shows.

Data the backend must provide: the daily rumor deck (claim text, context tag, `isReal`, explanation), the weekly trivia set (question, options, answer index, explanation), open/received duels, and the CP leaderboard.

## Design Tokens

Existing design-system tokens are used throughout — pull these from the live `style.css` / `colors_and_type.css`, do not redefine:
`--f1-red (#e10600)`, `--f1-red-light`, `--gold (#fbbf24)`, `--bg-primary (#0a0a0b)`, `--bg-secondary`, `--bg-card`, `--bg-hover`, `--border-color`, `--border-soft`, `--text-primary`, `--text-secondary`, `--text-muted`, `--status-success`, `--status-warning`, radii `--radius-sm/md/lg/xl/pill`, fonts `--display` (Chivo), `--body` (Manrope), `--mono`.

**Net-new values introduced by the arena skin** (scope them to the hub only):
- Arena base background: `#0b0b0d`
- Arena bottom-bar background: `rgba(13,13,16,.95)`
- Header band gradient: `linear-gradient(90deg,#17171b,#0d0d10)`
- Tile surface: `rgba(35,35,40,.62)`; active card surface: `rgba(35,35,40,.7)`
- Light foreground on arena dark: `#f5f5f7`
- Success green (option/reveal text): `#34d399`; blue accent (trivia): `#3b82f6` / icon `#7fb2ff`
- Scoreboard glow: `0 0 34px rgba(225,6,0,.16)`; number glow: `0 0 24px rgba(251,191,36,.4)`
- Checker: `repeating-conic-gradient(#f5f5f7 0 25%, #0b0b0d 0 50%) 0 0 / 14px 14px`
- Toast shadow: `0 8px 24px rgba(0,0,0,.45)`

## Assets
- **F1 logo mark + wordmark** — use the existing `assets/logo_header_dark.png` / `_light.png` from the repo.
- **Icons** — Font Awesome 6 Free (solid), already self-hosted in the repo. Glyphs used: `gamepad`, `bolt`, `fire`, `star`, `brain`, `circle-question`, `circle-check`, `circle-xmark`, `arrow-trend-up`, `champagne-glasses`, `clipboard-check`, `chevron-left`, `chevron-right`, `arrow-up-right-from-square`, `house`, `flag`, `trophy`, `user`, `circle-half-stroke`, `globe`, `font`, `sun`, `moon`, `right-to-bracket`, `right-from-bracket`, `map-marker-alt`, `book`.
- **No images/photography** — solid colors and gradients only, per the design system.

## Files
- `Paddock Prototype.dc.html` — **the primary reference.** The complete consolidated prototype: context-aware home, arena hub with all four sections playable, burger menu with working Theme/Language/Font, and the accented bottom bar. Two demo toggles (`raceWeekend`, `signedIn`) exposed as props.
- `reference_Challenges Nav Integration.dc.html` — a canvas of the navigation exploration (site-level bar zones, hub-tabs vs bottom-bar, arena-vs-plain contrast, and the settings-placement rationale). Read this to understand *why* the nav model is what it is; it is not needed for implementation.

### Screenshots (`screenshots/`)
Reference captures of each state (the phone content is shown wide; treat them as content/spec references, not exact device widths):
- `01-home-between-races.png` — Home with the Challenges hero on top (default, between-races state).
- `02-hub-overview.png` — Arena hub, Overview: CP scoreboard, Perfect Week tracker, game tiles.
- `03-hub-rumors.png` — Rumor or Not: claim card + Rumor/Real buttons.
- `04-hub-trivia.png` — Trivia: question + option list (unanswered).
- `05-hub-duels.png` — Prediction Duels: VS card + settled history.
- `06-hub-trivia-answered.png` — Trivia after answering: correct option green, wrong pick red, reveal + explanation.
- `07-burger-menu.png` — Drawer with nav + Preferences (Theme / Language / Font).

> These are Design-Component HTML files. Open them in a browser to view. When reading the source, ignore the `<helmet>`, `<x-import>`, `<sc-if>`, `<sc-for>`, `{{ }}` template holes, and the `class Component extends DCLogic` block — those are prototyping constructs. The **markup inside**, the **inline styles**, the **copy strings**, and the **logic in `renderVals()`/handlers** are the spec to reimplement in PHP + JS.
