const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);

const vocabLessonPrintJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-print-page.js'),
  'utf8'
);

const ONE_PIXEL_GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

function buildCardMarkup(id, wordText, translationText) {
  return (
    '<article class="ll-vocab-lesson-print-card" ' +
      'data-word-id="' + id + '" ' +
      'data-label="' + wordText + '" ' +
      'data-word-text="' + wordText + '" ' +
      'data-translation-text="' + translationText + '">' +
      '<div class="ll-vocab-lesson-print-card__media">' +
        '<img class="ll-vocab-lesson-print-image" src="' + ONE_PIXEL_GIF + '" alt="" data-ll-vocab-lesson-print-image="1">' +
      '</div>' +
      '<div class="ll-vocab-lesson-print-card__captions" hidden>' +
        '<div class="ll-vocab-lesson-print-card__text" dir="auto" hidden>' + wordText + '</div>' +
        '<div class="ll-vocab-lesson-print-card__translation" dir="auto" hidden>' + translationText + '</div>' +
      '</div>' +
    '</article>'
  );
}

function buildInteractivePrintPage() {
  const cards = [
    buildCardMarkup(1, 'Alpha', 'One'),
    buildCardMarkup(2, 'Bravo', 'Two'),
    buildCardMarkup(3, 'Charlie', 'Three')
  ].join('');

  return `<!DOCTYPE html>
<html>
<head>
  <style>${vocabLessonCssSource}</style>
</head>
<body class="ll-vocab-lesson-print-body">
  <main
    class="ll-vocab-lesson-print-page"
    data-ll-vocab-lesson-print-root
    data-items-per-page="12"
    data-show-text="0"
    data-show-translations="0"
    data-title="Print Lesson">
    <section class="ll-vocab-lesson-print-toolbar" data-ll-vocab-lesson-print-toolbar hidden>
      <div class="ll-vocab-lesson-print-toolbar__group">
        <label class="ll-vocab-lesson-print-toggle">
          <input type="checkbox" data-ll-vocab-lesson-print-toggle="text">
          <span>Text</span>
        </label>
        <label class="ll-vocab-lesson-print-toggle">
          <input type="checkbox" data-ll-vocab-lesson-print-toggle="translations">
          <span>Translations</span>
        </label>
      </div>
      <div class="ll-vocab-lesson-print-toolbar__group ll-vocab-lesson-print-toolbar__group--actions">
        <button type="button" class="ll-vocab-lesson-print-toolbar__button ll-vocab-lesson-print-toolbar__button--secondary" data-ll-vocab-lesson-print-restore-all hidden>Restore all</button>
        <button type="button" class="ll-vocab-lesson-print-toolbar__button ll-vocab-lesson-print-toolbar__button--primary" data-ll-vocab-lesson-print-trigger>Print</button>
      </div>
    </section>
    <section class="ll-vocab-lesson-print-removed" data-ll-vocab-lesson-print-removed hidden>
      <div class="ll-vocab-lesson-print-removed__title">Removed</div>
      <div class="ll-vocab-lesson-print-removed__list" data-ll-vocab-lesson-print-removed-list></div>
    </section>
    <div class="ll-vocab-lesson-print-canvas" data-ll-vocab-lesson-print-canvas>
      <section class="ll-vocab-lesson-print-sheet">
        <h1 class="ll-vocab-lesson-print-sheet__title">Print Lesson</h1>
        <div class="ll-vocab-lesson-print-grid" data-ll-vocab-lesson-print-grid data-page-index="1">
          ${cards}
        </div>
      </section>
    </div>
  </main>
  <script>
    window.llToolsVocabLessonPrintData = {
      i18n: {
        moveEarlier: 'Move earlier',
        moveLater: 'Move later',
        removeWord: 'Remove from print',
        restoreWord: 'Add back to print',
        restoreAll: 'Restore all',
        removedWords: 'Removed',
        allRemovedTitle: 'All words removed.',
        allRemovedMessage: 'Restore one or more words to print this lesson.'
      }
    };
  </script>
  <script>${vocabLessonPrintJsSource}</script>
</body>
</html>`;
}

test('print controls toggle captions and support remove/restore/reorder', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 960 });
  await page.setContent(buildInteractivePrintPage());

  const toolbar = page.locator('[data-ll-vocab-lesson-print-toolbar]');
  await expect(toolbar).toBeVisible();
  await expect(page.locator('.ll-vocab-lesson-print-card')).toHaveCount(3);
  await expect(page.locator('.ll-vocab-lesson-print-card__text')).toHaveCount(0);

  await page.locator('[data-ll-vocab-lesson-print-toggle="text"]').check();
  await expect(page.locator('.ll-vocab-lesson-print-card__text')).toHaveCount(3);
  await expect(page.locator('.ll-vocab-lesson-print-card__text').first()).toHaveText('Alpha');

  await page.locator('[data-ll-vocab-lesson-print-toggle="translations"]').check();
  await expect(page.locator('.ll-vocab-lesson-print-card__translation')).toHaveCount(3);
  await expect(page.locator('.ll-vocab-lesson-print-card__translation').first()).toHaveText('One');

  await page.locator('.ll-vocab-lesson-print-card__action--remove').first().click();
  await expect(page.locator('.ll-vocab-lesson-print-card')).toHaveCount(2);
  await expect(page.locator('[data-ll-vocab-lesson-print-removed]')).toBeVisible();
  await expect(page.locator('.ll-vocab-lesson-print-removed__item')).toHaveText(['Alpha']);

  await page.locator('.ll-vocab-lesson-print-removed__item').click();
  await expect(page.locator('.ll-vocab-lesson-print-card')).toHaveCount(3);

  await page.locator('.ll-vocab-lesson-print-card__action--move-later').first().click();
  const wordOrder = await page.locator('.ll-vocab-lesson-print-card').evaluateAll((nodes) => {
    return nodes.map((node) => node.getAttribute('data-word-id'));
  });

  expect(wordOrder).toEqual(['2', '1', '3']);
});
