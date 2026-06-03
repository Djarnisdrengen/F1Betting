#!/usr/bin/env node
/**
 * Paddock Rumors — one-off F1Technical backfill.
 *
 * Fetches monthly archive pages from f1technical.net, scores articles
 * against completed rounds by title relevance, summarises with Claude,
 * and upserts enrichment docs into knowledge-base.json.
 *
 * Usage:
 *   node backfill-enrichment.js                           default months: 03-2026 04-2026 05-2026
 *   node backfill-enrichment.js --months=03-2026,04-2026  specific months
 *   node backfill-enrichment.js --dry-run                 show matches, no Claude calls
 *
 * Env:
 *   ANTHROPIC_API_KEY   required (unless --dry-run)
 *   MAX_PER_ROUND       max articles per race round (default 5)
 *   CLAUDE_MODEL        override model (default claude-sonnet-4-6)
 *   KB_OUTPUT_PATH      override KB path
 */

import fs from 'fs';
import path from 'path';
import { createHash } from 'crypto';
import { fileURLToPath } from 'url';
import fetch from 'node-fetch';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_KB = path.resolve(__dirname, './data/knowledge-base.json');
const KB_PATH    = process.env.KB_OUTPUT_PATH ? path.resolve(process.env.KB_OUTPUT_PATH) : DEFAULT_KB;

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_MODEL      = process.env.CLAUDE_MODEL || 'claude-sonnet-4-6';
const MAX_PER_ROUND     = parseInt(process.env.MAX_PER_ROUND || '5', 10);
const UA                = 'Mozilla/5.0 (compatible; PaddockPicksKB/1.0; +https://formula-1.dk)';
const PAGE_STEP         = 60;
const MAX_PAGES         = 4;

const DRY_RUN    = process.argv.includes('--dry-run');
const monthsFlag = process.argv.find(a => a.startsWith('--months='));
const MONTHS     = monthsFlag
  ? monthsFlag.replace('--months=', '').split(',').map(s => s.trim())
  : ['03-2026', '04-2026', '05-2026'];

const SERIES_PATTERNS = [/F1\s*MATHS:/i, /F1\s*TECH:/i, /F1ANALYSIS:/i, /STRATEGY:/i, /ANALYSIS:/i];

// Alternative names F1Technical uses in headlines for each GP location.
const RACE_ALIASES = {
  'Australian': ['Australian', 'Melbourne', 'Albert Park'],
  'Chinese':    ['Chinese', 'Shanghai', 'China'],
  'Japanese':   ['Japanese', 'Suzuka', 'Japan'],
  'Miami':      ['Miami'],
  'Canadian':   ['Canadian', 'Montreal', 'Gilles Villeneuve', 'Canada'],
  'Monaco':     ['Monaco', 'Monte Carlo'],
  'Spanish':    ['Spanish', 'Barcelona', 'Catalunya'],
  'Austrian':   ['Austrian', 'Spielberg', 'Red Bull Ring', 'Austria'],
  'British':    ['British', 'Silverstone', 'Britain'],
  'Hungarian':  ['Hungarian', 'Budapest', 'Hungaroring', 'Hungary'],
  'Belgian':    ['Belgian', 'Spa', 'Francorchamps', 'Belgium'],
  'Dutch':      ['Dutch', 'Zandvoort', 'Netherlands'],
  'Italian':    ['Italian', 'Monza', 'Italy'],
  'Singapore':  ['Singapore', 'Marina Bay'],
  'United States': ['United States', 'Austin', 'COTA'],
  'Mexico':     ['Mexico', 'Mexican', 'Hermanos Rodriguez'],
  'São Paulo':  ['São Paulo', 'Brazil', 'Brazilian', 'Interlagos'],
  'Las Vegas':  ['Las Vegas'],
  'Qatar':      ['Qatar', 'Lusail'],
  'Abu Dhabi':  ['Abu Dhabi', 'Yas Marina'],
};

// ── Utilities ─────────────────────────────────────────────────────────

function slugify(s) {
  return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50);
}

function contentHash(content) {
  return createHash('sha256').update(content).digest('hex').slice(0, 16);
}

function decodeEntities(s) {
  return s.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#\d+;/g, '').replace(/&[a-z]+;/g, '');
}

function stripHtml(html) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<nav[\s\S]*?<\/nav>/gi, '')
    .replace(/<footer[\s\S]*?<\/footer>/gi, '')
    .replace(/<header[\s\S]*?<\/header>/gi, '')
    .replace(/<aside[\s\S]*?<\/aside>/gi, '');
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

// ── Claude ────────────────────────────────────────────────────────────

async function claude(prompt, maxTokens = 500, attempt = 1) {
  const res  = await fetch('https://api.anthropic.com/v1/messages', {
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
  if (!res.ok) {
    if (data?.error?.type === 'rate_limit_error' && attempt <= 3) {
      const wait = attempt * 20000;
      console.log(`  [backfill] rate limited — waiting ${wait / 1000}s (attempt ${attempt}/3)`);
      await sleep(wait);
      return claude(prompt, maxTokens, attempt + 1);
    }
    throw new Error(`Claude: ${JSON.stringify(data).slice(0, 400)}`);
  }
  return data.content[0].text;
}

// ── Link extraction ───────────────────────────────────────────────────

function extractLinks(html) {
  // Match /news/12345 with optional trailing ?session-params (stripped in URL)
  const re  = /<a\s+[^>]*href="\/news\/(\d+)[^"]*"[^>]*>([^<]+)<\/a>/gi;
  const seen = new Set();
  const out  = [];
  let m;
  while ((m = re.exec(html)) !== null) {
    const id    = m[1];
    const title = decodeEntities(m[2].replace(/\s+/g, ' ').trim());
    if (title.length < 8 || seen.has(id)) continue;
    seen.add(id);
    out.push({ id, url: `https://www.f1technical.net/news/${id}`, title });
  }
  return out;
}

// ── Relevance scoring ─────────────────────────────────────────────────

function scoreRelevance(title, race) {
  let score = 0;
  if (SERIES_PATTERNS.some(p => p.test(title))) score += 3;
  // Match by race location — use aliases to catch "Montreal" for "Canadian GP" etc.
  const locationWord = race.raceName.replace(/Grand Prix/i, '').trim().split(/\s+/)[0];
  const aliases = RACE_ALIASES[locationWord] || [locationWord];
  if (aliases.some(a => new RegExp(a, 'i').test(title))) score += 2;
  return score;
}

// ── Monthly archive fetcher ───────────────────────────────────────────

async function fetchMonthLinks(month) {
  const links = [];
  const seen  = new Set();

  for (let page = 0; page < MAX_PAGES; page++) {
    const start = page * PAGE_STEP;
    const url   = start === 0
      ? `https://www.f1technical.net/news/?my=${month}`
      : `https://www.f1technical.net/news/?my=${month}&start=${start}`;

    console.log(`  [backfill] GET ${url}`);
    let res;
    try {
      res = await fetch(url, { headers: { 'User-Agent': UA } });
    } catch (err) {
      console.warn(`  [backfill] network error: ${err.message}`);
      break;
    }
    if (!res.ok) { console.warn(`  [backfill] HTTP ${res.status}`); break; }

    const pageLinks = extractLinks(await res.text());
    let added = 0;
    for (const l of pageLinks) {
      if (!seen.has(l.id)) { seen.add(l.id); links.push(l); added++; }
    }
    console.log(`  [backfill] page ${page + 1}: ${pageLinks.length} links, ${added} new`);
    if (added === 0) break;
    await sleep(800);
  }
  return links;
}

// ── Article summarisers ───────────────────────────────────────────────

// Used when the article is already matched to a specific race by title.
async function summarise(article, race) {
  const res = await fetch(article.url, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const html = stripHtml(await res.text()).slice(0, 22000);

  const prompt = `Below is the HTML of an F1Technical.net article titled: "${article.title}"

Extract the key TECHNICAL and ANALYTICAL conclusions in 100–130 words. Tone: neutral, factual, useful for predicting future race performance — focus on upgrades, reliability, setup direction, power-unit context, telemetry findings, pace deltas. Use full team and driver names. No quotes, no opinion-as-fact, no boilerplate.

Output ONLY the summary prose.

HTML:
${html}`;

  const summary = (await claude(prompt, 500)).trim();
  if (summary.length < 20) return null;

  const { season, round, raceName } = race;
  const content = `Season ${season} technical analysis — ${article.title}. ${summary}`;

  return {
    id: `analysis-${season}-r${String(round).padStart(2, '0')}-f1tech-${slugify(article.title)}`,
    title: `${article.title} (F1Technical)`,
    content,
    tags: { season, type: 'analysis', source: 'f1technical', round },
    source_url: article.url,
    updated_at: new Date().toISOString(),
    content_hash: contentHash(content)
  };
}

// Used for series articles not matched by title — Claude determines the race
// from the article content itself.
async function summariseGeneral(article, races) {
  const res = await fetch(article.url, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const html = stripHtml(await res.text()).slice(0, 22000);

  const raceList = races.map(r => `R${r.round}: ${r.raceName} ${r.season}`).join(', ');

  const prompt = `Below is the HTML of an F1Technical.net article titled: "${article.title}"

Known races: ${raceList}

Your response MUST start with exactly one of these lines:
  RACE: R[number]   (e.g. RACE: R5 — if the article is primarily about one of the known races)
  RACE: GENERAL     (if it covers multiple races or the whole season)

Then on the next line, extract the key TECHNICAL and ANALYTICAL conclusions in 100–130 words. Tone: neutral, factual, useful for predicting future race performance — focus on upgrades, reliability, power-unit context, telemetry findings, pace deltas. Use full team and driver names. No quotes, no opinion-as-fact.

Output ONLY the RACE line followed by the summary prose.

HTML:
${html}`;

  const raw = (await claude(prompt, 560)).trim();
  if (raw.length < 20) return null;

  // Parse RACE: line
  const lines       = raw.split('\n');
  const raceLine    = lines[0].trim();
  const summaryBody = lines.slice(1).join('\n').trim();
  if (summaryBody.length < 60) return null;

  let round  = null;
  let season = races[0]?.season ?? 2026;
  const raceMatch = raceLine.match(/^RACE:\s*R(\d+)/i);
  if (raceMatch) {
    const rNum = parseInt(raceMatch[1], 10);
    const matched = races.find(r => r.round === rNum);
    if (matched) { round = matched.round; season = matched.season; }
  }

  const roundTag = round != null ? `r${String(round).padStart(2, '0')}` : 'general';
  const content  = `Season ${season} technical analysis — ${article.title}. ${summaryBody}`;

  return {
    id: `analysis-${season}-${roundTag}-f1tech-${slugify(article.title)}`,
    title: `${article.title} (F1Technical)`,
    content,
    tags: { season, type: 'analysis', source: 'f1technical', round },
    source_url: article.url,
    updated_at: new Date().toISOString(),
    content_hash: contentHash(content)
  };
}

// ── Main ─────────────────────────────────────────────────────────────

async function main() {
  if (!DRY_RUN && !ANTHROPIC_API_KEY) {
    console.error('[backfill] FATAL: ANTHROPIC_API_KEY not set (use --dry-run to preview without API calls)');
    process.exit(1);
  }

  console.log(`[backfill] months=${MONTHS.join(',')}  maxPerRound=${MAX_PER_ROUND}  dry-run=${DRY_RUN}`);
  console.log(`[backfill] KB: ${KB_PATH}`);

  if (!fs.existsSync(KB_PATH)) {
    console.error('[backfill] knowledge-base.json not found — run update-kb.js first');
    process.exit(1);
  }

  let kb = JSON.parse(fs.readFileSync(KB_PATH, 'utf-8'));

  const races = kb
    .filter(d => d.tags?.type === 'race')
    .map(d => ({
      round:    d.tags.round,
      season:   d.tags.season,
      raceName: d.title.replace(/\s+\d{4}\s+—.*$/, '').trim()  // "Canadian Grand Prix 2026 — …" → "Canadian Grand Prix"
    }))
    .sort((a, b) => a.round - b.round);

  console.log(`[backfill] ${races.length} race(s) in KB: ${races.map(r => `R${r.round} ${r.raceName}`).join(', ')}`);

  // Fetch all article links from the requested months
  console.log('\n[backfill] fetching monthly archives...');
  const allLinks = [];
  const seenIds  = new Set();
  for (const month of MONTHS) {
    console.log(`\n[backfill] ── month ${month} ──`);
    for (const l of await fetchMonthLinks(month)) {
      if (!seenIds.has(l.id)) { seenIds.add(l.id); allLinks.push(l); }
    }
  }
  console.log(`\n[backfill] ${allLinks.length} unique article(s) across ${MONTHS.length} month(s)`);

  // Score and bucket articles per round
  const byRound     = new Map(races.map(r => [r.round, { race: r, candidates: [] }]));
  const coveredIds  = new Set();   // IDs already matched to a specific round

  for (const link of allLinks) {
    for (const race of races) {
      const score = scoreRelevance(link.title, race);
      // score >= 5: series pattern + location match → attribute to this round
      if (score >= 5) {
        byRound.get(race.round).candidates.push({ ...link, score });
        coveredIds.add(link.id);
      }
    }
  }

  // Series-only articles (score == 3 for any race): collect once, let Claude
  // determine the round from content.
  const generalCandidates = allLinks.filter(l =>
    !coveredIds.has(l.id) &&
    SERIES_PATTERNS.some(p => p.test(l.title))
  );

  // Sort and cap per round — show plan
  console.log('\n[backfill] match plan:');
  for (const [round, { race, candidates }] of byRound) {
    candidates.sort((a, b) => b.score - a.score);
    const top = candidates.slice(0, MAX_PER_ROUND);
    byRound.get(round).candidates = top;
    console.log(`  R${round} ${race.raceName}: ${top.length} candidate(s)`);
    for (const a of top) console.log(`    [${a.score}] ${a.title}`);
  }
  console.log(`  General (series-only, Claude determines round): ${generalCandidates.length} candidate(s)`);
  for (const a of generalCandidates) console.log(`    ${a.title}`);

  if (DRY_RUN) {
    console.log('\n[backfill] --dry-run: stopping here. Re-run without --dry-run to summarise.');
    return;
  }

  // Summarise and upsert
  const existingIds = new Set(kb.map(d => d.id));
  let added = 0, skipped = 0;

  for (const [, { race, candidates }] of byRound) {
    for (const article of candidates) {
      const expectedId = `analysis-${race.season}-r${String(race.round).padStart(2, '0')}-f1tech-${slugify(article.title)}`;
      if (existingIds.has(expectedId)) {
        console.log(`[backfill] already in KB — skip: ${article.title}`);
        skipped++;
        continue;
      }
      try {
        console.log(`[backfill] summarising: ${article.title}`);
        const doc = await summarise(article, race);
        if (doc) {
          const idx = kb.findIndex(d => d.id === doc.id);
          if (idx >= 0) kb[idx] = doc; else kb.push(doc);
          existingIds.add(doc.id);
          fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));
          console.log(`[backfill] + ${doc.id}`);
          added++;
        } else {
          console.log(`[backfill] - SKIP (not F1-relevant): ${article.title}`);
          skipped++;
        }
        await sleep(2000);
      } catch (err) {
        console.warn(`[backfill] failed ${article.url}: ${err.message}`);
      }
    }
  }

  // ── Pass 2: series-only articles — Claude determines round ──────────
  console.log('\n[backfill] pass 2: series-only articles (Claude determines round)');
  for (const article of generalCandidates) {
    const expectedIdPrefix = `analysis-`;
    // Check any variant of the ID (round unknown, check by title slug)
    const titleSlug = slugify(article.title);
    const alreadyIn = kb.some(d => d.id.includes(`f1tech-${titleSlug}`));
    if (alreadyIn) {
      console.log(`[backfill] already in KB — skip: ${article.title}`);
      skipped++;
      continue;
    }
    try {
      console.log(`[backfill] summarising (general): ${article.title}`);
      const doc = await summariseGeneral(article, races);
      if (doc) {
        const idx = kb.findIndex(d => d.id === doc.id);
        if (idx >= 0) kb[idx] = doc; else kb.push(doc);
        existingIds.add(doc.id);
        fs.writeFileSync(KB_PATH, JSON.stringify(kb, null, 2));
        const roundLabel = doc.tags.round != null ? `R${doc.tags.round}` : 'general';
        console.log(`[backfill] + ${doc.id} [${roundLabel}]`);
        added++;
      } else {
        console.log(`[backfill] - SKIP: ${article.title}`);
        skipped++;
      }
      await sleep(2000);
    } catch (err) {
      console.warn(`[backfill] failed ${article.url}: ${err.message}`);
    }
  }

  console.log(`\n[backfill] done. added=${added}  skipped=${skipped}`);
  console.log(`[backfill] KB now has ${kb.length} docs at ${KB_PATH}`);
}

main().catch(err => {
  console.error('[backfill] FATAL:', err);
  process.exit(1);
});
