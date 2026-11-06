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

test('quiz popup supports results mode actions without a floating switcher', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const quizTriggers = page.locator('.ll-quiz-page-trigger');
  await expect(quizTriggers.first()).toBeVisible({ timeout: 60000 });

  await quizTriggers.first().click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-flashcard-header')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-mode-switcher-wrap, #ll-tools-mode-switcher, #ll-tools-mode-menu')).toHaveCount(0);

  await showResults(page);
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

  // Self-check must always review all words once regardless of star preference.
  expect(await page.evaluate(() => window.LLFlashcards.State.starModeOverride)).toBe('normal');

  // Both faces keep the same image cue, with text as a fallback for words without an image.
  const cueSelector = '.ll-study-check-image img, .ll-study-check-text';
  const front = page.locator('.ll-study-check-face--front');
  const promptCue = front.locator(cueSelector).first();
  await expect(promptCue).toBeVisible();
  await expect(front.locator('.ll-study-recording-btn, .ll-study-check-audio-btn')).toHaveCount(0);
  const promptImage = await promptCue.getAttribute('src');
  const promptText = promptImage ? '' : (await promptCue.textContent()).trim();
  expect(promptImage || promptText).toBeTruthy();
  await page.locator('.ll-study-check-btn--think').click();
  await expect(page.locator('.ll-study-check-flip-card')).toHaveClass(/is-flipped/);
  const answerCue = page.locator('.ll-study-check-face--back').locator(cueSelector).first();
  await expect(answerCue).toBeVisible();
  if (promptImage) {
    await expect(answerCue).toHaveAttribute('src', promptImage);
  } else {
    await expect(answerCue).toHaveText(promptText);
  }

  await showResults(page);
  await expect(page.locator('#restart-listening-mode')).toBeHidden();
  await switchViaResults(page, 'practice');

  await showResults(page);
  const learningButton = page.locator('#restart-learning-mode');
  if (await learningButton.isVisible() && await learningButton.isEnabled()) {
    await learningButton.click();
    await expect(page.locator('#quiz-results')).toBeHidden();
    await expect.poll(() => page.evaluate(() => window.LLFlashcards.State.isLearningMode)).toBe(true);
  }
});
