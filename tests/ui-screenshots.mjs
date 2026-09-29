// Erzeugt UI-Screenshots für den Pull-Request-Kommentar.
// Siehe .github/workflows/ui-screenshots.yml (erzeugt die PNGs) und
// .github/workflows/ui-screenshot-comment.yml (lädt sie hoch und bettet sie ein).
// Läuft gegen einen lokalen `php -S` Server, kein Docker nötig.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';

const root = process.env.REPO_ROOT || process.cwd();
const base = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8123';
const env = { ...process.env, FFF_CONFIG: `${root}/tests/config.php` };

mkdirSync(`${root}/screenshots`, { recursive: true });
const shot = (page, name) => page.screenshot({ path: `${root}/screenshots/${name}.png`, fullPage: true });

const server = spawn('php', ['-S', '127.0.0.1:8123', '-t', 'public'], { cwd: root, env, stdio: 'ignore' });

try {
  for (let i = 0; i < 100; i++) {
    try {
      await fetch(`${base}/admin.php`);
      break;
    } catch {
      await sleep(100);
    }
  }

  const seedLog = execFileSync('php', ['tools/seed-local.php'], { cwd: root, env }).toString();
  const link = seedLog.match(/Umfrage-Link: (\S+)/)?.[1];
  if (!link) throw new Error('Kein Umfrage-Link im seed-local.php-Log gefunden:\n' + seedLog);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

  await page.goto(link);
  await page.waitForSelector('h1');
  await shot(page, '01-umfrage');

  await page.goto(`${base}/admin.php`);
  await shot(page, '02-admin-login');

  await page.fill('input[name=password]', 'geheim123');
  await page.click('button');
  await page.waitForSelector('h1');
  await shot(page, '02-admin-uebersicht');

  await page.click('text=Feedback 2026');
  await page.waitForSelector('h1');
  await shot(page, '02-admin-umfrage');

  await page.check('input[name=confirm]');
  await page.click('button:has-text("Umfrage beenden")');
  await page.waitForSelector('text=Antworten');
  await shot(page, '02-admin-auswertung');

  await browser.close();
} finally {
  server.kill();
}
