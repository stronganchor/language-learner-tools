const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recorderJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-recorder.js'),
  'utf8'
);
const onePixelPngDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

function buildRecorderMarkup(view = 'category') {
  const isOverview = view === 'overview';
  const categoryOverviewMarkup = isOverview ? `
      <section data-ll-recorder-category-overview aria-busy="true">
        <div data-ll-recorder-category-grid>
          <article data-recorder-queue-category="trees" data-ll-recorder-queue-summary-placeholder="true"><span class="ll-recorder-category-placeholder__title"></span></article>
          <article data-recorder-queue-category="baby-animals" data-ll-recorder-queue-summary-placeholder="true"><span class="ll-recorder-category-placeholder__title"></span></article>
          <article data-recorder-queue-category="colors" data-ll-recorder-queue-summary-placeholder="true"><span class="ll-recorder-category-placeholder__title"></span></article>
          <article data-recorder-queue-category="foods" data-ll-recorder-queue-summary-placeholder="true" hidden><span class="ll-recorder-category-placeholder__title"></span></article>
          <article data-recorder-queue-category="places" data-ll-recorder-queue-summary-placeholder="true" hidden><span class="ll-recorder-category-placeholder__title"></span></article>
        </div>
        <span data-ll-recorder-category-more aria-hidden="true">&hellip;</span>
        <p data-ll-recorder-category-empty hidden>No words currently need recordings.</p>
        <span data-ll-recorder-category-status></span>
        <button type="button" data-ll-recorder-category-retry hidden>Retry</button>
      </section>
  ` : '';

  const focusedHeaderMarkup = !isOverview ? `
        <a href="https://ll-recorder-fixture.test/record/?ll_record_wordset=11" data-ll-recorder-category-back>Back</a>
        <div class="ll-recording-progress">
          <span class="ll-current-num">1</span>
          <span aria-hidden="true">/</span>
          <span class="ll-total-num">1</span>
        </div>
        <input type="hidden" id="ll-category-select" value="baby-animals" />
  ` : '';

  const focusedRecorderMarkup = !isOverview ? `
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
  ` : '';

  return `
    <div class="ll-recording-interface">
      <div class="ll-recording-header">
        ${focusedHeaderMarkup}
        <select id="ll-wordset-select">
          <option value="11" selected>Test wordset</option>
        </select>
      </div>

      ${categoryOverviewMarkup}
      ${focusedRecorderMarkup}
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
  const view = options.categoryOverview ? 'overview' : 'category';
  const initialUrl = options.initialUrl
    || (view === 'overview'
      ? 'https://ll-recorder-fixture.test/record/?ll_record_wordset=11&ll_record_word=999'
      : 'https://ll-recorder-fixture.test/record/?ll_record_wordset=11&ll_record_category=baby-animals');
  await page.route('https://ll-recorder-fixture.test/**', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto(initialUrl);
  await page.setContent(buildRecorderMarkup(view));

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
    window.sessionStorage.setItem('ll-recorder-fixture-queue-requests', '0');

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
        window.sessionStorage.setItem(
          'll-recorder-fixture-queue-requests',
          String((parseInt(window.sessionStorage.getItem('ll-recorder-fixture-queue-requests'), 10) || 0) + 1)
        );
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
    categoryOverviewResponse: options.categoryOverviewResponse || null,
    categoryOverviewDelay: Number(options.categoryOverviewDelay) || 0
  });

  const initialImages = Array.isArray(options.initialImages)
    ? options.initialImages
    : (view === 'overview'
      ? []
      : [options.initialImage || buildQueueItem('baby-animals', 'Baby animals', 'calf')]);
  await page.evaluate(({ initialImages, hideRecorderText, categoryQueue, categoryOverview, catalogComplete, includeTypes, excludeTypes, view }) => {
    window.ll_recorder_data = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      images: initialImages,
      available_categories: {
        'trees': 'Tree varieties',
        'baby-animals': 'Baby animals',
        'colors': 'Colors',
        'foods': 'Foods',
        'places': 'Places'
      },
      view,
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
      initial_category: view === 'overview' ? '' : 'baby-animals',
      include_types: includeTypes,
      exclude_types: excludeTypes,
      auto_process_recordings: false,
      category_queue: categoryQueue || (view === 'overview' ? {
        category: '',
        page: 1,
        per_page: 1,
        has_more: false
      } : {
          category: 'baby-animals',
          page: 1,
          per_page: 1,
          has_more: false
        }),
      category_overview: categoryOverview ? {
        enabled: true,
        action: 'll_tools_recorder_queue_summaries',
        batch_size: 6,
        max_auto_retries: 2,
        catalog_complete: catalogComplete
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
    catalogComplete: options.catalogComplete !== false,
    includeTypes: String(options.includeTypes || ''),
    excludeTypes: String(options.excludeTypes || ''),
    view
  });

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

test('recorder overview uses neutral bounded loading shells and opens an exact-count category page', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    categoryOverviewDelay: 1200,
    includeTypes: 'isolation,question',
    excludeTypes: 'sentence',
    categoryOverviewResponse: {
      generation: 'overview-generation-1',
      cards: [
        {
          slug: 'baby-animals',
          name: 'Baby animals',
          count: 12,
          optionLabel: 'Baby animals (12)',
          html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="baby-animals" data-recorder-queue-count="12"><span class="ll-wordset-card__title">Baby animals</span><span class="ll-wordset-settings-card__pill">12 words</span></button>'
        },
        {
          slug: 'colors',
          name: 'Colors',
          count: 2,
          optionLabel: 'Colors (2)',
          html: '<button type="button" class="ll-wordset-card ll-recorder-category-card" data-recorder-queue-category="colors" data-recorder-queue-count="2"><span class="ll-wordset-card__title">Colors</span><span class="ll-wordset-settings-card__pill">2 words</span></button>'
        }
      ],
      resolvedSlugs: ['trees', 'baby-animals', 'colors', 'foods', 'places'],
      pendingSlugs: []
    }
  });

  await expect(page.locator('.ll-recording-progress')).toHaveCount(0);
  await expect(page.locator('select#ll-category-select')).toHaveCount(0);
  await expect(page.locator('.ll-recording-main')).toHaveCount(0);
  const shownPlaceholders = page.locator('[data-ll-recorder-queue-summary-placeholder="true"]:not([hidden])');
  await expect(shownPlaceholders).toHaveCount(3);
  await expect(shownPlaceholders).toHaveText(['', '', '']);
  await expect(page.locator('[data-ll-recorder-category-more]')).toBeVisible();
  await expect(page.locator('[data-ll-recorder-category-grid]')).not.toContainText('Baby animals');

  await expect(page.locator('.ll-recorder-category-card')).toHaveCount(2);
  await expect(page.locator('[data-recorder-queue-category="baby-animals"] .ll-wordset-settings-card__pill')).toHaveText('12 words');
  await expect(page.locator('[data-recorder-queue-category="baby-animals"]')).toHaveAttribute('data-recorder-queue-count', '12');
  await expect(page.locator('[data-ll-recorder-category-grid]')).not.toContainText('+');
  await expect.poll(async () => page.evaluate(() => window.__categoryOverviewTypeScopes[0])).toEqual({
    include: 'isolation,question',
    exclude: 'sentence'
  });
  await page.locator('.ll-recorder-category-card[data-recorder-queue-category="colors"]').click();

  await expect(page).toHaveURL(url => (
    url.searchParams.get('ll_record_wordset') === '11'
    && url.searchParams.get('ll_record_category') === 'colors'
    && !url.searchParams.has('ll_record_word')
  ));
  await expect.poll(async () => page.evaluate(() => (
    parseInt(window.sessionStorage.getItem('ll-recorder-fixture-queue-requests'), 10) || 0
  ))).toBe(0);
});

test('incomplete category discovery keeps the overview non-authoritative and retryable', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    catalogComplete: false
  });

  await expect(page.locator('[data-ll-recorder-category-empty]')).toBeHidden();
  await expect(page.locator('[data-ll-recorder-category-retry]')).toBeVisible();
  await expect(page.locator('[data-ll-recorder-category-overview]')).toHaveClass(/has-error/);
  await expect(page.locator('[data-ll-recorder-queue-summary-placeholder="true"]:not([hidden])')).toHaveCount(3);
  await expect.poll(async () => page.evaluate(() => window.__categoryOverviewRequests.length)).toBe(0);
});

test('a late incomplete catalog response pauses overview loading without resolving shells or looping', async ({ page }) => {
  await mountRecorder(page, {
    categoryOverview: true,
    categoryOverviewResponse: {
      catalogComplete: false,
      cards: [],
      resolvedSlugs: [],
      pendingSlugs: ['trees', 'baby-animals', 'colors', 'foods', 'places']
    }
  });

  await expect.poll(async () => page.evaluate(() => window.__categoryOverviewRequests.length)).toBe(1);
  await expect(page.locator('[data-ll-recorder-category-overview]')).toHaveClass(/has-error/);
  await expect(page.locator('[data-ll-recorder-category-retry]')).toBeVisible();
  await expect(page.locator('[data-ll-recorder-category-empty]')).toBeHidden();
  await expect(page.locator('[data-ll-recorder-queue-summary-placeholder="true"]')).toHaveCount(5);
  await page.waitForTimeout(500);
  await expect.poll(async () => page.evaluate(() => window.__categoryOverviewRequests.length)).toBe(1);
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

test('focused category page keeps only hidden active-category state and a clean back link', async ({ page }) => {
  await mountRecorder(page);

  await expect(page.locator('select#ll-category-select')).toHaveCount(0);
  await expect(page.locator('input[type="hidden"]#ll-category-select')).toHaveValue('baby-animals');
  await expect(page.locator('.ll-category-selector')).toHaveCount(0);
  await expect(page.locator('.ll-category-selector-mobile')).toHaveCount(0);
  await expect(page.locator('[data-ll-recorder-category-back]')).toHaveAttribute(
    'href',
    'https://ll-recorder-fixture.test/record/?ll_record_wordset=11'
  );
});

test('focused category bootstrap does not request a category switch', async ({ page }) => {
  await mountRecorder(page);

  await expect(page.locator('#ll-image-title')).toHaveText('calf');
  await expect.poll(async () => page.evaluate(() => window.__requestedCategories)).toEqual([]);
  await expect(page.locator('#ll-wordset-select')).toBeEnabled();
  await expect(page.locator('#ll-recording-type')).toBeEnabled();
  await expect(page.locator('#ll-record-btn')).toBeEnabled();
  await expect(page.locator('#ll-skip-btn')).toBeEnabled();
  await expect(page.locator('#ll-hide-btn')).toBeEnabled();
});

test('focused category follows another empty continuation page without a selector', async ({ page }) => {
  const category = 'baby-animals';
  const continuedItem = buildQueueItem(category, 'Baby animals', 'foal', {
    word_id: 181
  });

  await mountRecorder(page, {
    initialImages: [],
    categoryQueue: {
      category,
      page: 1,
      per_page: 1,
      next_page: 1,
      cursor_token: 'first.cursor',
      is_continuation: true,
      has_more: true
    },
    categoryPages: {
      [category]: {
        '1': [
          {
            images: [],
            recording_types: [{ slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }],
            pagination: {
              category,
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
              category,
              page: 1,
              per_page: 1,
              has_more: false
            }
          }
        ]
      }
    }
  });

  await expect(page.locator('#ll-category-select')).toHaveValue(category);
  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|')))
    .toBe(`${category}:1|${category}:1`);
  await expect.poll(async () => page.evaluate(() => window.__requestedQueueCursors.join('|')))
    .toBe('first.cursor|switch.cursor');
});

test('focused category loads the next queue page before marking the category complete', async ({ page }) => {
  const firstItem = buildQueueItem('baby-animals', 'Baby animals', 'calf', {
    word_id: 201
  });
  const secondItem = buildQueueItem('baby-animals', 'Baby animals', 'foal', {
    word_id: 202
  });

  await mountRecorder(page, {
    initialImage: firstItem,
    categoryQueue: {
      category: 'baby-animals',
      page: 1,
      per_page: 1,
      next_page: 2,
      has_more: true,
      count: 2,
      count_is_lower_bound: true
    },
    categoryPages: {
      'baby-animals': {
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

  await expect(page.locator('#ll-image-title')).toHaveText('calf');

  await page.locator('#ll-skip-btn').click();

  await expect(page.locator('#ll-image-title')).toHaveText('foal');
  await expect(page.locator('.ll-recording-complete')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__requestedCategoryPages.join('|'))).toBe('baby-animals:2');
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
