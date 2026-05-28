/**
 * F1 RAG Indexing Script
 * 
 * Reads the F1 knowledge base and creates embeddings using OpenAI API.
 * Output: data/f1-vector-index.json
 * 
 * Usage:
 *   export OPENAI_API_KEY="sk-proj-..."
 *   node build-index.js
 */

import fs from 'fs';
import fetch from 'node-fetch';

const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const EMBEDDING_MODEL = 'text-embedding-3-small';

async function createEmbedding(text) {
  const response = await fetch('https://api.openai.com/v1/embeddings', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${OPENAI_API_KEY}`
    },
    body: JSON.stringify({
      model: EMBEDDING_MODEL,
      input: text
    })
  });

  const data = await response.json();
  
  if (!response.ok) {
    throw new Error(`OpenAI API error: ${JSON.stringify(data)}`);
  }
  
  return data.data[0].embedding;
}

async function buildIndex() {
  console.log('📚 Loading F1 knowledge base...');
  const knowledgeBase = JSON.parse(
    fs.readFileSync('./data/f1-knowledge-base.json', 'utf-8')
  );

  console.log(`📊 Found ${knowledgeBase.length} documents to index`);
  
  const vectorIndex = [];
  
  for (let i = 0; i < knowledgeBase.length; i++) {
    const doc = knowledgeBase[i];
    console.log(`🔄 Processing [${i + 1}/${knowledgeBase.length}]: ${doc.title}`);
    
    const embedding = await createEmbedding(doc.content);
    
    vectorIndex.push({
      id: doc.id,
      title: doc.title,
      content: doc.content,
      embedding: embedding
    });
    
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  
  console.log('💾 Saving vector index...');
  fs.writeFileSync(
    './data/f1-vector-index.json',
    JSON.stringify(vectorIndex, null, 2)
  );
  
  console.log('✅ Index built successfully!');
  console.log(`   Documents indexed: ${vectorIndex.length}`);
  console.log(`   Embedding dimension: ${vectorIndex[0].embedding.length}`);
  
  const totalTokens = knowledgeBase.reduce((sum, doc) => {
    return sum + Math.ceil(doc.content.length / 4);
  }, 0);
  const cost = (totalTokens / 1000000) * 0.02;
  console.log(`   Estimated cost: $${cost.toFixed(4)}`);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  if (!OPENAI_API_KEY) {
    console.error('❌ Error: OPENAI_API_KEY environment variable not set');
    console.error('   Set it with: export OPENAI_API_KEY="sk-proj-..."');
    process.exit(1);
  }
  
  buildIndex().catch(err => {
    console.error('❌ Error building index:', err);
    process.exit(1);
  });
}

export { createEmbedding };
