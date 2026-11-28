const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const domSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/dom.js'), 'utf8');
const audioSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/audio.js'), 'utf8');
const cardsSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/cards.js'), 'utf8');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

test('rapid sound-gate reactivation keeps its blocking overlay attached', async ({ page }) => {
  await page.setContent(`
    <div id="ll-tools-flashcard-quiz-popup">
      <div id="ll-tools-flashcard-content">
        <div id="ll-tools-flashcard" style="pointer-events:auto"><button>Answer</button></div>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.LLFlashcards = {
      State: { widgetActive: true, soundGateActive: false },
      Util: { getMessage(_key, fallback) { return fallback || ''; } }
    };
    window.llToolsFlashcardsData = {};
    window.llToolsFlashcardsMessages = {};
    window.FlashcardAudio = {
      clearAutoplayBlock() {},
      getCurrentTargetAudio() { return null; }
    };
  });
  await page.addScriptTag({ content: domSource });

  await page.evaluate(() => {
    const dom = window.LLFlashcards.Dom;
    dom.showAutoplayBlockedOverlay({ force: true });
    dom.hideAutoplayBlockedOverlay();
    dom.showAutoplayBlockedOverlay({ force: true });
  });
  await page.waitForTimeout(250);

  const overlay = page.locator('#ll-tools-autoplay-overlay');
  await expect(overlay).toHaveCount(1);
  await expect(overlay).toBeVisible();
  expect(await page.evaluate(() => ({
    pointerEvents: document.getElementById('ll-tools-flashcard').style.pointerEvents,
    soundGateActive: window.LLFlashcards.State.soundGateActive
  }))).toEqual({ pointerEvents: 'none', soundGateActive: true });

  await page.evaluate(() => window.LLFlashcards.Dom.hideAutoplayBlockedOverlay());
  await expect(overlay).toBeHidden();
  expect(await page.evaluate(() => ({
    pointerEvents: document.getElementById('ll-tools-flashcard').style.pointerEvents,
    soundGateActive: window.LLFlashcards.State.soundGateActive
  }))).toEqual({ pointerEvents: 'auto', soundGateActive: false });
});

async function mountManagedSoundGate(page) {
  await page.setContent(`
    <div id="ll-tools-flashcard-quiz-popup">
      <div id="ll-tools-flashcard-content">
        <div id="ll-tools-flashcard"><button data-word-id="1">Answer</button></div>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = { plugin_dir: '/' };
    window.answers = [];
    window.LLFlashcards = {
      State: { widgetActive: true, isLearningMode: true },
      Main: { onCorrectAnswer(target) { window.answers.push(target.id); } }
    };
    let soundGateClick;
    document.addEventListener('click', event => {
      if (event.target.closest('.ll-tools-autoplay-button')) soundGateClick = event;
    }, true);
    HTMLMediaElement.prototype.load = function () {};
    HTMLMediaElement.prototype.play = function () {
      if (!this.allowTestPlayback) {
        return Promise.reject(new DOMException('Playback requires a gesture', 'NotAllowedError'));
      }
      window.targetPlayedDuringGateClick = Boolean(soundGateClick && soundGateClick.eventPhase !== Event.NONE);
      Object.defineProperty(this, 'currentTime', { configurable: true, writable: true, value: 0.6 });
      this.dispatchEvent(new Event('timeupdate'));
      return Promise.resolve();
    };
  });
  await page.addScriptTag({ content: audioSource });
  await page.addScriptTag({ content: domSource });
  await page.addScriptTag({ content: cardsSource });
  await page.evaluate(() => {
    window.FlashcardAudio.initializeAudio();
    window.LLFlashcards.Cards.addClickEventToCard(window.jQuery('[data-word-id="1"]'), 0, { id: 1 }, 'image', 'audio');
  });
}

async function attemptBlockedPrompt(page) {
  await page.evaluate(async () => {
    const { Dom } = window.LLFlashcards;
    const audio = window.FlashcardAudio;
    Dom.setSoundGateRequired(true);
    await audio.setTargetWordAudio({ id: 1, audio: '/prompt.mp3' }, { autoplay: false });
    const target = audio.getCurrentTargetAudio();
    Object.defineProperty(target, 'readyState', { configurable: true, value: 3 });
    Dom.bindRepeatButtonAudio(target);
    await audio.playAudio(target).catch(error => {
      if (error.name !== 'NotAllowedError') throw error;
    });
  });
}

test('denied feedback leaves the speaker gate available to unlock a Learn answer', async ({ page }) => {
  await mountManagedSoundGate(page);
  await page.evaluate(async () => {
    window.LLFlashcards.Dom.setSoundGateRequired(true);
    await window.FlashcardAudio.playFeedback(true);
  });
  const overlay = page.locator('#ll-tools-autoplay-overlay');
  const answer = page.locator('[data-word-id="1"]');
  await expect(overlay).toHaveCount(0);

  await attemptBlockedPrompt(page);
  await expect(overlay).toBeVisible();
  await answer.dispatchEvent('click');
  expect(await page.evaluate(() => window.answers)).toEqual([]);

  await page.evaluate(() => {
    const target = window.FlashcardAudio.getCurrentTargetAudio();
    Object.defineProperty(target, 'readyState', { configurable: true, value: 0 });
    target.allowTestPlayback = true;
  });
  await overlay.getByRole('button', { name: 'Play audio' }).click();
  expect(await page.evaluate(() => window.targetPlayedDuringGateClick)).toBe(true);
  await expect(overlay).toBeHidden();
  await answer.click();
  expect(await page.evaluate(() => window.answers)).toEqual([1]);
});

test('a new prompt can show the speaker gate after another round cleared it', async ({ page }) => {
  await mountManagedSoundGate(page);
  const overlay = page.locator('#ll-tools-autoplay-overlay');
  await attemptBlockedPrompt(page);
  await expect(overlay).toBeVisible();
  await page.evaluate(() => window.LLFlashcards.Dom.setSoundGateRequired(false));
  await expect(overlay).toBeHidden();

  await attemptBlockedPrompt(page);
  await expect(overlay).toBeVisible();
});

test('old speaker playback cannot dismiss the gate for a replacement prompt', async ({ page }) => {
  await mountManagedSoundGate(page);
  const overlay = page.locator('#ll-tools-autoplay-overlay');
  await attemptBlockedPrompt(page);
  await page.evaluate(() => {
    window.FlashcardAudio.getCurrentTargetAudio().play = () => new Promise(resolve => {
      window.finishOldGatePlayback = resolve;
    });
  });
  await overlay.getByRole('button', { name: 'Play audio' }).click();

  await page.evaluate(() => window.LLFlashcards.Dom.setSoundGateRequired(false));
  await attemptBlockedPrompt(page);
  await expect(overlay).toBeVisible();
  const gateStillActive = await page.evaluate(async () => {
    window.finishOldGatePlayback();
    await new Promise(resolve => setTimeout(resolve, 0));
    return window.LLFlashcards.Dom.isSoundGateActive();
  });
  expect(gateStillActive).toBe(true);
  await expect(overlay).toBeVisible();
});

async function waitForQuizControlsWithWarmingRecovery(page) {
  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  const header = page.locator('#ll-tools-flashcard-header');
  const retry = page.locator('#restart-quiz');

  for (let attempt = 0; attempt < 3; attempt += 1) {
    await expect.poll(async () => {
      if (await header.isVisible()) {
        return 'ready';
      }
      if (await retry.isVisible()) {
        return 'retry';
      }
      return 'loading';
    }, {
      timeout: 45000,
      intervals: [250, 500, 1000]
    }).not.toBe('loading');

    if (await header.isVisible()) {
      return;
    }

    await expect(popup).toHaveClass(/ll-tools-error-state/);
    const warmingError = await page.evaluate(() => {
      const state = window.__LL_LAST_WORDS_AJAX || {};
      const error = state.error || {};
      return {
        code: String(error.code || ''),
        retryable: error.retryable === true
      };
    });
    expect(warmingError).toEqual({
      code: 'cache_warming_timeout',
      retryable: true
    });
    await retry.click({ force: true });
  }

  await expect(header).toBeVisible({ timeout: 60000 });
}

test('audio-required quiz rounds pause behind the speaker gate when quiz audio is muted', async ({ page }) => {
  test.slow();
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const audioQuizTrigger = page.locator('.ll-quiz-page-trigger[data-prompt-type*="audio"]').first();
  await expect(audioQuizTrigger).toBeVisible({ timeout: 60000 });
  await expect(audioQuizTrigger).toHaveAttribute('data-prompt-type', /audio/);
  await audioQuizTrigger.click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  // Full-suite fixtures can invalidate the anonymous payload materializer.
  // Keep the sound-gate assertions strict while allowing the documented
  // cache-warming recovery lifecycle to finish before the quiz controls appear.
  await waitForQuizControlsWithWarmingRecovery(page);
  await expect(page.locator('#ll-tools-mode-switcher-wrap, #ll-tools-mode-switcher, #ll-tools-mode-menu')).toHaveCount(0);

  await page.waitForFunction(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    return !!audio;
  }, { timeout: 60000 });

  const mutedApplied = await page.evaluate(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    if (!audio) {
      return false;
    }
    audio.volume = 0;
    audio.dispatchEvent(new Event('volumechange'));
    return true;
  });
  expect(mutedApplied).toBe(true);

  const overlay = page.locator('#ll-tools-autoplay-overlay');
  await expect(overlay).toBeVisible({ timeout: 10000 });
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toHaveClass(/ll-sound-gate-active/);

  await overlay.locator('.ll-tools-autoplay-button').click({ force: true });

  await expect(overlay).toBeHidden({ timeout: 10000 });
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).not.toHaveClass(/ll-sound-gate-active/);

  const restored = await page.evaluate(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    return !!audio && !audio.muted && Number(audio.volume || 0) > 0;
  });
  expect(restored).toBe(true);
});
