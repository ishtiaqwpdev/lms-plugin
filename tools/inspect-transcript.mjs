import fs from 'fs';

const f =
  'C:/Users/GuJjAr SaAb/.cursor/projects/c-Users-GuJjAr-SaAb-Desktop-cta-lms/agent-transcripts/e0c823a2-884f-482e-9155-8288e2c64f75/e0c823a2-884f-482e-9155-8288e2c64f75.jsonl';
const s = fs.readFileSync(f, 'utf8');
console.log('size', s.length);
const lines = s.split(/\r?\n/).filter(Boolean);
console.log('lines', lines.length);
for (const line of lines) {
  try {
    const o = JSON.parse(line);
    if (o.role === 'user') {
      const t = o.message?.content?.[0]?.text || '';
      console.log('user line len', t.length, 'has0001', t.includes('CTA-EP-002-FC-0001'), 'has0917', t.includes('CTA-EP-002-FC-0917'));
      const start = t.indexOf('[{');
      if (start >= 0) console.log('array start at', start);
    }
  } catch {
    /* ignore */
  }
}
