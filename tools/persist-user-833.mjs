/**
 * Build verbatim 833-card deck from 790-card base + user-message tail overlay.
 * Tail: FC-0808 through FC-0917 from tools/approved-tail-overlay.json
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const baseFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const tailFile = path.join(__dirname, 'approved-tail-overlay.json');
const outFile = path.join(__dirname, 'incoming-user-json.txt');
const saveScript = path.join(__dirname, 'save-approved-json.mjs');

const FORBIDDEN = new Set([
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(52 + i).padStart(4, '0')}`),
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(61 + i).padStart(4, '0')}`),
]);

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

function loadArray(filePath) {
  let raw = fs.readFileSync(filePath, 'utf8').trim();
  if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
  return JSON.parse(raw);
}

if (!fs.existsSync(tailFile)) {
  console.error(`Missing tail overlay: ${tailFile}`);
  process.exit(1);
}

const base = loadArray(baseFile);
const tail = loadArray(tailFile);

const byId = new Map();
for (const card of base) {
  byId.set(card.id, card);
}
for (const card of tail) {
  byId.set(card.id, card);
}
for (const id of FORBIDDEN) {
  byId.delete(id);
}

const cards = [...byId.values()].sort((a, b) => cardNum(a.id) - cardNum(b.id));

console.log(`Built ${cards.length} cards (base ${base.length}, tail overlay ${tail.length})`);
console.log(`First: ${cards[0]?.id}, Last: ${cards[cards.length - 1]?.id}`);

if (cards.length !== 833) {
  console.error(`Expected 833 cards, got ${cards.length}`);
  process.exit(1);
}

fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');

const result = spawnSync(process.execPath, [saveScript, outFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
