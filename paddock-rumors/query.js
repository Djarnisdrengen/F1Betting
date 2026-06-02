#!/usr/bin/env node
/**
 * Paddock Rumors — KB query tool.
 *
 * Keyword search against knowledge-base.json. No embeddings, no API calls.
 *
 * Usage:
 *   node query.js                        print stats only
 *   node query.js verstappen canada      keyword search
 *   node query.js --type race            filter by doc type
 *   node query.js --type driver          filter by doc type
 *   node query.js --round 7              filter by round number
 *   node query.js --type race verstappen combine filters + keyword
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const KB_PATH = path.resolve(__dirname, './data/knowledge-base.json');
const SNIPPET_LEN = 300;
const MAX_RESULTS = 20;

// ── CLI arg parsing ──────────────────────────────────────────────────

const args = process.argv.slice(2);
let filterType   = null;
let filterRound  = null;
const terms      = [];

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--type'  && args[i + 1]) { filterType  = args[++i]; continue; }
  if (args[i] === '--round' && args[i + 1]) { filterRound = parseInt(args[++i], 10); continue; }
  terms.push(args[i].toLowerCase());
}

// ── Load KB ──────────────────────────────────────────────────────────

if (!fs.existsSync(KB_PATH)) {
  console.log('[paddock-rumors] knowledge-base.json not found.');
  console.log('[paddock-rumors] Run "F1TECH_ENRICH=0 node update-kb.js" first to generate it.');
  process.exit(0);
}

const kb = JSON.parse(fs.readFileSync(KB_PATH, 'utf-8'));

// ── Stats ────────────────────────────────────────────────────────────

const byType   = {};
const seasons  = new Set();
let   lastUpdated = null;

for (const doc of kb) {
  byType[doc.tags?.type || 'unknown'] = (byType[doc.tags?.type || 'unknown'] || 0) + 1;
  if (doc.tags?.season) seasons.add(doc.tags.season);
  if (!lastUpdated || doc.updated_at > lastUpdated) lastUpdated = doc.updated_at;
}

console.log(`\n[paddock-rumors] KB stats`);
console.log(`  Total docs : ${kb.length}`);
console.log(`  By type    : ${Object.entries(byType).map(([k,v]) => `${k}(${v})`).join('  ')}`);
console.log(`  Seasons    : ${[...seasons].sort().join(', ')}`);
console.log(`  Last update: ${lastUpdated ? lastUpdated.slice(0, 19).replace('T', ' ') + ' UTC' : 'unknown'}`);

if (!terms.length && !filterType && !filterRound) {
  console.log('\n  Usage: node query.js [--type TYPE] [--round N] [keyword ...]\n');
  process.exit(0);
}

// ── Filter ───────────────────────────────────────────────────────────

function matches(doc) {
  if (filterType  && doc.tags?.type  !== filterType)  return false;
  if (filterRound && doc.tags?.round !== filterRound) return false;
  if (terms.length) {
    const haystack = `${doc.title} ${doc.content}`.toLowerCase();
    return terms.every(t => haystack.includes(t));
  }
  return true;
}

const results = kb.filter(matches).slice(0, MAX_RESULTS);

// ── Output ───────────────────────────────────────────────────────────

if (results.length === 0) {
  console.log('\n  No matching docs.\n');
  process.exit(0);
}

const filter = [
  filterType  ? `type=${filterType}`   : null,
  filterRound ? `round=${filterRound}` : null,
  terms.length ? `"${terms.join(' ')}"` : null,
].filter(Boolean).join('  ');

console.log(`\n  ${results.length} result(s) for: ${filter}\n`);

for (let i = 0; i < results.length; i++) {
  const doc  = results[i];
  const tags = doc.tags || {};
  const meta = [
    tags.type,
    tags.round != null ? `round ${tags.round}` : null,
    tags.season != null ? `season ${tags.season}` : null,
    tags.driver || null,
  ].filter(Boolean).join(' | ');

  const snippet = (doc.content || '').replace(/\s+/g, ' ').slice(0, SNIPPET_LEN);
  const ellipsis = doc.content?.length > SNIPPET_LEN ? '…' : '';

  console.log(`[${i + 1}] ${doc.id}  [${meta}]`);
  console.log(`    ${snippet}${ellipsis}`);
  console.log();
}
