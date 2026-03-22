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
  `;
}

async function mountLaunchHarness(page, options = {}) {
  const preserveMixedPresentation = !!options.preserveMixedPresentation;

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
    window.llToolsFlashcardsData = {
      debug: false,
      firstCategoryName: 'Cat A',
      imageSize: 'small',
      categories: categories.slice(),
      lastLaunchPlan,
      last_launch_plan: Object.assign({}, lastLaunchPlan),
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
      hideResults() {},
      showResults() {}
    };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {
      Practice: {
        initialize() {},
        runRound() {}
      }
    };

    window.FlashcardOptions = {
      initializeOptionsCount() {}
    };
    window.FlashcardLoader = {
      loadAudio() {},
      loadResourcesForCategory(categoryName) {
        window.__loadCategoryCalls.push(String(categoryName || ''));
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
    preserveMixedPresentation
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
        : []
    };
  });

  expect(result.categoryNames).toEqual(['Cat A', 'Cat B']);
  expect(result.initialCategoryNames).toEqual(['Cat A', 'Cat B']);
  expect(result.firstCategoryName).toBe('Cat A');
  expect(Array.from(new Set(result.loadCategoryCalls))).toEqual(['Cat A', 'Cat B']);
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
