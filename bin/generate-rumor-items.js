/**
 * Generates Rumor or Not draft cards (Phase 3, REQ-206/207) from the paddock-rumors knowledge
 * base and imports them as challenge_items drafts for admin review on admin-challenges.php.
 *
 * Reads paddock-rumors/data/knowledge-base.json READ-ONLY — never writes there (REQ-206).
 * Never runs on shared hosting (NFR-101): local/CI only, POSTs drafts to a PHP endpoint like
 * every other Node deploy-tooling script in this repo (no direct DB connection from Node).
 *
 * Usage:
 *   ANTHROPIC_API_KEY=sk-ant-... node bin/generate-rumor-items.js --env=test --count=6
 */

const fs = require('fs');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../build-deploy/.env') });
const { readPhpConfig } = require('../build-deploy/php-config');

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-5';

const KB_PATH = path.join(__dirname, '../paddock-rumors/data/knowledge-base.json');
const STATE_PATH = path.join(__dirname, 'state/rumor-generator-state.json');

function parseArgs() {
    const args = { env: 'test', count: 6 };
    for (const arg of process.argv.slice(2)) {
        const m = arg.match(/^--(\w+)=(.*)$/);
        if (m) args[m[1]] = m[2];
    }
    args.count = parseInt(args.count, 10) || 6;
    return args;
}

async function claude(prompt, maxTokens = 700) {
    if (!ANTHROPIC_API_KEY) throw new Error('ANTHROPIC_API_KEY not set');
    const res = await fetch('https://api.anthropic.com/v1/messages', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'x-api-key': ANTHROPIC_API_KEY,
            'anthropic-version': '2023-06-01',
        },
        body: JSON.stringify({
            model: CLAUDE_MODEL,
            max_tokens: maxTokens,
            messages: [{ role: 'user', content: prompt }],
        }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(`Claude API: ${JSON.stringify(data).slice(0, 400)}`);
    // content[0] isn't reliably the text block — a thinking block (or similar) can lead —
    // so find the text block explicitly rather than assuming position 0.
    const textBlock = (data.content || []).find((b) => b.type === 'text');
    if (!textBlock) throw new Error(`No text block in response: ${JSON.stringify(data).slice(0, 400)}`);
    return textBlock.text;
}

// Claude is asked for JSON only; defensively unwrap markdown fences if it adds them anyway.
function parseCardJson(text) {
    const cleaned = text.trim().replace(/^```(?:json)?\n?/, '').replace(/```$/, '').trim();
    return JSON.parse(cleaned);
}

const CARD_JSON_SHAPE = `Respond with ONLY a single JSON object, no prose, no markdown fences, in exactly this shape:
{"context_da":"...","context_en":"...","text_da":"...","text_en":"...","explain_da":"...","explain_en":"..."}
- context_*: a short 2-4 word badge (e.g. "Grid expansion", "Driver news")
- text_*: the claim shown to the player, one short punchy sentence
- explain_*: one short sentence revealed after answering
- da = Danish, en = English, matching tone/meaning across both`;

async function draftRealCard(doc) {
    const prompt = `You are drafting a "Real or Rumor" trivia card for an F1 prediction game. Below is a
confirmed, real F1 fact from our knowledge base. Restate ONE specific true detail from it as a
short, surprising-sounding claim a fan could plausibly doubt — but it must be TRUE and verifiable
from the source below.

SOURCE (season 2026 F1 knowledge base, factual):
"""
${doc.content.slice(0, 1200)}
"""

${CARD_JSON_SHAPE}
The explanation should confirm it's real and briefly say why/what the fact was.`;

    const card = parseCardJson(await claude(prompt));
    card.is_real = true;
    card.source_ref = `${doc.id}:${doc.content_hash}`;
    return card;
}

async function draftRumorCard(doc) {
    const prompt = `You are drafting a "Real or Rumor" trivia card for an F1 prediction game. Below is
real F1 context for flavor/grounding only. Invent a plausible-SOUNDING but ENTIRELY FALSE rumor
about F1 (a rule, a driver move, a technical detail) that did NOT happen — it must be fictional,
not a restatement of the real context below.

CONTEXT (season 2026 F1 knowledge base, for tone/grounding only — do not restate facts from it):
"""
${doc.content.slice(0, 600)}
"""

${CARD_JSON_SHAPE}
The explanation should say it's a synthetic rumor and briefly note what's actually true instead.`;

    const card = parseCardJson(await claude(prompt));
    card.is_real = false;
    card.source_ref = null;
    return card;
}

async function main() {
    const { env, count } = parseArgs();

    if (!fs.existsSync(KB_PATH)) {
        console.error(`❌ Knowledge base not found at ${KB_PATH}`);
        process.exit(1);
    }
    const kb = JSON.parse(fs.readFileSync(KB_PATH, 'utf8'));

    let state = { usedKbIds: [] };
    if (fs.existsSync(STATE_PATH)) {
        state = JSON.parse(fs.readFileSync(STATE_PATH, 'utf8'));
    }

    const unused = kb.filter((doc) => !state.usedKbIds.includes(doc.id));
    const realCount = Math.ceil(count / 2);
    const rumorCount = count - realCount;

    if (unused.length < realCount) {
        console.error(`❌ Only ${unused.length} unused KB docs left, need ${realCount} for real-fact cards.`);
        process.exit(1);
    }

    // Shuffle so repeated runs draw from different docs, not always the same prefix.
    const shuffled = [...unused].sort(() => Math.random() - 0.5);
    const realDocs = shuffled.slice(0, realCount);
    const rumorDocs = shuffled.slice(realCount, realCount + rumorCount);

    console.log(`🎲 Drafting ${realCount} real-fact + ${rumorCount} rumor cards via ${CLAUDE_MODEL}...`);

    // A single malformed response (e.g. truncated JSON) must not sink an entire large batch —
    // skip and keep going, since items[] is only imported once at the very end of the loop.
    const items = [];
    let skipped = 0;
    for (const doc of realDocs) {
        console.log(`  real  ← ${doc.id}`);
        try {
            items.push(await draftRealCard(doc));
            state.usedKbIds.push(doc.id);
        } catch (e) {
            console.warn(`  ⚠️  skipped: ${e.message.slice(0, 160)}`);
            skipped++;
        }
    }
    for (const doc of rumorDocs) {
        console.log(`  rumor ← ${doc.id} (grounding only)`);
        try {
            items.push(await draftRumorCard(doc));
        } catch (e) {
            console.warn(`  ⚠️  skipped: ${e.message.slice(0, 160)}`);
            skipped++;
        }
    }
    if (skipped > 0) console.log(`⚠️  ${skipped} card(s) skipped due to parse/API errors.`);

    // Env vars win when set (CI: GitHub Actions secrets/vars, no config.*.php checked out
    // there); otherwise fall back to the local config file, unchanged from before.
    let baseUrl = process.env.SITE_URL;
    let token = process.env.INTEGRATION_SEED_TOKEN;
    if (!baseUrl || !token) {
        try {
            const cfg = readPhpConfig(env);
            baseUrl = baseUrl || cfg.siteUrl;
            token = token || cfg.integrationSeedToken;
        } catch (e) {
            console.error('❌', e.message);
            process.exit(1);
        }
    }
    if (!baseUrl || !token) {
        console.error(`❌ SITE_URL or INTEGRATION_SEED_TOKEN missing (set env vars, or add to config.${env}.php)`);
        process.exit(1);
    }

    console.log(`📤 Importing ${items.length} drafts to ${baseUrl} (${env})...`);
    const res = await fetch(`${baseUrl}/tools/import-rumor-drafts.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ items }),
    });
    const body = await res.json();
    if (!res.ok || !body.ok) {
        console.error('❌ Import failed:', body);
        process.exit(1);
    }

    fs.mkdirSync(path.dirname(STATE_PATH), { recursive: true });
    fs.writeFileSync(STATE_PATH, JSON.stringify(state, null, 2));

    console.log(`✅ Imported ${body.inserted} drafts. Review them on admin-challenges.php.`);
    if (body.errors?.length) console.warn('⚠️  Some items were skipped:', body.errors);
}

main().catch((err) => {
    console.error('❌', err.message);
    process.exit(1);
});
