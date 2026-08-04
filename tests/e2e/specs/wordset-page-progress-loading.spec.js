const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

function buildCardProgressWidths({ masteredWords = 0, studiedWords = 0, newWords = 20, totalWords = 20 } = {}) {
  const total = Math.max(1, Number(totalWords) || 20);
  const mastered = Math.max(0, Number(masteredWords) || 0);
  const studiedTotal = Math.max(mastered, Number(studiedWords) || 0);
  const studied = Math.max(0, studiedTotal - mastered);
  const fresh = Math.max(0, Number(newWords) || 0);
  const toPercent = (value) => `${Math.round(((value * 100) / total) * 100) / 100}%`;

  return {
    mastered: toPercent(mastered),
    studied: toPercent(studied),
    new: toPercent(fresh)
  };
}

function buildAnalytics({
  totalWords = 20,
  masteredWords = 0,
  studiedWords = 0,
  newWords = 20,
  hardWords = 0,
  starredWords = 0,
  categoryId = 11,
  label = 'Cat A',
  words = [],
  wordIds = [],
  wordsPagination = null,
  wordFilterCounts = null
} = {}) {
  return {
    scope: {
      wordset_id: 77,
      category_ids: [categoryId],
      category_count: 1,
      mode: 'all'
    },
    summary: {
      total_words: totalWords,
      mastered_words: masteredWords,
      studied_words: studiedWords,
      new_words: newWords,
      hard_words: hardWords,
      starred_words: starredWords
    },
    daily_activity: {
      days: [],
      max_events: 0,
      window_days: 14
    },
    categories: [
      {
        id: categoryId,
        label,
        word_count: totalWords,
        mastered_words: masteredWords,
        studied_words: studiedWords,
        new_words: newWords,
        exposure_total: 0,
        exposure_by_mode: {
          learning: 0,
          practice: 0,
          listening: 0,
          gender: 0,
          'self-check': 0
        },
        last_mode: 'practice',
        last_seen_at: ''
      }
    ],
    words,
    word_ids: wordIds,
    word_filter_counts: wordFilterCounts || {},
    words_pagination: wordsPagination || {
      enabled: false,
      total: Array.isArray(words) ? words.length : 0,
      offset: 0,
      limit: 0,
      loaded: Array.isArray(words) ? words.length : 0,
      next_offset: null,
      has_more: false
    },
    generated_at: '2026-03-10T00:00:00Z'
  };
}

function buildProgressWords(startId, count, options = {}) {
  const starredIds = new Set((options.starredIds || []).map((value) => Number(value) || 0));
  return Array.from({ length: count }, (_unused, index) => {
    const id = startId + index;
    return {
      id,
      title: `Progress Word ${id}`,
      translation: `Translation ${id}`,
      label: `Progress Word ${id}`,
      image: '',
      audio_url: '',
      audio_recording_type: '',
      category_id: 11,
      category_label: 'Cat A',
      category_url: '#',
      category_ids: [11],
      category_labels: ['Cat A'],
      category_urls: ['#'],
      part_of_speech_slug: '',
      part_of_speech_label: '',
      part_of_speech_abbreviation: '',
      status: id <= 12 ? 'studied' : 'new',
      difficulty_score: id % 5,
      total_coverage: id <= 12 ? 2 : 0,
      incorrect: id % 3,
      lapse_count: 0,
      last_seen_at: '',
      is_starred: starredIds.has(id),
      prompt_blocked: false,
      normalized_grammatical_gender: '',
      gender_marked: false,
      gender_progress_tracked: false,
      gender_eligible: false,
      gender_level: 0,
      gender_seen_total: 0,
      gender_last_seen_at: '',
      gender_progress: {}
    };
  });
}

function buildWordsetMarkup(options = {}) {
  const cardMarginTop = Number.isFinite(options.cardMarginTop) ? options.cardMarginTop : 1800;
  const summaryCounts = Object.assign({
    mastered: 0,
    studied: 0,
    new: 20,
    starred: 0,
    hard: 0
  }, options.summaryCounts || {});
  const progressWidths = Object.assign({
    mastered: '0%',
    studied: '0%',
    new: '100%'
  }, options.progressWidths || {});
  const summaryCountsDeferred = options.summaryCountsDeferred !== false;
  const trackClass = options.trackLoading === false
    ? 'll-wordset-card__progress-track'
    : 'll-wordset-card__progress-track is-loading';

  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <a
        class="ll-wordset-progress-mini${summaryCountsDeferred ? ' is-loading' : ''}"
        data-ll-wordset-progress-mini-root
        href="#"
        aria-label="Open progress"
        ${summaryCountsDeferred ? 'aria-busy="true"' : ''}
      >
        <span class="ll-wordset-progress-pill ll-wordset-progress-pill--mastered">
          <span class="ll-wordset-progress-pill__icon" aria-hidden="true"></span>
          <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-mastered>${summaryCounts.mastered}</span>
        </span>
        <span class="ll-wordset-progress-pill ll-wordset-progress-pill--studied">
          <span class="ll-wordset-progress-pill__icon" aria-hidden="true"></span>
          <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-studied>${summaryCounts.studied}</span>
        </span>
        <span class="ll-wordset-progress-pill ll-wordset-progress-pill--new">
          <span class="ll-wordset-progress-pill__icon" aria-hidden="true"></span>
          <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-new>${summaryCounts.new}</span>
        </span>
        <span class="ll-wordset-progress-pill ll-wordset-progress-pill--starred">
          <span class="ll-wordset-progress-pill__icon" aria-hidden="true"></span>
          <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-starred>${summaryCounts.starred}</span>
        </span>
        <span class="ll-wordset-progress-pill ll-wordset-progress-pill--hard">
          <span class="ll-wordset-progress-pill__icon" aria-hidden="true"></span>
          <span class="ll-wordset-progress-pill__value" data-ll-progress-mini-hard>${summaryCounts.hard}</span>
        </span>
      </a>

      <div class="ll-wordset-grid">
        <button type="button" data-ll-wordset-select-all>Select all</button>
        <article class="ll-wordset-card" data-cat-id="11" style="margin-top: ${cardMarginTop}px;">
          <label>
            <input type="checkbox" data-ll-wordset-select value="11" />
            Cat A
          </label>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="${trackClass}">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered" style="width: ${progressWidths.mastered};"></span>
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied" style="width: ${progressWidths.studied};"></span>
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: ${progressWidths.new};"></span>
            </span>
          </div>
        </article>
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
          <span data-ll-wordset-selection-hard-icon></span>
          <span data-ll-wordset-selection-hard-label>Hard words only</span>
        </label>
        <button type="button" data-ll-wordset-selection-mode data-mode="practice">Selection Practice</button>
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

function buildProgressPageMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="progress" data-ll-wordset-id="77">
      <section class="ll-wordset-progress-view" data-ll-wordset-progress-root>
        <div data-ll-wordset-progress-status></div>
        <div data-ll-wordset-progress-scope></div>
        <div class="ll-wordset-progress-summary is-loading" data-ll-wordset-progress-summary aria-busy="true">
          ${['mastered', 'studied', 'new', 'starred', 'hard'].map((key) => `
            <div class="ll-wordset-progress-kpi ll-wordset-progress-kpi--${key} is-loading">
              <span class="ll-wordset-progress-kpi-icon-wrap"></span>
              <span class="ll-wordset-progress-kpi-value"></span>
              <span class="ll-wordset-progress-kpi-label">${key}</span>
            </div>
          `).join('')}
        </div>
        <div data-ll-wordset-progress-graph></div>

        <div role="tablist">
          <button type="button" data-ll-wordset-progress-tab="categories">Categories</button>
          <button type="button" data-ll-wordset-progress-tab="words">Words</button>
        </div>

        <div data-ll-wordset-progress-panel="categories">
          <input type="search" data-ll-wordset-progress-category-search />
          <span data-ll-wordset-progress-category-search-loading hidden></span>
          <table class="ll-wordset-progress-table">
            <tbody data-ll-wordset-progress-categories-body></tbody>
          </table>
        </div>

        <div data-ll-wordset-progress-panel="words" hidden>
          <input type="search" data-ll-wordset-progress-search />
          <span data-ll-wordset-progress-search-loading hidden></span>
          <button type="button" data-ll-wordset-progress-clear-filters hidden>Clear</button>
          <button type="button" data-ll-wordset-progress-select-all>Select all</button>
          <div data-ll-wordset-progress-column-filter-options="star"></div>
          <div data-ll-wordset-progress-column-filter-options="status"></div>
          <div data-ll-wordset-progress-column-filter-options="difficulty"></div>
          <div data-ll-wordset-progress-column-filter-options="seen"></div>
          <div data-ll-wordset-progress-column-filter-options="wrong"></div>
          <div data-ll-wordset-progress-category-filter-options></div>
          <div class="ll-wordset-progress-table-wrap" style="max-height: 40px; overflow: auto;">
            <table class="ll-wordset-progress-table ll-wordset-progress-table--words">
              <tbody data-ll-wordset-progress-words-body></tbody>
            </table>
          </div>
          <div class="ll-wordset-progress-words-more" data-ll-wordset-progress-words-more hidden>
            <span class="ll-wordset-progress-words-more__status" data-ll-wordset-progress-words-loaded></span>
            <button type="button" class="ll-wordset-progress-words-more__button" data-ll-wordset-progress-words-load-more>
              Load more words
            </button>
          </div>
          <div data-ll-wordset-progress-selection-bar hidden>
            <span data-ll-wordset-progress-selection-count></span>
            <span data-ll-wordset-progress-launch-feedback role="status" aria-live="polite" aria-atomic="true" hidden>
              <span data-ll-wordset-progress-launch-message></span>
              <button type="button" data-ll-wordset-progress-launch-retry hidden>Retry</button>
            </span>
            <button type="button" data-ll-wordset-progress-selection-mode data-mode="learning">Learn</button>
            <button type="button" data-ll-wordset-progress-selection-mode data-mode="practice">Practice</button>
            <button type="button" data-ll-wordset-progress-selection-mode data-mode="listening">Listen</button>
            <button type="button" data-ll-wordset-progress-selection-clear>Clear</button>
          </div>
        </div>
      </section>
    </div>

    <div id="ll-study-results-actions" style="display:none;"></div>
    <div id="ll-gender-results-actions" style="display:none;"></div>
    <button id="restart-quiz" type="button" style="display:none;">Restart</button>
    <div id="quiz-mode-buttons" style="display:none;"></div>
    <div id="ll-tools-flashcard-popup" style="display:none; position:fixed; inset:0; width:100vw; height:100vh;">
      <div
        id="ll-tools-flashcard-quiz-popup"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ll-tools-flashcard-dialog-title"
        aria-hidden="true"
        tabindex="-1"
        style="display:none; position:fixed; inset:0; width:100vw; height:100vh;"
      >
        <h2 id="ll-tools-flashcard-dialog-title">Quiz</h2>
        <button id="ll-tools-close-flashcard" type="button">Close</button>
        <div
          id="ll-tools-loading-animation"
          class="ll-tools-loading-animation"
          aria-hidden="true"
          style="display:none; width:40px; height:40px;"
        ></div>
        <div id="ll-tools-loading-status" role="status" aria-live="polite" hidden>Loading quiz...</div>
      </div>
    </div>
  `;
}

function buildPageConfig(overrides = {}) {
  const config = {
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: 'nonce-1',
    isLoggedIn: true,
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
        count: 20,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      }
    ],
    visibleCategoryIds: [11],
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
    nextActivity: null,
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
      new: 20,
      starred: 0,
      hard: 0
    },
    summaryCountsDeferred: true,
    progressWordPageSize: 80,
    selectionLaunchRequestTimeoutMs: 30000,
    hardWordDifficultyThreshold: 4,
    i18n: {
      selectionLabel: 'Select categories to study together',
      selectionWordsOnly: '%d words',
      selectAll: 'Select all',
      deselectAll: 'Deselect all',
      noCategoriesSelected: 'Select at least one category.',
      noWordsInSelection: 'No quiz words are available for this selection.',
      selectionLaunchError: 'Something went wrong. Please try again.',
      continueLabel: 'Continue',
      repeatLabel: 'Repeat',
      categoriesLabel: 'Categories',
      analyticsLoading: 'Loading progress...',
      analyticsUnavailable: 'Progress unavailable.',
      analyticsNoRows: 'No rows',
      analyticsWordsLoaded: 'Showing %1$d of %2$d words',
      analyticsFilteredWordsLoaded: 'Showing %1$d of %2$d matching words',
      analyticsLoadMoreWords: 'Load more words',
      analyticsLoadingWords: 'Loading words...',
      analyticsLoadMoreMatchingWords: 'Load more matching words',
      analyticsLoadingMoreMatchingWords: 'Loading matching words...',
      analyticsSelectAllWithContext: 'Select all: %1$s',
      analyticsDeselectAllWithContext: 'Deselect all: %1$s',
      analyticsSelectAllShown: 'Select all',
      analyticsDeselectAllShown: 'Deselect all',
      analyticsSelectAllContextFiltered: 'Filtered words',
      analyticsSelectionCount: '%d selected words',
      analyticsWordStatusMastered: 'Learned',
      analyticsWordStatusStudied: 'In progress',
      analyticsWordStatusNew: 'New',
      analyticsFilterStarredOnly: 'Starred only',
      analyticsFilterUnstarredOnly: 'Unstarred only',
      analyticsFilterLast24h: 'Last 24 hours',
      analyticsFilterLast7d: 'Last 7 days',
      analyticsFilterLast30d: 'Last 30 days',
      analyticsFilterLastOlder: 'Older',
      analyticsFilterLastNever: 'Never',
      analyticsFilterDifficultyHard: 'Hard',
      analyticsMastered: 'Learned',
      analyticsStudied: 'In progress',
      analyticsNew: 'New',
      analyticsStarred: 'Starred',
      analyticsHard: 'Hard',
      analyticsStarWord: 'Star word',
      analyticsUnstarWord: 'Unstar word'
    }
  };

  if (overrides && typeof overrides === 'object') {
    Object.assign(config, overrides);
  }

  config.summaryCounts = Object.assign({
    mastered: 0,
    studied: 0,
    new: 20,
    starred: 0,
    hard: 0
  }, (overrides && overrides.summaryCounts) || {});

  config.i18n = Object.assign({}, config.i18n, (overrides && overrides.i18n) || {});

  return config;
}

async function mountWordsetPage(page, options = {}) {
  const config = buildPageConfig(options.config || {});
  const markup = buildWordsetMarkup({
    cardMarginTop: options.cardMarginTop,
    trackLoading: options.trackLoading,
    progressWidths: options.progressWidths,
    summaryCounts: config.summaryCounts,
    summaryCountsDeferred: config.summaryCountsDeferred
  });

  await page.goto('about:blank');
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(markup);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrapConfig) => {
    window.llWordsetPageData = bootstrapConfig;
    window.__llAnalyticsRequests = [];
    window.__confettiCalls = 0;
    window.confetti = function () {
      window.__confettiCalls += 1;
    };

    window.alert = function () {};

    window.__resolveAnalyticsRequest = function (index, analytics) {
      const entry = Array.isArray(window.__llAnalyticsRequests)
        ? window.__llAnalyticsRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.resolve({
        success: true,
        data: {
          analytics
        }
      });
      return true;
    };

    const $ = window.jQuery;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_user_study_analytics') {
        window.__llAnalyticsRequests.push({
          action,
          request: Object.assign({}, request),
          deferred
        });
        return deferred.promise();
      }

      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: null,
            recommendation_queue: []
          }
        });
        return deferred.promise();
      }

      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, config);

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function mountProgressPage(page, options = {}) {
  const config = buildPageConfig(Object.assign({
    view: 'progress',
    progressWordPageSize: 30,
    summaryCountsDeferred: false
  }, options.config || {}));

  await page.goto('about:blank');
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(buildProgressPageMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrapConfig) => {
    const localStorageStub = {
      getItem() { return null; },
      setItem() {},
      removeItem() {},
      clear() {}
    };
    Object.defineProperty(window, 'localStorage', {
      value: localStorageStub,
      configurable: true
    });
    window.llWordsetPageData = bootstrapConfig;
    window.__llAnalyticsRequests = [];
    window.__llSelectionPlanRequests = [];
    window.__llFetchWordsRequests = [];
    window.__llFlashcardLaunches = [];
    window.__llFlashcardInitRequests = [];
    window.__llHoldSelectionPlanRequests = false;
    window.__llHoldFetchWordsRequests = false;
    window.__llHoldFlashcardInitRequests = false;
    window.__confettiCalls = 0;
    window.confetti = function () {
      window.__confettiCalls += 1;
    };
    window.initFlashcardWidget = function (categoryNames, mode) {
      const launch = {
        categoryNames: Array.isArray(categoryNames) ? categoryNames.slice() : [],
        mode,
        sessionWordIds: window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.sessionWordIds)
          ? window.llToolsFlashcardsData.sessionWordIds.slice()
          : [],
        logicalSessionWordIds: window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.logicalSessionWordIds)
          ? window.llToolsFlashcardsData.logicalSessionWordIds.slice()
          : [],
        lastLaunchPlan: window.llToolsFlashcardsData && window.llToolsFlashcardsData.lastLaunchPlan
          ? Object.assign({}, window.llToolsFlashcardsData.lastLaunchPlan)
          : null
      };
      window.__llFlashcardLaunches.push(launch);
      let resolveRequest;
      let rejectRequest;
      const promise = new Promise((resolve, reject) => {
        resolveRequest = resolve;
        rejectRequest = reject;
      });
      window.__llFlashcardInitRequests.push({
        launch,
        resolve: resolveRequest,
        reject: rejectRequest,
        promise
      });
      if (!window.__llHoldFlashcardInitRequests) {
        resolveRequest();
      }
      return promise;
    };

    window.__llAlerts = [];
    window.alert = function (message) {
      window.__llAlerts.push(String(message || ''));
    };

    window.__resolveAnalyticsRequest = function (index, analytics) {
      const entry = Array.isArray(window.__llAnalyticsRequests)
        ? window.__llAnalyticsRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.resolve({
        success: true,
        data: {
          analytics
        }
      });
      return true;
    };

    window.__rejectAnalyticsRequest = function (index, statusText) {
      const entry = Array.isArray(window.__llAnalyticsRequests)
        ? window.__llAnalyticsRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.reject({ status: 503 }, String(statusText || 'error'), 'Unavailable');
      return true;
    };

    window.__resolveFetchWordsRequest = function (index) {
      const entry = Array.isArray(window.__llFetchWordsRequests)
        ? window.__llFetchWordsRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.resolve(entry.response);
      return true;
    };

    window.__rejectFetchWordsRequest = function (index) {
      const entry = Array.isArray(window.__llFetchWordsRequests)
        ? window.__llFetchWordsRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.reject({ status: 503 }, 'error', 'Unavailable');
      return true;
    };

    window.__resolveSelectionPlanRequest = function (index) {
      const entry = Array.isArray(window.__llSelectionPlanRequests)
        ? window.__llSelectionPlanRequests[index]
        : null;
      if (!entry || !entry.deferred) {
        return false;
      }
      entry.deferred.resolve(entry.response);
      return true;
    };

    window.__resolveFlashcardInitRequest = function (index) {
      const entry = Array.isArray(window.__llFlashcardInitRequests)
        ? window.__llFlashcardInitRequests[index]
        : null;
      if (!entry || typeof entry.resolve !== 'function') {
        return false;
      }
      entry.resolve();
      return true;
    };

    window.__rejectFlashcardInitRequest = function (index) {
      const entry = Array.isArray(window.__llFlashcardInitRequests)
        ? window.__llFlashcardInitRequests[index]
        : null;
      if (!entry || typeof entry.reject !== 'function') {
        return false;
      }
      entry.reject(new Error('Initialization failed'));
      return true;
    };

    window.__llReadFlashcardLaunchUi = function () {
      const popup = document.getElementById('ll-tools-flashcard-popup');
      const quizPopup = document.getElementById('ll-tools-flashcard-quiz-popup');
      const loader = document.getElementById('ll-tools-loading-animation');
      const loadingStatus = document.getElementById('ll-tools-loading-status');
      const isVisible = function (element) {
        if (!element || element.hidden) {
          return false;
        }
        const style = window.getComputedStyle(element);
        return style.display !== 'none'
          && style.visibility !== 'hidden'
          && style.opacity !== '0'
          && element.getClientRects().length > 0;
      };
      return {
        bodyOpen: document.body.classList.contains('ll-tools-flashcard-open'),
        popupVisible: isVisible(popup),
        quizPopupVisible: isVisible(quizPopup),
        quizPopupBusy: !!quizPopup && quizPopup.getAttribute('aria-busy') === 'true',
        quizPopupAriaHidden: quizPopup ? quizPopup.getAttribute('aria-hidden') : null,
        loaderVisible: isVisible(loader),
        loadingStatusHidden: !loadingStatus || loadingStatus.hidden
      };
    };

    const $ = window.jQuery;
    const abortableDeferredPromise = function (deferred, entry) {
      const promise = deferred.promise();
      promise.abort = function (statusText) {
        if (deferred.state() !== 'pending') {
          return;
        }
        const normalizedStatus = String(statusText || 'abort');
        entry.aborted = true;
        entry.abortStatus = normalizedStatus;
        deferred.reject({ status: 0 }, normalizedStatus, normalizedStatus);
      };
      return promise;
    };
    window.__llFlashcardPopupHideCalls = 0;
    const originalHide = $.fn.hide;
    $.fn.hide = function () {
      if (this.filter('#ll-tools-flashcard-popup').length) {
        window.__llFlashcardPopupHideCalls += 1;
      }
      return originalHide.apply(this, arguments);
    };
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_user_study_analytics') {
        const entry = {
          action,
          request: Object.assign({}, request),
          deferred,
          aborted: false
        };
        window.__llAnalyticsRequests.push(entry);
        return abortableDeferredPromise(deferred, entry);
      }

      if (action === 'll_user_study_selection_launch_plan') {
        const requestedMode = String(request.mode || 'practice');
        const isLearning = requestedMode === 'learning';
        const candidateIds = (Array.isArray(request.candidate_word_ids)
          ? request.candidate_word_ids
          : String(request.candidate_word_ids || '').split(','))
          .map((value) => Number(value) || 0)
          .filter((value, index, values) => value > 0 && values.indexOf(value) === index);
        const chunks = [];
        for (let offset = 0; offset < candidateIds.length; offset += 15) {
          const chunkWordIds = candidateIds.slice(offset, offset + 15);
          chunks.push({
            category_ids: [11],
            word_ids: chunkWordIds,
            ...(isLearning ? {
              target_word_ids: chunkWordIds.slice(),
              compatibility_key: 'ratio:1_1|audio->image'
            } : {})
          });
        }
        const firstChunk = chunks[0] || null;
        const plan = {
          category_ids: firstChunk ? firstChunk.category_ids.slice() : [],
          word_ids: firstChunk ? firstChunk.word_ids.slice() : [],
          ...(isLearning ? {
            target_word_ids: firstChunk ? firstChunk.target_word_ids.slice() : [],
            compatibility_key: firstChunk ? firstChunk.compatibility_key : ''
          } : {}),
          chunks,
          criteria: '',
          mode: requestedMode,
          matched_count: candidateIds.length,
          planned_count: candidateIds.length,
          expanded_count: 0,
          chunk_count: chunks.length,
          truncated: false
        };
        const response = {
          success: true,
          data: {
            plan
          }
        };
        const entry = {
          action,
          request: Object.assign({}, request),
          plan,
          deferred,
          response,
          aborted: false
        };
        window.__llSelectionPlanRequests.push(entry);
        if (!window.__llHoldSelectionPlanRequests) {
          deferred.resolve(response);
        }
        return abortableDeferredPromise(deferred, entry);
      }

      if (action === 'll_get_words_by_category' || action === 'll_get_flashcard_payload_page') {
        const candidateIds = String(request.candidate_word_ids || '')
          .split(',')
          .map((value) => Number(value) || 0)
          .filter((value, index, values) => value > 0 && values.indexOf(value) === index);
        const categoryId = Number(request.category_id) || 11;
        const words = candidateIds.map((id) => ({
          id,
          title: `Launch Word ${id}`,
          translation: `Translation ${id}`,
          label: `Launch Word ${id}`,
          image: '',
          audio_url: '',
          category_id: categoryId,
          category_ids: [categoryId]
        }));
        const response = {
          success: true,
          data: action === 'll_get_flashcard_payload_page'
            ? {
                schema: 1,
                rows: words,
                next_cursor: '',
                complete: true
              }
            : words
        };
        const entry = {
          action,
          request: Object.assign({}, request),
          deferred,
          response,
          aborted: false
        };
        window.__llFetchWordsRequests.push(entry);
        if (!window.__llHoldFetchWordsRequests) {
          deferred.resolve(response);
        }
        return abortableDeferredPromise(deferred, entry);
      }

      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: null,
            recommendation_queue: []
          }
        });
        return deferred.promise();
      }

      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, config);

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function prepareAllFilteredProgressSelection(page, options = {}) {
  const allMatchingIds = Array.isArray(options.allMatchingIds)
    ? options.allMatchingIds.slice()
    : Array.from({ length: 24 }, (_unused, index) => 101 + index);
  const summary = {
    totalWords: Math.max(40, allMatchingIds.length),
    masteredWords: 0,
    studiedWords: allMatchingIds.length,
    newWords: Math.max(0, 40 - allMatchingIds.length),
    hardWords: 0,
    starredWords: allMatchingIds.length
  };

  await mountProgressPage(page, {
    config: Object.assign({
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: allMatchingIds,
        star_mode: 'normal',
        fast_transitions: false
      }
    }, options.config || {})
  });

  await expect.poll(async () => page.evaluate(() => window.__llAnalyticsRequests.length)).toBe(1);
  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    ...summary,
    words: buildProgressWords(1, 30),
    wordsPagination: {
      enabled: true,
      total: summary.totalWords,
      unfiltered_total: summary.totalWords,
      filtered: false,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();
  await page.locator('[data-ll-wordset-progress-kpi-filter="starred"]').click();
  await expect.poll(async () => page.evaluate(() => window.__llAnalyticsRequests.length)).toBe(2);

  const filteredPayload = buildAnalytics({
    ...summary,
    words: allMatchingIds.map((id) => buildProgressWords(id, 1, { starredIds: allMatchingIds })[0]),
    wordIds: options.primeSnapshot ? allMatchingIds : [],
    wordsPagination: {
      enabled: false,
      total: allMatchingIds.length,
      unfiltered_total: summary.totalWords,
      filtered: true,
      offset: 0,
      limit: allMatchingIds.length,
      loaded: allMatchingIds.length,
      next_offset: null,
      has_more: false
    }
  });
  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, filteredPayload);

  const selectAll = page.locator('[data-ll-wordset-progress-select-all]');
  await expect(selectAll).toHaveText('Select all: Starred');
  await selectAll.click();
  await expect(page.locator('[data-ll-wordset-progress-selection-count]'))
    .toHaveText(`${allMatchingIds.length} selected words`);

  return {
    allMatchingIds
  };
}

function buildAllFilteredWordIdAnalytics(wordIds) {
  const ids = Array.isArray(wordIds) ? wordIds.slice() : [];
  const payload = buildAnalytics({
    totalWords: Math.max(40, ids.length),
    studiedWords: ids.length,
    newWords: Math.max(0, 40 - ids.length),
    starredWords: ids.length,
    words: [],
    wordsPagination: {
      enabled: false,
      total: ids.length,
      unfiltered_total: Math.max(40, ids.length),
      filtered: true,
      offset: 0,
      limit: 0,
      loaded: 0,
      next_offset: null,
      has_more: false
    }
  });
  payload.word_ids = ids;
  return payload;
}

async function getAllFilteredLaunchRequestIndexes(page) {
  return page.evaluate(() => window.__llAnalyticsRequests.reduce((indexes, entry, index) => {
    const request = entry && entry.request ? entry.request : {};
    if (
      String(request.action || '') === 'll_user_study_analytics'
      && String(request.include_words ?? '') === '0'
      && String(request.include_word_ids ?? '') === '1'
    ) {
      indexes.push(index);
    }
    return indexes;
  }, []));
}

async function waitForAllFilteredLaunchRequestCount(page, expectedCount) {
  await expect.poll(async () => (await getAllFilteredLaunchRequestIndexes(page)).length).toBe(expectedCount);
  return getAllFilteredLaunchRequestIndexes(page);
}

function expectFlashcardLaunchUiState(state, expectedOpen) {
  expect(state).toEqual({
    bodyOpen: expectedOpen,
    popupVisible: expectedOpen,
    quizPopupVisible: expectedOpen,
    quizPopupBusy: expectedOpen,
    quizPopupAriaHidden: expectedOpen ? 'false' : 'true',
    loaderVisible: expectedOpen,
    loadingStatusHidden: !expectedOpen
  });
}

async function expectFlashcardLaunchUiOpen(page) {
  await expect.poll(async () => page.evaluate(() => window.__llReadFlashcardLaunchUi())).toEqual({
    bodyOpen: true,
    popupVisible: true,
    quizPopupVisible: true,
    quizPopupBusy: true,
    quizPopupAriaHidden: 'false',
    loaderVisible: true,
    loadingStatusHidden: false
  });
}

async function expectFlashcardLaunchUiClosed(page) {
  await expect.poll(async () => page.evaluate(() => window.__llReadFlashcardLaunchUi())).toEqual({
    bodyOpen: false,
    popupVisible: false,
    quizPopupVisible: false,
    quizPopupBusy: false,
    quizPopupAriaHidden: 'true',
    loaderVisible: false,
    loadingStatusHidden: true
  });
}

test('progress summary counts stay blank while initial analytics loads', async ({ page }) => {
  await mountProgressPage(page, {
    config: {
      summaryCountsDeferred: true
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  const summary = page.locator('[data-ll-wordset-progress-summary]');
  await expect(summary).toHaveClass(/is-loading/);
  await expect(summary).toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('.ll-wordset-progress-kpi')).toHaveCount(5);
  await expect(page.locator('.ll-wordset-progress-kpi.is-loading')).toHaveCount(5);
  await expect(page.locator('.ll-wordset-progress-kpi-value')).toHaveText(['', '', '', '', '']);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 20,
    masteredWords: 3,
    studiedWords: 9,
    newWords: 11,
    hardWords: 2,
    starredWords: 4,
    words: buildProgressWords(1, 20),
    wordsPagination: {
      enabled: false,
      total: 20,
      offset: 0,
      limit: 0,
      loaded: 20,
      next_offset: null,
      has_more: false
    }
  }));

  await expect(summary).not.toHaveClass(/is-loading/);
  await expect(summary).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.ll-wordset-progress-kpi.is-loading')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-progress-kpi-value')).toHaveText(['3', '6', '11', '4', '2']);
});

test('progress words load in bounded pages', async ({ page }) => {
  await mountProgressPage(page);

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await expect.poll(async () => {
    return page.evaluate(() => {
      const request = Array.isArray(window.__llAnalyticsRequests) && window.__llAnalyticsRequests[0]
        ? window.__llAnalyticsRequests[0].request
        : {};
      return {
        includeWords: String(request.include_words ?? ''),
        wordLimit: String(request.word_limit ?? ''),
        wordOffset: String(request.word_offset ?? '')
      };
    });
  }).toEqual({ includeWords: '1', wordLimit: '30', wordOffset: '0' });

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 45,
    studiedWords: 12,
    newWords: 33,
    words: buildProgressWords(1, 30),
    wordsPagination: {
      enabled: true,
      total: 45,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();

  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(30);
  await expect(page.locator('[data-ll-wordset-progress-words-loaded]')).toHaveText('Showing 30 of 45 words');
  await expect(page.locator('[data-ll-wordset-progress-words-load-more]')).toBeVisible();

  await page.locator('[data-ll-wordset-progress-words-load-more]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await expect.poll(async () => {
    return page.evaluate(() => {
      const request = Array.isArray(window.__llAnalyticsRequests) && window.__llAnalyticsRequests[1]
        ? window.__llAnalyticsRequests[1].request
        : {};
      return {
        includeWords: String(request.include_words ?? ''),
        wordLimit: String(request.word_limit ?? ''),
        wordOffset: String(request.word_offset ?? '')
      };
    });
  }).toEqual({ includeWords: '1', wordLimit: '30', wordOffset: '30' });

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({
    totalWords: 45,
    studiedWords: 12,
    newWords: 33,
    words: buildProgressWords(31, 15),
    wordsPagination: {
      enabled: true,
      total: 45,
      offset: 30,
      limit: 30,
      loaded: 45,
      next_offset: null,
      has_more: false
    }
  }));

  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(45);
  await expect(page.locator('[data-ll-wordset-progress-words-loaded]')).toHaveText('Showing 45 of 45 words');
  await expect(page.locator('[data-ll-wordset-progress-words-load-more]')).toBeHidden();
});

test('progress starred filter requests matching word pages directly', async ({ page }) => {
  const matchingIds = Array.from({ length: 12 }, (_unused, index) => 101 + index);
  await mountProgressPage(page, {
    config: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: matchingIds,
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 45,
    starredWords: 12,
    studiedWords: 12,
    newWords: 33,
    words: buildProgressWords(1, 30),
    wordsPagination: {
      enabled: true,
      total: 45,
      unfiltered_total: 45,
      filtered: false,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();
  await page.locator('[data-ll-wordset-progress-kpi-filter="starred"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await expect.poll(async () => {
    return page.evaluate(() => {
      const request = Array.isArray(window.__llAnalyticsRequests) && window.__llAnalyticsRequests[1]
        ? window.__llAnalyticsRequests[1].request
        : {};
      const parsedFilter = request.word_filter ? JSON.parse(request.word_filter) : {};
      return {
        wordLimit: String(request.word_limit ?? ''),
        wordOffset: String(request.word_offset ?? ''),
        summary: String(parsedFilter.summary || '')
      };
    });
  }).toEqual({ wordLimit: '30', wordOffset: '0', summary: 'starred' });

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({
    totalWords: 45,
    starredWords: 12,
    studiedWords: 12,
    newWords: 33,
    words: buildProgressWords(101, 12, { starredIds: matchingIds }),
    wordsPagination: {
      enabled: true,
      total: 12,
      unfiltered_total: 45,
      filtered: true,
      offset: 0,
      limit: 30,
      loaded: 12,
      next_offset: null,
      has_more: false
    }
  }));

  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(12);
  await expect(page.locator('[data-ll-wordset-progress-words-loaded]')).toHaveText('Showing 12 of 12 matching words');
  await expect(page.locator('[data-ll-wordset-progress-words-load-more]')).toBeHidden();
});

test('progress filter option counts use full matching totals instead of loaded rows', async ({ page }) => {
  await mountProgressPage(page, {
    config: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: [1, 4, 9, 30, 44],
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 100,
    masteredWords: 7,
    studiedWords: 35,
    newWords: 65,
    starredWords: 5,
    words: buildProgressWords(1, 30, { starredIds: [1, 4, 9, 30, 44] }),
    wordFilterCounts: {
      status: {
        mastered: 7,
        studied: 28,
        new: 65
      },
      star: {
        starred: 5,
        unstarred: 95
      },
      category: {
        11: 100
      }
    },
    wordsPagination: {
      enabled: true,
      total: 100,
      unfiltered_total: 100,
      filtered: false,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();

  await expect(page.locator('[data-ll-wordset-progress-column-filter-options="status"] .ll-wordset-progress-filter-option__count'))
    .toHaveText([' (7)', ' (28)', ' (65)']);
  await expect(page.locator('[data-ll-wordset-progress-column-filter-options="star"] .ll-wordset-progress-filter-option__count'))
    .toHaveText([' (5)', ' (95)']);
  await expect(page.locator('[data-ll-wordset-progress-category-filter-options] .ll-wordset-progress-filter-option__count'))
    .toHaveText([' (100)']);
});

test('progress select all enters loading state immediately while a filter applies', async ({ page }) => {
  const matchingIds = Array.from({ length: 42 }, (_unused, index) => 101 + index);
  await mountProgressPage(page, {
    config: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: matchingIds,
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 80,
    starredWords: 42,
    studiedWords: 42,
    newWords: 38,
    words: buildProgressWords(1, 30),
    wordsPagination: {
      enabled: true,
      total: 80,
      unfiltered_total: 80,
      filtered: false,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();
  await expect(page.locator('[data-ll-wordset-progress-select-all]')).toHaveText('Select all');

  await page.locator('[data-ll-wordset-progress-kpi-filter="starred"]').click();

  const selectAll = page.locator('[data-ll-wordset-progress-select-all]');
  await expect(selectAll).toHaveText('Select all: Starred');
  await expect(selectAll).toHaveClass(/is-loading/);
  await expect(selectAll).toHaveAttribute('aria-busy', 'true');
  await expect(selectAll).toBeDisabled();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({
    totalWords: 80,
    starredWords: 42,
    studiedWords: 42,
    newWords: 38,
    words: matchingIds.slice(0, 30).map((id) => buildProgressWords(id, 1, { starredIds: matchingIds })[0]),
    wordsPagination: {
      enabled: true,
      total: 42,
      unfiltered_total: 80,
      filtered: true,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await expect(selectAll).toHaveText('Select all: Starred');
  await expect(selectAll).not.toHaveClass(/is-loading/);
  await expect(selectAll).toHaveAttribute('aria-busy', 'false');
  await expect(selectAll).toBeEnabled();
});

async function assertFilteredProgressPracticeLaunch(page, options) {
  const filterKey = options.filterKey;
  const filterLabel = options.filterLabel;
  const firstPageMatchingIds = options.firstPageMatchingIds;
  const allMatchingIds = options.allMatchingIds;
  const starredIds = options.starredIds || [];
  const summary = Object.assign({
    totalWords: 80,
    masteredWords: 0,
    studiedWords: 42,
    newWords: 38,
    hardWords: 0,
    starredWords: starredIds.length
  }, options.summary || {});
  await mountProgressPage(page, {
    config: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: starredIds,
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: summary.totalWords,
    masteredWords: summary.masteredWords,
    studiedWords: summary.studiedWords,
    newWords: summary.newWords,
    hardWords: summary.hardWords,
    starredWords: summary.starredWords,
    words: buildProgressWords(1, 30),
    wordsPagination: {
      enabled: true,
      total: summary.totalWords,
      unfiltered_total: summary.totalWords,
      filtered: false,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();
  await page.locator(`[data-ll-wordset-progress-kpi-filter="${filterKey}"]`).click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({
    totalWords: summary.totalWords,
    masteredWords: summary.masteredWords,
    studiedWords: summary.studiedWords,
    newWords: summary.newWords,
    hardWords: summary.hardWords,
    starredWords: summary.starredWords,
    words: firstPageMatchingIds.map((id) => buildProgressWords(id, 1, { starredIds })[0]),
    wordIds: allMatchingIds,
    wordsPagination: {
      enabled: true,
      total: allMatchingIds.length,
      unfiltered_total: summary.totalWords,
      filtered: true,
      offset: 0,
      limit: 30,
      loaded: 30,
      next_offset: 30,
      has_more: true
    }
  }));

  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(30);
  await expect(page.locator('[data-ll-wordset-progress-select-all]')).toHaveText(`Select all: ${filterLabel}`);

  await page.locator('[data-ll-wordset-progress-select-all]').click();
  await expect(page.locator('[data-ll-wordset-progress-selection-count]')).toHaveText(`${allMatchingIds.length} selected words`);
  await expect(page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]')).toBeEnabled();

  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = true;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llAnalyticsRequests.length)).toBe(2);
  const filteredRequest = await page.evaluate(() => Object.assign({}, window.__llAnalyticsRequests[1].request || {}));
  expect(String(filteredRequest.include_word_ids ?? '')).toBe('1');
  expect(JSON.parse(filteredRequest.word_filter || '{}').summary).toBe(filterKey);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'plan');

  await expect.poll(async () => page.evaluate(() => (
    Array.isArray(window.__llSelectionPlanRequests) ? window.__llSelectionPlanRequests.length : 0
  ))).toBe(1);

  await page.evaluate(() => window.__resolveSelectionPlanRequest(0));

  await expect.poll(async () => page.evaluate(() => (
    Array.isArray(window.__llFetchWordsRequests) ? window.__llFetchWordsRequests.length : 0
  ))).toBe(1);

  await expect.poll(async () => {
    return page.evaluate(() => {
      const request = window.__llFetchWordsRequests[0].request || {};
      const ids = String(request.candidate_word_ids || '')
        .split(',')
        .map((value) => Number(value) || 0)
        .filter(Boolean);
      const launches = Array.isArray(window.__llFlashcardLaunches) ? window.__llFlashcardLaunches : [];
      const planRequest = Array.isArray(window.__llSelectionPlanRequests) && window.__llSelectionPlanRequests[0]
        ? window.__llSelectionPlanRequests[0].request
        : {};
      const plannedCandidateIds = (Array.isArray(planRequest.candidate_word_ids)
        ? planRequest.candidate_word_ids
        : String(planRequest.candidate_word_ids || '').split(','))
        .map((value) => Number(value) || 0)
        .filter(Boolean);
      return {
        candidatePayloadType: typeof planRequest.candidate_word_ids,
        plannedCandidateCount: plannedCandidateIds.length,
        firstPlannedCandidate: plannedCandidateIds[0] || 0,
        lastPlannedCandidate: plannedCandidateIds[plannedCandidateIds.length - 1] || 0,
        candidateCount: ids.length,
        firstCandidate: ids[0] || 0,
        lastCandidate: ids[ids.length - 1] || 0,
        launchCount: launches.length,
        sessionCount: launches[0] ? launches[0].sessionWordIds.length : 0,
        logicalSessionCount: launches[0] ? launches[0].logicalSessionWordIds.length : 0,
        launchSource: launches[0] && launches[0].lastLaunchPlan
          ? String(launches[0].lastLaunchPlan.source || '')
          : '',
        chunked: !!(launches[0] && launches[0].lastLaunchPlan && launches[0].lastLaunchPlan.chunked)
      };
    });
  }).toEqual({
    candidatePayloadType: 'string',
    plannedCandidateCount: allMatchingIds.length,
    firstPlannedCandidate: allMatchingIds[0],
    lastPlannedCandidate: allMatchingIds[allMatchingIds.length - 1],
    candidateCount: 15,
    firstCandidate: allMatchingIds[0],
    lastCandidate: allMatchingIds[14],
    launchCount: 1,
    sessionCount: 15,
    logicalSessionCount: allMatchingIds.length,
    launchSource: 'wordset_progress_bounded_start',
    chunked: true
  });
}

test('progress select all filtered launches practice with every matching word id', async ({ page }) => {
  const allMatchingIds = Array.from({ length: 1205 }, (_unused, index) => 101 + index);
  await assertFilteredProgressPracticeLaunch(page, {
    filterKey: 'starred',
    filterLabel: 'Starred',
    firstPageMatchingIds: allMatchingIds.slice(0, 30),
    allMatchingIds,
    starredIds: allMatchingIds,
    summary: {
      totalWords: 1240,
      studiedWords: 1205,
      newWords: 35,
      starredWords: 1205
    }
  });
});

test('progress select all launches a Zazaca-scale new-word snapshot without a duplicate ID request', async ({ page }) => {
  const allMatchingIds = Array.from({ length: 2714 }, (_unused, index) => 101 + index);
  await assertFilteredProgressPracticeLaunch(page, {
    filterKey: 'new',
    filterLabel: 'New',
    firstPageMatchingIds: allMatchingIds.slice(0, 30),
    allMatchingIds,
    summary: {
      totalWords: 2758,
      studiedWords: 44,
      newWords: 2714,
      starredWords: 0
    }
  });
});

test('progress all-filtered launch is single-flight and disables every mode while word IDs load', async ({ page }) => {
  await prepareAllFilteredProgressSelection(page);

  const clickState = await page.evaluate(() => {
    const button = document.querySelector('[data-ll-wordset-progress-selection-mode][data-mode="practice"]');
    button.click();
    const launchUi = window.__llReadFlashcardLaunchUi();
    button.click();
    return {
      launchUi,
      launchRequestCount: window.__llAnalyticsRequests.filter((entry) => {
        const request = entry && entry.request ? entry.request : {};
        return String(request.action || '') === 'll_user_study_analytics'
          && String(request.include_words ?? '') === '0'
          && String(request.include_word_ids ?? '') === '1';
      }).length,
      flashcardLaunchCount: window.__llFlashcardLaunches.length
    };
  });
  expectFlashcardLaunchUiState(clickState.launchUi, true);
  expect(clickState.launchRequestCount).toBeLessThanOrEqual(1);
  expect(clickState.flashcardLaunchCount).toBe(0);

  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  expect(await page.evaluate((index) => window.__llAnalyticsRequests[index].deferred.state(), launchRequestIndex))
    .toBe('pending');
  await expectFlashcardLaunchUiOpen(page);

  const selectionBar = page.locator('[data-ll-wordset-progress-selection-bar]');
  const modeButtons = page.locator('[data-ll-wordset-progress-selection-mode]');
  const activeButton = page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]');
  const feedback = page.locator('[data-ll-wordset-progress-launch-feedback]');
  await expect(selectionBar).toHaveAttribute('aria-busy', 'true');
  await expect(modeButtons).toHaveCount(3);
  await expect.poll(async () => modeButtons.evaluateAll((buttons) => buttons.every((button) => button.disabled))).toBe(true);
  await expect(activeButton).toHaveAttribute('aria-busy', 'true');
  await expect(activeButton).toHaveClass(/is-loading/);
  await expect(feedback).toBeVisible();
  await expect(feedback).toHaveClass(/is-loading/);
  await expect(page.locator('[data-ll-wordset-progress-launch-message]')).toHaveText('Loading progress...');
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);

  await page.evaluate((index) => window.__rejectAnalyticsRequest(index), launchRequestIndex);
  await expectFlashcardLaunchUiClosed(page);
});

test('progress mutation invalidates a cached filtered-ID snapshot before launch', async ({ page }) => {
  await prepareAllFilteredProgressSelection(page, { primeSnapshot: true });
  expect(await getAllFilteredLaunchRequestIndexes(page)).toEqual([]);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:progress-updated');
    document.querySelector('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  });

  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);
  expect(await page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(0);
  expect(await page.evaluate((index) => ({
    filter: JSON.parse(window.__llAnalyticsRequests[index].request.word_filter || '{}').summary,
    state: window.__llAnalyticsRequests[index].deferred.state()
  }), launchRequestIndex)).toEqual({ filter: 'starred', state: 'pending' });

  await page.locator('#ll-tools-close-flashcard').click();
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate((index) => window.__llAnalyticsRequests[index].aborted, launchRequestIndex)).toBe(true);
});

test('progress all-filtered launch failure shows inline Retry without a native alert', async ({ page }) => {
  await prepareAllFilteredProgressSelection(page);
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);

  await page.evaluate((index) => window.__rejectAnalyticsRequest(index), launchRequestIndex);

  const selectionBar = page.locator('[data-ll-wordset-progress-selection-bar]');
  const modeButtons = page.locator('[data-ll-wordset-progress-selection-mode]');
  const feedback = page.locator('[data-ll-wordset-progress-launch-feedback]');
  await expect(selectionBar).toHaveAttribute('aria-busy', 'false');
  await expect.poll(async () => modeButtons.evaluateAll((buttons) => buttons.every((button) => !button.disabled))).toBe(true);
  await expect(feedback).toBeVisible();
  await expect(feedback).toHaveClass(/is-error/);
  await expect(page.locator('[data-ll-wordset-progress-launch-message]'))
    .toHaveText('Something went wrong. Please try again.');
  await expect(page.locator('[data-ll-wordset-progress-launch-retry]')).toBeVisible();
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('closing the prelaunch modal cancels pending word IDs and ignores the late response', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);

  await page.locator('#ll-tools-close-flashcard').click();
  await expectFlashcardLaunchUiClosed(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  await expect.poll(async () => page.locator('[data-ll-wordset-progress-selection-mode]').evaluateAll(
    (buttons) => buttons.every((button) => !button.disabled)
  )).toBe(true);
  expect(await page.evaluate((index) => ({
    aborted: window.__llAnalyticsRequests[index].aborted,
    state: window.__llAnalyticsRequests[index].deferred.state()
  }), launchRequestIndex)).toEqual({ aborted: true, state: 'rejected' });

  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await page.waitForTimeout(100);

  expect(await page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  await expectFlashcardLaunchUiClosed(page);

  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  await waitForAllFilteredLaunchRequestCount(page, 2);
  await expectFlashcardLaunchUiOpen(page);
  await page.locator('#ll-tools-close-flashcard').click();
  await expectFlashcardLaunchUiClosed(page);
});

test('Escape cancels a pending bounded plan and prevents a late quiz launch', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = true;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);

  await page.keyboard.press('Escape');
  await expectFlashcardLaunchUiClosed(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  await expect.poll(async () => page.locator('[data-ll-wordset-progress-selection-mode]').evaluateAll(
    (buttons) => buttons.every((button) => !button.disabled)
  )).toBe(true);
  expect(await page.evaluate(() => ({
    aborted: window.__llSelectionPlanRequests[0].aborted,
    state: window.__llSelectionPlanRequests[0].deferred.state()
  }))).toEqual({ aborted: true, state: 'rejected' });

  await page.evaluate(() => window.__resolveSelectionPlanRequest(0));
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  await expectFlashcardLaunchUiClosed(page);

  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = false;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const launchRequestIndexes = await waitForAllFilteredLaunchRequestCount(page, 2);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndexes[1],
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  expect(await page.evaluate(() => window.__llSelectionPlanRequests[1].aborted)).toBe(false);
  await expectFlashcardLaunchUiOpen(page);
});

test('progress launch Retry repeats the same snapshot once and succeeds', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);

  const firstRequest = await page.evaluate((index) => (
    Object.assign({}, window.__llAnalyticsRequests[index].request || {})
  ), launchRequestIndex);
  await page.evaluate((index) => window.__rejectAnalyticsRequest(index), launchRequestIndex);
  const retry = page.locator('[data-ll-wordset-progress-launch-retry]');
  await expect(retry).toBeVisible();
  await retry.click();

  const launchRequestIndexes = await waitForAllFilteredLaunchRequestCount(page, 2);
  const retryRequestIndex = launchRequestIndexes[1];
  const retryRequest = await page.evaluate((index) => (
    Object.assign({}, window.__llAnalyticsRequests[index].request || {})
  ), retryRequestIndex);
  expect(retryRequest).toEqual(firstRequest);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'true');
  await expect(retry).toBeHidden();

  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: retryRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });

  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  const launch = await page.evaluate(() => window.__llFlashcardLaunches[0]);
  expect(launch.mode).toBe('practice');
  expect(launch.sessionWordIds).toEqual(allMatchingIds.slice(0, 15));
  expect(launch.logicalSessionWordIds).toEqual(allMatchingIds);
  await expect(page.locator('[data-ll-wordset-progress-launch-feedback]')).toBeHidden();
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress word-ID timeout clears loading and Retry relaunches the same selection', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page, {
    config: { selectionLaunchRequestTimeoutMs: 750 }
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  const [firstRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);

  const retry = page.locator('[data-ll-wordset-progress-launch-retry]');
  await expect(retry).toBeVisible();
  await expectFlashcardLaunchUiClosed(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  expect(await page.evaluate((index) => ({
    aborted: window.__llAnalyticsRequests[index].aborted,
    abortStatus: window.__llAnalyticsRequests[index].abortStatus,
    state: window.__llAnalyticsRequests[index].deferred.state()
  }), firstRequestIndex)).toEqual({ aborted: true, abortStatus: 'timeout', state: 'rejected' });

  const firstRequest = await page.evaluate((index) => (
    Object.assign({}, window.__llAnalyticsRequests[index].request || {})
  ), firstRequestIndex);

  await retry.click();
  const requestIndexes = await waitForAllFilteredLaunchRequestCount(page, 2);
  const retryRequest = await page.evaluate((index) => (
    Object.assign({}, window.__llAnalyticsRequests[index].request || {})
  ), requestIndexes[1]);
  expect(retryRequest).toEqual(firstRequest);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: requestIndexes[1],
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });

  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await expect(retry).toBeHidden();
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress plan timeout clears loading and Retry can complete the bounded launch', async ({ page }) => {
  await prepareAllFilteredProgressSelection(page, {
    primeSnapshot: true,
    config: { selectionLaunchRequestTimeoutMs: 750 }
  });
  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = true;
  });

  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  expect(await getAllFilteredLaunchRequestIndexes(page)).toEqual([]);
  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'plan');

  const retry = page.locator('[data-ll-wordset-progress-launch-retry]');
  await expect(retry).toBeVisible();
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate(() => ({
    aborted: window.__llSelectionPlanRequests[0].aborted,
    abortStatus: window.__llSelectionPlanRequests[0].abortStatus,
    state: window.__llSelectionPlanRequests[0].deferred.state()
  }))).toEqual({ aborted: true, abortStatus: 'timeout', state: 'rejected' });

  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = false;
  });
  await retry.click();

  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress hydration timeout clears loading and Retry refetches before launch', async ({ page }) => {
  const allMatchingIds = Array.from({ length: 10 }, (_unused, index) => 101 + index);
  await prepareAllFilteredProgressSelection(page, {
    allMatchingIds,
    primeSnapshot: true,
    config: { selectionLaunchRequestTimeoutMs: 750 }
  });
  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = true;
  });

  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  expect(await getAllFilteredLaunchRequestIndexes(page)).toEqual([]);
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'hydrate');

  const retry = page.locator('[data-ll-wordset-progress-launch-retry]');
  await expect(retry).toBeVisible();
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate(() => ({
    aborted: window.__llFetchWordsRequests[0].aborted,
    abortStatus: window.__llFetchWordsRequests[0].abortStatus,
    state: window.__llFetchWordsRequests[0].deferred.state()
  }))).toEqual({ aborted: true, abortStatus: 'timeout', state: 'rejected' });

  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = false;
  });
  await retry.click();

  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('bounded progress hydration timeout clears loading and Retry refetches before launch', async ({ page }) => {
  await prepareAllFilteredProgressSelection(page, {
    primeSnapshot: true,
    config: { selectionLaunchRequestTimeoutMs: 750 }
  });
  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = true;
  });

  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  expect(await getAllFilteredLaunchRequestIndexes(page)).toEqual([]);
  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'hydrate');

  const retry = page.locator('[data-ll-wordset-progress-launch-retry]');
  await expect(retry).toBeVisible();
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate(() => ({
    aborted: window.__llFetchWordsRequests[0].aborted,
    abortStatus: window.__llFetchWordsRequests[0].abortStatus,
    state: window.__llFetchWordsRequests[0].deferred.state()
  }))).toEqual({ aborted: true, abortStatus: 'timeout', state: 'rejected' });

  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = false;
  });
  await retry.click();

  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await expect(retry).toBeHidden();
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress all-filtered Learning hydrates and launches only its bounded first chunk', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.evaluate(() => {
    window.__llHoldSelectionPlanRequests = true;
    window.__llHoldFetchWordsRequests = true;
    window.__llHoldFlashcardInitRequests = true;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'ids');

  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });

  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'plan');
  expect(await page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);

  await page.evaluate(() => window.__resolveSelectionPlanRequest(0));
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'hydrate');
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);

  await page.evaluate(() => window.__resolveFetchWordsRequest(0));
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'commit');
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'true');
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);

  const state = await page.evaluate(() => {
    const planEntry = window.__llSelectionPlanRequests[0] || {};
    const planRequest = planEntry.request || {};
    const plan = planEntry.plan || {};
    const hydrationRequest = window.__llFetchWordsRequests[0].request || {};
    const launch = window.__llFlashcardLaunches[0] || {};
    const parseIds = (value) => (Array.isArray(value) ? value : String(value || '').split(','))
      .map((item) => Number(item) || 0)
      .filter(Boolean);
    return {
      planMode: String(planRequest.mode || ''),
      planCandidateIds: parseIds(planRequest.candidate_word_ids),
      planExpandedCount: Number(plan.expanded_count) || 0,
      planChunks: Array.isArray(plan.chunks) ? plan.chunks.map((chunk) => ({
        wordIds: parseIds(chunk.word_ids),
        targetWordIds: parseIds(chunk.target_word_ids),
        compatibilityKey: String(chunk.compatibility_key || '')
      })) : [],
      hydrationCandidateIds: parseIds(hydrationRequest.candidate_word_ids),
      launchMode: String(launch.mode || ''),
      launchSessionWordIds: Array.isArray(launch.sessionWordIds) ? launch.sessionWordIds : [],
      launchSource: launch.lastLaunchPlan ? String(launch.lastLaunchPlan.source || '') : '',
      chunked: !!(launch.lastLaunchPlan && launch.lastLaunchPlan.chunked)
    };
  });

  expect(state).toEqual({
    planMode: 'learning',
    planCandidateIds: allMatchingIds,
    planExpandedCount: 0,
    planChunks: [
      {
        wordIds: allMatchingIds.slice(0, 15),
        targetWordIds: allMatchingIds.slice(0, 15),
        compatibilityKey: 'ratio:1_1|audio->image'
      },
      {
        wordIds: allMatchingIds.slice(15),
        targetWordIds: allMatchingIds.slice(15),
        compatibilityKey: 'ratio:1_1|audio->image'
      }
    ],
    hydrationCandidateIds: allMatchingIds.slice(0, 15),
    launchMode: 'learning',
    launchSessionWordIds: allMatchingIds.slice(0, 15),
    launchSource: 'wordset_progress_bounded_start',
    chunked: true
  });
  await page.evaluate(() => window.__resolveFlashcardInitRequest(0));
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-progress-launch-feedback]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', '');
  await expectFlashcardLaunchUiOpen(page);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('bounded launch initialization failure closes the shared modal exactly once', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.evaluate(() => {
    window.__llHoldFlashcardInitRequests = true;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });

  await expect.poll(async () => page.evaluate(() => window.__llFlashcardInitRequests.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);
  await page.evaluate(() => window.__rejectFlashcardInitRequest(0));

  await expectFlashcardLaunchUiClosed(page);
  await expect(page.locator('[data-ll-wordset-progress-launch-feedback]')).toHaveClass(/is-error/);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(1);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress all-filtered small Practice keeps the same modal visible through hydration', async ({ page }) => {
  const allMatchingIds = Array.from({ length: 10 }, (_unused, index) => 101 + index);
  await prepareAllFilteredProgressSelection(page, { allMatchingIds });
  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = true;
  });

  const clickState = await page.evaluate(() => {
    document.querySelector('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
    return window.__llReadFlashcardLaunchUi();
  });
  expectFlashcardLaunchUiState(clickState, true);

  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });

  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  expect(await page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  await page.evaluate(() => new Promise((resolve) => window.requestAnimationFrame(() => resolve())));
  await expectFlashcardLaunchUiOpen(page);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);

  const hydrationCandidateIds = await page.evaluate(() => {
    const request = window.__llFetchWordsRequests[0].request || {};
    return String(request.candidate_word_ids || '')
      .split(',')
      .map((value) => Number(value) || 0)
      .filter(Boolean);
  });
  expect(hydrationCandidateIds).toEqual(allMatchingIds);

  await page.evaluate(() => window.__resolveFetchWordsRequest(0));
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  await expectFlashcardLaunchUiOpen(page);
  expect(await page.evaluate(() => window.__llFlashcardPopupHideCalls)).toBe(0);
  await expect(page.locator('[data-ll-wordset-progress-launch-feedback]')).toBeHidden();
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('Escape aborts bounded hydration and an immediate retry launches once', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = true;
  });

  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const firstLaunchRequestIndexes = await waitForAllFilteredLaunchRequestCount(page, 1);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: firstLaunchRequestIndexes[0],
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]'))
    .toHaveAttribute('data-ll-wordset-progress-launch-stage', 'hydrate');

  await page.keyboard.press('Escape');
  await expectFlashcardLaunchUiClosed(page);
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  await expect.poll(async () => page.locator('[data-ll-wordset-progress-selection-mode]').evaluateAll(
    (buttons) => buttons.every((button) => !button.disabled)
  )).toBe(true);
  expect(await page.evaluate(() => ({
    aborted: window.__llFetchWordsRequests[0].aborted,
    state: window.__llFetchWordsRequests[0].deferred.state()
  }))).toEqual({ aborted: true, state: 'rejected' });
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);

  await page.evaluate(() => {
    window.__llHoldFetchWordsRequests = false;
  });
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="learning"]').click();
  const retryLaunchRequestIndexes = await waitForAllFilteredLaunchRequestCount(page, 2);
  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: retryLaunchRequestIndexes[1],
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await expect.poll(async () => page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFetchWordsRequests.length)).toBe(2);
  await expect.poll(async () => page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(1);
  expect(await page.evaluate(() => window.__llFetchWordsRequests[1].aborted)).toBe(false);
  await expectFlashcardLaunchUiOpen(page);
});

test('changing filters invalidates an in-flight progress launch', async ({ page }) => {
  const { allMatchingIds } = await prepareAllFilteredProgressSelection(page);
  await page.locator('[data-ll-wordset-progress-selection-mode][data-mode="practice"]').click();
  const [launchRequestIndex] = await waitForAllFilteredLaunchRequestCount(page, 1);
  await expectFlashcardLaunchUiOpen(page);

  await page.locator('[data-ll-wordset-progress-search]').fill('changed scope');
  await expect(page.locator('[data-ll-wordset-progress-selection-bar]')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('[data-ll-wordset-progress-launch-feedback]')).toBeHidden();
  await expectFlashcardLaunchUiClosed(page);

  await page.evaluate(({ index, payload }) => {
    window.__resolveAnalyticsRequest(index, payload);
  }, {
    index: launchRequestIndex,
    payload: buildAllFilteredWordIdAnalytics(allMatchingIds)
  });
  await page.waitForTimeout(100);

  expect(await page.evaluate(() => window.__llSelectionPlanRequests.length)).toBe(0);
  expect(await page.evaluate(() => window.__llFlashcardLaunches.length)).toBe(0);
  await expectFlashcardLaunchUiClosed(page);
  expect(await page.evaluate(() => window.__llAlerts.slice())).toEqual([]);
});

test('progress unstar updates the visible row without reloading analytics', async ({ page }) => {
  await mountProgressPage(page, {
    config: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: [1, 2],
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({
    totalWords: 2,
    starredWords: 2,
    studiedWords: 0,
    newWords: 2,
    words: buildProgressWords(1, 2, { starredIds: [1, 2] }),
    wordsPagination: {
      enabled: false,
      total: 2,
      unfiltered_total: 2,
      filtered: false,
      offset: 0,
      limit: 0,
      loaded: 2,
      next_offset: null,
      has_more: false
    }
  }));

  await page.getByRole('button', { name: 'Words' }).click();
  const firstRow = page.locator('[data-ll-wordset-progress-words-body] tr').first();
  const firstStar = firstRow.locator('[data-ll-wordset-progress-word-star]');
  await expect(firstStar).toHaveAttribute('aria-pressed', 'true');

  await firstStar.click();

  await expect(firstStar).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(2);
  await expect(firstRow).toContainText('Progress Word 1');
  await expect(page.locator('.ll-wordset-progress-kpi--starred .ll-wordset-progress-kpi-value')).toHaveText('1');
  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);
});

test('offscreen loading progress bars keep the loading mask until real category progress is applied', async ({ page }) => {
  await mountWordsetPage(page);

  await page.evaluate((payload) => {
    window.__nextAnalyticsPayload = payload;
  }, buildAnalytics({ masteredWords: 5, studiedWords: 12, newWords: 8 }));

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);
  await expect.poll(async () => {
    return page.evaluate(() => {
      const request = Array.isArray(window.__llAnalyticsRequests) && window.__llAnalyticsRequests[0]
        ? window.__llAnalyticsRequests[0].request
        : {};
      return {
        summaryOnly: String(request.summary_only ?? ''),
        includeWords: String(request.include_words ?? '')
      };
    });
  }).toEqual({ summaryOnly: '1', includeWords: '0' });

  await expect(page.locator('.ll-wordset-card__progress-track')).toHaveClass(/is-loading/);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:progress-updated');
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate(() => {
    window.__resolveAnalyticsRequest(1, window.__nextAnalyticsPayload);
  });

  await expect(page.locator('.ll-wordset-card__progress-track')).not.toHaveClass(/is-loading/);

  const widths = await page.evaluate(() => {
    const track = document.querySelector('.ll-wordset-card__progress-track');
    if (!track) {
      return null;
    }

    const readWidth = (selector) => {
      const el = track.querySelector(selector);
      return el ? String(el.style.width || '') : '';
    };

    return {
      mastered: readWidth('.ll-wordset-card__progress-segment--mastered'),
      studied: readWidth('.ll-wordset-card__progress-segment--studied'),
      new: readWidth('.ll-wordset-card__progress-segment--new')
    };
  });

  expect(widths).toEqual({
    mastered: '25%',
    studied: '35%',
    new: '40%'
  });
});

test('stale summary responses stay hidden until the latest loading pass resolves', async ({ page }) => {
  await mountWordsetPage(page);

  await page.evaluate((payload) => {
    window.__nextAnalyticsPayload = payload;
  }, buildAnalytics({ masteredWords: 6, studiedWords: 14, newWords: 6 }));

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:progress-updated');
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({ masteredWords: 5, studiedWords: 12, newWords: 8 }));

  await page.waitForTimeout(120);

  const staleState = await page.evaluate(() => ({
    loading: document.querySelector('[data-ll-wordset-progress-mini-root]')?.classList.contains('is-loading') || false,
    mastered: document.querySelector('[data-ll-progress-mini-mastered]')?.textContent?.trim() || '',
    studied: document.querySelector('[data-ll-progress-mini-studied]')?.textContent?.trim() || '',
    fresh: document.querySelector('[data-ll-progress-mini-new]')?.textContent?.trim() || ''
  }));

  expect(staleState).toEqual({
    loading: true,
    mastered: '0',
    studied: '0',
    fresh: '20'
  });

  await page.evaluate(() => {
    window.__resolveAnalyticsRequest(1, window.__nextAnalyticsPayload);
  });

  await expect(page.locator('[data-ll-wordset-progress-mini-root]')).not.toHaveClass(/is-loading/);

  await expect.poll(async () => {
    return page.evaluate(() => ({
      mastered: document.querySelector('[data-ll-progress-mini-mastered]')?.textContent?.trim() || '',
      studied: document.querySelector('[data-ll-progress-mini-studied]')?.textContent?.trim() || '',
      fresh: document.querySelector('[data-ll-progress-mini-new]')?.textContent?.trim() || ''
    }));
  }).toEqual({
    mastered: '6',
    studied: '8',
    fresh: '6'
  });
});

test('completion burst fires only when new words actually drop to zero', async ({ page }) => {
  await mountWordsetPage(page, {
    trackLoading: false,
    progressWidths: buildCardProgressWidths({
      masteredWords: 10,
      studiedWords: 20,
      newWords: 0
    }),
    config: {
      summaryCounts: {
        mastered: 10,
        studied: 10,
        new: 0,
        starred: 0,
        hard: 0
      },
      summaryCountsDeferred: false
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({ masteredWords: 10, studiedWords: 20, newWords: 0 }));

  await page.waitForTimeout(120);

  await page.evaluate(() => {
    window.__confettiCalls = 0;
    window.jQuery(document).trigger('lltools:progress-updated');
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({ masteredWords: 12, studiedWords: 20, newWords: 0 }));

  await page.waitForTimeout(1400);

  await expect.poll(async () => {
    return page.evaluate(() => window.__confettiCalls || 0);
  }).toBe(0);
});

test('completion burst fires when new words move from above zero to zero', async ({ page }) => {
  await mountWordsetPage(page, {
    trackLoading: false,
    progressWidths: buildCardProgressWidths({
      masteredWords: 4,
      studiedWords: 12,
      newWords: 8
    }),
    config: {
      summaryCounts: {
        mastered: 4,
        studied: 8,
        new: 8,
        starred: 0,
        hard: 0
      },
      summaryCountsDeferred: false
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({ masteredWords: 4, studiedWords: 12, newWords: 8 }));

  await page.waitForTimeout(120);

  await page.evaluate(() => {
    window.__confettiCalls = 0;
    window.jQuery(document).trigger('lltools:progress-updated');
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({ masteredWords: 8, studiedWords: 20, newWords: 0 }));

  await expect.poll(async () => {
    return page.evaluate(() => window.__confettiCalls || 0);
  }, { timeout: 2500 }).toBeGreaterThan(0);
});

test('category progress bars wait for the pill animation to finish before animating', async ({ page }) => {
  await mountWordsetPage(page, {
    cardMarginTop: 320,
    trackLoading: false,
    progressWidths: buildCardProgressWidths({
      masteredWords: 2,
      studiedWords: 8,
      newWords: 12
    }),
    config: {
      summaryCounts: {
        mastered: 2,
        studied: 6,
        new: 12,
        starred: 0,
        hard: 0
      },
      summaryCountsDeferred: false
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics({ masteredWords: 2, studiedWords: 8, newWords: 12 }));

  await page.waitForTimeout(120);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:progress-updated');
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(2);

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(1, payload);
  }, buildAnalytics({ masteredWords: 5, studiedWords: 12, newWords: 8 }));

  await expect.poll(async () => {
    return page.evaluate(() => document.querySelector('[data-ll-wordset-progress-mini-root]')?.classList.contains('is-syncing') || false);
  }).toBe(true);

  const overlapState = await page.evaluate(() => ({
    syncing: document.querySelector('[data-ll-wordset-progress-mini-root]')?.classList.contains('is-syncing') || false,
    barUpdating: document.querySelector('.ll-wordset-card__progress-track')?.classList.contains('is-progress-updating') || false
  }));

  expect(overlapState).toEqual({
    syncing: true,
    barUpdating: false
  });

  await expect.poll(async () => {
    return page.evaluate(() => document.querySelector('[data-ll-wordset-progress-mini-root]')?.classList.contains('is-syncing') || false);
  }, { timeout: 2500 }).toBe(false);

  await expect.poll(async () => {
    return page.evaluate(() => document.querySelector('.ll-wordset-card__progress-track')?.classList.contains('is-progress-updating') || false);
  }, { timeout: 1000 }).toBe(true);
});
