const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const audioSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/audio.js'), 'utf8');
const stateSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/state.js'), 'utf8');
const mainSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/main.js'), 'utf8');

async function mountFeedbackHarness(page) {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = { plugin_dir: 'https://feedback.test/' };
    window.LLFlashcards = { Dom: { requestSoundGate() { return false; } } };
    const createElement = document.createElement.bind(document);
    const elements = [];

    function createAudio(src = '') {
      // Keep real DOM event/onended semantics and mounting, while controlling
      // buffering and native play settlement without network or device timing.
      const audio = createElement('audio');
      const model = {
        src, readyState: 4, currentTime: 0, paused: true, ended: false,
        duration: 0.6, error: null, behavior: 'normal', plays: [], starts: 0,
        pauses: 0, loads: 0, pending: []
      };
      audio.model = model;
      for (const key of ['src', 'readyState', 'currentTime', 'paused', 'ended', 'duration', 'error']) {
        Object.defineProperty(audio, key, {
          configurable: true,
          get() { return model[key]; },
          set(value) { model[key] = value; }
        });
      }
      audio.load = () => {
        model.loads += 1;
        // A native media error is sticky across pause/play and seeking. The
        // resource-selection reset performed by load() clears that error.
        model.error = null;
        model.currentTime = 0;
        model.ended = false;
        model.readyState = 4;
        if (model.behavior === 'deferred') model.behavior = 'normal';
        model.pending.splice(0).forEach(pending => pending.reject(new DOMException('Resource load restarted', 'AbortError')));
      };
      audio.play = () => {
        model.plays.push({ time: model.currentTime, volume: audio.volume, at: performance.now() });
        if (model.error) {
          model.paused = true;
          return Promise.reject(new DOMException('The media resource is still in an error state', 'NotSupportedError'));
        }
        model.paused = false;
        model.ended = false;
        if (model.behavior === 'reject' || model.behavior === 'abort-once') {
          const name = model.behavior === 'reject' ? 'NotAllowedError' : 'AbortError';
          if (model.behavior === 'abort-once') model.behavior = 'normal';
          model.paused = true;
          return Promise.reject(new DOMException('Controlled playback failure', name));
        }
        audio.dispatchEvent(new Event('play'));
        if (model.behavior === 'deferred') {
          return new Promise((resolve, reject) => model.pending.push({ resolve, reject }));
        }
        model.starts += 1;
        audio.dispatchEvent(new Event('playing'));
        return Promise.resolve();
      };
      audio.pause = () => {
        model.pauses += 1;
        model.paused = true;
        model.pending.splice(0).forEach(pending => pending.reject(new DOMException('Paused before playback', 'AbortError')));
        audio.dispatchEvent(new Event('pause'));
      };
      model.begin = () => {
        model.readyState = 4;
        model.paused = false;
        model.starts += 1;
        audio.dispatchEvent(new Event('playing'));
        model.pending.splice(0).forEach(pending => pending.resolve());
      };
      model.finish = () => {
        model.currentTime = model.duration;
        model.paused = true;
        model.ended = true;
        audio.dispatchEvent(new Event('ended'));
      };
      model.failMedia = code => {
        model.error = { code };
        model.readyState = 0;
        model.paused = true;
        audio.dispatchEvent(new Event('error'));
        model.pending.splice(0).forEach(pending => pending.reject(new DOMException('Media resource failed', 'NotSupportedError')));
      };
      elements.push(audio);
      return audio;
    }

    window.Audio = function (src) { return createAudio(src); };
    document.createElement = function (tagName, options) {
      return String(tagName).toLowerCase() === 'audio' ? createAudio() : createElement(tagName, options);
    };
    window.feedbackFixture = {
      elements,
      wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); },
      target(id) { return window.FlashcardAudio.setTargetWordAudio({ audio: `https://feedback.test/prompt-${id}.mp3` }, { autoplay: false }); }
    };
  });
  await page.addScriptTag({ content: audioSource });
  await page.evaluate(() => window.FlashcardAudio.initializeAudio());
}

test('a rejected wrong-answer sound still replays the captured prompt from the beginning', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const target = await fixture.target('rejected');
    target.currentTime = 0.35;
    fixture.elements[1].model.behavior = 'reject';
    await window.FlashcardAudio.playFeedback(false).catch(() => {});
    await fixture.wait(30);
    return { promptPlays: target.model.plays.length, start: target.model.plays[0]?.time };
  });
  expect(result).toEqual({ promptPlays: 1, start: 0 });
});

for (const isCorrect of [true, false]) {
  for (const [failure, code] of [['network', 2], ['decode', 3]]) {
    test(`${isCorrect ? 'correct' : 'wrong'} feedback recovers from a persistent ${failure} error after several rounds`, async ({ page }) => {
      await mountFeedbackHarness(page);
      const result = await page.evaluate(async ({ isCorrect, code }) => {
        const fixture = window.feedbackFixture;
        const target = await fixture.target('repeated');
        const feedback = fixture.elements[isCorrect ? 0 : 1];
        for (let round = 0; round < 3; round += 1) {
          await window.FlashcardAudio.playFeedback(isCorrect);
          feedback.model.finish();
        }
        const healthy = { starts: feedback.model.starts, loads: feedback.model.loads };
        feedback.model.failMedia(code);
        // Seeking and pause/play cannot clear a native MediaError. A later
        // accepted answer must recover the retained feedback element.
        for (let round = 0; round < 3; round += 1) {
          await window.FlashcardAudio.playFeedback(isCorrect);
          feedback.model.finish();
        }
        return {
          healthy,
          starts: feedback.model.starts,
          loads: feedback.model.loads,
          error: feedback.error,
          promptPlays: target.model.plays.length,
          startPositions: feedback.model.plays.map(play => play.time)
        };
      }, { isCorrect, code });
      expect(result.healthy).toEqual({ starts: 3, loads: 1 });
      expect(result.starts).toBe(6);
      expect(result.loads).toBe(2);
      expect(result.error).toBeNull();
      expect(result.promptPlays).toBe(isCorrect ? 0 : 6);
      expect(result.startPositions.every(time => time === 0)).toBe(true);
    });
  }
}

test('a persistent wrong-feedback failure restores its prompt once and recovers on the next answer', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const target = await fixture.target('failed-resource');
    const wrong = fixture.elements[1];
    target.currentTime = 0.35;
    wrong.model.behavior = 'deferred';
    const failed = window.FlashcardAudio.playFeedback(false);
    wrong.model.failMedia(3);
    await failed;
    await fixture.wait(30);
    const afterFailure = { promptPlays: target.model.plays.length, starts: wrong.model.starts };
    wrong.model.behavior = 'normal';
    await window.FlashcardAudio.playFeedback(false);
    wrong.model.finish();
    await fixture.wait(30);
    return {
      afterFailure,
      starts: wrong.model.starts,
      loads: wrong.model.loads,
      promptPlays: target.model.plays.length,
      promptStartPositions: target.model.plays.map(play => play.time)
    };
  });
  expect(result).toEqual({
    afterFailure: { promptPlays: 1, starts: 0 },
    starts: 1, loads: 2, promptPlays: 2, promptStartPositions: [0, 0]
  });
});

test('a late media error cannot reload feedback or replay a prompt after cancellation', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    const closedTarget = await fixture.target('cancelled-error');
    const wrong = fixture.elements[1];
    wrong.model.behavior = 'deferred';
    const cancelled = api.playFeedback(false);
    await api.suspendPlayback();
    await cancelled;
    const beforeLateFailure = { loads: wrong.model.loads, plays: wrong.model.plays.length };
    wrong.model.failMedia(2);
    wrong.model.finish();
    await fixture.wait(200);
    const suspended = { loads: wrong.model.loads, plays: wrong.model.plays.length, promptPlays: closedTarget.model.plays.length };
    await api.startNewSession();
    const nextTarget = await fixture.target('new-session-error');
    await fixture.wait(100);
    const beforeNextAnswer = { loads: wrong.model.loads, plays: wrong.model.plays.length, promptPlays: nextTarget.model.plays.length };
    wrong.model.behavior = 'normal';
    await api.playFeedback(false);
    wrong.model.finish();
    return {
      beforeLateFailure, suspended, beforeNextAnswer,
      final: { starts: wrong.model.starts, loads: wrong.model.loads, closedPromptPlays: closedTarget.model.plays.length, nextPromptPlays: nextTarget.model.plays.length }
    };
  });
  expect(result.beforeLateFailure).toEqual({ loads: 1, plays: 1 });
  expect(result.suspended).toEqual({ loads: 1, plays: 1, promptPlays: 0 });
  expect(result.beforeNextAnswer).toEqual({ loads: 1, plays: 1, promptPlays: 0 });
  expect(result.final).toEqual({ starts: 1, loads: 2, closedPromptPlays: 0, nextPromptPlays: 1 });
});

test('a stalled feedback watchdog recovers on the next answer and never retries after closing', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    const target = await fixture.target('stalled-decoder');
    const wrong = fixture.elements[1];
    wrong.model.behavior = 'deferred';
    // This decoder never settles play() and exposes no MediaError. The harness
    // stays stalled across pauses; only load() restores normal playback.
    await api.playFeedback(false);
    await fixture.wait(100);
    const afterWatchdog = {
      starts: wrong.model.starts, plays: wrong.model.plays.length,
      loads: wrong.model.loads, error: wrong.error,
      behavior: wrong.model.behavior, promptPlays: target.model.plays.length
    };
    await api.playFeedback(false);
    wrong.model.finish();
    const recovered = { starts: wrong.model.starts, loads: wrong.model.loads, promptPlays: target.model.plays.length };
    wrong.model.behavior = 'deferred';
    const cancelled = api.playFeedback(false);
    await api.suspendPlayback();
    await cancelled;
    // Wait past the failed request's original watchdog, so a forgotten timer
    // would have had time to reload or replay the closed round's prompt.
    await fixture.wait(1900);
    return {
      afterWatchdog, recovered,
      afterClose: {
        starts: wrong.model.starts, plays: wrong.model.plays.length,
        loads: wrong.model.loads, paused: wrong.paused, promptPlays: target.model.plays.length
      }
    };
  });
  expect(result.afterWatchdog).toEqual({ starts: 0, plays: 1, loads: 1, error: null, behavior: 'deferred', promptPlays: 1 });
  expect(result.recovered).toEqual({ starts: 1, loads: 2, promptPlays: 2 });
  expect(result.afterClose).toEqual({ starts: 1, plays: 3, loads: 2, paused: true, promptPlays: 2 });
});

test('autoplay rejection does not reload healthy feedback on the next answer', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    const target = await fixture.target('autoplay-rejected');
    const results = [];
    for (const isCorrect of [true, false]) {
      const audio = fixture.elements[isCorrect ? 0 : 1];
      audio.model.behavior = 'reject';
      await api.playFeedback(isCorrect);
      const rejected = { starts: audio.model.starts, loads: audio.model.loads, error: audio.error };
      audio.model.behavior = 'normal';
      await api.playFeedback(isCorrect);
      audio.model.finish();
      results.push({ rejected, starts: audio.model.starts, plays: audio.model.plays.length, loads: audio.model.loads });
    }
    return { sounds: results, promptPlays: target.model.plays.length };
  });
  expect(result).toEqual({
    sounds: [true, false].map(() => ({
      rejected: { starts: 0, loads: 1, error: null }, starts: 1, plays: 2, loads: 1
    })),
    promptPlays: 2
  });
});

test('suspending playback cancels an outstanding feedback AbortError retry', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const correct = fixture.elements[0];
    correct.model.behavior = 'abort-once';
    const request = window.FlashcardAudio.playFeedback(true).catch(() => {});
    await fixture.wait(20);
    const firstAttemptCount = correct.model.plays.length;
    await window.FlashcardAudio.suspendPlayback();
    await fixture.wait(200);
    await request;
    return { firstAttemptCount, finalAttemptCount: correct.model.plays.length, paused: correct.paused };
  });
  expect(result).toEqual({ firstAttemptCount: 1, finalAttemptCount: 1, paused: true });
});

test('old wrong-answer completion cannot replay a replacement round prompt', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const firstTarget = await fixture.target('first');
    await window.FlashcardAudio.playFeedback(false);
    const nextTarget = await fixture.target('next');
    fixture.elements[1].model.finish();
    await fixture.wait(30);
    return { firstPromptPlays: firstTarget.model.plays.length, nextPromptPlays: nextTarget.model.plays.length };
  });
  expect(result).toEqual({ firstPromptPlays: 0, nextPromptPlays: 0 });
});

test('a correct answer cancels the preceding wrong-answer prompt replay', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const target = await fixture.target('corrected');
    await window.FlashcardAudio.playFeedback(false);
    await window.FlashcardAudio.playFeedback(true);
    fixture.elements[1].model.finish();
    await fixture.wait(30);
    return { promptPlays: target.model.plays.length, correctPlays: fixture.elements[0].model.plays.length };
  });
  expect(result).toEqual({ promptPlays: 0, correctPlays: 1 });
});

test('reused feedback restarts from the beginning after being paused partway through', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    for (const isCorrect of [true, false]) {
      const audio = fixture.elements[isCorrect ? 0 : 1];
      audio.currentTime = 0.35;
      await window.FlashcardAudio.playFeedback(isCorrect);
    }
    return fixture.elements.slice(0, 2).map(audio => audio.model.plays[0].time);
  });
  expect(result).toEqual([0, 0]);
});

test('an earlier fade cannot silence a newly requested feedback playback', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    const correct = fixture.elements[0];
    await api.playFeedback(true);
    const oldFade = api.fadeOutFeedbackAudio(180, 'correct');
    await fixture.wait(75);
    const fadedVolume = correct.volume;
    await api.playFeedback(true);
    await oldFade;
    await fixture.wait(150);
    return { fadedVolume, paused: correct.paused, volume: correct.volume, plays: correct.model.plays.length };
  });
  expect(result.fadedVolume).toBeLessThan(1);
  expect(result.paused).toBe(false);
  expect(result.volume).toBe(1);
  expect(result.plays).toBe(2);
});

test('feedback buffering gets an audible interval before its scheduled fade stops it', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    const correct = fixture.elements[0];
    correct.readyState = 0;
    correct.model.behavior = 'deferred';
    const request = api.playFeedback(true).catch(() => {});
    await fixture.wait(210);
    const fade = api.fadeOutFeedbackAudio(140, 'correct');
    await fixture.wait(140);
    const beforeReady = { plays: correct.model.plays.length, paused: correct.paused, starts: correct.model.starts };
    correct.model.begin();
    correct.dispatchEvent(new Event('canplay'));
    await fixture.wait(25);
    const afterStart = { paused: correct.paused, volume: correct.volume };
    // Resolve any old implementation's late play so a failed assertion does not
    // leave the browser test waiting forever on its controlled media promise.
    if (correct.model.pending.length) correct.model.begin();
    await request;
    await fade;
    await fixture.wait(160);
    return { beforeReady, afterStart, finalPaused: correct.paused, finalVolume: correct.volume };
  });
  expect(result.beforeReady).toEqual({ plays: 1, paused: false, starts: 0 });
  expect(result.afterStart.paused).toBe(false);
  expect(result.afterStart.volume).toBeGreaterThan(0);
  expect(result.finalPaused).toBe(true);
  expect(result.finalVolume).toBe(1);
});

test('a missing feedback ended event still restores the prompt exactly once', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const target = await fixture.target('missing-ended');
    const wrong = fixture.elements[1];
    await window.FlashcardAudio.playFeedback(false);
    await fixture.wait(1300);
    const afterWatchdog = { promptPlays: target.model.plays.length, wrongPaused: wrong.paused };
    wrong.model.finish();
    await fixture.wait(30);
    return { afterWatchdog, finalPromptPlays: target.model.plays.length };
  });
  expect(result).toEqual({ afterWatchdog: { promptPlays: 1, wrongPaused: true }, finalPromptPlays: 1 });
});

test('closing and opening a new session cancels buffered feedback and its prompt continuation', async ({ page }) => {
  await mountFeedbackHarness(page);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const api = window.FlashcardAudio;
    await fixture.target('closed');
    const wrong = fixture.elements[1];
    wrong.model.behavior = 'deferred';
    const request = api.playFeedback(false).catch(() => {});
    await fixture.wait(20);
    await api.suspendPlayback();
    await api.flushAllAudioSessions();
    await api.startNewSession();
    const nextTarget = await fixture.target('reopened');
    // A decoder's delayed completion belongs to the closed session.
    wrong.model.finish();
    await fixture.wait(200);
    if (wrong.model.pending.length) wrong.model.begin();
    await request;
    return { feedbackPlays: wrong.model.plays.length, paused: wrong.paused, nextPromptPlays: nextTarget.model.plays.length };
  });
  expect(result).toEqual({ feedbackPlays: 1, paused: true, nextPromptPlays: 0 });
});

test('fast quiz transitions wait for delayed feedback playback before advancing', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  await mountFeedbackHarness(page);
  await page.addScriptTag({ content: stateSource });
  await page.evaluate(() => {
    const ns = window.LLFlashcards;
    ns.Util = {};
    Object.assign(ns.Dom, { updateSimpleProgress() {} });
    ns.Effects = { startConfetti() {} };
    ns.Selection = {};
    ns.Cards = {};
    ns.Results = {};
    ns.StateMachine = {};
    ns.ModeConfig = {};
    ns.Modes = {};
    window.FlashcardLoader = { loadAudio() {} };
    window.FlashcardOptions = {};
    window.llToolsStudyPrefs = { fastTransitions: true, starredWordIds: [], starMode: 'normal' };
    window.llToolsFlashcardsData.isUserLoggedIn = false;
    const state = ns.State;
    state.currentFlowState = state.STATES.SHOWING_QUESTION;
    state.widgetActive = true;
    state.isFirstRound = false;
    // Observe the real answer transition; subsequent question rendering is
    // outside this fixture's audio/transition contract.
    state.canStartQuizRound = () => false;
    const card = document.createElement('button');
    card.className = 'flashcard-container';
    card.setAttribute('data-word-id', '101');
    document.getElementById('ll-tools-flashcard').appendChild(card);
  });
  await page.addScriptTag({ content: mainSource });
  expect(pageErrors).toEqual([]);
  const result = await page.evaluate(async () => {
    const fixture = window.feedbackFixture;
    const state = window.LLFlashcards.State;
    const correct = fixture.elements.filter(audio => audio.src.includes('right-answer.mp3')).at(-1);
    correct.readyState = 0;
    correct.model.behavior = 'deferred';
    window.LLFlashcards.Main.onCorrectAnswer({ id: 101 }, window.jQuery('.flashcard-container'));
    await fixture.wait(650);
    const beforeStart = { state: state.getState(), starts: correct.model.starts, plays: correct.model.plays.length };
    correct.model.begin();
    correct.dispatchEvent(new Event('canplay'));
    await fixture.wait(90);
    const shortlyAfterStart = { state: state.getState(), paused: correct.paused };
    await fixture.wait(350);
    return { beforeStart, shortlyAfterStart, afterTransition: state.getState() };
  });
  expect(result.beforeStart).toEqual({ state: 'processing_answer', starts: 0, plays: 1 });
  expect(result.shortlyAfterStart).toEqual({ state: 'processing_answer', paused: false });
  expect(result.afterTransition).toBe('quiz_ready');
});
