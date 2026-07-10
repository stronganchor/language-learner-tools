const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const categorySelectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/category-selection.js'),
  'utf8'
);

test('standalone category picker loads the next bounded catalog page on demand', async ({ page }) => {
  let requestData = null;
  await page.route('https://example.test/wp-admin/admin-ajax.php', async (route) => {
    requestData = new URLSearchParams(route.request().postData() || '');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          categories: [{ id: 3, slug: 'gamma', name: 'Gamma' }],
          catalog: { hasMore: false, nextOffset: 48, pageSize: 24 }
        }
      })
    });
  });

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container" data-wordset="demo">
      <button id="ll-tools-start-flashcard" type="button">Start</button>
      <div id="ll-tools-flashcard-popup" style="display:none">
        <div id="ll-tools-category-selection-popup" style="display:none">
          <button id="ll-tools-uncheck-all" type="button">Deselect all</button>
          <button id="ll-tools-check-all" type="button">Select all</button>
          <div id="ll-tools-category-checkboxes"></div>
          <button id="ll-tools-load-more-categories" type="button" hidden>Load more</button>
          <span id="ll-tools-category-catalog-status"></span>
          <button id="ll-tools-start-selected-quiz" type="button">Start</button>
          <button id="ll-tools-close-category-selection" type="button">Close</button>
        </div>
        <div id="ll-tools-flashcard-quiz-popup" style="display:none"></div>
        <button id="ll-tools-close-flashcard" type="button">Close</button>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
      categories: [
        { id: 1, slug: 'alpha', name: 'Alpha' },
        { id: 2, slug: 'beta', name: 'Beta' }
      ],
      categoriesPreselected: false,
      categoryCatalog: { hasMore: true, nextOffset: 24, pageSize: 24 },
      quiz_mode: 'practice',
      wordset: 'demo',
      wordsetFallback: false
    };
    window.llToolsFlashcardsMessages = {
      categoryCatalogLoadMore: 'Load more',
      categoryCatalogLoading: 'Loading...',
      categoryCatalogError: 'Unable to load categories.'
    };
    window.LLFlashcards = {
      Util: {
        getCategorySelectionValue(category) {
          return category.slug;
        }
      }
    };
  });
  await page.addScriptTag({ content: categorySelectionSource });

  await page.locator('#ll-tools-start-flashcard').click();
  await expect(page.locator('#ll-tools-category-checkboxes input')).toHaveCount(2);
  await page.locator('#category-alpha').check();
  await expect(page.locator('#ll-tools-load-more-categories')).toBeVisible();
  await page.locator('#ll-tools-load-more-categories').click();

  await expect(page.locator('#ll-tools-category-checkboxes input')).toHaveCount(3);
  await expect(page.locator('#category-alpha')).toBeChecked();
  await expect(page.locator('#category-gamma')).toBeVisible();
  await expect(page.locator('#ll-tools-load-more-categories')).toBeHidden();
  expect(requestData.get('action')).toBe('ll_get_flashcard_category_catalog');
  expect(requestData.get('offset')).toBe('24');
  expect(requestData.get('wordset')).toBe('demo');
});
