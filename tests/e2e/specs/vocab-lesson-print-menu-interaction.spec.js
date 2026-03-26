const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);
const vocabLessonPageScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-page.js'),
  'utf8'
);

function buildPrintMenuMarkup() {
  return `<!DOCTYPE html>
<html>
<head>
  <style>${vocabLessonCssSource}</style>
</head>
<body>
  <main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
    <div class="ll-vocab-lesson-settings ll-vocab-lesson-print-settings">
      <button
        type="button"
        class="ll-tools-settings-button ll-vocab-lesson-print-trigger"
        aria-haspopup="true"
        aria-expanded="false">
        <span class="ll-vocab-lesson-print-label">Print</span>
      </button>
      <form
        class="ll-tools-settings-panel ll-vocab-lesson-print-panel"
        method="get"
        action="/print"
        target="_blank"
        role="dialog"
        aria-label="Print options"
        aria-hidden="true">
        <div class="ll-vocab-lesson-print-panel__title">Print options</div>
        <label class="ll-vocab-lesson-print-panel__option">
          <input type="checkbox" name="ll_print_text" value="1" />
          <span>Text</span>
        </label>
        <button type="submit" class="ll-study-btn tiny ll-vocab-lesson-print-button ll-vocab-lesson-print-panel__submit">
          <span class="ll-vocab-lesson-print-label">Open print view</span>
        </button>
      </form>
    </div>
  </main>
</body>
</html>`;
}

test('print menu opens without the word-grid script and closes on outside click or Escape', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildPrintMenuMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsVocabLessonData = {};
  });
  await page.addScriptTag({ content: vocabLessonPageScriptSource });

  const trigger = page.locator('.ll-vocab-lesson-print-trigger');
  const panel = page.locator('.ll-vocab-lesson-print-panel');

  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');

  await trigger.click();
  await expect(panel).toHaveAttribute('aria-hidden', 'false');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');

  await page.mouse.click(5, 5);
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');

  await trigger.click();
  await expect(panel).toHaveAttribute('aria-hidden', 'false');

  await page.keyboard.press('Escape');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
});
