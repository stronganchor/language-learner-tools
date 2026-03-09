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
      <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="1" data-ll-category-id="2">
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
                <div class="ll-vocab-lesson-bulk-heading-row">
                  <div class="ll-vocab-lesson-bulk-heading">Part of Speech</div>
                  <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="pos" data-state="idle" role="status" aria-live="polite" hidden>
                    <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                    <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                  </span>
                </div>
                <div class="ll-vocab-lesson-bulk-controls">
                  <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-pos>
                    <option value="">Part of Speech</option>
                    <option value="noun">Noun</option>
                  </select>
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
    ajaxUrl: '/wp-admin/admin-ajax.php',
    editNonce: 'bulk-test-edit-nonce',
    canEdit: true,
    isLoggedIn: true,
    state: {
      wordset_id: 1,
      category_ids: [2],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    i18n: {},
    bulkI18n: {
      saving: 'Updating...',
      saved: 'Saved.',
      posSuccess: 'Updated %d words.',
      error: 'Unable to update words.'
    }
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
  await page.evaluate(() => {
    window.llBulkPostCalls = [];
    jQuery.post = function (_url, data) {
      const payload = JSON.parse(JSON.stringify(data || {}));
      window.llBulkPostCalls.push(payload);
      const deferred = jQuery.Deferred();
      deferred.resolve({
        success: true,
        data: {
          word_ids: [101],
          part_of_speech: {
            slug: payload.part_of_speech || '',
            label: payload.part_of_speech === 'noun' ? 'Noun' : ''
          },
          gender_cleared: false,
          plurality_cleared: false,
          verb_tense_cleared: true,
          verb_mood_cleared: true
        }
      });
      return deferred.promise();
    };
  });
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

test('mobile bulk edit applies a select change without an apply button', async ({ page }) => {
  await mountMobileBulkEditor(page);

  await page.locator('.ll-vocab-lesson-bulk-button').click();
  await expect(page.locator('[data-ll-bulk-pos-apply]')).toHaveCount(0);

  await page.locator('[data-ll-bulk-pos]').selectOption('noun');

  await page.waitForFunction(() => Array.isArray(window.llBulkPostCalls) && window.llBulkPostCalls.length === 1);
  await expect(page.locator('[data-ll-bulk-control-status="pos"]')).toHaveAttribute('data-state', 'saved');

  const calls = await page.evaluate(() => window.llBulkPostCalls);
  expect(calls).toHaveLength(1);
  expect(calls[0].mode).toBe('pos');
  expect(calls[0].part_of_speech).toBe('noun');
});
