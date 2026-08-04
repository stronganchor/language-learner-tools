const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const practiceScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/modes/practice.js'),
  'utf8'
);

test('practice pending category load does not replace an active unanswered round', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };

    window.__practiceStarts = 0;
    window.__practiceLoadingShows = 0;
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: false,
        categoryNames: ['Loaded category', 'Pending category'],
        initialCategoryNames: ['Loaded category', 'Pending category'],
        completedCategories: {},
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        wordsByCategory: {
          'Loaded category': [{ id: 101, title: 'Loaded' }]
        },
        currentCategoryName: 'Loaded category',
        currentCategoryRoundCount: 0,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        },
        transitionTo(state) {
          this.currentFlowState = state;
          return true;
        }
      },
      Selection: {},
      Results: {}
    };

    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      loaded: {},
      isCategoryLoaded(categoryName) {
        return categoryName === 'Loaded category' || !!this.loaded[categoryName];
      },
      isCategoryLoading() {
        return false;
      },
      loadResourcesForCategory(categoryName, callback) {
        window.setTimeout(() => {
          this.loaded[categoryName] = true;
          window.LLFlashcards.State.wordsByCategory[categoryName] = [{ id: 202, title: 'Pending' }];
          if (typeof callback === 'function') {
            callback();
          }
        }, 30);
        return Promise.resolve({ success: true, category: categoryName });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });

  const handled = await page.evaluate(() => {
    const practice = window.LLFlashcards.Modes.Practice;
    const result = practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: {
        showLoading() {
          window.__practiceLoadingShows += 1;
        }
      },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      updatePracticeModeProgress() {}
    });

    window.setTimeout(() => {
      window.LLFlashcards.State.currentFlowState = window.LLFlashcards.State.STATES.SHOWING_QUESTION;
    }, 5);

    return result;
  });

  expect(handled).toBe(true);
  await page.waitForTimeout(90);

  await expect.poll(async () => {
    return page.evaluate(() => ({
      starts: window.__practiceStarts,
      loadingShows: window.__practiceLoadingShows,
      state: window.LLFlashcards.State.currentFlowState
    }));
  }).toEqual({
    starts: 0,
    loadingShows: 1,
    state: 'showing_question'
  });
});

test('practice queued replay restarts within the current session', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };
    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false
    };
    window.__practiceStarts = 0;
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        abortAllOperations: false,
        isLearningMode: false,
        isListeningMode: false,
        isGenderMode: false,
        isSelfCheckMode: false,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: false,
        categoryNames: ['Replay category'],
        initialCategoryNames: ['Replay category'],
        completedCategories: {},
        categoryRepetitionQueues: {
          'Replay category': [{ wordData: { id: 701, title: 'Replay' } }]
        },
        practiceForcedReplays: {},
        wordsByCategory: {
          'Replay category': [{ id: 701, title: 'Replay' }]
        },
        currentCategoryName: 'Replay category',
        currentCategoryRoundCount: 0,
        lastWordShownId: 999,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        }
      },
      Selection: {},
      Results: {}
    };
    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      isCategoryLoaded() { return true; },
      isCategoryLoading() { return false; },
      loadResourcesForCategory(_name, callback) {
        if (typeof callback === 'function') callback();
        return Promise.resolve({ success: true });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });
  expect(await page.evaluate(() => window.LLFlashcards.Modes.Practice.handleNoTarget({
    FlashcardLoader: window.FlashcardLoader,
    startQuizRound() {
      window.__practiceStarts += 1;
    },
    updatePracticeModeProgress() {}
  }))).toBe(true);

  await expect.poll(async () => page.evaluate(() => window.__practiceStarts)).toBe(1);
});

test('practice ignores a pending category continuation from a closed session', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };

    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false,
      preloadTuning: { categoryAjaxTimeoutMs: 4000 }
    };
    window.__practiceStarts = 0;
    window.__practiceLoadingErrors = 0;
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        abortAllOperations: false,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: true,
        categoryNames: ['Pending category'],
        initialCategoryNames: ['Pending category'],
        completedCategories: {},
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        wordsByCategory: {},
        currentCategoryName: '',
        currentCategoryRoundCount: 0,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        }
      },
      Selection: {},
      Results: {}
    };
    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      loaded: false,
      isCategoryLoaded() {
        return this.loaded;
      },
      isCategoryLoading() {
        return false;
      },
      loadResourcesForCategory(categoryName, callback) {
        return new Promise((resolve) => {
          window.__resolveOldPracticeLoad = () => {
            this.loaded = true;
            window.LLFlashcards.State.wordsByCategory[categoryName] = [{ id: 501, title: 'Old session' }];
            if (typeof callback === 'function') {
              callback();
            }
            resolve({ success: true, category: categoryName });
          };
        });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });
  const handled = await page.evaluate(() => {
    const practice = window.LLFlashcards.Modes.Practice;
    practice.initialize();
    const result = practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: { showLoading() {} },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      showLoadingError() {
        window.__practiceLoadingErrors += 1;
      },
      updatePracticeModeProgress() {}
    });

    window.LLFlashcards.State.widgetActive = false;
    window.LLFlashcards.State.abortAllOperations = true;
    window.LLFlashcards.State.widgetActive = true;
    window.LLFlashcards.State.abortAllOperations = false;
    practice.initialize();
    window.__resolveOldPracticeLoad();
    return result;
  });

  expect(handled).toBe(true);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingErrors: window.__practiceLoadingErrors
  }))).toEqual({ starts: 0, loadingErrors: 0 });
});

test('practice recovery checks a later category after an empty first batch', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };
    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false,
      preloadTuning: { categoryAjaxTimeoutMs: 1000 }
    };
    window.__practiceStarts = 0;
    window.__practiceLoadingErrors = 0;
    window.__practiceLoadOrder = [];
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        abortAllOperations: false,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: true,
        categoryNames: ['Empty one', 'Empty two', 'Usable three'],
        initialCategoryNames: ['Empty one', 'Empty two', 'Usable three'],
        completedCategories: {},
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        wordsByCategory: {},
        currentCategoryName: '',
        currentCategoryRoundCount: 0,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        }
      },
      Selection: {},
      Results: {}
    };
    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      loaded: {},
      isCategoryLoaded(categoryName) {
        return !!this.loaded[categoryName];
      },
      isCategoryLoading() {
        return false;
      },
      loadResourcesForCategory(categoryName, callback) {
        window.__practiceLoadOrder.push(categoryName);
        this.loaded[categoryName] = true;
        window.LLFlashcards.State.wordsByCategory[categoryName] = categoryName === 'Usable three'
          ? [{ id: 601, title: 'Usable' }]
          : [];
        if (typeof callback === 'function') {
          callback();
        }
        return Promise.resolve({ success: true, category: categoryName });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });
  const handled = await page.evaluate(() => {
    return window.LLFlashcards.Modes.Practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: { showLoading() {} },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      showLoadingError() {
        window.__practiceLoadingErrors += 1;
      },
      updatePracticeModeProgress() {}
    });
  });

  expect(handled).toBe(true);
  await expect.poll(async () => page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingErrors: window.__practiceLoadingErrors,
    loadOrder: window.__practiceLoadOrder
  }))).toEqual({
    starts: 1,
    loadingErrors: 0,
    loadOrder: ['Empty one', 'Empty two', 'Usable three']
  });
});

test('practice stops waiting on stalled category hydration and surfaces one loading error', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };

    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false,
      preloadTuning: { categoryAjaxTimeoutMs: 40 }
    };
    window.__practiceStarts = 0;
    window.__practiceLoadingShows = 0;
    window.__practiceLoadingErrors = 0;
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: true,
        categoryNames: ['Stalled category'],
        initialCategoryNames: ['Stalled category'],
        completedCategories: {},
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        wordsByCategory: {},
        currentCategoryName: '',
        currentCategoryRoundCount: 0,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        }
      },
      Selection: {},
      Results: {}
    };

    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      loading: true,
      loaded: false,
      loadCalls: 0,
      isCategoryLoaded() {
        return this.loaded;
      },
      isCategoryLoading() {
        return this.loading;
      },
      loadResourcesForCategory(categoryName, callback) {
        this.loadCalls += 1;
        this.loaded = true;
        window.LLFlashcards.State.wordsByCategory[categoryName] = [{ id: 301, title: 'Recovered' }];
        if (typeof callback === 'function') {
          callback();
        }
        return Promise.resolve({ success: true, category: categoryName });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });
  const handled = await page.evaluate(() => {
    return window.LLFlashcards.Modes.Practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: {
        showLoading() {
          window.__practiceLoadingShows += 1;
        }
      },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      showLoadingError() {
        window.__practiceLoadingErrors += 1;
      },
      updatePracticeModeProgress() {}
    });
  });

  expect(handled).toBe(true);
  await expect.poll(async () => page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingShows: window.__practiceLoadingShows,
    loadingErrors: window.__practiceLoadingErrors,
    loadCalls: window.FlashcardLoader.loadCalls
  }))).toEqual({ starts: 0, loadingShows: 1, loadingErrors: 1, loadCalls: 0 });

  const relaunched = await page.evaluate(() => {
    window.FlashcardLoader.loading = false;
    return window.LLFlashcards.Modes.Practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: {
        showLoading() {
          window.__practiceLoadingShows += 1;
        }
      },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      showLoadingError() {
        window.__practiceLoadingErrors += 1;
      },
      updatePracticeModeProgress() {}
    });
  });
  expect(relaunched).toBe(true);
  await expect.poll(async () => page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingErrors: window.__practiceLoadingErrors,
    loadCalls: window.FlashcardLoader.loadCalls,
    loaded: window.FlashcardLoader.loaded
  }))).toEqual({ starts: 1, loadingErrors: 1, loadCalls: 1, loaded: true });

  await page.waitForTimeout(650);
  expect(await page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingErrors: window.__practiceLoadingErrors
  }))).toEqual({ starts: 1, loadingErrors: 1 });
});

test('practice keeps waiting when category hydration succeeds before its hard deadline', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const STATES = {
      QUIZ_READY: 'quiz_ready',
      SHOWING_QUESTION: 'showing_question',
      PROCESSING_ANSWER: 'processing_answer',
      SHOWING_RESULTS: 'showing_results',
      CLOSING: 'closing'
    };

    window.llToolsFlashcardsData = {
      wordset: 'set-a',
      wordsetFallback: false,
      preloadTuning: { categoryAjaxTimeoutMs: 4000 }
    };
    window.__practiceStarts = 0;
    window.__practiceLoadingErrors = 0;
    window.LLFlashcards = {
      State: {
        STATES,
        widgetActive: true,
        currentFlowState: STATES.QUIZ_READY,
        isFirstRound: true,
        categoryNames: ['Slow category'],
        initialCategoryNames: ['Slow category'],
        completedCategories: {},
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        wordsByCategory: {},
        currentCategoryName: '',
        currentCategoryRoundCount: 0,
        is(state) {
          return this.currentFlowState === state;
        },
        canStartQuizRound() {
          return this.currentFlowState === STATES.QUIZ_READY;
        }
      },
      Selection: {},
      Results: {}
    };
    window.FlashcardOptions = {};
    window.FlashcardLoader = {
      loaded: false,
      isCategoryLoaded() {
        return this.loaded;
      },
      isCategoryLoading() {
        return false;
      },
      loadResourcesForCategory(categoryName, callback) {
        return new Promise((resolve) => {
          window.setTimeout(() => {
            this.loaded = true;
            window.LLFlashcards.State.wordsByCategory[categoryName] = [{ id: 401, title: 'Slow success' }];
            if (typeof callback === 'function') {
              callback();
            }
            resolve({ success: true, category: categoryName });
          }, 2800);
        });
      }
    };
  });

  await page.addScriptTag({ content: practiceScriptSource });
  const handled = await page.evaluate(() => {
    return window.LLFlashcards.Modes.Practice.handleNoTarget({
      FlashcardLoader: window.FlashcardLoader,
      Dom: { showLoading() {} },
      startQuizRound() {
        window.__practiceStarts += 1;
      },
      showLoadingError() {
        window.__practiceLoadingErrors += 1;
      },
      updatePracticeModeProgress() {}
    });
  });

  expect(handled).toBe(true);
  await expect.poll(async () => page.evaluate(() => ({
    starts: window.__practiceStarts,
    loadingErrors: window.__practiceLoadingErrors
  }))).toEqual({ starts: 1, loadingErrors: 0 });
});
