const { test, expect } = require('@playwright/test');

const ADMIN_USER = process.env.LL_E2E_ADMIN_USER || '';
const ADMIN_PASS = process.env.LL_E2E_ADMIN_PASS || '';

async function ensureLoggedIntoAdmin(page) {
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  const loginForm = page.locator('#loginform');
  if ((await loginForm.count()) > 0) {
    await expect(page.locator('#user_login')).toBeVisible({ timeout: 30000 });
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASS);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }),
      page.click('#wp-submit')
    ]);
  }

  await expect(page).toHaveURL(/\/wp-admin\/?/);
}

async function createRecorderPage(page, title) {
  await page.goto('/wp-admin/post-new.php?post_type=page', { waitUntil: 'domcontentloaded' });

  const result = await page.evaluate(async (pageTitle) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce) {
      return { error: 'missing-rest-nonce' };
    }

    const response = await fetch('/wp-json/wp/v2/pages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: JSON.stringify({
        title: pageTitle,
        status: 'publish',
        content: '[audio_recording_interface allow_new_words="1"]'
      })
    });

    return {
      ok: response.ok,
      status: response.status,
      data: await response.json()
    };
  }, title);

  if (!result || result.error) {
    throw new Error(`Failed to create recorder page: ${result && result.error ? result.error : 'unknown error'}`);
  }
  if (!result.ok || !result.data || !result.data.id || !result.data.link) {
    throw new Error(`Failed to create recorder page: HTTP ${result ? result.status : 'unknown'}`);
  }

  return {
    id: result.data.id,
    link: result.data.link
  };
}

async function deletePage(page, pageId) {
  await page.goto('/wp-admin/post-new.php?post_type=page', { waitUntil: 'domcontentloaded' });
  await page.evaluate(async (id) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce || !id) return;

    await fetch(`/wp-json/wp/v2/pages/${id}?force=true`, {
      method: 'DELETE',
      headers: {
        'X-WP-Nonce': nonce
      }
    });
  }, pageId);
}

test('new-word recorder shows startup state immediately and defers preparation until save', async ({ page }) => {
  test.skip(!ADMIN_USER || !ADMIN_PASS, 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for recorder E2E tests.');

  await ensureLoggedIntoAdmin(page);

  const title = `Recorder Startup ${Date.now()}`;
  const createdPage = await createRecorderPage(page, title);
  const ajaxActions = [];

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const postData = route.request().postData() || '';
    if (postData.includes('ll_prepare_new_word_recording')) {
      ajaxActions.push('prepare');
    }
    await route.continue();
  });

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

  try {
    await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });

    const newWordToggle = page.locator('#ll-new-word-toggle');
    if ((await newWordToggle.count()) > 0 && await newWordToggle.isVisible()) {
      await newWordToggle.click();
    }

    const recordButton = page.locator('#ll-new-word-record-btn');
    const recordIndicator = page.locator('#ll-new-word-recording-indicator');
    const levelMeter = page.locator('#ll-new-word-recording-meter');

    await expect(recordButton).toBeVisible({ timeout: 30000 });
    await recordButton.click();

    await expect(recordButton).toHaveClass(/starting/);
    await expect(recordIndicator).toHaveClass(/is-starting/);
    await expect.poll(() => ajaxActions.length).toBe(0);

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
    await expect.poll(() => ajaxActions.length).toBe(0);
  } finally {
    await deletePage(page, createdPage.id);
  }
});

test('new-word recorder shows a visible error when no microphone is available', async ({ page }) => {
  test.skip(!ADMIN_USER || !ADMIN_PASS, 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for recorder E2E tests.');

  await ensureLoggedIntoAdmin(page);

  const title = `Recorder No Mic ${Date.now()}`;
  const createdPage = await createRecorderPage(page, title);

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

  try {
    await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });

    const newWordToggle = page.locator('#ll-new-word-toggle');
    if ((await newWordToggle.count()) > 0 && await newWordToggle.isVisible()) {
      await newWordToggle.click();
    }

    const recordButton = page.locator('#ll-new-word-record-btn');
    const status = page.locator('#ll-new-word-status');

    await expect(recordButton).toBeVisible({ timeout: 30000 });
    await recordButton.click();

    await expect(status).toBeVisible({ timeout: 30000 });
    await expect(status).toContainText(/No microphone found|No input devices detected|Could not access microphone/i);
    await expect(recordButton).not.toHaveClass(/recording/);
    await expect(recordButton).not.toHaveClass(/starting/);
  } finally {
    await deletePage(page, createdPage.id);
  }
});

test('new-word recorder closes from the header button and backdrop on non-fullscreen layouts', async ({ page }) => {
  test.skip(!ADMIN_USER || !ADMIN_PASS, 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for recorder E2E tests.');

  await ensureLoggedIntoAdmin(page);
  await page.setViewportSize({ width: 1280, height: 900 });

  const title = `Recorder Dismiss ${Date.now()}`;
  const createdPage = await createRecorderPage(page, title);

  try {
    await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });

    const newWordToggle = page.locator('#ll-new-word-toggle');
    if ((await newWordToggle.count()) > 0 && await newWordToggle.isVisible()) {
      await newWordToggle.click();
    }

    const overlay = page.locator('#ll-new-word-overlay');
    const panel = page.locator('#ll-new-word-panel');
    const backdrop = page.locator('.ll-new-word-overlay-backdrop');
    const closeButton = page.locator('#ll-new-word-back');

    await expect(overlay).toBeVisible({ timeout: 30000 });
    await expect(panel).toBeVisible();
    await expect(closeButton).toBeVisible();
    await expect(page.getByRole('button', { name: /back to existing words/i })).toHaveCount(0);

    await backdrop.click({ position: { x: 20, y: 20 } });
    await expect(overlay).toBeHidden();

    if ((await newWordToggle.count()) > 0 && await newWordToggle.isVisible()) {
      await newWordToggle.click();
    }

    await expect(overlay).toBeVisible();
    await closeButton.click();
    await expect(overlay).toBeHidden();
  } finally {
    await deletePage(page, createdPage.id);
  }
});
