// Browser rules (logic.js) must match the server (lib.php): node --test tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { sanitize, questions, isNegative } from '../public/logic.js';

const survey = JSON.parse(fs.readFileSync(new URL('../public/surveys/dahlbruch-2026.json', import.meta.url)));

test('logic.js und lib.php bereinigen Antworten identisch', () => {
  const chain = { sections: [{ id: 's', title: 'S', freeText: false, questions: [
    { id: 'a', type: 'single', text: 'A', options: ['Ja', 'Nein'] },
    { id: 'b', type: 'multi', text: 'B', options: ['x', 'y'], showIf: { q: 'a', in: ['Ja'] } },
    { id: 'c', type: 'text', text: 'C', showIf: { q: 'b', in: ['y'] } },
  ] }] };
  const cases = [
    [chain, { answers: { a: 'Nein', b: ['y'], c: 'weg' } }],
    [chain, { answers: { a: 'Ja', b: ['y', 'x'], c: '  bleibt  ' }, notes: { a: ' n ', c: 'nie' } }],
    [chain, { answers: { a: 'Ja', b: ['y', 'y'], c: 'weg' } }],
    [survey, { answers: {
      uebungen: 'Selten', uebungen_warum: ['Gesundheit'], einsaetze: 'Oft', gesamt_bewertung: 3, kameradschaft: 'na',
      empfehlung: 'Nein', gh_gut: 'x'.repeat(2100), lehrgaenge: ['Auf Kreisebene'], lg_kreis: 5, lg_stadt: 2,
      ud_mehr: 'Atemschutz', ag_bewertung: 4, alter: '18–29', zz: 1,
    }, notes: { empfehlung: 'zu wenig Zeit', gh_gut: 'nein', kameradschaft: '   ' } }],
    [survey, { answers: { gesamt_bewertung: '3', kameradschaft: 2.5, empfehlung: ['Ja'] } }],
    [survey, {}],
  ];
  for (const [s, input] of cases) {
    const [server] = JSON.parse(execFileSync('php', [fileURLToPath(new URL('sanitize.php', import.meta.url))], {
      input: JSON.stringify({ survey: s, inputs: [input] }),
    }));
    assert.deepEqual(server, sanitize(s, input), JSON.stringify(input));
  }
});

test('Freitext je Kategorie und negative Antworten', () => {
  const qs = questions(survey);
  assert.ok(qs.some((q) => q.id === 'atemschutz_frei') && !qs.some((q) => q.id === 'person_frei'));
  const [scale] = qs;
  const empfehlung = qs.find((q) => q.id === 'empfehlung');
  assert.ok(isNegative(scale, 1) && isNegative(scale, 2) && !isNegative(scale, 3) && !isNegative(scale, 'na'));
  assert.ok(isNegative(empfehlung, 'Nein') && !isNegative(empfehlung, 'Ja'));
});
