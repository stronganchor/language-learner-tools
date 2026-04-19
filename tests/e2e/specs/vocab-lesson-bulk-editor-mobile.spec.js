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
const hostileBulkThemeCss = `
  .ll-vocab-lesson-page .ll-vocab-lesson-bulk-panel button,
  .ll-vocab-lesson-page .ll-vocab-lesson-bulk-panel input,
  .ll-vocab-lesson-page .ll-vocab-lesson-bulk-panel select {
    min-width: 96px !important;
    min-height: 46px !important;
    padding: 12px 18px !important;
    margin: 8px !important;
    border: 3px solid #1570a6 !important;
    border-radius: 0 !important;
    background: #eef7ff !important;
    box-shadow: inset 0 0 0 2px rgba(21, 112, 166, 0.35) !important;
    color: #113555 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    letter-spacing: 0.18em !important;
    line-height: 1.8 !important;
    text-transform: uppercase !important;
  }
`;

function buildMinimalWordGridMarkup(options = {}) {
  const useBlankNounMetaInputs = !!options.useBlankNounMetaInputs;
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
                  <div class="ll-vocab-lesson-bulk-heading-actions">
                    <span class="ll-vocab-lesson-bulk-control-status" data-ll-bulk-control-status="pos" data-state="idle" role="status" aria-live="polite" hidden>
                      <span class="ll-vocab-lesson-bulk-control-status-icon" aria-hidden="true"></span>
                      <span class="ll-vocab-lesson-bulk-control-status-message" data-ll-bulk-control-status-message hidden></span>
                    </span>
                    <button type="button" class="ll-vocab-lesson-bulk-control-undo" data-ll-bulk-control-undo="pos" aria-label="Undo last bulk change" hidden>
                      <span class="ll-vocab-lesson-bulk-control-undo-icon" aria-hidden="true">U</span>
                    </button>
                  </div>
                </div>
                <div class="ll-vocab-lesson-bulk-controls">
                  <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-pos>
                    <option value="">Part of Speech</option>
                    <option value="noun" selected>Noun</option>
                    <option value="adjective">Adjective</option>
                  </select>
                </div>
              </div>
              <div class="ll-vocab-lesson-bulk-section">
                <div class="ll-vocab-lesson-bulk-heading-row">
                  <div class="ll-vocab-lesson-bulk-heading">Gender</div>
                </div>
                <div class="ll-vocab-lesson-bulk-controls">
                  <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-gender>
                    <option value="">Gender</option>
                    <option value="Masculine" selected>Masculine</option>
                    <option value="Feminine">Feminine</option>
                  </select>
                </div>
              </div>
              <div class="ll-vocab-lesson-bulk-section">
                <div class="ll-vocab-lesson-bulk-heading-row">
                  <div class="ll-vocab-lesson-bulk-heading">Plurality</div>
                </div>
                <div class="ll-vocab-lesson-bulk-controls">
                  <select class="ll-vocab-lesson-bulk-select" data-ll-bulk-plurality>
                    <option value="">Plurality</option>
                    <option value="Singular" selected>Singular</option>
                    <option value="Plural">Plural</option>
                  </select>
                </div>
              </div>
              <span class="ll-vocab-lesson-bulk-status" data-ll-bulk-status aria-live="polite"></span>
            </div>
          </div>
        </div>

        <div class="word-item" data-word-id="101">
          <div data-ll-word-meta>
            <span data-ll-word-pos>Noun</span>
            <span data-ll-word-gender data-ll-gender-role="masculine" aria-label="Masculine" title="Masculine">Masculine</span>
            <span data-ll-word-plurality>Singular</span>
            <span data-ll-word-verb-tense></span>
            <span data-ll-word-verb-mood></span>
          </div>
          <select data-ll-word-input="part_of_speech">
            <option value="">Part of Speech</option>
            <option value="noun" selected>Noun</option>
            <option value="adjective">Adjective</option>
          </select>
          <div data-ll-word-gender-field>
            <select data-ll-word-input="gender">
              <option value="">Gender</option>
              <option value="Masculine"${useBlankNounMetaInputs ? '' : ' selected'}>Masculine</option>
            </select>
          </div>
          <div data-ll-word-plurality-field>
            <select data-ll-word-input="plurality">
              <option value="">Plurality</option>
              <option value="Singular"${useBlankNounMetaInputs ? '' : ' selected'}>Singular</option>
            </select>
          </div>
          <div data-ll-word-verb-tense-field aria-hidden="true">
            <select data-ll-word-input="verb_tense" disabled>
              <option value="">Verb tense</option>
              <option value="Present">Present</option>
            </select>
          </div>
          <div data-ll-word-verb-mood-field aria-hidden="true">
            <select data-ll-word-input="verb_mood" disabled>
              <option value="">Verb mood</option>
              <option value="Indicative">Indicative</option>
            </select>
          </div>
        </div>

        <div class="word-item" data-word-id="102">
          <div data-ll-word-meta>
            <span data-ll-word-pos>Noun</span>
            <span data-ll-word-gender data-ll-gender-role="masculine" aria-label="Masculine" title="Masculine">Masculine</span>
            <span data-ll-word-plurality>Singular</span>
            <span data-ll-word-verb-tense></span>
            <span data-ll-word-verb-mood></span>
          </div>
          <select data-ll-word-input="part_of_speech">
            <option value="">Part of Speech</option>
            <option value="noun" selected>Noun</option>
            <option value="adjective">Adjective</option>
          </select>
          <div data-ll-word-gender-field>
            <select data-ll-word-input="gender">
              <option value="">Gender</option>
              <option value="Masculine"${useBlankNounMetaInputs ? '' : ' selected'}>Masculine</option>
            </select>
          </div>
          <div data-ll-word-plurality-field>
            <select data-ll-word-input="plurality">
              <option value="">Plurality</option>
              <option value="Singular"${useBlankNounMetaInputs ? '' : ' selected'}>Singular</option>
            </select>
          </div>
          <div data-ll-word-verb-tense-field aria-hidden="true">
            <select data-ll-word-input="verb_tense" disabled>
              <option value="">Verb tense</option>
              <option value="Present">Present</option>
            </select>
          </div>
          <div data-ll-word-verb-mood-field aria-hidden="true">
            <select data-ll-word-input="verb_mood" disabled>
              <option value="">Verb mood</option>
              <option value="Indicative">Indicative</option>
            </select>
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
      undoLabel: 'Undo last bulk change',
      undoSuccess: 'Bulk changes undone.',
      undoError: 'Unable to undo bulk changes.',
      posSuccess: 'Updated %d words.',
      error: 'Unable to update words.'
    }
  };
}

async function mountMobileBulkEditor(page, options = {}) {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildMinimalWordGridMarkup(options));
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

      if (payload.action === 'll_tools_word_grid_bulk_undo') {
        deferred.resolve({
          success: true,
          data: {
            message: 'Bulk changes undone.',
            word_ids: [101, 102],
            words: [
              {
                word_id: 101,
                part_of_speech: { slug: 'noun', label: 'Noun' },
                grammatical_gender: { value: 'Masculine', label: 'Masculine', role: 'masculine', style: '', html: 'Masculine' },
                grammatical_plurality: { value: 'Singular', label: 'Singular' },
                verb_tense: { value: '', label: '' },
                verb_mood: { value: '', label: '' }
              },
              {
                word_id: 102,
                part_of_speech: { slug: 'noun', label: 'Noun' },
                grammatical_gender: { value: 'Masculine', label: 'Masculine', role: 'masculine', style: '', html: 'Masculine' },
                grammatical_plurality: { value: 'Singular', label: 'Singular' },
                verb_tense: { value: '', label: '' },
                verb_mood: { value: '', label: '' }
              }
            ]
          }
        });
        return deferred.promise();
      }

      deferred.resolve({
        success: true,
        data: {
          word_ids: [101, 102],
          part_of_speech: {
            slug: payload.part_of_speech || '',
            label: payload.part_of_speech === 'adjective' ? 'Adjective' : 'Noun'
          },
          gender_cleared: true,
          plurality_cleared: true,
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

test('mobile bulk edit keeps server-selected defaults on first open', async ({ page }) => {
  await mountMobileBulkEditor(page, { useBlankNounMetaInputs: true });

  await page.locator('.ll-vocab-lesson-bulk-button').click();
  await expect(page.locator('[data-ll-bulk-pos]')).toHaveValue('noun');
  await expect(page.locator('[data-ll-bulk-gender]')).toHaveValue('Masculine');
  await expect(page.locator('[data-ll-bulk-plurality]')).toHaveValue('Singular');
});

test('mobile bulk edit applies a select change and can undo it', async ({ page }) => {
  await mountMobileBulkEditor(page);

  await page.locator('.ll-vocab-lesson-bulk-button').click();
  await expect(page.locator('[data-ll-bulk-pos]')).toHaveValue('noun');
  await expect(page.locator('[data-ll-bulk-pos-apply]')).toHaveCount(0);

  await page.locator('[data-ll-bulk-pos]').selectOption('adjective');

  await page.waitForFunction(() => Array.isArray(window.llBulkPostCalls) && window.llBulkPostCalls.length === 1);
  await expect(page.locator('[data-ll-bulk-control-status="pos"]')).toHaveAttribute('data-state', 'saved');
  await expect(page.locator('[data-ll-bulk-control-undo="pos"]')).toBeVisible();
  await expect(page.locator('.word-item[data-word-id="101"] [data-ll-word-pos]')).toHaveText('Adjective');
  await expect(page.locator('.word-item[data-word-id="101"] [data-ll-word-gender]')).toHaveText('');

  await page.locator('[data-ll-bulk-control-undo="pos"]').click();

  await page.waitForFunction(() => Array.isArray(window.llBulkPostCalls) && window.llBulkPostCalls.length === 2);
  await expect(page.locator('[data-ll-bulk-pos]')).toHaveValue('noun');
  await expect(page.locator('[data-ll-bulk-control-undo="pos"]')).toBeHidden();
  await expect(page.locator('.word-item[data-word-id="101"] [data-ll-word-pos]')).toHaveText('Noun');
  await expect(page.locator('.word-item[data-word-id="101"] [data-ll-word-gender]')).toHaveText('Masculine');

  const calls = await page.evaluate(() => window.llBulkPostCalls);
  expect(calls).toHaveLength(2);
  expect(calls[0].action).toBe('ll_tools_word_grid_bulk_update');
  expect(calls[1].action).toBe('ll_tools_word_grid_bulk_undo');
  expect(calls[1].mode).toBe('pos');
});

test('mobile bulk edit neutralizes hostile theme select and button styles', async ({ page }) => {
  await mountMobileBulkEditor(page);
  await page.addStyleTag({ content: hostileBulkThemeCss });

  await page.locator('.ll-vocab-lesson-bulk-button').click();
  await page.locator('[data-ll-bulk-pos]').selectOption('adjective');

  await page.waitForFunction(() => Array.isArray(window.llBulkPostCalls) && window.llBulkPostCalls.length === 1);
  await expect(page.locator('[data-ll-bulk-control-undo="pos"]')).toBeVisible();

  const styles = await page.evaluate(() => {
    const select = document.querySelector('[data-ll-bulk-pos]');
    const undo = document.querySelector('[data-ll-bulk-control-undo="pos"]');
    const selectStyles = window.getComputedStyle(select);
    const undoStyles = window.getComputedStyle(undo);
    const undoRect = undo.getBoundingClientRect();

    return {
      select: {
        borderRadius: selectStyles.borderRadius,
        textTransform: selectStyles.textTransform,
        letterSpacing: selectStyles.letterSpacing,
        boxShadow: selectStyles.boxShadow,
        backgroundColor: selectStyles.backgroundColor
      },
      undo: {
        width: undoRect.width,
        height: undoRect.height,
        borderRadius: undoStyles.borderRadius,
        backgroundColor: undoStyles.backgroundColor,
        boxShadow: undoStyles.boxShadow
      }
    };
  });

  expect(styles.select.borderRadius).toBe('8px');
  expect(styles.select.textTransform).toBe('none');
  expect(styles.select.letterSpacing).not.toBe('3.06px');
  expect(styles.select.boxShadow).toBe('none');
  expect(styles.select.backgroundColor).toBe('rgb(255, 255, 255)');

  expect(styles.undo.width).toBeLessThanOrEqual(26);
  expect(styles.undo.height).toBeLessThanOrEqual(26);
  expect(styles.undo.borderRadius).not.toBe('0px');
  expect(styles.undo.backgroundColor).toBe('rgb(255, 255, 255)');
  expect(styles.undo.boxShadow).toBe('none');
});
