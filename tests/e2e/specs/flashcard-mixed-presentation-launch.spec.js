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

function buildHarnessMarkup() {
  return `
    <div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <button id="ll-tools-close-flashcard" type="button"></button>
        <div id="ll-tools-flashcard-header"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard-content"></div>
        <div id="ll-tools-flashcard"></div>
        <div id="quiz-results" style="display:block;">Previous results</div>
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
  `;
}

async function mountLaunchHarness(page, options = {}) {
  const preserveMixedPresentation = !!options.preserveMixedPresentation;
  const initialCategoryLoadFailure = !!options.initialCategoryLoadFailure;
  const boundedSelectionPlan = !!options.boundedSelectionPlan;
  const boundedPreloadFailure = !!options.boundedPreloadFailure;

  await page.goto('about:blank');
  await page.setContent(buildHarnessMarkup());
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate((bootstrap) => {
    const categories = [
      {
        id: 11,
        name: 'Cat A',
        slug: 'cat-a',
        prompt_type: 'audio',
        option_type: 'image',
        aspect_bucket: 'ratio:1_1',
        learning_supported: true
      },
      {
        id: 22,
        name: 'Cat B',
        slug: 'cat-b',
        prompt_type: 'audio',
        option_type: 'text_translation',
        aspect_bucket: 'no-image',
        learning_supported: true
      }
    ];

    const lastLaunchPlan = {
      mode: 'practice',
      category_ids: [11, 22],
      session_word_ids: [],
      details: bootstrap.preserveMixedPresentation ? { preserve_mixed_presentation: true } : {}
    };

    const findCategoryConfig = function (name) {
      const target = String(name || '').trim().toLowerCase();
      return categories.find((category) => String(category.name || '').trim().toLowerCase() === target) || categories[0];
    };

    window.__LLFlashcardsMainLoaded = false;
    window.__loadCategoryCalls = [];
    window.__boundedPreloadCalls = [];
    window.__hideResultsCalls = 0;
    window.__listeningCategoryCancelCalls = 0;
    window.llToolsFlashcardsData = {
      debug: false,
      firstCategoryName: 'Cat A',
      imageSize: 'small',
      categories: categories.slice(),
      lastLaunchPlan,
      last_launch_plan: Object.assign({}, lastLaunchPlan),
      modeUi: {},
      isUserLoggedIn: false,
      boundedSelectionPlan: bootstrap.boundedSelectionPlan,
      sessionWordIds: bootstrap.boundedSelectionPlan ? [101, 202] : []
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
      getCategoryConfig(name) {
        return findCategoryConfig(name);
      },
      getCategoryDisplayMode(name) {
        const cfg = findCategoryConfig(name);
        const optionType = String(cfg.option_type || 'image');
        return optionType === 'text_translation' || optionType === 'text_title' ? 'text' : optionType;
      },
      getCurrentDisplayMode() { return 'image'; },
      getTargetCategoryName() { return 'Cat A'; }
    };
    window.LLFlashcards.Cards = {};
    window.LLFlashcards.Results = {
      hideResults() {
        window.__hideResultsCalls += 1;
        window.jQuery('#quiz-results').hide();
      },
      showResults() {}
    };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {
      Practice: {
        initialize() {},
        runRound() {}
      },
      Listening: {
        initialize() {},
        cancelPendingCategoryLoads() {
          window.__listeningCategoryCancelCalls += 1;
        }
      }
    };

    window.FlashcardOptions = {
      initializeOptionsCount() {}
    };
    window.FlashcardLoader = {
      loadAudio() {},
      loadResourcesForCategory(categoryName) {
        window.__loadCategoryCalls.push(String(categoryName || ''));
        if (bootstrap.initialCategoryLoadFailure && window.__loadCategoryCalls.length === 1) {
          return Promise.resolve({ success: false, category: String(categoryName || '') });
        }
        return Promise.resolve({ success: true, category: String(categoryName || '') });
      },
      consumeBoundedPreloadedCategoryData(categoryNames) {
        window.__boundedPreloadCalls.push(Array.isArray(categoryNames) ? categoryNames.slice() : []);
        if (bootstrap.boundedPreloadFailure) {
          return Promise.reject(new Error('bounded handoff rejected'));
        }
        return Promise.resolve({ success: true, boundedPreloaded: true });
      },
      resetCacheForNewWordset() {}
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

    window.wordsByCategory = {};
    window.categoryNames = [];
    window.categoryRoundCount = {};
  }, {
    preserveMixedPresentation,
    initialCategoryLoadFailure,
    boundedSelectionPlan,
    boundedPreloadFailure
  });

  await page.addScriptTag({ content: mainSource });
}

test('practice init keeps mixed-presentation categories when the launch plan preserves them', async ({ page }) => {
  await mountLaunchHarness(page, { preserveMixedPresentation: true });

  const result = await page.evaluate(async () => {
    await window.initFlashcardWidget(['Cat A', 'Cat B'], 'practice');
    return {
      categoryNames: Array.isArray(window.LLFlashcards.State.categoryNames)
        ? window.LLFlashcards.State.categoryNames.slice()
        : [],
      initialCategoryNames: Array.isArray(window.LLFlashcards.State.initialCategoryNames)
        ? window.LLFlashcards.State.initialCategoryNames.slice()
        : [],
      firstCategoryName: String(window.LLFlashcards.State.firstCategoryName || ''),
      loadCategoryCalls: Array.isArray(window.__loadCategoryCalls)
        ? window.__loadCategoryCalls.slice()
        : [],
      hideResultsCalls: window.__hideResultsCalls,
      resultsDisplay: window.getComputedStyle(document.getElementById('quiz-results')).display
    };
  });

  expect(result.categoryNames).toEqual(['Cat A', 'Cat B']);
  expect(result.initialCategoryNames).toEqual(['Cat A', 'Cat B']);
  expect(result.firstCategoryName).toBe('Cat A');
  expect(Array.from(new Set(result.loadCategoryCalls))).toEqual(['Cat A', 'Cat B']);
  expect(result.hideResultsCalls).toBe(1);
  expect(result.resultsDisplay).toBe('none');
});

test('practice init still uses a single aspect bucket when mixed presentation is not preserved', async ({ page }) => {
  await mountLaunchHarness(page, { preserveMixedPresentation: false });

  const result = await page.evaluate(async () => {
    await window.initFlashcardWidget(['Cat A', 'Cat B'], 'practice');
    return {
      categoryNames: Array.isArray(window.LLFlashcards.State.categoryNames)
        ? window.LLFlashcards.State.categoryNames.slice()
        : [],
      initialCategoryNames: Array.isArray(window.LLFlashcards.State.initialCategoryNames)
        ? window.LLFlashcards.State.initialCategoryNames.slice()
        : [],
      firstCategoryName: String(window.LLFlashcards.State.firstCategoryName || ''),
      loadCategoryCalls: Array.isArray(window.__loadCategoryCalls)
        ? window.__loadCategoryCalls.slice()
        : []
    };
  });

  expect(result.categoryNames).toEqual(['Cat A']);
  expect(result.initialCategoryNames).toEqual(['Cat A']);
  expect(result.firstCategoryName).toBe('Cat A');
  expect(Array.from(new Set(result.loadCategoryCalls))).toEqual(['Cat A']);
});

test('practice init rejects when the explicit first-category load result fails', async ({ page }) => {
  await mountLaunchHarness(page, {
    preserveMixedPresentation: true,
    initialCategoryLoadFailure: true
  });

  const result = await page.evaluate(async () => {
    try {
      await window.initFlashcardWidget(['Cat A', 'Cat B'], 'practice');
      return { resolved: true };
    } catch (error) {
      return {
        resolved: false,
        code: String(error && error.code || ''),
        message: String(error && error.message || ''),
        state: window.LLFlashcards.State.getState(),
        loadCategoryCalls: window.__loadCategoryCalls.slice(),
        hideResultsCalls: window.__hideResultsCalls,
        resultsDisplay: window.getComputedStyle(document.getElementById('quiz-results')).display
      };
    }
  });

  expect(result).toMatchObject({
    resolved: false,
    code: 'll_flashcard_initial_category_load_failed',
    state: 'idle',
    loadCategoryCalls: ['Cat A'],
    hideResultsCalls: 0,
    resultsDisplay: 'block'
  });
  expect(result.message).toContain('did not load successfully');
});

test('bounded practice init propagates handoff rejection before category loading starts', async ({ page }) => {
  await mountLaunchHarness(page, {
    preserveMixedPresentation: true,
    boundedSelectionPlan: true,
    boundedPreloadFailure: true
  });

  const result = await page.evaluate(async () => {
    try {
      await window.initFlashcardWidget(['Cat A', 'Cat B'], 'practice');
      return { resolved: true };
    } catch (error) {
      return {
        resolved: false,
        message: String(error && error.message || ''),
        state: window.LLFlashcards.State.getState(),
        boundedPreloadCalls: window.__boundedPreloadCalls.map((names) => names.slice()),
        loadCategoryCalls: window.__loadCategoryCalls.slice(),
        hideResultsCalls: window.__hideResultsCalls,
        resultsDisplay: window.getComputedStyle(document.getElementById('quiz-results')).display
      };
    }
  });

  expect(result).toMatchObject({
    resolved: false,
    state: 'idle',
    boundedPreloadCalls: [['Cat A', 'Cat B']],
    loadCategoryCalls: [],
    hideResultsCalls: 0,
    resultsDisplay: 'block'
  });
  expect(result.message).toContain('bounded handoff rejected');
});

test('mode switch and close invalidate listening category prefetch', async ({ page }) => {
  await mountLaunchHarness(page, { preserveMixedPresentation: true });

  const result = await page.evaluate(async () => {
    window.LLFlashcards.State.isListeningMode = true;
    window.LLFlashcards.State.forceTransitionTo(window.LLFlashcards.State.STATES.QUIZ_READY, 'test setup');
    window.LLFlashcards.Main.switchMode('practice');
    await new Promise((resolve) => setTimeout(resolve, 0));
    const afterSwitch = window.__listeningCategoryCancelCalls;
    await window.LLFlashcards.Main.closeFlashcard();
    return {
      afterSwitch,
      afterClose: window.__listeningCategoryCancelCalls
    };
  });

  expect(result.afterSwitch).toBeGreaterThanOrEqual(1);
  expect(result.afterClose).toBeGreaterThan(result.afterSwitch);
});
