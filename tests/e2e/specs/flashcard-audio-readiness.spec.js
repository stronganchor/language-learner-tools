const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const audioSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/audio.js'),
  'utf8'
);

async function mountAudioHarness(page) {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      plugin_dir: '/',
      debug: false
    };
    window.LLFlashcards = {
      Dom: {}
    };
  });
  await page.addScriptTag({ content: audioSource });
}

test('playAudio waits for target audio to become buffered-ready before invoking play', async ({ page }) => {
  await mountAudioHarness(page);

  const result = await page.evaluate(async () => {
    const audioApi = window.FlashcardAudio;
    const sessionId = audioApi.getCurrentSessionId();

    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.__sessionId = sessionId;
        this.__options = { type: 'target', minReadyState: 3, readyTimeoutMs: 400 };
        this.readyState = 0;
        this.error = null;
        this.paused = true;
        this.ended = false;
        this.currentTime = 0;
        this.volume = 1;
        this.muted = false;
        this.playCalls = 0;
      }

      play() {
        this.playCalls += 1;
        this.paused = false;
        this.dispatchEvent(new Event('play'));
        this.dispatchEvent(new Event('playing'));
        return Promise.resolve();
      }

      pause() {
        this.paused = true;
        this.dispatchEvent(new Event('pause'));
      }

      load() {}
    }

    const audio = new FakeAudio();
    const playPromise = audioApi.playAudio(audio);

    await new Promise((resolve) => window.setTimeout(resolve, 120));
    const playCallsBeforeReady = audio.playCalls;

    audio.readyState = 3;
    audio.dispatchEvent(new Event('canplay'));

    await playPromise;

    return {
      playCallsBeforeReady,
      playCallsAfterReady: audio.playCalls,
      paused: audio.paused
    };
  });

  expect(result.playCallsBeforeReady).toBe(0);
  expect(result.playCallsAfterReady).toBe(1);
  expect(result.paused).toBe(false);
});

test('playAudio falls back to available current data after the readiness wait window', async ({ page }) => {
  await mountAudioHarness(page);

  const result = await page.evaluate(async () => {
    const audioApi = window.FlashcardAudio;
    const sessionId = audioApi.getCurrentSessionId();

    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.__sessionId = sessionId;
        this.__options = { type: 'target', minReadyState: 3, readyTimeoutMs: 140 };
        this.readyState = 2;
        this.error = null;
        this.paused = true;
        this.ended = false;
        this.currentTime = 0;
        this.volume = 1;
        this.muted = false;
        this.playCalls = 0;
      }

      play() {
        this.playCalls += 1;
        this.paused = false;
        this.dispatchEvent(new Event('play'));
        this.dispatchEvent(new Event('playing'));
        return Promise.resolve();
      }

      pause() {
        this.paused = true;
        this.dispatchEvent(new Event('pause'));
      }

      load() {}
    }

    const audio = new FakeAudio();
    const startedAt = performance.now();

    await audioApi.playAudio(audio);

    return {
      elapsedMs: performance.now() - startedAt,
      playCalls: audio.playCalls,
      paused: audio.paused
    };
  });

  expect(result.playCalls).toBe(1);
  expect(result.paused).toBe(false);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(110);
});
