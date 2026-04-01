const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);
const wordsetCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

function buildAnalytics({
  totalWords = 640,
  masteredWords = 128,
  studiedWords = 384,
  newWords = 256,
  hardWords = 48,
  starredWords = 32,
  categoryId = 11,
  label = 'Cat A'
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
    words: [],
    generated_at: '2026-03-26T00:00:00Z'
  };
}

function buildMarkup({
  summaryCounts = {
    mastered: 0,
    studied: 0,
    new: 20,
    starred: 0,
    hard: 0
  },
  summaryCountsDeferred = true
} = {}) {
  return `
    <style>${wordsetCssSource}</style>
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <header class="ll-wordset-hero">
        <div class="ll-wordset-hero__title-wrap">
          <div class="ll-wordset-hero__icon" aria-hidden="true">
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
          </div>
          <h1 class="ll-wordset-title">Test Wordset</h1>
        </div>
        <div class="ll-wordset-hero__tools">
          <div class="ll-wordset-hero__action-links">
            <a
              class="ll-wordset-link-chip ll-wordset-link-chip--hidden"
              data-ll-wordset-hidden-link
              href="#hidden"
              aria-label="Hidden categories: 0"
              hidden
            >
              <span class="ll-wordset-link-chip__icon" aria-hidden="true"></span>
              <span class="ll-wordset-link-chip__count" data-ll-wordset-hidden-count>0</span>
            </a>
            <a
              class="ll-wordset-link-chip ll-wordset-link-chip--games"
              href="#games"
              aria-label="Open games"
            >
              <span class="ll-wordset-link-chip__icon" aria-hidden="true"></span>
              <span class="ll-wordset-link-chip__label">Games</span>
            </a>
            <a class="ll-wordset-settings-link ll-tools-settings-button" href="#settings" aria-label="Word set tools">
              <span class="mode-icon" aria-hidden="true">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </span>
            </a>
          </div>
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
        </div>
      </header>

      <div class="ll-wordset-grid">
        <button type="button" data-ll-wordset-select-all>Select all</button>
        <article class="ll-wordset-card" data-cat-id="11">
          <label>
            <input type="checkbox" data-ll-wordset-select value="11" />
            Cat A
          </label>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track is-loading">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered" style="width: 0%;"></span>
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied" style="width: 0%;"></span>
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
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

function buildConfig(overrides = {}) {
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
      games: '/wordsets/test-wordset/games/',
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
        count: 640,
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

async function mountWordsetHeroHarness(page, options = {}) {
  const config = buildConfig(options.config || {});
  const markup = buildMarkup({
    summaryCounts: config.summaryCounts,
    summaryCountsDeferred: config.summaryCountsDeferred
  });

  await page.goto('about:blank');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(markup);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrapConfig) => {
    window.llWordsetPageData = bootstrapConfig;
    window.__llAnalyticsRequests = [];

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
  }, config);

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function measureHeroTools(page) {
  return page.evaluate(() => {
    const readRect = (selector) => {
      const el = document.querySelector(selector);
      if (!el) {
        return null;
      }
      const rect = el.getBoundingClientRect();
      return {
        top: rect.top,
        left: rect.left,
        right: rect.right,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height
      };
    };

    const readAnchorHref = (selector) => {
      const el = document.querySelector(selector);
      if (!el) {
        return null;
      }
      const rect = el.getBoundingClientRect();
      const target = document.elementFromPoint(rect.left + (rect.width / 2), rect.top + (rect.height / 2));
      const anchor = target ? target.closest('a') : null;
      return anchor ? anchor.getAttribute('href') : null;
    };

    return {
      toolsDisplay: window.getComputedStyle(document.querySelector('.ll-wordset-hero__tools')).display,
      games: readRect('.ll-wordset-link-chip--games'),
      settings: readRect('.ll-wordset-settings-link'),
      progress: readRect('[data-ll-wordset-progress-mini-root]'),
      gamesHref: readAnchorHref('.ll-wordset-link-chip--games'),
      settingsHref: readAnchorHref('.ll-wordset-settings-link')
    };
  });
}

test('mobile hero keeps the games chip anchored while progress metrics hydrate', async ({ page }) => {
  await mountWordsetHeroHarness(page);

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

  const initial = await measureHeroTools(page);

  expect(initial.toolsDisplay).toBe('flex');
  expect(initial.games).not.toBeNull();
  expect(initial.settings).not.toBeNull();
  expect(initial.progress).not.toBeNull();
  expect(initial.progress.top - initial.games.top).toBeGreaterThan(30);
  expect(initial.settings.top).toBe(initial.games.top);
  expect(initial.settings.left - initial.games.right).toBeGreaterThanOrEqual(8);
  expect(initial.gamesHref).toBe('#games');
  expect(initial.settingsHref).toBe('#settings');

  await page.evaluate((payload) => {
    window.__resolveAnalyticsRequest(0, payload);
  }, buildAnalytics());

  await expect(page.locator('[data-ll-wordset-progress-mini-root]')).not.toHaveClass(/is-loading/);

  await page.waitForTimeout(180);
  const duringAnimation = await measureHeroTools(page);

  expect(duringAnimation.progress.top - duringAnimation.games.top).toBeGreaterThan(30);
  expect(Math.abs(duringAnimation.games.top - initial.games.top)).toBeLessThanOrEqual(1);
  expect(Math.abs(duringAnimation.games.left - initial.games.left)).toBeLessThanOrEqual(1);
  expect(Math.abs(duringAnimation.settings.top - initial.settings.top)).toBeLessThanOrEqual(1);
  expect(Math.abs(duringAnimation.settings.left - initial.settings.left)).toBeLessThanOrEqual(1);
  expect(duringAnimation.gamesHref).toBe('#games');
  expect(duringAnimation.settingsHref).toBe('#settings');

  await page.waitForTimeout(1400);
  const settled = await measureHeroTools(page);

  expect(settled.progress.top - settled.games.top).toBeGreaterThan(30);
  expect(Math.abs(settled.games.top - initial.games.top)).toBeLessThanOrEqual(1);
  expect(Math.abs(settled.games.left - initial.games.left)).toBeLessThanOrEqual(1);
  expect(Math.abs(settled.settings.top - initial.settings.top)).toBeLessThanOrEqual(1);
  expect(Math.abs(settled.settings.left - initial.settings.left)).toBeLessThanOrEqual(1);
  expect(settled.gamesHref).toBe('#games');
  expect(settled.settingsHref).toBe('#settings');
});
