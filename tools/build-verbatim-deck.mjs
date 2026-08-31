/**
 * Build verbatim 833 deck: 790-card base + approved tail overlay (43 cards).
 * Tail overlay: tools/approved-tail-overlay.json
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const baseFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const tailFile = path.join(__dirname, 'approved-tail-overlay.json');
const saveScript = path.join(__dirname, 'save-approved-json.mjs');

const FORBIDDEN = new Set([
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(52 + i).padStart(4, '0')}`),
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(61 + i).padStart(4, '0')}`),
]);

function loadArray(filePath) {
  let raw = fs.readFileSync(filePath, 'utf8').trim();
  if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
  return JSON.parse(raw);
}

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

const base = loadArray(baseFile);
const tail = loadArray(tailFile);

if (base.length !== 790) {
  console.error(`Expected 790 base cards, got ${base.length}`);
  process.exit(1);
}
if (tail.length !== 43) {
  console.error(`Expected 43 tail overlay cards, got ${tail.length}`);
  process.exit(1);
}

const baseIds = new Set(base.map((c) => c.id));
for (const card of tail) {
  if (baseIds.has(card.id)) {
    console.error(`Tail card ${card.id} already in base — tail must be cards not in base`);
    process.exit(1);
  }
}

const byId = new Map(base.map((c) => [c.id, c]));
for (const card of tail) {
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

const fc808 = cards.find((c) => c.id === 'CTA-EP-002-FC-0808');
console.log(`FC-0808 concept: ${fc808?.concept}`);

const result = spawnSync(process.execPath, [saveScript, outFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
