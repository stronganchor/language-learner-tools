const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const listeningScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/modes/listening.js');
const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');

test('listening initialize defers bulk category loads until after startup', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.__llCategoryLoadCalls = [];
    window.FlashcardLoader = {
      loadedCategories: [],
      loadResourcesForCategory: function (categoryName, callback) {
        window.__llCategoryLoadCalls.push(String(categoryName || ''));
        if (typeof callback === 'function') {
          callback();
        }
        return Promise.resolve({ success: true, category: String(categoryName || '') });
      }
    };

    window.llToolsFlashcardsData = {};
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: ['CatA', 'CatB', 'CatC', 'CatD'],
        wordsByCategory: {},
        wordsLinear: [],
        listeningHistory: [],
        listeningLoop: false,
        starModeOverride: null
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const Listening = window.LLFlashcards && window.LLFlashcards.Modes
      ? window.LLFlashcards.Modes.Listening
      : null;
    if (!Listening || typeof Listening.initialize !== 'function') {
      return { error: 'missing listening module' };
    }

    const initOk = Listening.initialize();
    return {
      initOk: !!initOk,
      categoryLoadCalls: Array.isArray(window.__llCategoryLoadCalls) ? window.__llCategoryLoadCalls.slice() : []
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.initOk).toBe(true);
  expect(result.categoryLoadCalls).toEqual([]);
});

test('listening progress uses estimated total while multi-category loads are still pending', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.FlashcardLoader = {
      loadedCategories: []
    };
    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false,
      lastLaunchPlan: {
        estimated_results_total: 90
      },
      categories: [
        { id: 1, name: 'CatA', count: 30 },
        { id: 2, name: 'CatB', count: 30 },
        { id: 3, name: 'CatC', count: 30 }
      ]
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: ['CatA', 'CatB', 'CatC'],
        wordsByCategory: {
          CatA: [{ id: 101, title: 'a1', label: 'a1' }],
          CatB: [],
          CatC: []
        },
        wordsLinear: Array.from({ length: 12 }, (_, idx) => ({ id: 1000 + idx, title: 'w' + idx, label: 'w' + idx })),
        listeningHistory: Array.from({ length: 4 }, (_, idx) => ({ id: 2000 + idx })),
        listeningLoop: false,
        starModeOverride: null,
        listenIndex: 4
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const Listening = window.LLFlashcards && window.LLFlashcards.Modes
      ? window.LLFlashcards.Modes.Listening
      : null;
    if (!Listening || typeof Listening.getProgressDisplayState !== 'function') {
      return { error: 'missing listening progress helper' };
    }

    const pendingProgress = Listening.getProgressDisplayState();

    window.LLFlashcards.State.wordsByCategory.CatB = [{ id: 102, title: 'b1', label: 'b1' }];
    window.LLFlashcards.State.wordsByCategory.CatC = [{ id: 103, title: 'c1', label: 'c1' }];
    window.LLFlashcards.State.wordsLinear = Array.from({ length: 36 }, (_, idx) => ({ id: 3000 + idx }));

    const loadedProgress = Listening.getProgressDisplayState();

    return { pendingProgress, loadedProgress };
  });

  expect(result.error).toBeUndefined();
  expect(result.pendingProgress).toMatchObject({
    current: 4,
    total: 90
  });
  expect(result.loadedProgress).toMatchObject({
    current: 4,
    total: 36
  });
});

test('listening sequence de-duplicates shared words unless weighted mode allows a second starred play', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {};
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: [],
        wordsByCategory: {},
        wordsLinear: [],
        listeningHistory: [],
        listeningLoop: false,
        starModeOverride: null
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const State = window.LLFlashcards.State;
    const Listening = window.LLFlashcards.Modes.Listening;

    if (!Listening || typeof Listening.initialize !== 'function') {
      return { error: 'missing listening module' };
    }

    State.wordsByCategory = {
      CatA: [
        { id: 101, title: 'alpha', label: 'alpha', audio: 'alpha.mp3', __categoryName: 'CatA' },
        { id: 202, title: 'beta', label: 'beta', audio: 'beta.mp3', __categoryName: 'CatA' }
      ],
      CatB: [
        { id: 101, title: 'alpha', label: 'alpha', audio: 'alpha.mp3', __categoryName: 'CatB' }
      ]
    };
    State.categoryNames = ['CatA', 'CatB'];
    State.starModeOverride = null;

    window.llToolsStudyPrefs = {
      starredWordIds: [101],
      starMode: 'normal',
      star_mode: 'normal'
    };
    Listening.initialize();
    const loopAfterInitialize = !!State.listeningLoop;
    const normalIds = (Array.isArray(State.wordsLinear) ? State.wordsLinear : [])
      .map((w) => Number(w && w.id) || 0)
      .filter((id) => id > 0);

    window.llToolsStudyPrefs = {
      starredWordIds: [101],
      starMode: 'weighted',
      star_mode: 'weighted'
    };
    Listening.initialize();
    const weightedIds = (Array.isArray(State.wordsLinear) ? State.wordsLinear : [])
      .map((w) => Number(w && w.id) || 0)
      .filter((id) => id > 0);

    State.listeningHistory = [];
    State.listenIndex = weightedIds.length;
    const endTarget = Listening.selectTargetWord();

    return { loopAfterInitialize, normalIds, weightedIds, endTarget: endTarget || null };
  });

  expect(result.error).toBeUndefined();
  expect(result.loopAfterInitialize).toBe(false);

  const normalCount101 = result.normalIds.filter((id) => id === 101).length;
  const normalCount202 = result.normalIds.filter((id) => id === 202).length;
  expect(normalCount101).toBe(1);
  expect(normalCount202).toBe(1);
  expect(result.normalIds.length).toBe(2);

  const weightedCount101 = result.weightedIds.filter((id) => id === 101).length;
  const weightedCount202 = result.weightedIds.filter((id) => id === 202).length;
  expect(weightedCount101).toBe(2);
  expect(weightedCount202).toBe(1);
  expect(result.weightedIds.length).toBe(3);
  expect(result.endTarget).toBeNull();
});

test('listening sequence keeps category words grouped in contiguous blocks', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {};
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: [],
        wordsByCategory: {},
        wordsLinear: [],
        listeningHistory: [],
        listeningLoop: false,
        starModeOverride: null
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const State = window.LLFlashcards.State;
    const Listening = window.LLFlashcards.Modes.Listening;

    if (!Listening || typeof Listening.initialize !== 'function') {
      return { error: 'missing listening module' };
    }

    State.wordsByCategory = {
      CatA: [
        { id: 101, title: 'a1', label: 'a1', audio: 'a1.mp3' },
        { id: 102, title: 'a2', label: 'a2', audio: 'a2.mp3' },
        { id: 103, title: 'a3', label: 'a3', audio: 'a3.mp3' }
      ],
      CatB: [
        { id: 201, title: 'b1', label: 'b1', audio: 'b1.mp3' },
        { id: 202, title: 'b2', label: 'b2', audio: 'b2.mp3' }
      ],
      CatC: [
        { id: 301, title: 'c1', label: 'c1', audio: 'c1.mp3' }
      ]
    };
    State.categoryNames = ['CatA', 'CatB', 'CatC'];
    State.starModeOverride = null;

    window.llToolsStudyPrefs = {
      starredWordIds: [],
      starMode: 'normal',
      star_mode: 'normal'
    };

    Listening.initialize();
    const linear = Array.isArray(State.wordsLinear) ? State.wordsLinear : [];
    const pairs = linear.map((word) => ({
      id: Number(word && word.id) || 0,
      cat: String((word && word.__categoryName) || '')
    })).filter((row) => row.id > 0 && row.cat !== '');

    const collapsedCats = [];
    pairs.forEach((row) => {
      if (!collapsedCats.length || collapsedCats[collapsedCats.length - 1] !== row.cat) {
        collapsedCats.push(row.cat);
      }
    });

    const positionsByCat = {};
    pairs.forEach((row, idx) => {
      if (!positionsByCat[row.cat]) {
        positionsByCat[row.cat] = [];
      }
      positionsByCat[row.cat].push(idx);
    });

    return {
      pairs,
      collapsedCats,
      positionsByCat
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.pairs.length).toBe(6);

  const collapsed = Array.isArray(result.collapsedCats) ? result.collapsedCats : [];
  const uniqueCollapsed = Array.from(new Set(collapsed));
  expect(collapsed.length).toBe(uniqueCollapsed.length);
  expect(uniqueCollapsed.sort()).toEqual(['CatA', 'CatB', 'CatC'].sort());

  const positionsByCat = result.positionsByCat || {};
  Object.keys(positionsByCat).forEach((cat) => {
    const positions = Array.isArray(positionsByCat[cat]) ? positionsByCat[cat] : [];
    if (!positions.length) {
      return;
    }
    const min = Math.min(...positions);
    const max = Math.max(...positions);
    expect((max - min + 1)).toBe(positions.length);
  });
});

test('rapid listening follows provided lesson order without weighted repeats', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      listeningRapidMode: true,
      orderedWordIds: [103, 102, 101, 201]
    };
    window.llToolsStudyPrefs = { starredWordIds: [102], starMode: 'weighted', star_mode: 'weighted' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: [],
        wordsByCategory: {},
        wordsLinear: [],
        listeningHistory: [],
        listeningLoop: false,
        starModeOverride: null
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const State = window.LLFlashcards.State;
    const Listening = window.LLFlashcards.Modes.Listening;

    State.wordsByCategory = {
      CatB: [
        { id: 201, title: 'b1', label: 'b1', audio: 'b1.mp3' }
      ],
      CatA: [
        { id: 101, title: 'a1', label: 'a1', audio: 'a1.mp3' },
        { id: 102, title: 'a2', label: 'a2', audio: 'a2.mp3' },
        { id: 103, title: 'a3', label: 'a3', audio: 'a3.mp3' }
      ]
    };
    State.categoryNames = ['CatB', 'CatA'];
    Listening.initialize();
    State.lastWordShownId = 103;
    State.listenIndex = 0;
    const selected = Listening.selectTargetWord();

    return {
      rapidMode: !!State.listeningRapidMode,
      ids: State.wordsLinear.map((word) => Number(word && word.id) || 0),
      selectedId: selected ? Number(selected.id) || 0 : 0
    };
  });

  expect(result.rapidMode).toBe(true);
  expect(result.ids).toEqual([103, 102, 101, 201]);
  expect(result.selectedId).toBe(103);
});

test('rapid listening plays one isolation clip and advances without countdown', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>');
  await page.addScriptTag({ content: jquerySource });

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    const nativeSetTimeout = window.setTimeout.bind(window);
    window.setTimeout = (fn, delay, ...args) => nativeSetTimeout(fn, delay > 20 ? 2 : delay, ...args);
    window.__playedListeningUrls = [];
    window.__rapidAdvanceCount = 0;

    const first = {
      id: 1,
      title: 'one',
      label: 'one',
      image: 'https://example.com/one.jpg',
      isolation_audio: 'https://example.com/one-isolation.mp3',
      question_audio: 'https://example.com/one-question.mp3',
      introduction_audio: 'https://example.com/one-intro.mp3',
      __categoryName: 'Numbers'
    };
    const second = {
      id: 2,
      title: 'two',
      label: 'two',
      image: 'https://example.com/two.jpg',
      isolation_audio: 'https://example.com/two-isolation.mp3',
      __categoryName: 'Numbers'
    };

    window.llToolsFlashcardsData = {
      listeningRapidMode: true,
      rapidListeningGapMs: 250,
      categories: [{
        name: 'Numbers',
        prompt_type: 'audio',
        option_type: 'image'
      }]
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };

    window.LLFlashcards = {
      State: {
        STATES: {
          QUIZ_READY: 'quiz_ready',
          SHOWING_QUESTION: 'showing_question',
          SHOWING_RESULTS: 'showing_results'
        },
        isListeningMode: true,
        isFirstRound: false,
        listeningPaused: false,
        listeningLoop: false,
        listeningRapidMode: true,
        categoryNames: ['Numbers'],
        currentCategoryName: 'Numbers',
        currentCategory: [first, second],
        wordsByCategory: { Numbers: [first, second] },
        wordsLinear: [first, second],
        listeningHistory: [],
        listenIndex: 0,
        addTimeout() {},
        transitionTo() { return true; },
        forceTransitionTo() { return true; },
        onStateChange() { return function () {}; }
      },
      Dom: {
        showLoading() {},
        hideLoading() { return Promise.resolve(); },
        updateCategoryNameDisplay() {},
        disableRepeatButton() {},
        enableRepeatButton() {},
        bindRepeatButtonAudio() {},
        setRepeatButton() {},
        updateSimpleProgress() {}
      },
      Cards: {
        applyAnswerOptionTextStyle() {}
      },
      Results: {
        showResults() {
          window.__listeningResultsShown = true;
        }
      },
      Util: {
        isPromptCard() { return false; },
        promptTypeHasImage(type) { return String(type || '') === 'image'; },
        promptTypeHasAudio(type) { return String(type || '') === 'audio'; },
        getEffectiveOptionLabel(word) { return String((word && (word.label || word.title)) || ''); }
      },
      Selection: {
        getCategoryConfig() { return window.llToolsFlashcardsData.categories[0]; }
      },
      AudioVisualizer: {
        prepareForListening() {},
        followAudio() {},
        stop() {}
      },
      StarManager: {
        updateForWord() {}
      },
      Modes: {}
    };

    let currentAudio = null;
    window.FlashcardLoader = {
      loadedCategories: ['Numbers'],
      isCategoryLoaded() { return true; },
      isCategoryLoading() { return false; },
      loadResourcesForWord() {
        return Promise.resolve({ ready: true, audioReady: true, imageReady: true });
      },
      loadResourcesForCategory(categoryName, callback) {
        if (typeof callback === 'function') callback();
        return Promise.resolve({ ready: true, categoryName });
      }
    };
    window.FlashcardAudio = {
      selectBestAudio(word, preferredTypes) {
        const preferred = Array.isArray(preferredTypes) ? preferredTypes : [];
        if (preferred.indexOf('isolation') !== -1 && word && word.isolation_audio) {
          return word.isolation_audio;
        }
        if (preferred.indexOf('question') !== -1 && word && word.question_audio) {
          return word.question_audio;
        }
        if (preferred.indexOf('introduction') !== -1 && word && word.introduction_audio) {
          return word.introduction_audio;
        }
        return '';
      },
      setTargetWordAudio(word, options) {
        const listeners = {};
        currentAudio = {
          src: String((options && options.audioUrl) || ''),
          paused: true,
          ended: false,
          currentTime: 0,
          readyState: 4,
          addEventListener(type, callback) { listeners[type] = callback; },
          removeEventListener(type) { delete listeners[type]; },
          __listeners: listeners
        };
        return Promise.resolve(currentAudio);
      },
      getCurrentTargetAudio() { return currentAudio; },
      playAudio(audio) {
        window.__playedListeningUrls.push(audio.src);
        audio.paused = false;
        setTimeout(() => {
          audio.paused = true;
          audio.ended = true;
          if (audio.__listeners && typeof audio.__listeners.ended === 'function') {
            audio.__listeners.ended();
          }
        }, 1);
        return Promise.resolve();
      },
      pauseAllAudio() {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  await page.evaluate(() => {
    window.LLFlashcards.Modes.Listening.runRound({
      FlashcardLoader: window.FlashcardLoader,
      FlashcardAudio: window.FlashcardAudio,
      Dom: window.LLFlashcards.Dom,
      Results: window.LLFlashcards.Results,
      flashcardContainer: window.jQuery('#ll-tools-flashcard'),
      setGuardedTimeout: window.setTimeout.bind(window),
      runQuizRound() {
        window.__rapidAdvanceCount += 1;
      }
    });
  });

  await page.waitForFunction(() => window.__rapidAdvanceCount >= 1);

  const result = await page.evaluate(() => ({
    played: window.__playedListeningUrls.slice(),
    advanceCount: window.__rapidAdvanceCount,
    hasCountdown: !!document.querySelector('.ll-tools-listening-countdown'),
    hasOverlay: !!document.querySelector('.listening-overlay'),
    hasImage: !!document.querySelector('#ll-tools-flashcard .quiz-image')
  }));

  expect(result.played).toEqual(['https://example.com/one-isolation.mp3']);
  expect(result.advanceCount).toBe(1);
  expect(result.hasCountdown).toBe(false);
  expect(result.hasOverlay).toBe(false);
  expect(result.hasImage).toBe(true);
});

test('prompt-card listening plays question, countdown, then one answer audio', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>');
  await page.addScriptTag({ content: jquerySource });

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    const nativeSetTimeout = window.setTimeout.bind(window);
    window.setTimeout = (fn, delay, ...args) => nativeSetTimeout(fn, delay > 20 ? 2 : delay, ...args);
    window.__playedListeningUrls = [];
    window.__listeningTransitions = [];

    const target = {
      id: 7001,
      title: 'Horse',
      label: 'Horse',
      image: 'https://example.com/horse.jpg',
      audio: 'https://example.com/horse-answer.mp3',
      prompt_audio: 'https://example.com/is-this-horse-or-donkey.mp3',
      is_prompt_card: true,
      prompt_card_id: 9001,
      __categoryName: 'Prompt Cards'
    };

    window.llToolsFlashcardsData = {
      categories: [{
        name: 'Prompt Cards',
        prompt_type: 'image_audio',
        option_type: 'audio',
        learning_supported: false,
        self_check_supported: false
      }]
    };
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };

    window.LLFlashcards = {
      State: {
        STATES: {
          QUIZ_READY: 'quiz_ready',
          SHOWING_QUESTION: 'showing_question',
          SHOWING_RESULTS: 'showing_results'
        },
        isListeningMode: true,
        isFirstRound: false,
        listeningPaused: false,
        listeningLoop: false,
        categoryNames: ['Prompt Cards'],
        currentCategoryName: 'Prompt Cards',
        currentCategory: [target],
        wordsByCategory: { 'Prompt Cards': [target] },
        wordsLinear: [target],
        listeningHistory: [],
        listenIndex: 0,
        addTimeout() {},
        transitionTo(state, reason) {
          window.__listeningTransitions.push({ state, reason });
          return true;
        },
        forceTransitionTo(state, reason) {
          window.__listeningTransitions.push({ state, reason, forced: true });
          return true;
        },
        onStateChange() { return function () {}; }
      },
      Dom: {
        showLoading() {},
        hideLoading() { return Promise.resolve(); },
        updateCategoryNameDisplay() {},
        disableRepeatButton() {},
        enableRepeatButton() {},
        bindRepeatButtonAudio() {},
        setRepeatButton() {}
      },
      Cards: {
        applyAnswerOptionTextStyle() {}
      },
      Results: {
        showResults() {
          window.__listeningResultsShown = true;
        }
      },
      Util: {
        isPromptCard(word) { return !!(word && (word.is_prompt_card || word.prompt_card_id)); },
        getAnswerAudioUrl(word) { return String((word && word.audio) || '').trim(); },
        promptTypeHasImage(type) { return String(type || '') === 'image_audio' || String(type || '') === 'image'; },
        promptTypeHasAudio(type) { return String(type || '') === 'image_audio' || String(type || '') === 'audio'; },
        getEffectiveOptionLabel(word) { return String((word && (word.label || word.title)) || ''); }
      },
      Selection: {
        getCategoryConfig() { return window.llToolsFlashcardsData.categories[0]; }
      },
      AudioVisualizer: {
        prepareForListening() {},
        followAudio() {},
        stop() {}
      },
      StarManager: {
        updateForWord() {}
      },
      Modes: {}
    };

    let currentAudio = null;
    window.FlashcardLoader = {
      loadedCategories: ['Prompt Cards'],
      isCategoryLoaded() { return true; },
      isCategoryLoading() { return false; },
      loadResourcesForWord() {
        return Promise.resolve({ ready: true, audioReady: true, imageReady: true });
      },
      loadResourcesForCategory(categoryName, callback) {
        if (typeof callback === 'function') callback();
        return Promise.resolve({ ready: true, categoryName });
      }
    };
    window.FlashcardAudio = {
      selectBestAudio(word) { return String((word && word.audio) || '').trim(); },
      setTargetWordAudio(word, options) {
        const listeners = {};
        currentAudio = {
          src: String((options && options.audioUrl) || ''),
          paused: true,
          ended: false,
          currentTime: 0,
          readyState: 4,
          addEventListener(type, callback) { listeners[type] = callback; },
          removeEventListener(type) { delete listeners[type]; },
          __listeners: listeners
        };
        return Promise.resolve(currentAudio);
      },
      getCurrentTargetAudio() { return currentAudio; },
      playAudio(audio) {
        window.__playedListeningUrls.push(audio.src);
        audio.paused = false;
        audio.currentTime = 1;
        setTimeout(() => {
          audio.paused = true;
          audio.ended = true;
          if (audio.__listeners && typeof audio.__listeners.ended === 'function') {
            audio.__listeners.ended();
          }
        }, 1);
        return Promise.resolve();
      },
      pauseAllAudio() {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  await page.evaluate(() => {
    window.LLFlashcards.Modes.Listening.runRound({
      FlashcardLoader: window.FlashcardLoader,
      FlashcardAudio: window.FlashcardAudio,
      Dom: window.LLFlashcards.Dom,
      Results: window.LLFlashcards.Results,
      flashcardContainer: window.jQuery('#ll-tools-flashcard'),
      setGuardedTimeout: window.setTimeout.bind(window),
      runQuizRound() {}
    });
  });

  await page.waitForFunction(() => window.__playedListeningUrls && window.__playedListeningUrls.length >= 2);

  const result = await page.evaluate(() => ({
    played: window.__playedListeningUrls.slice(),
    hasImage: !!document.querySelector('#ll-tools-flashcard .quiz-image'),
    transitions: window.__listeningTransitions.slice()
  }));

  expect(result.played).toEqual([
    'https://example.com/is-this-horse-or-donkey.mp3',
    'https://example.com/horse-answer.mp3'
  ]);
  expect(result.hasImage).toBe(true);
  expect(result.transitions.some((row) => row.state === 'showing_question')).toBe(true);
});
