const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');
const source = name => fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget', name), 'utf8');

async function mount(page, { embed = true, rejectInit = false } = {}) {
  await page.goto('about:blank');
  await page.setContent(`<div id="ll-tools-flashcard-popup"><div id="ll-tools-flashcard-quiz-popup">
    <div id="ll-tools-flashcard-header">Quiz shell</div><div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>
    <div id="quiz-results" style="display:none"><h2>Results</h2></div>
  </div></div>`);
  await page.addScriptTag({ content: jquery });
  await page.evaluate(({ embed, rejectInit }) => {
    window.llToolsFlashcardsData = { isEmbed: embed, quiz_mode: 'practice', categories: [{ name: 'Lesson' }], roundLoadingShowDelayMs: 0, roundLoadingMinVisibleMs: 0 };
    window.__initDone = false;
    window.__presentationEvents = 0;
    window.__rejectInit = rejectInit;
    jQuery(document).on('lltools:flashcard-content-presented.test', () => { window.__presentationEvents += 1; });
  }, { embed, rejectInit });
  await page.addScriptTag({ content: source('state.js') });
  await page.addScriptTag({ content: source('dom.js') });
  await page.evaluate(() => {
    window.LLFlashcards.Main = { initFlashcardWidget() {
      window.LLFlashcards.State.widgetActive = true;
      window.LLFlashcards.State.forceTransitionTo('loading', 'Fixture initialization');
      window.LLFlashcards.Dom.showLoading();
      window.__initDone = true;
      return window.__rejectInit ? Promise.reject(new Error('Unable to load')) : Promise.resolve();
    } };
  });
  await page.addScriptTag({ content: source('category-selection.js') });
  if (embed) await expect.poll(() => page.evaluate(() => window.__initDone)).toBe(true);
}

for (const state of ['showing_question', 'introducing_words']) {
  test(`embed readiness waits for rendered ${state} content and the loader release`, async ({ page }) => {
    await mount(page);
    expect(await page.evaluate(() => window.__LL_EMBED_STATE || '')).toBe('');
    await expect(page.locator('#ll-tools-flashcard-header')).toBeVisible();
    await page.evaluate((state) => {
      jQuery('#ll-tools-flashcard').html('<button class="flashcard-container">Usable answer</button>');
      window.LLFlashcards.State.forceTransitionTo(state, 'Cards rendered');
    }, state);
    expect(await page.evaluate(() => window.LLFlashcards.Dom.getEmbedPresentationState())).toBe('');
    await page.evaluate(() => window.LLFlashcards.Dom.hideLoading());
    await expect.poll(() => page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-ready');
    await expect(page.locator('.flashcard-container')).toBeVisible();
  });
}

test('a stale loader release cannot reveal a replacement round still loading', async ({ page }) => {
  await mount(page);
  await page.evaluate(async () => {
    jQuery('#ll-tools-flashcard').html('<button class="flashcard-container">Previous answer</button>');
    window.LLFlashcards.State.forceTransitionTo('showing_question', 'Old round');
    const release = window.LLFlashcards.Dom.hideLoading();
    window.LLFlashcards.State.forceTransitionTo('loading', 'Replacement round');
    jQuery('#ll-tools-flashcard').empty();
    window.LLFlashcards.Dom.showLoading();
    await release;
  });
  expect(await page.evaluate(() => window.__LL_EMBED_STATE || '')).toBe('');
  await page.evaluate(async () => {
    jQuery('#ll-tools-flashcard').html('<button class="flashcard-container">Current answer</button>');
    window.LLFlashcards.State.forceTransitionTo('showing_question', 'Replacement rendered');
    await window.LLFlashcards.Dom.hideLoading();
  });
  expect(await page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-ready');
});

test('an initial render error reports failure for the host retry controls', async ({ page }) => {
  await mount(page);
  await page.evaluate(async () => {
    jQuery('#ll-tools-flashcard-quiz-popup').addClass('ll-tools-error-state');
    jQuery('#quiz-results').show();
    window.LLFlashcards.State.forceTransitionTo('showing_results', 'Load error');
    await window.LLFlashcards.Dom.hideLoading();
  });
  expect(await page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-error');
});

test('an initializer rejection reports failure without waiting for cards', async ({ page }) => {
  await mount(page, { rejectInit: true });
  await expect.poll(() => page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-error');
});

test('non-embed widgets do not send startup presentation signals', async ({ page }) => {
  await mount(page, { embed: false });
  await page.evaluate(async () => {
    await window.LLFlashcards.Main.initFlashcardWidget();
    jQuery('#ll-tools-flashcard').html('<button class="flashcard-container">Answer</button>');
    window.LLFlashcards.State.forceTransitionTo('showing_question', 'Normal widget');
    await window.LLFlashcards.Dom.hideLoading();
  });
  expect(await page.evaluate(() => ({ state: window.__LL_EMBED_STATE || '', events: window.__presentationEvents }))).toEqual({ state: '', events: 0 });
});


test('a usable state reached after loader release still notifies readiness once', async ({ page }) => {
  await mount(page);
  await page.evaluate(async () => {
    jQuery('#ll-tools-flashcard').html('<button class="flashcard-container">Answer</button>');
    await window.LLFlashcards.Dom.hideLoading();
  });
  expect(await page.evaluate(() => window.__LL_EMBED_STATE || '')).toBe('');
  await page.evaluate(() => window.LLFlashcards.State.forceTransitionTo('showing_question', 'Late ready state'));
  await expect.poll(() => page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-ready');
  expect(await page.evaluate(() => window.LLFlashcards.State._stateListeners.length)).toBe(0);
});

for (const mode of ['practice', 'learning', 'listening']) {
  test(`${mode} can reveal a usable blocked-audio control without waiting for a gesture behind the host loader`, async ({ page }) => {
    await mount(page);
    await page.evaluate(async mode => {
      window.llToolsFlashcardsData.quiz_mode = mode;
      const state = window.LLFlashcards.State;
      state.isLearningMode = mode === 'learning';
      state.isListeningMode = mode === 'listening';
      window.__audioPlays = 0;
      const audio = new EventTarget();
      Object.assign(audio, { paused: true, ended: false, muted: false, volume: 1, currentTime: 0, readyState: 2 });
      audio.play = () => { window.__audioPlays += 1; audio.paused = false; return Promise.resolve(); };
      window.FlashcardAudio = { getCurrentTargetAudio: () => audio, playAudio: () => audio.play(), clearAutoplayBlock() {} };
      window.LLFlashcards.Dom.setSoundGateRequired(true);
      window.LLFlashcards.Dom.requestSoundGate(audio, { force: true });
      // The media/play gate is usable even if an introduction has not rendered cards yet.
      await window.LLFlashcards.Dom.hideLoading();
    }, mode);
    expect(await page.evaluate(() => window.__LL_EMBED_STATE)).toBe('ll-embed-ready');
    await expect(page.locator('.ll-tools-autoplay-button')).toBeVisible();
    await page.locator('.ll-tools-autoplay-button').click();
    expect(await page.evaluate(() => window.__audioPlays)).toBe(1);
    await expect(page.locator('#ll-tools-autoplay-overlay')).toHaveCount(0);
    expect(await page.evaluate(() => window.LLFlashcards.State._stateListeners.length)).toBe(0);
  });
}
