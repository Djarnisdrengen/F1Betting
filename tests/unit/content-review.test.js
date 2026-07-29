'use strict';
const { test } = require('node:test');
const assert   = require('node:assert/strict');
const { reviewDraft } = require('../../bin/lib/content-review');

const doc = { id: 'race-2026-r01-albert-grand-prix', content: 'George Russell won the 2026 Australian Grand Prix.' };

function claudeReturning(jsonOrText) {
    return async () => (typeof jsonOrText === 'string' ? jsonOrText : JSON.stringify(jsonOrText));
}

test('passes when both checks pass', async () => {
    const claude = claudeReturning({
        fact_check: { verdict: 'pass', issues: [] },
        translation_check: { verdict: 'pass', issues: [] },
    });
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'Russell vandt', text_en: 'Russell won' },
        doc,
    });
    assert.strictEqual(result.pass, true);
    assert.deepStrictEqual(result.issues, []);
});

test('fails when the fact check fails, and prefixes the issue', async () => {
    const claude = claudeReturning({
        fact_check: { verdict: 'fail', issues: ['Verstappen did not win this race'] },
        translation_check: { verdict: 'pass', issues: [] },
    });
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'x', text_en: 'x' },
        doc,
    });
    assert.strictEqual(result.pass, false);
    assert.deepStrictEqual(result.issues, ['fact: Verstappen did not win this race']);
});

test('fails when only the translation check fails, and prefixes the issue', async () => {
    const claude = claudeReturning({
        fact_check: { verdict: 'pass', issues: [] },
        translation_check: { verdict: 'fail', issues: ['"Bet" was translated to "væddemål"'] },
    });
    const result = await reviewDraft(claude, {
        kind: 'trivia',
        item: { question_da: 'x', question_en: 'x', options_da: ['a'], options_en: ['a'], correct_option: 0 },
        doc,
    });
    assert.strictEqual(result.pass, false);
    assert.deepStrictEqual(result.issues, ['da: "Bet" was translated to "væddemål"']);
});

test('"unverifiable" counts as a pass, not a fail', async () => {
    const claude = claudeReturning({
        fact_check: { verdict: 'unverifiable', issues: ['source doc does not cover this detail'] },
        translation_check: { verdict: 'pass', issues: [] },
    });
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'x', text_en: 'x' },
        doc,
    });
    assert.strictEqual(result.pass, true);
    assert.strictEqual(result.factVerdict, 'unverifiable');
});

test('fabricated rumor cards (is_real=false) are not flagged for being untrue', async () => {
    // The prompt built for a fabricated card must steer the fact-check toward explain_da/en,
    // not text_da/en — verified here by inspecting what was actually sent to "claude".
    let sentPrompt;
    const claude = async (prompt) => {
        sentPrompt = prompt;
        return JSON.stringify({
            fact_check: { verdict: 'pass', issues: [] },
            translation_check: { verdict: 'pass', issues: [] },
        });
    };
    await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: false, text_da: 'Fiktiv rygte', text_en: 'Fictional rumor', explain_da: 'x', explain_en: 'x' },
        doc,
    });
    assert.match(sentPrompt, /DELIBERATELY FABRICATED/);
    assert.match(sentPrompt, /do NOT flag it for being untrue/);
});

test('fails open (pass=true, reviewError set) when the review call throws', async () => {
    const claude = async () => { throw new Error('network timeout'); };
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'x', text_en: 'x' },
        doc,
    });
    assert.strictEqual(result.pass, true);
    assert.match(result.reviewError, /network timeout/);
});

test('fails open (pass=true, reviewError set) when the response is unparsable JSON', async () => {
    const claude = claudeReturning('not json at all');
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'x', text_en: 'x' },
        doc,
    });
    assert.strictEqual(result.pass, true);
    assert.match(result.reviewError, /unparsable review response/);
});

test('strips markdown fences before parsing, same as the drafting prompts do', async () => {
    const claude = claudeReturning('```json\n{"fact_check":{"verdict":"pass","issues":[]},"translation_check":{"verdict":"pass","issues":[]}}\n```');
    const result = await reviewDraft(claude, {
        kind: 'rumor',
        item: { is_real: true, text_da: 'x', text_en: 'x' },
        doc,
    });
    assert.strictEqual(result.pass, true);
    assert.strictEqual(result.reviewError, undefined);
});
