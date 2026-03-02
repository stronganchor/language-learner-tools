const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const flashcardBaseCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);
const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);

function buildMinimalWordGridMarkup() {
  return `
    <div class="ll-vocab-lesson-page" style="padding: 10px; box-sizing: border-box;">
      <div class="word-grid ll-word-grid" data-ll-word-grid>
        <div class="ll-vocab-lesson-actions" style="justify-content: flex-start;">
          <div class="ll-vocab-lesson-bulk ll-tools-settings-control" data-ll-word-grid-bulk>
            <button
              type="button"
              class="ll-vocab-lesson-bulk-button ll-tools-settings-button"
              aria-haspopup="true"
              aria-expanded="false"
            >
              <span class="mode-icon" aria-hidden="true">E</span>
              <span class="ll-vocab-lesson-bulk-label">Bulk edit</span>
            </button>
            <div
              class="ll-vocab-lesson-bulk-panel ll-tools-settings-panel"
              role="dialog"
              aria-hidden="true"
            >
              <div class="ll-vocab-lesson-bulk-section">
                <div class="ll-vocab-lesson-bulk-heading">Part of Speech</div>
                <div class="ll-vocab-lesson-bulk-controls">
                  <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-pos>
                    <option value="">Part of Speech</option>
                    <option value="noun">Noun</option>
                  </select>
                  <button type="button" class="ll-study-btn tiny ll-vocab-lesson-bulk-apply" data-ll-bulk-pos-apply>
                    Apply
                  </button>
                </div>
              </div>
              <span class="ll-vocab-lesson-bulk-status" data-ll-bulk-status aria-live="polite"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

function buildWordGridConfig() {
  return {
    ajaxUrl: '',
    nonce: '',
    canEdit: true,
    isLoggedIn: true,
    state: {
      wordset_id: 1,
      category_ids: [],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    i18n: {}
  };
}

async function mountMobileBulkEditor(page) {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildMinimalWordGridMarkup());
  await page.addStyleTag({ content: flashcardBaseCssSource });
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
  }, buildWordGridConfig());
  await page.addScriptTag({ content: wordGridScriptSource });
}

test('mobile bulk edit panel stays inside viewport bounds', async ({ page }) => {
  await mountMobileBulkEditor(page);

  const bulkButton = page.locator('.ll-vocab-lesson-bulk-button').first();
  await bulkButton.click();

  const bulkPanel = page.locator('.ll-vocab-lesson-bulk-panel').first();
  await expect(bulkPanel).toHaveAttribute('aria-hidden', 'false');

  await page.waitForFunction(() => {
    const panel = document.querySelector('.ll-vocab-lesson-bulk-panel[aria-hidden="false"]');
    if (!panel) {
      return false;
    }
    const rect = panel.getBoundingClientRect();
    return rect.left >= -0.5 && rect.right <= (window.innerWidth + 0.5);
  });

  const bounds = await bulkPanel.evaluate((panel) => {
    const rect = panel.getBoundingClientRect();
    return {
      left: rect.left,
      right: rect.right,
      viewportWidth: window.innerWidth
    };
  });

  expect(bounds.left).toBeGreaterThanOrEqual(-0.5);
  expect(bounds.right).toBeLessThanOrEqual(bounds.viewportWidth + 0.5);
});
