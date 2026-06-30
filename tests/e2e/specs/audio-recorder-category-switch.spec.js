const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recorderJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-recorder.js'),
  'utf8'
);
const onePixelPngDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

function buildRecorderMarkup() {
  return `
    <div class="ll-recording-interface">
      <div class="ll-recording-header">
        <span class="ll-current-num">1</span>
        <span class="ll-total-num">1</span>
        <div class="ll-category-selector">
          <select id="ll-category-select">
            <option value="ağaç-çeşitleri">Ağaç çeşitleri (1)</option>
            <option value="baby-animals" selected>Baby animals (1)</option>
          </select>
        </div>
      </div>

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
  await page.setContent(buildRecorderMarkup());

  await page.evaluate(({ categoryPages }) => {
    window.__requestedCategories = [];
    window.__requestedCategoryPages = [];
    window.__categoryPages = categoryPages || {};

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
      if (action === 'll_get_images_for_recording') {
        const category = String(body.get('category') || '');
        const page = String(body.get('category_page') || '1');
        window.__requestedCategories.push(category);
        window.__requestedCategoryPages.push(`${category}:${page}`);
        const configuredPage = window.__categoryPages?.[category]?.[page];
        if (configuredPage) {
          return Promise.resolve(makeJsonResponse({
            success: true,
            data: configuredPage
          }));
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
    categoryPages: options.categoryPages || null
  });

  const initialImage = options.initialImage || buildQueueItem('baby-animals', 'Baby animals', 'calf');
  await page.evaluate(({ initialImage, hideRecorderText }) => {
    window.ll_recorder_data = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      images: [initialImage],
      available_categories: {
        'ağaç-çeşitleri': 'Ağaç çeşitleri',
        'baby-animals': 'Baby animals'
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
      include_types: '',
      exclude_types: '',
      auto_process_recordings: false,
      category_queue: {
        category: 'baby-animals',
        page: 1,
        per_page: 1,
        has_more: false
      },
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
        invalid_response: 'Server returned invalid response format'
      }
    };
  }, {
    initialImage,
    hideRecorderText: !!options.hideRecorderText
  });

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

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
