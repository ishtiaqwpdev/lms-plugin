import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const transcriptPath = process.argv[2];
const outFile = path.join(__dirname, 'incoming-user-json.txt');

if (!transcriptPath || !fs.existsSync(transcriptPath)) {
  console.error('Usage: node extract-from-transcript-file.mjs <path-to.jsonl>');
  process.exit(1);
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
          return null;
        }
        return null;
      }
    }
  }
  return null;
}

const text = fs.readFileSync(transcriptPath, 'utf8');
let best = null;
for (const line of text.split('\n')) {
  if (!line.includes('CTA-EP-002-FC-0917')) continue;
  const arr = tryExtract(line);
  if (arr && (!best || arr.length > best.length)) best = arr;
}
if (!best) best = tryExtract(text);

if (!best) {
  console.error('No array extracted');
  process.exit(1);
}

console.log('Extracted', best.length, 'cards');
console.log('First:', best[0].id, 'Last:', best[best.length - 1].id);
const fc808 = best.find((c) => c.id === 'CTA-EP-002-FC-0808');
console.log('FC-0808 concept:', fc808?.concept);

fs.writeFileSync(outFile, `${JSON.stringify(best, null, 2)}\n`, 'utf8');
console.log('Wrote', outFile);
