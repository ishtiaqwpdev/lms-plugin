/**
 * Compose verbatim 833-card paste: 790-card merge base + 41-approved-tail.json.
 * Does not use gap-ic-52-68.json or 40-tail.json.
 *
 * Optional: node tools/compose-verbatim-833.mjs --extract-tail=path/to/full-833.json
 *   Writes tools/flashcard-chunks/41-approved-tail.json (cards not in 790 base).
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const baseFile = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const tailFile = path.join(__dirname, 'flashcard-chunks', '41-approved-tail.json');
const outFile = path.join(__dirname, 'user-flashcard-paste.json');
const importScript = path.join(__dirname, 'import-user-flashcards.mjs');

const FORBIDDEN = new Set([
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(52 + i).padStart(4, '0')}`),
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(61 + i).padStart(4, '0')}`),
]);

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

function loadJsonArray(filePath, label) {
  if (!fs.existsSync(filePath)) {
    console.error(`Missing ${label}: ${filePath}`);
    process.exit(1);
  }
  let raw = fs.readFileSync(filePath, 'utf8');
  if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
  const data = JSON.parse(raw);
  if (!Array.isArray(data)) {
    console.error(`${label} must be a JSON array`);
    process.exit(1);
  }
  return data;
}

const extractArg = process.argv.find((a) => a.startsWith('--extract-tail='));
if (extractArg) {
  const fullPath = path.resolve(extractArg.slice('--extract-tail='.length));
  const base = loadJsonArray(baseFile, '790 base');
  const baseIds = new Set(base.map((c) => c.id));
  const full = loadJsonArray(fullPath, 'full approved deck');
  const tail = full.filter((c) => c.id && !baseIds.has(c.id));
  if (tail.length !== 43) {
    console.error(`Expected 43 tail cards not in base, got ${tail.length}`);
    process.exit(1);
  }
  fs.mkdirSync(path.dirname(tailFile), { recursive: true });
  fs.writeFileSync(tailFile, `${JSON.stringify(tail, null, 2)}\n`, 'utf8');
  console.log(`Wrote ${tail.length} tail cards -> ${tailFile}`);
}

const baseCards = loadJsonArray(baseFile, '790 base');
if (baseCards.length !== 790) {
  console.error(`Expected 790 base cards, got ${baseCards.length}`);
  process.exit(1);
}

const tailCards = loadJsonArray(tailFile, '41-approved-tail');
if (tailCards.length !== 43) {
  console.error(`Expected 43 tail cards, got ${tailCards.length}`);
  process.exit(1);
}

const byId = new Map();
for (const card of baseCards) {
  if (!card.id) {
    console.error('Base card missing id');
    process.exit(1);
  }
  byId.set(card.id, card);
}
for (const card of tailCards) {
  if (!card.id) {
    console.error('Tail card missing id');
    process.exit(1);
  }
  byId.set(card.id, card);
}

const cards = [...byId.values()].sort((a, b) => cardNum(a.id) - cardNum(b.id));

if (cards.length !== 833) {
  console.error(`Expected 833 merged cards, got ${cards.length}`);
  process.exit(1);
}

const last = cards[cards.length - 1];
if (last?.id !== 'CTA-EP-002-FC-0917') {
  console.error(`Expected last id CTA-EP-002-FC-0917, got ${last?.id ?? 'NONE'}`);
  process.exit(1);
}

const fc808 = cards.find((c) => c.id === 'CTA-EP-002-FC-0808');
if (fc808?.concept !== 'Source Hierarchy') {
  console.error(`Expected FC-0808 concept "Source Hierarchy", got "${fc808?.concept ?? 'NOT FOUND'}"`);
  process.exit(1);
}

for (const id of FORBIDDEN) {
  if (byId.has(id)) {
    console.error(`Forbidden LPCC gap card present: ${id}`);
    process.exit(1);
  }
}

fs.writeFileSync(outFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Wrote ${cards.length} cards -> ${outFile}`);
console.log(`Last id: ${last.id}`);
console.log(`FC-0808 concept: ${fc808.concept}`);

const result = spawnSync(process.execPath, [importScript, outFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
