const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

function buildCardMarkup(category) {
  const cat = category || {};
  const extraStyle = String(cat.extraStyle || '').trim();
  const styleAttr = extraStyle ? ` style="${extraStyle}"` : '';
  return `
    <article class="ll-wordset-card" role="listitem" data-cat-id="${cat.id}" data-word-count="${cat.count}"${styleAttr}>
      <div class="ll-wordset-card__top">
        <label class="ll-wordset-card__select" aria-label="Select ${cat.name}">
          <input type="checkbox" value="${cat.id}" data-ll-wordset-select />
          <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
        </label>
        <a class="ll-wordset-card__heading" href="#" aria-label="${cat.name}">
          <h2 class="ll-wordset-card__title">${cat.name}</h2>
        </a>
        <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
      </div>
      <div class="ll-wordset-card__progress" aria-hidden="true">
        <span class="ll-wordset-card__progress-track">
          <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered" style="width: 0%;"></span>
          <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied" style="width: 0%;"></span>
          <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width: 100%;"></span>
        </span>
      </div>
    </article>
  `;
}

function buildSortControls() {
  return `
    <div class="ll-wordset-grid-search-tools">
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
      <div class="ll-wordset-main-sort" data-ll-wordset-main-sort-root>
        <button
          type="button"
          class="ll-wordset-main-sort__toggle"
          data-ll-wordset-main-sort-toggle
          aria-expanded="false"
          aria-haspopup="menu"
          aria-controls="ll-wordset-main-sort-menu-77"
          aria-label="Sort categories"
          title="Sort categories">
          <span aria-hidden="true">Sort</span>
        </button>
        <div
          id="ll-wordset-main-sort-menu-77"
          class="ll-wordset-main-sort__menu"
          data-ll-wordset-main-sort-menu
          role="menu"
          hidden>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="default" role="menuitemradio" aria-checked="true">
            <span class="ll-wordset-main-sort__option-label">Default</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="alpha-asc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">A-Z</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="alpha-desc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">Z-A</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="progress-desc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">More learned</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="progress-asc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">Less learned</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="recent-desc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">Recently studied</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
          <button type="button" class="ll-wordset-main-sort__option" data-ll-wordset-main-sort-option="recent-asc" role="menuitemradio" aria-checked="false">
            <span class="ll-wordset-main-sort__option-label">Not recently studied</span>
            <span class="ll-wordset-main-sort__option-check" aria-hidden="true"></span>
          </button>
        </div>
      </div>
    </div>
  `;
}

function buildMarkup(categories, options = {}) {
  const opts = options || {};
  const includeLazyRoot = !!opts.includeLazyRoot;
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <div class="ll-wordset-grid-tools">
        ${buildSortControls()}
        <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-select-all aria-pressed="false">Select all</button>
      </div>

      <div class="ll-wordset-grid" role="list" data-ll-wordset-main-grid>
        ${categories.map((category) => buildCardMarkup(category)).join('\n')}
      </div>

      ${includeLazyRoot ? `
      <div class="ll-wordset-grid-lazy" data-ll-wordset-lazy-root>
        <span class="ll-wordset-grid-lazy__status screen-reader-text" data-ll-wordset-load-more-status aria-live="polite"></span>
        <div class="ll-wordset-grid-lazy__placeholders" data-ll-wordset-load-more-placeholders aria-hidden="true"></div>
        <span class="ll-wordset-grid-lazy__sentinel" data-ll-wordset-load-more-sentinel aria-hidden="true"></span>
      </div>
      ` : ''}

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

function buildConfig(categories, overrides = {}) {
  return Object.assign({
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: 'nonce-1',
    isLoggedIn: true,
    wordsetId: 77,
    wordsetSlug: 'sort-wordset',
    wordsetName: 'Sort Wordset',
    links: {
      base: '/wordsets/sort-wordset/',
      progress: '/wordsets/sort-wordset/progress/',
      hidden: '/wordsets/sort-wordset/hidden-categories/',
      settings: '/wordsets/sort-wordset/settings/'
    },
    progressIncludeHidden: false,
    categories,
    visibleCategoryIds: categories.map((category) => category.id),
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
      new: categories.reduce((total, category) => total + Number(category.count || 0), 0),
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
  }, overrides || {});
}

function buildAnalytics(categories) {
  const categoryRows = categories.map((category) => ({
    id: category.id,
    label: category.translation || category.name,
    word_count: category.count,
    mastered_words: category.mastered_words || 0,
    studied_words: category.studied_words || 0,
    new_words: category.new_words || Math.max(0, Number(category.count || 0) - Number(category.studied_words || 0)),
    exposure_total: 0,
    exposure_by_mode: {
      learning: 0,
      practice: 0,
      listening: 0,
      gender: 0,
      'self-check': 0
    },
    last_mode: 'practice',
    last_seen_at: category.last_seen_at || ''
  }));

  const totalWords = categoryRows.reduce((sum, row) => sum + Number(row.word_count || 0), 0);
  const masteredWords = categoryRows.reduce((sum, row) => sum + Number(row.mastered_words || 0), 0);
  const studiedWords = categoryRows.reduce((sum, row) => sum + Number(row.studied_words || 0), 0);
  const newWords = categoryRows.reduce((sum, row) => sum + Number(row.new_words || 0), 0);

  return {
    scope: {
      wordset_id: 77,
      category_ids: categories.map((category) => category.id),
      category_count: categories.length,
      mode: 'all'
    },
    summary: {
      total_words: totalWords,
      mastered_words: masteredWords,
      studied_words: studiedWords,
      new_words: newWords,
      hard_words: 0,
      starred_words: 0
    },
    daily_activity: {
      days: [],
      max_events: 0,
      window_days: 14
    },
    categories: categoryRows,
    words: [],
    generated_at: '2026-04-20T12:00:00Z'
  };
}

async function mountWordsetPage(page, options = {}) {
  const categories = options.categories || [];
  const initialCategories = Array.isArray(options.initialCategories) ? options.initialCategories : categories;
  const remainingCards = Array.isArray(options.remainingCards) ? options.remainingCards : [];
  const analytics = options.analytics || buildAnalytics(categories);
  const config = buildConfig(categories, options.configOverrides || {});
  const analyticsDelayMs = Number(options.analyticsDelayMs || 0);
  const storedSort = String(options.storedSort || '').trim();

  await page.goto('about:blank');
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(buildMarkup(initialCategories, {
    includeLazyRoot: !!(config.lazyCards && config.lazyCards.enabled)
  }));
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(({ configValue, analyticsValue, analyticsDelayValue, remainingCardsValue }) => {
    window.llWordsetPageData = configValue;
    window.alert = function () {};
    window.__llLazyAjaxCalls = [];

    const $ = window.jQuery;
    $.post = function () {
      const deferred = $.Deferred();
      window.setTimeout(() => {
        deferred.resolve({
          success: true,
          data: {
            analytics: analyticsValue,
            next_activity: null,
            recommendation_queue: []
          }
        });
      }, analyticsDelayValue);
      return deferred.promise();
    };

    $.ajax = function (options) {
      const deferred = $.Deferred();
      const data = (options && options.data) || {};
      window.__llLazyAjaxCalls.push(data);

      const offset = Number.parseInt(data.offset, 10) || 0;
      const count = Math.max(1, Number.parseInt(data.count, 10) || 1);
      const startIndex = Math.max(0, offset);
      const nextCards = remainingCardsValue.slice(startIndex, startIndex + count);
      const html = nextCards.map((card) => {
        return [
          '<article class=\"ll-wordset-card\" role=\"listitem\" data-cat-id=\"' + card.id + '\" data-word-count=\"' + card.count + '\">',
          '  <div class=\"ll-wordset-card__top\">',
          '    <label class=\"ll-wordset-card__select\" aria-label=\"Select ' + card.name + '\">',
          '      <input type=\"checkbox\" value=\"' + card.id + '\" data-ll-wordset-select />',
          '      <span class=\"ll-wordset-card__select-box\" aria-hidden=\"true\"></span>',
          '    </label>',
          '    <a class=\"ll-wordset-card__heading\" href=\"#\" aria-label=\"' + card.name + '\">',
          '      <h2 class=\"ll-wordset-card__title\">' + card.name + '</h2>',
          '    </a>',
          '    <span class=\"ll-wordset-card__hide-spacer\" aria-hidden=\"true\"></span>',
          '  </div>',
          '  <div class=\"ll-wordset-card__progress\" aria-hidden=\"true\">',
          '    <span class=\"ll-wordset-card__progress-track\">',
          '      <span class=\"ll-wordset-card__progress-segment ll-wordset-card__progress-segment--mastered\" style=\"width: 0%;\"></span>',
          '      <span class=\"ll-wordset-card__progress-segment ll-wordset-card__progress-segment--studied\" style=\"width: 0%;\"></span>',
          '      <span class=\"ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new\" style=\"width: 100%;\"></span>',
          '    </span>',
          '  </div>',
          '</article>'
        ].join('');
      }).join('');

      window.setTimeout(() => {
        deferred.resolve({
          success: true,
          data: {
            html,
            loaded: Math.min(remainingCardsValue.length, startIndex + nextCards.length),
            nextOffset: Math.min(remainingCardsValue.length, startIndex + nextCards.length),
            hasMore: (startIndex + nextCards.length) < remainingCardsValue.length
          }
        });
      }, 40);

      return deferred.promise();
    };
  }, {
    configValue: config,
    analyticsValue: analytics,
    analyticsDelayValue: analyticsDelayMs,
    remainingCardsValue: remainingCards
  });

  if (storedSort) {
    await page.evaluate((value) => {
      const storage = new Map();
      Object.defineProperty(window, 'localStorage', {
        configurable: true,
        value: {
          getItem(key) {
            return storage.has(key) ? storage.get(key) : null;
          },
          setItem(key, nextValue) {
            storage.set(key, String(nextValue));
          },
          removeItem(key) {
            storage.delete(key);
          }
        }
      });
      window.localStorage.setItem('llToolsWordsetMainSort:77', value);
    }, storedSort);
  }

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function getRenderedCategoryOrder(page) {
  return page.locator('.ll-wordset-card[data-cat-id] .ll-wordset-card__title').evaluateAll((nodes) =>
    nodes.map((node) => (node.textContent || '').trim())
  );
}

async function getRenderedCategorySlots(page) {
  return page.locator('.ll-wordset-card[data-cat-id]').evaluateAll((nodes) =>
    nodes.map((node) => {
      const titleNode = node.querySelector('.ll-wordset-card__title');
      return {
        id: Number.parseInt(node.getAttribute('data-cat-id') || '0', 10) || 0,
        placeholder: node.hasAttribute('data-ll-wordset-inline-placeholder'),
        title: titleNode ? (titleNode.textContent || '').trim() : ''
      };
    })
  );
}

test('wordset page sort menu reorders categories by alpha, progress, and recency', async ({ page }) => {
  const categories = [
    {
      id: 33,
      slug: 'travel',
      name: 'Travel',
      translation: 'Travel',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'plane train hotel',
      preview: [],
      mastered_words: 0,
      studied_words: 2,
      new_words: 8,
      last_seen_at: '2026-04-19 09:00:00'
    },
    {
      id: 11,
      slug: 'fruit',
      name: 'Fruit',
      translation: 'Fruit',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'apple pear banana',
      preview: [],
      mastered_words: 6,
      studied_words: 8,
      new_words: 2,
      last_seen_at: '2026-04-15 12:00:00'
    },
    {
      id: 22,
      slug: 'animals',
      name: 'Animals',
      translation: 'Animals',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'cat dog bird',
      preview: [],
      mastered_words: 4,
      studied_words: 5,
      new_words: 5,
      last_seen_at: ''
    }
  ];

  await mountWordsetPage(page, { categories });
  await expect(page.locator('[data-ll-wordset-main-sort-toggle]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-main-sort-option="progress-desc"]')).toBeAttached();
  await expect(page.locator('[data-ll-wordset-main-sort-menu]')).toBeHidden();

  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Travel', 'Fruit', 'Animals']);

  await page.click('[data-ll-wordset-main-sort-toggle]');
  await expect(page.locator('[data-ll-wordset-main-sort-menu]')).toBeVisible();
  await page.click('[data-ll-wordset-main-sort-option="alpha-asc"]');
  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Animals', 'Fruit', 'Travel']);

  await page.click('[data-ll-wordset-main-sort-toggle]');
  await page.click('[data-ll-wordset-main-sort-option="progress-desc"]');
  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Fruit', 'Animals', 'Travel']);

  await page.click('[data-ll-wordset-main-sort-toggle]');
  await page.click('[data-ll-wordset-main-sort-option="recent-desc"]');
  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Travel', 'Fruit', 'Animals']);
});

test('deferred metrics apply an active progress sort after analytics loads', async ({ page }) => {
  const categories = [
    {
      id: 33,
      slug: 'travel',
      name: 'Travel',
      translation: 'Travel',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'plane train hotel',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    },
    {
      id: 11,
      slug: 'fruit',
      name: 'Fruit',
      translation: 'Fruit',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'apple pear banana',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    },
    {
      id: 22,
      slug: 'animals',
      name: 'Animals',
      translation: 'Animals',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'cat dog bird',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    }
  ];

  const analyticsCategories = [
    Object.assign({}, categories[0], {
      mastered_words: 0,
      studied_words: 2,
      new_words: 8,
      last_seen_at: '2026-04-19 09:00:00'
    }),
    Object.assign({}, categories[1], {
      mastered_words: 6,
      studied_words: 8,
      new_words: 2,
      last_seen_at: '2026-04-15 12:00:00'
    }),
    Object.assign({}, categories[2], {
      mastered_words: 4,
      studied_words: 5,
      new_words: 5,
      last_seen_at: ''
    })
  ];

  await mountWordsetPage(page, {
    categories,
    analytics: buildAnalytics(analyticsCategories),
    configOverrides: {
      summaryCountsDeferred: true
    },
    analyticsDelayMs: 300
  });

  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Travel', 'Fruit', 'Animals']);
  await page.click('[data-ll-wordset-main-sort-toggle]');
  await expect(page.locator('[data-ll-wordset-main-sort-menu]')).toBeVisible();
  await page.click('[data-ll-wordset-main-sort-option="progress-desc"]');
  await expect.poll(() => getRenderedCategoryOrder(page)).toEqual(['Fruit', 'Animals', 'Travel']);
});

test('changing sort materializes the top sorted rows while deeper slots stay lazy', async ({ page }) => {
  const categories = [
    {
      id: 33,
      slug: 'travel',
      name: 'Travel',
      translation: 'Travel',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'plane train hotel',
      preview: []
    },
    {
      id: 66,
      slug: 'numbers',
      name: 'Numbers',
      translation: 'Numbers',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'one two three',
      preview: []
    },
    {
      id: 77,
      slug: 'school',
      name: 'School',
      translation: 'School',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'teacher book class',
      preview: []
    },
    {
      id: 88,
      slug: 'weather',
      name: 'Weather',
      translation: 'Weather',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'rain sun cloud',
      preview: []
    },
    {
      id: 11,
      slug: 'fruit',
      name: 'Fruit',
      translation: 'Fruit',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'apple pear banana',
      preview: []
    },
    {
      id: 55,
      slug: 'colors',
      name: 'Colors',
      translation: 'Colors',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'red blue green',
      preview: []
    },
    {
      id: 44,
      slug: 'body',
      name: 'Body',
      translation: 'Body',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'hand eye foot',
      preview: []
    },
    {
      id: 22,
      slug: 'animals',
      name: 'Animals',
      translation: 'Animals',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'cat dog bird',
      preview: []
    }
  ];

  await mountWordsetPage(page, {
    categories,
    initialCategories: [
      Object.assign({}, categories[0], {
        extraStyle: 'margin-top: 1600px;'
      })
    ],
    remainingCards: categories,
    configOverrides: {
      lazyCards: {
        enabled: true,
        nonce: 'lazy-nonce',
        token: 'lazy-token',
        wordsetId: 77,
        previewLimit: 2,
        batchSize: 3,
        initialCount: 1,
        loaded: 1,
        total: 8,
        remaining: 7
      }
    }
  });

  await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(1);

  await page.click('[data-ll-wordset-main-sort-toggle]');
  await expect(page.locator('[data-ll-wordset-main-sort-menu]')).toBeVisible();
  await page.click('[data-ll-wordset-main-sort-option="alpha-asc"]');

  await expect.poll(async () => {
    return (await getRenderedCategorySlots(page)).map((slot) => slot.id);
  }).toEqual([22, 44, 55, 11, 66, 77, 33, 88]);
  await expect.poll(async () => {
    return (await getRenderedCategorySlots(page)).slice(0, 2).every((slot) => !slot.placeholder);
  }).toBe(true);

  const afterSortSlots = await getRenderedCategorySlots(page);
  expect(afterSortSlots.slice(2).some((slot) => slot.placeholder)).toBe(true);
  await expect.poll(async () => {
    return page.evaluate(() => window.__llLazyAjaxCalls.length);
  }).toBe(0);

  await page.evaluate(() => {
    window.scrollTo(0, document.documentElement.scrollHeight || document.body.scrollHeight || 0);
    window.dispatchEvent(new Event('scroll'));
  });

  await expect.poll(async () => {
    const slots = await getRenderedCategorySlots(page);
    return slots.find((slot) => slot.id === 88) || null;
  }).toEqual({ id: 88, placeholder: false, title: 'Weather' });

  await expect.poll(async () => {
    const slots = await getRenderedCategorySlots(page);
    return slots.slice(0, 6).every((slot) => !slot.placeholder);
  }).toBe(true);

  const afterLoadSlots = await getRenderedCategorySlots(page);
  expect(afterLoadSlots.map((slot) => slot.id)).toEqual([22, 44, 55, 11, 66, 77, 33, 88]);
  expect(afterLoadSlots[0]).toEqual({ id: 22, placeholder: false, title: 'Animals' });
  expect(afterLoadSlots[3]).toEqual({ id: 11, placeholder: false, title: 'Fruit' });
  expect(afterLoadSlots[6]).toEqual({ id: 33, placeholder: false, title: 'Travel' });
  expect(afterLoadSlots[7]).toEqual({ id: 88, placeholder: false, title: 'Weather' });
});

test('saved sort preferences do not force-load all lazy cards on page init', async ({ page }) => {
  const categories = [
    {
      id: 33,
      slug: 'travel',
      name: 'Travel',
      translation: 'Travel',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'plane train hotel',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    },
    {
      id: 11,
      slug: 'fruit',
      name: 'Fruit',
      translation: 'Fruit',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'apple pear banana',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    },
    {
      id: 22,
      slug: 'animals',
      name: 'Animals',
      translation: 'Animals',
      count: 10,
      url: '#',
      mode: 'image',
      prompt_type: 'audio',
      option_type: 'image',
      learning_supported: true,
      gender_supported: false,
      aspect_bucket: 'ratio:1_1',
      hidden: false,
      search_text: 'cat dog bird',
      preview: [],
      mastered_words: 0,
      studied_words: 0,
      new_words: 10,
      last_seen_at: ''
    }
  ];

  const analyticsCategories = [
    Object.assign({}, categories[0], {
      mastered_words: 0,
      studied_words: 2,
      new_words: 8,
      last_seen_at: '2026-04-19 09:00:00'
    }),
    Object.assign({}, categories[1], {
      mastered_words: 6,
      studied_words: 8,
      new_words: 2,
      last_seen_at: '2026-04-15 12:00:00'
    }),
    Object.assign({}, categories[2], {
      mastered_words: 4,
      studied_words: 5,
      new_words: 5,
      last_seen_at: ''
    })
  ];

  await mountWordsetPage(page, {
    categories,
    initialCategories: [
      Object.assign({}, categories[0], {
        extraStyle: 'margin-top: 1600px;'
      })
    ],
    remainingCards: categories,
    analytics: buildAnalytics(analyticsCategories),
    analyticsDelayMs: 200,
    storedSort: 'recent-desc',
    configOverrides: {
      summaryCountsDeferred: true,
      lazyCards: {
        enabled: true,
        nonce: 'lazy-nonce',
        token: 'lazy-token',
        wordsetId: 77,
        previewLimit: 2,
        batchSize: 1,
        initialCount: 1,
        loaded: 1,
        total: 3,
        remaining: 2
      }
    }
  });

  await expect.poll(() => getRenderedCategorySlots(page)).toEqual([
    { id: 33, placeholder: false, title: 'Travel' },
    { id: 11, placeholder: false, title: 'Fruit' },
    { id: 22, placeholder: false, title: 'Animals' }
  ]);
  await expect.poll(async () => {
    return page.evaluate(() => window.__llLazyAjaxCalls.length);
  }).toBe(0);
});
