const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const pluginRoot = path.resolve(__dirname, '../../..');
const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
// Allows the same native regression to demonstrate the failure in a saved
// pre-fix source file without changing the working checkout.
const audioSource = fs.readFileSync(
  process.env.LL_FEEDBACK_AUDIO_SOURCE_FILE || path.join(pluginRoot, 'js/flashcard-widget/audio.js'),
  'utf8'
);
const media = {
  correct: fs.readFileSync(path.join(pluginRoot, 'media/right-answer.mp3')),
  wrong: fs.readFileSync(path.join(pluginRoot, 'media/wrong-answer.mp3'))
};

async function mountNativeFeedbackHarness(page, kind, failureMode) {
  const requests = { correct: 0, wrong: 0, failures: 0, failNext: false };
  await page.route('https://feedback-native.test/**', async route => {
    const url = new URL(route.request().url());
    const requestedKind = url.pathname.endsWith('/right-answer.mp3') ? 'correct'
      : url.pathname.endsWith('/wrong-answer.mp3') ? 'wrong' : null;
    if (!requestedKind) {
      await route.fulfill({
        contentType: 'text/html',
        body: '<button id="answer">Answer</button><div id="ll-tools-flashcard"></div>'
      });
      return;
    }
    requests[requestedKind] += 1;
    if (requests.failNext && requestedKind === kind) {
      requests.failNext = false;
      requests.failures += 1;
      if (failureMode === 'network') {
        await route.abort('failed');
        return;
      }
      await route.fulfill({
        contentType: 'audio/mpeg',
        headers: { 'cache-control': 'no-store' },
        body: Buffer.from('This response cannot be decoded as an MP3.')
      });
      return;
    }
    await route.fulfill({
      contentType: 'audio/mpeg',
      headers: { 'cache-control': 'no-store' },
      body: media[requestedKind]
    });
  });
  await page.goto('https://feedback-native.test/');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = { plugin_dir: 'https://feedback-native.test/' };
    window.LLFlashcards = { Dom: { requestSoundGate() { return false; } } };
    const NativeAudio = window.Audio;
    window.nativeFeedbackElements = [];
    // Observe the plugin's detached elements while preserving every native
    // media method, state property, decoder, event, and play promise.
    window.Audio = function (src) {
      const audio = new NativeAudio(src);
      window.nativeFeedbackElements.push(audio);
      return audio;
    };
    window.Audio.prototype = NativeAudio.prototype;
  });
  await page.addScriptTag({ content: audioSource });
  await page.evaluate(isCorrect => {
    const api = window.FlashcardAudio;
    api.initializeAudio();
    const audio = window.nativeFeedbackElements[isCorrect ? 0 : 1];
    const context = new AudioContext();
    const source = context.createMediaElementSource(audio);
    const analyser = context.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);
    analyser.connect(context.destination);
    window.nativeFeedbackAudio = audio;
    window.nativeFeedbackContext = context;
    document.getElementById('answer').addEventListener('click', () => {
      context.resume();
      window.nativeFeedbackRun = new Promise(resolve => {
        const samples = new Float32Array(analyser.fftSize);
        const result = { peak: 0, playing: 0, ended: false, error: null, advanced: 0 };
        let frame;
        let timer;
        let settled = false;
        const measure = () => {
          analyser.getFloatTimeDomainData(samples);
          for (const value of samples) result.peak = Math.max(result.peak, Math.abs(value));
          result.advanced = Math.max(result.advanced, audio.currentTime);
          frame = requestAnimationFrame(measure);
        };
        const finish = () => {
          if (settled) return;
          settled = true;
          cancelAnimationFrame(frame);
          clearTimeout(timer);
          audio.removeEventListener('playing', onPlaying);
          audio.removeEventListener('ended', onEnded);
          audio.removeEventListener('error', onError);
          result.error = audio.error ? audio.error.code : null;
          resolve(result);
        };
        const onPlaying = () => { result.playing += 1; };
        const onEnded = () => { result.ended = true; finish(); };
        const onError = () => { finish(); };
        audio.addEventListener('playing', onPlaying);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('error', onError);
        measure();
        timer = setTimeout(finish, 2500);
        api.playFeedback(isCorrect).catch(() => {});
      });
    });
  }, kind === 'correct');
  await page.waitForFunction(() => window.nativeFeedbackElements.every(audio => audio.readyState >= 2));
  return requests;
}

async function answerWithAudibleFeedback(page) {
  await page.locator('#answer').click();
  const playback = await page.evaluate(() => window.nativeFeedbackRun);
  expect(playback.error).toBeNull();
  expect(playback.playing).toBeGreaterThan(0);
  expect(playback.advanced).toBeGreaterThan(0.05);
  // Real decoded samples, not merely play()/playing instrumentation. Physical
  // speaker audibility on the user's own device remains a separate check.
  expect(playback.peak).toBeGreaterThan(0.001);
  expect(playback.ended).toBe(true);
  return playback;
}

for (const [kind, failureMode] of [['correct', 'decoder'], ['wrong', 'network']]) {
  test(`${kind} feedback recovers after a native ${failureMode} failure during repeated rounds`, async ({ page }, testInfo) => {
    const requests = await mountNativeFeedbackHarness(page, kind, failureMode);
    const beforeFailure = [];
    for (let round = 0; round < 3; round += 1) beforeFailure.push(await answerWithAudibleFeedback(page));

    requests.failNext = true;
    const failedState = await page.evaluate(async () => {
      const audio = window.nativeFeedbackAudio;
      await new Promise(resolve => {
        audio.addEventListener('error', resolve, { once: true });
        audio.load();
      });
      return { error: audio.error && audio.error.code, readyState: audio.readyState };
    });
    expect(requests.failures).toBe(1);
    expect(failedState.error).toBeGreaterThan(0);
    expect(failedState.readyState).toBe(0);

    // The route is healthy again, but the native element retains the failure.
    // Replaying without a resource reset cannot recover this same element.
    const stickyFailure = await page.evaluate(async () => {
      const audio = window.nativeFeedbackAudio;
      try {
        await audio.play();
        return { rejected: false, error: audio.error && audio.error.code };
      } catch (error) {
        return { rejected: true, error: audio.error && audio.error.code };
      }
    });
    expect(stickyFailure).toEqual({ rejected: true, error: failedState.error });
    const requestsBeforeRecovery = requests[kind];

    const afterRecovery = [];
    for (let round = 0; round < 3; round += 1) afterRecovery.push(await answerWithAudibleFeedback(page));
    expect(requests[kind]).toBeGreaterThan(requestsBeforeRecovery);
    expect(await page.evaluate(() => window.nativeFeedbackElements.length)).toBe(2);
    await testInfo.attach('native-feedback-recovery', {
      body: JSON.stringify({ kind, failureMode, failedState, stickyFailure, beforeFailure, afterRecovery, requests }, null, 2),
      contentType: 'application/json'
    });
    await page.evaluate(() => window.nativeFeedbackContext.close());
  });
}
