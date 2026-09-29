// Erzeugt UI-Screenshots für den Pull-Request-Kommentar (siehe .github/workflows/ui-screenshots.yml).
// Braucht eine leere Test-Datenbank (tests/config.php) und Playwright; startet selbst `php -S`.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';

const root = process.env.REPO_ROOT || process.cwd();
const base = 'http://127.0.0.1:8123';
const env = { ...process.env, FFF_CONFIG: `${root}/tests/config.php` };
const dir = `${root}/screenshots`;
mkdirSync(dir, { recursive: true });

// Invites the address via tools/seed-local.php and returns its survey link.
const invite = (email) => {
  const log = execFileSync('php', ['tools/seed-local.php', email], { cwd: root, env }).toString();
  const link = log.match(/Umfrage-Link: (\S+)/)?.[1];
  if (!link) throw new Error(`Kein Umfrage-Link in:\n${log}`);
  return link;
};

const server = spawn('php', ['-S', '127.0.0.1:8123', '-t', 'public'], { cwd: root, env, stdio: 'inherit' });
let browser;
try {
  for (let i = 0; !(await fetch(`${base}/style.css`).then((r) => r.ok, () => false)); i++) {
    if (i > 100) throw new Error('PHP-Server startet nicht');
    await sleep(100);
  }

  // At least MIN_GROUP (5) answers, otherwise the report shows nothing.
  const answers = [
    [1, 2, '18–29', 'Gute Kameradschaft'], [2, 2, '18–29', 'Neue Übungsthemen'], [2, 1, '30–45', 'Einsatzleitung'],
    [3, 4, '30–45', 'Mehr Ausbildung'], [2, 3, '46 und älter', 'Organisation'], [4, 2, '30–45', 'Fahrzeuge'],
  ];
  for (const [i, [gesamt, kameradschaft, alter, text]] of answers.entries()) {
    const r = await fetch(`${invite(`antwort${i}@example.org`)}&a=submit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ answers: { gesamt_bewertung: gesamt, kameradschaft, alter, allg_gut: text }, notes: {} }),
    });
    if (r.status !== 204) throw new Error(`Absenden fehlgeschlagen: ${r.status}`);
  }

  browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'de-DE' });
  const shot = (name) => page.screenshot({ path: `${dir}/${name}.png`, fullPage: true });

  await page.goto(invite('test@example.org'));
  await page.getByRole('button', { name: /Los geht/ }).waitFor();
  await shot('01-umfrage-start');
  await page.getByRole('button', { name: /Los geht/ }).click();
  await page.getByText('Teil 1 von').waitFor();
  await page.locator('fieldset').first().getByText('schlecht', { exact: true }).click(); // opens the "Was läuft nicht gut?" box
  await page.getByText('Was läuft nicht gut?').waitFor();
  await shot('01-umfrage-fragen');

  await page.goto(`${base}/admin.php`);
  await shot('02-admin-login');
  await page.fill('input[name=password]', 'geheim-lokal');
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await page.getByText('Umfrage importieren').waitFor();
  await shot('02-admin-uebersicht');

  await page.getByRole('link', { name: /Feedback 2026/ }).click();
  await page.getByRole('heading', { name: 'Beteiligung' }).waitFor();
  await shot('02-admin-umfrage');

  await page.getByLabel('Ja, Umfrage endgültig beenden').check();
  await page.getByRole('button', { name: 'Umfrage beenden' }).click();
  await page.getByText('in dieser Auswahl').waitFor();
  await shot('02-admin-auswertung');
} finally {
  await browser?.close();
  server.kill();
}
