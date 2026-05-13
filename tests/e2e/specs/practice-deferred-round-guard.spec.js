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
