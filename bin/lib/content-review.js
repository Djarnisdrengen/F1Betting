/**
 * Pre-import review pass for drafted Rumor-or-Not cards / Trivia questions. Applies the same
 * rubric documented in .claude/commands/f1-data-validation.md (fact-check against the source doc)
 * and motorsport-en-da-translator.md (Danish glossary/register), so the unattended weekly
 * generation pipeline gets that scrutiny automatically instead of relying solely on manual
 * spot-checks on admin-challenges.php.
 *
 * Scope limit: this only checks a drafted item against the same KB doc it was grounded on
 * (catches synthesis drift), not a live formula1.com/Jolpica cross-check — a bad or stale KB doc
 * itself still needs the manual /f1-data-validation skill or a periodic admin spot-check.
 *
 * Takes the caller's own `claude(prompt, maxTokens)` helper as a parameter rather than making its
 * own API calls — both generator scripts already have that function.
 */

const DA_GLOSSARY = `
Reuse these established translations verbatim — never invent alternatives:
Race(s)=Løb, Driver=Kører, Leaderboard=Rangliste, Qualifying=Kvalifikation, Team=Hold, Season=Sæson,
Points=Point, Podium=Podie, Grid=Startopstilling/grid, Grid penalty=Startpladsstraf,
Pole position=Pole position/Pole, Sprint race=Sprintløb, DNF/Retired=Udgået,
Disqualified=Diskvalificeret, Fastest lap=Hurtigste omgang, Overtake=Overhaling,
Pit stop=Pitstop, Constructor=Konstruktør, Standings=Stilling.
Kept as English loanwords, never translated: Bet/Bets, DRS, Safety car, Paddock, undercut/overcut, Steward.
Register: informal "du" (never "De"); sentence case, not Title Case; short/direct phrasing (terser
than a literal translation); native æ/ø/å (never ae/oe/aa substitutes); driver/team/circuit names
are never translated.
`.trim();

function factCheckInstructions(kind, item) {
    if (kind === 'trivia') {
        return `Check the question AND EVERY option (not just the one at correct_option) against
the source document below. Flag if the question, the marked correct answer, or any distractor
option contradicts or isn't actually supported by the source — a wrong distractor that happens to
also be true makes the question broken even if the marked answer is technically right.`;
    }
    if (item.is_real) {
        return `This card is marked as a REAL fact (is_real=true) — text_da/text_en must be
verifiably supported by the source document below. Flag any detail (number, name, position, race)
that isn't actually in the source.`;
    }
    return `This card is a DELIBERATELY FABRICATED rumor (is_real=false) — text_da/text_en is
SUPPOSED to be false, do NOT flag it for being untrue, that's the point. Instead check
explain_da/explain_en (the "what's actually true instead" reveal shown after answering) — that
field must be accurate and must not itself assert the fabricated rumor is real.`;
}

function fieldsFor(kind, item) {
    if (kind === 'trivia') {
        return {
            question_da: item.question_da, question_en: item.question_en,
            options_da: item.options_da, options_en: item.options_en,
            correct_option: item.correct_option,
            explain_da: item.explain_da, explain_en: item.explain_en,
        };
    }
    return {
        is_real: item.is_real,
        context_da: item.context_da, context_en: item.context_en,
        text_da: item.text_da, text_en: item.text_en,
        explain_da: item.explain_da, explain_en: item.explain_en,
    };
}

function stripFences(text) {
    return text.trim().replace(/^```(?:json)?\n?/, '').replace(/```$/, '').trim();
}

/**
 * @param {(prompt: string, maxTokens?: number) => Promise<string>} claude
 * @param {{kind: 'rumor'|'trivia', item: object, doc: {content: string}}} params
 * @returns {Promise<{pass: boolean, issues: string[], factVerdict?: string, translationVerdict?: string, reviewError?: string}>}
 */
async function reviewDraft(claude, { kind, item, doc }) {
    const label = kind === 'trivia' ? 'trivia question' : 'Rumor-or-Not card';
    const prompt = `You are reviewing drafted ${label} content for an F1 prediction game before it
ships to players. Do two independent checks and report both.

CHECK 1 — FACT CHECK. ${factCheckInstructions(kind, item)}

SOURCE DOCUMENT it was drafted from:
"""
${doc.content.slice(0, 1200)}
"""

CHECK 2 — DANISH TRANSLATION QUALITY. Review every *_da field against this project glossary:
${DA_GLOSSARY}
Flag: wrong/invented terminology where an established term exists above, wrong register ("De"
instead of "du", Title Case, or a translation noticeably longer/stiffer than the English), or an
established English loanword that got wrongly translated.

DRAFTED CONTENT:
${JSON.stringify(fieldsFor(kind, item), null, 2)}

Respond with ONLY a JSON object, no prose, no markdown fences, in exactly this shape:
{"fact_check":{"verdict":"pass|fail|unverifiable","issues":["..."]},"translation_check":{"verdict":"pass|fail","issues":["..."]}}
Use "fail" only for a clear, concrete problem you can name — not stylistic nitpicking. Empty
"issues" arrays are fine when there's nothing to flag.`;

    let raw;
    try {
        raw = await claude(prompt, 500);
    } catch (e) {
        // Fail open: a reviewer hiccup (network blip, API error) must not silently mass-downgrade
        // a whole batch — let the item ship as originally intended, but say so loudly.
        return { pass: true, issues: [], reviewError: e.message.slice(0, 160) };
    }

    let verdict;
    try {
        verdict = JSON.parse(stripFences(raw));
    } catch (e) {
        return { pass: true, issues: [], reviewError: `unparsable review response: ${e.message.slice(0, 120)}` };
    }

    const factVerdict = verdict.fact_check?.verdict;
    const translationVerdict = verdict.translation_check?.verdict;
    const issues = [
        ...(verdict.fact_check?.issues || []).map((s) => `fact: ${s}`),
        ...(verdict.translation_check?.issues || []).map((s) => `da: ${s}`),
    ];

    return {
        pass: factVerdict !== 'fail' && translationVerdict !== 'fail',
        issues,
        factVerdict,
        translationVerdict,
    };
}

module.exports = { reviewDraft };
