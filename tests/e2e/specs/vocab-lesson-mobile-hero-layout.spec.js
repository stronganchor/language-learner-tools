const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);

function buildLessonHeroMarkup() {
  return `
    <main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
      <header class="ll-vocab-lesson-hero">
        <div class="ll-vocab-lesson-top-row">
          <a class="ll-vocab-lesson-back" href="#">
            <span class="ll-vocab-lesson-back__label">Genc - Palu</span>
          </a>
          <div class="ll-vocab-lesson-star-controls">
            <button type="button" class="ll-vocab-lesson-star-toggle ll-study-btn tiny ghost ll-group-star">
              <span>Star all</span>
            </button>
            <div class="ll-vocab-lesson-transcribe">
              <div class="ll-vocab-lesson-transcribe-actions">
                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn">Auto captions</button>
                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn">Replace</button>
                <button type="button" class="ll-study-btn tiny ll-vocab-lesson-transcribe-btn">Clear</button>
                <button type="button" class="ll-study-btn tiny ghost ll-vocab-lesson-transcribe-btn">Cancel</button>
              </div>
            </div>
            <a class="ll-study-btn tiny ll-vocab-lesson-print-button" href="#">Print images</a>
            <div class="ll-vocab-lesson-bulk">
              <button type="button" class="ll-vocab-lesson-bulk-button ll-tools-settings-button">Bulk edit</button>
            </div>
            <div class="ll-vocab-lesson-settings">
              <button type="button" class="ll-study-btn tiny ghost">Options</button>
            </div>
          </div>
        </div>
        <div class="ll-vocab-lesson-title-row">
          <div class="ll-vocab-lesson-title-wrap">
            <h1 class="ll-vocab-lesson-title">Ev Esyasi: Mutfak 1</h1>
          </div>
          <div class="ll-vocab-lesson-actions">
            <div class="ll-vocab-lesson-modes">
              <button type="button" class="ll-vocab-lesson-mode-button"><span class="ll-vocab-lesson-mode-label">Learn</span></button>
              <button type="button" class="ll-vocab-lesson-mode-button"><span class="ll-vocab-lesson-mode-label">Practice</span></button>
              <button type="button" class="ll-vocab-lesson-mode-button"><span class="ll-vocab-lesson-mode-label">Listen</span></button>
              <button type="button" class="ll-vocab-lesson-mode-button"><span class="ll-vocab-lesson-mode-label">Gender</span></button>
              <button type="button" class="ll-vocab-lesson-mode-button"><span class="ll-vocab-lesson-mode-label">Self check</span></button>
            </div>
          </div>
        </div>
      </header>
      <div class="ll-vocab-lesson-content">
        <div style="height: 200px; background: #d9e1ea;"></div>
      </div>
    </main>
  `;
}

test('mobile vocab lesson hero keeps mode buttons close to the title', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildLessonHeroMarkup());
  await page.addStyleTag({ content: vocabLessonCssSource });

  const metrics = await page.evaluate(() => {
    const title = document.querySelector('.ll-vocab-lesson-title');
    const actions = document.querySelector('.ll-vocab-lesson-actions');
    const titleRow = document.querySelector('.ll-vocab-lesson-title-row');
    const titleWrap = document.querySelector('.ll-vocab-lesson-title-wrap');
    if (!title || !actions || !titleRow || !titleWrap) {
      return null;
    }

    const titleRect = title.getBoundingClientRect();
    const actionsRect = actions.getBoundingClientRect();
    const rowRect = titleRow.getBoundingClientRect();
    const wrapRect = titleWrap.getBoundingClientRect();

    return {
      gap: Math.round(actionsRect.top - titleRect.bottom),
      rowHeight: Math.round(rowRect.height),
      wrapHeight: Math.round(wrapRect.height)
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.gap).toBeLessThanOrEqual(24);
  expect(metrics.rowHeight).toBeLessThan(180);
  expect(metrics.wrapHeight).toBeLessThan(80);
});
