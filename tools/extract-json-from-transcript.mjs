import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(
  __dirname,
  '../../../.cursor/projects/c-Users-GuJjAr-SaAb-Desktop-cta-lms/agent-transcripts'
);

function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, acc);
    else if (e.name.endsWith('.jsonl')) acc.push(p);
  }
  return acc;
}

function tryExtract(text) {
  const start = text.indexOf('[{"id":"CTA-EP-002-FC-');
  if (start === -1) return null;
  let depth = 0;
  for (let i = start; i < text.length; i++) {
    const ch = text[i];
    if (ch === '[') depth++;
    else if (ch === ']') {
      depth--;
      if (depth === 0) {
        const slice = text.slice(start, i + 1);
        try {
          const arr = JSON.parse(slice);
          if (Array.isArray(arr) && arr.length > 800 && arr[0]?.id?.startsWith('CTA-EP-002-FC-')) {
            return arr;
          }
        } catch {
          /* continue */
        }
        return null;
      }
    }
  }
  return null;
}

let best = null;
let bestFile = '';

for (const file of walk(root)) {
  const text = fs.readFileSync(file, 'utf8');
  const count = (text.match(/"id": "CTA-EP-002-FC-/g) || []).length;
  if (count < 800) continue;
  console.log('candidate', file, 'id-count', count);
  const arr = tryExtract(text);
  if (arr && (!best || arr.length > best.length)) {
    best = arr;
    bestFile = file;
  }
}

if (!best) {
  // Try line-by-line jsonl user messages
  for (const file of walk(root)) {
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    for (const line of lines) {
      if (!line.includes('CTA-EP-002-FC-0917')) continue;
      const arr = tryExtract(line);
      if (arr && (!best || arr.length > best.length)) {
        best = arr;
        bestFile = file + ' (line)';
      }
    }
  }
}

if (!best) {
  console.error('No 833-card array extracted');
  process.exit(1);
}

console.log('Extracted', best.length, 'cards from', bestFile);
console.log('First:', best[0].id, 'Last:', best[best.length - 1].id);
const out = path.join(__dirname, 'incoming-user-json.txt');
fs.writeFileSync(out, JSON.stringify(best, null, 2) + '\n', 'utf8');
console.log('Wrote', out);
