/**
 * Rebuild verbatim 833 from 790 base + approved cards keyed by id.
 * Approved map: base790 first, then overlay from approved-full source (user message JSON).
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const baseFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const approvedFile = path.resolve(process.argv[2] || path.join(__dirname, 'incoming-user-json.txt'));
const saveScript = path.join(__dirname, 'save-approved-json.mjs');

const FORBIDDEN = new Set([
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(52 + i).padStart(4, '0')}`),
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(61 + i).padStart(4, '0')}`),
]);

function loadArray(filePath) {
  let raw = fs.readFileSync(filePath, 'utf8').trim();
  if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
  const data = JSON.parse(raw);
  if (!Array.isArray(data)) throw new Error(`${filePath} must be a JSON array`);
  return data;
}

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

const base = loadArray(baseFile);
const approved = loadArray(approvedFile);

const byId = new Map();
for (const card of base) {
  byId.set(card.id, card);
}
for (const card of approved) {
  if (!card.id) {
    console.error('Approved card missing id');
    process.exit(1);
  }
  byId.set(card.id, card);
}

for (const id of FORBIDDEN) {
  byId.delete(id);
}

const cards = [...byId.values()].sort((a, b) => cardNum(a.id) - cardNum(b.id));

const outFile = path.join(__dirname, 'incoming-user-json.txt');
fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Merged ${cards.length} cards -> ${outFile}`);
console.log(`Last id: ${cards[cards.length - 1]?.id}`);

const result = spawnSync(process.execPath, [saveScript, outFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
