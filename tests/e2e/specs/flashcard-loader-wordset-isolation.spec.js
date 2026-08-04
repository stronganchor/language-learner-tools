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

test('flashcard loader drops an invalidated queued category before AJAX starts', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Cancelled Category'];
    window.getCategoryDisplayMode = function () { return 'image'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      categories: [
        { id: 11, name: 'Cancelled Category', prompt_type: 'audio', option_type: 'image' }
      ]
    };
    window.__llAjaxCount = 0;
    window.jQuery.ajax = function () {
      window.__llAjaxCount += 1;
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(async () => {
    const response = await window.FlashcardLoader.loadResourcesForCategory(
      'Cancelled Category',
      function () {},
      { isRequestCurrent: function () { return false; } }
    );
    return { response, ajaxCount: window.__llAjaxCount };
  });

  expect(result.ajaxCount).toBe(0);
  expect(result.response).toMatchObject({ cancelled: true, category: 'Cancelled Category' });
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

test('flashcard loader times out a stalled category request and allows one clean relaunch', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Timeout Category'];
    window.getCategoryDisplayMode = function () { return 'text_title'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      preloadTuning: {
        categoryAjaxConcurrency: 1,
        categoryAjaxSpacingMs: 0,
        categoryAjaxTimeoutMs: 40
      },
      categories: [
        { id: 11, name: 'Timeout Category', prompt_type: 'text_title', option_type: 'text_title' }
      ]
    };

    window.__llTimeoutAttempts = 0;
    window.__llTimeoutValues = [];
    window.jQuery.ajax = function (opts) {
      window.__llTimeoutAttempts += 1;
      window.__llTimeoutValues.push(Number(opts.timeout) || 0);
      const attempt = window.__llTimeoutAttempts;
      if (attempt === 1) {
        window.setTimeout(() => {
          opts.error({ status: 0, responseText: '' }, 'timeout', 'timeout');
        }, Number(opts.timeout) || 0);
      } else {
        window.setTimeout(() => {
          opts.success({
            success: true,
            data: [{
              id: 1001,
              title: 'Recovered word',
              label: 'Recovered word',
              audio: '',
              image: '',
              audio_files: [],
              wordset_ids: [101]
            }]
          });
        }, 0);
      }
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const state = await page.evaluate(async () => {
    let callbackCount = 0;
    const first = await window.FlashcardLoader.loadResourcesForCategory(
      'Timeout Category',
      function () { callbackCount += 1; },
      { skipCategoryPreload: true }
    );
    const loadingAfterTimeout = window.FlashcardLoader.isCategoryLoading('Timeout Category');
    const second = await window.FlashcardLoader.loadResourcesForCategory(
      'Timeout Category',
      null,
      { skipCategoryPreload: true }
    );
    return {
      first,
      second,
      callbackCount,
      loadingAfterTimeout,
      loadingAfterRelaunch: window.FlashcardLoader.isCategoryLoading('Timeout Category'),
      attempts: window.__llTimeoutAttempts,
      timeoutValues: window.__llTimeoutValues.slice(),
      wordIds: (window.wordsByCategory['Timeout Category'] || []).map((row) => Number(row.id) || 0)
    };
  });

  expect(state.first).toMatchObject({ success: false, timedOut: true, category: 'Timeout Category' });
  expect(state.second).toMatchObject({ success: true, category: 'Timeout Category' });
  expect(state.callbackCount).toBe(1);
  expect(state.loadingAfterTimeout).toBe(false);
  expect(state.loadingAfterRelaunch).toBe(false);
  expect(state.attempts).toBe(2);
  expect(state.timeoutValues).toEqual([250, 250]);
  expect(state.wordIds).toEqual([1001]);
});

test('flashcard loader drains immutable payload pages and preserves the rendered locale', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Paged Category'];
    window.getCategoryDisplayMode = function () { return 'text_title'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      sortLocale: 'tr_TR',
      preloadTuning: {
        categoryAjaxConcurrency: 1,
        categoryAjaxSpacingMs: 0
      },
      categories: [
        { id: 11, name: 'Paged Category', slug: 'paged-category', prompt_type: 'text_title', option_type: 'text_title' }
      ]
    };

    window.__llPayloadRequests = [];
    window.jQuery.ajax = function (opts) {
      const request = Object.assign({}, opts.data || {});
      window.__llPayloadRequests.push(request);
      const rowId = request.cursor ? 1002 : 1001;
      setTimeout(() => {
        opts.success({
          success: true,
          data: {
            schema: 1,
            rows: [{
              id: rowId,
              title: `Word ${rowId}`,
              label: `Word ${rowId}`,
              audio: '',
              image: '',
              audio_files: [],
              wordset_ids: [101]
            }],
            next_cursor: request.cursor ? '' : 'signed-page-2',
            complete: !!request.cursor
          }
        });
      }, 0);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(() => {
    return window.FlashcardLoader.loadResourcesForCategory(
      'Paged Category',
      null,
      { skipCategoryPreload: true }
    );
  });

  expect(result.success).toBe(true);
  const state = await page.evaluate(() => ({
    requests: window.__llPayloadRequests,
    ids: (window.wordsByCategory['Paged Category'] || [])
      .map((row) => Number(row.id) || 0)
      .sort((a, b) => a - b)
  }));
  expect(state.ids).toEqual([1001, 1002]);
  expect(state.requests).toHaveLength(2);
  expect(state.requests[0]).toMatchObject({
    action: 'll_get_flashcard_payload_page',
    category_id: 11,
    locale: 'tr_TR'
  });
  expect(state.requests[0].cursor || '').toBe('');
  expect(state.requests[1].cursor).toBe('signed-page-2');
});

test('flashcard loader restarts once after a stale page cursor without mixing generations', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Restart Category'];
    window.getCategoryDisplayMode = function () { return 'text_title'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'set-a',
      wordsetIds: [101],
      wordsetFallback: false,
      preloadTuning: {
        categoryAjaxConcurrency: 1,
        categoryAjaxSpacingMs: 0
      },
      categories: [
        { id: 11, name: 'Restart Category', prompt_type: 'text_title', option_type: 'text_title' }
      ]
    };

    window.__llRestartAttempts = 0;
    window.jQuery.ajax = function (opts) {
      window.__llRestartAttempts += 1;
      const attempt = window.__llRestartAttempts;
      setTimeout(() => {
        if (attempt === 1) {
          opts.success({
            success: true,
            data: {
              rows: [{
                id: 1001,
                title: 'Old generation word',
                label: 'Old generation word',
                audio: '',
                image: '',
                audio_files: [],
                wordset_ids: [101]
              }],
              next_cursor: 'old-generation-cursor'
            }
          });
          return;
        }
        if (attempt === 2) {
          opts.error({
            status: 409,
            responseJSON: {
              success: false,
              data: { code: 'restart_required' }
            },
            responseText: ''
          }, 'error', 'conflict');
          return;
        }
        opts.success({
          success: true,
          data: {
            rows: [{
              id: 2001,
              title: 'New generation word',
              label: 'New generation word',
              audio: '',
              image: '',
              audio_files: [],
              wordset_ids: [101]
            }],
            next_cursor: ''
          }
        });
      }, 0);
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(() => {
    return window.FlashcardLoader.loadResourcesForCategory(
      'Restart Category',
      null,
      { skipCategoryPreload: true }
    );
  });

  expect(result.success).toBe(true);
  expect(await page.evaluate(() => window.__llRestartAttempts)).toBe(3);
  expect(await page.evaluate(() => {
    return (window.wordsByCategory['Restart Category'] || []).map((row) => Number(row.id) || 0);
  })).toEqual([2001]);
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

test('bounded category handoff keeps canonical prompt-card targets and performs no category AJAX', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Prompt cards', 'Regular words'];
    window.getCategoryDisplayMode = function () { return 'text'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'bounded-set',
      wordsetIds: [101],
      wordsetFallback: false,
      boundedSelectionPlan: true,
      sessionWordIds: [501, 602],
      logicalSessionWordIds: [501, 602, 703, 704],
      categories: [
        { id: 11, name: 'Prompt cards', prompt_type: 'text_title', option_type: 'text_title' },
        { id: 22, name: 'Regular words', prompt_type: 'text_title', option_type: 'text_title' }
      ],
      boundedCandidateRowsByCategoryId: {
        11: [
          {
            id: 501,
            title: 'Prompt support',
            label: 'Prompt support',
            wordset_ids: [101],
            is_prompt_card_support_only: true,
            prompt_card_support_owner_ids: [9001]
          },
          {
            id: 502,
            title: 'Prompt wrong option',
            label: 'Prompt wrong option',
            wordset_ids: [101],
            is_specific_wrong_answer_only: true,
            specific_wrong_answer_owner_ids: [9001]
          },
          {
            id: 9001,
            answer_word_id: 501,
            progress_word_id: 501,
            title: 'Canonical prompt target',
            label: 'Canonical prompt target',
            wordset_ids: [101],
            is_prompt_card: true,
            specific_wrong_answer_ids: [502]
          }
        ],
        22: [
          { id: 602, title: 'Regular target', label: 'Regular target', wordset_ids: [101] },
          { id: 699, title: 'Option only', label: 'Option only', wordset_ids: [101] }
        ]
      },
      sessionWordIdsByCategoryId: {
        11: [501],
        22: [602]
      }
    };
    window.__llAjaxCount = 0;
    window.jQuery.ajax = function () {
      window.__llAjaxCount += 1;
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(async () => {
    const consumed = await window.FlashcardLoader.consumeBoundedPreloadedCategoryData([
      'Prompt cards',
      'Regular words'
    ]);
    const promptLoad = await window.FlashcardLoader.loadResourcesForCategory('Prompt cards');
    const regularLoad = await window.FlashcardLoader.loadResourcesForCategory('Regular words');
    return {
      consumed,
      promptLoad,
      regularLoad,
      logicalSessionWordIds: window.llToolsFlashcardsData.logicalSessionWordIds.slice(),
      ajaxCount: window.__llAjaxCount,
      promptTargets: (window.wordsByCategory['Prompt cards'] || []).map((row) => ({
        id: Number(row.id) || 0,
        answerWordId: Number(row.answer_word_id) || 0,
        progressWordId: Number(row.progress_word_id) || 0
      })),
      regularTargets: (window.wordsByCategory['Regular words'] || []).map((row) => Number(row.id) || 0),
      promptOptions: (window.optionWordsByCategory['Prompt cards'] || []).map((row) => Number(row.id) || 0).sort((a, b) => a - b),
      regularOptions: (window.optionWordsByCategory['Regular words'] || []).map((row) => Number(row.id) || 0).sort((a, b) => a - b)
    };
  });

  expect(result.consumed).toMatchObject({
    success: true,
    boundedPreloaded: true,
    categories: ['Prompt cards', 'Regular words'],
    sessionWordIds: [501, 602]
  });
  expect(result.logicalSessionWordIds).toEqual([501, 602, 703, 704]);
  expect(result.promptLoad).toMatchObject({ cached: true, category: 'Prompt cards' });
  expect(result.regularLoad).toMatchObject({ cached: true, category: 'Regular words' });
  expect(result.ajaxCount).toBe(0);
  expect(result.promptTargets).toEqual([{ id: 9001, answerWordId: 501, progressWordId: 501 }]);
  expect(result.regularTargets).toEqual([602]);
  expect(result.promptOptions).toEqual([501, 502, 9001]);
  expect(result.regularOptions).toEqual([602, 699]);

  const continued = await page.evaluate(async () => {
    const flash = window.llToolsFlashcardsData;
    flash.sessionWordIds = [703, 704];
    flash.boundedCandidateRowsByCategoryId = {
      11: [
        { id: 703, title: 'Next prompt target', label: 'Next prompt target', wordset_ids: [101] },
        { id: 705, title: 'Next prompt option', label: 'Next prompt option', wordset_ids: [101] }
      ],
      22: [
        { id: 704, title: 'Next regular target', label: 'Next regular target', wordset_ids: [101] },
        { id: 706, title: 'Next regular option', label: 'Next regular option', wordset_ids: [101] }
      ]
    };
    flash.sessionWordIdsByCategoryId = { 11: [703], 22: [704] };
    const consumed = await window.FlashcardLoader.consumeBoundedPreloadedCategoryData([
      'Prompt cards',
      'Regular words'
    ]);
    return {
      consumed,
      logicalSessionWordIds: flash.logicalSessionWordIds.slice(),
      promptTargets: (window.wordsByCategory['Prompt cards'] || []).map((row) => Number(row.id) || 0),
      regularTargets: (window.wordsByCategory['Regular words'] || []).map((row) => Number(row.id) || 0),
      ajaxCount: window.__llAjaxCount
    };
  });
  expect(continued.consumed.sessionWordIds).toEqual([703, 704]);
  expect(continued.logicalSessionWordIds).toEqual([501, 602, 703, 704]);
  expect(continued.promptTargets).toEqual([703]);
  expect(continued.regularTargets).toEqual([704]);
  expect(continued.ajaxCount).toBe(0);
});

test('incomplete bounded category handoff rejects before any AJAX fallback', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Category One', 'Category Two'];
    window.getCategoryDisplayMode = function () { return 'text'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'bounded-set',
      wordsetIds: [101],
      wordsetFallback: false,
      bounded_selection_plan: true,
      session_word_ids: [501, 602],
      categories: [
        { id: 11, name: 'Category One', prompt_type: 'text_title', option_type: 'text_title' },
        { id: 22, name: 'Category Two', prompt_type: 'text_title', option_type: 'text_title' }
      ],
      bounded_candidate_rows_by_category_id: {
        11: [{ id: 501, title: 'Word One', label: 'Word One', wordset_ids: [101] }]
      },
      session_word_ids_by_category_id: {
        11: [501],
        22: [602]
      }
    };
    window.__llAjaxCount = 0;
    window.jQuery.ajax = function () {
      window.__llAjaxCount += 1;
      return { abort: function () {} };
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(async () => {
    let rejection = null;
    try {
      await window.FlashcardLoader.consumeBoundedPreloadedCategoryData(['Category One', 'Category Two']);
    } catch (error) {
      rejection = { code: String(error && error.code || ''), message: String(error && error.message || '') };
    }
    const fallback = await window.FlashcardLoader.loadResourcesForCategory('Category One');
    return { rejection, fallback, ajaxCount: window.__llAjaxCount };
  });

  expect(result.rejection).toMatchObject({ code: 'll_bounded_preload_invalid' });
  expect(result.rejection.message).toContain('missing');
  expect(result.fallback).toMatchObject({
    success: false,
    boundedPreloadRequired: true,
    code: 'll_bounded_preload_required'
  });
  expect(result.ajaxCount).toBe(0);
});

test('target-only bounded category handoff rejects atomically before quiz setup', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = { Solo: 7 };
    window.categoryNames = ['Solo'];
    window.getCategoryDisplayMode = function () { return 'text'; };
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      wordset: 'bounded-set',
      wordsetIds: [101],
      wordsetFallback: false,
      boundedSelectionPlan: true,
      sessionWordIds: [501],
      categories: [
        { id: 11, name: 'Solo', prompt_type: 'text_title', option_type: 'text_title' }
      ],
      boundedCandidateRowsByCategoryId: {
        11: [
          { id: 501, title: 'Target', label: 'Target', wordset_ids: [101] },
          { id: 502, title: 'Valid option', label: 'Valid option', wordset_ids: [101] }
        ]
      },
      sessionWordIdsByCategoryId: { 11: [501] }
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(async () => {
    await window.FlashcardLoader.consumeBoundedPreloadedCategoryData(['Solo']);
    window.llToolsFlashcardsData.boundedCandidateRowsByCategoryId = {
      11: [{ id: 501, title: 'Only target', label: 'Only target', wordset_ids: [101] }]
    };
    let rejection = null;
    try {
      await window.FlashcardLoader.consumeBoundedPreloadedCategoryData(['Solo']);
    } catch (error) {
      rejection = {
        code: String(error && error.code || ''),
        message: String(error && error.message || ''),
        details: error && error.details ? Object.assign({}, error.details) : {}
      };
    }
    return {
      rejection,
      targetIds: (window.wordsByCategory.Solo || []).map((row) => Number(row.id) || 0),
      optionIds: (window.optionWordsByCategory.Solo || []).map((row) => Number(row.id) || 0).sort((a, b) => a - b),
      roundCount: Number(window.categoryRoundCount.Solo) || 0
    };
  });

  expect(result.rejection).toMatchObject({
    code: 'll_bounded_preload_invalid',
    details: { availableOptions: 1, requiredOptions: 2 }
  });
  expect(result.rejection.message).toContain('fewer than two');
  expect(result.targetIds).toEqual([501]);
  expect(result.optionIds).toEqual([501, 502]);
  expect(result.roundCount).toBe(7);
});

test('bounded target-only handoff accepts only runtime-usable text fallbacks', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Fallbacks'];
    window.getCategoryDisplayMode = function () { return 'text'; };
    window.llToolsFlashcardsData = {
      wordset: 'bounded-set',
      wordsetIds: [101],
      wordsetFallback: false,
      boundedSelectionPlan: true,
      sessionWordIds: [601],
      categories: [
        { id: 11, name: 'Fallbacks', prompt_type: 'text_title', option_type: 'image' }
      ],
      boundedCandidateRowsByCategoryId: {},
      sessionWordIdsByCategoryId: { 11: [601] }
    };
  });

  await page.addScriptTag({ content: loaderScriptSource });
  const result = await page.evaluate(async () => {
    const flash = window.llToolsFlashcardsData;
    const category = flash.categories[0];
    const tryConsume = async function (optionType, wrongTexts, rowValues) {
      const values = rowValues && typeof rowValues === 'object' ? rowValues : {};
      category.option_type = optionType;
      flash.boundedCandidateRowsByCategoryId = {
        11: [{
          id: 601,
          title: String(values.title || 'Only target'),
          label: String(values.label || 'Only target'),
          translation: String(values.translation || ''),
          image: optionType === 'image' ? 'target.jpg' : '',
          wordset_ids: [101],
          specific_wrong_answer_texts: wrongTexts.slice()
        }]
      };
      try {
        const value = await window.FlashcardLoader.consumeBoundedPreloadedCategoryData(['Fallbacks']);
        return { status: 'fulfilled', value };
      } catch (error) {
        return {
          status: 'rejected',
          code: String(error && error.code || ''),
          availableOptions: Number(error && error.details && error.details.availableOptions) || 0
        };
      }
    };

    return {
      imageWithText: await tryConsume('image', ['Different text']),
      textMatchingTitle: await tryConsume('text_title', ['Only target']),
      translationMatchingOption: await tryConsume('text_translation', ['day'], {
        title: 'roj',
        label: 'day',
        translation: 'day'
      }),
      textWithDistinctFallback: await tryConsume('text_title', ['Different text'])
    };
  });

  expect(result.imageWithText).toEqual({
    status: 'rejected',
    code: 'll_bounded_preload_invalid',
    availableOptions: 1
  });
  expect(result.textMatchingTitle).toEqual({
    status: 'rejected',
    code: 'll_bounded_preload_invalid',
    availableOptions: 1
  });
  expect(result.translationMatchingOption).toEqual({
    status: 'rejected',
    code: 'll_bounded_preload_invalid',
    availableOptions: 1
  });
  expect(result.textWithDistinctFallback).toMatchObject({
    status: 'fulfilled',
    value: { success: true, boundedPreloaded: true }
  });
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
