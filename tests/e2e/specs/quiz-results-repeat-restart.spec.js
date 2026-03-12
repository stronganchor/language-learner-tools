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

async function mountPracticeProgressHarness(page, options = {}) {
  const targets = Array.isArray(options.targets) && options.targets.length
    ? options.targets
    : [
        { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
        { id: 502, title: 'Plate', __categoryName: 'Kitchen' }
      ];
  const categories = Array.isArray(options.categories) && options.categories.length
    ? options.categories
    : [
        { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'image', option_type: 'text', word_count: targets.length }
      ];
  const sessionWordIds = Array.isArray(options.sessionWordIds)
    ? options.sessionWordIds
    : [];
  const initialCategoryNames = Array.isArray(options.initialCategoryNames) && options.initialCategoryNames.length
    ? options.initialCategoryNames
    : categories.map((category) => String(category && category.name || '').trim()).filter(Boolean);
  const categoryNames = Array.isArray(options.categoryNames) && options.categoryNames.length
    ? options.categoryNames
    : initialCategoryNames.slice();
  const wordsByCategory = options.wordsByCategory && typeof options.wordsByCategory === 'object'
    ? options.wordsByCategory
    : {
        Kitchen: targets.slice()
      };
  const currentCategoryName = String(options.currentCategoryName || categoryNames[0] || initialCategoryNames[0] || 'Kitchen');
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
        <button id="ll-tools-repeat-flashcard" type="button"></button>
      </div>
    </div>
  `);

  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate((bootstrap) => {
    window.__LLFlashcardsMainLoaded = false;
    window.__progressCalls = [];
    window.__showResultsCount = 0;
    window.__currentTarget = null;
    window.__targets = bootstrap.targets.slice();

    window.llToolsFlashcardsData = {
      debug: false,
      firstCategoryName: bootstrap.currentCategoryName,
      imageSize: 'small',
      categories: bootstrap.categories.slice(),
      sessionWordIds: bootstrap.sessionWordIds.slice(),
      session_word_ids: bootstrap.sessionWordIds.slice(),
      modeUi: {},
      isUserLoggedIn: false
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', fastTransitions: true };

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
      hideAutoplayBlockedOverlay() {},
      updateSimpleProgress(currentCount, totalCount) {
        window.__progressCalls.push({
          current: Number(currentCount) || 0,
          total: Number(totalCount) || 0
        });
      }
    };
    window.LLFlashcards.Effects = {
      startConfetti() {}
    };
    window.LLFlashcards.Selection = {
      isLearningSupportedForCategories() { return true; },
      isGenderSupportedForCategories() { return true; },
      getCategoryConfig() {
        return { option_type: 'text', prompt_type: 'image', learning_supported: true };
      },
      getCurrentDisplayMode() { return 'text'; },
      getTargetCategoryName(word) {
        return (word && word.__categoryName) || 'Kitchen';
      },
      selectTargetWordAndCategory() {
        const next = window.__targets.length ? window.__targets.shift() : null;
        window.__currentTarget = next;
        return next;
      },
      fillQuizOptions(targetWord) {
        const $container = window.jQuery('#ll-tools-flashcard');
        $container.empty();
        window.jQuery('<button class="flashcard-container correct-card" type="button"></button>')
          .attr('data-word-id', targetWord.id)
          .appendTo($container);
        return Promise.resolve({ ready: true });
      }
    };
    window.LLFlashcards.Cards = {};
    window.LLFlashcards.Results = {
      hideResults() {},
      showResults() {
        window.__showResultsCount += 1;
      }
    };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {};

    window.FlashcardOptions = {
      initializeOptionsCount() {},
      categoryOptionsCount: { Kitchen: 2 }
    };
    window.FlashcardLoader = {
      loadAudio() {},
      loadResourcesForCategory() {},
      loadResourcesForWord() {
        return Promise.resolve({ ready: true, audioReady: true, imageReady: true });
      }
    };
    window.FlashcardAudio = {
      initializeAudio() {},
      getCorrectAudioURL() { return ''; },
      getWrongAudioURL() { return ''; },
      pauseAllAudio() {},
      setTargetAudioHasPlayed() {},
      setTargetWordAudio() {},
      getCurrentTargetAudio() { return null; }
    };

    const state = window.LLFlashcards.State;
    state.widgetActive = true;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.isFirstRound = false;
    state.categoryNames = bootstrap.categoryNames.slice();
    state.initialCategoryNames = bootstrap.initialCategoryNames.slice();
    state.wordsByCategory = bootstrap.wordsByCategory;
    state.currentCategoryName = bootstrap.currentCategoryName;
    state.currentCategory = state.wordsByCategory[bootstrap.currentCategoryName] || [];
    window.wordsByCategory = state.wordsByCategory;
    window.categoryNames = state.categoryNames;
    window.categoryRoundCount = state.categoryRoundCount;
  }, {
    targets,
    categories,
    sessionWordIds,
    categoryNames,
    initialCategoryNames,
    wordsByCategory,
    currentCategoryName
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

test('practice progress reaches full on the actual last answer without inserting an extra replay', async ({ page }) => {
  await mountPracticeProgressHarness(page);

  const answerCurrentRound = async () => {
    await page.evaluate(() => {
      window.LLFlashcards.Main.onCorrectAnswer(
        window.__currentTarget,
        window.jQuery('.correct-card')
      );
    });
  };

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  let progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 0, total: 2 });

  await answerCurrentRound();
  await page.waitForFunction(() => window.__progressCalls.length >= 2);
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 1, total: 2 });

  await answerCurrentRound();
  await page.waitForFunction(() => window.__showResultsCount === 1);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 2, total: 2 });

  const finalState = await page.evaluate(() => ({
    remainingTargets: window.__targets.length,
    flowState: window.LLFlashcards.State.getState()
  }));
  expect(finalState.remainingTargets).toBe(0);
  expect(finalState.flowState).toBe('showing_results');
});

test('practice progress advances after a correct answer even if the turn had a wrong guess first', async ({ page }) => {
  await mountPracticeProgressHarness(page);

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const outcome = await page.evaluate(() => {
    window.LLFlashcards.State.hadWrongAnswerThisTurn = true;
    window.LLFlashcards.Main.onCorrectAnswer(
      window.__currentTarget,
      window.jQuery('.correct-card')
    );
    return {
      flowState: window.LLFlashcards.State.getState(),
      progressCalls: window.__progressCalls.slice()
    };
  });

  expect(outcome.flowState).toBe('processing_answer');
  expect(outcome.progressCalls.at(-1)).toEqual({ current: 1, total: 2 });
});

test('practice progress prefers exact session word totals while later categories are still unloaded', async ({ page }) => {
  await mountPracticeProgressHarness(page, {
    targets: [
      { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
      { id: 502, title: 'Plate', __categoryName: 'Kitchen' },
      { id: 601, title: 'Rose', __categoryName: 'Garden' },
      { id: 602, title: 'Leaf', __categoryName: 'Garden' }
    ],
    categories: [
      { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'image', option_type: 'text', word_count: 20 },
      { id: 12, name: 'Garden', slug: 'garden', prompt_type: 'image', option_type: 'text', word_count: 20 }
    ],
    sessionWordIds: [501, 502, 601, 602],
    categoryNames: ['Kitchen', 'Garden'],
    initialCategoryNames: ['Kitchen', 'Garden'],
    currentCategoryName: 'Kitchen',
    wordsByCategory: {
      Kitchen: [
        { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
        { id: 502, title: 'Plate', __categoryName: 'Kitchen' }
      ]
    }
  });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  let progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 0, total: 4 });

  await page.evaluate(() => {
    window.LLFlashcards.Main.onCorrectAnswer(
      window.__currentTarget,
      window.jQuery('.correct-card')
    );
  });
  await page.waitForFunction(() => window.__progressCalls.length >= 2);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 1, total: 4 });
});

test('practice progress falls back to configured category counts before other categories finish loading', async ({ page }) => {
  await mountPracticeProgressHarness(page, {
    targets: [
      { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
      { id: 502, title: 'Plate', __categoryName: 'Kitchen' },
      { id: 601, title: 'Rose', __categoryName: 'Garden' },
      { id: 602, title: 'Leaf', __categoryName: 'Garden' }
    ],
    categories: [
      { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'image', option_type: 'text', word_count: 2 },
      { id: 12, name: 'Garden', slug: 'garden', prompt_type: 'image', option_type: 'text', word_count: 2 }
    ],
    categoryNames: ['Kitchen', 'Garden'],
    initialCategoryNames: ['Kitchen', 'Garden'],
    currentCategoryName: 'Kitchen',
    wordsByCategory: {
      Kitchen: [
        { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
        { id: 502, title: 'Plate', __categoryName: 'Kitchen' }
      ]
    }
  });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 0, total: 4 });
});
