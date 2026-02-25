const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const loaderScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/loader.js'),
  'utf8'
);

test('flashcard loader ignores stale category responses from a previous wordset', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Category One'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      categories: [
        {
          id: 11,
          name: 'Category One',
          prompt_type: 'audio',
          option_type: 'image'
        }
      ]
    };

    window.__llPendingAjax = [];
    const $ = window.jQuery;
    $.ajax = function (opts) {
      window.__llPendingAjax.push(opts);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });

  await page.evaluate(() => {
    window.FlashcardLoader.loadResourcesForCategory('Category One');

    window.llToolsFlashcardsData.wordset = 'set-b';
    window.llToolsFlashcardsData.wordsetIds = [202];
  });

  await page.waitForTimeout(80);

  const pendingCount = await page.evaluate(() => window.__llPendingAjax.length);
  expect([0, 1]).toContain(pendingCount);

  if (pendingCount === 1) {
    await page.evaluate(() => {
      const staleRequest = window.__llPendingAjax[0];

      staleRequest.success({
        success: true,
        data: [
          {
            id: 1001,
            title: 'Stale set word',
            label: 'Stale set word',
            audio: 'https://example.test/audio-stale.mp3',
            image: '',
            audio_files: [],
            wordset_ids: [202]
          }
        ]
      });
    });
  }

  await page.waitForTimeout(40);

  const activeWordIds = await page.evaluate(() => {
    const rows = window.wordsByCategory['Category One'] || [];
    return rows.map((row) => parseInt(row && row.id, 10) || 0).filter(Boolean);
  });
  expect(activeWordIds).toEqual([]);
});

test('flashcard loader serializes category AJAX preloads by default to avoid request bursts', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Category One', 'Category Two'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      categories: [
        { id: 11, name: 'Category One', prompt_type: 'audio', option_type: 'image' },
        { id: 12, name: 'Category Two', prompt_type: 'audio', option_type: 'image' }
      ]
    };

    window.__llPendingAjax = [];
    const $ = window.jQuery;
    $.ajax = function (opts) {
      window.__llPendingAjax.push(opts);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });

  await page.evaluate(() => {
    window.FlashcardLoader.loadResourcesForCategory('Category One');
    window.FlashcardLoader.loadResourcesForCategory('Category Two');
  });

  await expect.poll(async () => {
    return await page.evaluate(() => window.__llPendingAjax.length);
  }).toBe(1);

  await page.evaluate(() => {
    const firstRequest = window.__llPendingAjax[0];
    firstRequest.success({
      success: true,
      data: [
        {
          id: 1001,
          title: 'Word One',
          label: 'Word One',
          audio: 'https://example.test/audio-one.mp3',
          image: '',
          audio_files: [],
          wordset_ids: [101]
        }
      ]
    });
  });

  await expect.poll(async () => {
    return await page.evaluate(() => window.__llPendingAjax.length);
  }).toBe(2);
});
