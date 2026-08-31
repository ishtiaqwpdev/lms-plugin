/**
 * Extract the 833-card JSON array from a Cursor agent transcript user message.
 * Usage: node tools/extract-flashcards-from-transcript.mjs [path-to.jsonl]
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const defaultTranscript = path.join(
  process.env.USERPROFILE || '',
  '.cursor/projects/c-Users-GuJjAr-SaAb-Desktop-cta-lms/agent-transcripts/e0c823a2-884f-482e-9155-8288e2c64f75/e0c823a2-884f-482e-9155-8288e2c64f75.jsonl'
);
const transcriptPath = process.argv[2] ? path.resolve(process.argv[2]) : defaultTranscript;
const outSource = path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json');

if (!fs.existsSync(transcriptPath)) {
  console.error('Transcript not found:', transcriptPath);
  process.exit(1);
}

const lines = fs.readFileSync(transcriptPath, 'utf8').split(/\r?\n/).filter(Boolean);
let best = null;
let bestLen = 0;

for (const line of lines) {
  let row;
  try {
    row = JSON.parse(line);
  } catch {
    continue;
  }
  if (row.role !== 'user') continue;
  const text = row.message?.content
    ?.filter((c) => c.type === 'text')
    .map((c) => c.text)
    .join('\n');
  if (!text || !text.includes('CTA-EP-002-FC-0001')) continue;
  const start = text.indexOf('[');
  const end = text.lastIndexOf(']');
  if (start < 0 || end <= start) continue;
  const slice = text.slice(start, end + 1);
  if (slice.length > bestLen) {
    bestLen = slice.length;
    best = slice;
  }
}

if (!best) {
  console.error('No flashcard JSON array found in transcript.');
  process.exit(1);
}

let cards;
try {
  cards = JSON.parse(best);
} catch (e) {
  console.error('Failed to parse extracted JSON:', e.message);
  process.exit(1);
}

if (!Array.isArray(cards)) {
  console.error('Extracted payload is not an array.');
  process.exit(1);
}

fs.writeFileSync(outSource, JSON.stringify(cards, null, 2), 'utf8');
console.log(`Extracted ${cards.length} cards -> ${outSource}`);
