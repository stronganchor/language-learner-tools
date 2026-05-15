const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);

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
    editI18n: {}
  };
}

function categoryOption(id, label, order, checked = false) {
  return `
    <label
      class="ll-word-edit-category-option"
      for="ll-word-edit-category-${id}"
      data-ll-word-category-option
      data-ll-word-category-label="${label}"
      data-ll-word-category-search-text="${label}"
      data-ll-wordset-order="${order}">
      <input
        type="checkbox"
        id="ll-word-edit-category-${id}"
        class="ll-word-edit-category-checkbox"
        data-ll-word-category-input
        value="${id}"${checked ? ' checked' : ''} />
      <span class="ll-word-edit-category-label">${label}</span>
    </label>
  `;
}

function buildEditorMarkup() {
  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="7" data-ll-category-id="11">
      <div class="word-item ll-word-edit-open" data-word-id="101">
        <button type="button" data-ll-word-edit-toggle aria-expanded="true">Edit</button>
        <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="false"></div>
        <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="false">
          <div class="ll-word-edit-body" data-ll-word-edit-body>
            <fieldset class="ll-word-edit-field ll-word-edit-categories" data-ll-word-categories-field>
              <legend class="ll-word-edit-label">Categories</legend>
              <div class="ll-word-edit-category-tools">
                <label class="screen-reader-text" for="ll-word-edit-category-search-101">Search categories</label>
                <input
                  type="search"
                  class="ll-word-edit-input ll-word-edit-category-search"
                  id="ll-word-edit-category-search-101"
                  data-ll-word-category-search
                  placeholder="Search categories"
                  autocomplete="off" />
                <label class="screen-reader-text" for="ll-word-edit-category-sort-101">Sort categories</label>
                <select
                  class="ll-word-edit-input ll-word-edit-category-sort"
                  id="ll-word-edit-category-sort-101"
                  data-ll-word-category-sort
                  aria-label="Sort categories">
                  <option value="wordset">Wordset order</option>
                  <option value="alpha">A-Z</option>
                </select>
              </div>
              <div class="ll-word-edit-category-list" data-ll-word-category-list>
                ${categoryOption(31, 'Zoo animals', 0)}
                ${categoryOption(32, 'Apples and fruit', 1)}
                ${categoryOption(33, 'Market phrases', 2, true)}
              </div>
              <div class="ll-word-edit-category-empty" data-ll-word-category-empty hidden>No categories match.</div>
            </fieldset>
          </div>
        </div>
      </div>
    </div>
  `;
}

async function mountEditor(page) {
  await page.goto('about:blank');
  await page.setContent(buildEditorMarkup());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
  }, buildWordGridConfig());
  await page.addScriptTag({ content: wordGridScriptSource });
}

async function visibleCategoryLabels(page) {
  return page.locator('[data-ll-word-category-option]').evaluateAll((nodes) =>
    nodes
      .filter((node) => !node.hidden)
      .map((node) => {
        const label = node.querySelector('.ll-word-edit-category-label');
        return label ? label.textContent.trim() : '';
      })
  );
}

test('word editor category controls filter as the user types and show empty state', async ({ page }) => {
  await mountEditor(page);

  await page.fill('[data-ll-word-category-search]', 'market');
  await expect(page.locator('[data-ll-word-category-empty]')).toBeHidden();
  await expect.poll(() => visibleCategoryLabels(page)).toEqual(['Market phrases']);

  await page.fill('[data-ll-word-category-search]', 'missing');
  await expect.poll(() => visibleCategoryLabels(page)).toEqual([]);
  await expect(page.locator('[data-ll-word-category-empty]')).toBeVisible();
});

test('word editor category controls keep checked categories first while switching sort modes', async ({ page }) => {
  await mountEditor(page);

  await expect.poll(() => visibleCategoryLabels(page)).toEqual([
    'Market phrases',
    'Zoo animals',
    'Apples and fruit'
  ]);

  await page.selectOption('[data-ll-word-category-sort]', 'alpha');
  await expect.poll(() => visibleCategoryLabels(page)).toEqual([
    'Market phrases',
    'Apples and fruit',
    'Zoo animals'
  ]);

  await page.selectOption('[data-ll-word-category-sort]', 'wordset');
  await expect.poll(() => visibleCategoryLabels(page)).toEqual([
    'Market phrases',
    'Zoo animals',
    'Apples and fruit'
  ]);
});

test('word editor category controls move newly checked categories into the selected group', async ({ page }) => {
  await mountEditor(page);

  await page.check('[data-ll-word-category-input][value="32"]');

  await expect.poll(() => visibleCategoryLabels(page)).toEqual([
    'Apples and fruit',
    'Market phrases',
    'Zoo animals'
  ]);
});

test('word editor category section is compact until interacted with', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mountEditor(page);

  const field = page.locator('[data-ll-word-categories-field]');
  const list = page.locator('[data-ll-word-category-list]');
  const compactMaxHeight = await list.evaluate((node) => parseFloat(window.getComputedStyle(node).maxHeight || '0'));

  await field.click({ position: { x: 8, y: 8 } });
  await expect(field).toHaveClass(/is-expanded/);

  await expect.poll(async () => list.evaluate((node) => parseFloat(window.getComputedStyle(node).maxHeight || '0')))
    .toBeGreaterThan(compactMaxHeight);
});
