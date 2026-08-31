/**
 * Write tools/approved-full-833.json from tools/incoming-user-json.txt
 * (raw JSON array pasted by user, no markdown wrapper).
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const inFile = path.join(__dirname, 'incoming-user-json.txt');
const outFile = path.join(__dirname, 'approved-full-833.json');

if (!fs.existsSync(inFile)) {
  console.error(`Missing ${inFile}`);
  process.exit(1);
}

let raw = fs.readFileSync(inFile, 'utf8').trim();
if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
const cards = JSON.parse(raw);
if (!Array.isArray(cards)) {
  console.error('Expected JSON array');
  process.exit(1);
}

fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Wrote ${cards.length} cards -> ${outFile}`);
console.log(`Last id: ${cards[cards.length - 1]?.id}`);
