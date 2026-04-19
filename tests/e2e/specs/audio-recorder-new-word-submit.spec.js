const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recorderJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-recorder.js'),
  'utf8'
);

function buildRecorderMarkup() {
  return `
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
          <div class="ll-recording-type-selector">
            <select id="ll-recording-type"></select>
          </div>

          <div class="ll-recording-buttons">
            <button type="button" id="ll-record-btn" class="ll-btn ll-btn-record"></button>
            <button type="button" id="ll-skip-btn" class="ll-btn ll-btn-skip"></button>
            <button type="button" id="ll-hide-btn" class="ll-btn ll-btn-hide"></button>
          </div>

          <div id="ll-recording-indicator" class="ll-recording-indicator" style="display:none;">
            <span class="ll-recording-dot"></span>
            <span id="ll-recording-meter" class="ll-recording-meter" aria-hidden="true">
              <span class="ll-recording-meter-bar"></span>
              <span class="ll-recording-meter-bar"></span>
              <span class="ll-recording-meter-bar"></span>
              <span class="ll-recording-meter-bar"></span>
            </span>
            <span id="ll-recording-timer">0:00</span>
          </div>

          <div id="ll-playback-controls" class="ll-playback-controls" style="display:none;">
            <audio id="ll-playback-audio" controls></audio>
            <div class="ll-playback-actions">
              <button type="button" id="ll-redo-btn" class="ll-btn ll-btn-secondary">Redo</button>
              <button type="button" id="ll-submit-btn" class="ll-btn ll-btn-primary">Save</button>
            </div>
          </div>

          <div id="ll-upload-status" class="ll-upload-status"></div>
        </div>
      </div>

      <div class="ll-recording-complete" style="display:none;">
        <span class="ll-completed-count"></span>
      </div>

      <div id="ll-upload-feedback" class="ll-upload-feedback" hidden>
        <div class="ll-upload-feedback-row">
          <span id="ll-upload-feedback-label"></span>
          <span id="ll-upload-feedback-value" hidden></span>
        </div>
        <div id="ll-upload-progress-bar" class="ll-upload-progress-bar">
          <span id="ll-upload-progress-fill" class="ll-upload-progress-fill"></span>
        </div>
      </div>
    </div>
  `;
}

async function mountRecorder(page) {
  await page.goto('about:blank');
  await page.setContent(buildRecorderMarkup());

  await page.evaluate(() => {
    window.__llTestState = {
      prepareRequests: 0,
      updateTextRequests: 0,
      transcribeRequests: 0,
      uploadRequests: 0
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

    const makeAbortError = () => {
      const error = new Error('Request aborted');
      error.name = 'AbortError';
      return error;
    };

    window.fetch = (url, options = {}) => {
      const body = options.body;
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
        window.__llTestState.prepareRequests += 1;
        const targetText = String(body.get('word_text_target') || '').trim();
        const translationText = String(body.get('word_text_translation') || '').trim();
        const categoryName = String(body.get('new_category_name') || 'Uncategorized').trim() || 'Uncategorized';
        const selectedTypes = body.getAll('new_category_types[]');
        const recordingTypes = selectedTypes.length ? selectedTypes : ['isolation', 'introduction'];

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
        window.__llTestState.updateTextRequests += 1;
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
        window.__llTestState.transcribeRequests += 1;
        return new Promise((resolve, reject) => {
          const timer = setTimeout(() => {
            resolve(makeJsonResponse({
              success: true,
              data: {
                transcript: 'Delayed transcript',
                translation: 'Delayed translation'
              }
            }));
          }, 5000);

          const abortSignal = options.signal;
          if (!abortSignal) {
            return;
          }

          const handleAbort = () => {
            clearTimeout(timer);
            reject(makeAbortError());
          };

          if (abortSignal.aborted) {
            handleAbort();
            return;
          }

          abortSignal.addEventListener('abort', handleAbort, { once: true });
        });
      }

      return Promise.resolve(makeJsonResponse({ success: true, data: {} }));
    };

    class FakeUploadTarget {
      constructor() {
        this.listeners = {};
      }

      addEventListener(type, callback) {
        if (!this.listeners[type]) {
          this.listeners[type] = [];
        }
        this.listeners[type].push(callback);
      }

      dispatch(type, event) {
        (this.listeners[type] || []).forEach((listener) => listener(event));
      }
    }

    class FakeXMLHttpRequest {
      constructor() {
        this.listeners = {};
        this.upload = new FakeUploadTarget();
        this.status = 0;
        this.statusText = '';
        this.responseText = '';
        this.responseType = '';
      }

      open(method, url) {
        this.method = method;
        this.url = url;
      }

      addEventListener(type, callback) {
        if (!this.listeners[type]) {
          this.listeners[type] = [];
        }
        this.listeners[type].push(callback);
      }

      getResponseHeader(name) {
        return String(name || '').toLowerCase() === 'content-type' ? 'application/json' : null;
      }

      send(formData) {
        const action = formData && typeof formData.get === 'function' ? String(formData.get('action') || '') : '';
        if (action !== 'll_upload_recording') {
          this.status = 400;
          this.statusText = 'Bad Request';
          this.responseText = JSON.stringify({ success: false, data: { message: 'Unexpected action' } });
          this.dispatch('load');
          return;
        }

        window.__llTestState.uploadRequests += 1;
        const recordingType = String(formData.get('recording_type') || 'isolation');
        const remainingTypes = recordingType === 'isolation' ? ['introduction'] : [];

        setTimeout(() => {
          this.upload.dispatch('progress', new ProgressEvent('progress', {
            lengthComputable: true,
            loaded: 1,
            total: 1
          }));

          this.status = 200;
          this.statusText = 'OK';
          this.responseText = JSON.stringify({
            success: true,
            data: {
              audio_post_id: 801,
              word_id: 501,
              recording_type: recordingType,
              remaining_types: remainingTypes
            }
          });
          this.dispatch('load');
        }, 10);
      }

      dispatch(type) {
        (this.listeners[type] || []).forEach((listener) => listener());
      }
    }

    class FakeMediaRecorder {
      static isTypeSupported() {
        return true;
      }

      constructor(stream, options = {}) {
        this.stream = stream;
        this.mimeType = options.mimeType || 'audio/webm';
        this.state = 'inactive';
        this.ondataavailable = null;
        this.onstop = null;
      }

      start() {
        this.state = 'recording';
      }

      stop() {
        this.state = 'inactive';
        if (typeof this.ondataavailable === 'function') {
          this.ondataavailable({
            data: new Blob(['fake-audio'], { type: this.mimeType })
          });
        }
        if (typeof this.onstop === 'function') {
          setTimeout(() => this.onstop(), 0);
        }
      }
    }

    const fakeStream = {
      getTracks() {
        return [{ stop() {} }];
      }
    };

    try {
      Object.defineProperty(window, 'XMLHttpRequest', {
        value: FakeXMLHttpRequest,
        configurable: true
      });
    } catch (_) {
      window.XMLHttpRequest = FakeXMLHttpRequest;
    }

    try {
      Object.defineProperty(window, 'MediaRecorder', {
        value: FakeMediaRecorder,
        configurable: true
      });
    } catch (_) {
      window.MediaRecorder = FakeMediaRecorder;
    }

    try {
      Object.defineProperty(navigator, 'mediaDevices', {
        value: {
          async getUserMedia() {
            return fakeStream;
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
          return fakeStream;
        },
        async enumerateDevices() {
          return [{ kind: 'audioinput', deviceId: 'fake-mic', label: 'Fake Mic' }];
        }
      };
    }

    try {
      Object.defineProperty(window, 'AudioContext', {
        value: undefined,
        configurable: true
      });
      Object.defineProperty(window, 'webkitAudioContext', {
        value: undefined,
        configurable: true
      });
    } catch (_) {
      window.AudioContext = undefined;
      window.webkitAudioContext = undefined;
    }

    window.ll_recorder_data = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      images: [],
      available_categories: {
        uncategorized: 'Uncategorized'
      },
      language: '',
      wordset: '',
      wordset_ids: [],
      sort_locale: 'en_US',
      hide_name: false,
      recording_types: [
        { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' },
        { slug: 'introduction', name: 'Introduction', label: 'Introduction', icon: '' }
      ],
      recording_type_order: ['isolation', 'introduction'],
      recording_type_icons: {
        default: ''
      },
      allow_new_words: true,
      assembly_enabled: true,
      deepl_enabled: false,
      user_display_name: 'Recorder Tester',
      require_all_types: true,
      initial_category: 'uncategorized',
      include_types: '',
      exclude_types: '',
      auto_process_recordings: false,
      stop_delay_ms: 500,
      current_user_id: 10,
      hidden_words: [],
      hidden_count: 0,
      i18n: {
        category: 'Category:',
        uncategorized: 'Uncategorized',
        transcribing: 'Transcribing...',
        uploading: 'Uploading...',
        saved_next_type: 'Saved. Next type selected.',
        success: 'Success! Recording will be processed later.',
        new_word_prepare_failed: 'Failed to prepare new word',
        new_word_update_text_failed: 'Failed to update word text'
      }
    };
  });

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

test('pending new-word transcription does not block save and advances to the intro type', async ({ page }) => {
  await mountRecorder(page);

  await expect(page.locator('#ll-new-word-panel')).toBeVisible();

  await page.locator('#ll-new-word-create-category').check({ force: true });
  await page.fill('#ll-new-word-category-name', 'Debug Category');
  await page.locator('.ll-new-word-types input[value="introduction"]').check({ force: true });
  await page.fill('#ll-new-word-text-target', 'Entered word');
  await page.fill('#ll-new-word-text-translation', 'Entered translation');

  const recordButton = page.locator('#ll-new-word-record-btn');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/recording/);

  await recordButton.click();
  await expect(recordButton).toHaveClass(/stopping/);
  await expect(recordButton).toBeDisabled();
  await page.waitForTimeout(150);
  await expect(page.locator('#ll-new-word-start')).toBeHidden();
  await expect(page.locator('#ll-new-word-start')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => window.__llTestState.transcribeRequests)).toBe(1);

  await page.locator('#ll-new-word-start').click();

  await expect.poll(async () => page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.updateTextRequests)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);

  await expect(page.locator('#ll-recording-type')).toHaveValue('introduction');
  await expect(page.locator('.ll-recording-main')).toBeVisible();
  await expect(page.locator('#ll-new-word-overlay')).toHaveAttribute('hidden', 'hidden');
});
