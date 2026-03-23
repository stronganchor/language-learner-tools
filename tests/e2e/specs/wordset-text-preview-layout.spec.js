const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);
const wordsetCss = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

function buildMarkup() {
  return `
    <style>${wordsetCss}</style>
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <section class="ll-wordset-top-actions">
        <div class="ll-wordset-mode-buttons" role="group">
          <button type="button" data-ll-wordset-start-mode data-mode="practice">Practice</button>
          <button type="button" data-ll-wordset-start-mode data-mode="learning">Learn</button>
          <button type="button" data-ll-wordset-start-mode data-mode="listening">Listen</button>
          <button type="button" data-ll-wordset-select-all>Select all</button>
        </div>
        <div class="ll-wordset-next-wrap">
          <div class="ll-wordset-next-shell" data-ll-wordset-next-shell>
            <button type="button" class="ll-wordset-next-card" data-ll-wordset-next aria-live="polite">
              <span class="ll-wordset-next-card__main">
                <span class="ll-wordset-next-card__icon" data-ll-wordset-next-icon></span>
                <span class="ll-wordset-next-card__preview" data-ll-wordset-next-preview></span>
                <span class="ll-wordset-next-card__text" data-ll-wordset-next-text></span>
              </span>
            </button>
            <span class="ll-wordset-next-card__meta">
              <span class="ll-wordset-next-card__count" data-ll-wordset-next-count hidden></span>
              <button type="button" class="ll-wordset-next-remove" data-ll-wordset-next-remove hidden>Remove</button>
            </span>
          </div>
        </div>
      </section>

      <div class="ll-wordset-grid" role="list">
        <article class="ll-wordset-card" role="listitem" data-cat-id="11" data-word-count="9">
          <div class="ll-wordset-card__top">
            <label class="ll-wordset-card__select">
              <input type="checkbox" value="11" data-ll-wordset-select />
              <span class="ll-wordset-card__select-box" aria-hidden="true"></span>
            </label>
            <a class="ll-wordset-card__heading" href="#" aria-label="Aile">
              <h2 class="ll-wordset-card__title">Aile</h2>
            </a>
            <span class="ll-wordset-card__hide-spacer" aria-hidden="true"></span>
          </div>
          <a class="ll-wordset-card__lesson-link" href="#" aria-label="Aile">
            <div class="ll-wordset-card__preview has-text">
              <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                <span class="ll-wordset-preview-text" dir="auto">Hala oglu ve hala kizi</span>
              </span>
            </div>
          </a>
          <div class="ll-wordset-card__progress" aria-hidden="true">
            <span class="ll-wordset-card__progress-track">
              <span class="ll-wordset-card__progress-segment ll-wordset-card__progress-segment--new" style="width:100%;"></span>
            </span>
          </div>
        </article>
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
        <button type="button" data-ll-wordset-selection-mode data-mode="learning">Selection Learn</button>
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

function buildConfig() {
  return {
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: '',
    isLoggedIn: false,
    wordsetId: 77,
    wordsetSlug: 'preview-wordset',
    wordsetName: 'Preview Wordset',
    links: {
      base: '/wordsets/preview-wordset/',
      progress: '/wordsets/preview-wordset/progress/',
      hidden: '/wordsets/preview-wordset/hidden-categories/',
      settings: '/wordsets/preview-wordset/settings/'
    },
    progressIncludeHidden: false,
    categories: [
      {
        id: 11,
        slug: 'aile',
        name: 'Aile',
        translation: 'Aile',
        count: 9,
        url: '#',
        mode: 'text_title',
        prompt_type: 'text_title',
        option_type: 'text_title',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'no-image',
        hidden: false,
        preview: [
          {
            type: 'text',
            label: 'Hala oglu ve hala kizi'
          }
        ],
        has_images: false
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
    nextActivity: {
      mode: 'practice',
      category_ids: [11],
      session_word_ids: [1101, 1102, 1103],
      type: 'review_chunk',
      reason_code: 'review_chunk_balanced',
      details: { chunk_size: 3 }
    },
    recommendationQueue: [],
    analytics: {
      scope: {},
      summary: {},
      daily_activity: { days: [], max_events: 0, window_days: 14 },
      categories: [],
      words: []
    },
    summaryCounts: {
      mastered: 0,
      studied: 0,
      new: 9
    },
    hardWordDifficultyThreshold: 4,
    modeUi: {},
    gender: {
      enabled: false,
      options: [],
      min_count: 2
    },
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

async function mountWordsetPreviewHarness(page) {
  const config = buildConfig();

  await page.goto('about:blank');
  await page.setContent(buildMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrapConfig) => {
    window.llWordsetPageData = bootstrapConfig;
    window.initFlashcardWidget = function () {
      return Promise.resolve();
    };
    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.AudioVisualizer = window.LLFlashcards.AudioVisualizer || {};
    window.LLFlashcards.AudioVisualizer.warmup = function () {
      return Promise.resolve(true);
    };

    const $ = window.jQuery;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: bootstrapConfig.nextActivity,
            recommendation_queue: []
          }
        });
        return deferred.promise();
      }

      if (action === 'll_user_study_fetch_words') {
        deferred.resolve({
          success: true,
          data: {
            words_by_category: {}
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

test('text-based wordset previews render as one rectangular card and shrink text without splitting words', async ({ page }) => {
  await mountWordsetPreviewHarness(page);

  await expect.poll(async () => {
    return page.locator('[data-ll-wordset-next-preview] .ll-wordset-next-thumb').count();
  }).toBe(1);

  const metrics = await page.evaluate(() => {
    function collect(selector, textSelector) {
      const slot = document.querySelector(selector);
      const text = slot ? slot.querySelector(textSelector) : null;
      if (!slot || !text) {
        return null;
      }
      const slotStyle = window.getComputedStyle(slot);
      const textStyle = window.getComputedStyle(text);
      const lineHeight = parseFloat(textStyle.lineHeight || '0') || 0;
      const paddingY = (parseFloat(textStyle.paddingTop || '0') || 0) + (parseFloat(textStyle.paddingBottom || '0') || 0);
      return {
        slotWidth: Math.round(slot.getBoundingClientRect().width),
        slotHeight: Math.round(slot.getBoundingClientRect().height),
        fontSize: parseFloat(textStyle.fontSize || '0') || 0,
        lineHeight,
        whiteSpace: textStyle.whiteSpace,
        wordBreak: textStyle.wordBreak,
        overflowWrap: textStyle.overflowWrap,
        scrollWidth: Math.ceil(text.scrollWidth || 0),
        clientWidth: Math.ceil(text.clientWidth || 0),
        scrollHeight: Math.ceil(text.scrollHeight || 0),
        maxContentHeight: Math.ceil((lineHeight * 2) + 1),
        actualContentHeight: Math.max(0, Math.ceil((text.scrollHeight || 0) - paddingY)),
        backgroundColor: slotStyle.backgroundColor
      };
    }

    return {
      nextPreviewClassName: document.querySelector('[data-ll-wordset-next-preview]')?.className || '',
      next: collect('.ll-wordset-next-thumb--text', '.ll-wordset-next-thumb__text'),
      card: collect('.ll-wordset-preview-item--text', '.ll-wordset-preview-text')
    };
  });

  expect(metrics.nextPreviewClassName).toContain('ll-wordset-next-card__preview--text-only');
  expect(metrics.next).not.toBeNull();
  expect(metrics.card).not.toBeNull();

  expect(metrics.next.slotWidth).toBeGreaterThan(metrics.next.slotHeight);
  expect(metrics.card.slotWidth).toBeGreaterThan(metrics.card.slotHeight);

  expect(metrics.next.whiteSpace).toBe('normal');
  expect(metrics.card.whiteSpace).toBe('normal');
  expect(metrics.next.wordBreak).toBe('normal');
  expect(metrics.card.wordBreak).toBe('normal');
  expect(metrics.next.overflowWrap).toBe('normal');
  expect(metrics.card.overflowWrap).toBe('normal');

  expect(metrics.next.fontSize).toBeLessThan(16);
  expect(metrics.next.fontSize).toBeGreaterThan(9);
  expect(metrics.card.fontSize).toBeLessThanOrEqual(22);
  expect(metrics.card.fontSize).toBeGreaterThan(10);

  expect(metrics.next.scrollWidth).toBeLessThanOrEqual(metrics.next.clientWidth + 1);
  expect(metrics.card.scrollWidth).toBeLessThanOrEqual(metrics.card.clientWidth + 1);
  expect(metrics.next.actualContentHeight).toBeLessThanOrEqual(metrics.next.maxContentHeight);
  expect(metrics.card.actualContentHeight).toBeLessThanOrEqual(metrics.card.maxContentHeight);
});
