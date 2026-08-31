/**
 * Save approved 833-card JSON from a file path, validate, and import.
 * Usage: node tools/save-approved-json.mjs <path-to-json-array>
 */
import fs from 'fs';
import path from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const inPath = path.resolve(process.argv[2] || path.join(__dirname, 'incoming-user-json.txt'));
const pasteFile = path.join(__dirname, 'user-flashcard-paste.json');
const importScript = path.join(__dirname, 'import-user-flashcards.mjs');

const FORBIDDEN = new Set([
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(52 + i).padStart(4, '0')}`),
  ...Array.from({ length: 8 }, (_, i) => `CTA-EP-002-FC-${String(61 + i).padStart(4, '0')}`),
]);

const EXPECTED_DOMAINS = {
  'Informed Consent, Minors & Families': 167,
  'Telehealth & Technology': 102,
  'Professional Competence': 100,
  'Professional Impairment': 78,
  'Client Welfare & Harm Prevention': 81,
  'Boundaries & Exploitation': 88,
  'Cultural Humility & Bias': 52,
  'Confidentiality & Information Sharing': 70,
  'Documentation & Records': 70,
  'License-Specific Module': 25,
};

function domainLabel(card) {
  if (card.section === 'License-Specific Module') return 'License-Specific Module';
  const topic = (card.topic || '').trim();
  return topic || card.section || '';
}

if (!fs.existsSync(inPath)) {
  console.error(`Missing input: ${inPath}`);
  process.exit(1);
}

let raw = fs.readFileSync(inPath, 'utf8').trim();
if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
const cards = JSON.parse(raw);
if (!Array.isArray(cards)) {
  console.error('Expected JSON array');
  process.exit(1);
}

if (cards.length !== 833) {
  console.error(`Expected 833 cards, got ${cards.length}`);
  process.exit(1);
}

const ids = new Set();
for (const card of cards) {
  if (!card.id) {
    console.error('Card missing id');
    process.exit(1);
  }
  if (ids.has(card.id)) {
    console.error(`Duplicate id: ${card.id}`);
    process.exit(1);
  }
  ids.add(card.id);
}

for (const id of FORBIDDEN) {
  if (ids.has(id)) {
    console.error(`Forbidden gap card present: ${id}`);
    process.exit(1);
  }
}

const last = cards[cards.length - 1];
if (last.id !== 'CTA-EP-002-FC-0917') {
  console.error(`Expected last id CTA-EP-002-FC-0917, got ${last.id}`);
  process.exit(1);
}

const fc808 = cards.find((c) => c.id === 'CTA-EP-002-FC-0808');
if (!fc808 || fc808.concept !== 'Source Hierarchy') {
  console.error(`FC-0808 concept must be "Source Hierarchy", got "${fc808?.concept ?? 'NOT FOUND'}"`);
  process.exit(1);
}
if (fc808.topic !== 'LCSW/ASW License-Specific Law & Ethics') {
  console.error(`FC-0808 topic mismatch: ${fc808.topic}`);
  process.exit(1);
}
if (fc808.type !== 'License-Specific') {
  console.error(`FC-0808 type must be License-Specific, got ${fc808.type}`);
  process.exit(1);
}

const domainCounts = {};
for (const card of cards) {
  const domain = domainLabel(card) || 'Unknown';
  domainCounts[domain] = (domainCounts[domain] || 0) + 1;
}

for (const [domain, expected] of Object.entries(EXPECTED_DOMAINS)) {
  const got = domainCounts[domain] || 0;
  if (got !== expected) {
    console.error(`Domain "${domain}": expected ${expected}, got ${got}`);
    process.exit(1);
  }
}

fs.writeFileSync(pasteFile, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
console.log(`Validated and wrote ${cards.length} cards -> ${pasteFile}`);
console.log(`Last id: ${last.id}, FC-0808 concept: ${fc808.concept}`);

const result = spawnSync(process.execPath, [importScript, pasteFile], {
  stdio: 'inherit',
  cwd: path.resolve(__dirname, '..'),
});
process.exit(result.status ?? 1);
