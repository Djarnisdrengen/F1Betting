# Handoff: Race page — single-race focus · v2.3.0

> **Design System version:** `v2.3.0` · 2026-06-06 · see [CHANGELOG.md](./CHANGELOG.md)
>
> Adds **one new page** to the F1Betting site: a single-race detail/focus view at `public/race.php?id=N` that shows *everything we know about one race* at any point in its lifecycle. It is built entirely from the **existing race-box vocabulary** already used on `races.php` / `index.php`.
>
> The only genuinely new things in this release are: **(1)** the page itself and its layout, and **(2)** a qualifying-time schema pair (`quali_date` + `quali_time`) so the page can show *when qualifying starts* and count down to it. Nothing else changes.

---

## 0 · Ground rules — reuse the current implementation (read first, non-negotiable)

**This handoff describes a NEW PAGE. It does NOT authorise any change to the existing design system, styling, fonts, themes, colors, or shared components.**

When implementing, Claude Code MUST:

- **Reuse the live CSS as-is.** Use the existing classes (`.race-card`, `.countdown-timer`, `.quali-item`, `.position-badge`, `.bet-item`, `.badge`, `.btn`, …) exactly as they already render on `races.php`. Do not restyle them, do not "improve" them, do not fork them.
- **Reuse the live fonts and the live theme system untouched.** Whatever font stack, font toggle, dark/light/clubhouse theming, and `:root` tokens the repo ships today — leave them exactly as they are. Do **not** add fonts, change font assignments, or alter theme tokens.
- **Reuse the existing colors and tokens.** No new color tokens. The page must inherit its palette from the current tokens so it themes automatically.
- **Add, never modify.** The only code this release introduces is: the new `race.php` file, two nullable DB columns, a handful of small *additive* CSS classes for layout-only concerns (listed in §6), and new i18n keys. Every selector in §6 is new and namespaced — none overrides an existing rule.

> ⚠️ **If anything in the sections below appears to contradict the live implementation's styles, fonts, themes, or class behaviour — the live implementation wins.** The HTML/CSS in this doc and in `Race Page.html` is a *visual reference* for the new page's structure and content, rendered on a mirror of the tokens. Treat colours, exact paddings, and font-family declarations shown here as "whatever the live system already does", not as instructions to change anything. Reproduce the *structure and behaviour*, inherit the *styling*.

---

## 1 · What this page is

Today there is no single-race page — `races.php` is a vertical list of collapsible race-cards. This release gives **one race its own URL and full attention**: all available data for that race, on one page, adapting to where the race is in its lifecycle.

It is the **same `.race-card` you already ship**, promoted to a full page and always fully expanded (no collapse), with two additions specific to a focused view: a second countdown for **qualifying start**, and an always-open bets list.

`Race Page.html` in this project renders the target structure across all four lifecycle states (mobile-first XS, plus a wide proof), in the current dark + light themes, so you can see the intended content and layout. Build to match its **structure**, inheriting all **styling** from the live system per §0.

---

## 2 · Page structure (top → bottom)

1. **Back link** — "← Alle løb" to `races.php`.
2. **Identity block** — round eyebrow ("Runde 8 af 24"), race **title**, **location**, and the **status badge** (Afventer / Åbent for bud / Bud lukket / Afsluttet) using the existing `.badge .status-*` classes. `Pulje vundet` badge appears next to the title when `bettingpool_won`.
3. **Meta line** (`.race-meta`) — location, **race day · time CET**, and **qualifying day · time CET**. The qualifying line is a plain stopwatch-icon line (no "Kval." prefix) and only appears when `quali_date` is set.
4. **Schedule + countdowns** — a boxed group of `.countdown-timer` rows:
   - **Qualifying start** countdown (or *Afsluttet* once quali has run).
   - **Race start** countdown (or *Afsluttet* once the race has run).
   - The betting-window line: **"Bud åbner om …"** (pending) or **"Bud lukker om …"** (open).
   - **Pool size** — the existing modest gold `.bettingpool_size` treatment, exactly as on the race boxes today.
   - **Extra-small login CTA** (`.race-login-mini`) tucked at the bottom-right of this box — **open state, logged-out only** (see §5).
5. **Qualifying result** — `.position-badge` P1–P3 row when `quali_p1` is set; otherwise a dashed "vises efter kvalifikationen" placeholder. Never simply absent.
6. **Race result** — same pattern with `result_p1..p3`; placeholder "vises efter løbet" until set.
7. **Login CTA banner** (`.race-login-cta`) — open state, logged-out only.
8. **All bets** — always expanded. **Every member's bet with full P1/P2/P3 predictions visible at every state** (per product brief — no locking/hiding), plus placed-time. Once the race is scored, each row shows its points badge, the list sorts by points, and the perfect bet keeps its `.perfect-bet` gold glow + ★.

The page is **the same component in all four states** — content appears/changes, the layout role never switches (no modal, no breakpoint role-swap; see §7).

---

## 3 · Qualifying-timing schema (the one new data requirement)

The page shows **when qualifying starts** and counts down to it. The live `races` table has `race_date` + `race_time` but no qualifying equivalent, so add one nullable pair:

```sql
ALTER TABLE races
  ADD COLUMN quali_date DATE NULL AFTER race_time,
  ADD COLUMN quali_time TIME NULL AFTER quali_date;
```

- **Nullable** so existing rows don't break. When null: the page hides the qualifying meta line, hides the qualifying countdown, and the qualifying-result block falls back to its placeholder.
- The admin race form should gain the two fields (date + time) so editors can set qualifying timing. Backfill upcoming races.
- **Everything else maps to existing columns** — no other schema change: `name`, `location`, `race_date`, `race_time`, `round`, `total_rounds`, `quali_p1..p3`, `result_p1..p3`, `bettingpool_size`, `bettingpool_won`, and the joined `bets` rows (`display_name`, `p1..p3`, `points`, `is_perfect`, `placed_at`).

---

## 4 · The four lifecycle states

State is derived exactly like `getBettingStatus()` does today, plus the presence of result columns. Betting opens **48 h before race start** and closes at **lights-out** (race start) — unchanged from `races.php`.

| State | Trigger | Badge | Quali countdown | Race countdown | Betting line | Quali result | Race result | Bets | Login CTA |
|---|---|---|---|---|---|---|---|---|---|
| **Pending** | now < (race − 48 h) | `Afventer` | counts down | counts down | "Bud åbner om …" | placeholder | placeholder | empty state | — |
| **Open** | (race − 48 h) ≤ now < race start, no results | `Åbent for bud` | counts down | counts down | "Bud lukker om …" | placeholder | placeholder | full, predictions shown, points pending | **yes** (logged out) |
| **Quali done** | `quali_p1` set, `result_p1` null | `Bud lukket` | *Afsluttet* | counts down | — | **shown** | placeholder | full, predictions shown, points pending | — (closed) |
| **Completed** | `result_p1` set | `Afsluttet` (+`Pulje vundet` if `bettingpool_won`) | *Afsluttet* | *Afsluttet* | — | **shown** | **shown** | full, **scored + sorted** | — |

> The "Open" window may overlap qualifying in the current 48 h/lights-out logic. If the club ever wants betting to close at *qualifying* start instead, that's a one-line change in `getBettingStatus()` and doesn't affect this page's markup — only which state is returned.

---

## 5 · The two login affordances (open + logged-out only)

When `getBettingStatus()` returns `open` **and** there is no logged-in user, the page shows **two** entry points (and *only* in this state):

1. **Extra-small CTA** (`.race-login-mini`) — rendered **inline on the same row as the qualifying time** in the meta block (right-aligned via `justify-content: space-between` on that row). Low-commitment, in-context beside the actual timing.
2. **Full banner** (`.race-login-cta`) — the prominent banner above the bets list.

Both link to `login.php?redirect=race.php?id=<id>`. In every other state, and whenever a user is logged in, neither appears (the `quali`/`completed` states show a "Budrunden er lukket" note instead, exactly as the race boxes do today).

---

## 6 · New CSS — append to `public/assets/css/style.css` (layout-only, additive)

These are the **only** new style rules. They handle *layout grouping and the two new affordances* — they do **not** introduce colours, fonts, or theme values of their own; every colour below is an existing token or the existing `--f1-red` family, so the page themes automatically with the rest of the site. **Do not touch any existing rule.**

```css
/* ============================================================
   v2.3.0 — Single-race page additions (race.php). Additive only.
   Inherits all colour / font / theme behaviour from existing tokens.
   ============================================================ */

/* Boxed schedule panel grouping the countdown rows */
.race-schedule {
    margin-top: 1rem;
    padding: 0.875rem 1rem;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
/* Countdown rows as a 3-col grid so labels never collide with the value.
   Visual styling of .countdown-timer / .countdown-value stays as today. */
.race-schedule .countdown-timer {
    display: grid;
    grid-template-columns: 18px 1fr auto;
    align-items: baseline;
    gap: 0.375rem 0.625rem;
    margin: 0;
}
.race-schedule .countdown-timer .countdown-value { text-align: right; white-space: nowrap; }
.countdown-timer.done .countdown-value { color: var(--text-muted); }

/* Result-not-yet placeholder */
.result-pending {
    display: flex; align-items: center; gap: 0.625rem;
    margin-top: 0.5rem; padding: 0.75rem 0.875rem;
    border: 1px dashed var(--border-color); border-radius: 8px;
    color: var(--text-muted); font-size: 0.875rem;
}

/* Login CTA banner (open + logged-out only) */
.race-login-cta {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.875rem;
    margin-top: 1.125rem; padding: 1rem 1.125rem; border-radius: 12px;
    background: linear-gradient(90deg, rgba(225,6,0,0.12), rgba(225,6,0,0.03));
    border: 1px solid rgba(225,6,0,0.35);
}

/* Extra-small login CTA, beside the times in the schedule box (open + logged-out only) */
.race-login-mini {
    align-self: flex-end;
    display: inline-flex; align-items: center; gap: 0.4375rem;
    padding: 0.4375rem 0.8125rem; border-radius: 8px;
    background: rgba(225,6,0,0.10); border: 1px solid rgba(225,6,0,0.45);
    color: var(--f1-red-light); font-weight: 700; font-size: 0.78rem;
    text-decoration: none; white-space: nowrap;
}
.race-login-mini:hover { background: var(--f1-red); border-color: var(--f1-red); color: #fff; }

/* Empty bets state */
.bets-empty {
    padding: 1.25rem; text-align: center;
    border: 1px dashed var(--border-color); border-radius: 12px;
    color: var(--text-muted);
}
```

The existing `.countdown-timer` JS (`data-opens` / `data-closes`) only needs to *also* accept a generic `data-target` so both the qualifying and race counters can reuse one handler. Keep the live ticker exactly as-is; it already updates `.countdown-value` every second.

---

## 7 · Markup template — `public/race.php`

New file. The shape is the current `.race-card` with three deltas: **(a)** a qualifying meta line + a second `.countdown-timer` for qualifying, **(b)** the bets section is *always expanded* (no `.hidden` / toggle), **(c)** the two login affordances when open + logged out. Header/footer includes, the `t()` i18n helper, `getBettingStatus()`, `getDB()`, driver lookup, and bet loading are all reused from the existing pages — copy the patterns from `races.php`.

```php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
$db = getDB();
$race = $db->prepare("SELECT * FROM races WHERE id = ?"); $race->execute([$_GET['id']]);
$race = $race->fetch();
$status = getBettingStatus($race);          // 'pending' | 'open' | 'closed' | 'completed'
$currentUser = getCurrentUser();
// … load $driversById, $raceBets (with display_name) exactly as races.php does …
$raceDT  = new DateTime($race['race_date'].' '.$race['race_time']);
$qualiDT = $race['quali_date'] ? new DateTime($race['quali_date'].' '.$race['quali_time']) : null;
$bettingOpens = (clone $raceDT)->modify('-48 hours');
include __DIR__ . '/includes/header.php';
?>

<a href="races.php" class="text-muted" style="text-transform:uppercase;letter-spacing:.08em;font-size:.75rem;">&larr; <?= t('all_races') ?></a>

<div class="card race-card">
  <div class="race-header">
    <div>
      <div class="text-muted" style="font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;">
        <?= t('round') ?> <?= (int)$race['round'] ?> <?= t('of') ?> <?= (int)$race['total_rounds'] ?>
      </div>
      <h1 class="race-title">
        <?= escape($race['name']) ?>
        <?php if ($race['bettingpool_won']): ?><span class="badge status-pool-won"><i class="fas fa-trophy"></i> <?= t('pool_won') ?></span><?php endif; ?>
      </h1>
      <div class="race-meta">
        <span><i class="fas fa-map-marker-alt"></i> <?= escape($race['location']) ?></span>
        <span><i class="fas fa-flag-checkered"></i> <?= date('d M Y', $raceDT->getTimestamp()) ?> · <?= $raceDT->format('H:i') ?> CET</span>
        <?php if ($qualiDT): ?><span><i class="fas fa-stopwatch"></i> <?= date('d M', $qualiDT->getTimestamp()) ?> · <?= $qualiDT->format('H:i') ?> CET</span><?php endif; ?>
      </div>
    </div>
    <span class="badge status-<?= $status['status'] ?>"><?= $status['label'] ?></span>
  </div>

  <!-- Schedule + countdowns (boxed) -->
  <div class="race-schedule">
    <?php if ($qualiDT && !$race['quali_p1']): ?>
      <div class="countdown-timer" data-target="<?= $qualiDT->format('c') ?>">
        <i class="fas fa-stopwatch"></i> <?= t('quali_starts') ?>: <span class="countdown-value">--</span>
      </div>
    <?php elseif ($race['quali_p1']): ?>
      <div class="countdown-timer done"><i class="fas fa-stopwatch"></i> <?= t('qualifying') ?>: <span class="countdown-value"><?= t('finished') ?></span></div>
    <?php endif; ?>

    <?php if (!$race['result_p1']): ?>
      <div class="countdown-timer" data-target="<?= $raceDT->format('c') ?>">
        <i class="fas fa-flag-checkered"></i> <?= t('race_starts') ?>: <span class="countdown-value">--</span>
      </div>
    <?php else: ?>
      <div class="countdown-timer done"><i class="fas fa-flag-checkered"></i> <?= t('race') ?>: <span class="countdown-value"><?= t('finished') ?></span></div>
    <?php endif; ?>

    <?php if ($status['status'] === 'pending'): ?>
      <div class="countdown-timer" data-target="<?= $bettingOpens->format('c') ?>"><i class="fas fa-hourglass-half"></i> <?= t('betting_opens_in') ?>: <span class="countdown-value">--</span></div>
    <?php elseif ($status['status'] === 'open'): ?>
      <div class="countdown-timer betting-open" data-target="<?= $raceDT->format('c') ?>"><i class="fas fa-lock-open"></i> <?= t('betting_closes_in') ?>: <span class="countdown-value">--</span></div>
    <?php endif; ?>

    <?php if ($status['status'] === 'open' && !$currentUser): ?>
      <a href="login.php?redirect=race.php?id=<?= (int)$race['id'] ?>" class="race-login-mini"><i class="fas fa-right-to-bracket"></i> <?= t('login_to_bet') ?></a>
    <?php endif; ?>

    <?php if ($race['bettingpool_size']): ?>
      <div class="countdown-timer"><i class="fas fa-dollar-sign bettingpool_size"></i> <?= t('pool_size') ?> <span class="bettingpool_size"><?= escape($race['bettingpool_size']) ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Qualifying result (or placeholder) -->
  <div style="margin-top:1rem;">
    <small class="text-muted"><?= t('qualifying') ?>:</small>
    <?php if ($race['quali_p1']): ?>
      <div class="quali-row"><?php foreach (['quali_p1','quali_p2','quali_p3'] as $i=>$k){ $d=$driversById[$race[$k]]??null; if($d){ echo '<div class="quali-item"><span class="position-badge position-'.($i+1).'">P'.($i+1).'</span> '.escape($d['name']).'</div>'; } } ?></div>
    <?php else: ?>
      <div class="result-pending"><i class="fas fa-hourglass-half"></i> <?= t('result_after_quali') ?></div>
    <?php endif; ?>
  </div>

  <!-- Race result (or placeholder) — same pattern with result_p1..p3 / t('result_after_race') -->

  <!-- Login CTA banner — only when betting open + logged out -->
  <?php if ($status['status'] === 'open' && !$currentUser): ?>
    <div class="race-login-cta">
      <div><strong><?= t('betting_open') ?></strong><br><span class="text-secondary"><?= t('login_to_bet_hint') ?></span></div>
      <a href="login.php?redirect=race.php?id=<?= (int)$race['id'] ?>" class="btn btn-primary"><i class="fas fa-right-to-bracket"></i> <?= t('login_to_bet') ?></a>
    </div>
  <?php endif; ?>

  <!-- All bets — ALWAYS expanded, full predictions visible -->
  <div class="bets-section">
    <div class="bets-header"><h4><?= t('all_bets') ?> (<?= count($raceBets) ?>)</h4>
      <?php if ($race['result_p1']): ?><small class="text-muted"><?= t('sorted_by_points') ?></small><?php endif; ?></div>
    <?php
      if ($race['result_p1']) usort($raceBets, fn($a,$b)=>$b['points']<=>$a['points']);
      foreach ($raceBets as $bet):
        $isMe = $currentUser && $bet['user_id']===$currentUser['id'];
    ?>
      <div class="bet-item <?= $bet['is_perfect']?'perfect-bet':'' ?> <?= $isMe?'my-bet':'' ?>">
        <div class="bet-user">
          <div class="bet-avatar"><?= escape(strtoupper(substr($bet['display_name']?:$bet['email'],0,1))) ?></div>
          <div><strong class="flex items-center gap-1"><?= escape($bet['display_name']?:$bet['email']) ?>
            <?php if($isMe):?><span class="badge" style="background:var(--f1-red);color:#fff;"><?= t('you_badge') ?></span><?php endif;?>
            <?php if($bet['is_perfect']):?><span class="star">★</span><?php endif;?></strong>
            <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small></div>
        </div>
        <div class="flex items-center gap-1">
          <div class="bet-predictions"><?php foreach(['p1','p2','p3'] as $i=>$k){ $d=$driversById[$bet[$k]]??null; echo '<span class="bet-pred"><b>P'.($i+1).':</b> '.($d?escape(end(explode(' ',$d['name']))):'?').'</span>'; }?></div>
          <?php if($race['result_p1']):?><span class="badge" style="background:var(--f1-red);color:#fff;"><?= (int)$bet['points'] ?> pts</span><?php else:?><span class="text-muted">— pts</span><?php endif;?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$raceBets): ?><div class="bets-empty"><?= t('no_bets_yet') ?></div><?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
```

> The inline `style="…"`, `var(--f1-red)`, and class names above are copied from existing pages so the page inherits current styling. If the live `races.php` does any of this differently, follow the live pattern — see §0.

---

## 8 · Breakpoint behaviour (no role switches)

Per `CLAUDE.md`, components that change **role** across breakpoints must be flagged. **This page has none** — it is the same single-race view at every width. It only *reflows*:

| Element | XS / SM | MD+ |
|---|---|---|
| `.bet-item` | stacks vertical (the live `.bet-item` mobile rule) | single-line row |
| Schedule countdown rows | 1 column | 1 column |
| Result blocks | stacked | stacked |

No modal↔page, no tabs↔dropdown, no sidebar↔sheet. Implementers do not need to read any JSX to discover a hidden role change — there isn't one.

---

## 9 · Acceptance criteria

- [ ] **AC-RACE-01** — `public/race.php?id=N` renders one race using the existing `.race-card` vocabulary and **inherits the live styling, fonts, and theme** with zero changes to existing CSS/tokens (per §0). Visually consistent with a card on `races.php`.
- [ ] **AC-RACE-02** — Both **qualifying start** and **race start** show a time-of-start (in `.race-meta`) AND a live countdown (in the schedule box). When `quali_date` is null, the qualifying meta line + countdown are hidden (no broken "--", no "Kval." label).
- [ ] **AC-RACE-03** — The four states render per §4, driven by `quali_p1` / `result_p1` / current time relative to `race_date − 48h` and `race_date`.
- [ ] **AC-RACE-04** — Pool size renders with the **existing** `.bettingpool_size` gold treatment — not enlarged, not a hero. Matches the current race boxes.
- [ ] **AC-RACE-05** — Qualifying result and race result each render as `.position-badge` P1–P3 rows when present, and a dashed "vises efter…" placeholder when not. The section is never simply absent.
- [ ] **AC-RACE-06** — **All** members' bets are listed with **full** P1/P2/P3 predictions at every state (no locking/hiding). Placed-time shown. Own bet gets `.my-bet`; perfect bet gets `.perfect-bet` + ★.
- [ ] **AC-RACE-07** — When state is `open` AND the viewer is logged out, **both** login affordances appear: the extra-small `.race-login-mini` in the schedule box and the `.race-login-cta` banner above the bets, each linking to `login.php?redirect=…`. **Both are absent** in every other state and whenever logged in.
- [ ] **AC-RACE-08** — Once `result_p1` is set, each bet shows its points badge and the list sorts by points descending; before that, points read "— pts".
- [ ] **AC-RACE-09** — Countdown values keep ticking via the existing ticker (now also reading `data-target`) and don't reflow as they update.
- [ ] **AC-RACE-10** — No horizontal scroll at 320px. Bet rows stack at XS. Login/CTA tap targets ≥ 44px.
- [ ] **AC-RACE-11** — The page reads correctly in **every theme and font stack the site already supports**, with no theme/font changes made by this release.

---

## 10 · Migration sweep

1. **Schema** — run the `ALTER TABLE` in §3. Add `quali_date` + `quali_time` to the admin race form. Backfill upcoming races.
2. **`public/race.php`** — new file per §7.
3. **Link in** — point the race title / "view" affordance on `races.php`, and the next-race hero on `index.php`, at `race.php?id=<id>`.
4. **CSS** — paste §6 at the bottom of `style.css`. Every selector is new and additive — confirm nothing existing was edited.
5. **Countdown JS** — extend the existing ticker to also accept `data-target` (alias of `data-opens`/`data-closes`).
6. **i18n** — add keys: `round`, `of`, `quali_starts`, `race_starts`, `race`, `finished`, `result_after_quali`, `result_after_race`, `betting_open`, `login_to_bet`, `login_to_bet_hint`, `sorted_by_points`, `no_bets_yet`, `all_races` (DA + EN).
7. **Verify AC-RACE-01 → AC-RACE-11** on staging across XS/MD, every existing theme + font stack, all four states.

---

## 11 · What's explicitly NOT in this release

- **No change to the design system** — no token, color, font, theme, or existing-class changes (see §0). Additions only.
- No change to `races.php` list behaviour (the collapsible cards stay) — this is an *additional* page.
- No change to the bet-placement flow or `bet.php` — the CTAs just route to login.
- No locking of bets — per product brief, full bet info is always visible.
- The only new data: the `quali_date` / `quali_time` columns.

---

## 12 · Implementation time

~1–1.5 hr: schema + admin field, new `race.php` (mostly copied from `races.php`'s card), the §6 additive CSS, i18n keys, and the AC run.
