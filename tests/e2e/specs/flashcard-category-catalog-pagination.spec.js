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

test('standalone launch handles initializer rejection and closes the loading popup', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div class="ll-tools-flashcard-container" data-wordset="demo">
      <button id="ll-tools-start-flashcard" type="button">Start</button>
      <div id="ll-tools-flashcard-popup" style="display:none">
        <div id="ll-tools-category-selection-popup" style="display:none"></div>
        <div id="ll-tools-flashcard-quiz-popup" style="display:none">
          <div id="ll-tools-loading-animation" style="display:block"></div>
        </div>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.__llAlerts = [];
    window.__llUnhandledRejections = [];
    window.__llDialogDeactivateCount = 0;
    window.alert = (message) => window.__llAlerts.push(String(message || ''));
    window.addEventListener('unhandledrejection', (event) => {
      window.__llUnhandledRejections.push(String(event.reason && event.reason.message || event.reason || ''));
      event.preventDefault();
    });
    window.llToolsFlashcardsData = {
      categories: [{ id: 1, slug: 'alpha', name: 'Alpha' }],
      categoriesPreselected: true,
      quiz_mode: 'practice',
      wordset: 'demo',
      wordsetFallback: false
    };
    window.llToolsFlashcardsMessages = {
      somethingWentWrong: 'Something went wrong. Please try again.'
    };
    window.LLToolsQuizDialog = {
      activate() {},
      deactivate() {
        window.__llDialogDeactivateCount += 1;
      }
    };
    window.LLFlashcards = {
      Util: {
        getCategorySelectionValue(category) {
          return category.slug;
        }
      },
      Dom: {
        hideLoadingImmediately() {
          window.jQuery('#ll-tools-loading-animation').hide();
        }
      },
      Main: {
        initFlashcardWidget() {
          return Promise.reject(new Error('test initialization failure'));
        }
      }
    };
  });
  await page.addScriptTag({ content: categorySelectionSource });

  await page.locator('#ll-tools-start-flashcard').click();
  await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);

  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    unhandled: window.__llUnhandledRejections.slice(),
    popupDisplay: window.getComputedStyle(document.getElementById('ll-tools-flashcard-popup')).display,
    quizDisplay: window.getComputedStyle(document.getElementById('ll-tools-flashcard-quiz-popup')).display,
    loadingDisplay: window.getComputedStyle(document.getElementById('ll-tools-loading-animation')).display,
    bodyOpen: document.body.classList.contains('ll-tools-flashcard-open'),
    dialogDeactivateCount: window.__llDialogDeactivateCount
  }));

  expect(result.alerts).toEqual(['Something went wrong. Please try again.']);
  expect(result.unhandled).toEqual([]);
  expect(result.popupDisplay).toBe('none');
  expect(result.quizDisplay).toBe('none');
  expect(result.loadingDisplay).toBe('none');
  expect(result.bodyOpen).toBeFalsy();
  expect(result.dialogDeactivateCount).toBe(1);
});

test('embedded launch reports an initializer rejection as an error state', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div class="ll-tools-flashcard-container" data-wordset="demo">
      <div id="ll-tools-flashcard-popup" style="display:none">
        <div id="ll-tools-flashcard-quiz-popup" style="display:none">
          <div id="ll-tools-loading-animation" style="display:block"></div>
        </div>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.alert = () => {};
    window.llToolsFlashcardsData = {
      isEmbed: true,
      categories: [{ id: 1, slug: 'alpha', name: 'Alpha' }],
      quiz_mode: 'practice',
      wordset: 'demo',
      wordsetFallback: false
    };
    window.llToolsFlashcardsMessages = {
      somethingWentWrong: 'Something went wrong. Please try again.'
    };
    window.LLFlashcards = {
      Util: {
        getCategorySelectionValue(category) {
          return category.slug;
        }
      },
      Dom: {
        hideLoadingImmediately() {
          window.jQuery('#ll-tools-loading-animation').hide();
        }
      },
      Main: {
        initFlashcardWidget() {
          return Promise.reject(new Error('test embedded initialization failure'));
        }
      }
    };
  });
  await page.addScriptTag({ content: categorySelectionSource });

  await expect.poll(async () => page.evaluate(() => window.__LL_EMBED_STATE || '')).toBe('ll-embed-error');
});
