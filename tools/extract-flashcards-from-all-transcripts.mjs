/**
 * Scan all agent transcripts for the largest flashcard JSON array.
 */
import fs from 'fs';
import path from 'path';

const transcriptsRoot = path.join(
  process.env.USERPROFILE || '',
  '.cursor/projects/c-Users-GuJjAr-SaAb-Desktop-cta-lms/agent-transcripts'
);
const outSource = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Z]:)/, '$1')), 'CTA_LCSW_LawEthics_Flashcards_833.json');

function walk(dir, acc = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name);
    if (ent.isDirectory()) walk(p, acc);
    else if (ent.name.endsWith('.jsonl')) acc.push(p);
  }
  return acc;
}

let best = null;
let bestLen = 0;
let bestFile = '';

for (const file of walk(transcriptsRoot)) {
  const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/).filter(Boolean);
  for (const line of lines) {
    let row;
    try {
      row = JSON.parse(line);
    } catch {
      continue;
    }
    if (row.role !== 'user') continue;
    const parts = row.message?.content || [];
    const text = parts
      .filter((c) => c.type === 'text')
      .map((c) => c.text)
      .join('\n');
    if (!text || !text.includes('CTA-EP-002-FC-0001')) continue;
    const start = text.indexOf('[');
    const end = text.lastIndexOf(']');
    if (start < 0 || end <= start) continue;
    const slice = text.slice(start, end + 1);
    if (slice.length > bestLen) {
      bestLen = slice.length;
      best = slice;
      bestFile = file;
    }
  }
}

if (!best) {
  console.error('No flashcard JSON array found in any transcript.');
  process.exit(1);
}

let cards;
try {
  cards = JSON.parse(best);
} catch (e) {
  console.error('Failed to parse extracted JSON from', bestFile, e.message);
  process.exit(1);
}

if (!Array.isArray(cards)) {
  console.error('Extracted payload is not an array.');
  process.exit(1);
}

fs.writeFileSync(outSource, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Extracted ${cards.length} cards from ${bestFile} (${bestLen} chars) -> ${outSource}`);
