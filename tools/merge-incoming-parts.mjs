/**
 * Merge tools/incoming-parts/part-*.json into tools/incoming-user-json.txt
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const partsDir = path.join(__dirname, 'incoming-parts');
const outFile = path.join(__dirname, 'incoming-user-json.txt');

if (!fs.existsSync(partsDir)) {
  console.error(`Missing ${partsDir}`);
  process.exit(1);
}

const files = fs
  .readdirSync(partsDir)
  .filter((f) => f.startsWith('part-') && f.endsWith('.json'))
  .sort();

const cards = [];
for (const f of files) {
  const chunk = JSON.parse(fs.readFileSync(path.join(partsDir, f), 'utf8'));
  if (!Array.isArray(chunk)) {
    console.error(`${f} must be a JSON array`);
    process.exit(1);
  }
  cards.push(...chunk);
}

fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Merged ${files.length} parts -> ${cards.length} cards -> ${outFile}`);
console.log(`First: ${cards[0]?.id}, Last: ${cards[cards.length - 1]?.id}`);
