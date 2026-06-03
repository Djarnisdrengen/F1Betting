const KB_RAW_URL = process.env.KB_RAW_URL ||
  'https://raw.githubusercontent.com/Djarnisdrengen/F1Betting/main/paddock-rumors/data/knowledge-base.json';

export default async function handler(req, res) {
  try {
    const r  = await fetch(KB_RAW_URL, { headers: { 'User-Agent': 'paddock-rumors-api' } });
    const kb = r.ok ? await r.json() : [];
    res.status(200).json({
      status:  'ok',
      service: 'paddock-rumors',
      kb_docs: kb.length,
      kb_ok:   Array.isArray(kb) && kb.length > 0,
      source:  'github-raw'
    });
  } catch (err) {
    res.status(200).json({ status: 'degraded', service: 'paddock-rumors', error: err.message });
  }
}
