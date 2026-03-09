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

const defaultRows = [
  { id: 12, label: 'Basics', level: 1 },
  { id: 13, label: 'Food', level: 2 },
  { id: 14, label: 'Travel', level: 3 }
];

function buildPrereqEditorMarkup({
  options = defaultRows,
  selected = [defaultRows[1]],
  blocked = [],
  currentLevel = 3,
  hasCycle = false
} = {}) {
  return `
    <div class="ll-vocab-lesson-page" data-ll-vocab-lesson style="padding: 10px; box-sizing: border-box;">
      <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="7" data-ll-category-id="11">
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
            <div
              class="ll-vocab-lesson-bulk-section ll-vocab-lesson-bulk-section--prereq"
              data-ll-prereq-editor
              data-ll-prereq-options='${JSON.stringify(options)}'
              data-ll-prereq-selected='${JSON.stringify(selected)}'
              data-ll-prereq-blocked='${JSON.stringify(blocked)}'
              data-ll-prereq-current-level="${currentLevel}"
              data-ll-prereq-has-cycle="${hasCycle ? '1' : '0'}"
            >
              <div class="ll-vocab-lesson-bulk-heading">Prerequisites</div>
              <div class="ll-vocab-lesson-prereq-toolbar">
                <div class="ll-vocab-lesson-prereq-meta" aria-label="Prerequisite level">
                  <span class="ll-vocab-lesson-prereq-meta-icon" aria-hidden="true">L</span>
                  <span class="ll-vocab-lesson-prereq-level-value" data-ll-prereq-level>L${currentLevel}</span>
                </div>
                <span class="ll-vocab-lesson-prereq-status" data-ll-prereq-status data-state="idle" role="status" aria-live="polite" hidden>
                  <span class="ll-vocab-lesson-prereq-status-icon" aria-hidden="true"></span>
                  <span class="ll-vocab-lesson-prereq-status-message" data-ll-prereq-status-message hidden></span>
                </span>
              </div>
              <label class="screen-reader-text" for="ll-test-prereq-input">Search prerequisite categories</label>
              <div class="ll-vocab-lesson-prereq-controls">
                <div class="ll-vocab-lesson-prereq-search">
                  <span class="ll-vocab-lesson-prereq-search-icon" aria-hidden="true">S</span>
                  <input
                    type="text"
                    id="ll-test-prereq-input"
                    class="ll-vocab-lesson-prereq-input"
                    data-ll-prereq-input
                    autocomplete="off"
                    placeholder="Find categories"
                  />
                  <button type="button" class="ll-vocab-lesson-prereq-search-clear" data-ll-prereq-search-clear aria-label="Clear search" hidden>
                    <span aria-hidden="true">x</span>
                  </button>
                </div>
              </div>
              <div class="ll-vocab-lesson-prereq-chips" data-ll-prereq-chips aria-live="polite" hidden></div>
              <div class="ll-vocab-lesson-prereq-options" data-ll-prereq-options-list></div>
              <p class="ll-vocab-lesson-prereq-warning" data-ll-prereq-cycle-warning ${hasCycle ? '' : 'hidden'}>
                Loop warning
              </p>
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
    nonce: 'test-nonce',
    editNonce: 'test-edit-nonce',
    canEdit: true,
    isLoggedIn: true,
    state: {
      wordset_id: 7,
      category_ids: [11],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    i18n: {},
    prereqI18n: {
      saving: 'Saving prerequisites...',
      saved: 'Prerequisites saved.',
      error: 'Unable to save prerequisites.',
      remove: 'Remove %s',
      optionAdd: 'Add %s',
      optionRemove: 'Remove %s',
      optionBlocked: 'Cannot add %s because it would create a loop.',
      blockedHint: 'Would create a prerequisite loop.',
      noMatches: 'No matching categories.',
      levelCycle: 'Cycle',
      levelUnknown: '-'
    }
  };
}

async function mountPrereqEditor(page, viewport, {
  options = defaultRows,
  selected = [defaultRows[1]],
  blocked = [],
  currentLevel = 3,
  hasCycle = false,
  failOnIdsContaining = [],
  failResponse = null,
  successBlockedIds = blocked
} = {}) {
  await page.setViewportSize(viewport);
  await page.goto('about:blank');
  await page.setContent(buildPrereqEditorMarkup({
    options,
    selected,
    blocked,
    currentLevel,
    hasCycle
  }));
  await page.addStyleTag({ content: flashcardBaseCssSource });
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
  }, buildWordGridConfig());
  await page.evaluate((mockConfig) => {
    const optionRows = {};
    (mockConfig.options || []).forEach((row) => {
      optionRows[row.id] = row;
    });

    window.llPrereqPostCalls = [];
    jQuery.post = function (_url, data) {
      const payload = JSON.parse(JSON.stringify(data || {}));
      window.llPrereqPostCalls.push(payload);

      const deferred = jQuery.Deferred();
      const ids = Array.isArray(payload.prereq_ids)
        ? payload.prereq_ids.map((id) => parseInt(id, 10)).filter(Boolean)
        : [];
      const shouldFail = Array.isArray(mockConfig.failOnIdsContaining)
        && mockConfig.failOnIdsContaining.length > 0
        && mockConfig.failOnIdsContaining.every((id) => ids.includes(id));

      if (shouldFail) {
        deferred.reject({
          responseJSON: {
            success: false,
            data: mockConfig.failResponse
          }
        });
        return deferred.promise();
      }

      const selectedRows = ids.map((id) => optionRows[id]).filter(Boolean);
      deferred.resolve({
        success: true,
        data: {
          message: 'Prerequisites saved.',
          selected: selectedRows,
          selected_ids: ids,
          blocked_ids: Array.isArray(mockConfig.successBlockedIds) ? mockConfig.successBlockedIds : [],
          level: mockConfig.currentLevel,
          has_cycle: false
        }
      });

      return deferred.promise();
    };
  }, {
    options,
    failOnIdsContaining,
    failResponse,
    successBlockedIds,
    currentLevel
  });
  await page.addScriptTag({ content: wordGridScriptSource });
}

async function exercisePrereqEditor(page) {
  await page.locator('.ll-vocab-lesson-bulk-button').click();
  await expect(page.locator('.ll-vocab-lesson-bulk-panel')).toHaveAttribute('aria-hidden', 'false');

  const basicsOption = page.locator('[data-ll-prereq-option]').filter({ hasText: 'Basics' }).first();
  const foodOption = page.locator('[data-ll-prereq-option]').filter({ hasText: 'Food' }).first();
  const travelOption = page.locator('[data-ll-prereq-option]').filter({ hasText: 'Travel' }).first();

  await expect(foodOption).toHaveAttribute('aria-pressed', 'true');

  await basicsOption.click();
  await expect(basicsOption).toHaveAttribute('aria-pressed', 'true');

  const searchInput = page.locator('[data-ll-prereq-input]');
  await searchInput.fill('tra');
  await expect(page.locator('[data-ll-prereq-option]')).toHaveCount(1);
  await travelOption.click();

  const clearSearchButton = page.locator('[data-ll-prereq-search-clear]');
  await expect(clearSearchButton).toBeVisible();
  await clearSearchButton.click();
  await expect(travelOption).toHaveAttribute('aria-pressed', 'true');

  await page.locator('[data-ll-prereq-chip-id="13"] [data-ll-prereq-remove]').click();
  await expect(foodOption).toHaveAttribute('aria-pressed', 'false');

  await travelOption.click();
  await expect(travelOption).toHaveAttribute('aria-pressed', 'false');
  await travelOption.click();
  await expect(travelOption).toHaveAttribute('aria-pressed', 'true');

  await expect(page.locator('[data-ll-prereq-chip-id]')).toHaveCount(2);
  await page.waitForFunction(() => {
    const calls = Array.isArray(window.llPrereqPostCalls) ? window.llPrereqPostCalls : [];
    if (!calls.length) {
      return false;
    }
    const last = calls[calls.length - 1] || {};
    const ids = Array.isArray(last.prereq_ids) ? last.prereq_ids.map(String) : [];
    return ids.join(',') === '12,14';
  });
  await expect(page.locator('[data-ll-prereq-status]')).toHaveAttribute('data-state', 'saved');

  const calls = await page.evaluate(() => window.llPrereqPostCalls);
  expect(calls.length).toBeGreaterThanOrEqual(1);
  const lastCall = calls[calls.length - 1] || {};
  expect((lastCall.prereq_ids || []).map(String)).toEqual(['12', '14']);
}

[
  { name: 'desktop', viewport: { width: 1280, height: 900 } },
  { name: 'mobile', viewport: { width: 390, height: 844 } }
].forEach(({ name, viewport }) => {
  test(`${name} prerequisites editor supports multi-select and stable deselection`, async ({ page }) => {
    await mountPrereqEditor(page, viewport);
    await exercisePrereqEditor(page);
  });
});

test('prerequisites editor reverts looped saves and only shows blocked options when searched', async ({ page }) => {
  const blockedRow = { id: 15, label: 'Loops', level: 2 };
  const savedSelection = [{ id: 13, label: 'Food', level: 2 }];

  await mountPrereqEditor(page, { width: 1280, height: 900 }, {
    options: defaultRows.concat([blockedRow]),
    selected: savedSelection,
    blocked: [],
    failOnIdsContaining: [15],
    failResponse: {
      message: 'Prerequisites were not saved because they create a loop: Food -> Loops -> Food',
      selected: savedSelection,
      selected_ids: [13],
      blocked_ids: [15],
      level: 3,
      has_cycle: false
    }
  });

  await page.locator('.ll-vocab-lesson-bulk-button').click();

  const loopsOption = page.locator('[data-ll-prereq-option]').filter({ hasText: 'Loops' }).first();
  await expect(loopsOption).toHaveCount(1);
  await loopsOption.click();

  await expect(page.locator('[data-ll-prereq-status]')).toHaveAttribute('data-state', 'error');
  await expect(page.locator('[data-ll-prereq-chip-id]')).toHaveCount(1);
  await expect(page.locator('[data-ll-prereq-chip-id="15"]')).toHaveCount(0);
  await expect(page.locator('[data-ll-prereq-option]').filter({ hasText: 'Loops' })).toHaveCount(0);

  const searchInput = page.locator('[data-ll-prereq-input]');
  await searchInput.fill('loop');

  const blockedOption = page.locator('[data-ll-prereq-option]').filter({ hasText: 'Loops' }).first();
  await expect(blockedOption).toHaveClass(/is-blocked/);
  await expect(blockedOption).toBeDisabled();
  await expect(blockedOption).toHaveAttribute('aria-pressed', 'false');

  const calls = await page.evaluate(() => window.llPrereqPostCalls);
  expect(calls).toHaveLength(1);
  expect((calls[0].prereq_ids || []).map(String)).toEqual(['13', '15']);
});
