const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const mainSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);

async function mountPracticeStarFixture(page) {
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-quiz-popup">
      <div id="ll-tools-flashcard-content"></div>
      <div id="ll-tools-category-stack">
        <button id="ll-tools-repeat-flashcard" type="button"></button>
      </div>
      <div id="ll-tools-prompt"></div>
      <div id="ll-tools-flashcard"></div>
    </div>
  `);

  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    const noop = function () {};

    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      userStudyNonce: 'test-nonce',
      isUserLoggedIn: true,
      categoriesPreselected: false,
      userStudyState: {
        wordset_id: 77,
        category_ids: [12],
        starred_word_ids: [101, 102],
        star_mode: 'normal',
        fast_transitions: false
      },
      starredWordIds: [101, 102],
      starred_word_ids: [101, 102],
      starMode: 'normal',
      star_mode: 'normal',
      fastTransitions: false,
      fast_transitions: false
    };

    window.llToolsStudyPrefs = {
      starredWordIds: [101, 102],
      starred_word_ids: [101, 102],
      starMode: 'normal',
      star_mode: 'normal',
      fastTransitions: false,
      fast_transitions: false
    };

    window.LLFlashcards = {
      State: {
        STATES: { CLOSING: 'closing', IDLE: 'idle' },
        categoryNames: ['Cat A'],
        is: () => false,
        transitionTo: noop,
        wordsByCategory: {
          'Cat A': [
            { id: 101, title: 'One', label: 'One' },
            { id: 102, title: 'Two', label: 'Two' },
            { id: 103, title: 'Three', label: 'Three' }
          ]
        },
        currentCategoryName: 'Cat A',
        currentPromptType: 'audio',
        currentOptionType: 'image',
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        usedWordIDs: [],
        categoryRoundCount: { 'Cat A': 0 },
        completedCategories: {},
        wrongIndexes: [],
        isLearningMode: false,
        isListeningMode: false,
        isGenderMode: false,
        isSelfCheckMode: false,
        clearActiveTimeouts: noop,
        reset: noop
      },
      Util: {
        randomInt: function (min) {
          return Number(min) || 0;
        }
      },
      Dom: {
        updateSimpleProgress: noop,
        restoreHeaderUI: noop,
        clearRepeatButtonBinding: noop
      },
      Effects: {},
      Selection: {
        getCategoryPromptType: function () {
          return 'audio';
        }
      },
      Cards: {},
      Results: {
        hideResults: noop,
        showResults: noop
      },
      StateMachine: {},
      Modes: {}
    };

    window.FlashcardAudio = {
      initializeAudio: noop,
      getCorrectAudioURL: function () { return ''; },
      getWrongAudioURL: function () { return ''; },
      pauseAllAudio: noop
    };

    window.FlashcardLoader = {
      loadAudio: noop
    };

    window.__llPostCalls = [];
    window.__llPostResolvers = [];

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      window.__llPostCalls.push({
        url: String(url || ''),
        data: Object.assign({}, data)
      });
      window.__llPostResolvers.push({
        resolve: function (response) {
          deferred.resolve(response);
        },
        reject: function (error) {
          deferred.reject(error);
        }
      });
      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: mainSource });
}

test('rapid practice-mode unstars queue study saves and preserve the latest state', async ({ page }) => {
  await mountPracticeStarFixture(page);

  const firstPass = await page.evaluate(() => {
    const manager = window.LLFlashcards.StarManager;
    manager.applyStarChange({ id: 101, title: 'One', label: 'One' }, false);
    manager.applyStarChange({ id: 102, title: 'Two', label: 'Two' }, false);

    return {
      postCount: window.__llPostCalls.length,
      prefs: window.llToolsStudyPrefs.starredWordIds.slice(),
      state: window.llToolsFlashcardsData.userStudyState.starred_word_ids.slice()
    };
  });

  expect(firstPass.postCount).toBe(1);
  expect(firstPass.prefs).toEqual([]);
  expect(firstPass.state).toEqual([]);

  await page.evaluate(() => {
    window.__llPostResolvers[0].resolve({
      success: true,
      data: {
        state: {
          wordset_id: 77,
          category_ids: [12],
          starred_word_ids: [102],
          star_mode: 'normal',
          fast_transitions: false
        }
      }
    });
  });

  await page.waitForFunction(() => window.__llPostCalls.length === 2);

  const secondPass = await page.evaluate(() => ({
    secondPayload: window.__llPostCalls[1].data.starred_word_ids.slice(),
    prefsAfterFirstResponse: window.llToolsStudyPrefs.starredWordIds.slice(),
    stateAfterFirstResponse: window.llToolsFlashcardsData.userStudyState.starred_word_ids.slice()
  }));

  expect(secondPass.secondPayload).toEqual([]);
  expect(secondPass.prefsAfterFirstResponse).toEqual([]);
  expect(secondPass.stateAfterFirstResponse).toEqual([]);

  await page.evaluate(() => {
    window.__llPostResolvers[1].resolve({
      success: true,
      data: {
        state: {
          wordset_id: 77,
          category_ids: [12],
          starred_word_ids: [],
          star_mode: 'normal',
          fast_transitions: false
        }
      }
    });
  });

  await page.waitForFunction(() => {
    const state = window.llToolsFlashcardsData && window.llToolsFlashcardsData.userStudyState;
    return !!state && Array.isArray(state.starred_word_ids) && state.starred_word_ids.length === 0;
  });

  const finalState = await page.evaluate(() => ({
    postCount: window.__llPostCalls.length,
    prefs: window.llToolsStudyPrefs.starredWordIds.slice(),
    state: window.llToolsFlashcardsData.userStudyState.starred_word_ids.slice()
  }));

  expect(finalState.postCount).toBe(2);
  expect(finalState.prefs).toEqual([]);
  expect(finalState.state).toEqual([]);
});

function rejectSaveForLock(page, index) {
  return page.evaluate((index) => {
    window.__llPostResolvers[index].reject({
      status: 503,
      responseJSON: { data: { code: 'user_data_mutation_lock_unavailable', retryable: true, retry_after: 1 } }
    });
  }, index);
}

function acceptSave(page, index) {
  return page.evaluate((index) => {
    const state = Object.assign({}, window.__llPostCalls[index].data);
    window.__llPostResolvers[index].resolve({ success: true, data: { state } });
  }, index);
}

async function displayPracticeStar(page, wordId) {
  await page.evaluate((id) => {
    const { StarManager, State } = window.LLFlashcards;
    StarManager.updateForWord(State.wordsByCategory['Cat A'].find(word => word.id === id));
  }, wordId);
  return page.locator('.ll-quiz-star-btn');
}

test('a Practice star retries a rejected lock and survives closing the quiz', async ({ page }) => {
  await mountPracticeStarFixture(page);
  const star = await displayPracticeStar(page, 103);
  await expect(star).toHaveAttribute('aria-pressed', 'false');
  await star.click();
  await expect(star).toHaveAttribute('aria-pressed', 'true');
  await rejectSaveForLock(page, 0);
  await page.evaluate(() => window.LLFlashcards.Main.closeFlashcard());
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeHidden();

  await expect.poll(() => page.evaluate(() => window.__llPostCalls.length), { timeout: 3000 }).toBe(2);
  const payloads = await page.evaluate(() => window.__llPostCalls.map(record => record.data));
  expect(payloads[1]).toEqual(payloads[0]);
  expect(payloads[1].starred_word_ids).toEqual([101, 102, 103]);
  await acceptSave(page, 1);
  expect(await page.evaluate(() => window.llToolsFlashcardsData.userStudyState.starred_word_ids)).toEqual([101, 102, 103]);
});

test('a newer Practice star toggle wins while a lock retry is pending', async ({ page }) => {
  await mountPracticeStarFixture(page);
  const star = await displayPracticeStar(page, 103);
  await star.click();
  await rejectSaveForLock(page, 0);
  await star.click();
  await expect(star).toHaveAttribute('aria-pressed', 'false');
  expect(await page.evaluate(() => window.__llPostCalls.length)).toBe(1);

  await expect.poll(() => page.evaluate(() => window.__llPostCalls.length), { timeout: 3000 }).toBe(2);
  await acceptSave(page, 1);
  await expect.poll(() => page.evaluate(() => window.__llPostCalls.length)).toBe(3);
  expect(await page.evaluate(() => window.__llPostCalls[2].data.starred_word_ids)).toEqual([101, 102]);
  expect(await page.evaluate(() => window.llToolsStudyPrefs.starredWordIds)).toEqual([101, 102]);
  await acceptSave(page, 2);
  await expect(star).toHaveAttribute('aria-pressed', 'false');
});

test('changing star mode in the flashcard runtime stays session-local and does not queue a study save', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-content"></div>
    <div id="ll-tools-category-stack">
      <button id="ll-tools-repeat-flashcard" type="button"></button>
    </div>
    <div id="ll-tools-prompt"></div>
    <div id="ll-tools-flashcard"></div>
  `);

  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    const noop = function () {};

    window.llToolsFlashcardsData = {
      ajaxurl: '/fake-admin-ajax.php',
      userStudyNonce: 'test-nonce',
      isUserLoggedIn: true,
      categoriesPreselected: false,
      userStudyState: {
        wordset_id: 77,
        category_ids: [12],
        starred_word_ids: [101],
        star_mode: 'normal',
        fast_transitions: false
      },
      starredWordIds: [101],
      starred_word_ids: [101],
      starMode: 'normal',
      star_mode: 'normal',
      fastTransitions: false,
      fast_transitions: false
    };

    window.llToolsStudyPrefs = {
      starredWordIds: [101],
      starred_word_ids: [101],
      starMode: 'normal',
      star_mode: 'normal',
      fastTransitions: false,
      fast_transitions: false
    };

    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: ['Cat A'],
        initialCategoryNames: ['Cat A'],
        wordsByCategory: {
          'Cat A': [
            { id: 101, title: 'One', label: 'One' },
            { id: 102, title: 'Two', label: 'Two' }
          ]
        },
        currentCategoryName: 'Cat A',
        currentPromptType: 'audio',
        currentOptionType: 'image',
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        usedWordIDs: [],
        categoryRoundCount: { 'Cat A': 0 },
        completedCategories: {},
        wrongIndexes: [],
        isLearningMode: false,
        isListeningMode: false,
        isGenderMode: false,
        isSelfCheckMode: false,
        clearActiveTimeouts: noop,
        reset: noop
      },
      Util: {
        randomInt: function (min) {
          return Number(min) || 0;
        }
      },
      Dom: {
        updateSimpleProgress: noop,
        restoreHeaderUI: noop,
        clearRepeatButtonBinding: noop
      },
      Effects: {},
      Selection: {
        getCategoryPromptType: function () {
          return 'audio';
        }
      },
      Cards: {},
      Results: {
        hideResults: noop,
        showResults: noop
      },
      StateMachine: {},
      Modes: {}
    };

    window.FlashcardAudio = {
      initializeAudio: noop,
      getCorrectAudioURL: function () { return ''; },
      getWrongAudioURL: function () { return ''; },
      pauseAllAudio: noop
    };

    window.FlashcardLoader = {
      loadAudio: noop
    };

    window.__llPostCalls = [];

    const $ = window.jQuery;
    $.post = function () {
      window.__llPostCalls.push(Array.from(arguments));
      const deferred = $.Deferred();
      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: mainSource });

  const result = await page.evaluate(() => {
    window.LLFlashcards.StudySettings.applyStarMode('only');

    return {
      postCount: window.__llPostCalls.length,
      effectiveMode: window.LLFlashcards.StudySettings.getStarMode(),
      overrideMode: window.LLFlashcards.State.starModeOverride,
      persistedMode: window.llToolsStudyPrefs.starMode,
      persistedFlashMode: window.llToolsFlashcardsData.starMode
    };
  });

  expect(result.postCount).toBe(0);
  expect(result.effectiveMode).toBe('only');
  expect(result.overrideMode).toBe('only');
  expect(result.persistedMode).toBe('normal');
  expect(result.persistedFlashMode).toBe('normal');
});
