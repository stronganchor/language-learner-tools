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
    window.FlashcardLoader.loadResourcesForCategory('Category One');
  });

  await expect.poll(async () => {
    return await page.evaluate(() => window.__llPendingAjax.length);
  }).toBe(2);

  const requestWordsets = await page.evaluate(() => {
    return window.__llPendingAjax.map((req) => String((req && req.data && req.data.wordset) || ''));
  });
  expect(requestWordsets).toEqual(['set-a', 'set-b']);

  await page.evaluate(() => {
    const staleRequest = window.__llPendingAjax[0];
    const currentRequest = window.__llPendingAjax[1];

    currentRequest.success({
      success: true,
      data: [
        {
          id: 2002,
          title: 'Current set word',
          label: 'Current set word',
          audio: 'https://example.test/audio-current.mp3',
          image: '',
          audio_files: [],
          wordset_ids: [202]
        }
      ]
    });

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

  const activeWordIds = await page.evaluate(() => {
    const rows = window.wordsByCategory['Category One'] || [];
    return rows.map((row) => parseInt(row && row.id, 10) || 0).filter(Boolean);
  });
  expect(activeWordIds).toEqual([2002]);
});
