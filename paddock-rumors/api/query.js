/**
 * Paddock Rumors — query API.
 *
 * Keyword retrieval against knowledge-base.json, answered by Claude.
 * Deployed as a Vercel serverless function at /api/query.
 *
 * POST { query: string }
 * → { answer, sources, query, kb_size }
 */

import { readFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const KB_PATH   = join(__dirname, '..', 'data', 'knowledge-base.json');

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_MODEL      = process.env.CLAUDE_MODEL || 'claude-sonnet-4-6';
const CURRENT_SEASON    = parseInt(process.env.F1_SEASON || '2026', 10);
const TOP_N             = 6;

// Load once per cold start
let _kb = null;
function loadKb() {
  if (!_kb) _kb = JSON.parse(readFileSync(KB_PATH, 'utf-8'));
  return _kb;
}

// ── Retrieval ─────────────────────────────────────────────────────────

function scoreDocs(kb, query) {
  const terms = query
    .toLowerCase()
    .replace(/[^\w\s]/g, ' ')
    .split(/\s+/)
    .filter(t => t.length > 2);

  if (!terms.length) return [];

  const TYPE_BOOST = { qualifying: 1.2, race: 1.2, driver: 1.1, analysis: 1.05 };

  return kb
    .map(doc => {
      const text = `${doc.title} ${doc.content}`.toLowerCase();
      let score = terms.reduce((acc, t) => {
        const re = new RegExp(t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
        return acc + (text.match(re) || []).length;
      }, 0);
      if (!score) return null;
      if (doc.tags?.season === CURRENT_SEASON) score *= 1.3;
      score *= (TYPE_BOOST[doc.tags?.type] || 1.0);
      return { ...doc, _score: score };
    })
    .filter(Boolean)
    .sort((a, b) => b._score - a._score)
    .slice(0, TOP_N);
}

// ── Claude ────────────────────────────────────────────────────────────

async function askClaude(query, docs) {
  const context = docs
    .map((d, i) => `[${i + 1}] ${d.title}\n${d.content.slice(0, 1200)}`)
    .join('\n\n---\n\n');

  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01'
    },
    body: JSON.stringify({
      model: CLAUDE_MODEL,
      max_tokens: 450,
      messages: [{
        role: 'user',
        content:
`You are an F1 analyst for Paddock Picks, an F1 podium prediction game. \
Answer the user's question using ONLY the provided knowledge base documents. \
Be concise, factual, and focused on what helps predict podium finishers. \
If the documents don't contain enough information, say so clearly.

Knowledge base:
${context}

Question: ${query}`
      }]
    })
  });

  const data = await res.json();
  if (!res.ok) throw new Error(data?.error?.message || `Claude HTTP ${res.status}`);
  return data.content[0].text;
}

// ── Handler ───────────────────────────────────────────────────────────

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST')    return res.status(405).json({ error: 'POST only' });

  const { query } = req.body || {};
  if (!query?.trim())        return res.status(400).json({ error: 'query required' });
  if (!ANTHROPIC_API_KEY)    return res.status(500).json({ error: 'ANTHROPIC_API_KEY not set in Vercel' });

  try {
    const kb   = loadKb();
    const docs = scoreDocs(kb, query.trim());

    if (!docs.length) {
      return res.status(200).json({
        answer:   'No relevant documents found. Try a driver name, circuit, or team.',
        sources:  [],
        query,
        kb_size:  kb.length
      });
    }

    const answer = await askClaude(query.trim(), docs);

    return res.status(200).json({
      answer,
      sources: docs.map(d => ({
        id:      d.id,
        title:   d.title,
        type:    d.tags?.type,
        round:   d.tags?.round ?? null,
        season:  d.tags?.season ?? null,
        score:   +d._score.toFixed(2),
        snippet: d.content.slice(0, 200)
      })),
      query,
      kb_size: kb.length
    });

  } catch (err) {
    console.error('[paddock-rumors/query]', err.message);
    return res.status(500).json({ error: err.message });
  }
}
