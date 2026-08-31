/**
 * Merge good flashcard chunks + 41-approved-tail.json -> user-flashcard-paste.json
 * Excludes gap-ic-52-68.json and 40-tail.json (LPCC-derived fallback).
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const chunksDir = path.join(__dirname, 'flashcard-chunks');
const tailFile = path.join(chunksDir, '41-approved-tail.json');
const outFile = path.join(__dirname, 'user-flashcard-paste.json');
const exclude = new Set(['gap-ic-52-68.json', '40-tail.json']);

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

const byId = new Map();
const files = fs.readdirSync(chunksDir)
  .filter((f) => f.endsWith('.json') && !exclude.has(f))
  .sort();

for (const file of files) {
  const arr = JSON.parse(fs.readFileSync(path.join(chunksDir, file), 'utf8'));
  if (!Array.isArray(arr)) throw new Error(`${file} must be a JSON array`);
  for (const card of arr) {
    if (!card.id) throw new Error(`${file}: card missing id`);
    byId.set(card.id, card);
  }
}

if (!fs.existsSync(tailFile)) {
  console.error(`Missing ${tailFile} — add the 43 approved cards (ids 808–917 range).`);
  process.exit(1);
}

const tail = JSON.parse(fs.readFileSync(tailFile, 'utf8'));
if (!Array.isArray(tail)) throw new Error('41-approved-tail.json must be a JSON array');
for (const card of tail) {
  if (!card.id) throw new Error('Tail card missing id');
  byId.set(card.id, card);
}

const cards = [...byId.values()].sort((a, b) => cardNum(a.id) - cardNum(b.id));

console.log(`Merged ${cards.length} unique cards from ${files.length} chunks + tail`);
console.log(`First: ${cards[0]?.id}, Last: ${cards[cards.length - 1]?.id}`);

if (cards.length !== 833) {
  console.error(`Expected 833 cards, got ${cards.length}`);
  process.exit(1);
}

const fc808 = cards.find((c) => c.id === 'CTA-EP-002-FC-0808');
console.log(`FC-0808 concept: ${fc808?.concept ?? 'NOT FOUND'}`);
console.log(`FC-0808 topic: ${fc808?.topic ?? 'NOT FOUND'}`);

fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Wrote ${outFile}`);
