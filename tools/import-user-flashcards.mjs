/**
 * Import approved paste JSON -> source file -> build live deck.
 * Usage: node tools/import-user-flashcards.mjs [path-to-paste.json]
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const pasteFile = process.argv[2]
  ? path.resolve(process.argv[2])
  : path.join(__dirname, 'user-flashcard-paste.json');
const sourceFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const buildScript = path.join(__dirname, 'build-lcsw-law-ethics-flashcard-deck.mjs');

if (!fs.existsSync(pasteFile)) {
  console.error(`Missing paste file: ${pasteFile}`);
  console.error('Save the approved 833-card JSON array to tools/user-flashcard-paste.json');
  process.exit(1);
}

let raw = fs.readFileSync(pasteFile, 'utf8');
if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
const cards = JSON.parse(raw);
if (!Array.isArray(cards)) {
  console.error('Paste must be a JSON array.');
  process.exit(1);
}
console.log(`Paste contains ${cards.length} cards`);
if (cards.length !== 833) {
  console.error(`Expected 833 cards, got ${cards.length}`);
  process.exit(1);
}

fs.writeFileSync(sourceFile, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Wrote source: ${sourceFile}`);

const result = spawnSync(process.execPath, [buildScript, sourceFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
