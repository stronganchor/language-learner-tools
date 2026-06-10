const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function fixtureScriptPath() {
  return path.resolve(__dirname, '..', 'fixtures', 'seed-prompt-card-upload.php');
}

function seedFixture() {
  return runWpCliJson(['eval-file', fixtureScriptPath()], { timeoutMs: 120000 });
}

function inspectPromptCard(promptCardId) {
  return runWpCliJson(['eval-file', fixtureScriptPath(), 'inspect', String(promptCardId)], { timeoutMs: 120000 });
}

function buildWavBytes() {
  const sampleRate = 8000;
  const sampleCount = 1600;
  const bytesPerSample = 2;
  const dataSize = sampleCount * bytesPerSample;
  const buffer = new ArrayBuffer(44 + dataSize);
  const view = new DataView(buffer);

  const writeString = (offset, value) => {
    for (let index = 0; index < value.length; index += 1) {
      view.setUint8(offset + index, value.charCodeAt(index));
    }
  };

  writeString(0, 'RIFF');
  view.setUint32(4, 36 + dataSize, true);
  writeString(8, 'WAVE');
  writeString(12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, 1, true);
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * bytesPerSample, true);
  view.setUint16(32, bytesPerSample, true);
  view.setUint16(34, 16, true);
  writeString(36, 'data');
  view.setUint32(40, dataSize, true);

  for (let index = 0; index < sampleCount; index += 1) {
    const value = Math.round(Math.sin((index / sampleRate) * Math.PI * 2 * 440) * 12000);
    view.setInt16(44 + (index * bytesPerSample), value, true);
  }

  return Array.from(new Uint8Array(buffer));
}

async function installFakeRecorderRuntime(page) {
  await page.addInitScript((wavBytes) => {
    const makeWavBlob = () => new Blob([new Uint8Array(wavBytes)], { type: 'audio/wav' });
    const fakeStream = {
      getTracks() {
        return [{ stop() {} }];
      }
    };

    class FakeMediaRecorder {
      static isTypeSupported(type) {
        return String(type || '').toLowerCase() === 'audio/wav';
      }

      constructor(stream, options = {}) {
        this.stream = stream;
        this.mimeType = options.mimeType || 'audio/wav';
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
          this.ondataavailable({ data: makeWavBlob() });
        }
        if (typeof this.onstop === 'function') {
          setTimeout(() => this.onstop(), 0);
        }
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

    window.__llPromptCardUploadFixtureWavBytes = wavBytes;
  }, buildWavBytes());
}

async function loginAs(page, username, password, redirectPath) {
  const redirectTo = redirectPath || '/';
  await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(redirectTo)}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.toString().includes('wp-login.php'), { timeout: 60000 }),
    page.locator('#wp-submit').click()
  ]);
}

async function postBlockedPromptUpload(page, fixture) {
  return page.evaluate(async (args) => {
    const data = window.ll_recorder_data || {};
    const form = new FormData();
    form.append('action', 'll_upload_recording');
    form.append('nonce', data.nonce || '');
    form.append('prompt_card_id', String(args.otherPromptCardId));
    form.append('word_id', '0');
    form.append('word_title', 'Blocked prompt upload');
    form.append('recording_type', 'prompt');
    form.append('wordset_ids', JSON.stringify([args.wordsetId]));
    form.append('active_category', args.categorySlug);
    form.append(
      'audio',
      new Blob([new Uint8Array(window.__llPromptCardUploadFixtureWavBytes || [])], { type: 'audio/wav' }),
      'blocked-prompt.wav'
    );

    const response = await fetch(data.ajax_url, {
      method: 'POST',
      body: form,
      credentials: 'same-origin'
    });
    const payload = await response.json();
    return {
      status: response.status,
      payload
    };
  }, fixture);
}

async function recordAndSubmitPrompt(page) {
  const recordButton = page.locator('#ll-record-btn');
  await recordButton.click();
  await expect(recordButton).toHaveClass(/recording/);
  await recordButton.click();
  await expect(page.locator('#ll-submit-btn')).toBeVisible({ timeout: 30000 });

  const uploadResponsePromise = page.waitForResponse((response) => (
    response.url().includes('/wp-admin/admin-ajax.php')
    && response.request().method() === 'POST'
  ), { timeout: 90000 });

  await page.locator('#ll-submit-btn').click();
  return uploadResponsePromise;
}

test('limited recorder uploads prompt-card prompt audio through the real AJAX handler', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress prompt-card upload fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  await installFakeRecorderRuntime(page);
  await loginAs(page, fixture.recorderUsername, fixture.recorderPassword, fixture.recorderPagePath);

  await expect.poll(() => page.evaluate(() => Array.isArray(window.ll_recorder_data && window.ll_recorder_data.images)), { timeout: 60000 }).toBe(true);
  const recorderData = await page.evaluate(() => window.ll_recorder_data);
  expect(Number(recorderData.current_user_id)).toBe(fixture.recorderUserId);
  expect(recorderData.wordset).toBe(fixture.wordsetSlug);
  expect((recorderData.wordset_ids || []).map(Number)).toEqual([fixture.wordsetId]);

  const promptItem = recorderData.images.find((item) => Number(item.prompt_card_id || 0) === fixture.promptCardId);
  expect(promptItem).toMatchObject({
    id: 0,
    prompt_card_id: fixture.promptCardId,
    title: fixture.promptText,
    category_slug: fixture.categorySlug,
    word_id: 0,
    word_title: fixture.promptText,
    missing_types: ['prompt'],
    prompt_types: ['prompt'],
    is_text_only: true,
    is_prompt_audio: true,
    hide_key: `prompt_card:${fixture.promptCardId}`
  });

  await expect(page.locator('#ll-image-title')).toHaveText(fixture.promptText);
  await expect(page.locator('#ll-recording-type')).toHaveValue('prompt');
  await expect(page.locator('.ll-recording-type-choice[data-recording-type-value="prompt"]')).toHaveClass(/is-needed/);

  const blockedResult = await postBlockedPromptUpload(page, fixture);
  expect(blockedResult.status).toBe(403);
  expect(blockedResult.payload.success).toBe(false);
  expect(String(blockedResult.payload.data || '')).toContain('access');
  const blockedInspection = inspectPromptCard(fixture.otherPromptCardId);
  expect(blockedInspection.attachmentId).toBe(0);
  expect(blockedInspection.promptAudioUrl).toBe('');
  expect(blockedInspection.needsPromptAudio).toBe(true);

  const uploadResponse = await recordAndSubmitPrompt(page);
  expect(uploadResponse.ok()).toBe(true);
  const uploadPayload = await uploadResponse.json();
  expect(uploadPayload.success).toBe(true);
  expect(uploadPayload.data).toMatchObject({
    prompt_card_id: fixture.promptCardId,
    recording_type: 'prompt',
    remaining_types: []
  });
  expect(Number(uploadPayload.data.attachment_id || 0)).toBeGreaterThan(0);

  await expect(page.locator('.ll-recording-complete')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-upload-status')).toContainText(/Success|Saved|completed|complete/i);

  const inspection = inspectPromptCard(fixture.promptCardId);
  expect(inspection.attachmentId).toBe(Number(uploadPayload.data.attachment_id));
  expect(inspection.attachmentMime).toBe('audio/wav');
  expect(inspection.attachmentParent).toBe(fixture.promptCardId);
  expect(inspection.attachmentAuthor).toBe(fixture.recorderUserId);
  expect(inspection.recordedBy).toBe(fixture.recorderUserId);
  expect(inspection.recordedAt).not.toBe('');
  expect(inspection.uploadSha1).toMatch(/^[a-f0-9]{40}$/);
  expect(inspection.promptAudioUrl).toMatch(/^https?:\/\//);
  expect(inspection.needsPromptAudio).toBe(false);
});
