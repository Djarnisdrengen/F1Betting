---
name: f1-data-validation
description: Fact-checks factual claims in Paddock Challenges content (Rumor or Not cards, Weekly Trivia questions) against real-world F1 data from formula1.com, cross-checked with the Jolpica-F1 API. Use whenever the user asks to validate, fact-check, spot-check, or audit a rumor/trivia statement for accuracy, reports a suspicious or wrong-looking stat (e.g. "Perez didn't score that point"), or wants to review newly-generated/published challenge content before or after it goes live. Also trigger when the user asks "is this true", "can you verify this claim about [driver/race]", or wants a periodic accuracy sweep of admin-challenges.php content.
---

# F1 Data Validation

Fact-checks F1 statements — mainly Paddock Challenges content (Rumor or Not cards, Weekly Trivia
questions) — against real-world results. This exists because the content pipeline documented in
`docs/paddock-challenges-reference.md` ships **unreviewed**: Claude drafts cards/questions from
`paddock-rumors/data/knowledge-base.json`, and they auto-publish every Friday with no human gate.
The doc says it plainly: *"a factually-wrong rumor or a mis-keyed trivia answer reaches players
until someone deletes it... spot-checking the live tabs periodically is the mitigation."* This
skill is that spot-check, done rigorously against an authoritative source instead of by eyeballing.

This skill only **reads and reports**. It never edits the database or calls admin POST actions —
findings get handed to the user, who decides whether to fix content via `admin-challenges.php`.

---

## Two ways this gets triggered

**A. Check one specific claim** (the common case — e.g. "this trivia says Perez scored a point
at some 2026 race, but he hasn't scored all season"). Just verify that one statement and report
the verdict. Don't go looking for more work unless asked.

**B. Audit currently-live content.** The user wants a sweep of what's published. Content text
isn't exposed by any read-only endpoint (`test-seed.php`'s `list_challenge_content` action only
returns `id/status/publish_date`, not the text — see `public/tools/test-seed.php`), so pull the
actual rows one of these ways, whichever is available:
- A read-only `SELECT` against `challenge_items` (`text_da, text_en, is_real, explain_da,
  explain_en, source_ref, status, publish_date`) and/or `challenge_trivia_questions`
  (`question_da, question_en, options_da, options_en, correct_option, explain_da, explain_en,
  status, publish_date`) — schema at `database/schema.sql`. Ask the user for DB access/credentials
  if you don't have a route to run it; don't attempt destructive commands to get there.
- Or the user pastes/screenshots content from `admin-challenges.php` (Rumors/Trivia tabs).
- Or, if auditing a simulation run, read `bin/simulate-runs/<timestamp>/data.json` / `report.md`
  directly (no DB access needed).

Scope the sweep sensibly — recent `publish_date` batches (current + last few weeks), not the
entire archive, unless the user asks for a full historical pass.

---

## Critical distinction before checking anything: is this claim supposed to be true?

`challenge_items` (Rumor or Not) has an `is_real` flag. **Read it before fact-checking**, or
you'll flag intentional fiction as an error:

- **`is_real = 1`** — the card restates a real fact from the KB. `text_da`/`text_en` must be
  verifiably true. Check it against real data.
- **`is_real = 0`** — the card is a **deliberately fabricated** rumor (see
  `bin/generate-rumor-items.js`'s `draftRumorCard()`: *"Invent a plausible-SOUNDING but ENTIRELY
  FALSE rumor... it must be fictional"*). Do **not** flag `text_da`/`text_en` for being false —
  that's the point. Instead:
  1. Sanity-check it didn't *accidentally* turn out true (rare, but a fabricated rumor about a
     driver move or rule change could coincidentally match something that later actually happened).
  2. **Fact-check `explain_da`/`explain_en` instead** — that's the field telling the player "what's
     actually true instead," and it's the one that misleads someone if it's wrong.

**Trivia questions have no such flag** — every `challenge_trivia_questions` row is a genuine
factual question. Check the statement in `question_da`/`question_en` **and every option** in
`options_da`/`options_en`, not just the one at `correct_option`. A wrong distractor that happens
to also be true makes the question broken even if the marked answer is technically right.

If a card has a non-null `source_ref` (format `<kbDocId>:<contentHash>`, set in
`bin/generate-rumor-items.js`/`generate-trivia-questions.js`), that identifies the exact
`paddock-rumors/data/knowledge-base.json` doc it was drafted from. Read that doc directly (cheap,
no network) as a first check — it tells you whether the error is Claude misreading a correct doc
(synthesis drift) versus the doc itself being wrong. Either way, still verify against a live
source below before calling it correct — the KB doc is a snapshot, not ground truth.

---

## Extracting the claim

Break the statement into: **who** (driver/team), **what** (points scored, finishing position,
podium, pole, fastest lap, DNF/retirement, grid penalty, championship standing, contract/team
move, technical/rule detail), **when** (specific race, season-to-date, career). Get the season
year right before searching — KB docs span multiple seasons (current season gets a relevance
boost per `docs/paddock-rumors-reference.md`, but older content is still in the pool), so don't
assume "current season" if the statement doesn't say so.

---

## Verification sources, in order

**1. formula1.com — the authoritative source, use for the final citation.** Confirmed URL
patterns (verified live, current site structure):

| What | URL pattern |
|---|---|
| Season race list | `https://www.formula1.com/en/results/{year}/races` |
| One race's result | `https://www.formula1.com/en/results/{year}/races/{raceId}/{country-slug}/race-result` |
| Driver standings | `https://www.formula1.com/en/results/{year}/drivers` |
| One driver's page | `https://www.formula1.com/en/results/{year}/drivers/{DRIVERCODE}/{name-slug}` |
| Team standings | `https://www.formula1.com/en/results/{year}/team` |

`{raceId}` is an opaque numeric ID you can't guess (e.g. Belgium 2026 is `1290`) — use `WebSearch`
first (`site:formula1.com {year} {race name} race result`, or `site:formula1.com {year} driver
standings`) to resolve the exact URL, then `WebFetch` it for the actual numbers. Don't hand-build
a race-result URL from a guessed ID.

**2. Jolpica-F1 API — fast structured cross-check, no key needed.** This is the same source
`paddock-rumors/fetch-results.js` already uses to build the KB in this repo (`JOLPICA_BASE =
'https://api.jolpi.ca/ergast/f1'`), so it's a proven-reliable data source here, not a new
dependency. Good for a quick numeric gut-check before/after the formula1.com fetch:
- `https://api.jolpi.ca/ergast/f1/{year}/driverStandings.json`
- `https://api.jolpi.ca/ergast/f1/{year}/drivers/{driverId}/results.json`
- `https://api.jolpi.ca/ergast/f1/{year}/{round}/results.json`

If formula1.com and Jolpica disagree, trust formula1.com but **report the discrepancy** rather
than silently picking one — it usually means Jolpica hasn't caught up yet, or the claimed season/
round is wrong.

**3. Knowledge-base doc (if `source_ref` is set)** — as described above, checks synthesis drift,
not ground truth by itself.

---

## Worked example (the trigger case)

Claim: *"Perez scored a point at [some race] in 2026."*
1. `is_real` / trivia — treat as a checkable factual claim either way.
2. Jolpica: `GET https://api.jolpi.ca/ergast/f1/2026/driverStandings.json` → find Perez's entry.
3. formula1.com: `WebSearch` "site:formula1.com 2026 driver standings" → `WebFetch
   https://www.formula1.com/en/results/2026/drivers/SERPER01/sergio-perez` → confirm points total.
4. Both sources show **0 points** for Perez through the 2026 season (he's with Cadillac this
   year) → statement is **INCORRECT**. Verdict: flag for correction, cite both URLs.

---

## Reporting findings

For each statement checked, report:

- **Location** — table + row id (`challenge_items.<id>` / `challenge_trivia_questions.<id>`),
  or "user-supplied" if not sourced from the DB.
- **Statement** — the exact claim checked (quote it; note DA/EN if relevant).
- **Verdict** — ✅ Correct / ❌ Incorrect / 🟡 Partially correct (right idea, wrong detail — e.g.
  right driver, wrong race or wrong point count) / ⚠️ Unverifiable (sources don't cover it, or
  conflict without a clear resolution).
- **Ground truth + sources** — the actual fact, with the formula1.com URL (and Jolpica URL if
  used) actually fetched.
- **Recommended fix** — corrected wording if it's a wording fix, or a flag to Archive/Delete via
  `admin-challenges.php` if it's already live and wrong.

If checking multiple items, a short table is fine. Don't pad correct items with commentary —
lead with what's wrong, since that's the actionable part.

---

## Remediation stays with the user

This skill stops at reporting. `admin-challenges.php` has the actual fix actions: `archive_rumor_item`
/ `archive_trivia_question` (pull from rotation, reversible via Restore → draft), or
`delete_rumor_item` / `delete_trivia_question` (permanent) — per-row or bulk. Tell the user which
rows are wrong and let them pick Archive vs. Delete vs. hand-editing the wording; don't attempt to
call these endpoints yourself.

If the same error traces back to a bad or stale `paddock-rumors/data/knowledge-base.json` doc
(not just a bad synthesis of a correct doc), say so explicitly — every future draft pulling that
doc will repeat the mistake. Fixing the KB itself is the user's call (`paddock-rumors/` is an
isolated system per `docs/paddock-rumors-reference.md` — don't touch `f1-intelligence/` under any
circumstances, it serves live traffic and needs explicit approval for any change).
