const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const matcherSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-image-matcher.js'),
  'utf8'
);

async function mountMatcher(page, scenario) {
  await page.route('https://example.test/', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<!doctype html><html><body></body></html>'
    });
  });
  await page.goto('https://example.test/');
  await page.setContent(`
    <select id="ll-aim-wordset"><option value="0" selected>Default</option></select>
    <select id="ll-aim-category"><option value="11" selected>Category</option></select>
    <input id="ll-aim-rematch" type="checkbox">
    <input id="ll-aim-hide-used" type="checkbox" checked>
    <button id="ll-aim-start" type="button">Start</button>
    <button id="ll-aim-skip" type="button" disabled>Skip</button>
    <div id="ll-aim-feedback">
      <span id="ll-aim-status" role="status" aria-live="polite"></span>
      <button id="ll-aim-retry" type="button" hidden>Retry</button>
    </div>
    <div id="ll-aim-stage" style="display:none">
      <h2 id="ll-aim-word-title"></h2>
      <audio id="ll-aim-audio"></audio>
      <div id="ll-aim-extra"></div>
      <div id="ll-aim-current-thumb" style="display:none"><img><span class="ll-aim-cap"></span></div>
      <div id="ll-aim-images" role="group"></div>
      <button id="ll-aim-load-more-images" type="button" hidden>Load more</button>
      <span id="ll-aim-image-page-status" role="status"></span>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((activeScenario) => {
    window.__llAimTest = {
      imageOffsets: [],
      imageCalls: 0,
      nextCalls: 0,
      assignCalls: 0,
      assignedImageIds: [],
      aborts: 0
    };
    window.llAimData = {
      ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      initialWordsetId: 0,
      initialCategoryId: 11,
      initialCategoryRows: [{ id: 11, slug: 'category', label: 'Category' }],
      requestTimeoutMs: ['timeout', 'assign-slow'].includes(activeScenario) ? 40 : 1000,
      i18n: {
        loadingDefault: 'Loading...',
        loadingCategories: 'Loading categories...',
        loadingImages: 'Loading images...',
        loadingNextAudio: 'Loading next audio...',
        loadingMoreImages: 'Loading more...',
        loadMoreImages: 'Load more',
        imageLoadError: 'Could not load images.',
        nextLoadError: 'Could not load the next audio.',
        requestTimedOut: 'The request timed out. Please try again.',
        saveError: 'Error saving match.',
        retryButton: 'Retry',
        imageChoiceLabel: 'Choose image: %s',
        alreadyPickedLabel: 'Already picked',
        pickedBadge: 'Picked',
        allDoneCategory: 'All done in this category.',
        noImagesFound: 'No images found in this category.',
        selectOption: '— Select —'
      }
    };
    window.llToolsFlashcardsData = { imageSize: 'small' };

    const response = (data) => ({
      ok: true,
      async json() {
        return { success: true, data };
      }
    });
    const delayedResponse = (data, delay = 80) => new Promise((resolve) => {
      window.setTimeout(() => resolve(response(data)), delay);
    });
    const images = [
      { id: 1, title: 'Image One', thumb: '', used_count: activeScenario === 'rematch-count' ? 1 : 0 },
      { id: 2, title: 'Image Two', thumb: '', used_count: 0 }
    ];

    window.fetch = function (url, options = {}) {
      const parsed = new URL(url);
      const body = new URLSearchParams(options.body || '');
      const action = parsed.searchParams.get('action') || body.get('action');
      const state = window.__llAimTest;

      if (action === 'll_aim_get_images') {
        const offset = parseInt(parsed.searchParams.get('offset') || '0', 10) || 0;
        state.imageCalls += 1;
        state.imageOffsets.push(offset);

        if (activeScenario === 'image-failure' && state.imageCalls === 1) {
          return Promise.reject(new Error('simulated image failure'));
        }

        const pageData = offset === 0
          ? { images, has_more: activeScenario === 'pagination', next_offset: 2, page_size: 2 }
          : {
              images: [{ id: 3, title: 'Image Three', thumb: '', used_count: 0 }],
              has_more: false,
              next_offset: 3,
              page_size: 2
            };
        return activeScenario === 'pagination'
          ? delayedResponse(pageData)
          : Promise.resolve(response(pageData));
      }

      if (action === 'll_aim_get_next') {
        state.nextCalls += 1;
        if (activeScenario === 'timeout' && state.nextCalls === 1) {
          return new Promise((resolve, reject) => {
            options.signal.addEventListener('abort', () => {
              state.aborts += 1;
              reject(new DOMException('Aborted', 'AbortError'));
            }, { once: true });
          });
        }

        const item = {
          id: 100 + state.nextCalls,
          title: `Word ${state.nextCalls}`,
          translation: '',
          audio_url: '',
          current_thumb: '',
          current_image_id: activeScenario === 'rematch-count' && state.nextCalls === 1 ? 1 : 0
        };
        if (activeScenario === 'pagination' && state.nextCalls > 1) {
          return delayedResponse({ item });
        }
        return Promise.resolve(response({ item }));
      }

      if (action === 'll_aim_assign') {
        state.assignCalls += 1;
        state.assignedImageIds.push(parseInt(body.get('image_id') || '0', 10) || 0);
        options.signal.addEventListener('abort', () => {
          state.aborts += 1;
        }, { once: true });
        if (activeScenario === 'assign-failure' && state.assignCalls === 1) {
          return Promise.reject(new Error('simulated assignment failure'));
        }
        return delayedResponse({}, activeScenario === 'assign-slow' ? 120 : 80);
      }

      throw new Error(`Unexpected action: ${action}`);
    };
  }, scenario);
  await page.addScriptTag({ content: matcherSource });
}

test('matcher serializes delayed Start, Skip, and image-page requests', async ({ page }) => {
  await mountMatcher(page, 'pagination');

  await page.locator('#ll-aim-start').evaluate((button) => {
    button.click();
    button.click();
  });
  await expect(page.locator('#ll-aim-status')).toHaveText('Loading images...');
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(2);
  expect(await page.evaluate(() => window.__llAimTest.imageCalls)).toBe(1);

  await page.locator('#ll-aim-skip').evaluate((button) => {
    button.click();
    button.click();
  });
  await expect(page.locator('#ll-aim-status')).toHaveText('Loading next audio...');
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');
  expect(await page.evaluate(() => window.__llAimTest.nextCalls)).toBe(2);

  await expect(page.locator('#ll-aim-load-more-images')).toBeVisible();
  await page.locator('#ll-aim-load-more-images').evaluate((button) => {
    button.click();
    button.click();
  });
  await expect(page.locator('#ll-aim-image-page-status')).toHaveText('Loading more...');
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(3);
  await expect(page.locator('#ll-aim-load-more-images')).toBeHidden();
  expect(await page.evaluate(() => window.__llAimTest.imageOffsets.slice())).toEqual([0, 2]);
});

test('matcher exposes an image-load error and retries the failed start', async ({ page }) => {
  await mountMatcher(page, 'image-failure');

  await page.locator('#ll-aim-start').click();
  await expect(page.locator('#ll-aim-status')).toHaveText('Could not load images.');
  await expect(page.locator('#ll-aim-retry')).toBeVisible();
  await expect(page.locator('#ll-aim-start')).toBeEnabled();

  await page.locator('#ll-aim-retry').click();
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(2);
  await expect(page.locator('#ll-aim-retry')).toBeHidden();
  expect(await page.evaluate(() => window.__llAimTest.imageCalls)).toBe(2);
});

test('matcher aborts a timed-out next-audio request and allows retry', async ({ page }) => {
  await mountMatcher(page, 'timeout');

  await page.locator('#ll-aim-start').click();
  await expect(page.locator('#ll-aim-status')).toHaveText('The request timed out. Please try again.');
  await expect(page.locator('#ll-aim-retry')).toBeVisible();
  expect(await page.evaluate(() => window.__llAimTest.aborts)).toBe(1);

  await page.locator('#ll-aim-retry').click();
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(2);
  expect(await page.evaluate(() => window.__llAimTest.nextCalls)).toBe(2);
});

test('matcher restores the current choice after a failed assignment and retries once', async ({ page }) => {
  await mountMatcher(page, 'assign-failure');
  await page.locator('#ll-aim-hide-used').uncheck();
  await page.locator('#ll-aim-start').click();

  const firstChoice = page.locator('.ll-aim-card[data-img-id="1"]');
  await firstChoice.click();
  await expect(page.locator('#ll-aim-status')).toHaveText('Error saving match.');
  await expect(page.locator('#ll-aim-retry')).toBeVisible();
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 1');
  await expect(firstChoice).toBeFocused();
  await expect(firstChoice).toBeEnabled();
  expect(await firstChoice.getAttribute('aria-pressed')).toBeNull();

  await page.locator('#ll-aim-retry').click();
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');
  expect(await page.evaluate(() => window.__llAimTest.assignCalls)).toBe(2);
});

test('slow mutating assignments stay serialized instead of timing out into an overlapping retry', async ({ page }) => {
  await mountMatcher(page, 'assign-slow');
  await page.locator('#ll-aim-hide-used').uncheck();
  await page.locator('#ll-aim-start').click();

  const firstChoice = page.locator('.ll-aim-card[data-img-id="1"]');
  await firstChoice.click();
  await expect(page.locator('#ll-aim-status')).toHaveText('Saving match...');
  await expect(firstChoice).toBeDisabled();
  await expect(page.locator('#ll-aim-retry')).toBeHidden();
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');

  expect(await page.evaluate(() => ({
    assignCalls: window.__llAimTest.assignCalls,
    aborts: window.__llAimTest.aborts
  }))).toEqual({ assignCalls: 1, aborts: 0 });
});

test('image choices are ordinary buttons activated once by Enter or Space', async ({ page }) => {
  await mountMatcher(page, 'keyboard');
  await page.locator('#ll-aim-hide-used').uncheck();
  await page.locator('#ll-aim-start').click();

  const firstChoice = page.locator('#ll-aim-images .ll-aim-card').first();
  await expect(firstChoice).toBeFocused();
  await expect(firstChoice).toHaveAttribute('aria-label', 'Choose image: Image One');
  expect(await firstChoice.getAttribute('aria-pressed')).toBeNull();

  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await expect(page.locator('#ll-aim-status')).toHaveText('Saving match...');
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');
  expect(await page.evaluate(() => window.__llAimTest.assignCalls)).toBe(1);
  expect(await page.locator('.ll-aim-card[data-img-id="1"]').getAttribute('aria-pressed')).toBeNull();

  const secondChoice = page.locator('.ll-aim-card[data-img-id="2"]');
  await expect(secondChoice).toBeFocused();
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 3');

  expect(await page.evaluate(() => ({
    calls: window.__llAimTest.assignCalls,
    ids: window.__llAimTest.assignedImageIds.slice()
  }))).toEqual({ calls: 2, ids: [1, 2] });
  expect(await page.locator('.ll-aim-card[data-img-id="2"]').getAttribute('aria-pressed')).toBeNull();
});

test('successful rematches refresh used counts for the old and new image choices', async ({ page }) => {
  await mountMatcher(page, 'rematch-count');
  await page.locator('#ll-aim-hide-used').uncheck();
  await page.locator('#ll-aim-start').click();

  await expect(page.locator('.ll-aim-card[data-img-id="1"]')).toHaveClass(/is-picked/);
  await page.locator('.ll-aim-card[data-img-id="2"]').click();
  await expect(page.locator('#ll-aim-word-title')).toHaveText('Word 2');

  await expect(page.locator('.ll-aim-card[data-img-id="1"]')).not.toHaveClass(/is-picked/);
  await expect(page.locator('.ll-aim-card[data-img-id="2"]')).toHaveClass(/is-picked/);
});
