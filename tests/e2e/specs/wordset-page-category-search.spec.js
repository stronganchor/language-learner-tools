const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
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
          <span class="ll-wordset-progress-search__loading" data-ll-wordset-page-search-loading hidden aria-hidden="true"></span>
        </div>
        <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-select-all aria-pressed="false">Select all</button>
      </div>

      <div class="ll-wordset-grid" role="list">
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
      </div>

      <div class="ll-wordset-empty ll-wordset-empty--search" data-ll-wordset-page-search-empty hidden>
        No categories match this search.
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
        search_text: 'apple elma banana muz pear armut',
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
        search_text: 'cat kedi dog kopek bird kus',
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
        search_text: 'plane ucak train tren hotel otel',
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
    i18n: {
      selectionLabel: 'Select categories to study together',
      selectionWordsOnly: '%d words',
      selectAll: 'Select all',
      deselectAll: 'Deselect all'
    }
  };
}

async function mountWordsetPage(page) {
  await page.goto('about:blank');
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(buildMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((config) => {
    window.llWordsetPageData = config;
    window.alert = function () {};

    const $ = window.jQuery;
    $.post = function () {
      const deferred = $.Deferred();
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
  }, buildConfig());

  await page.addScriptTag({ content: wordsetScriptSource });
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

  await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(3);

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
