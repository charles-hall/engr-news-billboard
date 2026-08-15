/**
 * Dev helper: render the slide headless at 1920x1080 and save a PNG per story.
 *
 *   node tools/screenshot.js "http://127.0.0.1:8899/index.html?site=csc&dwell=6"
 *
 * Not needed in production. Requires playwright.
 */
const { chromium } = require('playwright');

const url = process.argv[2] || 'http://127.0.0.1:8899/index.html?site=csc';
const shots = parseInt(process.argv[3] || '3', 10);
const dwellMs = parseInt(process.argv[4] || '6000', 10);

(async () => {
  // PW_CHROME lets you point at an already-installed Chromium instead of
  // downloading one, e.g. PW_CHROME=/usr/bin/chromium node tools/screenshot.js
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined });
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });

  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));

  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('.slide.is-active', { timeout: 20000 });
  await page.waitForTimeout(2500);

  for (let i = 0; i < shots; i++) {
    await page.screenshot({ path: `/tmp/slide-${i + 1}.png` });
    const overflow = await page.evaluate(() => {
      const s = document.querySelector('.slide.is-active');
      if (!s) return null;
      const h = s.querySelector('.headline');
      const a = s.querySelector('.abstract');
      return {
        headline: h.textContent.slice(0, 70),
        fontSize: getComputedStyle(h).fontSize,
        headlineOverflow: h.scrollHeight > h.clientHeight + 1,
        abstractOverflow: a.scrollHeight > a.clientHeight + 1,
        panelOverflow: (() => {
          const p = s.querySelector('.panel-inner');
          return p.scrollHeight > s.querySelector('.panel').clientHeight - 128;
        })()
      };
    });
    console.log(JSON.stringify(overflow));
    if (i < shots - 1) await page.waitForTimeout(dwellMs);
  }

  if (errors.length) console.log('CONSOLE ERRORS:', errors);
  await browser.close();
})();
