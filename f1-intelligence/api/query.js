/**
 * F1 RAG Query Engine - CLI Tool
 * 
 * Test the RAG system from the command line.
 * 
 * Usage:
 *   export OPENAI_API_KEY="sk-proj-..."
 *   export ANTHROPIC_API_KEY="sk-ant-..."
 *   node query.js "Your F1 question here"
 */

import fs from 'fs';
import fetch from 'node-fetch';
import { createEmbedding } from './build-index.js';

const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;

function cosineSimilarity(vecA, vecB) {
  const dotProduct = vecA.reduce((sum, a, i) => sum + a * vecB[i], 0);
  const magnitudeA = Math.sqrt(vecA.reduce((sum, a) => sum + a * a, 0));
  const magnitudeB = Math.sqrt(vecB.reduce((sum, b) => sum + b * b, 0));
  return dotProduct / (magnitudeA * magnitudeB);
}

function searchVectorIndex(queryEmbedding, vectorIndex, topK = 3) {
  const scoredDocs = vectorIndex.map(doc => ({
    ...doc,
    score: cosineSimilarity(queryEmbedding, doc.embedding)
  }));
  
  return scoredDocs
    .sort((a, b) => b.score - a.score)
    .slice(0, topK);
}

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
      model: 'claude-sonnet-4-6',
      max_tokens: 1000,
      messages: [{ role: 'user', content: prompt }]
    })
  });

  const data = await response.json();
  
  if (!response.ok) {
    throw new Error(`Anthropic API error: ${JSON.stringify(data)}`);
  }
  
  return data.content[0].text;
}

export async function queryF1Intelligence(question, options = {}) {
  const { topK = 3, debug = false } = options;
  
  if (debug) console.log('🔍 Processing question:', question);
  
  if (debug) console.log('📚 Loading vector index...');
  const vectorIndex = JSON.parse(
    fs.readFileSync('./data/f1-vector-index.json', 'utf-8')
  );
  
  if (debug) console.log('🎯 Creating query embedding...');
  const queryEmbedding = await createEmbedding(question);
  
  if (debug) console.log(`📊 Searching for top ${topK} relevant documents...`);
  const retrievedDocs = searchVectorIndex(queryEmbedding, vectorIndex, topK);
  
  if (debug) {
    console.log('\n📄 Retrieved documents:');
    retrievedDocs.forEach((doc, i) => {
      console.log(`   ${i + 1}. ${doc.title} (similarity: ${(doc.score * 100).toFixed(1)}%)`);
    });
    console.log('');
  }
  
  if (debug) console.log('🤖 Generating answer with Claude...');
  const answer = await generateAnswer(question, retrievedDocs);
  
  return {
    answer,
    sources: retrievedDocs.map(doc => ({
      title: doc.title,
      id: doc.id,
      similarity: doc.score
    }))
  };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const question = process.argv[2];
  
  if (!question) {
    console.error('❌ Usage: node query.js "Your question about F1"');
    console.error('   Example: node query.js "How does Verstappen perform at Monaco?"');
    process.exit(1);
  }
  
  if (!ANTHROPIC_API_KEY) {
    console.error('❌ Error: ANTHROPIC_API_KEY environment variable not set');
    process.exit(1);
  }
  
  queryF1Intelligence(question, { debug: true })
    .then(result => {
      console.log('💡 ANSWER:');
      console.log('─'.repeat(50));
      console.log(result.answer);
      console.log('─'.repeat(50));
      console.log('\n📚 Sources used:');
      result.sources.forEach((source, i) => {
        console.log(`   ${i + 1}. ${source.title}`);
      });
    })
    .catch(err => {
      console.error('❌ Error:', err);
      process.exit(1);
    });
}
