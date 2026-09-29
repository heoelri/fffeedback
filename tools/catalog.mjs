// Prints the question catalog of a survey as Markdown: node tools/catalog.mjs public/surveys/<file>.json
import fs from 'node:fs';
import { questions, scaleOptions } from '../public/logic.js';

const file = process.argv[2];
const def = JSON.parse(fs.readFileSync(file, 'utf8'));
const all = questions(def);
let md = `# Fragenkatalog: ${def.title}\n\n> Automatisch erzeugt aus \`${file}\` mit \`node tools/catalog.mjs ${file}\`.\n\n${def.intro}\n\n**Anonymität:** ${def.anonymity}\n`;
let section;
for (const q of all) {
  if (q.section !== section) {
    section = q.section;
    const s = def.sections.find((x) => x.id === section);
    md += `\n## ${s.title}\n\n${s.description ? `_${s.description}_\n\n` : ''}`;
  }
  const opts = q.type === 'scale' ? scaleOptions(q).map(([, l]) => l) : q.options ?? [];
  const cond = q.showIf ? ` _(nur wenn „${all.find((p) => p.id === q.showIf.q).text}“ = ${q.showIf.in.join(' / ')})_` : '';
  md += `- **${q.text}**${cond}  \n  ${q.type === 'text' ? 'Freitext' : `${q.type === 'multi' ? 'Mehrfachauswahl' : 'Auswahl'}: ${opts.join(' · ')} + optionaler Kommentar`}\n`;
}
process.stdout.write(md);
