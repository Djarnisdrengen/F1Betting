# Handoff: Login + MFA challenge — simplified · v3.0.0

> **Design System version:** `v3.0.0` · 2026-07-07 · see [CHANGELOG.md](./CHANGELOG.md)
>
> Simplifies the two-factor **challenge screen** (`login.php` / the MFA step) so it no longer shows a separate input + button for every method. Login screen stays essentially as-is. Uses the **list → detail** layout (one method at a time).
>
> Interactive reference: `Login and MFA Challenge.html` (toolbar toggles dark/light; passkey, the "Andre muligheder" expander, method switching, and OTP auto-advance are all live).

---

## 0 · Ground rules (read first)

- **Reuse the live implementation.** Existing classes, tokens, fonts, and themes are used as-is; the reference HTML mirrors them. Where the mockup's exact padding/colour differs from production, **production wins** — reproduce *structure and behaviour*, inherit *styling*.
- **Additive only.** New markup for the challenge view + a small block of additive CSS + i18n keys. No token/font/theme change; no existing class modified.
- **Do not change the auth backend.** This is a UI reshape of the challenge step. Wire the same passkey / TOTP / email-OTP / recovery-code endpoints you already have.

---

## 1 · The problem & the fix

**Today** (see screenshot 1): the challenge screen stacks *every* MFA method at once — an input field and a button for the authenticator code, a "send code" button for email, and an input field + button for recovery codes — all visible simultaneously. It reads as a wall of controls.

**The fix:** show **one method at a time**. The challenge presents the enabled MFA methods as a **short list**; picking one opens a focused detail view with **one** input and **one** confirm button for just that method.

**Passkey is not on the challenge screen.** Passkey is initiated from the login screen's green "Log ind med passkey" button (a passwordless path that skips this second-factor step entirely). The challenge is only reached after a *password* login, so it offers the non-passkey factors the user has enabled: authenticator, email code, recovery code.

The login screen (screenshot 2) is already clean and stays as-is: email + password primary, passkey secondary, one helper line.

---

## 2 · Shared challenge anatomy (both directions)

Top to bottom, inside the existing auth card:

1. **Method pill** — small red pill; `TO-TRINS LOGIN` on the root list, the method name on a detail view.
2. **Heading** — "Verificér din identitet".
3. **Subtitle** — root: "Vælg hvordan du vil bekræfte, at det er dig."; detail: one line describing the chosen method.
4. **Method list** — the enabled methods as tappable rows (icon tile · title · one-line hint · chevron), shown **directly** (no passkey block, no disclosure to open).

Methods, in order: **Authenticator-app** (`fa-shield-halved`), **Kode på e-mail** (`fa-envelope`), **Gendannelseskode** (`fa-key`). Render only the factors the user has actually enabled. **If exactly one factor is enabled, skip the list entirely** and open that method's detail view directly (no single-item list, no back link needed).

---

## 3 · Chosen layout — list → detail swap

> **Decision:** ship this layout (formerly "Direction A"). The alternative segmented-switch layout was dropped. The reference `Login and MFA Challenge.html` now shows only this one.

The root view is a **vertical list of method rows** (icon tile · title · one-line hint · chevron). Tapping a row **replaces the whole card body** with that method's detail view:

- **← Tilbage** link back to the method list.
- Pill + heading + method-specific subtitle.
- **One** input (OTP boxes, or the recovery field).
- **One** "Bekræft →" button.

Each method gets its own focused screen — never more than one input visible, which is the direct fix for the reported clutter. Cleanest on mobile.

---

## 5 · Method behaviours (both directions)

- **Authenticator** — **6 separate OTP boxes**, numeric, **auto-advance** on entry and **backspace-to-previous**; full 6-digit **paste** distributes across boxes. Subtitle: "Indtast den 6-cifrede kode fra din authenticator-app."
- **Email OTP** — **auto-sends on selection** (no separate "send" button); goes straight to the OTP boxes with a green "Kode sendt · tjek din indbakke" confirmation and a "Send igen" link. Address shown masked (`th•••@hpovlsen.dk`).
- **Recovery code** — single monospace field, placeholder `XXXXX-XXXXX`, one "Bekræft →".

---

## 6 · States

| State | What shows |
|---|---|
| Root | Pill `TO-TRINS LOGIN`, heading, and the enabled-method list |
| Method active | One input + one "Bekræft →" on that method's own detail screen, with a "Tilbage" link |
| Email selected | Auto-send banner + OTP + "Send igen" |

Error state (wrong code): reuse the existing `.alert alert-error` / inline field-error pattern from `login.php` — show it directly under the input; keep the single-method layout.

---

## 7 · New CSS — append to `public/assets/css/style.css` (additive)

All new selectors; every colour is an existing token or the `--f1-red` family, so both themes work automatically. See `Login and MFA Challenge.html` for the full, ready-to-lift rules — key pieces:

```css
/* v3.0.0 — MFA challenge: one method at a time. Additive only. */

/* "Andre muligheder" disclosure */
.more-toggle { display:inline-flex; align-items:center; gap:8px; background:none; border:0;
  cursor:pointer; color:var(--text-secondary); font:600 13px/1 var(--font-display); }
.more-toggle .chev { transition:transform .18s; }
.more-toggle.open .chev { transform:rotate(180deg); }

/* Direction A — method rows */
.method { display:flex; align-items:center; gap:13px; width:100%; padding:13px 14px;
  border-radius:12px; cursor:pointer; text-align:left;
  background:var(--bg-secondary); border:1px solid var(--border-color); color:var(--text-primary); }
.method:hover { background:var(--bg-hover); border-color:var(--f1-red); }
.method .ic { width:38px; height:38px; border-radius:10px; display:inline-flex;
  align-items:center; justify-content:center; color:var(--f1-red-light);
  background:var(--bg-card); border:1px solid var(--border-soft); }

/* Segmented switch (unused in the shipped list→detail layout; omit) */
.mfa-switch { display:flex; gap:6px; padding:4px; border-radius:11px;
  background:var(--bg-secondary); border:1px solid var(--border-color); }
.mfa-switch button { flex:1; border:0; background:transparent; cursor:pointer; border-radius:8px;
  padding:9px 6px; color:var(--text-secondary); font:700 12px/1 var(--font-display); }
.mfa-switch button.on { background:var(--f1-red); color:#fff; }

/* OTP boxes (both directions) */
.otp { display:flex; gap:9px; justify-content:space-between; }
.otp input { width:100%; aspect-ratio:1/1.1; text-align:center; border-radius:10px;
  background:var(--bg-secondary); border:1px solid var(--border-color); color:var(--text-primary);
  font:700 22px/1 var(--font-mono); outline:none; }
.otp input:focus { border-color:var(--f1-red); box-shadow:0 0 0 4px rgba(225,6,0,.14); }

/* Email auto-send confirmation */
.code-sent { display:flex; align-items:center; gap:10px; padding:11px 13px; border-radius:10px;
  background:rgba(30,138,91,.12); border:1px solid rgba(30,138,91,.4);
  color:var(--text-secondary); font-size:12.5px; }
.code-sent i { color:#2fb37f; }
```

Light theme: because every value above is a token or a fixed brand red/green, no `body.light` overrides are required.

---

## 8 · OTP auto-advance JS

Minimal behaviour to wire on the 6 inputs (vanilla or your framework):
- On input: keep only digits; if a value is entered, focus the next box. A 6-char paste fills all boxes from the current index.
- On `Backspace` in an empty box: focus the previous box.
- Submit assembles the 6 values in order. Reference implementation is the `Otp` component in `Login and MFA Challenge.html`.

---

## 9 · Breakpoint-conditional components

Per `CLAUDE.md`: **the challenge screen does not change role across breakpoints** — same single-method card at every width (it only reflows within the fixed card). No modal↔page or tabs↔dropdown swap.

- Login stays a single centered card (its existing LG 2-column editorial split, if present, is unchanged).

---

## 10 · Acceptance criteria

- [ ] **AC-MFA-01** — The challenge screen shows the enabled MFA methods as a short list; **at most one method's input is visible at a time** (the reported clutter is gone). **No passkey button appears on the challenge** — passkey is only the login screen's green button.
- [ ] **AC-MFA-02** — Picking a method presents exactly **one** input and **one** "Bekræft →" for that method, on its own detail screen with a "Tilbage" link back to the method list.
- [ ] **AC-MFA-03** — Authenticator + email codes use **6 separate OTP boxes** with auto-advance, backspace-to-previous, and 6-digit paste distribution.
- [ ] **AC-MFA-04** — Selecting the **email** method **auto-sends** the code (no separate send button), shows a "Kode sendt" confirmation and a "Send igen" link, and reveals the OTP input directly.
- [ ] **AC-MFA-05** — Only the factors the user has enabled are listed. **When exactly one factor is enabled, the list is skipped and that method's input opens directly** (no single-item list).
- [ ] **AC-MFA-06** — The recovery method uses one monospace `XXXXX-XXXXX` field + one confirm.
- [ ] **AC-MFA-07** — Wrong-code errors render inline under the active input using the existing error pattern, without breaking the single-method layout.
- [ ] **AC-MFA-08** — Everything reads correctly in **dark + light** and in both font stacks, with **no** changes to existing tokens/classes.
- [ ] **AC-MFA-09** — No horizontal scroll at 320px; OTP boxes and buttons keep ≥44px tap targets.
- [ ] **AC-LOGIN-01** — Login screen is unchanged in role: email + password primary, passkey secondary, one helper line. (Optional polish only.)

---

## 11 · Migration sweep

1. Rebuild the challenge step's markup per §2–§5 (new file/partial, e.g. the MFA branch of `login.php` or a `challenge.php`), reusing the existing card + button classes.
3. Append §7 CSS to `style.css`; wire §8 OTP JS.
4. Add i18n keys (DA + EN): `verify_identity`, `choose_verify_method`, `other_options`, `authenticator_app`, `enter_6_digit`, `email_code`, `send_one_time_code`, `code_sent`, `resend_code`, `recovery_code`, `use_backup_code`, `back`, `confirm`.
5. Verify AC-MFA-01 → AC-MFA-09 + AC-LOGIN-01 across XS/desktop, both themes, all methods.

---

## 12 · Implementation time

~1.5–2 hr: reshape the challenge markup for the chosen direction, the additive CSS, the OTP JS, i18n, and the AC run. Backend endpoints unchanged.
