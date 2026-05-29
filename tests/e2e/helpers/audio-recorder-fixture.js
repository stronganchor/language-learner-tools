const fs = require('fs');
const path = require('path');

const recorderJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-recorder.js'),
  'utf8'
);

function buildNewWordRecorderMarkup() {
  return `
    <style>
      .ll-new-word-overlay:not([hidden]) {
        position: fixed;
        inset: 0;
        display: block;
      }

      .ll-new-word-overlay-backdrop {
        position: absolute;
        inset: 0;
      }

      .ll-new-word-panel {
        position: relative;
        z-index: 1;
        width: min(720px, calc(100vw - 80px));
        min-height: 320px;
        margin: 72px auto 0;
        background: #fff;
      }

      .ll-recording-meter {
        display: inline-flex;
        width: 72px;
        height: 18px;
      }

      .ll-recording-meter-bar {
        display: block;
        width: 12px;
        height: 100%;
      }
    </style>

    <div class="ll-recording-interface">
      <div class="ll-recording-header">
        <span class="ll-current-num">0</span>
        <span class="ll-total-num">0</span>
        <button type="button" id="ll-new-word-toggle">New Word</button>
      </div>

      <div class="ll-new-word-overlay" id="ll-new-word-overlay" hidden>
        <div class="ll-new-word-overlay-backdrop"></div>
        <div class="ll-new-word-panel" id="ll-new-word-panel" style="display:none;">
          <div class="ll-new-word-card">
            <div class="ll-new-word-shell">
              <div class="ll-new-word-header">
                <h3 id="ll-new-word-title">Record a New Word</h3>
                <div class="ll-new-word-header-actions">
                  <div id="ll-new-word-auto-status" class="ll-new-word-auto-status" style="display:none;" role="status" aria-live="polite">
                    <span class="ll-new-word-auto-icon" aria-hidden="true">A</span>
                    <span class="ll-new-word-auto-spinner" aria-hidden="true" style="display:none;"></span>
                    <button type="button" id="ll-new-word-auto-cancel" class="ll-btn ll-new-word-auto-cancel">x</button>
                  </div>
                  <button type="button" id="ll-new-word-back" class="ll-btn ll-new-word-close">&times;</button>
                </div>
              </div>

              <div id="ll-new-word-status" class="ll-new-word-status" hidden></div>

              <div class="ll-new-word-layout">
                <div class="ll-new-word-form">
                  <div class="ll-new-word-form-grid">
                    <div id="ll-new-word-category-row" class="ll-new-word-row ll-new-word-row--category">
                      <label for="ll-new-word-category">Category</label>
                      <select id="ll-new-word-category">
                        <option value="uncategorized" selected>Uncategorized</option>
                      </select>
                    </div>

                    <div class="ll-new-word-row ll-new-word-row--toggle ll-new-word-checkbox">
                      <label>
                        <input type="checkbox" id="ll-new-word-create-category" />
                        Create a new category
                      </label>
                    </div>

                    <div class="ll-new-word-create-fields" style="display:none;">
                      <div class="ll-new-word-row">
                        <label for="ll-new-word-category-name">New Category Name</label>
                        <input type="text" id="ll-new-word-category-name" />
                      </div>
                      <div class="ll-new-word-row">
                        <label>Desired Recording Types</label>
                        <div class="ll-new-word-types">
                          <label class="ll-recording-type-option">
                            <input type="checkbox" value="isolation" data-type-name="Isolation" checked />
                            <span class="ll-recording-type-option-label">Isolation</span>
                          </label>
                          <label class="ll-recording-type-option">
                            <input type="checkbox" value="introduction" data-type-name="Introduction" />
                            <span class="ll-recording-type-option-label">Introduction</span>
                          </label>
                        </div>
                      </div>
                    </div>

                    <div class="ll-new-word-row ll-new-word-row--target">
                      <label for="ll-new-word-text-target">Target</label>
                      <input type="text" id="ll-new-word-text-target" />
                    </div>

                    <div class="ll-new-word-row ll-new-word-row--translation">
                      <label for="ll-new-word-text-translation">Translation</label>
                      <input type="text" id="ll-new-word-text-translation" />
                    </div>
                  </div>
                </div>

                <div class="ll-new-word-sidebar">
                  <div class="ll-new-word-recording">
                    <div class="ll-new-word-recording-controls">
                      <button type="button" id="ll-new-word-record-btn" class="ll-btn ll-btn-record"></button>
                      <div id="ll-new-word-recording-indicator" class="ll-recording-indicator" style="display:none;">
                        <span class="ll-recording-dot"></span>
                        <span id="ll-new-word-recording-meter" class="ll-recording-meter" aria-hidden="true">
                          <span class="ll-recording-meter-bar"></span>
                          <span class="ll-recording-meter-bar"></span>
                          <span class="ll-recording-meter-bar"></span>
                          <span class="ll-recording-meter-bar"></span>
                        </span>
                        <span id="ll-new-word-recording-timer">0:00</span>
                      </div>
                    </div>

                    <div id="ll-new-word-recording-type" class="ll-new-word-recording-type" style="display:none;">
                      <span class="ll-new-word-recording-type-dot" aria-hidden="true"></span>
                      <span id="ll-new-word-recording-type-label"></span>
                    </div>

                    <div id="ll-new-word-playback-controls" class="ll-new-word-playback-controls" style="display:none;">
                      <audio id="ll-new-word-playback-audio" controls></audio>
                      <div class="ll-new-word-playback-actions">
                        <button type="button" id="ll-new-word-redo-btn" class="ll-btn ll-btn-secondary">Redo</button>
                        <button type="button" id="ll-new-word-start" class="ll-btn ll-btn-primary">Save</button>
                      </div>
                    </div>
                  </div>

                  <div id="ll-new-word-review-slot" class="ll-new-word-review-slot"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="ll-recording-main" style="display:none;">
        <div class="ll-recording-image-container">
          <div class="flashcard-container">
            <img id="ll-current-image" alt="" />
          </div>
          <p id="ll-image-title"></p>
          <p id="ll-image-category"></p>
        </div>
        <div class="ll-recording-controls-column">
          <select id="ll-recording-type"></select>
          <button type="button" id="ll-record-btn" class="ll-btn ll-btn-record"></button>
          <button type="button" id="ll-skip-btn" class="ll-btn ll-btn-skip"></button>
          <button type="button" id="ll-hide-btn" class="ll-btn ll-btn-hide"></button>
          <div id="ll-upload-status" class="ll-upload-status"></div>
        </div>
      </div>
    </div>
  `;
}

async function mountNewWordRecorderFixture(page, options = {}) {
  await page.route('https://ll-recorder-fixture.test/**', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('https://ll-recorder-fixture.test/');
  await page.setContent(buildNewWordRecorderMarkup());

  await page.evaluate((mountOptions) => {
    window.__llStartupTestState = {
      prepareRequests: 0,
      transcribeRequests: 0,
      updateTextRequests: 0
    };

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
      },
      async text() {
        return JSON.stringify(payload);
      }
    });

    window.fetch = (url, fetchOptions = {}) => {
      const body = fetchOptions.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';

      if (action === 'll_get_recording_types_for_category') {
        return Promise.resolve(makeJsonResponse({
          success: true,
          data: {
            recording_types: [
              { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' },
              { slug: 'introduction', name: 'Introduction', label: 'Introduction', icon: '' }
            ]
          }
        }));
      }

      if (action === 'll_prepare_new_word_recording') {
        window.__llStartupTestState.prepareRequests += 1;
        const targetText = String(body.get('word_text_target') || '').trim();
        const translationText = String(body.get('word_text_translation') || '').trim();
        const categoryName = String(body.get('new_category_name') || 'Uncategorized').trim() || 'Uncategorized';
        const selectedTypes = body.getAll('new_category_types[]');
        const recordingTypes = selectedTypes.length ? selectedTypes : ['isolation'];

        return Promise.resolve(makeJsonResponse({
          success: true,
          data: {
            word: {
              id: 0,
              title: targetText || 'Placeholder',
              image_url: '',
              category_name: categoryName,
              category_slug: 'debug-category',
              word_id: 501,
              word_title: targetText || 'Placeholder',
              word_translation: translationText,
              use_word_display: true,
              missing_types: recordingTypes,
              existing_types: [],
              prompt_types: recordingTypes,
              my_existing_types: [],
              is_text_only: true
            },
            recording_types: recordingTypes.map((slug) => ({
              slug,
              name: slug === 'introduction' ? 'Introduction' : 'Isolation',
              label: slug === 'introduction' ? 'Introduction' : 'Isolation',
              icon: ''
            })),
            category: {
              slug: 'debug-category',
              name: categoryName,
              term_id: 91
            }
          }
        }));
      }

      if (action === 'll_update_new_word_text') {
        window.__llStartupTestState.updateTextRequests += 1;
        return Promise.resolve(makeJsonResponse({
          success: true,
          data: {
            word_id: 501,
            post_title: String(body.get('word_text_target') || ''),
            word_translation: String(body.get('word_text_translation') || ''),
            store_in_title: true
          }
        }));
      }

      if (action === 'll_transcribe_recording') {
        window.__llStartupTestState.transcribeRequests += 1;
        return new Promise((resolve) => {
          setTimeout(() => {
            resolve(makeJsonResponse({
              success: true,
              data: mountOptions.transcribeData || {
                transcript: 'auto transcript',
                translation: 'auto translation'
              }
            }));
          }, mountOptions.transcribeDelayMs || 0);
        });
      }

      return Promise.resolve(makeJsonResponse({ success: true, data: {} }));
    };

    window.ll_recorder_data = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      images: [],
      available_categories: { uncategorized: 'Uncategorized' },
      language: '',
      wordset: '',
      wordset_ids: [],
      sort_locale: 'en_US',
      hide_name: false,
      hide_recorder_text: false,
      recording_types: [
        { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' },
        { slug: 'introduction', name: 'Introduction', label: 'Introduction', icon: '' }
      ],
      recording_type_order: ['isolation', 'introduction'],
      recording_type_icons: { default: '' },
      allow_new_words: true,
      assembly_enabled: true,
      deepl_enabled: false,
      user_display_name: 'Recorder Tester',
      require_all_types: true,
      initial_category: 'uncategorized',
      include_types: '',
      exclude_types: '',
      auto_process_recordings: false,
      stop_delay_ms: 0,
      transcribe_poll_attempts: 2,
      transcribe_poll_interval_ms: 10,
      current_user_id: 10,
      hidden_words: [],
      hidden_count: 0,
      i18n: {
        category: 'Category:',
        uncategorized: 'Uncategorized',
        transcribing: 'Transcribing...',
        uploading: 'Uploading...',
        saved_next_type: 'Saved. Next type selected.',
        new_word_prepare_failed: 'Failed to prepare new word',
        new_word_update_text_failed: 'Failed to update word text'
      }
    };
  }, options);

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

module.exports = {
  mountNewWordRecorderFixture
};
