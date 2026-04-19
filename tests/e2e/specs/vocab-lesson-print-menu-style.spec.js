const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);

function buildPrintMenuMarkup() {
  return `<!DOCTYPE html>
<html>
<head>
  <style>${vocabLessonCssSource}</style>
</head>
<body>
  <main class="ll-vocab-lesson-page">
    <div class="ll-vocab-lesson-settings ll-vocab-lesson-print-settings">
      <button
        type="button"
        class="ll-tools-settings-button ll-vocab-lesson-print-trigger">
        <span class="ll-vocab-lesson-print-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
            <path d="M7 8V4h10v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="ll-vocab-lesson-print-label">Print</span>
      </button>
      <form
        class="ll-tools-settings-panel ll-vocab-lesson-print-panel"
        aria-hidden="false">
        <div class="ll-vocab-lesson-print-panel__title">Print options</div>
        <label class="ll-vocab-lesson-print-panel__option">
          <input type="checkbox" name="ll_print_text" value="1" />
          <span>Text</span>
        </label>
        <button type="submit" class="ll-study-btn tiny ll-vocab-lesson-print-button ll-vocab-lesson-print-panel__submit">
          <span class="ll-vocab-lesson-print-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
              <path d="M7 8V4h10v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          <span class="ll-vocab-lesson-print-label">Open print view</span>
        </button>
      </form>
    </div>
  </main>
</body>
</html>`;
}

async function readStyle(locator) {
  return locator.evaluate((node) => {
    const computed = window.getComputedStyle(node);
    return {
      backgroundColor: computed.backgroundColor,
      borderColor: computed.borderColor,
      color: computed.color
    };
  });
}

test('print menu keeps explicit print palette for trigger and submit button', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 800 });
  await page.setContent(buildPrintMenuMarkup());

  const trigger = page.locator('.ll-vocab-lesson-print-trigger');
  const submit = page.locator('.ll-vocab-lesson-print-panel__submit');

  const triggerDefault = await readStyle(trigger);
  expect(triggerDefault.backgroundColor).toBe('rgb(238, 244, 255)');
  expect(triggerDefault.borderColor).toBe('rgb(199, 215, 239)');
  expect(triggerDefault.color).toBe('rgb(29, 77, 153)');

  await trigger.hover();
  const triggerHover = await readStyle(trigger);
  expect(triggerHover.backgroundColor).toBe('rgb(219, 234, 254)');
  expect(triggerHover.borderColor).toBe('rgb(143, 179, 232)');
  expect(triggerHover.color).toBe('rgb(20, 60, 125)');

  const submitDefault = await readStyle(submit);
  expect(submitDefault.backgroundColor).toBe('rgb(238, 244, 255)');
  expect(submitDefault.borderColor).toBe('rgb(199, 215, 239)');
  expect(submitDefault.color).toBe('rgb(29, 77, 153)');
});
