/**
 * Scan all agent transcripts for the 833-card CTA-EP-002 flashcard array.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const transcriptRoot =
  process.env.CURSOR_AGENT_TRANSCRIPTS ||
  'C:/Users/GuJjAr SaAb/.cursor/projects/c-Users-GuJjAr-SaAb-Desktop-cta-lms/agent-transcripts';

function tryParseArray(text) {
  const markers = ['[{"id":"CTA-EP-002-FC-', '[{"id": "CTA-EP-002-FC-', '[\n  {\n    "id": "CTA-EP-002-FC-'];
  for (const marker of markers) {
    const start = text.indexOf(marker);
    if (start === -1) continue;
    let depth = 0;
    let inString = false;
    let escape = false;
    for (let i = start; i < text.length; i++) {
      const ch = text[i];
      if (inString) {
        if (escape) escape = false;
        else if (ch === '\\') escape = true;
        else if (ch === '"') inString = false;
        continue;
      }
      if (ch === '"') {
        inString = true;
        continue;
      }
      if (ch === '[') depth++;
      else if (ch === ']') {
        depth--;
        if (depth === 0) {
          try {
            const arr = JSON.parse(text.slice(start, i + 1));
            if (Array.isArray(arr) && arr.length >= 800 && arr[0]?.id?.startsWith('CTA-EP-002-FC-')) {
              return arr;
            }
          } catch {
            /* next marker */
          }
          break;
        }
      }
    }
  }
  return null;
}

function walkTranscripts(dir, acc = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walkTranscripts(full, acc);
    else if (entry.name.endsWith('.jsonl')) acc.push(full);
  }
  return acc;
}

function extractFromLine(line) {
  if (!line.includes('CTA-EP-002-FC-0001') || !line.includes('CTA-EP-002-FC-0917')) return null;
  const direct = tryParseArray(line);
  if (direct) return direct;
  try {
    const obj = JSON.parse(line);
    const found = [];
    const walk = (node) => {
      if (!node) return;
      if (typeof node === 'string') {
        const arr = tryParseArray(node);
        if (arr) found.push(arr);
        return;
      }
      if (Array.isArray(node)) {
        for (const item of node) walk(item);
        return;
      }
      if (typeof node === 'object') {
        for (const v of Object.values(node)) walk(v);
      }
    };
    walk(obj);
    return found.sort((a, b) => b.length - a.length)[0] ?? null;
  } catch {
    return null;
  }
}

let best = null;
let bestMeta = null;

for (const file of walkTranscripts(transcriptRoot)) {
  const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/).filter(Boolean);
  for (let li = 0; li < lines.length; li++) {
    const arr = extractFromLine(lines[li]);
    if (arr && (!best || arr.length > best.length)) {
      best = arr;
      bestMeta = { file, line: li + 1, size: lines[li].length };
    }
  }
}

if (!best) {
  console.error('No 833-card array found in transcripts');
  process.exit(1);
}

const outFile = path.join(__dirname, 'incoming-user-json.txt');
fs.writeFileSync(outFile, `${JSON.stringify(best, null, 2)}\n`, 'utf8');

console.log(JSON.stringify({
  source: bestMeta,
  count: best.length,
  first: best[0].id,
  last: best[best.length - 1].id,
  fc808Concept: best.find((c) => c.id === 'CTA-EP-002-FC-0808')?.concept,
  outFile,
}, null, 2));
