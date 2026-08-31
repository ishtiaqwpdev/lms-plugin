/**
 * Build LCSW California Law & Ethics Flashcard Study Center JSON (833 cards / 10 sections).
 * Node parser avoids PowerShell ConvertFrom-Json issues with curly quotes in cue fields.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const sourceFile = process.argv[2]
  ? path.resolve(process.argv[2])
  : path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');
const outFile = path.join(
  root,
  'assets/course-materials/lcsw-law-ethics/study-tools/flashcard-study-center.json'
);

function domainKey(label) {
  const s = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  return s || 'general';
}

function sortOrder(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

function domainLabel(card) {
  if (card.section === 'License-Specific Module') return 'License-Specific Module';
  const topic = (card.topic || '').trim();
  return topic || card.section || '';
}

function buildFront(card) {
  const concept = (card.concept || '').trim();
  const prompt = (card.prompt || '').trim();
  if (concept && prompt && concept !== prompt) return `${concept}\n\n${prompt}`;
  return prompt || concept;
}

const domainOrder = [
  { label: 'Informed Consent, Minors & Families', expected: 167 },
  { label: 'Telehealth & Technology', expected: 102 },
  { label: 'Professional Competence', expected: 100 },
  { label: 'Professional Impairment', expected: 78 },
  { label: 'Client Welfare & Harm Prevention', expected: 81 },
  { label: 'Boundaries & Exploitation', expected: 88 },
  { label: 'Cultural Humility & Bias', expected: 52 },
  { label: 'Confidentiality & Information Sharing', expected: 70 },
  { label: 'Documentation & Records', expected: 70 },
  { label: 'License-Specific Module', expected: 25 },
];

if (!fs.existsSync(sourceFile)) {
  console.error(`Missing source JSON: ${sourceFile}`);
  process.exit(1);
}

let raw = fs.readFileSync(sourceFile, 'utf8');
if (raw.charCodeAt(0) === 0xfeff) raw = raw.slice(1);
const parsed = JSON.parse(raw);
const sourceCards = Array.isArray(parsed) ? parsed : parsed.cards;
if (!sourceCards?.length) {
  console.error('Source JSON must be a non-empty array or { cards: [...] }');
  process.exit(1);
}

console.log(`Source records: ${sourceCards.length}`);

const seen = new Set();
const domainCounts = {};
const cards = [];

for (const src of sourceCards) {
  const id = (src.id || '').trim();
  if (!id) throw new Error('Card missing id');
  if (seen.has(id)) throw new Error(`Duplicate id: ${id}`);
  seen.add(id);

  const dLabel = domainLabel(src);
  const dKey = domainKey(dLabel);
  const front = buildFront(src);
  const back = (src.back || '').trim();
  const cue = (src.cue || '').trim();
  if (!front || !back) throw new Error(`Card ${id} missing front or back`);

  domainCounts[dKey] = (domainCounts[dKey] || 0) + 1;

  const meta = {};
  if (src.section) meta.section = src.section;
  if (src.topic) meta.topic = src.topic;
  if (src.workbook !== undefined && src.workbook !== '') meta.workbook = Number(src.workbook);
  if (src.chapter) meta.chapter = Number(src.chapter);
  if (src.chapterTitle) meta.chapterTitle = src.chapterTitle;
  if (src.type) meta.type = src.type;

  cards.push({
    id,
    domain: dKey,
    domain_label: dLabel,
    front,
    back,
    memory_cue: cue,
    sort_order: sortOrder(id),
    meta,
  });
}

cards.sort((a, b) => a.sort_order - b.sort_order || a.id.localeCompare(b.id));

if (cards.length !== 833) {
  console.error(`Expected 833 cards, got ${cards.length}`);
  process.exit(1);
}

const domains = domainOrder.map((def, i) => {
  const key = domainKey(def.label);
  const count = domainCounts[key] || 0;
  if (count !== def.expected) {
    throw new Error(`Domain '${def.label}': expected ${def.expected}, got ${count}`);
  }
  return { key, label: def.label, order: i + 1, expected_count: count };
});

fs.mkdirSync(path.dirname(outFile), { recursive: true });
const deck = {
  program: 'lcsw-law-ethics',
  title: 'LCSW California Law & Ethics - Flashcard Study Center',
  version: '1.0',
  expected_total: 833,
  source:
    'CTA_LCSW_LawEthics_Flashcards_833.json (CTA_LCSW_Law_and_Ethics_EP_Master_Flashcard_Study_Center_v1.0.html)',
  domains,
  cards,
};

fs.writeFileSync(outFile, JSON.stringify(deck, null, 2), 'utf8');
console.log(`Wrote ${cards.length} cards / ${domains.length} domains -> ${outFile}`);
for (const d of domains) {
  console.log(`  ${d.label}: ${d.expected_count}`);
}
