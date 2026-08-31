import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const chunksDir = path.join(__dirname, 'flashcard-chunks');
const outFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');

const files = fs.readdirSync(chunksDir)
  .filter((f) => f.endsWith('.json'))
  .sort();

let cards = [];
for (const file of files) {
  const part = JSON.parse(fs.readFileSync(path.join(chunksDir, file), 'utf8'));
  if (!Array.isArray(part)) {
    throw new Error(`${file} must be a JSON array`);
  }
  cards = cards.concat(part);
}

const ids = new Set();
for (const c of cards) {
  if (ids.has(c.id)) throw new Error(`Duplicate id: ${c.id}`);
  ids.add(c.id);
}

fs.writeFileSync(outFile, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Merged ${cards.length} cards from ${files.length} chunks -> ${outFile}`);
