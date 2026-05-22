const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const contentLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/content-lesson-pages.css'),
  'utf8'
);

const textDocumentJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/text-document.js'),
  'utf8'
);

function buildTextDocumentPrintPage() {
  return `<!DOCTYPE html>
<html>
<head>
  <style>${contentLessonCssSource}</style>
</head>
<body>
  <header id="masthead" role="banner">Theme header</header>
  <main class="ll-content-lesson-page ll-content-lesson-page--corpus-text" data-ll-content-lesson>
    <header class="ll-content-lesson-hero">
      <div class="ll-content-lesson-hero__top">Navigation</div>
      <div class="ll-content-lesson-hero__content">
        <h1 class="ll-content-lesson-title">Historical Text</h1>
        <p class="ll-content-lesson-summary">Intro summary.</p>
        <p class="ll-content-lesson-print-source-url">
          <span>URL:</span>
          <a href="https://example.com/lesson/historical-text/">https://example.com/lesson/historical-text/</a>
        </p>
      </div>
    </header>
    <section class="ll-text-document" data-ll-text-document data-view="reader">
      <div class="ll-text-document__head">
        <button type="button" class="ll-text-document__print-button" data-ll-text-document-print>Print</button>
      </div>
      <section class="ll-text-reader">
        <div class="ll-text-reader__head"><span>Text</span><span>Translation</span></div>
        <div class="ll-text-reader__row">
          <div class="ll-text-reader__cell ll-text-reader__cell--source">Original text.</div>
          <div class="ll-text-reader__cell ll-text-reader__cell--translation">Translated text.</div>
        </div>
      </section>
      <section class="ll-text-document__print-sources" aria-label="Print sources">
        <h2>Sources</h2>
        <section class="ll-text-sources">
          <article class="ll-text-sources__item">
            <h3>Scholarly source</h3>
            <p class="ll-text-sources__citation">A compact citation.</p>
          </article>
        </section>
      </section>
    </section>
  </main>
  <footer id="colophon" role="contentinfo">Theme footer</footer>
</body>
</html>`;
}

test('text document print button prints a cloned lesson without theme chrome', async ({ page }) => {
  await page.setContent(buildTextDocumentPrintPage());
  await page.evaluate(() => {
    window.__llDidPrint = false;
    window.print = () => {
      window.__llDidPrint = true;
    };
  });
  await page.addScriptTag({ content: textDocumentJsSource });

  await page.locator('[data-ll-text-document-print]').click();

  await expect.poll(async () => {
    return page.evaluate(() => window.__llDidPrint);
  }).toBe(true);
  await expect(page.locator('[data-ll-text-document-print-root]')).toHaveCount(1);
  await expect(page.locator('[data-ll-text-document-print-root] .ll-content-lesson-title')).toHaveText('Historical Text');
  await expect(page.locator('body')).toHaveClass(/ll-text-document-print-active/);

  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('body > header')).toHaveCSS('display', 'none');
  await expect(page.locator('body > footer')).toHaveCSS('display', 'none');
  await expect(page.locator('[data-ll-text-document-print-root]')).not.toHaveCSS('display', 'none');
  await expect(page.locator('[data-ll-text-document-print-root] .ll-content-lesson-print-source-url')).not.toHaveCSS('display', 'none');

  await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  await expect(page.locator('[data-ll-text-document-print-root]')).toHaveCount(0);
  await expect(page.locator('body')).not.toHaveClass(/ll-text-document-print-active/);
});
