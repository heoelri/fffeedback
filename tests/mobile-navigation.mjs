// Browser regression check: node tests/mobile-navigation.mjs (requires Playwright).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const root = process.env.REPO_ROOT || process.cwd();
const survey = JSON.parse(readFileSync(resolve(root, 'public', 'surveys', 'dahlbruch-2026.json')));
const inViewport = (locator) => locator.evaluate((el) => {
  const rect = el.getBoundingClientRect();
  return rect.bottom > 0 && rect.top < innerHeight;
});
const browser = await chromium.launch();
try {
  for (const viewport of [{ width: 390, height: 844 }, { width: 844, height: 390 }, { width: 1280, height: 900 }]) {
    const mobile = viewport.width < 1280;
    const context = await browser.newContext({ viewport, isMobile: mobile, hasTouch: mobile });
    const page = await context.newPage();
    await page.route('http://survey.test/**', (route) => {
      const url = new URL(route.request().url());
      if (url.searchParams.get('a') === 'load') {
        return route.fulfill({ json: { survey } });
      }
      if (url.searchParams.get('a') === 'draft') return route.fulfill({ status: 204 });
      const file = url.pathname.slice(1) || 'index.html';
      if (file === 'index.html') {
        return route.fulfill({ contentType: 'text/html', body: '<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="style.css"><main id="app"></main><script type="module" src="app.js"></script>' });
      }
      return route.fulfill({
        contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript',
        body: readFileSync(resolve(root, 'public', file), 'utf8'),
      });
    });
    await page.goto('http://survey.test/');
    await page.getByRole('button', { name: 'Los geht’s' }).click();
    if (!mobile) {
      assert.equal(await page.locator('#app > nav').evaluate((el) => getComputedStyle(el).position), 'fixed');
      assert.equal(await inViewport(page.getByRole('button', { name: 'Weiter', exact: true })), true);
      await context.close();
      continue;
    }
    await page.locator('fieldset').first().getByText('schlecht', { exact: true }).click();
    for (let section = 0; section < survey.sections.length; section++) {
      await page.getByText(`Teil ${section + 1} von ${survey.sections.length}`, { exact: true }).waitFor();
      const nav = page.locator('#app > nav');
      assert.equal(await nav.evaluate((el) => getComputedStyle(el).position), 'static');
      const last = page.locator('fieldset:visible').last();
      const lastBox = await last.boundingBox();
      const navBox = await nav.boundingBox();
      assert.ok(navBox.y >= lastBox.y + lastBox.height, 'Navigation must follow the complete last question');
      if (lastBox.y + lastBox.height > viewport.height) {
        assert.equal(await inViewport(page.getByRole('button', { name: 'Weiter', exact: true })), false);
        assert.equal(await inViewport(page.getByRole('button', { name: 'Zurück', exact: true })), false);
      }
      await nav.scrollIntoViewIfNeeded();
      assert.equal(await inViewport(page.getByRole('button', { name: 'Weiter', exact: true })), true);
      await page.getByRole('button', { name: 'Weiter', exact: true }).click();
    }
    await page.getByRole('button', { name: 'Zurück', exact: true }).click();
    await page.getByText(`Teil ${survey.sections.length} von ${survey.sections.length}`, { exact: true }).waitFor();
    assert.equal(await page.locator('#app > nav').evaluate((el) => getComputedStyle(el).position), 'static');
    await context.close();
  }
} finally {
  await browser.close();
}
console.log('Mobile navigation follows the last question in portrait and landscape.');
