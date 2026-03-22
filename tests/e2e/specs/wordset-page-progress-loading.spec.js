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
    generated_at: '2026-03-10T00:00:00Z'
  };
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

test('offscreen loading progress bars keep the loading mask until real category progress is applied', async ({ page }) => {
  await mountWordsetPage(page);

  await page.evaluate((payload) => {
    window.__nextAnalyticsPayload = payload;
  }, buildAnalytics({ masteredWords: 5, studiedWords: 12, newWords: 8 }));

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAnalyticsRequests) ? window.__llAnalyticsRequests.length : 0);
  }).toBe(1);

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
