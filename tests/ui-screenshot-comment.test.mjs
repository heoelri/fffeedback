import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const workflow = readFileSync(new URL('../.github/workflows/ui-screenshot-comment.yml', import.meta.url), 'utf8');
const validation = workflow.slice(workflow.indexOf('          mapfile -t files'), workflow.indexOf('          pr_number='))
  .replace(/^          /gm, '');
const requiredFiles = [
  '01-umfrage-fragen.png',
  '01-umfrage-start.png',
  '02-admin-auswertung.png',
  '02-admin-login.png',
  '02-admin-uebersicht.png',
  '02-admin-umfrage.png',
];

function validate(files) {
  const dir = mkdtempSync(join(tmpdir(), 'ui-screenshot-comment-'));
  try {
    mkdirSync(join(dir, 'screenshots'));
    for (const file of files) writeFileSync(join(dir, 'screenshots', file), '');
    const result = spawnSync('bash', ['-euo', 'pipefail', '-c', validation], { cwd: dir, encoding: 'utf8' });
    assert.ifError(result.error);
    return result.status;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

test('Screenshot-Kommentar akzeptiert die bisherigen sechs Screenshots', () => {
  assert.equal(validate(requiredFiles), 0);
});

test('Screenshot-Kommentar akzeptiert zusätzliche Auswertungs- und Umfrageansichten', () => {
  assert.equal(validate([...requiredFiles,
    '02-admin-auswertung-gruppe.png',
    '02-admin-auswertung-gesperrt.png',
    '01-umfrage-mobil.png',
  ]), 0);
});

test('Screenshot-Kommentar lehnt fehlende Pflichtansichten und leere Artefakte ab', () => {
  for (const file of requiredFiles) {
    assert.notEqual(validate(requiredFiles.filter((name) => name !== file)), 0);
  }
  assert.notEqual(validate([]), 0);
});

test('Screenshot-Kommentar lehnt unbekannte Gruppen und unsichere Dateinamen ab', () => {
  for (const file of ['03-andere-ansicht.png', '02-admin-[link].png', '01-umfrage-$(id).png', '02-admin-test name.png']) {
    assert.notEqual(validate([...requiredFiles, file]), 0);
  }
});
