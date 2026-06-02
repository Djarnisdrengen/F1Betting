/**
 * Optional, non-blocking enrichment from F1Technical.net.
 *
 * Workflow:
 *   1. Fetch F1Technical's news index.
 *   2. Filter for the recurring F1MATHS / F1 TECH series or for titles
 *      mentioning the current race / circuit.
 *   3. For each match (capped), fetch the article HTML, strip the noisy
 *      bits, and hand it to Claude with a tight summarisation prompt.
 *   4. Return summarised docs as KB entries tagged with source=f1technical.
 *
 * Timing:
 *   Analysis pieces (F1MATHS, F1 TECH deep-dives) appear T+36h to T+96h
 *   after a race. Calling this earlier wastes API spend on the few quick
 *   race-day pieces and misses the high-value analysis. The orchestrator
 *   (update-kb.js) gates calls via schedule.js — see ENRICHMENT_MIN_AGE_HOURS
 *   there. This module trusts the caller and just does the work.
 *
 * Failure semantics:
 *   Caller MUST wrap calls in try/catch. Any failure here (network, parse,
 *   Claude API) must not block Tier 1 race/driver doc commits.
 */

import fetch from 'node-fetch';
import { createHash } from 'crypto';

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-4-6';

const F1TECH_INDEX = 'https://www.f1technical.net/news';
const MAX_ARTICLES = parseInt(process.env.F1TECH_MAX_ARTICLES || '5', 10);
const SERIES_PATTERNS = [/F1MATHS:/i, /F1\s*TECH:/i, /STRATEGY:/i, /ANALYSIS:/i];
const UA = 'Mozilla/5.0 (compatible; PaddockPicksKB/1.0; +https://formula-1.dk)';

async function claude(prompt, maxTokens = 500) {
  if (!ANTHROPIC_API_KEY) throw new Error('ANTHROPIC_API_KEY not set');
  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01'
    },
    body: JSON.stringify({
      model: CLAUDE_MODEL,
      max_tokens: maxTokens,
      messages: [{ role: 'user', content: prompt }]
    })
  });
  const data = await res.json();
  if (!res.ok) throw new Error(`Claude: ${JSON.stringify(data).slice(0, 400)}`);
  return data.content[0].text;
}

function contentHash(content) {
  return createHash('sha256').update(content).digest('hex').slice(0, 16);
}

function slugify(s) {
  return String(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 50);
}

function stripHtmlNoise(html) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<nav[\s\S]*?<\/nav>/gi, '')
    .replace(/<footer[\s\S]*?<\/footer>/gi, '')
    .replace(/<header[\s\S]*?<\/header>/gi, '')
    .replace(/<aside[\s\S]*?<\/aside>/gi, '');
}

function extractArticleLinks(html) {
  const linkRe = /<a\s+[^>]*href="(\/news\/\d+)"[^>]*>([^<]+)<\/a>/gi;
  const out = [];
  let m;
  while ((m = linkRe.exec(html)) !== null) {
    const href = m[1];
    const title = m[2].replace(/\s+/g, ' ').trim();
    if (title.length < 8) continue;
    out.push({ url: `https://www.f1technical.net${href}`, title });
  }
  return out;
}

function scoreRelevance(title, raceContext) {
  let score = 0;
  if (SERIES_PATTERNS.some(p => p.test(title))) score += 3;
  if (raceContext?.raceName) {
    const gpKey = raceContext.raceName.replace(/Grand Prix/i, '').trim();
    if (gpKey && new RegExp(gpKey, 'i').test(title)) score += 2;
  }
  if (raceContext?.circuit && new RegExp(raceContext.circuit.split(' ')[0], 'i').test(title)) {
    score += 1;
  }
  return score;
}

async function findRelevantArticles(raceContext) {
  const res = await fetch(F1TECH_INDEX, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`F1Tech index: HTTP ${res.status}`);
  const html = await res.text();

  const all = extractArticleLinks(html);
  const scored = all
    .map(a => ({ ...a, score: scoreRelevance(a.title, raceContext) }))
    .filter(a => a.score > 0)
    .sort((a, b) => b.score - a.score);

  const seen = new Set();
  const out = [];
  for (const a of scored) {
    if (seen.has(a.url)) continue;
    seen.add(a.url);
    out.push(a);
    if (out.length >= MAX_ARTICLES) break;
  }
  return out;
}

async function summariseArticle(article, raceContext) {
  const res = await fetch(article.url, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const html = stripHtmlNoise(await res.text()).slice(0, 22000);

  const prompt = `Below is the HTML of an F1Technical.net article titled: "${article.title}"

Extract the key TECHNICAL and ANALYTICAL conclusions in 100–130 words. Tone: neutral, factual, useful for predicting future race performance — focus on upgrades, reliability, setup direction, power-unit context, telemetry findings, pace deltas. Use full team and driver names. No quotes, no opinion-as-fact, no boilerplate.

If the article is NOT relevant to F1 race performance (e.g. management news, sponsorship, calendar admin), output exactly: SKIP

Output ONLY the summary prose, or the word SKIP.

HTML:
${html}`;

  const summary = (await claude(prompt, 500)).trim();
  if (summary === 'SKIP' || summary.length < 60) return null;

  const season = raceContext?.season || new Date().getUTCFullYear();
  const round = raceContext?.round || 0;
  const content = `Season ${season} technical analysis — ${article.title}. ${summary}`;

  return {
    id: `analysis-${season}-r${String(round).padStart(2, '0')}-f1tech-${slugify(article.title)}`,
    title: `${article.title} (F1Technical)`,
    content,
    tags: {
      season,
      type: 'analysis',
      source: 'f1technical',
      round
    },
    source_url: article.url,
    updated_at: new Date().toISOString(),
    content_hash: contentHash(content)
  };
}

/**
 * Main entry. Returns an array of enrichment docs (possibly empty).
 *
 * @param {object} raceContext { season, round, raceName, circuit }
 */
export async function enrichFromF1Technical(raceContext) {
  const articles = await findRelevantArticles(raceContext);
  if (articles.length === 0) {
    console.log('[enrich-f1tech] no relevant articles found');
    return [];
  }
  console.log(`[enrich-f1tech] found ${articles.length} candidates`);

  const docs = [];
  for (const article of articles) {
    try {
      const doc = await summariseArticle(article, raceContext);
      if (doc) {
        docs.push(doc);
        console.log(`[enrich-f1tech] + ${article.title}`);
      } else {
        console.log(`[enrich-f1tech] - skipped (not relevant): ${article.title}`);
      }
      await new Promise(r => setTimeout(r, 600));
    } catch (err) {
      console.warn(`[enrich-f1tech] failed ${article.url}: ${err.message}`);
    }
  }
  return docs;
}
