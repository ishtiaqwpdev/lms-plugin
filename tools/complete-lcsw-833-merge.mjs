/**
 * Merge 790-card base + missing IC gap + tail (license/replacements) -> 833-card paste.
 * Fallback when verbatim user paste is unavailable.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const chunksDir = path.join(__dirname, 'flashcard-chunks');
const pastePath = path.join(__dirname, 'user-flashcard-paste.json');
const lpccDeck = JSON.parse(
  fs.readFileSync(
    path.join(__dirname, '../assets/course-materials/lpcc-law-ethics/study-tools/flashcard-study-center.json'),
    'utf8'
  )
);

const IC_GAP_IDS = [52, 53, 54, 55, 56, 57, 58, 59, 61, 62, 63, 64, 65, 66, 67, 68];
const LICENSE_IDS = Array.from({ length: 25 }, (_, i) => 808 + i);
const REPLACEMENT_IDS = { ic: 833, competence: 834 };

const LICENSE_CARDS = [
  { concept: 'Hierarchy of Legal Authority', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'The applicable current statute and effective regulation are the highest-authority sources listed in the facts.' },
  { concept: 'Agency Policy vs. Law', prompt: 'What principle should guide the clinician’s decision?', back: 'It separates policy from authority, prevents a premature disclosure, and preserves minimum-necessary ethical reasoning and documentation.' },
  { concept: 'ASW Status Until Licensure', prompt: 'What is the key principle to remember?', back: 'The person remains an ASW and must continue within registered, supervised practice until the license is issued.' },
  { concept: 'Scope of Clinical Social Work Practice', prompt: 'What principle should guide the clinician’s decision?', back: 'It states the broad scope accurately and preserves the separate competence and practice safeguards.' },
  { concept: 'Treatment vs. Evaluation Roles', prompt: 'What is the strongest first response or sequence?', back: 'It identifies the foundational role, client, confidentiality, use, and conflict questions before a commitment is made.' },
  { concept: 'Competence Before Assignment', prompt: 'What is the strongest first response or sequence?', back: 'It preserves client protection, lawful ASW status, and the need for method-specific preparation and supervision.' },
  { concept: 'California ASW 90-Day Rule', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'The ASW must obtain registration before beginning qualifying supervised experience and must not accumulate hours outside the lawful registration period.' },
  { concept: 'ASW Registration Renewal', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'Registration must remain current; lapsed registration generally stops lawful accumulation of supervised experience.' },
  { concept: 'ASW Advertising Requirements', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'Public-facing professional status must be accurate; associate status, registration, employer, and supervision must not be omitted or misrepresented.' },
  { concept: 'Pending ASW Registration', prompt: 'What principle should guide the clinician’s decision?', back: 'The person should not represent themself as registered or licensed before the Board confirms the lawful status.' },
  { concept: 'ASW Registration Number vs. License', prompt: 'How should these concepts be separated on the exam?', back: 'Registration establishes lawful associate status; licensure is a separate Board action that creates independent practice authority.' },
  { concept: 'Private Practice Restrictions for ASW', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'An ASW may not engage in independent private practice and must remain within registered, supervised, employer-authorized practice.' },
  { concept: 'Supervision Requirements for ASW', prompt: 'What is the key principle to remember?', back: 'Required supervision is a legal control on associate practice; consultation does not substitute for the qualifying supervisory relationship.' },
  { concept: 'Professional Corporation Eligibility', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'Corporate practice and ownership rules depend on licensure status and applicable California law; associate status does not create full independent corporate practice authority.' },
  { concept: 'Scope of Independent LCSW Practice', prompt: 'What principle should guide the clinician’s decision?', back: 'Licensure establishes legal status within the profession-wide scope, but individual competence, consent, and role clarity still govern each service.' },
  { concept: 'Mandatory Reporting and LCSW Duty', prompt: 'What is the strongest first response or sequence?', back: 'It completes the immediate duty while preserving dignity, minimum necessary disclosure, consultation, safety, continuity, and documentation.' },
  { concept: 'Dual Relationship in Agency Settings', prompt: 'What principle should guide the clinician’s decision?', back: 'The therapist should identify role conflict, protect the client, clarify authority, and use supervision or consultation before proceeding.' },
  { concept: 'Record Access and LCSW Responsibility', prompt: 'What risk-management principle should guide the response?', back: 'The therapist should verify authority, share only what is authorized and needed, and document the legal and clinical basis for access or refusal.' },
  { concept: 'Telehealth Practice by ASW', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'Telehealth must comply with California telehealth law, informed consent, privacy safeguards, and the ASW’s registered, supervised practice requirements.' },
  { concept: 'Supervisor Qualifications', prompt: 'What legal rule, authority, or timing requirement applies?', back: 'Only a Board-qualified supervisor may provide the legally required ASW supervision; clinical expertise alone is insufficient.' },
  { concept: 'Experience Hours and Registration', prompt: 'What is the key principle to remember?', back: 'Qualifying hours must be earned within lawful registration and supervision; milestones such as exam passage or application filing do not by themselves change status.' },
  { concept: 'Misrepresentation of Professional Status', prompt: 'What common exam mistake should be avoided?', back: '“Candidate” or future licensure language does not cure a current false implication of licensed independent practice.' },
  { concept: 'Employment Disclosure Requirements', prompt: 'What principle should guide the clinician’s decision?', back: 'Required disclosures must be accurate, visible, and not replaced by employer authorship or informal titles.' },
  { concept: 'Integrated LCSW/ASW Ethical Decision-Making — Part 1 of 2', prompt: 'What risk-management principle should guide the response?', back: 'It integrates current client protection, documentation, internal escalation, equity, advocacy, and policy repair.' },
  { concept: 'Integrated LCSW/ASW Ethical Decision-Making — Part 2 of 2', prompt: 'What additional rule, exception, or safeguard applies?', back: 'When the legal threshold is already met, act without avoidable delay and use ethics to shape the least harmful lawful implementation.' },
];

function cardNum(id) {
  const m = /FC-(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : 0;
}

function splitFront(front) {
  const f = (front || '').trim();
  const idx = f.indexOf('\n\n');
  if (idx >= 0) {
    return { concept: f.slice(0, idx).trim(), prompt: f.slice(idx + 2).trim() };
  }
  const idx2 = f.indexOf('\n');
  if (idx2 >= 0) {
    return { concept: f.slice(0, idx2).trim(), prompt: f.slice(idx2 + 1).trim() };
  }
  return { concept: f, prompt: f };
}

function typeFromPrompt(prompt) {
  switch (prompt) {
    case 'What is the key principle to remember?':
      return 'Key Concept';
    case 'What legal rule, authority, or timing requirement applies?':
      return 'Legal Rule';
    case 'How should these concepts be separated on the exam?':
      return 'Distinction';
    case 'What risk-management principle should guide the response?':
      return 'Risk Management Principle';
    case 'What common exam mistake should be avoided?':
      return 'Exam Trap';
    case 'What is the strongest first response or sequence?':
      return 'Application Principle';
    default:
      return 'Application Principle';
  }
}

const lpccByNum = new Map(lpccDeck.cards.map((c) => [cardNum(c.id), c]));

function lpccToIc(num) {
  const src = lpccByNum.get(num);
  if (!src) throw new Error(`Missing LPCC card ${num}`);
  const { concept, prompt } = splitFront(src.front);
  return {
    id: `CTA-EP-002-FC-${String(num).padStart(4, '0')}`,
    section: 'Workbook 1',
    topic: 'Informed Consent, Minors & Families',
    workbook: 1,
    chapter: 4,
    chapterTitle: 'Required Disclosures',
    type: typeFromPrompt(prompt),
    concept,
    prompt,
    back: src.back,
    cue: src.memory_cue || '',
  };
}

function licenseCard(num, index) {
  const lic = LICENSE_CARDS[index];
  return {
    id: `CTA-EP-002-FC-${String(num).padStart(4, '0')}`,
    section: 'License-Specific Module',
    topic: 'License-Specific Module',
    workbook: 10,
    chapter: 1,
    chapterTitle: 'LCSW/ASW License-Specific Foundations',
    type: typeFromPrompt(lic.prompt),
    concept: lic.concept,
    prompt: lic.prompt,
    back: lic.back,
    cue: '',
  };
}

// Fix FC-0278 in chunk 18.json
const chunk18Path = path.join(chunksDir, '18.json');
const chunk18 = JSON.parse(fs.readFileSync(chunk18Path, 'utf8'));
const idx278 = chunk18.findIndex((c) => c.id === 'CTA-EP-002-FC-0278');
if (idx278 >= 0) {
  chunk18[idx278] = {
    ...chunk18[idx278],
    section: 'Workbook 4',
    topic: 'Professional Impairment',
    workbook: 4,
    chapter: 5,
    chapterTitle: 'Responding Ethically to Professional Impairment',
    type: 'Application Principle',
  };
  fs.writeFileSync(chunk18Path, `${JSON.stringify(chunk18, null, 2)}\n`, 'utf8');
  console.log('Fixed FC-0278 -> Workbook 4 / Professional Impairment');
}

const icGapCards = IC_GAP_IDS.map(lpccToIc);
fs.writeFileSync(path.join(chunksDir, 'gap-ic-52-68.json'), `${JSON.stringify(icGapCards, null, 2)}\n`, 'utf8');
console.log(`Wrote gap-ic-52-68.json: ${icGapCards.length} cards`);

const lpcc834 = lpccByNum.get(834);
const { concept: c834c, prompt: c834p } = splitFront(lpcc834?.front || 'Competence Can Change\nWhat principle should guide the clinician’s decision?');
const replacementCards = [
  {
    id: `CTA-EP-002-FC-${String(REPLACEMENT_IDS.ic).padStart(4, '0')}`,
    section: 'Workbook 1',
    topic: 'Informed Consent, Minors & Families',
    workbook: 1,
    chapter: 4,
    chapterTitle: 'Required Disclosures',
    type: typeFromPrompt('What legal rule, authority, or timing requirement applies?'),
    concept: 'Comprehensive Required Disclosures',
    prompt: 'What legal rule, authority, or timing requirement applies?',
    back: lpccByNum.get(68)?.back || 'The scenario contains distinct legal and professional disclosure duties; no single form or disclosure cures all of them.',
    cue: lpccByNum.get(68)?.memory_cue || 'Integrated questions require addressing every material framework.',
  },
  {
    id: `CTA-EP-002-FC-${String(REPLACEMENT_IDS.competence).padStart(4, '0')}`,
    section: 'Workbook 3',
    topic: 'Professional Competence',
    workbook: 3,
    chapter: 1,
    chapterTitle: 'Professional Competence Foundations',
    type: typeFromPrompt(c834p),
    concept: c834c,
    prompt: c834p,
    back: lpcc834?.back || 'Competence may expand, become outdated, or be temporarily limited as knowledge, case demands, standards, and clinician functioning change.',
    cue: lpcc834?.memory_cue || 'Competence is current and contextual—not a permanent status earned at one point in a career.',
  },
  ...LICENSE_IDS.map((num, i) => licenseCard(num, i)),
];

fs.writeFileSync(path.join(chunksDir, '40-tail.json'), `${JSON.stringify(replacementCards, null, 2)}\n`, 'utf8');
console.log(`Wrote 40-tail.json: ${replacementCards.length} cards (${replacementCards[0].id} – ${replacementCards[replacementCards.length - 1].id})`);

// Merge all chunks -> paste (prefer later files on duplicate id)
const files = fs.readdirSync(chunksDir).filter((f) => f.endsWith('.json')).sort();
const byId = new Map();
for (const file of files) {
  const arr = JSON.parse(fs.readFileSync(path.join(chunksDir, file), 'utf8'));
  for (const card of arr) byId.set(card.id, card);
}

const cards = [...byId.values()].sort(
  (a, b) => cardNum(a.id) - cardNum(b.id) || a.id.localeCompare(b.id)
);

fs.writeFileSync(pastePath, `${JSON.stringify(cards, null, 2)}\n`, 'utf8');
fs.writeFileSync(
  path.join(__dirname, 'CTA_LCSW_LawEthics_Flashcards_833.json'),
  `${JSON.stringify(cards, null, 2)}\n`,
  'utf8'
);

console.log(`Merged ${cards.length} unique cards -> ${pastePath}`);
console.log(`First: ${cards[0]?.id}, Last: ${cards[cards.length - 1]?.id}`);

const exp = {
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
const counts = {};
for (const c of cards) counts[c.topic] = (counts[c.topic] || 0) + 1;
for (const [k, v] of Object.entries(exp)) {
  const have = counts[k] || 0;
  console.log(`  ${k}: ${have}/${v}${have === v ? '' : ' MISMATCH'}`);
}
