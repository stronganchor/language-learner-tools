const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recorderJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-recorder.js'),
  'utf8'
);
const onePixelPngDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

function buildRecorderMarkup(includeCategoryOverview = false) {
  const categoryOverviewMarkup = includeCategoryOverview ? `
      <section data-ll-recorder-category-overview aria-busy="true">
        <div data-ll-recorder-category-grid>
          <article data-recorder-queue-category="ağaç-çeşitleri" data-ll-recorder-queue-summary-placeholder="true"></article>
          <article data-recorder-queue-category="baby-animals" data-ll-recorder-queue-summary-placeholder="true"></article>
          <article data-recorder-queue-category="colors" data-ll-recorder-queue-summary-placeholder="true"></article>
        </div>
        <p data-ll-recorder-category-empty hidden>No words currently need recordings.</p>
        <span data-ll-recorder-category-status></span>
        <button type="button" data-ll-recorder-category-retry hidden>Retry</button>
      </section>
  ` : '';

  return `
    <div class="ll-recording-interface">
      <div class="ll-recording-header">
        <span class="ll-current-num">1</span>
        <span class="ll-total-num">1</span>
        <div class="ll-category-selector">
          <select id="ll-category-select">
            <option value="ağaç-çeşitleri">Ağaç çeşitleri (1)</option>
            <option value="baby-animals" selected>Baby animals (1)</option>
            ${includeCategoryOverview ? '<option value="colors">Colors</option>' : ''}
          </select>
        </div>
        <select id="ll-wordset-select">
          <option value="11" selected>Test wordset</option>
        </select>
      </div>

      ${categoryOverviewMarkup}

      <div class="ll-recording-main" style="display:flex;">
        <div class="ll-recording-image-container">
          <div class="flashcard-container">
            <img id="ll-current-image" alt="" />
          </div>
          <p id="ll-image-title"></p>
          <p id="ll-image-category"></p>
        </div>

        <div class="ll-recording-controls-column">
          <div class="ll-recording-type-selector">
            <select id="ll-recording-type"></select>
          </div>
          <div class="ll-recording-buttons">
            <button type="button" id="ll-record-btn" class="ll-btn ll-btn-record"></button>
            <button type="button" id="ll-skip-btn" class="ll-btn ll-btn-skip"></button>
            <button type="button" id="ll-hide-btn" class="ll-btn ll-btn-hide"></button>
          </div>
          <div id="ll-recording-indicator" style="display:none;">
            <span id="ll-recording-meter"></span>
            <span id="ll-recording-timer">0:00</span>
          </div>
          <div id="ll-playback-controls" style="display:none;">
            <audio id="ll-playback-audio" controls></audio>
            <button type="button" id="ll-redo-btn"></button>
            <button type="button" id="ll-submit-btn"></button>
          </div>
          <div id="ll-upload-status" class="ll-upload-status"></div>
        </div>
      </div>

      <div class="ll-recording-complete" style="display:none;">
        <h2>Done</h2>
        <p><span class="ll-completed-count"></span> recordings completed</p>
      </div>

      <div id="ll-upload-feedback" hidden>
        <span id="ll-upload-feedback-label"></span>
        <span id="ll-upload-feedback-value" hidden></span>
        <span id="ll-upload-progress-fill"></span>
      </div>
    </div>
  `;
}

function buildQueueItem(categorySlug, categoryName, title, overrides = {}) {
  return Object.assign({
    id: 0,
    title,
    image_url: '',
    category_name: categoryName,
    category_slug: categorySlug,
    word_id: 101,
    word_title: title,
    word_translation: '',
    use_word_display: true,
    missing_types: ['isolation'],
    existing_types: [],
    prompt_types: ['isolation'],
    my_existing_types: [],
    is_text_only: true
  }, overrides);
}

async function mountRecorder(page, options = {}) {
  await page.goto('about:blank');
  await page.setContent(buildRecorderMarkup(!!options.categoryOverview));

  await page.evaluate(({ categoryPages, categoryResponseDelays, categoryFailures, categoryOverviewResponse, categoryOverviewDelay }) => {
    window.__requestedCategories = [];
    window.__requestedCategoryPages = [];
    window.__requestedQueueCursors = [];
    window.__categoryPages = categoryPages || {};
    window.__categoryResponseDelays = categoryResponseDelays || {};
    window.__categoryFailures = categoryFailures || {};
    window.__categoryOverviewResponse = categoryOverviewResponse || null;
    window.__categoryOverviewDelay = Math.max(0, Number(categoryOverviewDelay) || 0);
    window.__categoryOverviewRequests = [];
    window.__categoryOverviewTypeScopes = [];

    const makeJsonResponse = (payload) => ({
      ok: true,
      status: 200,
      statusText: 'OK',
      headers: {
        get(name) {
          return String(name || '').toLowerCase() === 'content-type' ? 'application/json' : null;
        }
      },
      async json() {
        return payload;
      }
    });

    window.fetch = (url, options = {}) => {
      const body = options.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';
      if (action === 'll_tools_recorder_queue_summaries') {
        window.__categoryOverviewRequests.push(body.getAll('category_slugs[]').map(String));
        window.__categoryOverviewTypeScopes.push({
          include: String(body.get('include_recording_types') || ''),
          exclude: String(body.get('exclude_recording_types') || '')
        });
        const response = makeJsonResponse({
          success: true,
          data: window.__categoryOverviewResponse || {
            cards: [],
            resolvedSlugs: [],
            pendingSlugs: []
          }
        });
        return window.__categoryOverviewDelay > 0
          ? new Promise(resolve => setTimeout(() => resolve(response), window.__categoryOverviewDelay))
          : Promise.resolve(response);
      }
      if (action === 'll_get_images_for_recording') {
        const category = String(body.get('category') || '');
        const page = String(body.get('category_page') || '1');
        window.__requestedCategories.push(category);
        window.__requestedCategoryPages.push(`${category}:${page}`);
        window.__requestedQueueCursors.push(String(body.get('queue_cursor') || ''));
        const configuredPageConfig = window.__categoryPages?.[category]?.[page];
        const configuredPage = Array.isArray(configuredPageConfig)
          ? configuredPageConfig.shift()
          : configuredPageConfig;
        const configuredDelayConfig = window.__categoryResponseDelays?.[category]?.[page];
        const configuredDelay = Array.isArray(configuredDelayConfig)
          ? configuredDelayConfig.shift()
          : configuredDelayConfig;
        const configuredFailure = window.__categoryFailures?.[category]?.[page];
        if (configuredFailure) {
          return Promise.resolve(makeJsonResponse({
            success: false,
            data: String(configuredFailure)
          }));
        }
        if (configuredPage) {
          const response = makeJsonResponse({
            success: true,
            data: configuredPage
          });
          return configuredDelay > 0
            ? new Promise(resolve => setTimeout(() => resolve(response), configuredDelay))
            : Promise.resolve(response);
        }
        if (category === 'ağaç-çeşitleri') {
          return Promise.resolve(makeJsonResponse({
            success: true,
            data: {
              images: [],
              recording_types: [],
              pagination: {
                category,
                page: 1,
                per_page: 1,
                has_more: false
              }
            }
          }));
        }
        return Promise.resolve(makeJsonResponse({
          success: true,
          data: {
            images: [{
              id: 0,
              title: 'calf',
              image_url: '',
              category_name: 'Baby animals',
              category_slug: 'baby-animals',
              word_id: 102,
              word_title: 'calf',
              word_translation: '',
              use_word_display: true,
              missing_types: ['isolation'],
              existing_types: [],
              prompt_types: ['isolation'],
              my_existing_types: [],
              is_text_only: true
            }],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category,
              page: Number(page) || 1,
              per_page: 1,
              has_more: false
            }
          }
        }));
      }
      return Promise.resolve(makeJsonResponse({ success: true, data: {} }));
    };

    try {
      Object.defineProperty(navigator, 'mediaDevices', {
        value: {
          async getUserMedia() {
            return { getTracks: () => [{ stop() {} }] };
          },
          async enumerateDevices() {
            return [{ kind: 'audioinput', deviceId: 'fake-mic', label: 'Fake Mic' }];
          }
        },
        configurable: true
      });
    } catch (_) {
      navigator.mediaDevices = {
        async getUserMedia() {
          return { getTracks: () => [{ stop() {} }] };
        },
        async enumerateDevices() {
          return [{ kind: 'audioinput', deviceId: 'fake-mic', label: 'Fake Mic' }];
        }
      };
    }
  }, {
    categoryPages: options.categoryPages || null,
    categoryResponseDelays: options.categoryResponseDelays || null,
    categoryFailures: options.categoryFailures || null,
    categoryOverviewResponse: options.categoryOverviewResponse || null
  });

  const initialImages = Array.isArray(options.initialImages)
    ? options.initialImages
    : [options.initialImage || buildQueueItem('baby-animals', 'Baby animals', 'calf')];
  await page.evaluate(({ initialImages, hideRecorderText, categoryQueue, categoryOverview, includeTypes, excludeTypes }) => {
    window.ll_recorder_data = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      images: initialImages,
      available_categories: {
        'ağaç-çeşitleri': 'Ağaç çeşitleri',
        'baby-animals': 'Baby animals',
        'colors': 'Colors'
      },
      language: '',
      wordset: '',
      wordset_ids: [11],
      sort_locale: 'tr_TR',
      hide_name: !!hideRecorderText,
      hide_recorder_text: !!hideRecorderText,
      recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
      recording_type_order: ['isolation'],
      recording_type_icons: { default: '' },
      allow_new_words: false,
      assembly_enabled: false,
      deepl_enabled: false,
      user_display_name: 'Recorder Tester',
      require_all_types: true,
      initial_category: 'baby-animals',
      include_types: includeTypes,
      exclude_types: excludeTypes,
      auto_process_recordings: false,
      category_queue: categoryQueue || {
        category: 'baby-animals',
        page: 1,
        per_page: 1,
        has_more: false
      },
      category_overview: categoryOverview ? {
        enabled: true,
        action: 'll_tools_recorder_queue_summaries',
        batch_size: 6,
        max_auto_retries: 2
      } : { enabled: false },
      stop_delay_ms: 0,
      current_user_id: 10,
      hidden_words: [],
      hidden_count: 0,
      i18n: {
        category: 'Category:',
        uncategorized: 'Uncategorized',
        switching_category: 'Switching category...',
        loading_more_category: 'Loading more words in this category...',
        no_images_in_category: 'No images need audio in this category.',
        category_switched: 'Category switched. Ready to record.',
        category_overview_loading: 'Loading recording queues...',
        category_overview_loaded: 'Recording queues loaded.',
        category_overview_error: 'Some recording queues could not be loaded.',
        invalid_response: 'Server returned invalid response format'
      }
    };
  }, {
    initialImages,
    hideRecorderText: !!options.hideRecorderText,
    categoryQueue: options.categoryQueue || null,
    categoryOverview: !!options.categoryOverview,
    categoryOverviewDelay: Number(options.categoryOverviewDelay) || 0,
    includeTypes: String(options.includeTypes || ''),
    excludeTypes: String(options.excludeTypes || '')
  });

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

test('recorder category overview shows queued counts, removes empty categories, and switches from a card', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    includeTypes: 'isolation,question',
    excludeTypes: 'sentence',
    categoryOverviewResponse: {
      generation: 'overview-generation-1',
      cards: [
        {
          slug: 'baby-animals',
          name: 'Baby animals',
          count: 3,
          optionLabel: 'Baby animals (3)',
          html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="baby-animals" data-recorder-queue-count="3" aria-pressed="false"><span class="ll-wordset-card__title">Baby animals</span><span class="ll-wordset-settings-card__pill">3 words</span></button>'
        },
        {
          slug: 'colors',
          name: 'Colors',
          count: 2,
          optionLabel: 'Colors (2)',
          html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="colors" data-recorder-queue-count="2" aria-pressed="false"><span class="ll-wordset-card__title">Colors</span><span class="ll-wordset-settings-card__pill">2 words</span></button>'
        }
      ],
      resolvedSlugs: ['ağaç-çeşitleri', 'baby-animals', 'colors'],
      pendingSlugs: []
    }
  });

  await expect(page.locator('.ll-recorder-category-card')).toHaveCount(2);
  await expect(page.locator('[data-recorder-queue-category="baby-animals"] .ll-wordset-settings-card__pill')).toHaveText('3 words');
  await expect(page.locator('#ll-category-select option[value="baby-animals"]')).toHaveText('Baby animals (3)');
  await expect.poll(async () => page.evaluate(() => window.__categoryOverviewTypeScopes[0])).toEqual({
    include: 'isolation,question',
    exclude: 'sentence'
  });
  await expect(page.locator('#ll-category-select option[value="ağaç-çeşitleri"]')).toHaveCount(0);

  await page.locator('.ll-recorder-category-card[data-recorder-queue-category="colors"]').click();

  await expect(page.locator('#ll-category-select')).toHaveValue('colors');
  await expect.poll(async () => page.evaluate(() => window.__requestedCategories.includes('colors'))).toBe(true);

  await page.locator('#ll-skip-btn').click();

  await expect(page.locator('.ll-recorder-category-card[data-recorder-queue-category="colors"]')).toHaveCount(0);
  await expect(page.locator('#ll-category-select option[value="colors"]')).toHaveCount(0);
  await expect(page.locator('#ll-category-select')).toHaveValue('baby-animals');
  await expect(page.locator('.ll-next-category-btn')).toContainText('Baby animals');
});

test('a delayed overview response cannot resurrect a category completed before hydration', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    categoryOverviewDelay: 250,
    categoryOverviewResponse: {
      generation: 'overview-generation-delayed-completion',
      cards: [{
        slug: 'baby-animals',
        name: 'Baby animals',
        count: 1,
        optionLabel: 'Baby animals (1)',
        html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="baby-animals" data-recorder-queue-count="1" aria-pressed="false"><span class="ll-wordset-card__title">Baby animals</span></button>'
      }],
      resolvedSlugs: ['baby-animals'],
      pendingSlugs: []
    }
  });

  await page.locator('#ll-skip-btn').click();
  await expect(page.locator('[data-ll-recorder-queue-summary-placeholder="true"][data-recorder-queue-category="baby-animals"]')).toHaveCount(0);
  await page.waitForTimeout(350);
  await expect(page.locator('.ll-recorder-category-card[data-recorder-queue-category="baby-animals"]')).toHaveCount(0);
  await expect(page.locator('#ll-category-select option[value="baby-animals"]')).toHaveCount(0);
});

test('removing the selected empty overview category loads the first remaining queue', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    categoryOverviewResponse: {
      generation: 'overview-generation-selected-empty',
      cards: [
        {
          slug: 'colors',
          name: 'Colors',
          count: 2,
          optionLabel: 'Colors (2)',
          html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="colors" data-recorder-queue-count="2" aria-pressed="false"><span class="ll-wordset-card__title">Colors</span><span class="ll-wordset-settings-card__pill">2 words</span></button>'
        }
      ],
      resolvedSlugs: ['aÄŸaÃ§-Ã§eÅŸitleri', 'baby-animals', 'colors'],
      pendingSlugs: []
    }
  });

  await expect(page.locator('#ll-category-select option[value="baby-animals"]')).toHaveCount(0);
  await expect(page.locator('#ll-category-select')).toHaveValue('colors');
  await expect.poll(async () => page.evaluate(() => window.__requestedCategories.includes('colors'))).toBe(true);
  await expect(page.locator('#ll-image-title')).toHaveText('calf');
});

test('initial empty queue follows its continuation before showing completion', async ({ page }) => {
  const continuedItem = buildQueueItem('baby-animals', 'Baby animals', 'foal', {
    word_id: 151
  });

  await mountRecorder(page, {
    initialImages: [],
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 1,
      next_page: 1,
      cursor_token: 'initial.cursor',
      is_continuation: true,
      has_more: true
    },
    categoryPages: {
      'baby-animals': {
        '1': {
          images: [continuedItem],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 1,
            per_page: 1,
            has_more: false
          }
        }
      }
    }
  });

  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|'))).toBe('baby-animals:1');
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|'))).toBe('initial.cursor');
});

test('manual category switch stays on an empty Turkish category instead of advancing', async ({ page }) => {
  await mountRecorder(page);

  await page.selectOption('#ll-category-select', 'ağaç-çeşitleri');

  await expect(page.locator('#ll-category-select')).toHaveValue('ağaç-çeşitleri');
  await expect(page.locator('.ll-recording-complete')).toBeVisible();
  await expect(page.locator('.ll-current-num')).toHaveText('0');
  await expect(page.locator('.ll-total-num')).toHaveText('0');
  await expect(page.locator('#ll-upload-status')).toContainText('No images need audio in this category.');

  await expect.poll(async () => page.evaluate(() => window.__requestedCategories.join('|'))).toBe('ağaç-çeşitleri');
});

test('failed current category switch restores the previous category and re-enables controls', async ({ page }) => {
  const treeCategory = 'a\u011fa\u00e7-\u00e7e\u015fitleri';
  await mountRecorder(page, {
    categoryFailures: {
      [treeCategory]: {
        '1': 'Intentional category failure'
      }
    }
  });

  await page.selectOption('#ll-category-select', treeCategory);

  await expect(page.locator('#ll-category-select')).toHaveValue('baby-animals');
  await expect(page.locator('#ll-upload-status')).toContainText('Intentional category failure');
  await expect(page.locator('#ll-category-select')).toBeEnabled();
  await expect(page.locator('#ll-wordset-select')).toBeEnabled();
  await expect(page.locator('#ll-recording-type')).toBeEnabled();
  await expect(page.locator('#ll-record-btn')).toBeEnabled();
  await expect(page.locator('#ll-skip-btn')).toBeEnabled();
  await expect(page.locator('#ll-hide-btn')).toBeEnabled();
});

test('category switch follows an empty first response continuation', async ({ page }) => {
  const treeCategory = 'a\u011fa\u00e7-\u00e7e\u015fitleri';
  const continuedItem = buildQueueItem(treeCategory, 'Ağaç çeşitleri', 'oak', {
    word_id: 181
  });

  await mountRecorder(page, {
    categoryPages: {
      [treeCategory]: {
        '1': [
          {
            images: [],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: treeCategory,
              page: 1,
              per_page: 1,
              next_page: 1,
              cursor_token: 'switch.cursor',
              is_continuation: true,
              has_more: true
            }
          },
          {
            images: [continuedItem],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: treeCategory,
              page: 1,
              per_page: 1,
              has_more: false
            }
          }
        ]
      }
    }
  });

  await page.selectOption('#ll-category-select', treeCategory);

  await expect(page.locator('#ll-category-select')).toHaveValue(treeCategory);
  await expect(page.locator('#ll-image-title')).toHaveText('oak');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|')))
    .toBe(`${treeCategory}:1|${treeCategory}:1`);
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|'))).toBe('|switch.cursor');
});

test('late continuation from the previous category cannot replace the newly selected queue', async ({ page }) => {
  const treeCategory = 'a\u011fa\u00e7-\u00e7e\u015fitleri';
  const staleAnimal = buildQueueItem('baby-animals', 'Baby animals', 'late foal', {
    word_id: 191
  });
  const treeItem = buildQueueItem(treeCategory, 'Tree varieties', 'oak', {
    word_id: 192
  });

  await mountRecorder(page, {
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 1,
      next_page: 2,
      cursor_token: 'animals.cursor',
      has_more: true
    },
    categoryPages: {
      'baby-animals': {
        '2': {
          images: [staleAnimal],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 2,
            per_page: 1,
            has_more: false
          }
        }
      },
      [treeCategory]: {
        '1': [
          {
            images: [],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: treeCategory,
              page: 1,
              per_page: 1,
              next_page: 1,
              cursor_token: 'trees.cursor',
              is_continuation: true,
              has_more: true
            }
          },
          {
            images: [treeItem],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: treeCategory,
              page: 1,
              per_page: 1,
              has_more: false
            }
          }
        ]
      }
    },
    categoryResponseDelays: {
      'baby-animals': {
        '2': 250
      }
    }
  });

  await page.locator('#ll-skip-btn').click();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|')))
    .toBe('baby-animals:2');

  await page.selectOption('#ll-category-select', treeCategory);

  await expect(page.locator('#ll-image-title')).toHaveText('oak');
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|')))
    .toBe(`baby-animals:2|${treeCategory}:1|${treeCategory}:1`);
  await page.waitForTimeout(350);

  await expect(page.locator('#ll-category-select')).toHaveValue(treeCategory);
  await expect(page.locator('#ll-image-title')).toHaveText('oak');
  await expect(page.locator('.ll-total-num')).toHaveText('1');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => ({
    titles: window.ll_recorder_data.images.map(item => item.title),
    category: window.ll_recorder_data.category_queue?.category || ''
  }))).toEqual({
    titles: ['oak'],
    category: treeCategory
  });
});

test('category switch loads the next queue page before marking the category complete', async ({ page }) => {
  const firstItem = buildQueueItem('baby-animals', 'Baby animals', 'calf', {
    word_id: 201
  });
  const secondItem = buildQueueItem('baby-animals', 'Baby animals', 'foal', {
    word_id: 202
  });

  await mountRecorder(page, {
    initialImage: firstItem,
    categoryPages: {
      'baby-animals': {
        '1': {
          images: [firstItem],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 1,
            per_page: 1,
            has_more: true,
            count: 2,
            count_is_lower_bound: true
          }
        },
        '2': {
          images: [secondItem],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 2,
            per_page: 1,
            has_more: false,
            count: 2,
            count_is_lower_bound: false
          }
        }
      }
    }
  });

  await page.locator('#ll-category-select').dispatchEvent('change');
  await expect(page.locator('#ll-image-title')).toHaveText('calf');

  await page.locator('#ll-skip-btn').click();

  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|'))).toBe('baby-animals:1|baby-animals:2');
});

test('sparse queue continuation reuses the same page with its opaque cursor', async ({ page }) => {
  const firstItem = buildQueueItem('baby-animals', 'Baby animals', 'calf', {
    word_id: 301
  });
  const secondItem = buildQueueItem('baby-animals', 'Baby animals', 'foal', {
    word_id: 302
  });

  await mountRecorder(page, {
    initialImage: firstItem,
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 2,
      next_page: 1,
      cursor_token: 'opaque.payload',
      is_continuation: true,
      has_more: true
    },
    categoryPages: {
      'baby-animals': {
        '1': {
          images: [secondItem],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 1,
            per_page: 2,
            next_page: 0,
            cursor_token: '',
            has_more: false,
            count: 2,
            count_is_lower_bound: false
          }
        }
      }
    }
  });

  await page.locator('#ll-skip-btn').click();

  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|'))).toBe('baby-animals:1');
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|'))).toBe('opaque.payload');
});

test('empty rebased continuation replaces the stale queue and keeps scanning', async ({ page }) => {
  const staleItem = buildQueueItem('baby-animals', 'Baby animals', 'stale calf', {
    word_id: 351
  });
  const recoveredItem = buildQueueItem('baby-animals', 'Baby animals', 'recovered foal', {
    word_id: 352
  });

  await mountRecorder(page, {
    initialImage: staleItem,
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 1,
      next_page: 1,
      cursor_token: 'expired.cursor',
      is_continuation: true,
      has_more: true
    },
    categoryPages: {
      'baby-animals': {
        '1': [
          {
            images: [],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: 'baby-animals',
              page: 1,
              per_page: 1,
              next_page: 1,
              cursor_token: 'rebased.cursor',
              is_continuation: true,
              reset_queue: true,
              cursor_rebased: true,
              has_more: true
            }
          },
          {
            images: [recoveredItem],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category: 'baby-animals',
              page: 1,
              per_page: 1,
              has_more: false
            }
          }
        ]
      }
    }
  });

  await page.locator('#ll-skip-btn').click();

  await expect(page.locator('#ll-image-title')).toHaveText('recovered foal');
  await expect(page.locator('.ll-total-num')).toHaveText('1');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|')))
    .toBe('baby-animals:1|baby-animals:1');
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|')))
    .toBe('expired.cursor|rebased.cursor');
});

test('hiding the last buffered item loads its continuation before completion', async ({ page }) => {
  const hiddenItem = buildQueueItem('baby-animals', 'Baby animals', 'calf', {
    word_id: 401
  });
  const continuedItem = buildQueueItem('baby-animals', 'Baby animals', 'foal', {
    word_id: 402
  });

  await mountRecorder(page, {
    initialImage: hiddenItem,
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 1,
      next_page: 1,
      cursor_token: 'hide.cursor',
      is_continuation: true,
      has_more: true
    },
    categoryPages: {
      'baby-animals': {
        '1': {
          images: [continuedItem],
          recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
          pagination: {
            category: 'baby-animals',
            page: 1,
            per_page: 1,
            has_more: false
          }
        }
      }
    }
  });

  await page.locator('#ll-hide-btn').click();

  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|'))).toBe('hide.cursor');
});

test('recorder text setting hides image-backed word text but keeps text-only prompts usable', async ({ page }) => {
  await mountRecorder(page, {
    hideRecorderText: true,
    initialImage: buildQueueItem('baby-animals', 'Baby animals', 'calf', {
      id: 44,
      image_url: onePixelPngDataUrl,
      is_text_only: false
    })
  });

  await expect(page.locator('#ll-image-title')).toBeHidden();
  await expect(page.locator('#ll-image-title')).toHaveText('');
  await expect(page.locator('#ll-current-image')).toHaveAttribute('alt', '');

  await mountRecorder(page, {
    hideRecorderText: true,
    initialImage: buildQueueItem('text-only', 'Text only', 'fallback word')
  });

  await expect(page.locator('#ll-image-title')).toBeVisible();
  await expect(page.locator('#ll-image-title')).toHaveText('fallback word');
  await expect(page.locator('.ll-text-display')).toHaveText('fallback word');
});
