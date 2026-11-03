const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const css = fs.readFileSync(path.resolve(__dirname, '../../../css/content-lesson-pages.css'), 'utf8');
const fullTitle = 'Noun possession (benim, senin, onun, bizim, sizin, onların)';

function prerequisiteLink(index) {
  const title = index === 0 ? fullTitle : `Another prerequisite ${index} (with extra examples)`;
  const shortTitle = index === 0 ? 'Noun possession' : `Another prerequisite ${index}`;
  return `<li class="ll-content-lesson-prerequisites__item${index === 0 ? ' is-completed' : ''}">
    <a href="#prerequisite-${index}" title="${title}" aria-label="${title} — ${index === 0 ? 'Completed' : 'Not completed'}">
      <span class="ll-content-lesson-prerequisites__status" aria-hidden="true">${index === 0 ? '✓' : '○'}</span>
      <span class="ll-content-lesson-prerequisites__label ll-content-lesson-prerequisites__label--shortened">
        <span class="ll-content-lesson-prerequisites__title-full">${title}</span>
        <span class="ll-content-lesson-prerequisites__title-short" aria-hidden="true">${shortTitle}</span>
      </span>
    </a>
  </li>`;
}

function prerequisiteBlock(count) {
  if (!count) return '';
  const items = Array.from({ length: count }, (_, index) => prerequisiteLink(index)).join('');
  if (count === 1) {
    return `<section class="ll-content-lesson-prerequisites ll-content-lesson-prerequisites--compact ll-content-lesson-prerequisites--single">
      <h2 class="ll-content-lesson-prerequisites__heading">Bu dersten önce</h2>
      <ul class="ll-content-lesson-prerequisites__list">${items}</ul>
    </section>`;
  }
  return `<details class="ll-content-lesson-prerequisites ll-content-lesson-prerequisites--compact ll-content-lesson-prerequisites--disclosure">
    <summary class="ll-content-lesson-prerequisites__heading">Bu dersten önce <span class="ll-content-lesson-prerequisites__count">${count}</span></summary>
    <ul class="ll-content-lesson-prerequisites__list">${items}</ul>
  </details>`;
}

async function mountArticle(page, count) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body { margin: 0; font: 16px/1.6 Arial, sans-serif; }
      h1,h2 { font-size: 38px; letter-spacing: 0.08em; margin: 1.6em 0 0.8em; }
      p { margin: 0 0 1.4em; }
      a { color: #900; }
      .site-header { height: 120px; background: #eee; }
      .tt-lesson h2 { margin-top: 2em; font-size: 23px; letter-spacing: 0; line-height: 1.3; }
    </style><style>${css}</style></head><body>
    <div class="site-header">Site navigation and language selector</div>
    <main class="ll-content-lesson-page ll-content-lesson-page--article">
      <header class="ll-content-lesson-hero">
        <div class="ll-content-lesson-hero__top">
          <a class="ll-content-lesson-back" href="#vocab"><span class="ll-content-lesson-back__label">Vocab</span></a>
          <span class="ll-content-lesson-pill">MAKALE DERSİ</span>
        </div>
        <div class="ll-content-lesson-hero__content">
          <h1 class="ll-content-lesson-title">Familial terms</h1>
          <p class="ll-content-lesson-summary">-çığım · Vocab: immediate family · Vocab: extended family</p>
          <div class="ll-content-lesson-progress">
            <a class="ll-content-lesson-progress-login" href="#login">Ders ilerlemesini kaydetmek için giriş yap</a>
            <span class="ll-content-lesson-progress-status"></span>
          </div>
        </div>
      </header>
      ${prerequisiteBlock(count)}
      <article class="ll-content-lesson-article"><div class="ll-content-lesson-article__content"><div class="tt-lesson">
        <h2>Everyone is a part of the family</h2>
        <p>One thing I love about Turkey is the culture of including everyone into part of the family.</p>
      </div></div></article>
      <section class="ll-content-lesson-prerequisites ll-content-lesson-dependents">
        <h2 class="ll-content-lessons-section__title">Continue learning</h2>
        <ul class="ll-content-lesson-prerequisites__list"><li class="ll-content-lesson-prerequisites__item"><a href="#next">Next lesson (examples remain)</a></li></ul>
      </section>
    </main></body></html>`);
}

for (const width of [320, 390, 430]) {
  for (const count of [0, 1, 8]) {
    test(`mobile article opens with visible lesson text at ${width}px and ${count} prerequisites`, async ({ page }) => {
      await page.setViewportSize({ width, height: 700 });
      await mountArticle(page, count);
      const paragraph = page.locator('.ll-content-lesson-article__content p');
      await expect(paragraph).toBeInViewport({ ratio: 1 });
      const position = await paragraph.boundingBox();
      expect(position.y).toBeLessThan(500);
      await expect(page.locator('.ll-content-lesson-article__content')).toHaveCSS('font-size', '16px');
      await expect(page.locator('.ll-content-lesson-pill')).toBeHidden();
      for (const selector of ['.ll-content-lesson-back', '.ll-content-lesson-progress-login']) {
        expect((await page.locator(selector).boundingBox()).height).toBeGreaterThanOrEqual(44);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      if (count === 1) {
        const link = page.locator('.ll-content-lesson-prerequisites--compact a');
        await expect(link).toBeVisible();
        await expect(link).toHaveAccessibleName(`${fullTitle} — Completed`);
        await expect(link.locator('.ll-content-lesson-prerequisites__title-full')).toBeHidden();
        await expect(link.locator('.ll-content-lesson-prerequisites__title-short')).toHaveText('Noun possession');
        expect((await link.boundingBox()).height).toBeGreaterThanOrEqual(44);
      } else if (count > 1) {
        await expect(page.locator('details')).not.toHaveAttribute('open', '');
        await expect(page.locator('details a').first()).toBeHidden();
      }
    });
  }
}

test('multiple prerequisites open with the keyboard and preserve full link names and completion state', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 700 });
  await mountArticle(page, 8);
  const summary = page.locator('summary');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('details')).toHaveAttribute('open', '');
  await expect(page.locator('details a')).toHaveCount(8);
  await expect(page.locator('details a').first()).toBeVisible();
  await expect(page.locator('details a').first()).toHaveAccessibleName(`${fullTitle} — Completed`);
  await expect(page.locator('details li').first()).toHaveClass(/is-completed/);
  await expect(page.locator('details a').nth(1)).toHaveAttribute('href', '#prerequisite-1');
  await page.keyboard.press('Space');
  await expect(page.locator('details')).not.toHaveAttribute('open', '');
});

test('desktop retains full prerequisite titles and the footer cards keep their original layout', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await mountArticle(page, 1);
  const full = page.locator('.ll-content-lesson-prerequisites__title-full');
  await expect(full).toBeVisible();
  await expect(full).toHaveText(fullTitle);
  await expect(page.locator('.ll-content-lesson-prerequisites__title-short')).toBeHidden();
  await expect(page.locator('.ll-content-lesson-pill')).toBeVisible();
  await expect(page.locator('.ll-content-lesson-dependents')).toHaveCSS('border-top-width', '1px');
  await expect(page.locator('.ll-content-lesson-dependents')).toHaveCSS('border-radius', '24px');
  await expect(page.locator('.ll-content-lesson-dependents a')).toHaveText('Next lesson (examples remain)');
  expect(await page.locator('.tt-lesson > h2').evaluate((heading) => parseFloat(getComputedStyle(heading).marginTop))).toBe(0);
});
