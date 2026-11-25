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
          <div id="ll-recording-prompt" class="ll-recording-prompt" hidden></div>

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

async function mountRecorder(page, options = {}) {
  await page.goto('about:blank');
  await page.setContent(buildRecorderMarkup());

  await page.evaluate((mountOptions) => {
    const initialImages = Array.isArray(mountOptions.images) ? mountOptions.images : [];
    const uploadRemainingTypes = Array.isArray(mountOptions.uploadRemainingTypes)
      ? mountOptions.uploadRemainingTypes
      : null;
    const uploadRemainingTypesSequence = Array.isArray(mountOptions.uploadRemainingTypesSequence)
      ? mountOptions.uploadRemainingTypesSequence
      : null;
    const defaultRecordingTypes = [
      { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' },
      { slug: 'introduction', name: 'Introduction', label: 'Introduction', icon: '' }
    ];
    const recordingTypes = Array.isArray(mountOptions.recordingTypes) && mountOptions.recordingTypes.length
      ? mountOptions.recordingTypes
      : defaultRecordingTypes;
    const recordingTypeOrder = Array.isArray(mountOptions.recordingTypeOrder) && mountOptions.recordingTypeOrder.length
      ? mountOptions.recordingTypeOrder
      : recordingTypes.map((type) => String(type.slug || '').trim()).filter(Boolean);

    window.__llTestState = {
      prepareRequests: 0,
      preparedWordCount: 0,
      preparePayloads: [],
      prepareReceipts: {},
      textReadRequests: 0,
      textPayloads: [],
      textServer: { target: '', translation: '', revision: 0 },
      updateTextRequests: 0,
      transcribeRequests: 0,
      uploadRequests: 0,
      verifyRequests: 0,
      lastUploadPayload: null,
      lastCategoryTypePayload: null,
      uploadRemainingTypes,
      uploadRemainingTypesSequence
    };

    const makeJsonResponse = (payload, status = 200) => ({
      ok: status >= 200 && status < 300,
      status,
      statusText: 'OK',
      headers: {
        get(name) {
          return String(name || '').toLowerCase() === 'content-type' ? 'application/json' : null;
        }
      },
      async json() {
        return JSON.parse(JSON.stringify(payload));
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
        window.__llTestState.lastCategoryTypePayload = {
          category: String(body.get('category') || ''),
          wordset: String(body.get('wordset') || ''),
          wordset_ids: String(body.get('wordset_ids') || '')
        };
        return Promise.resolve(makeJsonResponse({
          success: true,
          data: {
            recording_types: recordingTypes
          }
        }));
      }

      const currentText = () => ({
        word_id: 501,
        word_text_target: window.__llTestState.textServer.target,
        word_translation: window.__llTestState.textServer.translation,
        post_title: window.__llTestState.textServer.target,
        store_in_title: true,
        text_revision: 'revision-' + window.__llTestState.textServer.revision
      });
      if (action === 'll_prepare_new_word_recording') {
        window.__llTestState.prepareRequests += 1;
        window.__llTestState.preparePayloads.push(Array.from(body.entries()));
        if (mountOptions.preparationProtocolError) {
          return Promise.resolve(makeJsonResponse({ success: false, data: {
            code: mountOptions.preparationProtocolError, message: 'Server preparation needs review'
          } }, 409));
        }
        const preparationId = String(body.get('preparation_id') || '');
        if (window.__llTestState.prepareReceipts[preparationId]) {
          return Promise.resolve(makeJsonResponse(window.__llTestState.prepareReceipts[preparationId]));
        }
        if (window.__llTestState.prepareRequests === 1 && mountOptions.firstPrepareError) {
          return Promise.resolve(makeJsonResponse({ success: false, data: mountOptions.firstPrepareError, safe_to_retry: true }));
        }
        window.__llTestState.preparedWordCount += 1;
        const targetText = String(body.get('word_text_target') || '').trim();
        const translationText = String(body.get('word_text_translation') || '').trim();
        const categoryName = String(body.get('new_category_name') || 'Uncategorized').trim() || 'Uncategorized';
        const selectedTypes = body.getAll('new_category_types[]');
        const recordingTypes = selectedTypes.length ? selectedTypes : ['isolation', 'introduction'];
        window.__llTestState.textServer = { target: targetText || 'Placeholder', translation: translationText, revision: 1 };
        const payload = {
          success: true,
          data: {
            word: Object.assign({
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
            }, currentText()),
            recording_types: recordingTypes.map((slug) => ({
              slug,
              name: slug === 'introduction' ? 'Introduction' : 'Isolation',
              label: slug === 'introduction' ? 'Introduction' : 'Isolation',
              icon: ''
            })),
            category: { slug: 'debug-category', name: categoryName, term_id: 91 }
          }
        };
        if (mountOptions.legacyPrepare) delete payload.data.word.text_revision;
        window.__llTestState.prepareReceipts[preparationId] = JSON.parse(JSON.stringify(payload));
        if (mountOptions.mutateAfterPrepare) {
          Object.assign(window.__llTestState.textServer, mountOptions.mutateAfterPrepare);
          window.__llTestState.textServer.revision += 1;
        }
        if (window.__llTestState.prepareRequests === 1 && mountOptions.firstPrepareFailure === 'transport') {
          return Promise.reject(new TypeError('Failed to fetch'));
        }
        if (window.__llTestState.prepareRequests === 1 && mountOptions.firstPrepareFailure === 'json') {
          const response = makeJsonResponse({});
          response.json = async () => { throw new SyntaxError('Unexpected token in JSON'); };
          return Promise.resolve(response);
        }
        if (window.__llTestState.prepareRequests === 1 && mountOptions.firstPrepareFailure === 'malformed') {
          return Promise.resolve(makeJsonResponse({ success: true, data: { word: {}, recording_types: [] } }));
        }
        if (window.__llTestState.prepareRequests === 1 && mountOptions.firstPrepareFailure === 'boolean_id') {
          return Promise.resolve(makeJsonResponse({ success: true, data: { word: { word_id: true }, recording_types: [] } }));
        }
        const delayMs = Number(mountOptions.prepareDelayMs || 0);
        const response = makeJsonResponse(payload);
        return delayMs > 0 ? new Promise(resolve => setTimeout(() => resolve(response), delayMs)) : Promise.resolve(response);
      }
      if (action === 'll_get_new_word_text') {
        window.__llTestState.textReadRequests += 1;
        return Promise.resolve(makeJsonResponse({ success: true, data: currentText() }));
      }
      if (action === 'll_update_new_word_text') {
        window.__llTestState.updateTextRequests += 1;
        window.__llTestState.textPayloads.push(Object.fromEntries(body.entries()));
        if (String(body.get('expected_text_revision') || '') !== currentText().text_revision) {
          return Promise.resolve(makeJsonResponse({ success: false, data: {
            code: 'recorder_text_conflict', message: 'Word text changed elsewhere', current: currentText()
          } }, 409));
        }
        window.__llTestState.textServer.target = String(body.get('word_text_target') || '').trim() || window.__llTestState.textServer.target;
        window.__llTestState.textServer.translation = String(body.get('word_text_translation') || '').trim();
        window.__llTestState.textServer.revision += 1;
        return Promise.resolve(makeJsonResponse({ success: true, data: currentText() }));
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

      if (action === 'll_verify_recording') {
        window.__llTestState.verifyRequests += 1;
        if (!mountOptions.hangVerify) {
          return Promise.resolve(makeJsonResponse({ success: true, data: {} }));
        }

        return new Promise((resolve, reject) => {
          const abortSignal = options.signal;
          if (!abortSignal) {
            return;
          }

          const handleAbort = () => reject(makeAbortError());
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
        if (window.__llTestState.uploadRequests === 1 && mountOptions.firstUploadError) {
          this.status = 400;
          this.statusText = 'Bad Request';
          this.responseText = JSON.stringify({ success: false, data: mountOptions.firstUploadError });
          this.dispatch('load');
          return;
        }
        const recordingType = String(formData.get('recording_type') || 'isolation');
        const uploadPayload = {};
        if (formData && typeof formData.entries === 'function') {
          for (const [key, value] of formData.entries()) {
            uploadPayload[key] = value instanceof Blob
              ? {
                name: String(value.name || ''),
                type: String(value.type || ''),
                size: value.size
              }
              : String(value);
          }
        }
        window.__llTestState.lastUploadPayload = uploadPayload;
        if (mountOptions.hangUpload) {
          setTimeout(() => this.dispatch('timeout'), Number(this.timeout) || 0);
          return;
        }
        const uploadIndex = window.__llTestState.uploadRequests - 1;
        const sequencedRemainingTypes = Array.isArray(window.__llTestState.uploadRemainingTypesSequence)
          ? window.__llTestState.uploadRemainingTypesSequence[uploadIndex]
          : null;
        const remainingTypes = Array.isArray(sequencedRemainingTypes)
          ? sequencedRemainingTypes.slice()
          : Array.isArray(window.__llTestState.uploadRemainingTypes)
          ? window.__llTestState.uploadRemainingTypes.slice()
          : (recordingType === 'isolation' ? ['introduction'] : []);

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
      images: initialImages,
      available_categories: {
        uncategorized: 'Uncategorized'
      },
      language: '',
      wordset: String(mountOptions.wordset || ''),
      wordset_ids: Array.isArray(mountOptions.wordsetIds) ? mountOptions.wordsetIds : [],
      sort_locale: 'en_US',
      hide_name: false,
      recording_types: [
        ...recordingTypes
      ],
      recording_type_order: recordingTypeOrder,
      recording_type_icons: {
        default: ''
      },
      allow_new_words: true,
      assembly_enabled: mountOptions.assemblyEnabled !== false,
      deepl_enabled: mountOptions.deeplEnabled === true,
      user_display_name: 'Recorder Tester',
      require_all_types: true,
      initial_category: 'uncategorized',
      include_types: '',
      exclude_types: '',
      auto_process_recordings: false,
      stop_delay_ms: Number.isFinite(mountOptions.stopDelayMs) ? mountOptions.stopDelayMs : 500,
      upload_timeout_ms: Number.isFinite(mountOptions.uploadTimeoutMs) ? mountOptions.uploadTimeoutMs : undefined,
      verify_timeout_ms: Number.isFinite(mountOptions.verifyTimeoutMs) ? mountOptions.verifyTimeoutMs : undefined,
      request_timeout_ms: Number.isFinite(mountOptions.requestTimeoutMs) ? mountOptions.requestTimeoutMs : undefined,
      current_user_id: 10,
      hidden_words: [],
      hidden_count: 0,
      i18n: Object.assign({
        category: 'Category:',
        uncategorized: 'Uncategorized',
        transcribing: 'Transcribing...',
        uploading: 'Uploading...',
        saved_next_type: 'Saved. Next type selected.',
        recording_prompt_label: 'Say:',
        success: 'Success! Recording will be processed later.',
        new_word_prepare_failed: 'Failed to prepare new word',
        new_word_update_text_failed: 'Failed to update word text',
        new_word_prepare_unknown: 'New word creation could not be confirmed. Check for an existing draft before recording this word again.',
        new_word_saving: 'Saving...',
        new_word_saved: 'Saved',
        new_word_prepare_retry: 'Retry preparation',
        new_word_download_recording: 'Download recording',
        new_word_prepare_recovering: 'Checking new word...',
        new_word_text_conflict: 'This word changed elsewhere. Review the saved text before choosing which changes to keep.',
        new_word_saved_text: 'Current saved text',
        new_word_use_saved_text: 'Use saved text',
        new_word_save_my_changes: 'Save my changes',
        new_word_check_saved_text: 'Check saved text',
        new_word_text_read_failed: 'The saved word text could not be checked. Please try again.'
      }, mountOptions.i18n || {})
    };
  }, options);

  await page.addScriptTag({ content: recorderJsSource });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });
}

async function recordAndSubmitCurrentType(page) {
  const recordButton = page.locator('#ll-record-btn');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/recording/);

  await recordButton.click();
  await expect(recordButton).toHaveClass(/stopping/);
  await expect(page.locator('#ll-submit-btn')).toBeVisible();

  await page.locator('#ll-submit-btn').click();
}

async function recordNewWord(page) {
  await page.locator('#ll-new-word-toggle').click();
  await page.locator('#ll-new-word-text-target').fill('Initial word');
  await page.locator('#ll-new-word-text-translation').fill('Initial translation');
  const record = page.locator('#ll-new-word-record-btn');
  await record.click();
  await expect(record).toHaveClass(/recording/);
  await record.click();
  await expect(page.locator('#ll-new-word-start')).toBeVisible();
  await expect(page.locator('#ll-new-word-start')).toBeEnabled();
}

async function prepareRetainedNewWord(page, options = {}) {
  await mountRecorder(page, Object.assign({ assemblyEnabled: false, stopDelayMs: 100, firstUploadError: 'Upload rejected' }, options));
  await recordNewWord(page);
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('#ll-new-word-status')).toContainText('Upload rejected');
  await expect(page.locator('#ll-new-word-start')).toBeEnabled();
}

async function deferPreparedTextWrites(page) {
  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.__llPendingTextWrites = [];
    window.fetch = (url, options = {}) => {
      if (options.body?.get('action') !== 'll_update_new_word_text') return originalFetch(url, options);
      const target = String(options.body.get('word_text_target') || '');
      const translation = String(options.body.get('word_text_translation') || '');
      const revision = String(options.body.get('expected_text_revision') || '');
      return new Promise((resolve, reject) => {
        window.__llPendingTextWrites.push({
          target,
          translation,
          revision,
          reject,
          resolve(overrides = {}) {
            resolve({ ok: true, json: async () => ({ success: true, data: Object.assign({
              word_id: 501,
              post_title: target,
              word_text_target: target,
              word_translation: translation,
              store_in_title: true,
              text_revision: revision + '-next'
            }, overrides) }) });
          }
        });
      });
    };
  });
}

test('prepared-word autosaves coalesce newer edits and Save waits for the latest acknowledgement', async ({ page }) => {
  await prepareRetainedNewWord(page);
  await deferPreparedTextWrites(page);
  const target = page.locator('#ll-new-word-text-target');
  await target.fill('Older');
  await target.blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await target.fill('Intermediate');
  await target.blur();
  await target.fill('Newest');
  await target.blur();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await expect(page.locator('#ll-new-word-status')).toHaveText('Saving...');

  await page.locator('#ll-new-word-start').click();
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[0].resolve());
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(2);
  await expect(target).toHaveValue('Newest');
  expect(await page.evaluate(() => window.ll_recorder_data.images[0].word_title)).toBe('Initial word');
  expect(await page.evaluate(() => window.__llPendingTextWrites[1].target)).toBe('Newest');
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[1].resolve());
  await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(2);
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  expect(await page.evaluate(() => window.ll_recorder_data.images[0].word_title)).toBe('Newest');
});

test('prepared-word translation clearing reconciles canonical acknowledgements without a save loop', async ({ page }) => {
  await prepareRetainedNewWord(page);
  await deferPreparedTextWrites(page);
  const translation = page.locator('#ll-new-word-text-translation');
  await translation.fill('');
  await translation.blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[0].resolve({ word_text_target: 'Normalized target', post_title: 'Normalized target' }));
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Normalized target');
  await expect(translation).toHaveValue('');
  await expect(page.locator('#ll-new-word-status')).toHaveText('Saved');
  expect(await page.evaluate(() => window.ll_recorder_data.images[0].word_translation)).toBe('');
  await translation.focus();
  await translation.blur();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
});

test('an upload locks prepared text fields until its response settles', async ({ page }) => {
  await prepareRetainedNewWord(page);
  await expect(page.locator('#ll-new-word-text-target')).toBeEnabled();
  await expect(page.locator('#ll-new-word-text-translation')).toBeEnabled();
  await page.evaluate(() => {
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (formData) {
      window.__llReleaseHeldUpload = () => originalSend.call(this, formData);
    };
  });
  await page.locator('#ll-new-word-start').click();
  await expect.poll(() => page.evaluate(() => typeof window.__llReleaseHeldUpload)).toBe('function');
  await expect(page.locator('#ll-new-word-text-target')).toBeDisabled();
  await expect(page.locator('#ll-new-word-text-translation')).toBeDisabled();
  await page.evaluate(() => window.__llReleaseHeldUpload());
  await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(2);
  await expect(page.locator('#ll-new-word-text-target')).toBeEnabled();
  await expect(page.locator('#ll-new-word-text-translation')).toBeEnabled();
});

test('a final successful upload keeps edits and capture locked until the next form opens', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, uploadRemainingTypes: [] });
  await recordNewWord(page);
  await page.clock.install();
  await page.clock.pauseAt(new Date(Date.now() + 1000));
  await page.locator('#ll-new-word-start').click();
  await page.clock.runFor(20);
  await expect(page.locator('#ll-new-word-status')).toContainText('Success!');
  await expect(page.locator('#ll-new-word-text-target')).toBeDisabled();
  await expect(page.locator('#ll-new-word-text-translation')).toBeDisabled();
  await expect(page.locator('#ll-new-word-record-btn')).toBeDisabled();
  await expect(page.locator('#ll-new-word-back')).toBeDisabled();
  await page.clock.runFor(800);
  await expect(page.locator('#ll-new-word-text-target')).toBeEnabled();
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('');
  await expect(page.locator('#ll-new-word-record-btn')).toBeEnabled();
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
});

test('an old prepared-word acknowledgement cannot mutate a newly opened draft', async ({ page }) => {
  await prepareRetainedNewWord(page);
  await deferPreparedTextWrites(page);
  await page.locator('#ll-new-word-text-target').fill('Retired word');
  await page.locator('#ll-new-word-text-target').blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await page.locator('#ll-new-word-redo-btn').click();
  await page.locator('#ll-new-word-back').click();
  await page.locator('#ll-new-word-toggle').click();
  await page.locator('#ll-new-word-text-target').fill('New draft');
  await page.evaluate(() => window.__llPendingTextWrites[0].resolve());
  await page.waitForTimeout(300);
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('New draft');
  await expect(page.locator('#ll-new-word-status')).toBeHidden();
  expect(await page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
});

test('prepared-word native save failures require a read and explicit revision review before retry', async ({ page }) => {
  await prepareRetainedNewWord(page, { i18n: { new_word_update_text_failed: 'Kelime metni güncellenemedi' } });
  await deferPreparedTextWrites(page);
  await page.locator('#ll-new-word-text-target').fill('Retained edit');
  await page.locator('#ll-new-word-text-target').blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[0].reject(new TypeError('Failed to fetch')));
  await expect(page.locator('#ll-new-word-status')).toHaveText('Kelime metni güncellenemedi');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Retained edit');
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await expect(page.locator('#ll-new-word-start')).toBeDisabled();
  await page.locator('[data-ll-new-word-check-saved]').click();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Initial word');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Retained edit');
  await page.locator('[data-ll-new-word-save-local]').click();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(2);
  await page.evaluate(() => window.__llPendingTextWrites[1].resolve());
  await expect(page.locator('#ll-new-word-status')).toHaveText('Saved');
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
});

test('a precreation rejection can retry the retained recording without creating duplicate drafts', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, firstPrepareError: 'Choose another category' });
  await recordNewWord(page);
  const audio = page.locator('#ll-new-word-playback-audio');
  const recordingUrl = await audio.getAttribute('src');
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('#ll-new-word-status')).toHaveText('Choose another category');
  await expect(page.locator('#ll-new-word-start')).toBeEnabled();
  await expect(audio).toHaveAttribute('src', recordingUrl);
  await page.locator('#ll-new-word-start').click();
  await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  expect(await page.evaluate(() => ({ requests: window.__llTestState.prepareRequests, created: window.__llTestState.preparedWordCount }))).toEqual({ requests: 2, created: 1 });
});

test('preparation fails closed without cryptographic operation IDs and retains the recording', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100 });
  await recordNewWord(page);
  const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
  await page.evaluate(() => Object.defineProperty(window.crypto, 'getRandomValues', { configurable: true, value: undefined }));
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('#ll-new-word-status')).toHaveText('Failed to prepare new word');
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(0);
  await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
});

test('concurrent word edits require explicit review and every overwrite remains conditional', async ({ page }) => {
  await prepareRetainedNewWord(page);
  const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
  await page.evaluate(() => {
    Object.assign(window.__llTestState.textServer, { target: 'Other tab text', translation: 'Other tab translation' });
    window.__llTestState.textServer.revision += 1;
  });
  await page.locator('#ll-new-word-text-target').fill('My retained edit');
  await page.locator('#ll-new-word-text-target').blur();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Other tab text');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('My retained edit');
  await expect(page.locator('#ll-new-word-start')).toBeDisabled();
  await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llTestState.updateTextRequests)).toBe(2);

  await page.evaluate(() => {
    window.__llTestState.textServer.target = 'Newer other tab text';
    window.__llTestState.textServer.revision += 1;
  });
  await page.locator('[data-ll-new-word-save-local]').click();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Newer other tab text');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('My retained edit');
  expect(await page.evaluate(() => window.__llTestState.textServer.target)).toBe('Newer other tab text');
  await page.locator('[data-ll-new-word-save-local]').click();
  await expect(page.locator('#ll-new-word-status')).toHaveText('Saved');
  expect(await page.evaluate(() => window.__llTestState.textServer.target)).toBe('My retained edit');
  expect(await page.evaluate(() => window.__llTestState.textPayloads.map(payload => payload.expected_text_revision)))
    .toEqual(['revision-1', 'revision-2', 'revision-3', 'revision-4']);
  await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
});

for (const failure of ['transport', 'body']) {
  test(`a committed text save with a lost ${failure} response is acknowledged by a bounded read without replay`, async ({ page }) => {
    await prepareRetainedNewWord(page, { requestTimeoutMs: 75 });
    const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
    await page.evaluate(({ failure }) => {
      const originalFetch = window.fetch;
      let failed = false;
      window.fetch = async (url, options = {}) => {
        const response = await originalFetch(url, options);
        if (options.body?.get('action') !== 'll_update_new_word_text' || failed) return response;
        failed = true;
        if (failure === 'transport') throw new TypeError('Failed to fetch');
        return Object.assign({}, response, { json: () => new Promise(() => {}) });
      };
    }, { failure });
    await page.locator('#ll-new-word-text-target').fill('Committed edit');
    await page.locator('#ll-new-word-text-target').blur();
    await expect(page.locator('[data-ll-new-word-check-saved]')).toBeEnabled();
    await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Committed edit');
    expect(await page.evaluate(() => window.__llTestState.updateTextRequests)).toBe(2);
    await page.locator('[data-ll-new-word-check-saved]').click();
    await expect(page.locator('#ll-new-word-status')).toHaveText('Saved');
    expect(await page.evaluate(() => window.__llTestState.updateTextRequests)).toBe(2);
    expect(await page.evaluate(() => window.__llTestState.textReadRequests)).toBe(1);
    await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
    await page.locator('#ll-new-word-start').click();
    await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(2);
    expect(await page.evaluate(() => window.__llTestState.textPayloads.at(-1).expected_text_revision)).toBe('revision-3');
    expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  });
}

test('legacy preparation bootstraps a revision only while its original text baseline still matches', async ({ page }) => {
  await prepareRetainedNewWord(page, { legacyPrepare: true });
  expect(await page.evaluate(() => window.__llTestState.textReadRequests)).toBe(1);
  expect(await page.evaluate(() => window.__llTestState.textPayloads[0].expected_text_revision)).toBe('revision-1');
});

test('legacy revision bootstrap preserves the local draft when another writer changed the saved text', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, legacyPrepare: true,
    mutateAfterPrepare: { target: 'Other tab baseline', translation: 'Other translation' } });
  await recordNewWord(page);
  const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Other tab baseline');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Initial word');
  expect(await page.evaluate(() => window.__llTestState.updateTextRequests)).toBe(0);
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(0);
  await page.locator('[data-ll-new-word-use-saved]').click();
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Other tab baseline');
  await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
  await page.locator('#ll-new-word-start').click();
  await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  expect(await page.evaluate(() => window.__llTestState.textPayloads[0].expected_text_revision)).toBe('revision-2');
});

test('a replayed creation receipt checks its original revision before uploading even unchanged text', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, firstPrepareFailure: 'transport',
    mutateAfterPrepare: { target: 'Other tab after creation', translation: 'Other translation' } });
  await recordNewWord(page);
  await page.locator('#ll-new-word-start').click();
  await page.locator('[data-ll-new-word-retry-prepare]').click();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Other tab after creation');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Initial word');
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(0);
  expect(await page.evaluate(() => window.__llTestState.textPayloads[0].expected_text_revision)).toBe('revision-1');
  expect(await page.evaluate(() => window.__llTestState.preparedWordCount)).toBe(1);
});

test('a later recording type opens visible conflict review without discarding its captured audio', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100 });
  await recordNewWord(page);
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('.ll-recording-main')).toBeVisible();
  await expect(page.locator('#ll-recording-type')).toHaveValue('introduction');
  await page.locator('#ll-record-btn').click();
  await expect(page.locator('#ll-record-btn')).toHaveClass(/recording/);
  await page.locator('#ll-record-btn').click();
  await expect(page.locator('#ll-submit-btn')).toBeVisible();
  await page.evaluate(() => {
    window.__llTestState.textServer.target = 'Other tab during next type';
    window.__llTestState.textServer.revision += 1;
  });
  await page.locator('#ll-submit-btn').click();
  await expect(page.locator('#ll-new-word-panel')).toBeVisible();
  await expect(page.locator('.ll-new-word-recovery-text')).toContainText('Other tab during next type');
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Initial word');
  await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', /^blob:/);
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await page.locator('[data-ll-new-word-save-local]').click();
  await expect(page.locator('#ll-new-word-status')).toHaveText('Saved');
  await page.locator('#ll-new-word-start').click();
  await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(2);
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
});

for (const code of ['recorder_preparation_expired', 'recorder_preparation_incomplete', 'recorder_preparation_mismatch']) {
  test(`${code} retains audio without inviting a new operation or automatic retries`, async ({ page }) => {
    await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, preparationProtocolError: code });
    await recordNewWord(page);
    const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
    await page.locator('#ll-new-word-start').click();
    await expect(page.locator('#ll-new-word-status')).toHaveText('Server preparation needs review');
    await expect(page.locator('[data-ll-new-word-retry-prepare]')).toHaveCount(0);
    await expect(page.locator('#ll-new-word-start')).toBeDisabled();
    await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: 'Download recording' }).click()
    ]);
    expect(download.suggestedFilename()).toMatch(/^recording\.(?:webm|wav|ogg|mp4)$/);
    const chunks = [];
    for await (const chunk of await download.createReadStream()) {
      chunks.push(chunk);
    }
    expect(Buffer.concat(chunks).toString()).toBe('fake-audio');
    await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
    await page.waitForTimeout(300);
    const requests = await page.evaluate(() => ({
      preparation: window.__llTestState.prepareRequests,
      uploads: window.__llTestState.uploadRequests
    }));
    expect(requests).toEqual({ preparation: 1, uploads: 0 });
  });
}

test('a busy creation receipt retries only on demand and keeps the same operation payload', async ({ page }) => {
  await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, preparationProtocolError: 'recorder_preparation_busy' });
  await recordNewWord(page);
  await page.locator('#ll-new-word-start').click();
  await expect(page.locator('[data-ll-new-word-retry-prepare]')).toBeEnabled();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  await page.locator('[data-ll-new-word-retry-prepare]').click();
  await expect.poll(() => page.evaluate(() => window.__llTestState.prepareRequests)).toBe(2);
  const payloads = await page.evaluate(() => window.__llTestState.preparePayloads);
  expect(payloads[1]).toEqual(payloads[0]);
});

test('a late read-only recovery response cannot replace a newer draft', async ({ page }) => {
  await prepareRetainedNewWord(page);
  await deferPreparedTextWrites(page);
  await page.locator('#ll-new-word-text-target').fill('Retained before redo');
  await page.locator('#ll-new-word-text-target').blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[0].reject(new TypeError('Failed to fetch')));
  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.fetch = (url, options = {}) => {
      if (options.body?.get('action') !== 'll_get_new_word_text') return originalFetch(url, options);
      return new Promise(resolve => {
        window.__llResolveOldTextRead = () => resolve({ ok: true, json: async () => ({ success: true, data: {
          word_id: 501, word_text_target: 'Retired text', word_translation: 'Retired translation', text_revision: 'retired-revision'
        } }) });
      });
    };
  });
  await page.locator('[data-ll-new-word-check-saved]').click();
  await expect.poll(() => page.evaluate(() => typeof window.__llResolveOldTextRead)).toBe('function');
  await page.locator('#ll-new-word-redo-btn').click();
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Retained before redo');
  await page.locator('#ll-new-word-back').click();
  await page.locator('#ll-new-word-toggle').click();
  await page.locator('#ll-new-word-text-target').fill('New draft');
  await page.evaluate(() => window.__llResolveOldTextRead());
  await page.waitForTimeout(300);
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('New draft');
  await expect(page.locator('[data-ll-new-word-recovery]')).toHaveCount(0);
});

test('a recovery read body deadline preserves the local draft and exposes one manual retry', async ({ page }) => {
  await prepareRetainedNewWord(page, { requestTimeoutMs: 75 });
  await deferPreparedTextWrites(page);
  await page.locator('#ll-new-word-text-target').fill('Read failure draft');
  await page.locator('#ll-new-word-text-target').blur();
  await expect.poll(() => page.evaluate(() => window.__llPendingTextWrites.length)).toBe(1);
  await page.evaluate(() => window.__llPendingTextWrites[0].reject(new TypeError('Failed to fetch')));
  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.fetch = async (url, options = {}) => {
      const response = await originalFetch(url, options);
      return options.body?.get('action') === 'll_get_new_word_text'
        ? Object.assign({}, response, { json: () => new Promise(() => {}) }) : response;
    };
  });
  await page.locator('[data-ll-new-word-check-saved]').click();
  await expect(page.locator('#ll-new-word-status')).toHaveText('The saved word text could not be checked. Please try again.');
  await expect(page.locator('[data-ll-new-word-check-saved]')).toBeEnabled();
  await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Read failure draft');
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.__llTestState.textReadRequests)).toBe(1);
});

for (const failure of ['transport', 'json', 'malformed', 'boolean_id']) {
  test(`ambiguous ${failure} preparation failure replays the frozen receipt only after explicit recovery`, async ({ page }) => {
    await mountRecorder(page, { assemblyEnabled: false, stopDelayMs: 100, firstPrepareFailure: failure,
      i18n: { new_word_prepare_unknown: 'Taslak oluşturma doğrulanamadı' } });
    await recordNewWord(page);
    const recordingUrl = await page.locator('#ll-new-word-playback-audio').getAttribute('src');
    await page.locator('#ll-new-word-start').click();
    await expect(page.locator('#ll-new-word-status')).toHaveText('Taslak oluşturma doğrulanamadı');
    await expect(page.locator('#ll-new-word-start')).toBeDisabled();
    await expect(page.locator('#ll-new-word-playback-audio')).toHaveAttribute('src', recordingUrl);
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
    expect(await page.evaluate(() => window.__llTestState.preparedWordCount)).toBe(1);
    expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(0);
    await page.locator('#ll-new-word-text-target').fill('Edited while recovering');
    await page.locator('[data-ll-new-word-retry-prepare]').click();
    await expect.poll(() => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
    const state = await page.evaluate(() => ({
      payloads: window.__llTestState.preparePayloads,
      created: window.__llTestState.preparedWordCount,
      target: window.__llTestState.textServer.target,
      expected: window.__llTestState.textPayloads[0].expected_text_revision
    }));
    expect(state.payloads).toHaveLength(2);
    expect(state.payloads[1]).toEqual(state.payloads[0]);
    expect(Object.fromEntries(state.payloads[0]).preparation_id).toMatch(/^\d+-[a-f0-9]{32}$/);
    expect(state.created).toBe(1);
    expect(state.target).toBe('Edited while recovering');
    expect(state.expected).toBe('revision-1');
  });

  if (!['transport', 'json'].includes(failure)) continue;
  for (const provider of ['translation', 'transcription']) {
    test(`new-word ${provider} ${failure} errors use localized feedback and retain entered text`, async ({ page }) => {
      const message = provider === 'translation' ? 'Çeviri yapılamadı' : 'Transkripsiyon yapılamadı';
      await mountRecorder(page, {
        assemblyEnabled: provider === 'transcription',
        deeplEnabled: provider === 'translation',
        stopDelayMs: 100,
        i18n: { [`${provider}_request_failed`]: message }
      });
      await page.evaluate(({ provider, failure }) => {
        const originalFetch = window.fetch;
        const action = provider === 'translation' ? 'll_translate_recording_text' : 'll_transcribe_recording';
        window.fetch = (url, options = {}) => {
          if (options.body?.get('action') !== action) return originalFetch(url, options);
          if (failure === 'transport') return Promise.reject(new TypeError('Failed to fetch'));
          return Promise.resolve({ ok: true, json: async () => { throw new SyntaxError('Unexpected token in JSON'); } });
        };
      }, { provider, failure });
      if (provider === 'transcription') {
        await recordNewWord(page);
      } else {
        await page.locator('#ll-new-word-toggle').click();
        await page.locator('#ll-new-word-text-target').fill('Initial word');
        await page.locator('#ll-new-word-text-target').blur();
      }
      await expect(page.locator('#ll-new-word-status')).toHaveText(message);
      await expect(page.locator('#ll-new-word-text-target')).toHaveValue('Initial word');
      await expect(page.locator('#ll-new-word-text-target')).toBeEnabled();
    });
  }
}

test('new-word category type preflight includes the selected wordset scope', async ({ page }) => {
  await mountRecorder(page, {
    wordset: 'non-default-wordset',
    wordsetIds: [42]
  });

  await page.locator('#ll-new-word-toggle').click();
  await expect(page.locator('#ll-new-word-panel')).toBeVisible();
  await expect.poll(async () => page.evaluate(() => window.__llTestState.lastCategoryTypePayload)).toEqual({
    category: 'uncategorized',
    wordset: 'non-default-wordset',
    wordset_ids: '[42]'
  });
});

test('successful word upload advances past a stale returned recording type', async ({ page }) => {
  await mountRecorder(page, {
    images: [{
      id: 101,
      title: 'Existing word',
      image_url: '',
      category_name: 'Debug Category',
      category_slug: 'debug-category',
      word_id: 501,
      word_title: 'Existing word',
      word_translation: 'Existing translation',
      use_word_display: true,
      missing_types: ['isolation', 'introduction'],
      existing_types: [],
      prompt_types: ['isolation', 'introduction'],
      my_existing_types: [],
      is_text_only: true
    }],
    uploadRemainingTypes: ['isolation', 'introduction']
  });

  await expect(page.locator('#ll-recording-type')).toHaveValue('isolation');

  await recordAndSubmitCurrentType(page);

  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await expect(page.locator('#ll-recording-type')).toHaveValue('introduction');
  await expect(page.locator('#ll-upload-status')).toContainText('Saved. Next type selected.');
});

test('final required type upload advances to the next word when remaining types are stale', async ({ page }) => {
  await mountRecorder(page, {
    images: [
      {
        id: 101,
        title: 'First word',
        image_url: '',
        category_name: 'Debug Category',
        category_slug: 'debug-category',
        word_id: 501,
        word_title: 'First word',
        word_translation: 'First translation',
        use_word_display: true,
        missing_types: ['isolation', 'introduction', 'question'],
        existing_types: [],
        prompt_types: ['isolation', 'introduction', 'question'],
        my_existing_types: [],
        is_text_only: true
      },
      {
        id: 102,
        title: 'Second word',
        image_url: '',
        category_name: 'Debug Category',
        category_slug: 'debug-category',
        word_id: 502,
        word_title: 'Second word',
        word_translation: 'Second translation',
        use_word_display: true,
        missing_types: ['isolation'],
        existing_types: [],
        prompt_types: ['isolation'],
        my_existing_types: [],
        is_text_only: true
      }
    ],
    uploadRemainingTypesSequence: [
      ['introduction', 'question'],
      ['question'],
      ['isolation', 'introduction', 'question']
    ]
  });

  await expect(page.locator('#ll-image-title')).toHaveText('First word');
  await expect(page.locator('#ll-recording-type')).toHaveValue('isolation');

  await recordAndSubmitCurrentType(page);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await expect(page.locator('#ll-recording-type')).toHaveValue('introduction');

  await recordAndSubmitCurrentType(page);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(2);
  await expect(page.locator('#ll-recording-type')).toHaveValue('question');

  await recordAndSubmitCurrentType(page);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(3);
  await expect(page.locator('#ll-image-title')).toHaveText('Second word');
  await expect(page.locator('#ll-recording-type')).toHaveValue('isolation');
});

test('prompt-card queue item uploads prompt audio by prompt card id and advances', async ({ page }) => {
  await mountRecorder(page, {
    images: [
      {
        id: 0,
        prompt_card_id: 901,
        title: 'Is this a horse?',
        image_url: '',
        category_name: 'Prompt Cards',
        category_slug: 'prompt-cards',
        word_id: 0,
        word_title: 'Is this a horse?',
        word_translation: '',
        use_word_display: true,
        missing_types: ['prompt'],
        existing_types: [],
        prompt_types: ['prompt'],
        my_existing_types: [],
        recording_prompts: {
          prompt: 'Read the question exactly.'
        },
        is_text_only: true,
        is_prompt_audio: true,
        hide_key: 'prompt_card:901'
      },
      {
        id: 102,
        title: 'Second word',
        image_url: '',
        category_name: 'Debug Category',
        category_slug: 'debug-category',
        word_id: 502,
        word_title: 'Second word',
        word_translation: 'Second translation',
        use_word_display: true,
        missing_types: ['isolation'],
        existing_types: [],
        prompt_types: ['isolation'],
        my_existing_types: [],
        is_text_only: true
      }
    ],
    recordingTypes: [
      { slug: 'prompt', name: 'Prompt audio', label: 'Prompt audio', icon: '' },
      { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }
    ],
    recordingTypeOrder: ['prompt', 'isolation'],
    uploadRemainingTypesSequence: [
      []
    ]
  });

  await expect(page.locator('#ll-image-title')).toHaveText('Is this a horse?');
  await expect(page.locator('#ll-recording-type')).toHaveValue('prompt');
  await expect(page.locator('.ll-recording-type-choice[data-recording-type-value="prompt"]')).toHaveClass(/is-needed/);
  await expect(page.locator('#ll-recording-prompt')).toBeVisible();
  await expect(page.locator('#ll-recording-prompt')).toHaveText('Say: Read the question exactly.');

  await recordAndSubmitCurrentType(page);

  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.lastUploadPayload)).toMatchObject({
    prompt_card_id: '901',
    word_id: '0',
    recording_type: 'prompt',
    word_title: 'Is this a horse?'
  });
  await expect(page.locator('#ll-image-title')).toHaveText('Second word');
  await expect(page.locator('#ll-recording-type')).toHaveValue('isolation');
});

test('hung upload and verification requests time out and restore recorder controls', async ({ page }) => {
  await mountRecorder(page, {
    images: [{
      id: 101,
      title: 'Existing word',
      image_url: '',
      category_name: 'Debug Category',
      category_slug: 'debug-category',
      word_id: 501,
      word_title: 'Existing word',
      word_translation: 'Existing translation',
      use_word_display: true,
      missing_types: ['isolation'],
      existing_types: [],
      prompt_types: ['isolation'],
      my_existing_types: [],
      is_text_only: true
    }],
    hangUpload: true,
    hangVerify: true,
    stopDelayMs: 100,
    uploadTimeoutMs: 50,
    verifyTimeoutMs: 50
  });

  await recordAndSubmitCurrentType(page);

  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.verifyRequests)).toBe(1);
  await expect(page.locator('#ll-upload-status')).toContainText('Request failed');
  await expect(page.locator('#ll-submit-btn')).toBeEnabled();
  await expect(page.locator('#ll-redo-btn')).toBeEnabled();
  await page.waitForTimeout(100);
  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
});

test('pending new-word transcription does not block save and advances to the intro type', async ({ page }) => {
  await mountRecorder(page);

  await page.locator('#ll-new-word-toggle').click();
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

test('slow idempotent creation has a bounded deadline and reuses its receipt on explicit recovery', async ({ page }) => {
  await mountRecorder(page, {
    prepareDelayMs: 150,
    requestTimeoutMs: 50
  });

  await page.locator('#ll-new-word-toggle').click();
  await page.locator('#ll-new-word-create-category').check({ force: true });
  await page.fill('#ll-new-word-category-name', 'Slow Category');
  await page.fill('#ll-new-word-text-target', 'Slow word');

  const recordButton = page.locator('#ll-new-word-record-btn');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/recording/);
  await recordButton.click();
  await expect(page.locator('#ll-new-word-start')).toBeVisible();
  await page.locator('#ll-new-word-start').click();

  await expect.poll(async () => page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llTestState.prepareRequests)).toBe(1);
  expect(await page.evaluate(() => window.__llTestState.uploadRequests)).toBe(0);
  await page.locator('[data-ll-new-word-retry-prepare]').click();

  await expect.poll(async () => page.evaluate(() => window.__llTestState.uploadRequests)).toBe(1);
  expect(await page.evaluate(() => ({
    prepareRequests: window.__llTestState.prepareRequests,
    updateTextRequests: window.__llTestState.updateTextRequests,
    uploadRequests: window.__llTestState.uploadRequests
  }))).toEqual({
    prepareRequests: 2,
    updateTextRequests: 1,
    uploadRequests: 1
  });
});
