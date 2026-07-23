# Handoff: Admin Area — Two-Tier Navigation + Operations Dashboards

## Overview
This handoff covers a restructure of the **Klub-admin** area for the Frederikssund Formel 1 Klub site (repo: `Djarnisdrengen/F1Betting`, PHP + vanilla JS, MySQL/SQLite, no front-end framework).

The admin area is reorganized into **three top-level areas**, each with a row of **section tabs**:

1. **Core** — Races · drivers · users · invites · Bets · Security · Settings
2. **Paddock Challenges** — Members · Rumor or Not · Trivia · Duels · Suppressions
3. **Dashboards** — Oversigt · Nøgler & Rotation · PaddockKB · Challenges · GitHub Actions (**net-new**)

### ⚠️ Scope — read first
- **Core** and **Paddock Challenges** sections: **UI/navigation changes ONLY.** Do **not** change what data these pages show, their queries, their business logic, or their behavior. The only work here is (a) reparenting the existing pages under the new two-tier nav and (b) restyling the nav/tab chrome to match the mock. Each section's existing content stays exactly as it is in production today. The tables/forms shown in the mock for these sections are **placeholder representations** of pages that already exist — recreate the *nav shell and styling*, keep the *real page content*.
- **Dashboards** area: **net-new.** All five tabs (Oversigt, Nøgler & Rotation, PaddockKB, Challenges, GitHub Actions) are new screens to build. These need real backend wiring (see "Data & backend" per screen). The mock data in the HTML is illustrative — replace with live values.

## About the Design Files
The file in this bundle (`Admin Dashboard Ideas.dc.html`) is a **design reference created in HTML** — a prototype showing intended look and behavior, **not production code to copy directly**. It is authored as a single-file component and uses a small runtime (`support.js`) plus template syntax (`sc-if`, `sc-for`, `{{ }}`) that is **not** part of the target codebase.

Your task is to **recreate these designs in the F1Betting codebase using its existing patterns**: PHP page includes, the live stylesheet (`public/assets/css/style.css`), the CSS custom properties already defined there, Font Awesome 6 (self-hosted in `public/assets/fontawesome/`), and vanilla JS (`public/assets/js/app.js`). Do **not** introduce React, a build step, or the `.dc.html` runtime.

## Fidelity
**High-fidelity.** Colors, typography, spacing, radii, and states are final and should be reproduced faithfully using the codebase's existing design tokens (all values below already exist in `style.css` as `--*` variables). Where the mock shows a value, prefer the matching CSS variable over a raw hex.

---

## Design Tokens
All of these already exist in `public/assets/css/style.css` (dark theme is the default, `body.dark` / `.theme-dark`). Use the variables, not raw values.

**Brand**
- `--f1-red: #e10600` · `--f1-red-dark: #b30500` · `--f1-red-light: #ff1a14`

**Status (gradients are `linear-gradient(135deg, light → dark)`)**
- success `#10b981 → #059669` (fg `#0b3b2b`) · warning `#f59e0b → #d97706` (fg `#3a2600`) · danger `#ef4444 → #dc2626` (fg `#fff`)
- `--status-success-light: #10b981` · `--status-warning-light: #f59e0b` · `--status-danger-light: #ef4444`

**Podium / accent**
- `--gold: #fbbf24` · `--pool-gold: #b98f10`

**Dark theme neutrals**
- `--bg-primary: #131316` · `--bg-secondary: #1c1c20` · `--bg-card: #232328` · `--bg-hover: #2d2d33`
- `--text-primary: #f5f5f7` · `--text-secondary: #b8b8be` · `--text-muted: #8e8e95` · `--border-color: #34343a`

**Type**
- `--font-body` / `--font-display`: system UI stack (no brand display font)
- `--font-accent: 'Kalam', cursive` — page ledes / warm callouts only
- `--font-mono: 'Courier Prime', monospace` — **all numbers, counts, timestamps, countdowns, tab-count chips, masked/expiry values, IPs**
- Sizes used: page nav 13–14px, card titles 15–19px, KPI numbers 25–28px, meta 11–12px

**Radii**
- `--radius-md: 8px` (buttons/inputs) · `--radius-lg: 12px` (cards; mock uses 11–14px) · `--radius-pill: 9999px` (badges + nav pills)

**Shadows** — colored only, applied on hover (cards lift `translateY(-2px)` with `0 8px 24px rgba(225,6,0,0.15)`).

---

## Screens / Views

### Global chrome — the two-tier nav (applies to ALL areas)
- **Top bar** (`position: sticky; top: 0`): left = 30×30 `--f1-red` rounded square with `fa-flag-checkered`, then "Klub-admin" (800 weight, 15px) over "Frederikssund Formel 1 Klub" (11px `--text-muted`). Right = a `--font-mono` "alle systemer kører" status with a small green `fa-circle`, then a 28px avatar circle + "Jens · Admin". Border-bottom `1px --border-color`, bg `--bg-secondary`.
- **Content width**: `max-width: 1240px`, centered, padding `22px 28px`.
- **Level 1 — area buttons** (row, `gap: 10px`): each is `display:flex; gap:9px; padding:10px 18px; border-radius:9px; font-weight:800; font-size:14px`.
  - Active: `border:1px solid var(--f1-red)`, `color:var(--f1-red)`, `background:rgba(225,6,0,0.10)`, icon `--f1-red`.
  - Inactive: `border:1px solid var(--border-color)`, `background:var(--bg-secondary)`, `color:var(--text-secondary)`, icon `--text-muted`.
  - Icons: Core `fa-gear`, Paddock Challenges `fa-user-check`, Dashboards `fa-gauge-high`.
- **Level 2 — section tabs** (row below, `gap:8px`, `border-bottom:1px solid var(--border-color)`, `padding-bottom:20px`): pill buttons `padding:8px 14px; border-radius:pill; font-weight:700; font-size:13px`.
  - Active: `border:1px solid var(--f1-red)`, `color:var(--f1-red)`, `background:rgba(225,6,0,0.08)`.
  - Inactive: `border:1px solid var(--border-color)`, `background:var(--bg-secondary)`, `color:var(--text-secondary)`.
  - Optional trailing **count chip**: `--font-mono`, 11px, `padding:0 7px; border-radius:pill`. Active chip `background:rgba(225,6,0,0.15)/color:--f1-red`; inactive `background:--bg-hover/color:--text-muted`. Counts shown: Races 6, drivers 10, users 4, Bets 15, Rumor or Not 101, Trivia 101.
- **Behavior**: clicking an area switches the tab row and shows that area's first (or last-selected) tab. Clicking a tab swaps the content pane. In the PHP app this maps to `?area=core&tab=races` style routing (or the existing per-page URLs) — active state is derived from the current route server-side; the nav is plain `<a>` links styled per above. No SPA needed.

> **Note on nesting:** dashboards themselves have **no inner tabs** — each dashboard is one scrolling pane. (An earlier concept nested tabs inside a dashboard; that was rejected.)

---

### CORE area — UI-only reparent (do not touch data/logic)
These pages exist in production. Recreate the nav shell + card/table styling to match; **keep existing content, queries, columns, forms, and actions unchanged.** The mock's rows are placeholders.

- **Races** — existing race-management list. Card header "Løb", `sæson 2026` chip, primary "Nyt løb" button. Rows: name + date/note, mono bets count with `fa-users`, status badge (Afsluttet = `--bg-hover`; Betting lukket = danger gradient; Betting åben = success gradient; Kommende = `--bg-secondary`), edit + Resultat buttons.
- **drivers** — existing driver list. Rows: mono number, name, team, mono "N pts", edit.
- **users** — existing user list. Avatar initials circle, name + email/last-seen, gold `★N`, role badge, edit.
- **invites** — existing invite list. Mono email, sent-by/when, status badge (Sendt = warning, Åbnet = success), "Send igen" + dismiss.
- **Bets** — existing bets view. Three KPI cards (mono numbers) + recent-bets table (user, mono pick string, mono pts, mono when).
- **Security** — existing app-security page. Callout linking secrets/tokens to Dashboards → Nøgler & Rotation, two toggles (2FA / auto-logout), active-sessions list with "Denne enhed" badge + Log ud.
- **Settings** — existing settings form. App title / season / betting-close / point-table fields + Gem.

### PADDOCK CHALLENGES area — UI-only reparent (do not touch data/logic)
Existing challenge-admin pages, reparented + restyled only.
- **Members** — participants/members list: 3 KPI cards, rows with avatar, joined/points, status badge (Fuldt medlem / Deltager / Ansøger), Godkend for pending / edit otherwise.
- **Rumor or Not** — claims bank (count 101): KB-coupling callout, rows with claim text, mono play count, verdict badge (Sandt/Rygte/Afventer), edit.
- **Trivia** — (count 101): 3 KPI cards + rounds list (Aktiv/Planlagt/Afsluttet badges), "Ny runde".
- **Duels** — 3 KPI cards + settings toggles (link duels to races, points per duel, allow guests).
- **Suppressions** — hidden/rejected content list, rows with text, reason/by/when, "skjult" badge, Gendan.

---

### DASHBOARDS area — NET-NEW (build these, wire to real data)

#### 1. Oversigt (default tab)
- **Layout**: 2×2 grid of clickable summary cards (`gap:14px`), then a full-width "Kræver handling" strip.
- **Summary cards**: 36px icon tile, title (800/15px), optional red count flag (mono pill), big mono stat + unit label, footer note (tone-colored) + "Åbn →". Cards: Nøgler & Rotation (stat = health score, flag = expired+overdue count), GitHub Actions (87% / flag 2), PaddockKB (Healthy / flag 1), Paddock Challenges (248 / flag 0). Clicking a card navigates to that dashboard tab.
- **Action strip**: `border:1px solid rgba(225,6,0,0.4)`, `background:linear-gradient(90deg,rgba(225,6,0,0.09),transparent 60%)`. Cross-cutting exceptions, each with a colored dot + text + a right-aligned link to the relevant tab.
- **Data & backend**: aggregates the headline health of the other four dashboards. No new storage — reads from the same sources as each dashboard.

#### 2. Nøgler & Rotation  (token expiry + manual secret rotation)
- **Environment toggle** (Production / Test) in the card header — segmented control, active segment `--f1-red`/white. All figures below are per-environment.
- **"Handling påkrævet" queue**: pulsing red-bordered box (`@keyframes` box-shadow pulse, 2.4s), count + list of expired tokens / expiring-soon / overdue secrets, each with icon + text.
- **Health ring**: 110px `conic-gradient(<color> <health>%, --border-color 0)` with a `--bg-card` inner disc showing the mono score. Color: ≥80 green, ≥55 orange, else red. Score formula in mock: `100 − expired×16 − overdue×11 − soon×4`, clamped 0–100.
- **KPI grid** (3×2): expired tokens, expiring <14 days, overdue secrets, last-rotated, secrets-in-config count, access-token count.
- **Access tokens** (GitHub / Anthropic / OpenAI): row = provider icon, name, "Sidst roteret <date> · <who>", right side = mono expiry text + status badge (Healthy / Udløber snart / Udløbet). Each token has a **"Roteret — indtast ny dato"** button → reveals a `<input type=date>` + Gem/Fortryd; saving records the new expiry, recomputes days-until-expiry, sets last-rotated = today and rotated-by = current user.
  - **Backend**: tokens are rotated *at the provider*; this screen only **records** the new expiry + who/when. Store per (env, token) an `expires_at`, `last_rotated_at`, `rotated_by`. Countdown = `expires_at − now`.
- **Hemmeligheder & passwords** (config secrets/passwords): row = icon, name (mono), by/policy, an **age/policy bar** (green <80% of policy, orange ≥80%, red ≥100%), status badge (OK/Snart/Forfalden), and a **"Roter nu"** button.
  - **Backend**: "Roter nu" **actually rotates** — generate a new secret value, write it to the target config file for that environment, reset age to 0, record who/when. This is a real privileged action; gate it behind admin auth + (ideally) a re-auth/confirm step, and log it to the audit trail.
- **Rotations-historik**: audit log (when / who / action / scope). Persist every token-record and secret-rotation event.
- **Test ↔ Prod drift** (dashed idea panel): detects secrets present in one env but not the other, or much older in prod. Nice-to-have; compute by diffing the two environments' secret sets + ages.

#### 3. PaddockKB (knowledge base behind rumor/trivia + paddock/query.php)
- **Update status**: two cards — "Sidste opdatering" (relative time + added/sources/duration) and "Næste planlagte" (countdown + schedule "dagligt kl. 04:00 · auto"). Then a full-width primary **"Kør opdatering nu"** button (triggers an on-demand ingest run).
- **KPIs**: entries total, categories, index size, queries (7d).
- **Indhold pr. kategori**: per-category red progress bar + mono count + freshness dot (green/orange/red by staleness).
- **Seneste ingest-kørsler**: run log (when / source / +added / OK|Fejl badge) — show real run outcomes incl. failures.
- **Query-brug & svar-kvalitet** (idea panel): top queries (mono counts) + a coverage stat (e.g. % answers with a source hit) highlighting coverage gaps.
- **Backend**: reads KB metadata + the ingest job's run history + query logs. "Kør opdatering nu" enqueues the same job the nightly cron runs.

#### 4. Challenges (usage)
- **KPIs**: active participants, plays (7d), new applications, participation rate.
- **Konkurrencer**: one card per public competition (Duels / Rumor or Not / Weekly Trivia) with icon tile, participants / plays / completion% (mono), and an 8-bar weekly sparkline (`--f1-red` at 0.65 opacity).
- **Funnel** (idea panel): visitor → participated → registered → requested membership, horizontal bars + mono values.
- **Backend**: read-only aggregates over the three challenges' play/participant tables.

#### 5. GitHub Actions  (net-new dashboard; models the repo's Actions)
- **KPIs** (4): Workflows (9), Runs · last 24h (14), Success rate (87%), Failing now (2).
- **"Runs · seneste 12t"** collapsible header bar (count chip + chevron).
- **Two-column** `290px / 1fr`:
  - **Left — "Alle workflows"**: count chip, a filter input, and a list of workflow rows (status check/x icon + name + mono relative-time). Clicking a row selects it; selected row has `--bg-hover` background + primary text.
  - **Right — detail card** for the selected workflow: icon tile + name + status summary ("N passed · M failed — seneste 10 kørsler") + yaml path chip (`.github/workflows/<name>.yml`, mono). Two panels — **PURPOSE** and **EXPECTED RESULT** (red mono kicker labels). Action buttons "Kør workflow" / "Vis log". Then "Seneste kørsler" list (status icon / run # / when / duration, all mono). Footer notes the Anthropic/OpenAI key coupling and links to Nøgler & Rotation.
- **Backend**: GitHub Actions REST API (workflows, recent runs, per-workflow run history). Cache server-side; the PURPOSE/EXPECTED text can live in a small per-workflow config/table since it's editorial.

---

## Interactions & Behavior
- **Nav**: server-rendered active state from the current route; nav items are `<a>` links. No client framework.
- **Token expiry entry**: inline date input toggled per token; on save, recompute countdown, badge, last-rotated/by.
- **Secret rotation**: privileged POST; regenerate + write to config; reset age; append to audit log; confirm before running.
- **KB "Kør opdatering nu"**: enqueue ingest job; reflect running/last-run state.
- **GitHub workflow selection**: swaps the detail pane (client-side is fine; or a route param).
- **Hover**: cards lift + red-tinted shadow; table rows → `--bg-hover`; buttons per the design system (primary lightens + red shadow, secondary/ghost → `--bg-hover`).
- **Transitions**: `all 0.2s`; collapsibles `max-height 0.3s ease`. No springs/parallax.

## State Management
Server-side routing drives which area/tab is active. Per-dashboard state that needs persistence:
- Keys: per-(env, secret) age/last-rotated/by; per-(env, token) expires_at/last-rotated/by; rotation audit log.
- KB: last-run + next-scheduled + run history (from the ingest job).
- GitHub: cached Actions API responses + editorial purpose/expected text.
No client state store required beyond the GitHub detail-pane selection.

## Assets
- Icons: **Font Awesome 6** (already self-hosted at `public/assets/fontawesome/`). Icons used: `fa-flag-checkered, fa-gear, fa-user-check, fa-gauge-high, fa-flag, fa-car, fa-users, fa-envelope, fa-trophy, fa-shield-halved, fa-key, fa-book-open, fa-comment-dots, fa-circle-question, fa-bolt, fa-ban, fa-plug, fa-file-shield, fa-clock-rotate-left, fa-code-compare, fa-triangle-exclamation, fa-circle-check, fa-circle-xmark, fa-magnifying-glass, fa-diagram-project, fa-rocket, fa-code, fa-box-archive, fa-ranking-star, fa-table-cells-large`, plus `fa-brands fa-github`.
- Logo: existing `public/assets/logo_header_dark.png` (mock uses a red flag-square placeholder — swap for the real logo).
- No photography, no new fonts (Kalam + Courier Prime already loaded).

## Files
- `Admin Dashboard Ideas.dc.html` — the full design reference (all areas, tabs, and dashboards). Open in a browser to see live behavior. Ignore the `sc-if`/`sc-for`/`{{ }}`/`support.js` mechanics — they are prototype-only.
- `screenshots/` — static captures of each area/tab for quick reference without running the HTML:
  - `01-core-races.png` — Core area, Races tab (nav shell)
  - `02-paddock-challenges-members.png` — Paddock Challenges area, Members tab
  - `03-dashboards-oversigt.png` — Dashboards area, Oversigt (overview)
  - `04-dashboards-keys.png` — Dashboards → Nøgler & Rotation
  - `05-dashboards-paddockkb.png` — Dashboards → PaddockKB
  - `06-dashboards-challenges.png` — Dashboards → Challenges (usage)
  - `07-dashboards-github-actions.png` — Dashboards → GitHub Actions
- Reference for existing tokens/patterns (in the main repo, not this bundle): `public/assets/css/style.css`, `public/includes/header.php`, `public/assets/js/app.js`.
