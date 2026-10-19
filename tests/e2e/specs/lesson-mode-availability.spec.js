const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');
const source = (name) => fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget', name), 'utf8');

async function mount(page, options = {}) {
  await page.goto('about:blank');
  await page.setContent(`<div id="ll-tools-flashcard-quiz-popup"><div id="quiz-results"><h2 id="quiz-results-title"></h2><p><span id="correct-count"></span></p><span id="total-questions"></span></div><div id="quiz-mode-buttons"></div><div id="ll-study-results-actions"><button id="ll-study-results-same-chunk">Repeat</button><button id="ll-study-results-different-chunk">Next activity</button><button id="ll-study-results-next-chunk">Next</button><span id="ll-study-results-suggestion"></span></div></div>`);
  await page.addScriptTag({ content: jquery });
  await page.evaluate((opts) => {
    const noop = () => {};
    window.llToolsFlashcardsData = {
      categories: [{ name: 'Lesson', prompt_type: 'text_title', option_type: 'text_translation', listening_supported: !!opts.audio }],
      launchContext: 'vocab_lesson',
      availableModes: opts.modes || null
    };
    window.llToolsFlashcardsMessages = { quizResults: 'Results', practiceModeText: 'Practice', selfCheckModeText: 'Self check' };
    window.wordsByCategory = { Lesson: [{ id: 1, title: 'Prompt', translation: 'Answer' }] };
    window.LLFlashcards = {
      State: { categoryNames: ['Lesson'], initialCategoryNames: ['Lesson'], wordsByCategory: window.wordsByCategory,
        quizResults: { correctOnFirstTry: 1, incorrect: [], wordAttempts: {} }, modeSessionCompleteTracked: true,
        wordCorrectCounts: {}, usedWordIDs: [1], DEFAULT_DISPLAY_MODE: 'text_translation',
        isLearningMode: false, isListeningMode: false, isGenderMode: false, isSelfCheckMode: false,
        STATES: {}, transitionTo: noop, clearActiveTimeouts: noop },
      Util: { getCategoryConfig: () => window.llToolsFlashcardsData.categories[0] },
      Dom: { hideLoading: noop, hideHeader: noop, hideFlashcard: noop },
      Effects: { startConfetti: noop }, Modes: {}, Cards: {}
    };
  }, options);
  await page.addScriptTag({ content: source('selection.js') });
}

test('Listen is unavailable for a silent text lesson and becomes available only with published audio metadata', async ({ page }) => {
  await mount(page);
  expect(await page.evaluate(() => window.LLFlashcards.Selection.isListeningSupportedForCategories(['Lesson']))).toBe(false);
  await page.evaluate(() => { window.llToolsFlashcardsData.categories[0].listening_supported = true; });
  expect(await page.evaluate(() => window.LLFlashcards.Selection.isListeningSupportedForCategories(['Lesson']))).toBe(true);
});

test('Practice-only lesson hides the next activity action and rejects other runtime modes', async ({ page }) => {
  await mount(page, { modes: ['practice'], audio: true });
  const modes = await page.evaluate(() => {
    const api = window.LLFlashcards.Selection;
    return ['learning', 'practice', 'listening', 'self-check', 'gender'].map((mode) => api.isModeAllowed(mode));
  });
  expect(modes).toEqual([false, true, false, false, false]);
  await page.addScriptTag({ content: source('results.js') });
  await page.evaluate(() => window.LLFlashcards.Results.showResults());
  await expect(page.locator('#ll-study-results-same-chunk')).toBeVisible();
  await expect(page.locator('#ll-study-results-different-chunk')).toBeHidden();
});

test('silent lesson completion advances to an enabled activity instead of Listen', async ({ page }) => {
  await mount(page, { modes: ['practice', 'self-check'] });
  await page.addScriptTag({ content: source('results.js') });
  await page.evaluate(() => window.LLFlashcards.Results.showResults());
  await expect(page.locator('#ll-study-results-different-chunk')).toBeVisible();
  const next = await page.locator('#ll-study-results-different-chunk').innerHTML();
  expect(next).not.toContain('🎧');
  await page.evaluate(() => {
    window.__next = '';
    window.LLFlashcards.Main = { switchMode: (mode) => { window.__next = mode; } };
  });
  await page.locator('#ll-study-results-different-chunk').click();
  expect(await page.evaluate(() => window.__next)).toBe('self-check');
});
