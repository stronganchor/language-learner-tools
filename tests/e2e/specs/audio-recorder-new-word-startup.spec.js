const { test, expect } = require('@playwright/test');
const { mountNewWordRecorderFixture } = require('../helpers/audio-recorder-fixture');

test.describe.configure({ timeout: 240000 });

async function installFakeRecorderRuntime(page) {
  await page.addInitScript(() => {
    let resolveMicStart = null;
    window.__llTrackStopCount = 0;
    window.__llGetUserMediaCalls = 0;
    const fakeTrack = {
      stop() {
        window.__llTrackStopCount += 1;
      }
    };
    const fakeStream = {
      getTracks() {
        return [fakeTrack];
      }
    };

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
            data: new Blob(['RIFF'], { type: this.mimeType })
          });
        }
        if (typeof this.onstop === 'function') {
          this.onstop();
        }
      }
    }

    const mediaDevices = {
      getUserMedia() {
        window.__llGetUserMediaCalls += 1;
        return new Promise((resolve) => {
          resolveMicStart = resolve;
        });
      },
      enumerateDevices: async () => [{ kind: 'audioinput', deviceId: 'fake-mic', label: 'Fake Mic' }]
    };

    class FakeAnalyser {
      constructor() {
        this.fftSize = 256;
        this.frequencyBinCount = 128;
        this.smoothingTimeConstant = 0.65;
        this.__tick = 0;
      }

      connect() {}

      getByteFrequencyData(array) {
        this.__tick += 1;
        const peak = this.__tick % 2 === 0 ? 128 : 96;
        for (let i = 0; i < array.length; i++) {
          array[i] = i % 3 === 0 ? peak : Math.max(32, peak - 36);
        }
      }

      getByteTimeDomainData(array) {
        const amplitude = this.__tick % 2 === 0 ? 44 : 28;
        for (let i = 0; i < array.length; i++) {
          array[i] = 128 + (i % 2 === 0 ? amplitude : -amplitude);
        }
      }
    }

    class FakeMediaStreamSource {
      connect() {}
      disconnect() {}
    }

    class FakeAudioContext {
      constructor() {
        this.state = 'running';
        this.destination = {};
      }

      createAnalyser() {
        return new FakeAnalyser();
      }

      createMediaStreamSource() {
        return new FakeMediaStreamSource();
      }

      resume() {
        this.state = 'running';
        return Promise.resolve();
      }

      close() {
        this.state = 'closed';
        return Promise.resolve();
      }
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
        value: mediaDevices,
        configurable: true
      });
    } catch (_) {
      navigator.mediaDevices = mediaDevices;
    }

    try {
      Object.defineProperty(window, 'AudioContext', {
        value: FakeAudioContext,
        configurable: true
      });
    } catch (_) {
      window.AudioContext = FakeAudioContext;
    }

    try {
      Object.defineProperty(window, 'webkitAudioContext', {
        value: FakeAudioContext,
        configurable: true
      });
    } catch (_) {
      window.webkitAudioContext = FakeAudioContext;
    }

    window.__llResolveRecorderMic = () => {
      if (typeof resolveMicStart === 'function') {
        resolveMicStart(fakeStream);
      }
    };
  });
}

async function openNewWordPanel(page) {
  const overlay = page.locator('#ll-new-word-overlay');
  const newWordToggle = page.locator('#ll-new-word-toggle');
  await expect(overlay).toBeHidden();
  await expect(newWordToggle).toBeVisible();
  await expect(newWordToggle).toBeEnabled();
  await newWordToggle.click();
  await expect(overlay).toBeVisible();
}

test('recorder overview keeps the new-word panel closed until its button is clicked', async ({ page }) => {
  await mountNewWordRecorderFixture(page);

  await expect(page.locator('.ll-recording-progress')).toHaveCount(0);
  await expect(page.locator('.ll-current-num')).toHaveCount(0);
  await expect(page.locator('.ll-total-num')).toHaveCount(0);
  await openNewWordPanel(page);
});

test('new-word recorder shows startup state immediately and defers preparation until save', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page);

  await openNewWordPanel(page);

  const recordButton = page.locator('#ll-new-word-record-btn');
  const recordIndicator = page.locator('#ll-new-word-recording-indicator');
  const levelMeter = page.locator('#ll-new-word-recording-meter');

  await expect(recordButton).toBeVisible({ timeout: 30000 });
  await recordButton.click();

  await expect(recordButton).toHaveClass(/starting/);
  await expect(recordIndicator).toHaveClass(/is-starting/);
  await expect.poll(() => page.evaluate(() => window.__llStartupTestState.prepareRequests)).toBe(0);

  await page.evaluate(() => {
    if (typeof window.__llResolveRecorderMic === 'function') {
      window.__llResolveRecorderMic();
    }
  });

  await expect(recordButton).toHaveClass(/recording/);
  await expect(recordButton).not.toHaveClass(/starting/);
  await expect(recordIndicator).not.toHaveClass(/is-starting/);
  await expect(recordIndicator).toHaveClass(/is-live/);
  await expect(levelMeter).toBeVisible();
  await expect.poll(async () => {
    return await page.evaluate(() => {
      return Array.from(document.querySelectorAll('#ll-new-word-recording-meter .ll-recording-meter-bar'))
        .some((bar) => parseFloat(bar.style.getPropertyValue('--level') || '0') > 0.08);
    });
  }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__llStartupTestState.prepareRequests)).toBe(0);
});

test('new-word recording waits for the current category type and ignores an aborted stale lookup', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page, {
    recordingTypeOrder: ['introduction', 'isolation']
  });
  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.__llCategoryTypeRequests = [];
    window.fetch = (url, options = {}) => {
      const body = options.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';
      if (action !== 'll_get_recording_types_for_category') {
        return originalFetch(url, options);
      }

      const request = {
        category: String(body.get('category') || ''),
        aborted: false,
        resolve: null
      };
      const promise = new Promise((resolve) => {
        request.resolve = (types) => resolve({
          ok: true,
          status: 200,
          statusText: 'OK',
          headers: {
            get(name) {
              return String(name || '').toLowerCase() === 'content-type' ? 'application/json' : null;
            }
          },
          async json() {
            return { success: true, data: { recording_types: types } };
          }
        });
      });
      if (options.signal) {
        request.aborted = options.signal.aborted;
        options.signal.addEventListener('abort', () => {
          request.aborted = true;
        }, { once: true });
      }
      window.__llCategoryTypeRequests.push(request);
      return promise;
    };
  });

  await openNewWordPanel(page);
  await expect.poll(() => page.evaluate(() => window.__llCategoryTypeRequests.length)).toBe(1);
  await page.evaluate(() => {
    const select = document.getElementById('ll-new-word-category');
    const option = document.createElement('option');
    option.value = 'second-category';
    option.textContent = 'Second category';
    select.appendChild(option);
    select.value = option.value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect.poll(() => page.evaluate(() => window.__llCategoryTypeRequests.length)).toBe(2);
  expect(await page.evaluate(() => ({
    firstCategory: window.__llCategoryTypeRequests[0].category,
    firstAborted: window.__llCategoryTypeRequests[0].aborted,
    secondCategory: window.__llCategoryTypeRequests[1].category
  }))).toEqual({
    firstCategory: 'uncategorized',
    firstAborted: true,
    secondCategory: 'second-category'
  });

  const recordButton = page.locator('#ll-new-word-record-btn');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/starting/);
  expect(await page.evaluate(() => ({
    typeRequests: window.__llCategoryTypeRequests.length,
    micRequests: window.__llGetUserMediaCalls
  }))).toEqual({ typeRequests: 2, micRequests: 0 });

  await page.evaluate(() => {
    window.__llCategoryTypeRequests[0].resolve([
      { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }
    ]);
  });
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__llGetUserMediaCalls)).toBe(0);

  await page.evaluate(() => {
    window.__llCategoryTypeRequests[1].resolve([
      { slug: 'introduction', name: 'Introduction', label: 'Introduction', icon: '' }
    ]);
  });
  await expect.poll(() => page.evaluate(() => window.__llGetUserMediaCalls)).toBe(1);
  await expect(page.locator('#ll-new-word-recording-type-label')).toHaveText('Introduction');
  await expect(recordButton).toHaveClass(/starting/);

  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
});

test('new-word category type timeout aborts the lookup and restores a usable recorder', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page);
  await page.clock.install();
  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.__llNeverResolvingCategoryTypeRequest = {
      aborted: false,
      requests: 0
    };
    window.fetch = (url, options = {}) => {
      const body = options.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';
      if (action !== 'll_get_recording_types_for_category') {
        return originalFetch(url, options);
      }

      window.__llNeverResolvingCategoryTypeRequest.requests += 1;
      if (options.signal) {
        window.__llNeverResolvingCategoryTypeRequest.aborted = options.signal.aborted;
        options.signal.addEventListener('abort', () => {
          window.__llNeverResolvingCategoryTypeRequest.aborted = true;
        }, { once: true });
      }
      return new Promise(() => {});
    };
  });

  await openNewWordPanel(page);
  await expect.poll(() => page.evaluate(() => window.__llNeverResolvingCategoryTypeRequest.requests)).toBe(1);

  const recordButton = page.locator('#ll-new-word-record-btn');
  const categorySelect = page.locator('#ll-new-word-category');
  const status = page.locator('#ll-new-word-status');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/starting/);
  await expect(categorySelect).toBeDisabled();
  expect(await page.evaluate(() => window.__llGetUserMediaCalls)).toBe(0);

  await page.clock.runFor(20001);

  await expect.poll(() => page.evaluate(() => window.__llNeverResolvingCategoryTypeRequest.aborted)).toBe(true);
  await expect(recordButton).not.toHaveClass(/starting/);
  await expect(recordButton).toBeEnabled();
  await expect(categorySelect).toBeEnabled();
  await expect(status).toBeVisible();
  await expect(status).toHaveText('Request failed');
  expect(await page.evaluate(() => window.__llGetUserMediaCalls)).toBe(0);

  await page.evaluate(() => {
    const originalFetch = window.fetch;
    window.fetch = (url, options = {}) => {
      const body = options.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';
      if (action !== 'll_get_recording_types_for_category') {
        return originalFetch(url, options);
      }
      return Promise.resolve({
        ok: true,
        status: 200,
        statusText: 'OK',
        headers: {
          get(name) {
            return String(name || '').toLowerCase() === 'content-type' ? 'application/json' : null;
          }
        },
        async json() {
          return {
            success: true,
            data: {
              recording_types: [
                { slug: 'isolation', name: 'Isolation', label: 'Isolation', icon: '' }
              ]
            }
          };
        }
      });
    };
  });

  await recordButton.click();
  await expect.poll(() => page.evaluate(() => window.__llGetUserMediaCalls)).toBe(1);
  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
});

test('new-word recording locks its category and type context until capture is discarded', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page, {
    recordingTypeOrder: ['introduction', 'isolation']
  });

  await openNewWordPanel(page);
  const recordButton = page.locator('#ll-new-word-record-btn');
  const backButton = page.locator('#ll-new-word-back');
  const categorySelect = page.locator('#ll-new-word-category');
  const createCategory = page.locator('#ll-new-word-create-category');
  const typeCheckbox = page.locator('.ll-new-word-types input[value="introduction"]');

  const initialCategory = await categorySelect.inputValue();
  const initialCreateState = await createCategory.isChecked();
  const initialTypeState = await typeCheckbox.isChecked();

  await recordButton.click();
  await expect(recordButton).toHaveClass(/starting/);
  await expect(backButton).toBeDisabled();
  await expect(categorySelect).toBeDisabled();
  await expect(createCategory).toBeDisabled();
  await expect(typeCheckbox).toBeDisabled();

  await page.evaluate(() => {
    const category = document.getElementById('ll-new-word-category');
    const create = document.getElementById('ll-new-word-create-category');
    const type = document.querySelector('.ll-new-word-types input[value="introduction"]');
    category.disabled = false;
    category.value = '__different_category__';
    category.dispatchEvent(new Event('change', { bubbles: true }));
    create.disabled = false;
    create.checked = !create.checked;
    create.dispatchEvent(new Event('change', { bubbles: true }));
    type.disabled = false;
    type.checked = !type.checked;
    type.dispatchEvent(new Event('change', { bubbles: true }));
  });

  await expect(categorySelect).toHaveValue(initialCategory);
  await expect(createCategory).toHaveJSProperty('checked', initialCreateState);
  await expect(typeCheckbox).toHaveJSProperty('checked', initialTypeState);
  await expect(categorySelect).toBeDisabled();
  await expect(createCategory).toBeDisabled();
  await expect(typeCheckbox).toBeDisabled();

  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
  await expect(backButton).toBeDisabled();
  await recordButton.click();

  await expect(page.locator('#ll-new-word-start')).toBeEnabled();
  await expect(backButton).toBeDisabled();
  await page.locator('#ll-new-word-redo-btn').click();
  await expect(backButton).toBeEnabled();
  await expect(categorySelect).toBeEnabled();
  await expect(typeCheckbox).toBeEnabled();
  await expect.poll(() => page.evaluate(() => window.__llTrackStopCount)).toBeGreaterThan(0);
});

test('redo revokes the retired recorder playback blob URL', async ({ page }) => {
  await page.addInitScript(() => {
    const createObjectUrl = URL.createObjectURL.bind(URL);
    const revokeObjectUrl = URL.revokeObjectURL.bind(URL);
    window.__llCreatedBlobUrls = [];
    window.__llRevokedBlobUrls = [];
    URL.createObjectURL = (blob) => {
      const url = createObjectUrl(blob);
      window.__llCreatedBlobUrls.push(url);
      return url;
    };
    URL.revokeObjectURL = (url) => {
      window.__llRevokedBlobUrls.push(String(url || ''));
      return revokeObjectUrl(url);
    };
  });
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page);
  await openNewWordPanel(page);

  const recordButton = page.locator('#ll-new-word-record-btn');
  await recordButton.click();
  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
  await recordButton.click();
  await expect.poll(() => page.evaluate(() => window.__llCreatedBlobUrls.length)).toBe(1);

  const activeUrl = await page.evaluate(() => window.__llCreatedBlobUrls[0]);
  expect(await page.evaluate(() => window.__llRevokedBlobUrls.length)).toBe(0);
  await page.locator('#ll-new-word-redo-btn').click();

  await expect.poll(() => page.evaluate(() => window.__llRevokedBlobUrls)).toEqual([activeUrl]);
  await expect(page.locator('#ll-new-word-playback-audio')).not.toHaveAttribute('src', /blob:/);
});

test('persisted pagehide preserves recorder playback until a real unload', async ({ page }) => {
  await page.addInitScript(() => {
    const createObjectUrl = URL.createObjectURL.bind(URL);
    const revokeObjectUrl = URL.revokeObjectURL.bind(URL);
    window.__llCreatedBlobUrls = [];
    window.__llRevokedBlobUrls = [];
    URL.createObjectURL = (blob) => {
      const url = createObjectUrl(blob);
      window.__llCreatedBlobUrls.push(url);
      return url;
    };
    URL.revokeObjectURL = (url) => {
      window.__llRevokedBlobUrls.push(String(url || ''));
      return revokeObjectUrl(url);
    };
  });
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page);
  await openNewWordPanel(page);

  const recordButton = page.locator('#ll-new-word-record-btn');
  const playbackAudio = page.locator('#ll-new-word-playback-audio');
  await recordButton.click();
  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
  await recordButton.click();
  await expect.poll(() => page.evaluate(() => window.__llCreatedBlobUrls.length)).toBe(1);

  const activeUrl = await page.evaluate(() => window.__llCreatedBlobUrls[0]);
  await expect(playbackAudio).toHaveAttribute('src', activeUrl);
  await page.evaluate(() => {
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: true });
    window.dispatchEvent(event);
  });

  expect(await page.evaluate(() => window.__llRevokedBlobUrls.slice())).toEqual([]);
  await expect(playbackAudio).toHaveAttribute('src', activeUrl);

  await page.evaluate(() => {
    const event = new Event('pagehide');
    Object.defineProperty(event, 'persisted', { value: false });
    window.dispatchEvent(event);
  });
  await expect.poll(() => page.evaluate(() => window.__llRevokedBlobUrls)).toEqual([activeUrl]);
  await expect(playbackAudio).not.toHaveAttribute('src', /blob:/);
});

test('overview new-word recording submits the selected type and keeps later types in the panel', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page, {
    recordingTypeOrder: ['introduction', 'isolation'],
    remainingTypesAfterUpload: ['isolation']
  });

  await openNewWordPanel(page);

  await page.locator('#ll-new-word-create-category').check();
  await page.locator('#ll-new-word-category-name').fill('Two-type category');
  await page.locator('.ll-new-word-types input[value="introduction"]').check();

  const recordButton = page.locator('#ll-new-word-record-btn');
  await recordButton.click();
  await page.evaluate(() => window.__llResolveRecorderMic?.());
  await expect(recordButton).toHaveClass(/recording/);
  await recordButton.click();

  const saveButton = page.locator('#ll-new-word-start');
  await expect(saveButton).toBeVisible();
  await expect(saveButton).toBeEnabled();
  await saveButton.click();

  await expect.poll(() => page.evaluate(() => window.__llStartupTestState.uploadRecordingTypes)).toEqual([
    'introduction'
  ]);
  await expect(page.locator('#ll-new-word-overlay')).toBeVisible();
  await expect(page.locator('#ll-new-word-panel')).toBeVisible();
  await expect(page.locator('.ll-recording-main')).toHaveCount(0);
  await expect(page.locator('#ll-recording-type')).toBeHidden();
  await expect(page.locator('#ll-recording-type')).toHaveValue('isolation');
  await expect(page.locator('#ll-new-word-recording-type-label')).toHaveText('Isolation');
  await expect(recordButton).toBeVisible();
  await expect(recordButton).toBeEnabled();
});

test('new-word redo keeps entered text and translation intact', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page, {
    transcribeData: {
      transcript: 'auto transcript',
      translation: 'auto translation'
    }
  });

  await openNewWordPanel(page);

  const targetInput = page.locator('#ll-new-word-text-target');
  const translationInput = page.locator('#ll-new-word-text-translation');
  const recordButton = page.locator('#ll-new-word-record-btn');

  await expect(targetInput).toBeVisible({ timeout: 30000 });
  await targetInput.fill('entered word');
  await translationInput.fill('entered translation');

  await recordButton.click();
  await page.evaluate(() => {
    if (typeof window.__llResolveRecorderMic === 'function') {
      window.__llResolveRecorderMic();
    }
  });
  await expect(recordButton).toHaveClass(/recording/);

  await recordButton.click();

  const redoSelectorHandle = await page.waitForFunction(() => {
    const isVisible = (node) => {
      return !!node
        && node instanceof HTMLElement
        && node.offsetParent !== null
        && window.getComputedStyle(node).visibility !== 'hidden';
    };

    const reviewRedo = document.querySelector('#ll-review-redo');
    if (isVisible(reviewRedo)) {
      return '#ll-review-redo';
    }

    const inlineRedo = document.querySelector('#ll-new-word-redo-btn');
    if (isVisible(inlineRedo)) {
      return '#ll-new-word-redo-btn';
    }

    return false;
  });
  const redoSelector = await redoSelectorHandle.jsonValue();
  await page.locator(redoSelector).click();

  await expect(targetInput).toHaveValue('entered word');
  await expect(translationInput).toHaveValue('entered translation');
  await expect(recordButton).toBeVisible();
});

test('new-word recorder shows a visible error when no microphone is available', async ({ page }) => {
  await page.addInitScript(() => {
    const mediaDevices = {
      getUserMedia() {
        const error = new Error('Requested device not found');
        error.name = 'NotFoundError';
        return Promise.reject(error);
      },
      enumerateDevices: async () => []
    };

    try {
      Object.defineProperty(navigator, 'mediaDevices', {
        value: mediaDevices,
        configurable: true
      });
    } catch (_) {
      navigator.mediaDevices = mediaDevices;
    }
  });
  await mountNewWordRecorderFixture(page);

  await openNewWordPanel(page);

  const recordButton = page.locator('#ll-new-word-record-btn');
  const status = page.locator('#ll-new-word-status');

  await expect(recordButton).toBeVisible({ timeout: 30000 });
  await recordButton.click();

  await expect(status).toBeVisible({ timeout: 30000 });
  await expect(status).toContainText(/No microphone found|No input devices detected|Could not access microphone/i);
  await expect(recordButton).not.toHaveClass(/recording/);
  await expect(recordButton).not.toHaveClass(/starting/);
});

test('new-word recorder closes from the header button and backdrop on non-fullscreen layouts', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await mountNewWordRecorderFixture(page);

  await openNewWordPanel(page);

  const overlay = page.locator('#ll-new-word-overlay');
  const panel = page.locator('#ll-new-word-panel');
  const backdrop = page.locator('.ll-new-word-overlay-backdrop');
  const closeButton = page.locator('#ll-new-word-back');
  const newWordToggle = page.locator('#ll-new-word-toggle');

  await expect(overlay).toBeVisible({ timeout: 30000 });
  await expect(panel).toBeVisible();
  await expect(closeButton).toBeVisible();
  await expect(page.getByRole('button', { name: /back to existing words/i })).toHaveCount(0);

  await backdrop.click({ position: { x: 20, y: 20 } });
  await expect(overlay).toBeHidden();

  if (
    (await newWordToggle.count()) > 0
    && await newWordToggle.isVisible()
    && await newWordToggle.isEnabled()
  ) {
    await newWordToggle.click();
  }

  await expect(overlay).toBeVisible();
  await closeButton.click();
  await expect(overlay).toBeHidden();
});
