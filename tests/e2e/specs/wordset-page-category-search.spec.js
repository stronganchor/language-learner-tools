const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);
const wordsetStylesSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

function buildMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <div class="ll-wordset-grid-tools">
        <div class="ll-wordset-progress-search ll-wordset-progress-search--wordset-page">
          <label class="screen-reader-text" for="ll-wordset-page-search-input">Search words or translations</label>
          <input
            id="ll-wordset-page-search-input"
            class="ll-wordset-progress-search__input"
            type="search"
            data-ll-wordset-page-search
            autocomplete="off"
          />
          <button
            type="button"
            class="ll-wordset-progress-search__clear"
            data-ll-wordset-page-search-clear
            aria-label="Clear search"
            hidden>
            <svg class="ll-wordset-progress-search__clear-icon" viewBox="0 0 16 16" focusable="false" aria-hidden="true">
              <path d="M4.5 4.5l7 7m0-7l-7 7" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>
            </svg>
          </button>
          <span class="ll-wordset-progress-search__loading" data-ll-wordset-page-search-loading hidden aria-hidden="true"></span>
        </div>
        <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-select-all aria-pressed="false">Select all</button>
      </div>

      <div class="ll-wordset-grid" role="list">
        <article class="ll-wordset-card ll-wordset-card--add-category" role="listitem" data-ll-wordset-card-type="add-category">
          <a class="ll-wordset-add-category-card" href="/wordsets/search-wordset/?ll_wordset_tool=editor&ll_wordset_editor_action=new_category" aria-label="Add category">
            <span class="ll-wordset-add-category-card__title">Add Category</span>
            <span class="ll-wordset-add-category-card__meta">Create a fresh lesson category.</span>
          </a>
        </article>

        <article class="ll-wordset-card" role="listitem" data-cat-id="11" data-word-count="3">
          <div class="ll-wordset-card__top">
            <label class="ll-wordset-card__select">
              <input type="checkbox" value="11" data-ll-wordset-select />
              <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
            </label>
            <a class="ll-wordset-card__heading" href="#" aria-label="Fruit">
              <h2 class="ll-wordset-card__title">Fruit</h2>
            </a>
            <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
          </div>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
            </span>
          </div>
          <div class="ll-wordset-card__quiz-actions">
            <button type="button" data-ll-wordset-category-mode data-mode="practice" data-cat-id="11">Practice</button>
          </div>
        </article>

        <article class="ll-wordset-card" role="listitem" data-cat-id="22" data-word-count="3">
          <div class="ll-wordset-card__top">
            <label class="ll-wordset-card__select">
              <input type="checkbox" value="22" data-ll-wordset-select />
              <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
            </label>
            <a class="ll-wordset-card__heading" href="#" aria-label="Animals">
              <h2 class="ll-wordset-card__title">Animals</h2>
            </a>
            <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
          </div>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
            </span>
          </div>
        </article>

        <article class="ll-wordset-card" role="listitem" data-cat-id="33" data-word-count="3">
          <div class="ll-wordset-card__top">
            <label class="ll-wordset-card__select">
              <input type="checkbox" value="33" data-ll-wordset-select />
              <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
            </label>
            <a class="ll-wordset-card__heading" href="#" aria-label="Travel">
              <h2 class="ll-wordset-card__title">Travel</h2>
            </a>
            <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
          </div>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
            </span>
          </div>
        </article>

        <article class="ll-wordset-card" role="listitem" data-cat-id="44" data-word-count="3">
          <div class="ll-wordset-card__top">
            <label class="ll-wordset-card__select">
              <input type="checkbox" value="44" data-ll-wordset-select />
              <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
            </label>
            <a class="ll-wordset-card__heading" href="#" aria-label="din ve inanc">
              <h2 class="ll-wordset-card__title">din ve inanc</h2>
            </a>
            <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
          </div>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
            </span>
          </div>
        </article>
      </div>

      <div class="ll-wordset-empty ll-wordset-empty--search" data-ll-wordset-page-search-empty hidden>
        No categories match this search.
      </div>
      <div class="ll-wordset-empty ll-wordset-empty--search ll-wordset-search-error" data-ll-wordset-page-search-error hidden>
        <span>Could not search this word set right now.</span>
        <button type="button" class="ll-wordset-search-error__retry" data-ll-wordset-page-search-retry>Retry</button>
      </div>

      <div data-ll-wordset-selection-bar hidden>
        <span data-ll-wordset-selection-text>Select categories to study together</span>
        <label class="ll-wordset-selection-bar__starred-toggle" hidden>
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

function buildConfig() {
  return {
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: '',
    isLoggedIn: false,
    wordsetId: 77,
    wordsetSlug: 'search-wordset',
    wordsetName: 'Search Wordset',
    links: {
      base: '/wordsets/search-wordset/',
      progress: '/wordsets/search-wordset/progress/',
      hidden: '/wordsets/search-wordset/hidden-categories/',
      settings: '/wordsets/search-wordset/settings/'
    },
    progressIncludeHidden: false,
    categories: [
      {
        id: 11,
        slug: 'fruit',
        name: 'Fruit',
        translation: 'Fruit',
        count: 3,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      },
      {
        id: 22,
        slug: 'animals',
        name: 'Animals',
        translation: 'Animals',
        count: 3,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      },
      {
        id: 33,
        slug: 'travel',
        name: 'Travel',
        translation: 'Travel',
        count: 3,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      },
      {
        id: 44,
        slug: 'din-ve-inanc',
        name: 'din ve inanc',
        translation: 'din ve inanc',
        count: 3,
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
    visibleCategoryIds: [11, 22, 33, 44],
    hiddenCategoryIds: [],
    state: {
      wordset_id: 77,
      category_ids: [],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    goals: {
      enabled_modes: ['practice', 'learning', 'listening'],
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
      new: 9,
      starred: 0,
      hard: 0
    },
    summaryCountsDeferred: false,
    categorySearch: {
      enabled: true,
      nonce: 'search-nonce',
      token: 'search-token',
      wordsetId: 77,
      minQueryLength: 1
    },
    i18n: {
      selectionLabel: 'Select categories to study together',
      selectionWordsOnly: '%d words',
      selectAll: 'Select all',
      deselectAll: 'Deselect all'
    }
  };
}

async function mountWordsetPage(page, options = {}) {
  await page.goto('about:blank');
  if (options.fakeClock) {
    await page.clock.install({ time: new Date('2026-01-01T00:00:00Z') });
  }
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(buildMarkup());
  if (options.productionStyles) {
    await page.addStyleTag({ content: wordsetStylesSource });
  }
  await page.addScriptTag({ content: jquerySource });

  const config = buildConfig();
  config.categorySearch.testWarmFailures = Math.max(
    0,
    Number(options.warmFailures || (options.warmOnce ? 1 : 0)) || 0
  );
  config.categorySearch.testWarmQuery = String(options.warmQuery || 'warm').toLowerCase();
  config.categorySearch.testTransientFailures = Math.max(0, Number(options.transientFailures || 0) || 0);
  config.categorySearch.testTransientQuery = String(options.transientQuery || 'transient').toLowerCase();
  config.categorySearch.testTransientStatus = Math.max(0, Number(options.transientStatus || 502) || 502);
  config.categorySearch.testLateFailureQuery = String(options.lateFailureQuery || '').toLowerCase();
  config.categorySearch.testLateFailureMs = Math.max(0, Number(options.lateFailureMs || 0) || 0);
  config.categorySearch.testLateFailures = Math.max(0, Number(options.lateFailures || 0) || 0);
  config.categorySearch.testPendingAbortQuery = String(options.pendingAbortQuery || '').toLowerCase();
  config.categorySearch.testStallQuery = String(options.stallQuery || '').toLowerCase();
  config.categorySearch.testStallNonSearchAjax = !!options.stallNonSearchAjax;
  if (options.maxRetries) {
    config.categorySearch.maxRetries = Number(options.maxRetries);
  }
  if (options.retryBaseMs) {
    config.categorySearch.retryBaseMs = Number(options.retryBaseMs);
  }
  if (options.transientMaxRetries) {
    config.categorySearch.transientMaxRetries = Number(options.transientMaxRetries);
  }
  if (options.preparationRetryWindowMs) {
    config.categorySearch.preparationRetryWindowMs = Number(options.preparationRetryWindowMs);
  }
  if (options.requestTimeoutMs) {
    config.categorySearch.requestTimeoutMs = Number(options.requestTimeoutMs);
  }
  await page.evaluate((config) => {
    window.llWordsetPageData = config;
    window.alert = function () {};
    window.__categorySearchRequests = [];
    window.__categorySearchTimeouts = [];
    window.__categorySearchWarmAttempts = 0;
    window.__categorySearchTransientAttempts = 0;
    window.__categorySearchLateFailureAttempts = 0;

    const $ = window.jQuery;
    $.post = function () {
      const deferred = $.Deferred();
      if (config.categorySearch.testStallNonSearchAjax) {
        return deferred.promise();
      }
      deferred.resolve({
        success: true,
        data: {
          analytics: {
            scope: {},
            summary: {},
            daily_activity: { days: [], max_events: 0, window_days: 14 },
            categories: [],
            words: []
          },
          next_activity: null,
          recommendation_queue: []
        }
      });
      return deferred.promise();
    };
    $.ajax = function (options) {
      const source = (options && typeof options === 'object') ? options : {};
      const data = source.data || {};
      const deferred = $.Deferred();
      const request = deferred.promise();
      let requestQuery = '';
      let timeoutTimer = 0;
      const requestTimeoutMs = Math.max(0, Number(source.timeout || 0) || 0);
      const clearRequestTimeout = function () {
        if (!timeoutTimer) {
          return;
        }
        window.clearTimeout(timeoutTimer);
        timeoutTimer = 0;
      };
      request.abort = function () {
        if (requestQuery && requestQuery === config.categorySearch.testLateFailureQuery) {
          return;
        }
        clearRequestTimeout();
        request.status = 0;
        request.statusText = 'abort';
        deferred.reject(request, 'abort', 'abort');
      };

      if (data.action !== 'll_tools_wordset_page_category_search') {
        if (config.categorySearch.testStallNonSearchAjax) {
          return request;
        }
        window.setTimeout(function () {
          deferred.reject(null, 'error');
        }, 0);
        return request;
      }

      const query = String(data.query || '').toLowerCase();
      requestQuery = query;
      window.__categorySearchRequests.push({
        action: data.action,
        token: data.token,
        query
      });
      window.__categorySearchTimeouts.push({ query, timeout: requestTimeoutMs });

      if (requestTimeoutMs > 0) {
        timeoutTimer = window.setTimeout(function () {
          timeoutTimer = 0;
          request.status = 0;
          request.statusText = 'timeout';
          deferred.reject(request, 'timeout', 'timeout');
        }, requestTimeoutMs);
        deferred.always(clearRequestTimeout);
      }

      if (
        query === config.categorySearch.testPendingAbortQuery
        || query === config.categorySearch.testStallQuery
      ) {
        return request;
      }

      window.setTimeout(function () {
        if (
          config.categorySearch.testLateFailures > 0
          && query === config.categorySearch.testLateFailureQuery
          && window.__categorySearchLateFailureAttempts < config.categorySearch.testLateFailures
        ) {
          window.__categorySearchLateFailureAttempts += 1;
          window.setTimeout(function () {
            deferred.reject({
              status: 500,
              responseJSON: {
                success: false,
                data: {
                  message: 'Delayed failure'
                }
              },
              getResponseHeader: function () {
                return null;
              }
            }, 'error');
          }, config.categorySearch.testLateFailureMs);
          return;
        }

        if (
          config.categorySearch.testWarmFailures > 0
          && query === config.categorySearch.testWarmQuery
          && window.__categorySearchWarmAttempts < config.categorySearch.testWarmFailures
        ) {
          window.__categorySearchWarmAttempts += 1;
          deferred.reject({
            status: 503,
            responseJSON: {
              success: false,
              data: {
                  retry: true
              }
            },
            getResponseHeader: function () {
              return null;
            }
          }, 'error');
          return;
        }

        if (
          config.categorySearch.testTransientFailures > 0
          && query === config.categorySearch.testTransientQuery
          && window.__categorySearchTransientAttempts < config.categorySearch.testTransientFailures
        ) {
          window.__categorySearchTransientAttempts += 1;
          deferred.reject({
            status: config.categorySearch.testTransientStatus,
            responseJSON: {
              success: false,
              data: {
                message: 'Temporary gateway failure'
              }
            },
            getResponseHeader: function () {
              return null;
            }
          }, 'error');
          return;
        }

        let categoryIds = [];
        let wordMatches = {};
        if (
          query.indexOf('app') !== -1
          || query.indexOf('elma') !== -1
          || query === config.categorySearch.testWarmQuery
          || query === config.categorySearch.testTransientQuery
          || query === config.categorySearch.testLateFailureQuery
        ) {
          categoryIds = [11];
          wordMatches = {
            11: [{
              id: 1101,
              title: 'apple',
              translation: 'elma',
              image: 'https://example.test/apple.jpg',
              match_rank: 300,
              match_field: 'title'
            }]
          };
        } else if (query.indexOf('cirun') !== -1 || query.indexOf('otel') !== -1) {
          categoryIds = [33];
          wordMatches = {
            33: [{
              id: 3301,
              title: 'Travel Hotel',
              translation: 'cirun otel',
              image: '',
              match_rank: 300,
              match_field: 'translation'
            }]
          };
        } else if (query.indexOf('din') !== -1) {
          categoryIds = [11, 33];
          wordMatches = {
            11: [{
              id: 1102,
              title: 'pudding',
              translation: 'dessert',
              image: '',
              match_rank: 100,
              match_field: 'title'
            }],
            33: [{
              id: 3302,
              title: 'dinner',
              translation: 'meal',
              image: '',
              match_rank: 200,
              match_field: 'title'
            }]
          };
        }
        deferred.resolve({
          success: true,
          data: {
            query,
            categoryIds,
            wordMatches
          }
        });
      }, 35);

      return request;
    };
  }, config);

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function mountReloadableStaleSearchPage(page, options = {}) {
  const pageUrl = options.pageUrl || 'https://wordset-search-recovery.test/genc/';
  const expectedPagePathname = new URL(pageUrl).pathname;
  const persistentFreshFailure = !!options.persistentFreshFailure;
  let documentLoads = 0;
  const documentUrls = [];
  const documentTokens = [];

  await page.route('https://wordset-search-recovery.test/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    const pathname = requestUrl.pathname;
    if (pathname === '/jquery.js') {
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: jquerySource });
      return;
    }
    if (pathname === '/wordset-pages.js') {
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: wordsetScriptSource });
      return;
    }
    if (pathname !== expectedPagePathname) {
      await route.fulfill({ status: 404, body: 'Not found' });
      return;
    }

    documentLoads += 1;
    documentUrls.push(pathname + requestUrl.search);
    const config = buildConfig();
    const isRecoveryRequest = requestUrl.searchParams.get('ll_category_search_recovery') === '1';
    config.categorySearch.token = isRecoveryRequest ? 'fresh-search-token' : 'stale-search-token';
    documentTokens.push(config.categorySearch.token);
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html>
        <html><body>
          ${buildMarkup()}
          <script src="/jquery.js"></script>
          <script>
            window.llWordsetPageData = ${JSON.stringify(config)};
            window.alert = function () {};
            (function ($) {
              $.post = function () {
                var deferred = $.Deferred();
                deferred.resolve({ success: true, data: { analytics: {}, next_activity: null, recommendation_queue: [] } });
                return deferred.promise();
              };
              $.ajax = function (options) {
                var data = options && options.data ? options.data : {};
                var deferred = $.Deferred();
                var request = deferred.promise();
                request.abort = function () { deferred.reject(null, 'abort'); };
                if (data.action !== 'll_tools_wordset_page_category_search') {
                  window.setTimeout(function () { deferred.reject({ status: 400 }, 'error'); }, 0);
                  return request;
                }
                var count = parseInt(window.sessionStorage.getItem('__staleSearchRequestCount'), 10) || 0;
                count += 1;
                window.sessionStorage.setItem('__staleSearchRequestCount', String(count));
                var queries = JSON.parse(window.sessionStorage.getItem('__staleSearchQueries') || '[]');
                queries.push(String(data.query || ''));
                window.sessionStorage.setItem('__staleSearchQueries', JSON.stringify(queries));
                window.setTimeout(function () {
                  if (String(data.token || '') !== 'fresh-search-token' || ${persistentFreshFailure ? 'true' : 'false'}) {
                    deferred.reject({
                      status: 410,
                      responseJSON: { success: false, data: { message: 'Expired search scope' } },
                      getResponseHeader: function () { return null; }
                    }, 'error');
                    return;
                  }
                  deferred.resolve({
                    success: true,
                    data: {
                      query: String(data.query || ''),
                      categoryIds: [11],
                      wordMatches: { 11: [{ id: 1101, title: 'qalem', translation: 'pencil', match_rank: 300 }] }
                    }
                  });
                }, 0);
                return request;
              };
            })(window.jQuery);
          </script>
          <script src="/wordset-pages.js"></script>
        </body></html>`
    });
  });

  await page.goto(pageUrl);
  return {
    documentLoads: () => documentLoads,
    documentUrls: () => documentUrls.slice(),
    documentTokens: () => documentTokens.slice()
  };
}

async function mountStorageUnavailableStaleSearchPage(page) {
  const pageUrl = 'https://wordset-search-storage-unavailable.test/genc/';
  let documentLoads = 0;
  const documentUrls = [];

  await page.route('https://wordset-search-storage-unavailable.test/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    const pathname = requestUrl.pathname;
    if (pathname === '/jquery.js') {
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: jquerySource });
      return;
    }
    if (pathname === '/wordset-pages.js') {
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: wordsetScriptSource });
      return;
    }
    if (pathname !== '/genc/') {
      await route.fulfill({ status: 404, body: 'Not found' });
      return;
    }

    documentLoads += 1;
    documentUrls.push(pathname + requestUrl.search);
    const shouldFail = requestUrl.searchParams.get('ll_category_search_recovery') !== '1';
    const config = buildConfig();
    config.categorySearch.token = shouldFail ? 'stale-search-token' : 'fresh-search-token';
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><html><body>
        ${buildMarkup()}
        <script src="/jquery.js"></script>
        <script>
          window.llWordsetPageData = ${JSON.stringify(config)};
          window.alert = function () {};
          Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            get: function () { throw new DOMException('Storage blocked', 'SecurityError'); }
          });
          (function ($) {
            $.post = function () {
              var deferred = $.Deferred();
              deferred.resolve({ success: true, data: { analytics: {}, next_activity: null, recommendation_queue: [] } });
              return deferred.promise();
            };
            $.ajax = function (options) {
              var data = options && options.data ? options.data : {};
              var deferred = $.Deferred();
              var request = deferred.promise();
              request.abort = function () { deferred.reject(null, 'abort'); };
              window.setTimeout(function () {
                if (${shouldFail ? 'true' : 'false'}) {
                  deferred.reject({ status: 410, responseJSON: { success: false, data: {} }, getResponseHeader: function () { return null; } }, 'error');
                  return;
                }
                deferred.resolve({ success: true, data: { query: String(data.query || ''), categoryIds: [], wordMatches: {} } });
              }, 0);
              return request;
            };
          })(window.jQuery);
        </script>
        <script src="/wordset-pages.js"></script>
      </body></html>`
    });
  });

  await page.goto(pageUrl);
  return {
    documentLoads: () => documentLoads,
    documentUrls: () => documentUrls.slice()
  };
}

async function setSearchValue(page, value) {
  await page.evaluate((nextValue) => {
    const input = document.querySelector('[data-ll-wordset-page-search]');
    if (!input) {
      throw new Error('Search input not found.');
    }
    input.value = String(nextValue || '');
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, value);
}

test('main wordset search filters category cards by matching words and clears hidden selections', async ({ page }) => {
  await mountWordsetPage(page);

  await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(4);

  await page.locator('[data-ll-wordset-select][value="22"]').check();
  await expect(page.locator('[data-ll-wordset-selection-bar]')).toBeVisible();

  await setSearchValue(page, 'app');

  await expect(page.locator('[data-ll-wordset-page]')).toHaveClass(/is-category-search-loading/);

  await expect.poll(async () => {
    return page.evaluate(() => {
      const root = document.querySelector('[data-ll-wordset-page]');
      return root ? root.className : '';
    });
  }).not.toMatch(/is-category-search-loading/);

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11]);
  await expect.poll(async () => page.evaluate(() => window.__categorySearchRequests || [])).toContainEqual({
    action: 'll_tools_wordset_page_category_search',
    token: 'search-token',
    query: 'app'
  });

  await expect(page.locator('[data-ll-wordset-select][value="22"]')).not.toBeChecked();
  await expect(page.locator('[data-ll-wordset-selection-bar]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();

  await setSearchValue(page, 'zzz');

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([]);

  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeVisible();
});

test('main wordset search matches words when only diacritics differ', async ({ page }) => {
  await mountWordsetPage(page);

  await setSearchValue(page, 'cirun');

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([33]);

  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();
});

test('main wordset search keeps loading and retries while the durable index warms', async ({ page }) => {
  await mountWordsetPage(page, { warmOnce: true });

  await setSearchValue(page, 'warm');

  await expect(page.locator('[data-ll-wordset-page]')).toHaveClass(/is-category-search-loading/);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'warm').length
  ))).toBe(2);
  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11]);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();
});

test('main wordset search keeps provisional results through a roughly 79 second durable preparation', async ({ page }) => {
  await mountWordsetPage(page, {
    fakeClock: true,
    warmFailures: 30,
    warmQuery: 'fruit',
    productionStyles: true
  });

  await setSearchValue(page, 'fruit');
  await page.clock.runFor(40000);

  const provisionalCard = page.locator('.ll-wordset-card[data-cat-id="11"]');
  await expect(provisionalCard).toBeVisible();
  await expect(provisionalCard).toHaveCSS('opacity', '1');
  await expect(provisionalCard).toHaveCSS('pointer-events', 'auto');
  await expect(page.locator('[data-ll-wordset-page]')).toHaveClass(/is-category-search-loading/);
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  expect(await page.evaluate(() => window.__categorySearchWarmAttempts)).toBeGreaterThan(10);

  await page.clock.runFor(40000);

  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(31);
  await expect(provisionalCard).toBeVisible();
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
  expect(await page.evaluate(() => Date.now())).toBeGreaterThanOrEqual(Date.parse('2026-01-01T00:01:20Z'));
});

test('main wordset search stops durable preparation at the default 120 second boundary', async ({ page }) => {
  await mountWordsetPage(page, {
    fakeClock: true,
    warmFailures: 100,
    warmQuery: 'fruit',
    productionStyles: true
  });

  await setSearchValue(page, 'fruit');
  await page.clock.runFor(121000);

  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(45);
});

test('main wordset search times out stalled requests and reaches Retry before the overall preparation bound', async ({ page }) => {
  await mountWordsetPage(page, {
    fakeClock: true,
    stallQuery: 'fruit',
    requestTimeoutMs: 60000,
    productionStyles: true
  });

  await setSearchValue(page, 'fruit');

  const root = page.locator('[data-ll-wordset-page]');
  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  const provisionalCard = page.locator('.ll-wordset-card[data-cat-id="11"]');
  await expect(root).toHaveClass(/is-category-search-loading/);
  await expect(provisionalCard).toBeVisible();

  await page.clock.runFor(14999);
  await expect(errorState).toBeHidden();
  await expect(root).toHaveClass(/is-category-search-loading/);
  expect(await page.evaluate(() => window.__categorySearchTimeouts)).toEqual([{
    query: 'fruit',
    timeout: 15000
  }]);

  await page.clock.runFor(251);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(2);

  await page.clock.runFor(30750);
  const stalledRequests = await page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit')
  ));
  expect(stalledRequests).toHaveLength(3);
  expect(await page.evaluate(() => window.__categorySearchTimeouts.map((request) => request.timeout)))
    .toEqual([15000, 15000, 15000]);
  await expect(errorState).toBeVisible();
  await expect(root).not.toHaveClass(/is-category-search-loading/);
  await expect(provisionalCard).toBeVisible();
  expect(await page.evaluate(() => Date.now())).toBeLessThan(Date.parse('2026-01-01T00:02:00Z'));

  await page.clock.runFor(80000);
  expect(await page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(3);
  await expect(errorState).toBeVisible();
  await expect(root).not.toHaveClass(/is-category-search-loading/);
});

test('main wordset search recovers from a transient gateway failure without showing terminal Retry', async ({ page }) => {
  await mountWordsetPage(page, {
    transientFailures: 1,
    transientQuery: 'transient',
    transientStatus: 502,
    retryBaseMs: 10
  });

  await setSearchValue(page, 'transient');

  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'transient').length
  ))).toBe(2);
  await expect.poll(async () => page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
    .filter((card) => !card.hidden)
    .map((card) => Number(card.getAttribute('data-cat-id'))))).toEqual([11]);
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
});

test('main wordset search gives a gateway blip its own retry budget after durable warming', async ({ page }) => {
  await mountWordsetPage(page, {
    warmFailures: 7,
    warmQuery: 'mixed',
    transientFailures: 1,
    transientQuery: 'mixed',
    transientStatus: 502,
    retryBaseMs: 10
  });

  await setSearchValue(page, 'mixed');

  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'mixed').length
  ))).toBe(9);
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();
});

test('main wordset search ignores a superseded query failure instead of poisoning that query', async ({ page }) => {
  await mountWordsetPage(page, {
    lateFailureQuery: 'late',
    lateFailures: 1,
    lateFailureMs: 40,
    retryBaseMs: 10
  });

  await setSearchValue(page, 'late');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'late').length
  ))).toBe(1);
  await setSearchValue(page, 'app');

  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'app').length
  ))).toBe(1);
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();

  await setSearchValue(page, 'late');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'late').length
  ))).toBe(2);
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();
});

test('main wordset search starts one replacement when a pending request aborts synchronously', async ({ page }) => {
  await mountWordsetPage(page, {
    pendingAbortQuery: 'pending-a'
  });

  await setSearchValue(page, 'pending-a');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'pending-a').length
  ))).toBe(1);
  await expect(page.locator('[data-ll-wordset-page]')).toHaveClass(/is-category-search-loading/);

  await setSearchValue(page, 'app');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'app').length
  ))).toBe(1);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();

  await page.waitForTimeout(150);
  expect(await page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'app').length
  ))).toBe(1);
});

test('main wordset search leaves a bare 429 for manual Retry without a rapid loop', async ({ page }) => {
  await mountWordsetPage(page, {
    transientFailures: 1,
    transientQuery: 'limited',
    transientStatus: 429,
    retryBaseMs: 10
  });

  await setSearchValue(page, 'limited');

  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await page.waitForTimeout(150);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'limited').length
  ))).toBe(1);

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'limited').length
  ))).toBe(2);
  await expect(errorState).toBeHidden();
});

test('main wordset search does not automatically repeat a deterministic client failure', async ({ page }) => {
  await mountWordsetPage(page, {
    transientFailures: 1,
    transientQuery: 'denied',
    transientStatus: 400,
    retryBaseMs: 10
  });

  await setSearchValue(page, 'denied');

  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await page.waitForTimeout(120);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'denied').length
  ))).toBe(1);

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'denied').length
  ))).toBe(2);
  await expect(errorState).toBeHidden();
});

test('main wordset search reloads stale credentials once, restores the query, and fences a persistent 410', async ({ page }) => {
  const recovery = await mountReloadableStaleSearchPage(page, { persistentFreshFailure: true });

  await setSearchValue(page, 'qalem');
  let errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  expect(recovery.documentLoads()).toBe(1);

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();

  await expect.poll(() => recovery.documentLoads()).toBe(2);
  expect(recovery.documentUrls()).toEqual(['/genc/', '/genc/?ll_category_search_recovery=1']);
  expect(recovery.documentTokens()).toEqual(['stale-search-token', 'fresh-search-token']);
  await expect.poll(() => page.url()).toBe('https://wordset-search-recovery.test/genc/');
  await expect(page.locator('[data-ll-wordset-page-search]')).toHaveValue('qalem');
  errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await expect.poll(async () => page.evaluate(() => JSON.parse(
    window.sessionStorage.getItem('__staleSearchQueries') || '[]'
  ))).toEqual(['qalem', 'qalem']);
  const recoveryStorage = await page.evaluate(() => {
    const entries = {};
    for (let index = 0; index < window.sessionStorage.length; index += 1) {
      const key = String(window.sessionStorage.key(index) || '');
      if (key.indexOf('llToolsWordsetCategorySearchRecovery:') !== 0) {
        continue;
      }
      entries[key] = JSON.parse(window.sessionStorage.getItem(key) || '{}');
    }
    return entries;
  });
  const reloadFenceKey = Object.keys(recoveryStorage).find((key) => key.endsWith(':reload'));
  expect(JSON.stringify(recoveryStorage)).not.toContain('search-token');
  expect(reloadFenceKey).toBeTruthy();
  expect(recoveryStorage[reloadFenceKey]).not.toHaveProperty('query');
  expect(recoveryStorage[reloadFenceKey]).not.toHaveProperty('token');
  expect(recoveryStorage[reloadFenceKey]).not.toHaveProperty('credentialFingerprint');

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();
  await page.waitForTimeout(250);
  expect(recovery.documentLoads()).toBe(2);
  await expect(errorState).toBeVisible();
  await expect.poll(async () => page.evaluate(() => (
    parseInt(window.sessionStorage.getItem('__staleSearchRequestCount'), 10) || 0
  ))).toBe(2);
});

test('main wordset search bypasses a stale ordinary reload, restores the query, and succeeds with fresh page data', async ({ page }) => {
  const recovery = await mountReloadableStaleSearchPage(page);

  await setSearchValue(page, 'qalem');
  let errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();

  await page.reload();
  await expect.poll(() => recovery.documentLoads()).toBe(2);
  expect(recovery.documentUrls()).toEqual(['/genc/', '/genc/']);
  expect(recovery.documentTokens()).toEqual(['stale-search-token', 'stale-search-token']);

  await setSearchValue(page, 'qalem');
  errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await errorState.locator('[data-ll-wordset-page-search-retry]').click();

  await expect.poll(() => recovery.documentLoads()).toBe(3);
  expect(recovery.documentUrls()).toEqual([
    '/genc/',
    '/genc/',
    '/genc/?ll_category_search_recovery=1'
  ]);
  expect(recovery.documentTokens()).toEqual([
    'stale-search-token',
    'stale-search-token',
    'fresh-search-token'
  ]);
  await expect.poll(() => page.url()).toBe('https://wordset-search-recovery.test/genc/');
  await expect(page.locator('[data-ll-wordset-page-search]')).toHaveValue('qalem');
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeHidden();
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => JSON.parse(
    window.sessionStorage.getItem('__staleSearchQueries') || '[]'
  ))).toEqual(['qalem', 'qalem', 'qalem']);
});

test('main wordset search still reloads stale credentials when session storage is unavailable', async ({ page }) => {
  const recovery = await mountStorageUnavailableStaleSearchPage(page);

  await setSearchValue(page, 'qalem');
  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();

  await expect.poll(() => recovery.documentLoads()).toBe(2);
  expect(recovery.documentUrls()).toEqual(['/genc/', '/genc/?ll_category_search_recovery=1']);
  await expect.poll(() => page.url()).toBe('https://wordset-search-storage-unavailable.test/genc/');
  await page.waitForTimeout(200);
  expect(recovery.documentLoads()).toBe(2);
});

test('main wordset search recovery never persists invite or search credential strings', async ({ page }) => {
  const inviteSecret = 'invite-secret-7b1f9a';
  const recovery = await mountReloadableStaleSearchPage(page, {
    persistentFreshFailure: true,
    pageUrl: `https://wordset-search-recovery.test/genc/?ll_recorder_invite=${inviteSecret}&manager_token=private-manager-value`
  });

  await setSearchValue(page, 'qalem');
  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await errorState.locator('[data-ll-wordset-page-search-retry]').click();

  await expect.poll(() => recovery.documentLoads()).toBe(2);
  expect(recovery.documentUrls()[1]).toBe('/genc/?ll_category_search_recovery=1');
  await expect.poll(() => page.url()).toBe('https://wordset-search-recovery.test/genc/');
  await expect(page.locator('[data-ll-wordset-page-search-error]')).toBeVisible();
  const storageSnapshot = await page.evaluate(() => {
    const entries = {};
    for (let index = 0; index < window.sessionStorage.length; index += 1) {
      const key = String(window.sessionStorage.key(index) || '');
      entries[key] = String(window.sessionStorage.getItem(key) || '');
    }
    return entries;
  });
  const serialized = JSON.stringify(storageSnapshot);
  expect(serialized).not.toContain(inviteSecret);
  expect(serialized).not.toContain('private-manager-value');
  expect(serialized).not.toContain('fresh-search-token');
  expect(serialized).not.toContain('stale-search-token');
  expect(serialized).not.toContain('search-token');
  expect(serialized).not.toContain('ll_recorder_invite');
  expect(serialized).not.toContain('manager_token');
  const storedRecovery = Object.fromEntries(Object.entries(storageSnapshot)
    .filter(([key]) => key.indexOf('llToolsWordsetCategorySearchRecovery:') === 0)
    .map(([key, value]) => [key, JSON.parse(value || '{}')]));
  Object.values(storedRecovery).forEach((entry) => {
    expect(entry.path).toBe('/genc/');
    expect(entry).not.toHaveProperty('token');
  });
});

test('main wordset search recovery keeps double-slash paths on the current origin', async ({ page }) => {
  const recovery = await mountReloadableStaleSearchPage(page, {
    pageUrl: 'https://wordset-search-recovery.test//evil.example/genc/'
  });

  await setSearchValue(page, 'qalem');
  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await errorState.locator('[data-ll-wordset-page-search-retry]').click();

  await expect.poll(() => recovery.documentLoads()).toBe(2);
  expect(recovery.documentUrls()[1]).toBe('//evil.example/genc/?ll_category_search_recovery=1');
  await expect.poll(() => page.url()).toBe(
    'https://wordset-search-recovery.test//evil.example/genc/'
  );
});

test('main wordset search removes only the recovery marker from the visible URL', async ({ page }) => {
  await mountReloadableStaleSearchPage(page, {
    pageUrl: 'https://wordset-search-recovery.test/genc/?utm_source=kept&ll_category_search_recovery=1&view=compact'
  });

  await expect.poll(() => page.url()).toBe(
    'https://wordset-search-recovery.test/genc/?utm_source=kept&view=compact'
  );
});

test('main wordset search keeps provisional matches visible while the durable index warms', async ({ page }) => {
  await mountWordsetPage(page, {
    warmFailures: 100,
    warmQuery: 'fruit',
    retryBaseMs: 2000,
    maxRetries: 2,
    productionStyles: true
  });

  await setSearchValue(page, 'fruit');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBeGreaterThan(0);
  await expect.poll(async () => page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
    .filter((card) => !card.hidden)
    .map((card) => Number(card.getAttribute('data-cat-id'))))).toEqual([11]);
  await expect(page.locator('[data-ll-wordset-page]')).toHaveClass(/is-category-search-loading/);

  const provisionalCardState = await page.locator('.ll-wordset-card[data-cat-id="11"]').evaluate((card) => {
    const styles = window.getComputedStyle(card);
    return {
      opacity: styles.opacity,
      pointerEvents: styles.pointerEvents
    };
  });
  expect(provisionalCardState).toEqual({
    opacity: '1',
    pointerEvents: 'auto'
  });
});

test('main wordset search shows a retry state instead of a false empty result after warming exhausts', async ({ page }) => {
  await mountWordsetPage(page, {
    warmFailures: 7,
    retryBaseMs: 10,
    maxRetries: 6
  });

  await setSearchValue(page, 'warm');

  const errorState = page.locator('[data-ll-wordset-page-search-error]');
  await expect(errorState).toBeVisible();
  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'warm').length
  ))).toBe(7);

  await errorState.locator('[data-ll-wordset-page-search-retry]').click();
  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11]);
  await expect(errorState).toBeHidden();
  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();
});

test('main wordset search Retry button resists hostile theme hover and focus styles', async ({ page }) => {
  await mountWordsetPage(page, {
    transientFailures: 1,
    transientQuery: 'theme',
    transientStatus: 403,
    productionStyles: true
  });
  await page.addStyleTag({
    content: `
      .ll-wordset-page button,
      .ll-wordset-page button:hover,
      .ll-wordset-page button:focus,
      .ll-wordset-page button:active {
        background: #f97316 !important;
        border: 4px solid #ea580c !important;
        border-radius: 0 !important;
        color: #431407 !important;
        box-shadow: 0 0 0 8px #fed7aa !important;
      }
    `
  });

  await setSearchValue(page, 'theme');

  const retry = page.locator('[data-ll-wordset-page-search-retry]');
  await expect(retry).toBeVisible();
  await expect(retry).toHaveCSS('background-color', 'rgb(255, 255, 255)');
  await expect(retry).toHaveCSS('color', 'rgb(36, 52, 71)');
  await expect(retry).toHaveCSS('border-radius', '999px');

  await retry.hover();
  await expect(retry).toHaveCSS('background-color', 'rgb(244, 247, 249)');
  await expect(retry).toHaveCSS('color', 'rgb(23, 37, 54)');
  await expect(retry).toHaveCSS('border-top-color', 'rgb(101, 120, 138)');

  await page.mouse.move(0, 0);
  await retry.focus();
  await expect(retry).toHaveCSS('background-color', 'rgb(244, 247, 249)');
  await expect(retry).toHaveCSS('color', 'rgb(23, 37, 54)');
  await expect(retry).toHaveCSS('outline-style', 'solid');
  await expect(retry).toHaveCSS('outline-width', '3px');
  await expect(retry).toHaveCSS('outline-color', 'rgb(36, 52, 71)');

  await page.emulateMedia({ forcedColors: 'active' });
  await expect(retry).toHaveCSS('forced-color-adjust', 'auto');
  await expect(retry).toHaveCSS('outline-style', 'solid');
});

test('main wordset search stops warming when a visible result link is activated', async ({ page }) => {
  await mountWordsetPage(page, {
    warmFailures: 100,
    warmQuery: 'fruit',
    retryBaseMs: 40
  });

  await setSearchValue(page, 'fruit');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBeGreaterThan(0);
  await expect(page.locator('.ll-wordset-card[data-cat-id="11"]')).toBeVisible();

  await page.locator('.ll-wordset-card[data-cat-id="11"] .ll-wordset-card__heading').click();
  const requestCountAfterActivation = await page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ));

  await page.waitForTimeout(750);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(requestCountAfterActivation);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
});

test('main wordset search pauses warming during a result quiz launch and resumes after close', async ({ page }) => {
  await mountWordsetPage(page, {
    warmFailures: 100,
    warmQuery: 'fruit',
    retryBaseMs: 40,
    stallNonSearchAjax: true
  });

  await setSearchValue(page, 'fruit');
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBeGreaterThan(0);

  await page.locator('[data-ll-wordset-category-mode][data-cat-id="11"]').click();
  await expect(page.locator('#ll-tools-flashcard-popup')).toHaveCSS('display', 'block');
  const requestCountDuringLaunch = await page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ));

  await page.waitForTimeout(750);
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBe(requestCountDuringLaunch);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:flashcard-closed');
  });
  await expect.poll(async () => page.evaluate(() => (
    (window.__categorySearchRequests || []).filter((request) => request.query === 'fruit').length
  ))).toBeGreaterThan(requestCountDuringLaunch);
});

test('main wordset search keeps a cached result render scheduled when a quiz opens', async ({ page }) => {
  await mountWordsetPage(page, { stallNonSearchAjax: true });

  await setSearchValue(page, 'fruit');
  await expect.poll(async () => page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
    .filter((card) => !card.hidden)
    .map((card) => Number(card.getAttribute('data-cat-id'))))).toEqual([11]);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);

  await setSearchValue(page, '');
  await expect.poll(async () => page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
    .filter((card) => !card.hidden)
    .map((card) => Number(card.getAttribute('data-cat-id'))))).toEqual([11, 22, 33, 44]);

  await setSearchValue(page, 'fruit');
  await page.locator('[data-ll-wordset-category-mode][data-cat-id="11"]').click();
  await expect(page.locator('#ll-tools-flashcard-popup')).toHaveCSS('display', 'block');
  await expect.poll(async () => page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
    .filter((card) => !card.hidden)
    .map((card) => Number(card.getAttribute('data-cat-id'))))).toEqual([11]);
  await expect(page.locator('[data-ll-wordset-page]')).not.toHaveClass(/is-category-search-loading/);
});

test('main wordset search hides the add category card while filtering', async ({ page }) => {
  await mountWordsetPage(page);

  const addCategoryCard = page.locator('[data-ll-wordset-card-type="add-category"]');
  await expect(addCategoryCard).toBeVisible();

  await setSearchValue(page, 'app');

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11]);
  await expect(addCategoryCard).toBeHidden();

  await setSearchValue(page, '');

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11, 22, 33, 44]);
  await expect(addCategoryCard).toBeVisible();
});

test('main wordset search ranks category title matches above word matches and shows matched word context', async ({ page }) => {
  await mountWordsetPage(page);

  await setSearchValue(page, 'din');

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([44, 33, 11]);

  await expect(page.locator('.ll-wordset-card[data-cat-id="44"] [data-ll-wordset-search-match]')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-card[data-cat-id="33"] [data-ll-wordset-search-match]')).toContainText('dinner');
  await expect(page.locator('.ll-wordset-card[data-cat-id="33"] [data-ll-wordset-search-match] mark')).toHaveText('din');
});

test('main wordset search clear button appears only for active filters and resets visible cards', async ({ page }) => {
  await mountWordsetPage(page);

  const clearButton = page.locator('[data-ll-wordset-page-search-clear]');
  await expect(clearButton).toBeHidden();

  await setSearchValue(page, 'app');
  await expect(clearButton).toBeVisible();

  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11]);

  await clearButton.click();

  await expect(page.locator('[data-ll-wordset-page-search]')).toHaveValue('');
  await expect(clearButton).toBeHidden();
  await expect.poll(async () => {
    return page.evaluate(() => Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]'))
      .filter((card) => !card.hidden)
      .map((card) => Number(card.getAttribute('data-cat-id'))));
  }).toEqual([11, 22, 33, 44]);
  await expect(page.locator('[data-ll-wordset-page-search-empty]')).toBeHidden();
});
