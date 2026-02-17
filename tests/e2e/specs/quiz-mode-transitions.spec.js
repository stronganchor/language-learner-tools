const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

async function openModeMenu(page) {
  const switcher = page.locator('#ll-tools-mode-switcher');
  const menu = page.locator('#ll-tools-mode-menu');

  await expect(switcher).toBeVisible();
  if ((await menu.getAttribute('aria-hidden')) !== 'false') {
    await switcher.click();
  }
  await expect(menu).toHaveAttribute('aria-hidden', 'false');
}

async function switchToMode(page, mode) {
  const option = page.locator(`#ll-tools-mode-menu .ll-tools-mode-option.${mode}`);
  await openModeMenu(page);
  await expect(option).toBeVisible();

  const isDisabled = await option.evaluate((el) => {
    return el.classList.contains('disabled') || el.hasAttribute('disabled');
  });
  if (isDisabled) {
    throw new Error(`Mode option "${mode}" is unexpectedly disabled`);
  }

  await option.click();
  await expect(option).toHaveClass(/active/);
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
    await expect(face.locator('.ll-study-recording-btn, .ll-study-check-audio-btn')).toBeVisible();
  }
}

test('quiz popup supports mode transitions in the primary learn flow', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const quizTriggers = page.locator('.ll-quiz-page-trigger');
  await expect(quizTriggers.first()).toBeVisible({ timeout: 60000 });

  await quizTriggers.first().click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeVisible({ timeout: 60000 });

  await switchToMode(page, 'self-check');
  const selfCheckOption = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.self-check');
  const practiceOptionAfterSelfCheck = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.practice');
  const learningOptionAfterSelfCheck = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.learning');
  await openModeMenu(page);
  await expect(selfCheckOption).toHaveClass(/active/);
  await expect(practiceOptionAfterSelfCheck).not.toHaveClass(/active/);
  await expect(learningOptionAfterSelfCheck).not.toHaveClass(/active/);
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

  await switchToMode(page, 'listening');
  await switchToMode(page, 'practice');

  const learningOption = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.learning');
  await openModeMenu(page);
  const learningDisabled = await learningOption.evaluate((el) => {
    return el.classList.contains('disabled') || el.hasAttribute('disabled');
  });
  if (!learningDisabled) {
    await learningOption.click();
    await expect(learningOption).toHaveClass(/active/);
  }
});
