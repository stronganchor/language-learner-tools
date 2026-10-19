const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

async function showResults(page) {
  // Exercise the same results renderer used by completed rounds without making
  // this regression depend on a site's word count or audio duration.
  await page.evaluate(() => {
    const state = window.LLFlashcards.State;
    state.clearActiveTimeouts();
    state.forceTransitionTo('showing_results', 'Result-action regression');
    window.LLFlashcards.Results.showResults();
  });
  await expect(page.locator('#quiz-results')).toBeVisible();
}

async function switchViaResults(page, mode) {
  await showResults(page);
  const button = page.locator(`#restart-${mode}-mode`);
  await expect(button).toBeVisible();
  await expect(button).toBeEnabled();
  await button.click();
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect.poll(() => page.evaluate(() => {
    const state = window.LLFlashcards.State;
    return state.isSelfCheckMode ? 'self-check'
      : state.isListeningMode ? 'listening'
      : state.isLearningMode ? 'learning'
      : state.isGenderMode ? 'gender' : 'practice';
  })).toBe(mode);
}

function expectsImage(mode) {
  return String(mode || '') === 'image';
}

function expectsText(mode) {
  const normalized = String(mode || '');
  return normalized === 'text' || normalized === 'text_title' || normalized === 'text_translation' || normalized === 'text_audio';
}

function expectsAudio(mode) {
  const normalized = String(mode || '');
  return normalized === 'audio' || normalized === 'text_audio';
}

async function expectFaceMatchesMode(page, faceSelector, mode) {
  const face = page.locator(faceSelector);
  await expect(face).toBeVisible();

  if (expectsImage(mode)) {
    await expect(face.locator('.ll-study-check-image img')).toBeVisible();
  }
  if (expectsText(mode)) {
    await expect(face.locator('.ll-study-check-text')).toBeVisible();
  }
  if (expectsAudio(mode)) {
    const audioControls = face.locator('.ll-study-recording-btn, .ll-study-check-audio-btn');
    expect(await audioControls.count()).toBeGreaterThan(0);
    await expect(audioControls.first()).toBeVisible();
  }
}

test('quiz popup supports results mode actions without a floating switcher', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const quizTriggers = page.locator('.ll-quiz-page-trigger');
  await expect(quizTriggers.first()).toBeVisible({ timeout: 60000 });

  await quizTriggers.first().click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-flashcard-header')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-mode-switcher-wrap, #ll-tools-mode-switcher, #ll-tools-mode-menu')).toHaveCount(0);

  await switchViaResults(page, 'self-check');
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toHaveClass(/ll-self-check-active/);
  await expect(page.locator('.ll-study-check-round')).toBeVisible();
  await expect(page.locator('.ll-study-check-btn--idk')).toBeVisible();
  await expect(page.locator('.ll-study-check-btn--think')).toBeVisible();
  await expect(page.locator('.ll-study-check-btn--know')).toBeVisible();
  const selfCheckUi = await page.evaluate(() => {
    const card = document.querySelector('.ll-study-check-flip-card');
    const actions = document.querySelector('.ll-study-check-actions');
    const thinkSvg = document.querySelector('.ll-study-check-btn--think svg');
    const knowSvg = document.querySelector('.ll-study-check-btn--know svg');
    const popupRoot = document.querySelector('#ll-tools-flashcard-popup');
    const flashcard = document.querySelector('#ll-tools-flashcard');
    const flashcardContent = document.querySelector('#ll-tools-flashcard-content');
    const cardRect = card ? card.getBoundingClientRect() : null;
    const actionsRect = actions ? actions.getBoundingClientRect() : null;
    return {
      thinkNamespace: thinkSvg ? thinkSvg.namespaceURI : '',
      knowNamespace: knowSvg ? knowSvg.namespaceURI : '',
      iconSvgCount: document.querySelectorAll('.ll-study-check-btn .ll-study-check-icon svg').length,
      cardBottom: cardRect ? cardRect.bottom : 0,
      actionsTop: actionsRect ? actionsRect.top : 0,
      popupParentTag: popupRoot && popupRoot.parentElement ? popupRoot.parentElement.tagName.toLowerCase() : '',
      flashcardHasAudioLineLayout: !!(flashcard && flashcard.classList.contains('audio-line-layout')),
      contentHasAudioLineMode: !!(flashcardContent && flashcardContent.classList.contains('audio-line-mode'))
    };
  });
  expect(selfCheckUi.thinkNamespace).toBe('http://www.w3.org/2000/svg');
  expect(selfCheckUi.knowNamespace).toBe('http://www.w3.org/2000/svg');
  expect(selfCheckUi.iconSvgCount).toBeGreaterThanOrEqual(3);
  expect(selfCheckUi.actionsTop).toBeGreaterThanOrEqual(selfCheckUi.cardBottom - 1);
  expect(selfCheckUi.popupParentTag).toBe('body');
  expect(selfCheckUi.flashcardHasAudioLineLayout).toBe(false);
  expect(selfCheckUi.contentHasAudioLineMode).toBe(false);

  const modeMeta = await page.evaluate(() => {
    const state = (window.LLFlashcards && window.LLFlashcards.State) || {};
    return {
      optionType: String(state.currentOptionType || ''),
      promptType: String(state.currentPromptType || ''),
      starModeOverride: (state.starModeOverride === null || typeof state.starModeOverride === 'undefined')
        ? null
        : String(state.starModeOverride)
    };
  });

  // Self-check must always review all words once regardless of star preference.
  expect(modeMeta.starModeOverride).toBe('normal');

  await expectFaceMatchesMode(page, '.ll-study-check-face--front', modeMeta.optionType);
  await page.locator('.ll-study-check-btn--think').click();
  await expect(page.locator('.ll-study-check-flip-card')).toHaveClass(/is-flipped/);
  await expectFaceMatchesMode(page, '.ll-study-check-face--back', modeMeta.promptType);

  await switchViaResults(page, 'listening');
  await switchViaResults(page, 'practice');

  await showResults(page);
  const learningButton = page.locator('#restart-learning-mode');
  if (await learningButton.isVisible() && await learningButton.isEnabled()) {
    await learningButton.click();
    await expect(page.locator('#quiz-results')).toBeHidden();
    await expect.poll(() => page.evaluate(() => window.LLFlashcards.State.isLearningMode)).toBe(true);
  }
});
