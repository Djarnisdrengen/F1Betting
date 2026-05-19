# Design system migration — v1.3.0

## Handoff location
`/home/thomas-helveg-povlsen/Downloads/design_handoff_redesign_v1.3.0`

## Branch
`redesign/v1.3.0` — all implementation commits go here; merge to `main` only after all §7 ACs pass.

## Implementation phases
A — CSS tokens & shell
B — Header + drawer nav
C — Bottom bar partial
D — Per-page templates (8 pages)
E — Admin + Bet modal
F — Backend changes (leaderboard rank delta, pool size DKK)
G — Email templates (5 transactional emails)

## Post-migration cleanup tasks
- [ ] **Delete obsolete language keys** — after all pages are ported, audit `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php` for keys no longer referenced anywhere in the codebase and remove them.
- [ ] **Merge `bet.php` and `edit_bet.php`** — the 5-step bet modal design assumes a single page. After the redesign ships, merge the two pages into one to eliminate the duplicated modal UI/JS.
