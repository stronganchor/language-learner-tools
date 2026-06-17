const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);

const hostileThemeCss = `
  .word-grid.ll-word-grid button,
  .word-grid.ll-word-grid input,
  .word-grid.ll-word-grid textarea {
    min-height: 48px !important;
    font-size: 16px !important;
    line-height: 1.5 !important;
  }

  .word-grid.ll-word-grid button {
    margin: 0 !important;
    border-radius: 0 !important;
  }
`;

function buildWordEditorMarkup(options = {}) {
  const isOpen = !!options.isOpen;
  const extraFields = Array.from({ length: 16 }, (_, index) => {
    const fieldIndex = index + 1;
    return `
      <label class="ll-word-edit-label" for="ll-word-edit-extra-${fieldIndex}">Extra field ${fieldIndex}</label>
      <textarea
        class="ll-word-edit-input ll-word-edit-textarea"
        id="ll-word-edit-extra-${fieldIndex}"
        rows="3"
      >Extra content ${fieldIndex}</textarea>
    `;
  }).join('');

  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid>
      <div class="word-item ll-word-edit-open" data-word-id="101">
        <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="${isOpen ? 'false' : 'true'}"${isOpen ? '' : ' hidden'}></div>
        <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="${isOpen ? 'false' : 'true'}">
          <div class="ll-word-edit-body" data-ll-word-edit-body>
            <div class="ll-word-edit-fields">
              <label class="ll-word-edit-label" for="ll-word-edit-word-101">Word</label>
              <input type="text" class="ll-word-edit-input" id="ll-word-edit-word-101" value="shalom" />
              <label class="ll-word-edit-label" for="ll-word-edit-translation-101">Translation</label>
              <input type="text" class="ll-word-edit-input" id="ll-word-edit-translation-101" value="peace" />
              <label class="ll-word-edit-label" for="ll-word-edit-note-101">Note</label>
              <textarea class="ll-word-edit-input ll-word-edit-textarea" id="ll-word-edit-note-101" rows="4">Longer note to force a realistic modal height on mobile.</textarea>
              ${extraFields}
            </div>
          </div>
          <div class="ll-word-edit-footer">
            <div class="ll-word-edit-actions">
              <button type="button" class="ll-word-edit-action ll-word-edit-save" data-ll-word-edit-save aria-label="Save" title="Save">
                <span aria-hidden="true">
                  <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
              </button>
              <button type="button" class="ll-word-edit-action ll-word-edit-cancel" data-ll-word-edit-cancel aria-label="Cancel" title="Cancel">
                <span aria-hidden="true">
                  <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
              </button>
            </div>
            <div class="ll-word-edit-status" data-ll-word-edit-status aria-live="polite">Ready</div>
          </div>
        </div>
      </div>
    </div>
  `;
}

function buildCategoryEditorMarkup() {
  const categories = [
    { id: 1, name: 'Aile - Benim, Bizim', count: 10, quizzable: true },
    { id: 2, name: 'Al etli Dil nerede?', count: 0, quizzable: false },
    { id: 3, name: 'Doğa: gökyüzü', count: 7, quizzable: true },
    { id: 4, name: 'Diğer Ev Eşyası', count: 36, quizzable: true }
  ];

  const categoryOptions = categories.map((category) => `
    <label
      class="ll-word-edit-category-option${category.quizzable ? '' : ' ll-word-edit-category-option--not-quizzable'}"
      for="category-${category.id}"
      data-ll-word-category-option
      data-ll-word-category-id="${category.id}"
      data-ll-word-category-quizzable-count="${category.count}"
      data-ll-word-category-quizzable="${category.quizzable ? '1' : '0'}"
    >
      <input type="checkbox" id="category-${category.id}" class="ll-word-edit-category-checkbox" />
      <span class="ll-word-edit-category-main">
        <span class="ll-word-edit-category-label">${category.name}</span>
        <span class="ll-word-edit-category-meta">
          <span class="ll-word-edit-category-count">${category.count}</span>
        </span>
      </span>
    </label>
  `).join('');

  return `
    <div class="word-grid ll-word-grid">
      <fieldset class="ll-word-edit-categories is-expanded">
        <legend class="ll-word-edit-label">Categories</legend>
        <div class="ll-word-edit-category-list">
          ${categoryOptions}
        </div>
      </fieldset>
    </div>
  `;
}

test('closed vocab lesson word editor panels stay hidden', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildWordEditorMarkup());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: hostileThemeCss });

  const panel = page.locator('[data-ll-word-edit-panel]');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(panel).toBeHidden();
});

test('mobile vocab lesson word editor keeps save and cancel visible while the form scrolls', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildWordEditorMarkup({ isOpen: true }));
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: hostileThemeCss });
  await page.evaluate(() => {
    document.body.classList.add('ll-word-edit-modal-open');
    document.body.style.margin = '0';
  });

  const editorBody = page.locator('[data-ll-word-edit-body]');
  const footer = page.locator('.ll-word-edit-footer');
  const saveButton = page.locator('[data-ll-word-edit-save]');
  const cancelButton = page.locator('[data-ll-word-edit-cancel]');

  await expect(editorBody).toBeVisible();
  await expect(footer).toBeVisible();
  await expect(saveButton).toBeVisible();
  await expect(cancelButton).toBeVisible();

  const scrollMetrics = await editorBody.evaluate((el) => ({
    scrollHeight: el.scrollHeight,
    clientHeight: el.clientHeight
  }));
  expect(scrollMetrics.scrollHeight).toBeGreaterThan(scrollMetrics.clientHeight);

  const initialFooterBox = await footer.boundingBox();
  const initialSaveBox = await saveButton.boundingBox();
  const initialCancelBox = await cancelButton.boundingBox();

  expect(initialFooterBox).not.toBeNull();
  expect(initialSaveBox).not.toBeNull();
  expect(initialCancelBox).not.toBeNull();

  const viewportHeight = 844;
  expect(initialFooterBox.y + initialFooterBox.height).toBeLessThanOrEqual(viewportHeight);
  expect(initialSaveBox.y + initialSaveBox.height).toBeLessThanOrEqual(viewportHeight);
  expect(initialCancelBox.y + initialCancelBox.height).toBeLessThanOrEqual(viewportHeight);

  await editorBody.evaluate((el) => {
    el.scrollTop = el.scrollHeight;
  });
  await page.waitForTimeout(50);

  const scrolledState = await editorBody.evaluate((el) => ({
    scrollTop: el.scrollTop,
    maxScrollTop: el.scrollHeight - el.clientHeight
  }));
  expect(scrolledState.scrollTop).toBeGreaterThan(0);
  expect(scrolledState.maxScrollTop - scrolledState.scrollTop).toBeLessThanOrEqual(2);

  const scrolledFooterBox = await footer.boundingBox();
  expect(scrolledFooterBox).not.toBeNull();
  expect(Math.abs(scrolledFooterBox.y - initialFooterBox.y)).toBeLessThanOrEqual(2);
  expect(scrolledFooterBox.y + scrolledFooterBox.height).toBeLessThanOrEqual(viewportHeight);
});

test('category editor counts do not crowd category labels', async ({ page }) => {
  await page.setViewportSize({ width: 1800, height: 480 });
  await page.goto('about:blank');
  await page.setContent(buildCategoryEditorMarkup());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: hostileThemeCss });

  const option = page.locator('.ll-word-edit-category-option[data-ll-word-category-id="2"]');
  const label = option.locator('.ll-word-edit-category-label');
  const count = option.locator('.ll-word-edit-category-count');

  await expect(option).toBeVisible();
  await expect(page.locator('.ll-word-edit-category-state')).toHaveCount(0);
  await expect(page.locator('.ll-word-edit-category-option--not-public')).toHaveCount(0);

  const optionBox = await option.boundingBox();
  const labelBox = await label.boundingBox();
  const countBox = await count.boundingBox();

  expect(optionBox).not.toBeNull();
  expect(labelBox).not.toBeNull();
  expect(countBox).not.toBeNull();
  expect(optionBox.width).toBeGreaterThanOrEqual(220);
  expect(labelBox.width).toBeGreaterThanOrEqual(150);
  expect(labelBox.x + labelBox.width).toBeLessThanOrEqual(countBox.x - 4);

  await expect(count).toHaveCSS('color', 'rgb(220, 38, 38)');
});
