import { readFileSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const KB_PATH   = join(__dirname, '..', 'data', 'knowledge-base.json');

export default function handler(req, res) {
  const kbExists = existsSync(KB_PATH);
  const kbSize   = kbExists
    ? JSON.parse(readFileSync(KB_PATH, 'utf-8')).length
    : 0;

  res.status(200).json({
    status:  'ok',
    service: 'paddock-rumors',
    kb_docs: kbSize,
    kb_ok:   kbExists && kbSize > 0
  });
}
