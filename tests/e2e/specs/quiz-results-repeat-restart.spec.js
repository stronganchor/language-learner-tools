const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const stateSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/state.js'),
  'utf8'
);
const mainSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);

async function mountRestartHarness(page) {
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <button id="ll-tools-close-flashcard" type="button"></button>
        <div id="ll-tools-flashcard-header"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard-content"></div>
        <div id="ll-tools-flashcard"></div>
        <div id="quiz-results"></div>
        <div id="ll-tools-mode-switcher-wrap" aria-expanded="false">
          <div id="ll-tools-mode-menu" aria-hidden="true">
            <button class="ll-tools-mode-option practice" data-mode="practice" type="button"></button>
          </div>
          <button id="ll-tools-mode-switcher" type="button"></button>
        </div>
        <button id="ll-tools-repeat-flashcard" type="button"></button>
        <button id="restart-practice-mode" type="button"></button>
        <button id="restart-learning-mode" type="button"></button>
        <button id="restart-self-check-mode" type="button"></button>
        <button id="restart-gender-mode" type="button"></button>
        <button id="restart-listening-mode" type="button"></button>
        <button id="restart-quiz" type="button"></button>
      </div>
    </div>
  `);

  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate(() => {
    window.__LLFlashcardsMainLoaded = false;
    window.llToolsFlashcardsData = {
      debug: false,
      firstCategoryName: 'Kitchen',
      imageSize: 'small',
      categories: [
        { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'audio', option_type: 'image' }
      ],
      modeUi: {},
      isUserLoggedIn: false
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal' };

    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.Util = {
      randomlySort(items) {
        return Array.isArray(items) ? items.slice() : [];
      }
    };
    window.LLFlashcards.Dom = {
      clearRepeatButtonBinding() {},
      restoreHeaderUI() {},
      showLoading() {},
      hideLoading() { return Promise.resolve(); },
      hideLoadingImmediately() { return Promise.resolve(); },
      setRepeatButton() {},
      updateCategoryNameDisplay() {},
      enableRepeatButton() {},
      disableRepeatButton() {},
      bindRepeatButtonAudio() {},
      updateSimpleProgress() {},
      hideAutoplayBlockedOverlay() {}
    };
    window.LLFlashcards.Effects = {
      startConfetti() {}
    };
    window.LLFlashcards.Selection = {
      isLearningSupportedForCategories() { return true; },
      isGenderSupportedForCategories() { return true; },
      getCategoryConfig() {
        return { option_type: 'image', prompt_type: 'audio', learning_supported: true };
      },
      getCurrentDisplayMode() { return 'image'; },
      getTargetCategoryName() { return 'Kitchen'; },
      selectTargetWordAndCategory() { return null; }
    };
    window.LLFlashcards.Cards = {};
    window.LLFlashcards.Results = {
      hideResults() {}
    };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {};

    window.FlashcardOptions = {
      initializeOptionsCount() {}
    };
    window.FlashcardLoader = {
      loadAudio() {},
      loadResourcesForCategory() {}
    };
    window.FlashcardAudio = {
      initializeAudio() {},
      getCorrectAudioURL() { return ''; },
      getWrongAudioURL() { return ''; },
      suspendPlayback() {},
      startNewSession() { return Promise.resolve(); },
      pauseAllAudio() {},
      setTargetAudioHasPlayed() {},
      setTargetWordAudio() {},
      getCurrentTargetAudio() { return null; },
      clearAutoplayBlock() {}
    };

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.SHOWING_RESULTS;
    state.widgetActive = true;
    state.categoryNames = ['Kitchen'];
    state.initialCategoryNames = ['Kitchen'];
    state.wordsByCategory = {
      Kitchen: [
        { id: 501, title: 'Cup', audio: 'https://audio.test/cup.mp3', __categoryName: 'Kitchen' }
      ]
    };
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
    window.categoryNames = state.categoryNames;
    window.categoryRoundCount = state.categoryRoundCount;
  });

  await page.addScriptTag({ content: mainSource });
}

test('switchMode keeps the popup session active after resetting state', async ({ page }) => {
  await mountRestartHarness(page);

  await page.evaluate(() => {
    window.LLFlashcards.Main.switchMode('practice');
  });

  await page.waitForTimeout(50);

  const state = await page.evaluate(() => {
    return {
      widgetActive: !!window.LLFlashcards.State.widgetActive,
      flowState: window.LLFlashcards.State.getState()
    };
  });

  expect(state.widgetActive).toBe(true);
  expect(['loading', 'quiz_ready']).toContain(state.flowState);
});

test('restartQuiz keeps the popup session active after resetting state', async ({ page }) => {
  await mountRestartHarness(page);

  await page.evaluate(() => {
    window.LLFlashcards.Main.restartQuiz();
  });

  await page.waitForTimeout(50);

  const state = await page.evaluate(() => {
    return {
      widgetActive: !!window.LLFlashcards.State.widgetActive,
      flowState: window.LLFlashcards.State.getState()
    };
  });

  expect(state.widgetActive).toBe(true);
  expect(['loading', 'quiz_ready']).toContain(state.flowState);
});
