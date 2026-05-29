const { test, expect } = require('@playwright/test');
const { mountNewWordRecorderFixture } = require('../helpers/audio-recorder-fixture');

test.describe.configure({ timeout: 240000 });

async function installFakeRecorderRuntime(page) {
  await page.addInitScript(() => {
    let resolveMicStart = null;
    const fakeTrack = { stop() {} };
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

async function openNewWordPanelIfNeeded(page) {
  const overlay = page.locator('#ll-new-word-overlay');
  if (await overlay.isVisible()) {
    return;
  }

  const newWordToggle = page.locator('#ll-new-word-toggle');
  if (
    (await newWordToggle.count()) > 0
    && await newWordToggle.isVisible()
    && await newWordToggle.isEnabled()
  ) {
    await newWordToggle.click();
  }
}

test('new-word recorder shows startup state immediately and defers preparation until save', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page);

  await openNewWordPanelIfNeeded(page);

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

test('new-word redo keeps entered text and translation intact', async ({ page }) => {
  await installFakeRecorderRuntime(page);
  await mountNewWordRecorderFixture(page, {
    transcribeData: {
      transcript: 'auto transcript',
      translation: 'auto translation'
    }
  });

  await openNewWordPanelIfNeeded(page);

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

  await openNewWordPanelIfNeeded(page);

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

  await openNewWordPanelIfNeeded(page);

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
