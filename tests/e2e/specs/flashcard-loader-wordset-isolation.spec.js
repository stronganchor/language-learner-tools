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

test('flashcard loader retries retryable category AJAX 429 responses', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Category One'];
    window.getCategoryDisplayMode = function () { return 'text_title'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      preloadTuning: {
        categoryAjaxConcurrency: 1,
        categoryAjaxSpacingMs: 0,
        categoryAjaxMaxRetriesOn429: 1,
        categoryAjaxRetryBaseMs: 1,
        categoryAjaxRetryMaxMs: 10
      },
      categories: [
        { id: 11, name: 'Category One', prompt_type: 'text_title', option_type: 'text_title' }
      ]
    };

    window.__llAjaxAttempts = 0;
    const $ = window.jQuery;
    $.ajax = function (opts) {
      window.__llAjaxAttempts += 1;
      if (window.__llAjaxAttempts === 1) {
        setTimeout(() => {
          opts.error({
            status: 429,
            getResponseHeader(name) {
              return String(name || '').toLowerCase() === 'retry-after' ? '0' : '';
            },
            responseText: '{"success":false,"data":{"code":"cache_warming","retry_after":1}}'
          }, 'error', 'rate_limited');
        }, 0);
      } else {
        setTimeout(() => {
          opts.success({
            success: true,
            data: [
              {
                id: 1001,
                title: 'Word One',
                label: 'Word One',
                audio: '',
                image: '',
                audio_files: [],
                wordset_ids: [101]
              }
            ]
          });
        }, 0);
      }
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });

  const result = await page.evaluate(() => {
    return window.FlashcardLoader.loadResourcesForCategory('Category One', null, { skipCategoryPreload: true });
  });
  expect(result.success).toBe(true);

  const attempts = await page.evaluate(() => window.__llAjaxAttempts);
  expect(attempts).toBe(2);

  const activeWordIds = await page.evaluate(() => {
    const rows = window.wordsByCategory['Category One'] || [];
    return rows.map((row) => parseInt(row && row.id, 10) || 0).filter(Boolean);
  });
  expect(activeWordIds).toEqual([1001]);
});

test('flashcard loader preserves explicit listening word order when provided', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Numbers'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'numbers',
      wordsetIds: [101],
      wordsetFallback: false,
      sessionWordIds: [3, 1, 2],
      session_word_ids: [3, 1, 2],
      orderedWordIds: [3, 1, 2],
      ordered_word_ids: [3, 1, 2],
      preserveWordOrder: true,
      preserve_word_order: true,
      categories: [
        { id: 11, name: 'Numbers', prompt_type: 'audio', option_type: 'image' }
      ]
    };

    const $ = window.jQuery;
    $.ajax = function (opts) {
      window.__llLastWordsPayload = Object.assign({}, opts.data || {});
      setTimeout(() => {
        opts.success({
          success: true,
          data: [
            { id: 1, title: 'one', label: 'one', audio: 'one.mp3', image: '', audio_files: [], wordset_ids: [101] },
            { id: 2, title: 'two', label: 'two', audio: 'two.mp3', image: '', audio_files: [], wordset_ids: [101] },
            { id: 3, title: 'three', label: 'three', audio: 'three.mp3', image: '', audio_files: [], wordset_ids: [101] }
          ]
        });
      }, 0);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });

  await page.evaluate(() => window.FlashcardLoader.loadResourcesForCategory('Numbers'));

  await expect.poll(async () => {
    return await page.evaluate(() => (window.wordsByCategory.Numbers || []).map((word) => Number(word && word.id) || 0));
  }).toEqual([3, 1, 2]);

  const payloadCandidateIds = await page.evaluate(() => String((window.__llLastWordsPayload || {}).candidate_word_ids || ''));
  expect(payloadCandidateIds).toBe('3,1,2');
  const payloadIncludeOptionPool = await page.evaluate(() => String((window.__llLastWordsPayload || {}).include_option_pool || ''));
  const payloadOptionPoolLimit = await page.evaluate(() => String((window.__llLastWordsPayload || {}).option_pool_limit || ''));
  expect(payloadIncludeOptionPool).toBe('1');
  expect(payloadOptionPoolLimit).toBe('12');

  const optionIds = await page.evaluate(() => (window.optionWordsByCategory.Numbers || []).map((word) => Number(word && word.id) || 0));
  expect(optionIds).toEqual([3, 1, 2]);
});

test('flashcard loader keeps session targets scoped while preserving returned option-pool rows', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['New words'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'genc-palu',
      wordsetIds: [101],
      wordsetFallback: false,
      sessionWordIds: [84226],
      session_word_ids: [84226],
      categories: [
        { id: 11, name: 'New words', prompt_type: 'audio', option_type: 'image' }
      ]
    };

    const $ = window.jQuery;
    $.ajax = function (opts) {
      window.__llLastWordsPayload = Object.assign({}, opts.data || {});
      setTimeout(() => {
        opts.success({
          success: true,
          data: [
            { id: 84226, title: 'Target', label: 'Target', audio: 'target.mp3', image: 'target.jpg', audio_files: [], wordset_ids: [101] },
            { id: 84227, title: 'Distractor', label: 'Distractor', audio: 'distractor.mp3', image: 'distractor.jpg', audio_files: [], wordset_ids: [101] }
          ]
        });
      }, 0);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  await page.evaluate(() => window.FlashcardLoader.loadResourcesForCategory('New words'));

  await expect.poll(async () => {
    return await page.evaluate(() => (window.wordsByCategory['New words'] || []).map((word) => Number(word && word.id) || 0));
  }).toEqual([84226]);

  const optionIds = await page.evaluate(() => (window.optionWordsByCategory['New words'] || []).map((word) => Number(word && word.id) || 0).sort((a, b) => a - b));
  const payload = await page.evaluate(() => Object.assign({}, window.__llLastWordsPayload || {}));

  expect(optionIds).toEqual([84226, 84227]);
  expect(String(payload.candidate_word_ids || '')).toBe('84226');
  expect(String(payload.include_option_pool || '')).toBe('1');
});

test('flashcard loader keeps prompt-card support rows out of the target pool while preserving options', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['ASL basics'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'asl',
      wordsetIds: [101],
      wordsetFallback: false,
      categories: [
        { id: 11, name: 'ASL basics', prompt_type: 'image', option_type: 'image', sign_language_mode: true }
      ]
    };

    const $ = window.jQuery;
    $.ajax = function (opts) {
      setTimeout(() => {
        opts.success({
          success: true,
          data: [
            {
              id: 551,
              title: 'Tree sign',
              label: 'Tree sign',
              image: 'https://img.test/tree-sign.webp',
              wordset_ids: [101],
              is_prompt_card_support_only: true,
              is_prompt_card_prompt_image_support: true,
              prompt_card_support_roles: ['prompt'],
              prompt_card_support_owner_ids: [901]
            },
            {
              id: 552,
              title: 'Tree',
              label: 'Tree',
              image: 'https://img.test/tree-answer.jpg',
              wordset_ids: [101],
              is_prompt_card_support_only: true,
              is_prompt_card_answer_option_support: true,
              prompt_card_support_roles: ['correct'],
              prompt_card_support_owner_ids: [901]
            },
            {
              id: 901,
              title: 'Tree',
              label: 'Tree',
              image: 'https://img.test/tree-sign.webp',
              answer_image: 'https://img.test/tree-answer.jpg',
              wordset_ids: [101],
              is_prompt_card: true,
              prompt_card_id: 901,
              answer_word_id: 552
            }
          ]
        });
      }, 0);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  await page.evaluate(() => window.FlashcardLoader.loadResourcesForCategory('ASL basics'));

  await expect.poll(async () => {
    return await page.evaluate(() => (window.optionWordsByCategory['ASL basics'] || []).length);
  }).toBe(3);

  const targetIds = await page.evaluate(() => (window.wordsByCategory['ASL basics'] || []).map((word) => Number(word && word.id) || 0));
  const optionIds = await page.evaluate(() => (window.optionWordsByCategory['ASL basics'] || []).map((word) => Number(word && word.id) || 0).sort((a, b) => a - b));

  expect(targetIds).toEqual([901]);
  expect(optionIds).toEqual([551, 552, 901]);
});

test('flashcard loader can skip current-word audio preload when requested', async ({ page }) => {
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
        { id: 11, name: 'Category One', prompt_type: 'audio', option_type: 'image' }
      ]
    };

    const originalCreateElement = document.createElement.bind(document);
    window.__llAudioCreateCount = 0;
    document.createElement = function (tagName) {
      if (String(tagName || '').toLowerCase() === 'audio') {
        window.__llAudioCreateCount += 1;
      }
      return originalCreateElement(tagName);
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });

  const result = await page.evaluate(async () => {
    return await window.FlashcardLoader.loadResourcesForWord(
      {
        id: 1001,
        title: 'Word One',
        label: 'Word One',
        audio: 'https://example.test/audio-one.mp3',
        image: 'https://example.test/image-one.jpg',
        audio_files: [],
        wordset_ids: [101]
      },
      'image',
      'Category One',
      { prompt_type: 'audio', option_type: 'image' },
      { skipAudioPreload: true, skipImagePreload: true }
    );
  });

  const audioCreateCount = await page.evaluate(() => window.__llAudioCreateCount || 0);

  expect(audioCreateCount).toBe(0);
  expect(result).toMatchObject({
    ready: true,
    audioReady: true,
    imageReady: true
  });
  expect(result.audio && result.audio.skipped).toBeTruthy();
});
