const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const stateSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/state.js'),
  'utf8'
);
const optionConflictsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/option-conflicts.js'),
  'utf8'
);
const selfCheckSharedSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/self-check-shared.js'),
  'utf8'
);
const selfCheckModeSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/modes/self-check.js'),
  'utf8'
);

function buildHarnessMarkup() {
  return `
    <div id="ll-tools-flashcard-quiz-popup">
      <div id="ll-tools-flashcard-header"></div>
      <div id="ll-tools-category-stack"></div>
      <div id="ll-tools-category-display"></div>
      <button id="ll-tools-repeat-flashcard" type="button"></button>
      <div id="ll-tools-flashcard-content">
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard"></div>
      </div>
    </div>
    <div id="quiz-results"></div>
  `;
}

async function mountSelfCheckHarness(page) {
  await page.goto('about:blank');
  await page.setContent(buildHarnessMarkup());
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });

  await page.evaluate(() => {
    window.__playedAudioUrls = [];

    window.Audio = function AudioStub(url) {
      this.url = String(url || '');
      this.paused = true;
      this.currentTime = 0;
      this._listeners = {};
    };

    window.Audio.prototype.addEventListener = function addEventListener(type, handler) {
      this._listeners[type] = this._listeners[type] || [];
      this._listeners[type].push(handler);
    };

    window.Audio.prototype.removeEventListener = function removeEventListener(type, handler) {
      const listeners = Array.isArray(this._listeners[type]) ? this._listeners[type] : [];
      this._listeners[type] = listeners.filter((entry) => entry !== handler);
    };

    window.Audio.prototype._emit = function emit(type) {
      const listeners = Array.isArray(this._listeners[type]) ? this._listeners[type].slice() : [];
      listeners.forEach((handler) => {
        try {
          handler();
        } catch (_) {
          // Ignore listener failures inside the test harness.
        }
      });
    };

    window.Audio.prototype.play = function play() {
      this.paused = false;
      window.__playedAudioUrls.push(this.url);
      Promise.resolve().then(() => {
        this._emit('play');
        this.paused = true;
        this._emit('ended');
      });
      return Promise.resolve();
    };

    window.Audio.prototype.pause = function pause() {
      this.paused = true;
      this._emit('pause');
    };

    window.llToolsFlashcardsMessages = {
      selfCheckTitle: 'Self check',
      selfCheckDontKnow: "I don't know it",
      selfCheckThinkKnow: 'I think I know it',
      selfCheckKnow: 'I know it',
      selfCheckGotWrong: 'I got it wrong',
      selfCheckGotClose: 'I got close',
      selfCheckGotRight: 'I got it right',
      selfCheckPlayAudio: 'Play audio',
      selfCheckMultipleAnswers: '%d answers',
      playAudioType: 'Play %s recording',
      recordingIsolation: 'Isolation',
      recordingIntroduction: 'Introduction',
      recordingsLabel: 'Recordings',
      noWordsFound: 'No content available.'
    };

    window.llToolsFlashcardsData = {
      categories: [
        {
          id: 17,
          name: 'Family',
          translation: 'Family',
          prompt_type: 'audio',
          option_type: 'image'
        }
      ],
      wordsetIds: [41],
      userStudyState: {
        wordset_id: 41
      }
    };

    const sharedImage = 'https://images.test/family-photo.jpg?lltools-img=500';
    const words = [
      {
        id: 201,
        title: 'mother',
        label: 'mother',
        image: sharedImage,
        audio_files: [
          { recording_type: 'isolation', url: 'https://audio.test/mother-isolation.mp3' },
          { recording_type: 'introduction', url: 'https://audio.test/mother-introduction.mp3' }
        ],
        __categoryName: 'Family'
      },
      {
        id: 202,
        title: 'father',
        label: 'father',
        image: sharedImage,
        audio_files: [
          { recording_type: 'isolation', url: 'https://audio.test/father-isolation.mp3' },
          { recording_type: 'introduction', url: 'https://audio.test/father-introduction.mp3' }
        ],
        __categoryName: 'Family'
      },
      {
        id: 203,
        title: 'sister',
        label: 'sister',
        image: 'https://images.test/sister-photo.jpg?lltools-img=501',
        audio_files: [
          { recording_type: 'isolation', url: 'https://audio.test/sister-isolation.mp3' }
        ],
        __categoryName: 'Family'
      }
    ];

    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.Dom = {
      updateCategoryNameDisplay() {},
      updateSimpleProgress(current, total) {
        window.__selfCheckProgress = { current, total };
      },
      hideLoading() {},
      disableRepeatButton() {},
      bindRepeatButtonAudio() {}
    };
    window.LLFlashcards.Results = {
      showResults() {
        window.__resultsShown = true;
      }
    };
    window.LLFlashcards.Selection = {
      selectTargetWordAndCategory() {
        return words[0];
      },
      getTargetCategoryName(word) {
        return (word && word.__categoryName) || 'Family';
      },
      getCategoryConfig() {
        return {
          prompt_type: 'audio',
          option_type: 'image'
        };
      },
      getCategoryDisplayMode() {
        return 'image';
      },
      isWordBlockedFromPromptRounds() {
        return false;
      }
    };
    window.LLFlashcards.State.wordsByCategory = { Family: words };
    window.LLFlashcards.State.categoryNames = ['Family'];
    window.LLFlashcards.State.initialCategoryNames = ['Family'];
    window.LLFlashcards.State.currentCategoryName = 'Family';
    window.LLFlashcards.State.currentCategory = words;
    window.LLFlashcards.State.categoryRoundCount = {};
    window.LLFlashcards.State.completedCategories = {};
    window.LLFlashcards.State.categoryRepetitionQueues = {};
    window.LLFlashcards.State.practiceForcedReplays = {};
    window.LLFlashcards.State.usedWordIDs = [];
    window.LLFlashcards.State.starPlayCounts = {};
    window.LLFlashcards.State.quizResults = { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} };
    window.LLFlashcards.State.currentCategoryRoundCount = 0;
    window.LLFlashcards.State.isFirstRound = true;
    window.LLFlashcards.State.widgetActive = false;
    window.LLFlashcards.State.transitionTo = function transitionTo() {};
    window.LLFlashcards.State.forceTransitionTo = function forceTransitionTo() {};

    window.__selfCheckCtx = {
      flashcardContainer: window.jQuery('#ll-tools-flashcard'),
      Dom: window.LLFlashcards.Dom,
      FlashcardAudio: {
        pauseAllAudio() {},
        setTargetAudioHasPlayed() {}
      },
      startQuizRound() {
        window.__startQuizRoundCalls = (window.__startQuizRoundCalls || 0) + 1;
      }
    };
  });

  await page.addScriptTag({ content: optionConflictsSource });
  await page.addScriptTag({ content: selfCheckSharedSource });
  await page.addScriptTag({ content: selfCheckModeSource });

  await page.evaluate(() => {
    window.LLFlashcards.Modes.SelfCheck.initialize();
    window.LLFlashcards.Modes.SelfCheck.runRound(window.__selfCheckCtx);
  });
}

test('self-check groups words that share one image into a single review card', async ({ page }) => {
  await mountSelfCheckHarness(page);

  await expect(page.locator('.ll-study-check-face--front .ll-study-check-answer-count')).toHaveText('2 answers');
  await expect(page.locator('.ll-study-check-progress')).toHaveText('1 / 2');
  await expect(page.locator('.ll-study-check-face--back .ll-study-check-answer-item')).toHaveCount(2);

  const answerWords = await page.locator('.ll-study-check-face--back .ll-study-check-answer-word').allTextContents();
  expect(answerWords).toEqual(['father', 'mother']);

  await page.locator('.ll-study-check-btn--think').click();
  await expect(page.locator('.ll-study-check-flip-card')).toHaveClass(/is-flipped/);
  await expect(page.locator('.ll-study-check-btn--right')).toBeEnabled();

  const playedAudio = await page.evaluate(() => window.__playedAudioUrls.slice());
  expect(playedAudio).toEqual([
    'https://audio.test/father-isolation.mp3',
    'https://audio.test/mother-isolation.mp3'
  ]);

  await page.locator('.ll-study-check-btn--right').click();

  const summary = await page.evaluate(() => {
    const results = window.LLFlashcards.State.quizResults || {};
    return {
      usedWordIDs: Array.isArray(window.LLFlashcards.State.usedWordIDs)
        ? window.LLFlashcards.State.usedWordIDs.slice().sort((a, b) => a - b)
        : [],
      correctOnFirstTry: results.correctOnFirstTry || 0,
      incorrect: Array.isArray(results.incorrect) ? results.incorrect.slice() : [],
      wordAttempts: results.wordAttempts || {},
      roundAttempts: results.selfCheckRoundAttempts || {}
    };
  });

  expect(summary.usedWordIDs).toEqual([201, 202]);
  expect(summary.correctOnFirstTry).toBe(2);
  expect(summary.incorrect).toEqual([]);
  expect(summary.wordAttempts['201']).toMatchObject({ seen: 1, clean: 1, hadWrong: false });
  expect(summary.wordAttempts['202']).toMatchObject({ seen: 1, clean: 1, hadWrong: false });
  expect(Object.keys(summary.roundAttempts)).toHaveLength(1);
});
