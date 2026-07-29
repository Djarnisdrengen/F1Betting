---
name: motorsport-en-da-translator
description: English-to-Danish translation skill specialized in motorsport/F1 terminology, for the Paddock Picks bilingual UI (public/lang/*.php). Use whenever the user asks to translate strings, add or update `t()` keys, write Danish copy for a new feature, review existing Danish copy for accuracy/tone, or localize F1-specific terms (qualifying, podium, pit stop, grid penalty, DNF, etc.). Also trigger when the user pastes English UI text, email copy, or admin labels and asks for the Danish equivalent, or asks "how would we say X in Danish" for anything race/betting related.
---

# Motorsport English → Danish Translator

Translates English copy into Danish for Paddock Picks, a bilingual (`da` default / `en`) F1 prediction game. Danish is not generic Danish — it follows the terse, informal, motorsport-fluent register already established in `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php`.

## Before translating anything

1. **Grep the existing lang files first.** `public/lang/user.php`, `public/lang/admin.php`, `public/lang/email.php` are the authority on tone and existing terms — never invent a new Danish word for a concept that's already translated elsewhere. Search both the `'da'` and `'en'` arrays for the English term or a close synonym before proposing a translation.
2. **Match the register already in use**: informal "du" (never "De"), short/direct labels (button and nav copy is often one or two words), sentence case not Title Case in Danish (English source may be Title Case — do not carry that over).
3. Danish letters æ/ø/å are used natively (`Løb`, `Sæson`, `Kørere`) — don't substitute ae/oe/aa.

## Established project glossary (do not deviate)

These are the actual translations already live in the codebase — reuse them verbatim:

| English | Danish (in use) |
|---|---|
| Race(s) | Løb |
| Driver | Kører |
| Leaderboard | Rangliste |
| Qualifying | Kvalifikation |
| Team | Hold |
| Season | Sæson |
| Place bet | Placer Bet |
| Bet / Bets | **Bet / Bets** (kept as English loanword — not "væddemål") |
| Result(s) | Resultat(er) |
| Points | Point |
| Race starts | Løbet starter |
| Qualifying starts | Kvalifikation starter |
| In competition | I konkurrence |
| Settings | Indstillinger |
| Edit / Edit profile | Rediger / Rediger Profil |
| Language | Sprog |
| Betting open/closed | Betting Åben / Betting Lukket |

Note the pattern: core betting-game vocabulary ("Bet", "Betting") is deliberately kept English — it's the product's own jargon, not translated like ordinary nouns. Follow this precedent for new betting-specific terms rather than translating them literally.

## General motorsport/F1 glossary (for terms not yet in the codebase)

Danish motorsport media (DR, TV3+, Viaplay commentary) keeps a lot of English technical jargon as-is. Use this as the default when introducing new terms — check first whether the concept already has a project precedent above.

| English | Danish |
|---|---|
| Podium | Podie |
| Pole position | Pole position / Pole |
| Grid / starting grid | Startopstilling / grid |
| Grid penalty | Startpladsstraf |
| Practice session | Træning |
| Sprint race | Sprintløb |
| Sprint shootout | Sprint-kvalifikation |
| Safety car | Safety car (kept) |
| Virtual safety car | Virtuel safety car |
| DRS | DRS (kept, technical acronym) |
| Chequered flag | Målflag |
| Red/yellow flag | Rødt/gult flag |
| Formation lap | Opvarmningsrunde |
| Lap | Omgang |
| Fastest lap | Hurtigste omgang |
| Overtake | Overhaling |
| Undercut / overcut | Undercut / overcut (kept, strategy jargon) |
| Pit stop / pit lane | Pitstop / pitlane |
| Constructor | Konstruktør |
| Constructors' championship | Konstruktørmesterskab |
| Drivers' championship | Kørermesterskab |
| Standings | Stilling |
| Retired / DNF | Udgået |
| Disqualified (DSQ) | Diskvalificeret |
| Circuit / track | Bane |
| Corner / turn | Sving |
| Steward | Steward (kept) or løbsdommer |
| Team principal | Teamchef |
| Penalty | Straf |
| Paddock | Paddock (kept — the product is literally named after it) |

Driver names, team names, and circuit names (Verstappen, Red Bull, Silverstone, etc.) are never translated or transliterated.

## Applying a translation to the codebase

When adding or changing a `t()` string:

1. Add the key to **both** the `'da'` and `'en'` arrays in the same lang file, keeping them in the same relative position/section (each file groups keys under `// Comment` section headers — slot the new key into the matching section rather than appending at the end).
2. **Check for duplicate keys before saving.** PHP array literals silently let a later duplicate key win with no warning — `public/lang/user.php` has had this bug before (multiple keys defined twice, later one silently overriding). Grep the key name in the target file first; if it already exists, edit that line instead of adding a new one.
3. Keep alignment/spacing consistent with the surrounding lines (this codebase visually aligns `=>` within each block).
4. Use `t('key')` at the call site — never hardcode Danish or English strings directly in `.php` view files.
5. If the string includes a placeholder (name, count, date), check how existing keys handle interpolation (e.g. `sprintf`-style or string replacement) in the same file rather than introducing a new pattern.

## Tone check before delivering

- Would a Danish F1 fan say it this way, or does it read like a literal translation? Prefer how Viaplay/TV3+ commentators phrase things over a dictionary-accurate but stiff rendering.
- Is it as short as the English original? Danish UI copy in this project is consistently terser than a literal translation would produce.
- Did you accidentally translate a term that's established as an English loanword in this codebase (Bet, DRS, Safety car, Paddock, undercut)? Revert those to English.
