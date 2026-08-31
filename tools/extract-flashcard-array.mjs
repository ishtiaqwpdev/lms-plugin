/**
 * Extract a CTA-EP-002 flashcard JSON array from text (transcript, jsonl line, or file).
 * Handles `"id": "CTA-EP-002-FC-` spacing variants and escaped JSON in jsonl.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

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
          const slice = text.slice(start, i + 1);
          try {
            const arr = JSON.parse(slice);
            if (Array.isArray(arr) && arr.length >= 800 && arr[0]?.id?.startsWith('CTA-EP-002-FC-')) {
              return arr;
            }
          } catch {
            /* try next marker */
          }
          break;
        }
      }
    }
  }
  return null;
}

function tryParseJsonlUserLine(line) {
  if (!line.includes('CTA-EP-002-FC-0917')) return null;
  try {
    const obj = JSON.parse(line);
    const parts = [];
    const walk = (node) => {
      if (!node) return;
      if (typeof node === 'string') {
        const arr = tryParseArray(node);
        if (arr) parts.push(arr);
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
    return parts.sort((a, b) => b.length - a.length)[0] ?? null;
  } catch {
    return tryParseArray(line);
  }
}

function extractFromFile(filePath) {
  const text = fs.readFileSync(filePath, 'utf8');
  let best = tryParseArray(text);
  if (!best) {
    for (const line of text.split(/\r?\n/)) {
      if (!line.includes('CTA-EP-002-FC-0917')) continue;
      const arr = tryParseJsonlUserLine(line);
      if (arr && (!best || arr.length > best.length)) best = arr;
    }
  }
  return best;
}

const inputs = process.argv.slice(2);
const outFile = path.join(__dirname, 'incoming-user-json.txt');

if (inputs.length === 0) {
  console.error('Usage: node extract-flashcard-array.mjs <file> [file...]');
  process.exit(1);
}

let best = null;
let bestFile = '';
for (const input of inputs) {
  const filePath = path.resolve(input);
  if (!fs.existsSync(filePath)) {
    console.warn('Skip missing:', filePath);
    continue;
  }
  const arr = extractFromFile(filePath);
  if (arr && (!best || arr.length > best.length)) {
    best = arr;
    bestFile = filePath;
  }
}

if (!best) {
  console.error('No 833-card array extracted');
  process.exit(1);
}

console.log('Extracted', best.length, 'cards from', bestFile);
console.log('First:', best[0].id, 'Last:', best[best.length - 1].id);
const fc808 = best.find((c) => c.id === 'CTA-EP-002-FC-0808');
console.log('FC-0808 concept:', fc808?.concept);

fs.writeFileSync(outFile, `${JSON.stringify(best, null, 2)}\n`, 'utf8');
console.log('Wrote', outFile);
