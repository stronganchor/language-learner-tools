const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const mainSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);

test('rapid practice-mode unstars queue study saves and preserve the latest state', async ({ page }) => {
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
        STATES: {},
        categoryNames: ['Cat A'],
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
