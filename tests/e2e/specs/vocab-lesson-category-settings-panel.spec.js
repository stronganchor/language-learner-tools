const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');
const flashcardBaseCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);
const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);
const vocabLessonJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-page.js'),
  'utf8'
);

const hostileThemeCss = `
  .ll-vocab-lesson-page button,
  .ll-vocab-lesson-page input,
  .ll-vocab-lesson-page select {
    min-width: 0 !important;
    border-radius: 0 !important;
    box-shadow: inset 0 0 0 2px rgba(15, 64, 99, 0.18) !important;
    letter-spacing: 0.08em !important;
    text-transform: uppercase !important;
  }
`;

function buildLineupItems(count = 3) {
  return Array.from({ length: count }, (_, index) => {
    const wordId = 41 + index;
    const label = `Word ${index + 1}`;
    return `
      <li class="ll-vocab-lesson-category-lineup-item" data-ll-category-lineup-item data-word-id="${wordId}">
        <span class="ll-vocab-lesson-category-lineup-title" dir="auto">${label}</span>
        <span class="ll-vocab-lesson-category-lineup-actions">
          <button type="button" class="ll-vocab-lesson-category-lineup-move" data-ll-category-lineup-move="up">Up</button>
          <button type="button" class="ll-vocab-lesson-category-lineup-move" data-ll-category-lineup-move="down">Down</button>
        </span>
      </li>
    `;
  }).join('');
}

function buildCategorySettingsMarkup(options = {}) {
  const lineupCount = Number.isFinite(options.lineupCount) ? Math.max(1, options.lineupCount) : 3;
  const includeOverlapShell = options.includeOverlapShell === true;
  const lineupItems = buildLineupItems(lineupCount);
  const lineupIds = Array.from({ length: lineupCount }, (_, index) => String(41 + index)).join(',');
  const overlapShell = includeOverlapShell
    ? `
      <section class="ll-vocab-lesson-content">
        <div id="word-grid" data-test-overlap-grid>
          <div class="word-grid" data-ll-word-grid>
            <div class="ll-word-grid-shell-card" data-test-overlap-card>Overlap shell</div>
          </div>
        </div>
      </section>
    `
    : '';

  return `
    <main class="ll-vocab-lesson-page" data-ll-vocab-lesson style="padding: 16px;">
      <header class="ll-vocab-lesson-hero">
        <div class="ll-vocab-lesson-top-row">
          <div class="ll-vocab-lesson-star-controls">
            <div class="ll-vocab-lesson-category-settings ll-tools-settings-control" data-ll-vocab-lesson-category-settings>
              <button type="button" class="ll-vocab-lesson-category-settings-trigger ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="Category settings">
                <span class="ll-vocab-lesson-category-settings-trigger-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                    <path d="M12 3.5 4 7.5v9l8 4 8-4v-9l-8-4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                    <path d="M12 8.5v7M8.8 10.1l3.2 1.8 3.2-1.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </span>
                <span class="ll-vocab-lesson-category-settings-trigger-label">Category</span>
              </button>
              <form class="ll-tools-settings-panel ll-vocab-lesson-category-settings-panel ll-vocab-lesson-tool-modal" role="dialog" aria-modal="true" aria-labelledby="ll-test-category-settings-title" aria-hidden="true">
                <div class="ll-vocab-lesson-category-settings-panel__title-row">
                  <div class="ll-vocab-lesson-category-settings-panel__title" id="ll-test-category-settings-title">Category settings</div>
                  <button type="button" class="ll-vocab-lesson-tool-modal__close" data-ll-category-settings-close aria-label="Close category settings">
                    <span aria-hidden="true">x</span>
                  </button>
                  <div class="ll-vocab-lesson-category-settings-summary">
                    <span class="ll-vocab-lesson-category-settings-summary-pill">Text to text</span>
                    <span class="ll-vocab-lesson-category-settings-summary-pill">Text visible</span>
                    <span class="ll-vocab-lesson-category-settings-summary-pill">1 recording type</span>
                  </div>
                </div>

                <div class="ll-vocab-lesson-category-settings-section">
                  <div class="ll-vocab-lesson-category-settings-section__heading">Quiz</div>
                  <label class="ll-vocab-lesson-category-settings-field">
                    <span class="ll-vocab-lesson-category-settings-field__label">Prompt</span>
                    <select name="ll_vocab_lesson_quiz_prompt_type" class="ll-vocab-lesson-category-settings-select">
                      <option value="text_title" selected>Word text</option>
                      <option value="text_translation">Translation</option>
                    </select>
                  </label>
                  <label class="ll-vocab-lesson-category-settings-field">
                    <span class="ll-vocab-lesson-category-settings-field__label">Answers</span>
                    <select name="ll_vocab_lesson_quiz_option_type" class="ll-vocab-lesson-category-settings-select">
                      <option value="text_title" selected>Word text</option>
                      <option value="image">Images</option>
                    </select>
                  </label>
                  <label class="ll-vocab-lesson-category-settings-field">
                    <span class="ll-vocab-lesson-category-settings-field__label">Lesson text</span>
                    <select name="ll_vocab_lesson_grid_text_visibility" class="ll-vocab-lesson-category-settings-select">
                      <option value="default" selected>Default</option>
                      <option value="hide">Hide</option>
                    </select>
                  </label>
                </div>

                <div class="ll-vocab-lesson-category-settings-section">
                  <div class="ll-vocab-lesson-category-settings-section__heading">Games</div>
                  <div class="ll-vocab-lesson-category-settings-checkboxes">
                    <label class="ll-vocab-lesson-category-settings-check">
                      <input type="checkbox" name="ll_vocab_lesson_category_enabled_games[]" value="line-up" checked />
                      <span>Line-Up</span>
                    </label>
                    <label class="ll-vocab-lesson-category-settings-check">
                      <input type="checkbox" name="ll_vocab_lesson_category_enabled_games[]" value="unscramble" />
                      <span>Unscramble</span>
                    </label>
                  </div>
                </div>

                <div class="ll-vocab-lesson-category-settings-section">
                  <div class="ll-vocab-lesson-category-settings-section__heading">Recording</div>
                  <p class="ll-vocab-lesson-category-settings-help">Choose which recording types this category expects.</p>
                  <div class="ll-vocab-lesson-category-settings-checkboxes">
                    <label class="ll-vocab-lesson-category-settings-check">
                      <input type="checkbox" name="ll_vocab_lesson_desired_recording_types[]" value="isolation" checked />
                      <span>Isolation</span>
                    </label>
                    <label class="ll-vocab-lesson-category-settings-check">
                      <input type="checkbox" name="ll_vocab_lesson_desired_recording_types[]" value="question" />
                      <span>Question</span>
                    </label>
                  </div>
                </div>

                <div class="ll-vocab-lesson-category-settings-section ll-vocab-lesson-category-settings-section--lineup" data-ll-category-lineup-ordering>
                  <div class="ll-vocab-lesson-category-settings-section__heading">Line-Up</div>
                  <input type="hidden" name="ll_vocab_lesson_category_lineup_submitted" value="1" />
                  <label class="ll-vocab-lesson-category-settings-field">
                    <span class="ll-vocab-lesson-category-settings-field__label">Direction</span>
                    <select name="ll_vocab_lesson_category_lineup_direction" class="ll-vocab-lesson-category-settings-select" data-ll-category-lineup-direction>
                      <option value="auto" selected>Auto</option>
                      <option value="ltr">Left to right</option>
                      <option value="rtl">Right to left</option>
                    </select>
                  </label>
                  <ol class="ll-vocab-lesson-category-lineup-list" data-ll-category-lineup-list>
                    ${lineupItems}
                  </ol>
                  <input type="hidden" name="ll_vocab_lesson_category_lineup_word_ids" value="${lineupIds}" data-ll-category-lineup-order-input />
                  <p class="ll-vocab-lesson-category-settings-help">Move words up or down to set the Line-Up teaching order for this category.</p>
                </div>

                <div class="ll-vocab-lesson-category-settings-actions" data-ll-category-settings-actions hidden>
                  <span class="ll-vocab-lesson-category-settings-status" data-ll-category-settings-status data-state="idle" role="status" aria-live="polite" hidden>
                    <span class="ll-vocab-lesson-category-settings-status-icon" aria-hidden="true"></span>
                    <span class="ll-vocab-lesson-category-settings-status-message" data-ll-category-settings-status-message hidden></span>
                  </span>
                </div>
              </form>
            </div>
          </div>
        </div>
      </header>
      ${overlapShell}
    </main>
  `;
}

async function mountCategorySettingsHarness(page, viewport, options = {}) {
  await page.setViewportSize(viewport);
  await page.goto('about:blank');
  await page.setContent(buildCategorySettingsMarkup(options));
  await page.addStyleTag({ content: flashcardBaseCssSource });
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.addStyleTag({ content: hostileThemeCss });
  if (options.includeOverlapShell) {
    await page.addStyleTag({
      content: `
        [data-test-overlap-grid] {
          position: relative;
          z-index: 40;
          margin-top: -148px;
          min-height: 320px;
          padding: 28px 18px;
          border: 1px solid rgba(148, 163, 184, 0.5);
          border-radius: 24px;
          background: linear-gradient(180deg, rgba(241, 245, 249, 0.96), rgba(226, 232, 240, 0.96));
          box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
        }

        [data-test-overlap-card] {
          display: grid;
          place-items: center;
          min-height: 180px;
          border-radius: 18px;
          border: 1px dashed rgba(71, 85, 105, 0.4);
          background: rgba(255, 255, 255, 0.82);
          color: #334155;
          font-weight: 700;
        }
      `
    });
  }
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({
    content: `
      window.jQuery = window.$ = jQuery;
      window.llToolsVocabLessonData = {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        grid: {
          action: 'll_tools_get_vocab_lesson_grid',
          i18n: {}
        },
        categorySettings: {
          action: 'll_tools_save_vocab_lesson_category_settings',
          i18n: {
            saving: 'Saving changes...',
            saved: 'Changes saved.',
            error: 'Unable to save category settings right now.'
          }
        }
      };

      window.__llCategorySettingsSaves = [];
      jQuery.ajax = function (options) {
        const entries = options && options.data && typeof options.data.entries === 'function'
          ? Array.from(options.data.entries()).reduce((acc, item) => {
              const key = String(item[0]);
              const value = String(item[1]);
              if (!acc[key]) {
                acc[key] = [];
              }
              acc[key].push(value);
              return acc;
            }, {})
          : {};
        window.__llCategorySettingsSaves.push({
          url: String(options && options.url ? options.url : ''),
          entries
        });
        const deferred = jQuery.Deferred();
        window.setTimeout(function () {
          deferred.resolve({
            success: true,
            data: {
              message: 'Changes saved.'
            }
          });
        }, 25);
        return deferred.promise();
      };
    `
  });
  await page.addScriptTag({ content: vocabLessonJsSource });
}

test('lesson category settings panel opens, reorders Line-Up, and closes cleanly', async ({ page }) => {
  await mountCategorySettingsHarness(page, { width: 1366, height: 900 });

  const trigger = page.locator('.ll-vocab-lesson-category-settings-trigger');
  const panel = page.locator('.ll-vocab-lesson-category-settings-panel');
  const orderInput = page.locator('[data-ll-category-lineup-order-input]');

  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await expect(panel).toHaveAttribute('aria-hidden', 'false');
  await expect(orderInput).toHaveValue('41,42,43');

  await page.locator('[data-word-id="42"] [data-ll-category-lineup-move="up"]').click();
  await expect(orderInput).toHaveValue('42,41,43');

  await page.locator('[data-word-id="41"] [data-ll-category-lineup-move="down"]').click();
  await expect(orderInput).toHaveValue('42,43,41');

  await page.keyboard.press('Escape');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');

  await trigger.click();
  await page.locator('[data-ll-category-settings-close]').click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
});

test('lesson category settings panel clamps to the mobile viewport and keeps autosave status reachable', async ({ page }) => {
  await mountCategorySettingsHarness(page, { width: 390, height: 844 });

  const trigger = page.locator('.ll-vocab-lesson-category-settings-trigger');
  const panel = page.locator('.ll-vocab-lesson-category-settings-panel');

  await trigger.click();
  await expect(panel).toHaveAttribute('aria-hidden', 'false');
  await page.locator('select[name="ll_vocab_lesson_grid_text_visibility"]').selectOption('hide');
  await expect(page.locator('[data-ll-category-settings-status]')).toHaveAttribute('data-state', 'saved');

  const metrics = await page.evaluate(() => {
    const panelEl = document.querySelector('.ll-vocab-lesson-category-settings-panel');
    const statusEl = document.querySelector('[data-ll-category-settings-status]');
    const directionSelect = document.querySelector('[data-ll-category-lineup-direction]');
    const lineupActions = Array.from(document.querySelectorAll('.ll-vocab-lesson-category-lineup-move')).map((button) => {
      const rect = button.getBoundingClientRect();
      return {
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        width: Math.round(rect.width)
      };
    });

    if (!panelEl || !statusEl || !directionSelect) {
      return null;
    }

    const panelRect = panelEl.getBoundingClientRect();
    const statusRect = statusEl.getBoundingClientRect();
    const selectRect = directionSelect.getBoundingClientRect();

    return {
      viewportWidth: window.innerWidth,
      panelLeft: Math.round(panelRect.left),
      panelRight: Math.round(panelRect.right),
      bodyWidth: document.documentElement.scrollWidth,
      selectWidth: Math.round(selectRect.width),
      statusBottom: Math.round(statusRect.bottom),
      lineupActions
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.bodyWidth).toBeLessThanOrEqual(metrics.viewportWidth + 2);
  expect(metrics.panelLeft).toBeGreaterThanOrEqual(0);
  expect(metrics.panelRight).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.selectWidth).toBeGreaterThan(180);
  expect(metrics.statusBottom).toBeLessThanOrEqual(844);
  metrics.lineupActions.forEach((actionMetric) => {
    expect(actionMetric.left).toBeGreaterThanOrEqual(0);
    expect(actionMetric.right).toBeLessThanOrEqual(metrics.viewportWidth + 1);
    expect(actionMetric.width).toBeGreaterThanOrEqual(40);
  });

  await expect(page.locator('[data-ll-category-settings-status]')).toBeInViewport();

  await page.locator('body').click({ position: { x: 8, y: 8 } });
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
});

test('lesson category settings keeps autosave feedback above overlapping lesson content', async ({ page }) => {
  await mountCategorySettingsHarness(page, { width: 1366, height: 900 }, {
    lineupCount: 14,
    includeOverlapShell: true
  });

  const trigger = page.locator('.ll-vocab-lesson-category-settings-trigger');
  const panel = page.locator('.ll-vocab-lesson-category-settings-panel');
  const status = page.locator('[data-ll-category-settings-status]');

  await trigger.click();
  await expect(panel).toHaveAttribute('aria-hidden', 'false');
  await page.locator('[data-word-id="42"] [data-ll-category-lineup-move="up"]').click();
  await expect(status).toHaveAttribute('data-state', 'saved');
  await expect(status).toBeInViewport();

  const metrics = await page.evaluate(() => {
    const panelEl = document.querySelector('.ll-vocab-lesson-category-settings-panel');
    const statusEl = document.querySelector('[data-ll-category-settings-status]');
    const actionEl = document.querySelector('.ll-vocab-lesson-category-settings-actions');

    if (!panelEl || !statusEl || !actionEl) {
      return null;
    }

    const panelRect = panelEl.getBoundingClientRect();
    const statusRect = statusEl.getBoundingClientRect();
    const footerRect = actionEl.getBoundingClientRect();
    const topAtButtonCenter = document.elementFromPoint(
      statusRect.left + (statusRect.width / 2),
      statusRect.top + (statusRect.height / 2)
    );

    return {
      panelBottom: Math.round(panelRect.bottom),
      footerBottom: Math.round(footerRect.bottom),
      statusBottom: Math.round(statusRect.bottom),
      statusTop: Math.round(statusRect.top),
      hitInsideStatus: !!(topAtButtonCenter && topAtButtonCenter.closest('[data-ll-category-settings-status]'))
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.hitInsideStatus).toBe(true);
  expect(metrics.statusTop).toBeGreaterThan(0);
  expect(metrics.statusBottom).toBeLessThanOrEqual(900);
  expect(Math.abs(metrics.footerBottom - metrics.panelBottom)).toBeLessThanOrEqual(18);

  await expect.poll(async () => (
    page.evaluate(() => window.__llCategorySettingsSaves.length)
  )).toBeGreaterThan(0);
});
