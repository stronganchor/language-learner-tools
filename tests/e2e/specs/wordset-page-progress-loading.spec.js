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
        <button type="button" data-ll-wordset-select-all>Select all</button>
        <article class="ll-wordset-card" data-cat-id="11" style="margin-top: 1800px;">
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

function buildPageConfig() {
  return {
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
}

async function mountWordsetPage(page) {
  const config = buildPageConfig();

  await page.goto('about:blank');
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(buildWordsetMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrapConfig) => {
    window.llWordsetPageData = bootstrapConfig;
    window.__llAnalyticsRequests = [];

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
    window.__resolveAnalyticsRequest(1, {
      scope: {
        wordset_id: 77,
        category_ids: [11],
        category_count: 1,
        mode: 'all'
      },
      summary: {
        total_words: 20,
        mastered_words: 5,
        studied_words: 12,
        new_words: 8,
        hard_words: 0,
        starred_words: 0
      },
      daily_activity: {
        days: [],
        max_events: 0,
        window_days: 14
      },
      categories: [
        {
          id: 11,
          label: 'Cat A',
          word_count: 20,
          mastered_words: 5,
          studied_words: 12,
          new_words: 8,
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
    });
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
