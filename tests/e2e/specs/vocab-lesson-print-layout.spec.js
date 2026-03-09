const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);

const ONE_PIXEL_GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

function buildPrintMarkup() {
  const cards = Array.from({ length: 12 }, (_, index) => (
    '<article class="ll-vocab-lesson-print-card" data-word-id="' + (index + 1) + '">' +
      '<img class="ll-vocab-lesson-print-image" src="' + ONE_PIXEL_GIF + '" alt="">' +
    '</article>'
  )).join('');

  return (
    '<main class="ll-vocab-lesson-print-page">' +
      '<section class="ll-vocab-lesson-print-sheet">' +
        '<h1 class="ll-vocab-lesson-print-sheet__title">Print Category</h1>' +
        '<div class="ll-vocab-lesson-print-grid" data-ll-vocab-lesson-print-grid>' +
          cards +
        '</div>' +
      '</section>' +
    '</main>'
  );
}

test('print sheet keeps a fixed three-column layout on narrow screens', async ({ page }) => {
  await page.setViewportSize({ width: 760, height: 1000 });
  await page.goto('about:blank');
  await page.setContent(buildPrintMarkup());
  await page.addStyleTag({ content: vocabLessonCssSource });

  const grid = page.locator('[data-ll-vocab-lesson-print-grid]');
  await expect(grid).toHaveCount(1);

  const columnCount = await grid.evaluate((node) => {
    const computed = window.getComputedStyle(node).gridTemplateColumns || '';
    return computed.split(' ').filter(Boolean).length;
  });
  const gapValue = await grid.evaluate((node) => window.getComputedStyle(node).gap || '');

  expect(columnCount).toBe(3);
  expect(gapValue).toBe('0px');
  await expect(page.locator('.ll-vocab-lesson-print-sheet__title')).toHaveText('Print Category');
  await expect(page.locator('.ll-vocab-lesson-print-card')).toHaveCount(12);
});
