const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const audioSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/audio.js'),
  'utf8'
);
const loaderSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/loader.js'),
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

async function mountLoaderHarness(page) {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      debug: false,
      categories: []
    };
    window.LLFlashcards = {
      Util: {}
    };
  });
  await page.addScriptTag({ content: loaderSource });
}

test('audio preload keeps waiting when buffering stalls transiently', async ({ page }) => {
  await mountLoaderHarness(page);

  const result = await page.evaluate(async () => {
    const originalCreateElement = document.createElement.bind(document);
    let audioElementCount = 0;

    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.readyState = 0;
        this.error = null;
        this.parentNode = null;
        this.preload = '';
        this.crossOrigin = '';
        this.src = '';
      }

      load() {
        window.setTimeout(() => {
          this.dispatchEvent(new Event('stalled'));
        }, 20);
        window.setTimeout(() => {
          this.readyState = 3;
          this.dispatchEvent(new Event('canplay'));
        }, 80);
      }

      pause() {}

      removeAttribute(name) {
        if (name === 'src') this.src = '';
      }
    }

    document.createElement = function (tagName, options) {
      if (String(tagName || '').toLowerCase() === 'audio') {
        audioElementCount += 1;
        return new FakeAudio();
      }
      return originalCreateElement(tagName, options);
    };

    try {
      const startedAt = performance.now();
      const preload = await window.FlashcardLoader.loadAudio(
        'https://media.test/transient-stall.m4a',
        { maxRetries: 0, timeoutMs: 500 }
      );

      return {
        ready: preload.ready,
        attempts: preload.attempts,
        elapsedMs: performance.now() - startedAt,
        audioElementCount
      };
    } finally {
      document.createElement = originalCreateElement;
    }
  });

  expect(result.ready).toBe(true);
  expect(result.attempts).toBe(1);
  expect(result.audioElementCount).toBe(1);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(60);
});

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

test('target audio uses live prompt helpers loaded after the audio module', async ({ page }) => {
  await mountAudioHarness(page);

  const result = await page.evaluate(async () => {
    window.LLFlashcards.Util = {
      getPromptAudioUrl: function (word) {
        return String((word && word.prompt_audio) || '').trim();
      }
    };

    const audio = await window.FlashcardAudio.setTargetWordAudio({
      id: 1001,
      audio: 'https://cdn.test/answer-isolation.mp3',
      prompt_audio: 'https://cdn.test/question-prompt.mp3'
    }, {
      autoplay: false
    });

    return {
      src: audio ? audio.src : ''
    };
  });

  expect(result.src).toBe('https://cdn.test/question-prompt.mp3');
});

test('accepted answers keep playing feedback before the prompt playback flag settles', async ({ page }) => {
  await mountAudioHarness(page);

  const result = await page.evaluate(async () => {
    const feedback = [];
    class FeedbackAudio extends EventTarget {
      constructor(src) {
        super();
        this.src = src;
        this.readyState = 4;
        this.paused = true;
        this.currentTime = 0;
        this.volume = 1;
        this.muted = false;
        this.playCalls = 0;
        feedback.push(this);
      }
      load() {}
      pause() { this.paused = true; }
      play() {
        this.paused = false;
        this.playCalls += 1;
        return Promise.resolve();
      }
    }
    window.Audio = FeedbackAudio;
    const api = window.FlashcardAudio;
    api.initializeAudio();
    await api.startNewSession();

    // A slow first answer works. Subsequent accepted answers can arrive before
    // a prompt timeupdate marks the first 400 ms as played.
    await api.setTargetWordAudio({ audio: 'data:audio/mpeg;base64,' }, { autoplay: false });
    api.setTargetAudioHasPlayed(true);
    await api.playFeedback(true);
    const firstCorrect = feedback[0].playCalls;
    for (let round = 0; round < 25; round += 1) {
      await api.setTargetWordAudio({ audio: 'data:audio/mpeg;base64,' }, { autoplay: false });
      await api.playFeedback(true);
    }
    const repeatedCorrect = feedback[0].playCalls;
    await api.playFeedback(false);
    const wrong = feedback[1].playCalls;

    await api.suspendPlayback();
    await api.playFeedback(true);
    const suspendedCorrect = feedback[0].playCalls;
    await api.flushAllAudioSessions();
    await api.startNewSession();
    await api.setTargetWordAudio({ audio: 'data:audio/mpeg;base64,' }, { autoplay: false });
    await api.playFeedback(true);
    return { firstCorrect, repeatedCorrect, wrong, suspendedCorrect, reopenedCorrect: feedback[0].playCalls };
  });

  expect(result).toEqual({
    firstCorrect: 1,
    repeatedCorrect: 26,
    wrong: 1,
    suspendedCorrect: 26,
    reopenedCorrect: 27
  });
});

test('native correct-answer audio produces sound through 25 quick rounds and a reopen', async ({ page }) => {
  const sound = fs.readFileSync(path.resolve(__dirname, '../../../media/right-answer.mp3'));
  await page.route('https://feedback.test/**', (route) => route.fulfill(
    new URL(route.request().url()).pathname.endsWith('.mp3')
      ? { contentType: 'audio/mpeg', body: sound }
      : { contentType: 'text/html', body: '<button>Start</button><div id="ll-tools-flashcard"></div>' }
  ));
  await page.goto('https://feedback.test/');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = { plugin_dir: 'https://feedback.test/' };
    window.LLFlashcards = { Dom: {} };
    window.__feedbackAudio = [];
    const NativeAudio = window.Audio;
    window.Audio = function (src) {
      const audio = new NativeAudio(src);
      window.__feedbackAudio.push(audio);
      return audio;
    };
  });
  await page.addScriptTag({ content: audioSource });
  await page.getByRole('button', { name: 'Start' }).click();

  const result = await page.evaluate(async () => {
    const api = window.FlashcardAudio;
    api.initializeAudio();
    const context = new AudioContext();
    await context.resume();
    const analyser = context.createAnalyser();
    const source = context.createMediaElementSource(window.__feedbackAudio[0]);
    source.connect(analyser);
    analyser.connect(context.destination);
    const samples = new Float32Array(analyser.fftSize);
    const peaks = [];
    for (let round = 0; round < 26; round += 1) {
      if (round === 25) {
        await api.suspendPlayback();
        await api.flushAllAudioSessions();
        await api.startNewSession();
      }
      await api.setTargetWordAudio({ audio: 'https://feedback.test/prompt.mp3' }, { autoplay: false });
      await api.playFeedback(true);
      let peak = 0;
      const deadline = performance.now() + 1500;
      while (peak < 0.01 && performance.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 20));
        analyser.getFloatTimeDomainData(samples);
        peak = Math.max(...samples.map(Math.abs));
      }
      peaks.push(peak);
      await api.fadeOutFeedbackAudio(140, 'correct');
    }
    await api.flushAllAudioSessions();
    await context.close();
    return { rounds: peaks.length, silentRounds: peaks.filter((peak) => peak < 0.01).length };
  });
  expect(result).toEqual({ rounds: 26, silentRounds: 0 });
});
