const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const domSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/dom.js'), 'utf8');

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

async function openModeMenu(page) {
  const switcher = page.locator('#ll-tools-mode-switcher');
  const menu = page.locator('#ll-tools-mode-menu');

  await expect(switcher).toBeVisible();
  if ((await menu.getAttribute('aria-hidden')) !== 'false') {
    await switcher.click();
  }
  await expect(menu).toHaveAttribute('aria-hidden', 'false');
}

async function switchToListening(page) {
  const option = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.listening');
  await openModeMenu(page);
  await expect(option).toBeVisible();
  await option.click();
  await expect(option).toHaveClass(/active/);
}

async function waitForQuizControlsWithWarmingRecovery(page) {
  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  const switcher = page.locator('#ll-tools-mode-switcher-wrap');
  const retry = page.locator('#restart-quiz');

  for (let attempt = 0; attempt < 3; attempt += 1) {
    await expect.poll(async () => {
      if (await switcher.isVisible()) {
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

    if (await switcher.isVisible()) {
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

  await expect(switcher).toBeVisible({ timeout: 60000 });
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
  await switchToListening(page);

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
