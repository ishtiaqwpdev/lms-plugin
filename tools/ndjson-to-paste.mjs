/**
 * Merge flashcard-chunks/*.json + tools/cards.ndjson -> user-flashcard-paste.json
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const chunksDir = path.join(__dirname, 'flashcard-chunks');
const ndjsonPath = path.join(__dirname, 'cards.ndjson');
const outPath = path.join(__dirname, 'user-flashcard-paste.json');

const cards = [];
const seen = new Set();

function add(card) {
  const id = (card.id || '').trim();
  if (!id || seen.has(id)) return;
  seen.add(id);
  cards.push(card);
}

if (fs.existsSync(chunksDir)) {
  const files = fs.readdirSync(chunksDir).filter((f) => f.endsWith('.json')).sort();
  for (const file of files) {
    const arr = JSON.parse(fs.readFileSync(path.join(chunksDir, file), 'utf8'));
    if (!Array.isArray(arr)) throw new Error(`Chunk not array: ${file}`);
    for (const c of arr) add(c);
  }
}

if (fs.existsSync(ndjsonPath)) {
  for (const line of fs.readFileSync(ndjsonPath, 'utf8').split(/\r?\n/)) {
    const t = line.trim();
    if (!t) continue;
    add(JSON.parse(t));
  }
}

cards.sort((a, b) => {
  const na = parseInt(/FC-(\d+)$/.exec(a.id)?.[1] || '0', 10);
  const nb = parseInt(/FC-(\d+)$/.exec(b.id)?.[1] || '0', 10);
  return na - nb;
});

fs.writeFileSync(outPath, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Wrote ${cards.length} unique cards -> ${outPath}`);
