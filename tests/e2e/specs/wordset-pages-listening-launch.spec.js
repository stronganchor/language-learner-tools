const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

function buildWordsetMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <div class="ll-wordset-grid">
        <button type="button" data-ll-wordset-start-mode data-mode="practice">Practice</button>
        <button type="button" data-ll-wordset-start-mode data-mode="listening">Listen</button>
        <button type="button" data-ll-wordset-select-all>Select all</button>
        <label><input type="checkbox" data-ll-wordset-select value="11" />Cat A</label>
        <label><input type="checkbox" data-ll-wordset-select value="22" />Cat B</label>
        <label><input type="checkbox" data-ll-wordset-select value="33" />Cat C</label>
      </div>

      <div data-ll-wordset-next-shell>
        <button type="button" data-ll-wordset-next>
          <span data-ll-wordset-next-icon></span>
          <span data-ll-wordset-next-preview></span>
          <span data-ll-wordset-next-text></span>
        </button>
        <span>
          <span data-ll-wordset-next-count hidden></span>
          <button type="button" data-ll-wordset-next-remove hidden>Remove</button>
        </span>
      </div>

      <div data-ll-wordset-selection-bar hidden>
        <span data-ll-wordset-selection-text>Select categories to study together</span>
        <label class="ll-wordset-selection-bar__starred-toggle">
          <input type="checkbox" data-ll-wordset-selection-starred-only />
          <span data-ll-wordset-selection-starred-icon>☆</span>
          <span data-ll-wordset-selection-starred-label>Starred only</span>
        </label>
        <label class="ll-wordset-selection-bar__hard-toggle" hidden>
          <input type="checkbox" data-ll-wordset-selection-hard-only />
          <span data-ll-wordset-selection-hard-icon>△</span>
          <span data-ll-wordset-selection-hard-label>Hard words only</span>
        </label>
        <button type="button" data-ll-wordset-selection-mode data-mode="listening">Selection Listen</button>
        <button type="button" data-ll-wordset-selection-clear>Clear</button>
      </div>
    </div>

    <div id="ll-study-results-actions" style="display:none;">
      <button id="ll-study-results-same-chunk" type="button" style="display:none;">Repeat</button>
      <button id="ll-study-results-different-chunk" type="button" style="display:none;">New words</button>
      <button id="ll-study-results-next-chunk" type="button" style="display:none;">Recommended</button>
    </div>
    <div id="ll-gender-results-actions" style="display:none;"></div>
    <button id="restart-quiz" type="button" style="display:none;">Restart</button>
    <div id="quiz-mode-buttons" style="display:none;"></div>

    <div id="ll-tools-flashcard-popup" style="display:none;"></div>
    <div id="ll-tools-flashcard-quiz-popup" style="display:none;"></div>
  `;
}

function buildCategoryWords() {
  return {
    11: [
      { id: 1101, title: 'A1', translation: 'A1', label: 'A1', audio: '', image: '', audio_files: [] },
      { id: 1102, title: 'A2', translation: 'A2', label: 'A2', audio: '', image: '', audio_files: [] }
    ],
    22: [
      { id: 2201, title: 'B1', translation: 'B1', label: 'B1', audio: '', image: '', audio_files: [] },
      { id: 2202, title: 'B2', translation: 'B2', label: 'B2', audio: '', image: '', audio_files: [] }
    ],
    33: [
      { id: 3301, title: 'C1', translation: 'C1', label: 'C1', audio: '', image: '', audio_files: [] },
      { id: 3302, title: 'C2', translation: 'C2', label: 'C2', audio: '', image: '', audio_files: [] }
    ]
  };
}

function buildPageConfig({ isLoggedIn }) {
  return {
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: isLoggedIn ? 'nonce-1' : '',
    isLoggedIn: !!isLoggedIn,
    wordsetId: 77,
    wordsetSlug: 'test-wordset',
    wordsetName: 'Test Wordset',
    links: {
      base: '/wordsets/test-wordset/',
      progress: '/wordsets/test-wordset/progress/',
      hidden: '/wordsets/test-wordset/hidden-categories/',
      settings: '/wordsets/test-wordset/settings/'
    },
    progressIncludeHidden: false,
    categories: [
      {
        id: 11,
        slug: 'cat-a',
        name: 'Cat A',
        translation: 'Cat A',
        count: 30,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        hidden: false,
        preview: []
      },
      {
        id: 22,
        slug: 'cat-b',
        name: 'Cat B',
        translation: 'Cat B',
        count: 30,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        hidden: false,
        preview: []
      },
      {
        id: 33,
        slug: 'cat-c',
        name: 'Cat C',
        translation: 'Cat C',
        count: 30,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        hidden: false,
        preview: []
      }
    ],
    visibleCategoryIds: [11, 22, 33],
    hiddenCategoryIds: [],
    state: {
      wordset_id: 77,
      category_ids: [],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    goals: {
      enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
      ignored_category_ids: [],
      preferred_wordset_ids: [77],
      placement_known_category_ids: [],
      daily_new_word_target: 0,
      priority_focus: ''
    },
    nextActivity: {
      mode: 'listening',
      category_ids: [11, 22, 33],
      session_word_ids: [1101, 2201],
      type: 'review_chunk',
      reason_code: 'review_chunk_balanced',
      details: { chunk_size: 2 }
    },
    recommendationQueue: [],
    analytics: {
      scope: {},
      summary: {},
      daily_activity: { days: [], max_events: 0, window_days: 14 },
      categories: [],
      words: []
    },
    modeUi: {},
    gender: {
      enabled: false,
      options: [],
      min_count: 2
    },
    summaryCounts: {
      mastered: 0,
      studied: 0,
      new: 0
    },
    hardWordDifficultyThreshold: 4,
    i18n: {
      selectionLabel: 'Select categories to study together',
      selectionWordsOnly: '%d words',
      selectAll: 'Select all',
      deselectAll: 'Deselect all',
      noCategoriesSelected: 'Select at least one category.',
      noWordsInSelection: 'No quiz words are available for this selection.',
      continueLabel: 'Continue',
      repeatLabel: 'Repeat',
      categoriesLabel: 'Categories'
    }
  };
}

async function mountWordsetPage(page, options = {}) {
  const isLoggedIn = !!options.isLoggedIn;
  const wordsByCategory = buildCategoryWords();
  let config = buildPageConfig({ isLoggedIn });
  if (options.configPatch && typeof options.configPatch === 'object') {
    config = Object.assign({}, config, options.configPatch);
  }
  const wordsByCategoryName = {
    'Cat A': wordsByCategory[11],
    'Cat B': wordsByCategory[22],
    'Cat C': wordsByCategory[33]
  };

  await page.goto('about:blank');
  await page.setContent(buildWordsetMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    window.llWordsetPageData = bootstrap.config;
    window.__llLaunches = [];
    window.__llAlerts = [];
    window.alert = function (message) {
      window.__llAlerts.push(String(message || ''));
    };

    window.initFlashcardWidget = function (catNames, mode) {
      const flash = window.llToolsFlashcardsData || {};
      const plan = (flash.lastLaunchPlan && typeof flash.lastLaunchPlan === 'object')
        ? flash.lastLaunchPlan
        : ((flash.last_launch_plan && typeof flash.last_launch_plan === 'object') ? flash.last_launch_plan : {});
      const userStudyState = (flash.userStudyState && typeof flash.userStudyState === 'object')
        ? flash.userStudyState
        : {};

      window.__llLaunches.push({
        mode: String(mode || ''),
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        sessionWordIds: Array.isArray(flash.sessionWordIds) ? flash.sessionWordIds.slice() : [],
        categoryIds: Array.isArray(plan.category_ids)
          ? plan.category_ids.slice()
          : (Array.isArray(userStudyState.category_ids) ? userStudyState.category_ids.slice() : []),
        source: String(plan.source || '')
      });
      return Promise.resolve();
    };

    const $ = window.jQuery;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_get_words_by_category') {
        const categoryName = String((request && request.category) || '');
        deferred.resolve({
          success: true,
          data: Array.isArray(bootstrap.wordsByCategoryName[categoryName]) ? bootstrap.wordsByCategoryName[categoryName] : []
        });
        return deferred.promise();
      }

      if (action === 'll_user_study_fetch_words') {
        deferred.resolve({
          success: true,
          data: {
            words_by_category: bootstrap.wordsByCategory
          }
        });
        return deferred.promise();
      }

      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: bootstrap.config.nextActivity,
            recommendation_queue: bootstrap.config.recommendationQueue || []
          }
        });
        return deferred.promise();
      }

      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, {
    config,
    wordsByCategory,
    wordsByCategoryName
  });

  await page.addScriptTag({ content: wordsetScriptSource });
}

test('logged-out select-all shows real word count and allows listening launch', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: false });

  await page.locator('[data-ll-wordset-select-all]').click();

  await expect.poll(async () => {
    return page.locator('[data-ll-wordset-selection-text]').innerText();
  }).toContain('90');

  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]')).toBeEnabled();

  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('listening');
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
});

test('logged-in listening launches ignore recommendation chunk IDs for top and selection starts', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: true });

  await page.locator('[data-ll-wordset-start-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const topLaunch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });

  expect(topLaunch).not.toBeNull();
  expect(topLaunch.mode).toBe('listening');
  expect(topLaunch.sessionWordIds).toEqual([]);
  expect(topLaunch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]')).toBeEnabled();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(2);

  const selectionLaunch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[1]) || null;
  });

  expect(selectionLaunch).not.toBeNull();
  expect(selectionLaunch.mode).toBe('listening');
  expect(selectionLaunch.sessionWordIds).toEqual([]);
  expect(selectionLaunch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
});

test('logged-in practice top launch falls back to visible categories when recommendation categories are stale', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: true,
    configPatch: {
      nextActivity: {
        mode: 'practice',
        category_ids: [9999],
        session_word_ids: [999901],
        type: 'review_chunk',
        reason_code: 'review_chunk_balanced',
        details: { chunk_size: 1 }
      },
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-start-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const launch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });
  const alerts = await page.evaluate(() => {
    return Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : [];
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
  expect(alerts).toEqual([]);
});
