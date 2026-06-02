/**
 * Vercel Serverless Function: F1 Intelligence API
 *
 * Endpoint: POST /api/intelligence
 * Body: { "question": "Your F1 question" }
 * Response: { "answer": "...", "sources": [...] }
 *
 * Retrieval is season-aware: documents tagged with the current championship
 * season are boosted at query time so the RAG prefers current-season race
 * and driver context while still falling back on evergreen / historical
 * documents when nothing current matches.
 */

import fs from 'fs';
import path from 'path';
import fetch from 'node-fetch';

const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const EMBEDDING_MODEL = 'text-embedding-3-small';

// ── Season weighting ─────────────────────────────────────────────────
//
// CURRENT_SEASON drives the retrieval boost. Override via Vercel env
// var F1_SEASON when the calendar year rolls over.
const CURRENT_SEASON = parseInt(process.env.F1_SEASON || '2026', 10);

/**
 * Multiplicative boost factor applied to a document's cosine similarity
 * based on the season tag. Tune to taste.
 *
 *   current season   → ×1.20   (strongest)
 *   previous season  → ×1.00   (neutral)
 *   two seasons back → ×0.90
 *   older / untagged → ×0.80–0.85
 */
function seasonBoost(docSeason) {
  if (docSeason == null) return 0.85; // legacy / untagged docs
  const diff = CURRENT_SEASON - docSeason;
  if (diff === 0) return 1.20;
  if (diff === 1) return 1.00;
  if (diff === 2) return 0.90;
  return 0.80;
}

// ── Embedding ────────────────────────────────────────────────────────

async function createEmbedding(text) {
  const response = await fetch('https://api.openai.com/v1/embeddings', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${OPENAI_API_KEY}`
    },
    body: JSON.stringify({ model: EMBEDDING_MODEL, input: text })
  });

  const data = await response.json();
  if (!response.ok) throw new Error(`OpenAI API error: ${JSON.stringify(data)}`);
  return data.data[0].embedding;
}

// ── Vector search (season-aware) ─────────────────────────────────────

function cosineSimilarity(vecA, vecB) {
  const dotProduct = vecA.reduce((sum, a, i) => sum + a * vecB[i], 0);
  const magnitudeA = Math.sqrt(vecA.reduce((sum, a) => sum + a * a, 0));
  const magnitudeB = Math.sqrt(vecB.reduce((sum, b) => sum + b * b, 0));
  return dotProduct / (magnitudeA * magnitudeB);
}

function searchVectorIndex(queryEmbedding, vectorIndex, topK = 3) {
  const scored = vectorIndex.map(doc => {
    const sim = cosineSimilarity(queryEmbedding, doc.embedding);
    const boost = seasonBoost(doc.tags?.season);
    return {
      ...doc,
      score: sim * boost,
      _similarity: sim,
      _boost: boost
    };
  });

  return scored.sort((a, b) => b.score - a.score).slice(0, topK);
}

// ── Answer generation ────────────────────────────────────────────────

async function generateAnswer(question, retrievedDocs) {
  const context = retrievedDocs
    .map((doc, i) => `[${i + 1}] ${doc.title}\n${doc.content}`)
    .join('\n\n---\n\n');

  const prompt = `You are an F1 racing expert helping users make better race predictions for the Paddock Picks betting app.

Using the following F1 historical data and statistics, answer the user's question. Be specific, cite statistics from the context, and focus on actionable insights for making podium predictions.

CONTEXT:
${context}

USER QUESTION: ${question}

Provide a clear, helpful answer that helps the user make a more informed prediction. Include specific statistics and percentages where available.`;

  const response = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01'
    },
    body: JSON.stringify({
      model: 'claude-sonnet-4-20250514',
      max_tokens: 1000,
      messages: [{ role: 'user', content: prompt }]
    })
  });

  const data = await response.json();
  if (!response.ok) throw new Error(`Anthropic API error: ${JSON.stringify(data)}`);
  return data.content[0].text;
}

// ── Main handler ─────────────────────────────────────────────────────

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  try {
    const { question } = req.body;
    if (!question) {
      return res.status(400).json({ error: 'Missing required field: question' });
    }

    const vectorIndexPath = path.join(process.cwd(), 'data', 'f1-vector-index.json');
    const vectorIndex = JSON.parse(fs.readFileSync(vectorIndexPath, 'utf-8'));

    const queryEmbedding = await createEmbedding(question);
    const retrievedDocs = searchVectorIndex(queryEmbedding, vectorIndex, 3);
    const answer = await generateAnswer(question, retrievedDocs);

    res.status(200).json({
      answer,
      sources: retrievedDocs.map(doc => ({
        title: doc.title,
        id: doc.id,
        score: doc.score,
        similarity: doc._similarity,
        boost: doc._boost,
        season: doc.tags?.season ?? null,
        type: doc.tags?.type ?? null
      }))
    });
  } catch (error) {
    console.error('Error processing request:', error);
    res.status(500).json({
      error: 'Failed to process query',
      message: error.message
    });
  }
}
