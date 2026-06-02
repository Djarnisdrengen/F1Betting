/**
 * Incremental, tag-aware vector index builder.
 *
 * Reads:  data/f1-knowledge-base.json
 * Reads:  data/f1-vector-index.json   (existing index, if any)
 * Writes: data/f1-vector-index.json
 *
 * Key differences vs the original build-index.js:
 *   - Reuses existing embeddings when a doc's content_hash is unchanged.
 *   - Preserves every doc field (tags, source_url, updated_at, …) into
 *     the vector index, so the retrieval layer can do tag-aware scoring.
 *   - Falls back gracefully for legacy docs that lack a content_hash:
 *     it computes one on the fly.
 *
 * Run:
 *   export OPENAI_API_KEY="sk-proj-..."
 *   npm run build-index
 */

import fs from 'fs';
import fetch from 'node-fetch';
import { createHash } from 'crypto';

const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const EMBEDDING_MODEL = process.env.EMBEDDING_MODEL || 'text-embedding-3-small';

const KB_PATH = './data/f1-knowledge-base.json';
const INDEX_PATH = './data/f1-vector-index.json';

function contentHash(s) {
  return createHash('sha256').update(s).digest('hex').slice(0, 16);
}

export async function createEmbedding(text) {
  const res = await fetch('https://api.openai.com/v1/embeddings', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${OPENAI_API_KEY}`
    },
    body: JSON.stringify({ model: EMBEDDING_MODEL, input: text })
  });
  const data = await res.json();
  if (!res.ok) throw new Error(`OpenAI: ${JSON.stringify(data).slice(0, 300)}`);
  return data.data[0].embedding;
}

async function buildIndex() {
  console.log('📚 Loading knowledge base...');
  const kb = JSON.parse(fs.readFileSync(KB_PATH, 'utf-8'));

  let existing = [];
  if (fs.existsSync(INDEX_PATH)) {
    existing = JSON.parse(fs.readFileSync(INDEX_PATH, 'utf-8'));
  }
  const existingById = new Map(existing.map(d => [d.id, d]));

  console.log(`📊 ${kb.length} KB docs · ${existing.length} in existing index`);

  const out = [];
  let embedded = 0;
  let reused = 0;

  for (let i = 0; i < kb.length; i++) {
    const doc = kb[i];
    const hash = doc.content_hash || contentHash(doc.content);
    const prior = existingById.get(doc.id);

    if (prior && prior.content_hash === hash && Array.isArray(prior.embedding)) {
      // Unchanged — reuse the existing embedding but pick up any updated
      // metadata (title, tags, source_url, updated_at, etc.) from the KB.
      out.push({ ...doc, content_hash: hash, embedding: prior.embedding });
      reused++;
    } else {
      console.log(`🔄 [${i + 1}/${kb.length}] embedding: ${doc.title}`);
      const embedding = await createEmbedding(doc.content);
      out.push({ ...doc, content_hash: hash, embedding });
      embedded++;
      await new Promise(r => setTimeout(r, 80)); // gentle rate-limit
    }
  }

  fs.writeFileSync(INDEX_PATH, JSON.stringify(out, null, 2));

  console.log('✅ done');
  console.log(`   total: ${out.length}`);
  console.log(`   newly embedded: ${embedded}`);
  console.log(`   reused: ${reused}`);

  // Cheap cost estimate (only for newly embedded docs).
  const newlyEmbeddedTokens = out
    .filter((d, idx) => existingById.get(d.id)?.content_hash !== d.content_hash)
    .reduce((sum, d) => sum + Math.ceil(d.content.length / 4), 0);
  const cost = (newlyEmbeddedTokens / 1_000_000) * 0.02;
  console.log(`   estimated cost this run: $${cost.toFixed(4)}`);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  if (!OPENAI_API_KEY) {
    console.error('❌ OPENAI_API_KEY not set');
    process.exit(1);
  }
  buildIndex().catch(err => {
    console.error('❌', err);
    process.exit(1);
  });
}
