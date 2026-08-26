const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const stateSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/state.js'),
  'utf8'
);
const domSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/dom.js'),
  'utf8'
);
const mainSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);

function fixtureImage(fill, label) {
  return `data:image/svg+xml,${encodeURIComponent(`
    <svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" viewBox="0 0 320 240">
      <rect width="320" height="240" fill="${fill}"/>
      <text x="160" y="130" text-anchor="middle" font-family="Arial, sans-serif" font-size="48" font-weight="700" fill="#1d4d99">${label}</text>
    </svg>
  `)}`;
}

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
        <div id="ll-tools-mode-switcher-wrap" aria-expanded="false">
          <div id="ll-tools-mode-menu" aria-hidden="true">
            <button class="ll-tools-mode-option practice" data-mode="practice" type="button"></button>
          </div>
          <button id="ll-tools-mode-switcher" type="button"></button>
        </div>
        <div id="quiz-results" style="display:none;">
          <h2 id="quiz-results-title"></h2>
          <p id="quiz-results-message" style="display:none;"></p>
          <p class="ll-quiz-results-score">
            <span id="correct-count">0</span> / <span id="total-questions">0</span>
          </p>
          <div id="quiz-mode-buttons" style="display:none;">
            <button id="restart-practice-mode" type="button"></button>
            <button id="restart-learning-mode" type="button"></button>
            <button id="restart-self-check-mode" type="button"></button>
            <button id="restart-gender-mode" type="button"></button>
            <button id="restart-listening-mode" type="button"></button>
          </div>
          <button id="restart-quiz" class="quiz-button" type="button" style="display:none;"></button>
        </div>
        <button id="ll-tools-repeat-flashcard" type="button"></button>
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
      },
      promptTypeHasAudio(promptType) {
        return String(promptType || '').trim().toLowerCase() === 'audio';
      },
      promptTypeHasImage(promptType) {
        return String(promptType || '').trim().toLowerCase() === 'image';
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

async function mountRenderedImageReadinessHarness(page) {
  const goodImage = fixtureImage('#dcfce7', 'OK');
  const brokenImage = 'data:image/png;base64,this-is-not-a-valid-image';

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <button id="ll-tools-close-flashcard" type="button"></button>
        <div id="ll-tools-flashcard-header"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard-content">
          <div id="ll-tools-flashcard"></div>
        </div>
        <div id="quiz-results"></div>
        <button id="ll-tools-repeat-flashcard" type="button"></button>
      </div>
    </div>
  `);

  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate((bootstrap) => {
    window.__LLFlashcardsMainLoaded = false;
    window.__shownWordIds = [];
    window.__loadImageCalls = [];
    window.__targetSelectionCalls = 0;
    window.__currentTarget = null;
    window.__targets = [
      { id: 501, title: 'Broken first image', image: bootstrap.brokenImage, __categoryName: 'Kitchen' },
      { id: 502, title: 'Loaded next image', image: bootstrap.goodImage, __categoryName: 'Kitchen' }
    ];

    window.llToolsFlashcardsData = {
      debug: false,
      firstCategoryName: 'Kitchen',
      imageSize: 'small',
      categories: [
        { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'text_title', option_type: 'image' }
      ],
      modeUi: {},
      isUserLoggedIn: false
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', fastTransitions: true };

    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.Util = {
      randomlySort(items) {
        return Array.isArray(items) ? items.slice() : [];
      },
      promptTypeHasAudio(promptType) {
        const normalized = String(promptType || '').trim().toLowerCase();
        return normalized === 'audio' || normalized === 'audio_text_title' || normalized === 'audio_text_translation' || normalized === 'image_audio';
      },
      promptTypeHasImage(promptType) {
        const normalized = String(promptType || '').trim().toLowerCase();
        return normalized === 'image' || normalized === 'image_audio' || normalized === 'image_text_title' || normalized === 'image_text_translation';
      },
      promptTypeHasText(promptType) {
        return String(promptType || '').trim().toLowerCase() === 'text_title';
      },
      getPromptTextType(promptType) {
        return String(promptType || '').trim().toLowerCase() === 'text_title' ? 'text_title' : '';
      },
      optionTypeHasImage(optionType) {
        const normalized = String(optionType || '').trim().toLowerCase();
        return normalized === 'image' || normalized === 'image_text_translation';
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
    window.LLFlashcards.Effects = { startConfetti() {} };
    window.LLFlashcards.Selection = {
      isLearningSupportedForCategories() { return true; },
      isGenderSupportedForCategories() { return false; },
      getCategoryConfig() {
        return { option_type: 'image', prompt_type: 'text_title', learning_supported: true };
      },
      getCurrentDisplayMode() { return 'image'; },
      getTargetCategoryName(word) {
        return (word && word.__categoryName) || 'Kitchen';
      },
      selectTargetWordAndCategory() {
        window.__targetSelectionCalls += 1;
        const next = window.__targets.length ? window.__targets.shift() : null;
        window.__currentTarget = next;
        return next;
      },
      fillQuizOptions(targetWord) {
        const $container = window.jQuery('#ll-tools-flashcard');
        $container.empty();
        [
          { id: targetWord.id, title: targetWord.title, image: targetWord.image },
          { id: 900, title: 'Distractor', image: bootstrap.goodImage }
        ].forEach((word) => {
          window.jQuery('<div class="flashcard-container ll-answer-option-image-card"></div>')
            .attr('data-word-id', String(word.id))
            .append(window.jQuery('<img class="quiz-image" alt="" aria-hidden="true">').attr('src', word.image))
            .appendTo($container);
        });
        return Promise.resolve({ ready: true, failedWordIds: [] });
      }
    };
    window.LLFlashcards.Cards = {};
    window.LLFlashcards.Results = {
      hideResults() {},
      showResults() {}
    };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {};

    window.FlashcardOptions = {
      initializeOptionsCount() {},
      calculateNumberOfOptions() { return 2; },
      categoryOptionsCount: { Kitchen: 2 }
    };
    window.FlashcardLoader = {
      loadAudio() {},
      loadResourcesForCategory() {},
      loadResourcesForWord() {
        return Promise.resolve({ ready: true, audioReady: true, imageReady: true });
      },
      loadImage(url, opts) {
        window.__loadImageCalls.push({
          url: String(url || ''),
          forceRetry: !!(opts && opts.forceRetry)
        });
        return Promise.resolve({
          ready: !String(url || '').includes('this-is-not-a-valid-image'),
          url: String(url || '')
        });
      }
    };
    window.FlashcardAudio = {
      initializeAudio() {},
      getCorrectAudioURL() { return ''; },
      getWrongAudioURL() { return ''; },
      playFeedback(isCorrect, audioUrl, callback) {
        if (typeof callback === 'function') callback();
      },
      pauseAllAudio() {},
      setTargetAudioHasPlayed() {},
      setTargetWordAudio() {},
      getCurrentTargetAudio() { return null; }
    };

    const state = window.LLFlashcards.State;
    state.widgetActive = true;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.isFirstRound = false;
    state.categoryNames = ['Kitchen'];
    state.initialCategoryNames = ['Kitchen'];
    state.wordsByCategory = {
      Kitchen: window.__targets.slice()
    };
    state.currentCategoryName = 'Kitchen';
    state.currentCategory = state.wordsByCategory.Kitchen;
    window.wordsByCategory = state.wordsByCategory;
    window.categoryNames = state.categoryNames;
    window.categoryRoundCount = state.categoryRoundCount;
  }, { goodImage, brokenImage });

  await page.addScriptTag({ content: mainSource });

  await page.evaluate(() => {
    window.LLFlashcards.State.onStateChange((nextState) => {
      if (nextState === window.LLFlashcards.State.STATES.SHOWING_QUESTION && window.__currentTarget) {
        window.__shownWordIds.push(Number(window.__currentTarget.id) || 0);
      }
    });
  });
}

async function mountPracticeProgressHarness(page, options = {}) {
  const uniqueTargets = Array.isArray(options.targets) && options.targets.length
    ? options.targets
    : [
        { id: 501, title: 'Cup', __categoryName: 'Kitchen' },
        { id: 502, title: 'Plate', __categoryName: 'Kitchen' }
      ];
  const roundTargets = Array.isArray(options.roundTargets) && options.roundTargets.length
    ? options.roundTargets
    : uniqueTargets.slice();
  const categories = Array.isArray(options.categories) && options.categories.length
    ? options.categories
    : [
        { id: 11, name: 'Kitchen', slug: 'kitchen', prompt_type: 'image', option_type: 'text', word_count: uniqueTargets.length }
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
        Kitchen: uniqueTargets.slice()
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
    window.__progressDisplayRatios = [];
    window.__restoreHeaderOptions = [];
    window.__showResultsCount = 0;
    window.__currentTarget = null;
    window.__targets = bootstrap.roundTargets.slice();

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
      restoreHeaderUI(options) {
        window.__restoreHeaderOptions.push(Object.assign({}, options || {}));
      },
      showLoading() {},
      hideLoading() { return Promise.resolve(); },
      hideLoadingImmediately() { return Promise.resolve(); },
      setRepeatButton() {},
      updateCategoryNameDisplay() {},
      enableRepeatButton() {},
      disableRepeatButton() {},
      bindRepeatButtonAudio() {},
      hideAutoplayBlockedOverlay() {},
      updateSimpleProgress(currentCount, totalCount, progressOptions) {
        const total = Math.max(0, Number(totalCount) || 0);
        const current = Math.max(0, Number(currentCount) || 0);
        const clampedCurrent = total > 0 ? Math.min(total, current) : 0;
        const rawRatio = total > 0 ? (clampedCurrent / total) : 0;
        const minDisplayRatio = Number(progressOptions && progressOptions.minDisplayRatio);
        const displayRatio = Number.isFinite(minDisplayRatio)
          ? Math.max(rawRatio, Math.max(0, Math.min(1, minDisplayRatio)))
          : rawRatio;
        window.__progressCalls.push({
          current,
          total
        });
        window.__progressDisplayRatios.push(displayRatio);
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
        window.jQuery('<button class="flashcard-container wrong-card" type="button"></button>')
          .attr('data-word-id', `${targetWord.id}-wrong`)
          .appendTo($container);
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
      calculateNumberOfOptions() { return 2; },
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
      playFeedback(isCorrect, audioUrl, callback) {
        if (typeof callback === 'function') {
          callback();
        }
      },
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
    roundTargets,
    categories,
    sessionWordIds,
    categoryNames,
    initialCategoryNames,
    wordsByCategory,
    currentCategoryName
  });

  await page.addScriptTag({ content: mainSource });
}

async function mountBoundedCategoryLabelHarness(page, options = {}) {
  const categoryDisplayOverride = String(options.categoryDisplayOverride || '');
  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push(String((error && error.message) || error || 'unknown page error'));
  });

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <div id="ll-tools-flashcard-header"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="ll-tools-category-stack">
          <div id="ll-tools-category-display">Travel</div>
          <button id="ll-tools-repeat-flashcard" type="button"></button>
        </div>
        <div id="ll-tools-flashcard-content"></div>
        <div id="ll-tools-flashcard"></div>
      </div>
    </div>
    <div id="ll-tools-loading-animation" style="display:none;"></div>
    <div id="ll-tools-loading-status" hidden></div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate((bootstrap) => {
    const noop = function () {};
    const travelWords = [
      { id: 1101, title: 'Ticket', __categoryName: 'Travel' },
      { id: 1102, title: 'Passport', __categoryName: 'Travel' }
    ];
    const animalWords = [
      { id: 2201, title: 'Cat', __categoryName: 'Animals' },
      { id: 2202, title: 'Dog', __categoryName: 'Animals' }
    ];

    window.__LLFlashcardsMainLoaded = false;
    window.llToolsFlashcardsData = {
      categories: [
        { id: 11, name: 'Travel', slug: 'travel', word_count: travelWords.length },
        { id: 22, name: 'Animals', slug: 'animals', word_count: animalWords.length }
      ],
      categoryDisplayOverride: bootstrap.categoryDisplayOverride,
      category_display_override: bootstrap.categoryDisplayOverride,
      sessionWordIds: animalWords.map((word) => word.id),
      session_word_ids: animalWords.map((word) => word.id),
      logicalSessionWordIds: travelWords.concat(animalWords).map((word) => word.id),
      logical_session_word_ids: travelWords.concat(animalWords).map((word) => word.id),
      logicalSessionTotal: travelWords.length + animalWords.length,
      logical_session_total: travelWords.length + animalWords.length,
      isUserLoggedIn: true,
      modeUi: {}
    };
    window.llToolsFlashcardsMessages = {};
    window.llToolsStudyPrefs = {
      starredWordIds: animalWords.map((word) => word.id),
      starred_word_ids: animalWords.map((word) => word.id),
      starMode: 'only',
      star_mode: 'only',
      fastTransitions: true
    };

    const state = window.LLFlashcards.State;
    state.widgetActive = true;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.isFirstRound = false;
    state.isLearningMode = false;
    state.isListeningMode = false;
    state.isGenderMode = false;
    state.isSelfCheckMode = false;
    state.wordsByCategory = { Travel: travelWords.slice() };
    state.currentCategoryName = 'Travel';
    state.currentCategory = state.wordsByCategory.Travel;
    state.initialCategoryNames = ['Travel'];
    state.categoryNames = ['Travel'];
    state.completedCategories = { Travel: true };
    state.categoryRoundCount = { Travel: 2 };
    state.categoryRepetitionQueues = { Travel: [] };
    state.currentCategoryRoundCount = 2;
    state.quizResults = { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} };
    state.usedWordIDs = [];

    window.LLFlashcards.Util = {
      randomlySort(items) { return Array.isArray(items) ? items.slice() : []; },
      getCategoryDisplayLabel(name, fallback) { return String(name || fallback || ''); }
    };
    window.LLFlashcards.Effects = { startConfetti: noop };
    window.__boundedLabelRoundTarget = null;
    window.LLFlashcards.Selection = {
      getTargetCategoryName(word) {
        return String((word && word.__categoryName) || state.currentCategoryName || '');
      },
      selectTargetWordAndCategory() {
        return window.__boundedLabelRoundTarget;
      },
      getCategoryConfig() {
        return { option_type: 'text', prompt_type: 'image' };
      },
      getCurrentDisplayMode() { return 'text'; },
      fillQuizOptions(targetWord) {
        const container = window.jQuery('#ll-tools-flashcard').empty();
        window.jQuery('<button type="button" class="flashcard-container wrong-card"></button>')
          .attr('data-word-id', `${targetWord.id}-wrong`)
          .appendTo(container);
        window.jQuery('<button type="button" class="flashcard-container correct-card"></button>')
          .attr('data-word-id', targetWord.id)
          .appendTo(container);
        return Promise.resolve({ ready: true });
      }
    };
    window.LLFlashcards.Cards = {};
    window.LLFlashcards.Results = { hideResults: noop, showResults: noop };
    window.LLFlashcards.StateMachine = {};
    window.LLFlashcards.ModeConfig = {};
    window.LLFlashcards.Modes = {};

    window.FlashcardLoader = {
      loadedCategories: [],
      loadAudio: noop,
      loadResourcesForWord() {
        return Promise.resolve({ ready: true, audioReady: true, imageReady: true });
      },
      consumeBoundedPreloadedCategoryData(names) {
        state.wordsByCategory.Animals = animalWords.slice();
        return Promise.resolve({
          success: true,
          categories: Array.isArray(names) ? names.slice() : [],
          sessionWordIds: animalWords.map((word) => word.id)
        });
      }
    };
    window.FlashcardAudio = {
      initializeAudio: noop,
      getCorrectAudioURL() { return ''; },
      getWrongAudioURL() { return ''; },
      playFeedback(_isCorrect, _audioUrl, callback) {
        if (typeof callback === 'function') { callback(); }
      },
      pauseAllAudio: noop,
      setTargetAudioHasPlayed: noop,
      setTargetWordAudio: noop,
      getCurrentTargetAudio() { return null; }
    };
    window.FlashcardOptions = { initializeOptionsCount: noop };
    window.wordsByCategory = state.wordsByCategory;
    window.categoryNames = state.categoryNames;
  }, { categoryDisplayOverride });

  await page.addScriptTag({ content: domSource });
  await page.addScriptTag({ content: mainSource });
  if (!await page.evaluate(() => !!(window.LLFlashcards && window.LLFlashcards.Main))) {
    throw new Error(`Flashcard main failed to initialize: ${pageErrors.join(' | ')}`);
  }
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

test('mode switch cancels a bounded continuation before the replacement mode starts', async ({ page }) => {
  await mountRestartHarness(page);

  await page.evaluate(() => {
    window.__modeSwitchEvents = [];
    window.jQuery(document).on('lltools:flashcard-mode-switching.test', (_event, detail) => {
      window.__modeSwitchEvents.push(String((detail && detail.mode) || ''));
    });
    window.llToolsFlashcardsData.boundedSessionContinuation = () => Promise.resolve({ success: true });
    window.llToolsFlashcardsData.bounded_session_continuation = window.llToolsFlashcardsData.boundedSessionContinuation;
    window.LLFlashcards.Main.switchMode('practice');
  });

  await page.waitForTimeout(50);

  const result = await page.evaluate(() => ({
    hasCamelContinuation: typeof window.llToolsFlashcardsData.boundedSessionContinuation === 'function',
    hasSnakeContinuation: typeof window.llToolsFlashcardsData.bounded_session_continuation === 'function',
    events: window.__modeSwitchEvents.slice()
  }));
  expect(result).toEqual({
    hasCamelContinuation: false,
    hasSnakeContinuation: false,
    events: ['practice']
  });
});

test('mode switch fences late bounded continuation fulfillment and rejection from the replacement mode', async ({ page }) => {
  for (const staleOutcome of ['resolve', 'reject']) {
    await mountRestartHarness(page);

    await page.evaluate(() => {
      window.__staleContinuationSettle = null;
      window.__boundedAppendCalls = 0;
      window.FlashcardLoader.consumeBoundedPreloadedCategoryData = () => {
        window.__boundedAppendCalls += 1;
        return { success: true };
      };
      const staleContinuation = new Promise((resolve, reject) => {
        window.__staleContinuationSettle = { resolve, reject };
      });
      window.llToolsFlashcardsData.boundedSessionContinuation = () => staleContinuation;
      window.LLFlashcards.Main.tryContinueLogicalSession();
      window.LLFlashcards.Main.switchMode('practice');
    });

    await page.waitForTimeout(50);

    const replacementAccepted = await page.evaluate(() => {
      window.__replacementContinuationCalls = 0;
      window.__replacementContinuation = new Promise(() => {});
      window.llToolsFlashcardsData.boundedSessionContinuation = () => {
        window.__replacementContinuationCalls += 1;
        return window.__replacementContinuation;
      };
      return window.LLFlashcards.Main.tryContinueLogicalSession();
    });
    expect(replacementAccepted).toBe(true);
    await page.waitForFunction(() => window.__replacementContinuationCalls === 1);

    await page.evaluate((outcome) => {
      if (outcome === 'reject') {
        window.__staleContinuationSettle.reject(new Error('stale continuation failed'));
        return;
      }
      window.__staleContinuationSettle.resolve({ success: true });
    }, staleOutcome);
    await page.waitForTimeout(20);

    const acceptedAgain = await page.evaluate(() => window.LLFlashcards.Main.tryContinueLogicalSession());
    expect(acceptedAgain).toBe(true);
    await page.waitForTimeout(20);

    const state = await page.evaluate(() => {
      const results = document.getElementById('quiz-results');
      return {
        replacementCalls: window.__replacementContinuationCalls,
        boundedAppendCalls: window.__boundedAppendCalls,
        flowState: window.LLFlashcards.State.getState(),
        widgetActive: !!window.LLFlashcards.State.widgetActive,
        resultsVisible: window.getComputedStyle(results).display !== 'none',
        errorState: results.classList.contains('ll-tools-error-state')
      };
    });
    expect(state).toEqual({
      replacementCalls: 1,
      boundedAppendCalls: 0,
      flowState: 'loading',
      widgetActive: true,
      resultsVisible: false,
      errorState: false
    });
  }
});

test('armed bounded Gender plan bypasses a stale category support shell', async ({ page }) => {
  await mountRestartHarness(page);

  const result = await page.evaluate(async () => {
    window.__genderInitializeCalls = 0;
    window.LLFlashcards.Selection.isGenderSupportedForCategories = () => false;
    window.FlashcardLoader.loadResourcesForCategory = () => Promise.resolve({ success: true });
    window.LLFlashcards.Modes.Gender = {
      initialize() {
        window.__genderInitializeCalls += 1;
        return true;
      },
      selectTargetWord() { return null; },
      handleNoTarget() { return true; }
    };
    window.llToolsFlashcardsData.genderSessionPlan = {
      level: 1,
      word_ids: [501],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    window.llToolsFlashcardsData.genderSessionPlanArmed = true;
    window.llToolsFlashcardsData.gender_session_plan_armed = true;
    window.llToolsFlashcardsData.genderLaunchSource = 'dashboard';

    await window.LLFlashcards.Main.initFlashcardWidget(['Kitchen'], 'gender');
    return {
      isGenderMode: !!window.LLFlashcards.State.isGenderMode,
      isPracticeMode: !window.LLFlashcards.State.isLearningMode
        && !window.LLFlashcards.State.isListeningMode
        && !window.LLFlashcards.State.isGenderMode
        && !window.LLFlashcards.State.isSelfCheckMode,
      initializeCalls: window.__genderInitializeCalls
    };
  });

  expect(result).toEqual({
    isGenderMode: true,
    isPracticeMode: false,
    initializeCalls: 1
  });
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

test('bounded continuation error renders a localized retry state and clears it on Retry', async ({ page }) => {
  await mountRestartHarness(page);

  await page.evaluate(() => {
    window.llToolsFlashcardsMessages = {
      loadingError: 'Bir şeyler ters gitti',
      errorDetailsLabel: 'Hata ayrıntıları',
      sessionContinuationError: 'Lütfen tekrar deneyin.',
      noContentAvailable: 'Gösterilecek içerik yok.',
      retry: 'Yeniden Dene'
    };
    window.__continuationCalls = 0;
    window.__pendingRetry = new Promise(() => {});
    window.llToolsFlashcardsData.boundedSessionContinuation = () => {
      window.__continuationCalls += 1;
      return window.__continuationCalls === 1
        ? Promise.reject(new Error('server hot'))
        : window.__pendingRetry;
    };
    window.LLFlashcards.Main.tryContinueLogicalSession();
  });

  await page.waitForFunction(() => (
    window.__continuationCalls === 1
    && window.LLFlashcards.State.getState() === 'showing_results'
  ));

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  const results = page.locator('#quiz-results');
  const retry = page.locator('#restart-quiz');
  await expect(results).toBeVisible();
  await expect(popup).toHaveClass(/ll-tools-error-state/);
  await expect(results).toHaveClass(/ll-tools-error-state/);
  await expect(page.locator('#quiz-results-title')).toHaveText('Bir şeyler ters gitti');
  await expect(page.locator('#quiz-results-message')).toHaveText('Hata ayrıntıları: Lütfen tekrar deneyin.');
  await expect(page.locator('#quiz-results-message')).not.toContainText('Gösterilecek içerik yok.');
  await expect(retry).toBeVisible();
  await expect(retry).toHaveText('Yeniden Dene');
  await expect(page.locator('.ll-quiz-results-score')).toBeHidden();
  await expect(page.locator('#quiz-mode-buttons')).toBeHidden();
  await expect(page.locator('#ll-tools-learning-progress')).toBeHidden();
  await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeHidden();

  await retry.click();
  await page.waitForFunction(() => (
    window.__continuationCalls === 2
    && window.LLFlashcards.State.getState() === 'loading'
  ));
  await expect(results).toBeHidden();
  await expect(popup).not.toHaveClass(/ll-tools-error-state/);
  await expect(results).not.toHaveClass(/ll-tools-error-state/);
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

test('practice round transitions preserve the visible progress bar', async ({ page }) => {
  await mountPracticeProgressHarness(page);

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const restoreOptions = await page.evaluate(() => window.__restoreHeaderOptions.slice());
  expect(restoreOptions.at(-1)).toEqual({ preserveProgress: true });
});

test('bounded in-progress continuation replaces the generic session label with the actual next category', async ({ page }) => {
  await mountBoundedCategoryLabelHarness(page, {
    categoryDisplayOverride: 'In progress words'
  });

  const initialState = await page.evaluate(() => {
    const label = document.getElementById('ll-tools-category-display');
    label.textContent = 'Travel';
    return {
      category: window.LLFlashcards.State.currentCategoryName,
      label: label.textContent
    };
  });
  expect(initialState).toEqual({ category: 'Travel', label: 'Travel' });

  const continuation = await page.evaluate(async () => {
    const result = await window.LLFlashcards.Main.appendBoundedSelectionChunk(['Animals']);
    return {
      result,
      category: window.LLFlashcards.State.currentCategoryName,
      label: document.getElementById('ll-tools-category-display').textContent,
      sessionLabel: String(window.llToolsFlashcardsData.categoryDisplayOverride || '')
    };
  });

  expect(continuation.result).toEqual({
    success: true,
    categories: ['Animals'],
    sessionWordIds: [2201, 2202]
  });
  expect(continuation.category).toBe('Animals');
  expect(continuation.label).toBe('Animals');
  expect(continuation.sessionLabel).toBe('In progress words');
});

test('bounded starred continuation refreshes the actual category instead of retaining the prior round label', async ({ page }) => {
  await mountBoundedCategoryLabelHarness(page);

  await page.locator('#ll-tools-category-display').evaluate((element) => {
    element.textContent = 'Travel';
  });
  const continuation = await page.evaluate(async () => {
    const result = await window.LLFlashcards.Main.appendBoundedSelectionChunk(['Animals']);
    return {
      result,
      category: window.LLFlashcards.State.currentCategoryName,
      label: document.getElementById('ll-tools-category-display').textContent
    };
  });

  expect(continuation.result.success).toBeTruthy();
  expect(continuation.category).toBe('Animals');
  expect(continuation.label).toBe('Animals');

  await page.evaluate(() => {
    document.getElementById('ll-tools-category-display').textContent = 'Travel';
    const state = window.LLFlashcards.State;
    window.__boundedLabelRoundTarget = state.wordsByCategory.Animals[0];
    state.forceTransitionTo(state.STATES.QUIZ_READY, 'Prepare category-label round guard');
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');
  await expect(page.locator('#ll-tools-category-display')).toHaveText('Animals');
});

test('bounded listening continuation is accepted and delegated to the active listening sequence', async ({ page }) => {
  await mountBoundedCategoryLabelHarness(page, {
    categoryDisplayOverride: 'In progress words'
  });

  const continuation = await page.evaluate(async () => {
    const state = window.LLFlashcards.State;
    state.isListeningMode = true;
    state.isLearningMode = false;
    state.isGenderMode = false;
    state.isSelfCheckMode = false;
    window.__listeningAppendCalls = [];
    window.LLFlashcards.Modes.Listening = {
      appendBoundedSelectionChunk(categoryNames) {
        window.__listeningAppendCalls.push(Array.isArray(categoryNames) ? categoryNames.slice() : []);
        return true;
      }
    };

    const result = await window.LLFlashcards.Main.appendBoundedSelectionChunk(['Animals']);
    return {
      result,
      appendCalls: window.__listeningAppendCalls.slice(),
      currentCategory: state.currentCategoryName,
      totalWordCount: state.totalWordCount
    };
  });

  expect(continuation.result).toEqual({
    success: true,
    categories: ['Animals'],
    sessionWordIds: [2201, 2202]
  });
  expect(continuation.appendCalls).toEqual([['Animals']]);
  expect(continuation.currentCategory).toBe('Animals');
  expect(continuation.totalWordCount).toBe(4);
});

test('header restoration leaves practice progress visible when requested', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-quiz-popup"></div>
    <div id="ll-tools-flashcard-content"></div>
    <div id="ll-tools-flashcard-header"></div>
    <div id="ll-tools-learning-progress"></div>
    <div id="ll-tools-category-stack"></div>
    <div id="ll-tools-category-display"></div>
    <button id="ll-tools-repeat-flashcard" type="button"></button>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.LLFlashcards = {
      State: { isSelfCheckMode: false },
      Util: {}
    };
    window.llToolsFlashcardsData = {};
  });
  await page.addScriptTag({ content: domSource });

  const display = await page.evaluate(() => {
    const progress = document.getElementById('ll-tools-learning-progress');
    window.LLFlashcards.Dom.updateSimpleProgress(1, 3);
    window.LLFlashcards.Dom.restoreHeaderUI({ preserveProgress: true });
    return window.getComputedStyle(progress).display;
  });

  expect(display).not.toBe('none');
});

test('practice progress reopens a completed word while its wrong-answer replay is pending', async ({ page }) => {
  const cup = { id: 501, title: 'Cup', __categoryName: 'Kitchen' };
  const plate = { id: 502, title: 'Plate', __categoryName: 'Kitchen' };

  await mountPracticeProgressHarness(page, {
    targets: [cup, plate],
    roundTargets: [cup, plate, cup, cup]
  });

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

  await answerCurrentRound();
  await page.waitForFunction(() => window.__currentTarget && window.__currentTarget.id === 502);

  await answerCurrentRound();
  await page.waitForFunction(() => window.__currentTarget && window.__currentTarget.id === 501);

  let progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  let displayRatios = await page.evaluate(() => window.__progressDisplayRatios.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 2, total: 2 });
  expect(displayRatios.at(-1)).toBe(1);

  await page.evaluate(() => {
    window.LLFlashcards.Main.onWrongAnswer(
      window.__currentTarget,
      0,
      window.jQuery('.wrong-card')
    );
  });
  await page.waitForFunction(() => window.__progressCalls.at(-1).current === 1);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  displayRatios = await page.evaluate(() => window.__progressDisplayRatios.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 1, total: 2 });
  expect(displayRatios.at(-1)).toBe(0.5);

  await answerCurrentRound();
  await page.waitForFunction(() => window.__currentTarget && window.__currentTarget.id === 501 && window.__targets.length === 0);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  displayRatios = await page.evaluate(() => window.__progressDisplayRatios.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 1, total: 2 });
  expect(displayRatios.at(-1)).toBe(0.5);

  await answerCurrentRound();
  await page.waitForFunction(() => window.__progressCalls.at(-1).current === 2);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  displayRatios = await page.evaluate(() => window.__progressDisplayRatios.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 2, total: 2 });
  expect(displayRatios.at(-1)).toBe(1);
});

test('practice progress waits for a clean replay round after a wrong guess', async ({ page }) => {
  const cup = { id: 501, title: 'Cup', __categoryName: 'Kitchen' };
  const plate = { id: 502, title: 'Plate', __categoryName: 'Kitchen' };

  await mountPracticeProgressHarness(page, {
    targets: [cup, plate],
    roundTargets: [cup, cup, plate]
  });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  await page.evaluate(() => {
    window.LLFlashcards.Main.onWrongAnswer(
      window.__currentTarget,
      0,
      window.jQuery('.wrong-card')
    );
  });
  await page.waitForTimeout(30);

  let progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 0, total: 2 });

  await page.evaluate(() => {
    window.LLFlashcards.Main.onCorrectAnswer(
      window.__currentTarget,
      window.jQuery('.correct-card')
    );
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 0, total: 2 });

  await page.evaluate(() => {
    window.LLFlashcards.Main.onCorrectAnswer(
      window.__currentTarget,
      window.jQuery('.correct-card')
    );
  });
  await page.waitForFunction(() => window.__progressCalls.length >= 4);

  progressCalls = await page.evaluate(() => window.__progressCalls.slice());
  expect(progressCalls.at(-1)).toEqual({ current: 1, total: 2 });
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

test('practice rounds skip an unready rendered image instead of showing a blank option', async ({ page }) => {
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });

  await page.waitForFunction(() => window.__shownWordIds.includes(502));

  const result = await page.evaluate(() => {
    const images = Array.from(document.querySelectorAll('#ll-tools-flashcard img'));
    return {
      shownWordIds: window.__shownWordIds.slice(),
      loadImageCalls: window.__loadImageCalls.slice(),
      currentTargetId: window.__currentTarget ? Number(window.__currentTarget.id) : 0,
      imageStates: images.map((img) => ({
        complete: img.complete,
        naturalWidth: img.naturalWidth,
        src: img.getAttribute('src') || ''
      }))
    };
  });

  expect(result.shownWordIds).toEqual([502]);
  expect(result.currentTargetId).toBe(502);
  expect(result.loadImageCalls.some((call) => call.forceRetry && call.url.includes('this-is-not-a-valid-image'))).toBe(true);
  expect(result.imageStates.every((img) => img.complete && img.naturalWidth > 0)).toBe(true);
});

test('practice rounds remove failed distractor images without dropping a healthy target', async ({ page }) => {
  const goodImage = fixtureImage('#dcfce7', 'OK');
  const brokenImage = 'data:image/png;base64,this-is-not-a-valid-image';
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate((bootstrap) => {
    const target = {
      id: 703,
      title: 'Healthy target',
      image: bootstrap.goodImage,
      __categoryName: 'Kitchen'
    };
    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];

    window.LLFlashcards.Selection.fillQuizOptions = (targetWord) => {
      const $container = window.jQuery('#ll-tools-flashcard').empty();
      [
        { id: targetWord.id, title: targetWord.title, image: targetWord.image },
        { id: 901, title: 'Broken visible distractor', image: bootstrap.brokenImage },
        { id: 902, title: 'Broken hidden distractor', image: bootstrap.brokenImage, hidden: true },
        { id: 903, title: 'Healthy distractor', image: bootstrap.goodImage }
      ].forEach((word) => {
        const $card = window.jQuery('<div class="flashcard-container ll-answer-option-image-card"></div>')
          .attr('data-word-id', String(word.id))
          .append(window.jQuery('<img class="quiz-image" alt="" aria-hidden="true">').attr('src', word.image));
        if (word.hidden) {
          $card.attr('hidden', 'hidden').css('visibility', 'hidden');
        }
        $card.appendTo($container);
      });
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
  }, { goodImage, brokenImage });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const result = await page.evaluate(() => ({
    shownWordIds: window.__shownWordIds.slice(),
    cardWordIds: Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((card) => Number(card.getAttribute('data-word-id'))),
    remainingWordIds: window.LLFlashcards.State.wordsByCategory.Kitchen.map((word) => Number(word.id)),
    imageStates: Array.from(document.querySelectorAll('#ll-tools-flashcard img')).map((img) => ({
      complete: img.complete,
      naturalWidth: img.naturalWidth
    }))
  }));

  expect(result.shownWordIds).toEqual([703]);
  expect(result.cardWordIds).toEqual([703, 903]);
  expect(result.remainingWordIds).toEqual([703]);
  expect(result.imageStates.every((img) => img.complete && img.naturalWidth > 0)).toBe(true);
});

test('practice rounds refill failed distractors from the hydrated reserve without resetting progress', async ({ page }) => {
  const goodImage = fixtureImage('#dcfce7', 'OK');
  const brokenImage = 'data:image/png;base64,this-is-not-a-valid-image';
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate((bootstrap) => {
    const target = {
      id: 704,
      title: 'Healthy target',
      image: bootstrap.goodImage,
      __categoryName: 'Kitchen'
    };
    const brokenDistractors = [904, 905, 906, 907, 908].map((id) => ({
      id,
      title: `Broken distractor ${id}`,
      image: bootstrap.brokenImage
    }));
    const reserveDistractors = [909, 910].map((id) => ({
      id,
      title: `Healthy reserve ${id}`,
      image: bootstrap.goodImage
    }));
    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];
    window.__optionFillCalls = [];

    window.LLFlashcards.Selection.fillQuizOptions = (targetWord, options = {}) => {
      const $container = window.jQuery('#ll-tools-flashcard').empty();
      const excludedIds = Array.isArray(options.excludedOptionWordIds)
        ? options.excludedOptionWordIds.map((id) => String(id))
        : [];
      window.__optionFillCalls.push(excludedIds);
      const distractors = excludedIds.length ? reserveDistractors : brokenDistractors;
      [{ id: targetWord.id, title: targetWord.title, image: targetWord.image }, ...distractors].forEach((word) => {
        window.jQuery('<div class="flashcard-container ll-answer-option-image-card"></div>')
          .attr('data-word-id', String(word.id))
          .append(window.jQuery('<img class="quiz-image" alt="" aria-hidden="true">').attr('src', word.image))
          .appendTo($container);
      });
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    state.usedWordIDs = Array.from({ length: 100 }, (_, index) => index + 1);
    state.categoryRoundCount = { Kitchen: 100 };
    state.totalWordCount = 1600;
    state.quizResults = {
      correctOnFirstTry: 73,
      incorrect: [42],
      wordAttempts: {
        42: { seen: 2, clean: 1, hadWrong: true, needsCleanReplay: false }
      }
    };
    state.categoryRepetitionQueues = {
      Kitchen: [{ wordData: { id: 42 }, reappearRound: 104, forceReplay: true, needsCleanReplay: true }]
    };
    window.FlashcardOptions.categoryOptionsCount.Kitchen = 6;
    window.wordsByCategory = state.wordsByCategory;
  }, { goodImage, brokenImage });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const result = await page.evaluate(() => ({
    flowState: window.LLFlashcards.State.getState(),
    shownWordIds: window.__shownWordIds.slice(),
    targetSelectionCalls: window.__targetSelectionCalls,
    optionFillCalls: window.__optionFillCalls.map((ids) => ids.slice()),
    cardWordIds: Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((card) => Number(card.getAttribute('data-word-id'))),
    imageStates: Array.from(document.querySelectorAll('#ll-tools-flashcard img')).map((img) => ({
      complete: img.complete,
      naturalWidth: img.naturalWidth
    })),
    usedWordIDs: window.LLFlashcards.State.usedWordIDs.slice(),
    categoryRoundCount: Object.assign({}, window.LLFlashcards.State.categoryRoundCount),
    totalWordCount: window.LLFlashcards.State.totalWordCount,
    quizResults: JSON.parse(JSON.stringify(window.LLFlashcards.State.quizResults)),
    categoryRepetitionQueues: JSON.parse(JSON.stringify(window.LLFlashcards.State.categoryRepetitionQueues)),
    errorState: document.querySelector('#ll-tools-flashcard-quiz-popup').classList.contains('ll-tools-error-state')
  }));

  expect(result.flowState).toBe('showing_question');
  expect(result.shownWordIds).toEqual([704]);
  expect(result.targetSelectionCalls).toBe(1);
  expect(result.optionFillCalls).toEqual([[], ['904', '905', '906', '907', '908']]);
  expect(result.cardWordIds).toEqual([704, 909, 910]);
  expect(result.imageStates.every((image) => image.complete && image.naturalWidth > 0)).toBe(true);
  expect(result.usedWordIDs).toEqual(Array.from({ length: 100 }, (_, index) => index + 1));
  expect(result.categoryRoundCount).toEqual({ Kitchen: 100 });
  expect(result.totalWordCount).toBe(1600);
  expect(result.quizResults).toEqual({
    correctOnFirstTry: 73,
    incorrect: [42],
    wordAttempts: {
      42: { seen: 2, clean: 1, hadWrong: true, needsCleanReplay: false }
    }
  });
  expect(result.categoryRepetitionQueues).toEqual({
    Kitchen: [{ wordData: { id: 42 }, reappearRound: 104, forceReplay: true, needsCleanReplay: true }]
  });
  expect(result.errorState).toBe(false);
});

test('practice rounds limit failed distractor refills to one bounded attempt', async ({ page }) => {
  const goodImage = fixtureImage('#dcfce7', 'OK');
  const brokenImage = 'data:image/png;base64,this-is-not-a-valid-image';
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate((bootstrap) => {
    const target = {
      id: 711,
      title: 'Healthy target without a ready reserve',
      image: bootstrap.goodImage,
      __categoryName: 'Kitchen'
    };
    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];
    window.__optionFillCalls = [];

    window.LLFlashcards.Selection.fillQuizOptions = (targetWord, options = {}) => {
      const excludedIds = Array.isArray(options.excludedOptionWordIds)
        ? options.excludedOptionWordIds.map((id) => String(id))
        : [];
      window.__optionFillCalls.push(excludedIds);
      const failedId = excludedIds.length ? 912 : 911;
      const $container = window.jQuery('#ll-tools-flashcard').empty();
      [
        { id: targetWord.id, title: targetWord.title, image: targetWord.image },
        { id: failedId, title: 'Still broken', image: bootstrap.brokenImage }
      ].forEach((word) => {
        window.jQuery('<div class="flashcard-container ll-answer-option-image-card"></div>')
          .attr('data-word-id', String(word.id))
          .append(window.jQuery('<img class="quiz-image" alt="" aria-hidden="true">').attr('src', word.image))
          .appendTo($container);
      });
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
  }, { goodImage, brokenImage });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_results');
  await page.waitForTimeout(100);

  const result = await page.evaluate(() => ({
    flowState: window.LLFlashcards.State.getState(),
    shownWordIds: window.__shownWordIds.slice(),
    optionFillCalls: window.__optionFillCalls.map((ids) => ids.slice()),
    remainingWordIds: window.LLFlashcards.State.wordsByCategory.Kitchen.map((word) => Number(word.id)),
    errorState: document.querySelector('#ll-tools-flashcard-quiz-popup').classList.contains('ll-tools-error-state')
  }));

  expect(result.flowState).toBe('showing_results');
  expect(result.shownWordIds).toEqual([]);
  expect(result.optionFillCalls).toEqual([[], ['911']]);
  expect(result.remainingWordIds).toEqual([711]);
  expect(result.errorState).toBe(true);
});

test('image retry rechecks mounted prompt audio before remounting it', async ({ page }) => {
  const goodImage = fixtureImage('#dcfce7', 'OK');
  const brokenImage = 'data:image/png;base64,this-is-not-a-valid-image';
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate((bootstrap) => {
    const target = {
      id: 705,
      title: 'Audio target',
      audio: 'https://media.test/audio-target.m4a',
      image: bootstrap.goodImage,
      __categoryName: 'Kitchen'
    };
    const mountedAudio = { readyState: 0, error: null };
    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];
    window.__targetMountCalls = 0;
    window.__audioWaitCalls = 0;
    window.__targetLoaderRetryCalls = 0;

    window.LLFlashcards.Util.getPromptAudioUrl = (word) => String((word && word.audio) || '');
    window.LLFlashcards.Selection.getCategoryConfig = () => ({
      option_type: 'image',
      prompt_type: 'audio',
      learning_supported: true
    });
    window.LLFlashcards.Selection.fillQuizOptions = (targetWord) => {
      const $container = window.jQuery('#ll-tools-flashcard').empty();
      [
        { id: targetWord.id, title: targetWord.title, image: targetWord.image },
        { id: 905, title: 'Initially broken distractor', image: bootstrap.brokenImage }
      ].forEach((word) => {
        window.jQuery('<div class="flashcard-container ll-answer-option-image-card"></div>')
          .attr('data-word-id', String(word.id))
          .append(window.jQuery('<img class="quiz-image" alt="" aria-hidden="true">').attr('src', word.image))
          .appendTo($container);
      });
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    window.FlashcardLoader.loadImage = (url, opts) => {
      window.__loadImageCalls.push({
        url: String(url || ''),
        forceRetry: !!(opts && opts.forceRetry)
      });
      const broken = Array.from(document.querySelectorAll('#ll-tools-flashcard img'))
        .find((img) => String(img.getAttribute('src') || '').includes('this-is-not-a-valid-image'));
      if (!broken) {
        return Promise.resolve({ ready: false, url: String(url || '') });
      }
      return new Promise((resolve) => {
        const finish = () => resolve({ ready: true, url: bootstrap.goodImage });
        broken.addEventListener('load', finish, { once: true });
        broken.setAttribute('src', bootstrap.goodImage);
        if (broken.complete && broken.naturalWidth > 0) finish();
      });
    };
    window.FlashcardLoader.loadAudio = () => {
      window.__targetLoaderRetryCalls += 1;
      return Promise.resolve({ ready: false, attempts: 1 });
    };
    window.FlashcardAudio.setTargetWordAudio = () => {
      window.__targetMountCalls += 1;
      return Promise.resolve(mountedAudio);
    };
    window.FlashcardAudio.getCurrentTargetAudio = () => mountedAudio;
    window.FlashcardAudio.waitForAudioPlayable = (audio) => {
      window.__audioWaitCalls += 1;
      if (window.__audioWaitCalls === 1) return Promise.resolve(false);
      return new Promise((resolve) => {
        setTimeout(() => {
          audio.readyState = 3;
          resolve(true);
        }, 20);
      });
    };

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
  }, { goodImage, brokenImage });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const result = await page.evaluate(() => ({
    shownWordIds: window.__shownWordIds.slice(),
    targetMountCalls: window.__targetMountCalls,
    audioWaitCalls: window.__audioWaitCalls,
    targetLoaderRetryCalls: window.__targetLoaderRetryCalls,
    remainingWordIds: window.LLFlashcards.State.wordsByCategory.Kitchen.map((word) => Number(word.id))
  }));

  expect(result.shownWordIds).toEqual([705]);
  expect(result.targetMountCalls).toBe(1);
  expect(result.audioWaitCalls).toBe(2);
  expect(result.targetLoaderRetryCalls).toBe(0);
  expect(result.remainingWordIds).toEqual([705]);
});

test('practice round trusts playable mounted prompt audio when the preload probe reports false', async ({ page }) => {
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate(() => {
    const target = {
      id: 701,
      title: 'Horse',
      audio: 'https://media.test/horse.m4a',
      __categoryName: 'Kitchen'
    };
    const mountedAudio = {
      readyState: 3,
      error: null
    };

    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];
    window.__targetPreloadCalls = 0;
    window.__targetPreloadOptions = null;
    window.__targetRetryCalls = 0;

    window.LLFlashcards.Util.getPromptAudioUrl = (word) => String((word && word.audio) || '');
    window.LLFlashcards.Selection.getCategoryConfig = () => ({
      option_type: 'text',
      prompt_type: 'audio',
      learning_supported: true
    });
    window.LLFlashcards.Selection.getCurrentDisplayMode = () => 'text';
    window.LLFlashcards.Selection.selectTargetWordAndCategory = () => {
      const next = window.__targets.length ? window.__targets.shift() : null;
      window.__currentTarget = next;
      return next;
    };
    window.LLFlashcards.Selection.fillQuizOptions = (targetWord) => {
      window.jQuery('#ll-tools-flashcard')
        .empty()
        .append(
          window.jQuery('<div class="flashcard-container text-based"></div>')
            .attr('data-word-id', String(targetWord.id))
            .text(targetWord.title)
        );
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    window.FlashcardLoader.loadResourcesForWord = (word, mode, categoryName, config, options) => {
      window.__targetPreloadCalls += 1;
      window.__targetPreloadOptions = Object.assign({}, options || {});
      return Promise.resolve({
        ready: false,
        audioReady: false,
        imageReady: true,
        audio: { ready: false, attempts: 1 }
      });
    };
    window.FlashcardLoader.loadAudio = () => {
      window.__targetRetryCalls += 1;
      return Promise.resolve({ ready: false, attempts: 1 });
    };

    window.FlashcardAudio.setTargetWordAudio = () => Promise.resolve(mountedAudio);
    window.FlashcardAudio.getCurrentTargetAudio = () => mountedAudio;
    window.FlashcardAudio.waitForAudioPlayable = (audio) => Promise.resolve(
      !!(audio && audio.readyState >= 2 && !audio.error)
    );

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
  });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const result = await page.evaluate(() => ({
    shownWordIds: window.__shownWordIds.slice(),
    targetPreloadCalls: window.__targetPreloadCalls,
    targetPreloadOptions: window.__targetPreloadOptions,
    targetRetryCalls: window.__targetRetryCalls,
    remainingWordIds: window.LLFlashcards.State.wordsByCategory.Kitchen.map((word) => Number(word.id))
  }));

  expect(result.shownWordIds).toEqual([701]);
  expect(result.targetPreloadCalls).toBe(1);
  expect(result.targetPreloadOptions).toEqual({
    audioSource: 'prompt',
    skipAudioPreload: true
  });
  expect(result.targetRetryCalls).toBe(0);
  expect(result.remainingWordIds).toEqual([701]);
});

test('prompt-audio retry remounts the foreground element without another preload probe', async ({ page }) => {
  await mountRenderedImageReadinessHarness(page);

  await page.evaluate(() => {
    const target = {
      id: 702,
      title: 'Donkey',
      audio: 'https://media.test/donkey.m4a',
      __categoryName: 'Kitchen'
    };
    const initialAudio = { readyState: 0, error: null };
    const retryAudio = { readyState: 3, error: null };

    window.__targets = [target];
    window.__currentTarget = null;
    window.__shownWordIds = [];
    window.__mountedTargetAudio = null;
    window.__targetMountCalls = 0;
    window.__targetLoaderRetryCalls = 0;

    window.LLFlashcards.Util.getPromptAudioUrl = (word) => String((word && word.audio) || '');
    window.LLFlashcards.Selection.getCategoryConfig = () => ({
      option_type: 'text',
      prompt_type: 'audio',
      learning_supported: true
    });
    window.LLFlashcards.Selection.getCurrentDisplayMode = () => 'text';
    window.LLFlashcards.Selection.selectTargetWordAndCategory = () => {
      const next = window.__targets.length ? window.__targets.shift() : null;
      window.__currentTarget = next;
      return next;
    };
    window.LLFlashcards.Selection.fillQuizOptions = (targetWord) => {
      window.jQuery('#ll-tools-flashcard')
        .empty()
        .append(
          window.jQuery('<div class="flashcard-container text-based"></div>')
            .attr('data-word-id', String(targetWord.id))
            .text(targetWord.title)
        );
      return Promise.resolve({ ready: true, failedWordIds: [] });
    };

    window.FlashcardLoader.loadResourcesForWord = () => Promise.resolve({
      ready: true,
      audioReady: true,
      imageReady: true,
      audio: { ready: true, skipped: true }
    });
    window.FlashcardLoader.loadAudio = () => {
      window.__targetLoaderRetryCalls += 1;
      return Promise.resolve({ ready: false, attempts: 1 });
    };

    window.FlashcardAudio.setTargetWordAudio = () => {
      window.__targetMountCalls += 1;
      window.__mountedTargetAudio = window.__targetMountCalls === 1 ? initialAudio : retryAudio;
      return Promise.resolve(window.__mountedTargetAudio);
    };
    window.FlashcardAudio.getCurrentTargetAudio = () => window.__mountedTargetAudio;
    window.FlashcardAudio.waitForAudioPlayable = (audio) => Promise.resolve(
      !!(audio && audio.readyState >= 2 && !audio.error)
    );

    const state = window.LLFlashcards.State;
    state.currentFlowState = state.STATES.QUIZ_READY;
    state.wordsByCategory = { Kitchen: [target] };
    state.currentCategory = state.wordsByCategory.Kitchen;
    state.currentCategoryName = 'Kitchen';
    window.wordsByCategory = state.wordsByCategory;
  });

  await page.evaluate(() => {
    window.LLFlashcards.Main.runQuizRound();
  });
  await page.waitForFunction(() => window.LLFlashcards.State.getState() === 'showing_question');

  const result = await page.evaluate(() => ({
    shownWordIds: window.__shownWordIds.slice(),
    targetMountCalls: window.__targetMountCalls,
    targetLoaderRetryCalls: window.__targetLoaderRetryCalls,
    remainingWordIds: window.LLFlashcards.State.wordsByCategory.Kitchen.map((word) => Number(word.id))
  }));

  expect(result.shownWordIds).toEqual([702]);
  expect(result.targetMountCalls).toBe(2);
  expect(result.targetLoaderRetryCalls).toBe(0);
  expect(result.remainingWordIds).toEqual([702]);
});
