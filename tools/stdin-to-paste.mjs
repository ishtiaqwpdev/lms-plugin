import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import readline from 'readline';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outFile = path.join(__dirname, 'user-flashcard-paste.json');

// Read JSON array from stdin (paste full approved array, then EOF).
const rl = readline.createInterface({ input: process.stdin, terminal: false });
let data = '';
for await (const line of rl) {
  data += line + '\n';
}
data = data.trim();
if (!data) {
  console.error('No stdin data. Pipe or redirect the JSON array into this script.');
  process.exit(1);
}
const cards = JSON.parse(data);
if (!Array.isArray(cards)) {
  console.error('Expected a JSON array.');
  process.exit(1);
}
fs.writeFileSync(outFile, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Saved ${cards.length} cards to ${outFile}`);
