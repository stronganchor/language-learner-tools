const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const selectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);
const optionsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/options.js'),
  'utf8'
);
const mainSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);
const practiceSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/modes/practice.js'),
  'utf8'
);

async function mountSelectionHarness(page, options = {}) {
  const maxCards = Number.isFinite(Number(options.maxCards))
    ? Math.max(1, Number(options.maxCards))
    : null;

  const data = {
    categories: Array.isArray(options.categories) ? options.categories : [],
    wordsByCategory: options.wordsByCategory || {},
    optionWordsByCategory: options.optionWordsByCategory || options.wordsByCategory || {},
    targetCategoryName: String(options.targetCategoryName || ''),
    desiredCount: Number(options.desiredCount || 4),
    maxCards,
    state: Object.assign({
      DEFAULT_DISPLAY_MODE: 'image',
      ROUNDS_PER_CATEGORY: 2,
      isFirstRound: false,
      isLearningMode: false,
      isListeningMode: false,
      isGenderMode: false,
      isSelfCheckMode: false,
      currentCategoryName: String(options.targetCategoryName || ''),
      currentCategory: [],
      categoryNames: [],
      initialCategoryNames: [],
      categoryRepetitionQueues: {},
      categoryRoundCount: {},
      completedCategories: {},
      quizResults: { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} },
      wrongIndexes: [],
      currentCategoryRoundCount: 0,
      usedWordIDs: [],
      lastWordShownId: null
    }, options.state || {})
  };

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-content">
      <div id="ll-tools-prompt"></div>
      <div id="ll-tools-flashcard"></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    window.llToolsFlashcardsData = {
      categories: bootstrap.categories
    };
    window.wordsByCategory = bootstrap.wordsByCategory;
    window.optionWordsByCategory = bootstrap.optionWordsByCategory;

    const State = Object.assign({}, bootstrap.state);
    State.currentCategory = bootstrap.wordsByCategory[bootstrap.targetCategoryName] || [];
    State.wordsByCategory = bootstrap.wordsByCategory || {};
    State.categoryNames = Array.isArray(State.categoryNames) && State.categoryNames.length
      ? State.categoryNames.slice()
      : Object.keys(bootstrap.wordsByCategory || {});

    window.LLFlashcards = {
      State,
      Util: {
        randomlySort: function (items) {
          return Array.isArray(items) ? items.slice() : [];
        }
      },
      Cards: {
        appendWordToContainer: function (word) {
          const $card = window.jQuery('<div>', {
            class: 'flashcard-container',
            'data-word-id': String((word && word.id) || ''),
            'data-word-image': String((word && word.image) || ''),
            'data-word-label': String((word && (word.__resolvedOptionLabel || word.label || word.title)) || '')
          });
          window.jQuery('#ll-tools-flashcard').append($card);
          return $card;
        },
        addClickEventToCard: function () {}
      },
      Dom: {
        updateCategoryNameDisplay: function () {}
      },
      LearningMode: {
        getChoiceCount: function () {
          return Number(bootstrap.desiredCount || 4);
        }
      }
    };

    window.FlashcardLoader = {
      loadResourcesForWord: function () {}
    };

    window.FlashcardOptions = {
      categoryOptionsCount: {
        [bootstrap.targetCategoryName]: Number(bootstrap.desiredCount || 4)
      },
      canAddMoreCards: function () {
        if (Number.isFinite(bootstrap.maxCards)) {
          const count = document.querySelectorAll('#ll-tools-flashcard .flashcard-container').length;
          return count < Number(bootstrap.maxCards);
        }
        return true;
      }
    };
  }, data);

  await page.addScriptTag({ content: selectionSource });
}

async function mountPracticeModeHarness(page, options = {}) {
  const state = Object.assign({
    currentCategoryName: '',
    categoryNames: [],
    initialCategoryNames: [],
    wordsByCategory: {},
    categoryRepetitionQueues: {},
    practiceForcedReplays: {},
    completedCategories: {},
    lastWordShownId: null,
    isFirstRound: false,
    widgetActive: true
  }, options.state || {});

  await page.goto('about:blank');
  await page.evaluate((bootstrap) => {
    window.__practiceStartQuizRoundCalls = 0;
    window.__practiceShowResultsCalls = 0;
    window.__practiceLastTransition = null;
    window.llToolsFlashcardsData = Object.assign({
      isUserLoggedIn: !!bootstrap.isUserLoggedIn
    }, bootstrap.flashcardsData || {});

    window.LLFlashcards = {
      State: Object.assign({
        STATES: {
          SHOWING_RESULTS: 'showing_results'
        },
        transitionTo: function (state, reason) {
          window.__practiceLastTransition = { state, reason };
          return true;
        }
    }, bootstrap.state),
      Selection: {
        hasPracticeBridgeWordAvailable: function () {
          return !!bootstrap.hasPracticeBridgeWordAvailable;
        }
      },
      Results: {
        showResults: function () {
          window.__practiceShowResultsCalls += 1;
        }
      },
      Util: {
        randomInt: function (min) {
          return Number(min) || 0;
        }
      },
      Modes: {}
    };
    window.FlashcardOptions = bootstrap.flashcardOptions || {};
    window.FlashcardLoader = bootstrap.flashcardLoader || null;
  }, {
    state,
    hasPracticeBridgeWordAvailable: !!options.hasPracticeBridgeWordAvailable,
    flashcardOptions: options.flashcardOptions || {},
    flashcardsData: options.flashcardsData || {},
    flashcardLoader: options.flashcardLoader || null,
    isUserLoggedIn: !!options.isUserLoggedIn
  });

  await page.addScriptTag({ content: practiceSource });
}

async function mountPracticeExposureHarness(page, options = {}) {
  const categoryName = String(options.categoryName || 'Actions');

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-content">
      <div id="ll-tools-prompt"></div>
      <div id="ll-tools-flashcard"></div>
      <button id="ll-tools-repeat-flashcard" type="button"></button>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    const noop = function () {};

    window.__LLFlashcardsMainLoaded = false;
    window.llToolsFlashcardsData = {
      isUserLoggedIn: true,
      categories: [
        {
          id: 1,
          name: bootstrap.categoryName,
          prompt_type: 'audio',
          option_type: 'image'
        }
      ]
    };
    window.llToolsStudyPrefs = {
      starredWordIds: [],
      starred_word_ids: [],
      starMode: 'normal',
      star_mode: 'normal'
    };

    window.LLFlashcards = {
      State: {
        STATES: {
          PROCESSING_ANSWER: 'processing_answer',
          QUIZ_READY: 'quiz_ready'
        },
        canProcessAnswer: function () {
          return true;
        },
        getState: function () {
          return 'quiz_ready';
        },
        transitionTo: function () {
          return true;
        },
        forceTransitionTo: function () {
          return true;
        },
        clearActiveTimeouts: noop,
        currentCategoryName: bootstrap.categoryName,
        currentPromptType: 'audio',
        currentOptionType: 'image',
        categoryRoundCount: {
          [bootstrap.categoryName]: 0
        },
        categoryRepetitionQueues: {},
        practiceForcedReplays: {},
        usedWordIDs: [],
        completedCategories: {},
        wrongIndexes: [],
        userClickedCorrectAnswer: false,
        hadWrongAnswerThisTurn: false,
        quizResults: { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} },
        isLearningMode: false,
        isListeningMode: false,
        isGenderMode: false,
        isSelfCheckMode: false,
        widgetActive: true,
        wordsByCategory: {
          [bootstrap.categoryName]: []
        },
        categoryNames: [bootstrap.categoryName]
      },
      Util: {
        randomInt: function (min) {
          return Number(min) || 0;
        }
      },
      Dom: {
        updateSimpleProgress: noop,
        restoreHeaderUI: noop,
        clearRepeatButtonBinding: noop,
        updateCategoryNameDisplay: noop
      },
      Effects: {
        startConfetti: noop
      },
      Selection: {
        getCategoryConfig: function () {
          return { option_type: 'image', prompt_type: 'audio' };
        },
        getCurrentDisplayMode: function () {
          return 'image';
        }
      },
      Cards: {},
      Results: {
        hideResults: noop,
        showResults: noop
      },
      StateMachine: {},
      ModeConfig: {},
      Modes: {},
      ProgressTracker: {
        trackWordExposure: noop,
        trackWordOutcome: noop
      }
    };

    window.FlashcardAudio = {
      initializeAudio: noop,
      playFeedback: noop,
      pauseAllAudio: noop,
      getCorrectAudioURL: function () { return ''; },
      getWrongAudioURL: function () { return ''; }
    };
    window.FlashcardLoader = {
      loadAudio: noop
    };
    window.FlashcardOptions = {
      initializeOptionsCount: noop
    };
  }, {
    categoryName
  });

  await page.addScriptTag({ content: practiceSource });
  await page.addScriptTag({ content: mainSource });
}

test('practice mode rotates logged-in prompts by exposure count', async ({ page }) => {
  await mountPracticeModeHarness(page, { isUserLoggedIn: true });

  const outcome = await page.evaluate(() => {
    const target = {
      id: 901,
      title: 'Walking',
      practice_exposure_count: 1,
      practice_correct_recording_types: ['question'],
      practice_recording_types: ['question', 'isolation', 'introduction'],
      audio_files: [
        { recording_type: 'question', url: 'https://audio.test/question.mp3' },
        { recording_type: 'isolation', url: 'https://audio.test/isolation.mp3' },
        { recording_type: 'introduction', url: 'https://audio.test/introduction.mp3' }
      ]
    };

    window.LLFlashcards.Modes.Practice.configureTargetAudio(target);

    return {
      type: target.__practiceRecordingType,
      audio: target.audio
    };
  });

  expect(outcome).toEqual({
    type: 'isolation',
    audio: 'https://audio.test/isolation.mp3'
  });
});

test('practice mode advances the next prompt on the same page after tracking an exposure', async ({ page }) => {
  await mountPracticeExposureHarness(page);

  const outcome = await page.evaluate(() => {
    const target = {
      id: 902,
      title: 'Walking',
      audio: 'https://audio.test/question.mp3',
      practice_exposure_count: 0,
      practice_correct_recording_types: [],
      practice_recording_types: ['question', 'isolation', 'introduction'],
      audio_files: [
        { recording_type: 'question', url: 'https://audio.test/question.mp3' },
        { recording_type: 'isolation', url: 'https://audio.test/isolation.mp3' },
        { recording_type: 'introduction', url: 'https://audio.test/introduction.mp3' }
      ]
    };

    window.LLFlashcards.State.wordsByCategory.Actions = [target];
    window.LLFlashcards.Modes.Practice.configureTargetAudio(target);
    const firstType = target.__practiceRecordingType;

    const $wrong = window.jQuery('<div class="flashcard-container"></div>').appendTo('#ll-tools-flashcard');
    window.LLFlashcards.Main.onWrongAnswer(target, 1, $wrong);

    delete target.__practiceRecordingType;
    delete target.__practiceRecordingText;
    window.LLFlashcards.Modes.Practice.configureTargetAudio(target);

    return {
      firstType,
      nextType: target.__practiceRecordingType,
      exposureCount: Number(target.practice_exposure_count) || 0
    };
  });

  expect(outcome).toEqual({
    firstType: 'question',
    nextType: 'isolation',
    exposureCount: 1
  });
});

test('practice options stay within the target category pool', async ({ page }) => {
  const targetCategory = 'Baby animals';
  const otherCategory = 'People';
  const targetWord = { id: 101, title: 'Lamb', label: 'Lamb', image: 'https://img.test/lamb.jpg', audio: 'https://audio.test/lamb.mp3' };
  const sameCategoryDistractor = { id: 102, title: 'Calf', label: 'Calf', image: 'https://img.test/calf.jpg', audio: 'https://audio.test/calf.mp3' };
  const crossCategoryDistractor = { id: 201, title: 'Man', label: 'Man', image: 'https://img.test/man.jpg', audio: 'https://audio.test/man.mp3' };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'image' },
      { name: otherCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 5,
    wordsByCategory: {
      [targetCategory]: [targetWord],
      [otherCategory]: [crossCategoryDistractor]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, sameCategoryDistractor],
      [otherCategory]: [crossCategoryDistractor]
    },
    state: {
      categoryNames: [targetCategory, otherCategory]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Baby animals' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([101, 102]);
});

test('practice options exclude wrong answers with the same translation', async ({ page }) => {
  const targetCategory = 'Actions';
  const targetWord = {
    id: 211,
    title: 'to walk',
    translation: 'caminar',
    image: 'https://img.test/walk.jpg'
  };
  const duplicateTranslationWord = {
    id: 212,
    title: 'to stroll',
    translation: 'caminar',
    image: 'https://img.test/stroll.jpg'
  };
  const distinctTranslationWord = {
    id: 213,
    title: 'to run',
    translation: 'correr',
    image: 'https://img.test/run.jpg'
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 4,
    wordsByCategory: {
      [targetCategory]: [targetWord]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, duplicateTranslationWord, distinctTranslationWord]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Actions' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([211, 213]);
});

test('practice options exclude duplicate wrong answers with the same active recording-type transcript', async ({ page }) => {
  const targetCategory = 'Actions';
  const targetWord = {
    id: 221,
    title: 'The dog is jumping',
    label: 'The dog is jumping',
    recording_texts_by_type: {
      isolation: 'jumping'
    }
  };
  const firstDuplicateWrongAnswer = {
    id: 222,
    title: 'The cat is walking',
    label: 'The cat is walking',
    recording_texts_by_type: {
      isolation: 'walking'
    }
  };
  const secondDuplicateWrongAnswer = {
    id: 223,
    title: 'The fox is walking',
    label: 'The fox is walking',
    recording_texts_by_type: {
      isolation: 'walking'
    }
  };
  const distinctWrongAnswer = {
    id: 224,
    title: 'The bird is running',
    label: 'The bird is running',
    recording_texts_by_type: {
      isolation: 'running'
    }
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'text_title' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 4,
    wordsByCategory: {
      [targetCategory]: [targetWord]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, firstDuplicateWrongAnswer, secondDuplicateWrongAnswer, distinctWrongAnswer]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Actions', __promptRecordingType: 'isolation' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([221, 222, 224]);
});

test('practice options exclude wrong answers with the same active recording-type transcript', async ({ page }) => {
  const targetCategory = 'Actions';
  const targetWord = {
    id: 301,
    title: 'The dog is walking',
    label: 'The dog is walking',
    recording_texts_by_type: {
      isolation: 'walking',
      introduction: 'the dog is walking'
    }
  };
  const duplicateIsolationWord = {
    id: 302,
    title: 'The cat is walking',
    label: 'The cat is walking',
    recording_texts_by_type: {
      isolation: 'walking',
      introduction: 'the cat is walking'
    }
  };
  const distinctIsolationWord = {
    id: 303,
    title: 'The bird is running',
    label: 'The bird is running',
    recording_texts_by_type: {
      isolation: 'running',
      introduction: 'the bird is running'
    }
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'text_title' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 4,
    wordsByCategory: {
      [targetCategory]: [targetWord]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, duplicateIsolationWord, distinctIsolationWord]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Actions', __practiceRecordingType: 'isolation' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([301, 303]);
});

test('audio-to-text options prefer active recording-type translations and de-dupe on the rendered label', async ({ page }) => {
  const category = 'Recording translation labels';
  const targetWord = {
    id: 901,
    title: 'Caminar',
    label: 'Walk',
    recording_translations_by_type: {
      isolation: 'Walk now'
    }
  };
  const duplicateRenderedLabel = {
    id: 902,
    title: 'Marchar',
    label: 'March',
    recording_translations_by_type: {
      isolation: 'Walk now'
    }
  };
  const distinctRenderedLabel = {
    id: 903,
    title: 'Correr',
    label: 'Run',
    recording_translations_by_type: {
      isolation: 'Run now'
    }
  };
  const fallbackLabel = {
    id: 904,
    title: 'Saltar',
    label: 'Jump'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord]
    },
    optionWordsByCategory: {
      [category]: [targetWord, duplicateRenderedLabel, distinctRenderedLabel, fallbackLabel]
    }
  });

  const picked = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Recording translation labels', __promptRecordingType: 'isolation' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container')).map((el) => ({
      id: Number(el.getAttribute('data-word-id')) || 0,
      label: String(el.getAttribute('data-word-label') || '')
    }));
  }, targetWord);

  expect(picked).toEqual([
    { id: 901, label: 'Walk now' },
    { id: 903, label: 'Run now' },
    { id: 904, label: 'Jump' }
  ]);
});

test('practice options still allow words whose other recording types differ', async ({ page }) => {
  const targetCategory = 'Actions';
  const targetWord = {
    id: 401,
    title: 'The dog is walking',
    label: 'The dog is walking',
    recording_texts_by_type: {
      isolation: 'walking',
      introduction: 'the dog is walking'
    }
  };
  const sameIsolationDifferentIntro = {
    id: 402,
    title: 'The cat is walking',
    label: 'The cat is walking',
    recording_texts_by_type: {
      isolation: 'walking',
      introduction: 'the cat is walking'
    }
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'text_title' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 3,
    wordsByCategory: {
      [targetCategory]: [targetWord]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, sameIsolationDifferentIntro]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Actions', __practiceRecordingType: 'introduction' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([401, 402]);
});

test('option count never drops below two after wrong answers', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div><div id="ll-tools-flashcard-content"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      maxOptionsOverride: 9
    };
    window.categoryNames = ['Audio text'];
    window.wordsByCategory = {
      'Audio text': [{ id: 1 }, { id: 2 }]
    };
    window.optionWordsByCategory = {
      'Audio text': [{ id: 1 }, { id: 2 }]
    };
  });
  await page.addScriptTag({ content: optionsSource });

  const nextCount = await page.evaluate(() => {
    window.FlashcardOptions.initializeOptionsCount(2);
    const wrongIndexes = [0];
    return window.FlashcardOptions.calculateNumberOfOptions(wrongIndexes, false, 'Audio text', {
      id: 1,
      progress_status: 'new',
      progress_total_coverage: 0
    });
  });

  expect(nextCount).toBe(2);
});

test('practice option count grows from overall word progress instead of session streaks', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div><div id="ll-tools-flashcard-content"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    const animals = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }, { id: 6 }];
    const objects = [{ id: 101 }, { id: 102 }, { id: 103 }, { id: 104 }, { id: 105 }, { id: 106 }];
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      maxOptionsOverride: 9
    };
    window.categoryNames = ['Animals', 'Objects'];
    window.wordsByCategory = {
      Animals: animals,
      Objects: objects
    };
    window.optionWordsByCategory = {
      Animals: animals,
      Objects: objects
    };
  });
  await page.addScriptTag({ content: optionsSource });

  const counts = await page.evaluate(() => {
    window.FlashcardOptions.initializeOptionsCount(2);
    return {
      newWord: window.FlashcardOptions.calculateNumberOfOptions([], true, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0
      }),
      earlyStudiedWord: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 2,
        progress_status: 'studied',
        progress_total_coverage: 1
      }),
      moreStudiedWord: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 3,
        progress_status: 'studied',
        progress_total_coverage: 3
      }),
      masteredWord: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Objects', {
        id: 101,
        progress_status: 'mastered',
        progress_total_coverage: 2
      })
    };
  });

  expect(counts).toEqual({
    newWord: 2,
    earlyStudiedWord: 3,
    moreStudiedWord: 5,
    masteredWord: 6
  });
});

test('practice option count can keep growing during the current session from local exposure progress', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div><div id="ll-tools-flashcard-content"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    const animals = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }, { id: 6 }];
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      maxOptionsOverride: 9
    };
    window.categoryNames = ['Animals'];
    window.wordsByCategory = {
      Animals: animals
    };
    window.optionWordsByCategory = {
      Animals: animals
    };
  });
  await page.addScriptTag({ content: optionsSource });

  const counts = await page.evaluate(() => {
    window.FlashcardOptions.initializeOptionsCount(2);
    return {
      initial: window.FlashcardOptions.calculateNumberOfOptions([], true, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 0
      }),
      afterOneExposure: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 1
      }),
      afterTwoExposures: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 2
      })
    };
  });

  expect(counts).toEqual({
    initial: 2,
    afterOneExposure: 3,
    afterTwoExposures: 4
  });
});

test('practice forced replays downgrade the next option count instead of upgrading it', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div><div id="ll-tools-flashcard-content"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    const animals = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }, { id: 6 }];
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      maxOptionsOverride: 9
    };
    window.categoryNames = ['Animals'];
    window.wordsByCategory = {
      Animals: animals
    };
    window.optionWordsByCategory = {
      Animals: animals
    };
  });
  await page.addScriptTag({ content: optionsSource });

  const counts = await page.evaluate(() => {
    window.FlashcardOptions.initializeOptionsCount(2);
    return {
      regularReplayCandidate: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 1
      }),
      forcedReplayCandidate: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 1,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 1,
        __practiceForcedReplay: true
      }),
      minimumForcedReplay: window.FlashcardOptions.calculateNumberOfOptions([], false, 'Animals', {
        id: 2,
        progress_status: 'new',
        progress_total_coverage: 0,
        practice_exposure_count: 0,
        __practiceForcedReplay: true
      })
    };
  });

  expect(counts).toEqual({
    regularReplayCandidate: 3,
    forcedReplayCandidate: 2,
    minimumForcedReplay: 2
  });
});

test('practice mode calculates option count from the selected target word', async ({ page }) => {
  await mountPracticeModeHarness(page, {
    state: {
      currentCategoryName: 'Animals',
      wrongIndexes: [1],
      isFirstRound: false
    }
  });

  const outcome = await page.evaluate(() => {
    const picked = {
      id: 101,
      __categoryName: 'Objects',
      progress_status: 'mastered',
      progress_total_coverage: 4
    };

    window.__practiceOptionCountArgs = null;
    window.FlashcardOptions.calculateNumberOfOptions = function (wrongIndexes, isFirstRound, categoryName, targetWord) {
      window.__practiceOptionCountArgs = {
        wrongIndexes: Array.isArray(wrongIndexes) ? wrongIndexes.slice() : [],
        isFirstRound: !!isFirstRound,
        categoryName: String(categoryName || ''),
        targetWordId: Number(targetWord && targetWord.id) || 0
      };
      return 6;
    };
    window.LLFlashcards.Selection.selectTargetWordAndCategory = function () {
      return picked;
    };

    const selected = window.LLFlashcards.Modes.Practice.selectTargetWord();
    return {
      selectedWordId: Number(selected && selected.id) || 0,
      args: window.__practiceOptionCountArgs
    };
  });

  expect(outcome).toEqual({
    selectedWordId: 101,
    args: {
      wrongIndexes: [1],
      isFirstRound: false,
      categoryName: 'Objects',
      targetWordId: 101
    }
  });
});

test('practice selector marks forced replays so the next round can downgrade options', async ({ page }) => {
  const category = 'Replay category';
  const replayWord = {
    id: 2051,
    title: 'Replay word',
    label: 'Replay word',
    image: 'https://img.test/replay-option-word.jpg',
    audio: 'https://audio.test/replay-option-word.mp3'
  };
  const otherWord = {
    id: 2052,
    title: 'Other word',
    label: 'Other word',
    image: 'https://img.test/other-option-word.jpg',
    audio: 'https://audio.test/other-option-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    wordsByCategory: {
      [category]: [replayWord, otherWord]
    },
    optionWordsByCategory: {
      [category]: [replayWord, otherWord]
    },
    state: {
      categoryNames: [category],
      currentCategoryName: category,
      currentCategoryRoundCount: 1,
      categoryRoundCount: {
        [category]: 1
      },
      categoryRepetitionQueues: {
        [category]: [{ wordData: replayWord, reappearRound: 0, forceReplay: true }]
      },
      lastWordShownId: otherWord.id
    }
  });

  const targetMeta = await page.evaluate(() => {
    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    return target ? {
      id: Number(target.id) || 0,
      categoryName: String(target.__categoryName || ''),
      isForcedReplay: !!target.__practiceForcedReplay
    } : null;
  });

  expect(targetMeta).toEqual({
    id: 2051,
    categoryName: category,
    isForcedReplay: true
  });
});

test('practice selector bridges to an already covered word before replaying the wrong word', async ({ page }) => {
  const replayCategory = 'Replay category';
  const bridgeCategory = 'Bridge category';
  const replayWord = {
    id: 2101,
    title: 'Replay word',
    label: 'Replay word',
    image: 'https://img.test/replay-word.jpg',
    audio: 'https://audio.test/replay-word.mp3'
  };
  const bridgeWord = {
    id: 2102,
    title: 'Bridge word',
    label: 'Bridge word',
    image: 'https://img.test/bridge-word.jpg',
    audio: 'https://audio.test/bridge-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: replayCategory, prompt_type: 'audio', option_type: 'image' },
      { name: bridgeCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: replayCategory,
    wordsByCategory: {
      [replayCategory]: [replayWord],
      [bridgeCategory]: [bridgeWord]
    },
    optionWordsByCategory: {
      [replayCategory]: [replayWord],
      [bridgeCategory]: [bridgeWord]
    },
    state: {
      categoryNames: [replayCategory],
      initialCategoryNames: [replayCategory, bridgeCategory],
      currentCategoryName: replayCategory,
      currentCategoryRoundCount: 3,
      categoryRoundCount: {
        [replayCategory]: 3,
        [bridgeCategory]: 1
      },
      categoryRepetitionQueues: {
        [replayCategory]: [{ wordData: replayWord, reappearRound: 0, forceReplay: true }]
      },
      quizResults: {
        correctOnFirstTry: 1,
        incorrect: [2101],
        wordAttempts: {
          2101: { seen: 1, clean: 0, hadWrong: true },
          2102: { seen: 1, clean: 1, hadWrong: false }
        }
      },
      usedWordIDs: [2101, 2102],
      lastWordShownId: 2101
    }
  });

  const targetMeta = await page.evaluate(() => {
    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    return target ? {
      id: Number(target.id) || 0,
      categoryName: String(target.__categoryName || '')
    } : null;
  });

  expect(targetMeta).toEqual({
    id: 2102,
    categoryName: bridgeCategory
  });
});

test('practice selector refuses immediate repeat when no legal bridge word exists', async ({ page }) => {
  const category = 'Replay category';
  const replayWord = {
    id: 2151,
    title: 'Replay word',
    label: 'Replay word',
    image: 'https://img.test/replay-only-word.jpg',
    audio: 'https://audio.test/replay-only-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    wordsByCategory: {
      [category]: [replayWord]
    },
    optionWordsByCategory: {
      [category]: [replayWord]
    },
    state: {
      categoryNames: [category],
      initialCategoryNames: [category],
      currentCategoryName: category,
      currentCategoryRoundCount: 3,
      categoryRoundCount: {
        [category]: 3
      },
      categoryRepetitionQueues: {
        [category]: [{ wordData: replayWord, reappearRound: 0, forceReplay: true }]
      },
      quizResults: {
        correctOnFirstTry: 0,
        incorrect: [2151],
        wordAttempts: {
          2151: { seen: 1, clean: 0, hadWrong: true }
        }
      },
      usedWordIDs: [2151],
      lastWordShownId: 2151
    }
  });

  const targetId = await page.evaluate(() => {
    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    return target ? Number(target.id) : 0;
  });

  expect(targetId).toBe(0);
});

test('practice selector ends cleanly when no replay is pending instead of adding a bridge word', async ({ page }) => {
  const finishedCategory = 'Finished category';
  const seenOtherCategory = 'Seen category';
  const finishedWord = {
    id: 2171,
    title: 'Finished word',
    label: 'Finished word',
    image: 'https://img.test/finished-word.jpg',
    audio: 'https://audio.test/finished-word.mp3'
  };
  const seenWord = {
    id: 2172,
    title: 'Seen word',
    label: 'Seen word',
    image: 'https://img.test/seen-word.jpg',
    audio: 'https://audio.test/seen-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: finishedCategory, prompt_type: 'audio', option_type: 'image' },
      { name: seenOtherCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: finishedCategory,
    wordsByCategory: {
      [finishedCategory]: [finishedWord],
      [seenOtherCategory]: [seenWord]
    },
    optionWordsByCategory: {
      [finishedCategory]: [finishedWord],
      [seenOtherCategory]: [seenWord]
    },
    state: {
      categoryNames: [finishedCategory],
      initialCategoryNames: [finishedCategory, seenOtherCategory],
      currentCategoryName: finishedCategory,
      currentCategoryRoundCount: 3,
      categoryRoundCount: {
        [finishedCategory]: 3,
        [seenOtherCategory]: 1
      },
      categoryRepetitionQueues: {},
      practiceForcedReplays: {},
      quizResults: {
        correctOnFirstTry: 2,
        incorrect: [],
        wordAttempts: {
          2171: { seen: 1, clean: 1, hadWrong: false },
          2172: { seen: 1, clean: 1, hadWrong: false }
        }
      },
      usedWordIDs: [2171, 2172],
      lastWordShownId: 2171
    }
  });

  const targetId = await page.evaluate(() => {
    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    return target ? Number(target.id) : 0;
  });

  expect(targetId).toBe(0);
});

test('practice selector keeps unloaded categories pending instead of completing them', async ({ page }) => {
  const loadedCategory = 'Loaded category';
  const pendingCategory = 'Pending category';
  const loadedWord = {
    id: 2181,
    title: 'Loaded word',
    label: 'Loaded word',
    image: 'https://img.test/loaded-word.jpg',
    audio: 'https://audio.test/loaded-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [
      { name: loadedCategory, prompt_type: 'audio', option_type: 'image' },
      { name: pendingCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: loadedCategory,
    wordsByCategory: {
      [loadedCategory]: [loadedWord],
      [pendingCategory]: []
    },
    optionWordsByCategory: {
      [loadedCategory]: [loadedWord],
      [pendingCategory]: []
    },
    state: {
      categoryNames: [loadedCategory, pendingCategory],
      initialCategoryNames: [loadedCategory, pendingCategory],
      currentCategoryName: loadedCategory,
      currentCategory: [loadedWord],
      categoryRoundCount: {
        [loadedCategory]: 1,
        [pendingCategory]: 0
      },
      currentCategoryRoundCount: 1,
      usedWordIDs: [2181],
      completedCategories: {}
    }
  });

  const selectionState = await page.evaluate(() => {
    window.__practiceQueueLoads = [];
    window.FlashcardLoader = {
      isCategoryLoaded(name) {
        return String(name || '') === 'Loaded category';
      }
    };
    window.LLFlashcards.Modes = window.LLFlashcards.Modes || {};
    window.LLFlashcards.Modes.Practice = {
      queueCategoryLoad(name) {
        window.__practiceQueueLoads.push(String(name || ''));
      }
    };

    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    const completed = window.LLFlashcards.State.completedCategories || {};
    return {
      targetId: target ? Number(target.id) : 0,
      pendingCompleted: !!completed['Pending category'],
      queueLoads: Array.isArray(window.__practiceQueueLoads) ? window.__practiceQueueLoads.slice() : []
    };
  });

  expect(selectionState.targetId).toBe(0);
  expect(selectionState.pendingCompleted).toBeFalsy();
  expect(selectionState.queueLoads).toEqual([pendingCategory]);
});

test('practice selector still avoids immediate repeat when another prompt word exists', async ({ page }) => {
  const category = 'Alternate category';
  const replayWord = {
    id: 2201,
    title: 'Replay word',
    label: 'Replay word',
    image: 'https://img.test/replay-word.jpg',
    audio: 'https://audio.test/replay-word.mp3'
  };
  const alternateWord = {
    id: 2202,
    title: 'Alternate word',
    label: 'Alternate word',
    image: 'https://img.test/alternate-word.jpg',
    audio: 'https://audio.test/alternate-word.mp3'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    wordsByCategory: {
      [category]: [replayWord, alternateWord]
    },
    optionWordsByCategory: {
      [category]: [replayWord, alternateWord]
    },
    state: {
      categoryNames: [category],
      currentCategoryName: category,
      currentCategoryRoundCount: 3,
      categoryRoundCount: {
        [category]: 3
      },
      categoryRepetitionQueues: {
        [category]: [{ wordData: replayWord, reappearRound: 0, forceReplay: true }]
      },
      usedWordIDs: [2201],
      lastWordShownId: 2201
    }
  });

  const targetId = await page.evaluate(() => {
    const target = window.LLFlashcards.Selection.selectTargetWordAndCategory();
    return target ? Number(target.id) : 0;
  });

  expect(targetId).toBe(2202);
});

test('practice mode ends cleanly instead of restarting forever when only the last shown replay remains', async ({ page }) => {
  const category = 'Replay category';
  const replayWord = {
    id: 2251,
    title: 'Replay word',
    label: 'Replay word',
    image: 'https://img.test/replay-practice-word.jpg',
    audio: 'https://audio.test/replay-practice-word.mp3'
  };

  await mountPracticeModeHarness(page, {
    state: {
      currentCategoryName: category,
      categoryNames: [category],
      wordsByCategory: {
        [category]: [replayWord]
      },
      categoryRepetitionQueues: {
        [category]: [{ wordData: replayWord, reappearRound: 0, forceReplay: true }]
      },
      practiceForcedReplays: {
        2251: 1
      },
      lastWordShownId: 2251
    },
    hasPracticeBridgeWordAvailable: false
  });

  const outcome = await page.evaluate(() => {
    window.LLFlashcards.Modes.Practice.handleNoTarget({
      startQuizRound: function () {
        window.__practiceStartQuizRoundCalls += 1;
      }
    });

    return {
      startCalls: window.__practiceStartQuizRoundCalls,
      showResultsCalls: window.__practiceShowResultsCalls,
      transition: window.__practiceLastTransition
    };
  });

  expect(outcome.startCalls).toBe(0);
  expect(outcome.showResultsCalls).toBe(1);
  expect(outcome.transition).toEqual({
    state: 'showing_results',
    reason: 'Practice replay deadlock avoided'
  });
});

test('practice mode waits for pending category loads before showing results', async ({ page }) => {
  await mountPracticeModeHarness(page, {
    state: {
      currentCategoryName: 'Loaded category',
      categoryNames: ['Loaded category', 'Pending category'],
      initialCategoryNames: ['Loaded category', 'Pending category'],
      wordsByCategory: {
        'Loaded category': [],
        'Pending category': []
      },
      categoryRoundCount: {
        'Loaded category': 1,
        'Pending category': 0
      },
      currentCategoryRoundCount: 2,
      completedCategories: {}
    }
  });

  await page.evaluate(() => {
    window.FlashcardLoader = {
      loadedCategories: [],
      loadResourcesForCategory(name, callback) {
        if (String(name || '') !== 'Pending category') {
          return;
        }
        setTimeout(() => {
          window.LLFlashcards.State.wordsByCategory['Pending category'] = [{
            id: 3001,
            title: 'Pending word',
            label: 'Pending word'
          }];
          if (typeof callback === 'function') {
            callback();
          }
        }, 40);
      },
      isCategoryLoaded(name) {
        const rows = window.LLFlashcards.State.wordsByCategory[String(name || '')] || [];
        return Array.isArray(rows) && rows.length > 0;
      },
      isCategoryLoading() {
        return false;
      }
    };
  });

  const outcome = await page.evaluate(async () => {
    window.LLFlashcards.Modes.Practice.handleNoTarget({
      startQuizRound: function () {
        window.__practiceStartQuizRoundCalls += 1;
      }
    });

    await new Promise((resolve) => setTimeout(resolve, 180));

    return {
      startCalls: window.__practiceStartQuizRoundCalls,
      showResultsCalls: window.__practiceShowResultsCalls
    };
  });

  expect(outcome.startCalls).toBe(1);
  expect(outcome.showResultsCalls).toBe(0);
});

test('practice options never include duplicate images', async ({ page }) => {
  const category = 'Baby animals';
  const targetWord = { id: 301, title: 'Bear cub', label: 'Bear cub', image: 'https://img.test/bear.jpg', audio: 'https://audio.test/bear.mp3' };
  const chickA = { id: 302, title: 'Chick one', label: 'Chick one', image: 'https://img.test/chick.jpg', audio: 'https://audio.test/chick-a.mp3' };
  const chickB = { id: 303, title: 'Chick two', label: 'Chick two', image: 'https://img.test/chick.jpg', audio: 'https://audio.test/chick-b.mp3' };
  const lamb = { id: 304, title: 'Lamb', label: 'Lamb', image: 'https://img.test/lamb.jpg', audio: 'https://audio.test/lamb.mp3' };

  await mountSelectionHarness(page, {
    categories: [
      { name: category, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: category,
    desiredCount: 5,
    wordsByCategory: {
      [category]: [targetWord]
    },
    optionWordsByCategory: {
      [category]: [targetWord, chickA, chickB, lamb]
    },
    state: {
      categoryNames: [category]
    }
  });

  const picked = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Baby animals' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container')).map((el) => ({
      id: Number(el.getAttribute('data-word-id')) || 0,
      image: String(el.getAttribute('data-word-image') || '')
    }));
  }, targetWord);

  const chickCount = picked.filter((row) => row.image === 'https://img.test/chick.jpg').length;
  expect(chickCount).toBe(1);
  expect(picked.some((row) => row.id === 302)).toBe(true);
  expect(picked.some((row) => row.id === 303)).toBe(false);
});

test('specific wrong-answer list overrides normal distractors', async ({ page }) => {
  const category = 'Override category';
  const targetWord = {
    id: 401,
    title: 'Owner',
    label: 'Owner',
    image: 'https://img.test/owner.jpg',
    audio: 'https://audio.test/owner.mp3',
    specific_wrong_answer_ids: [402, 403]
  };
  const specifiedA = { id: 402, title: 'Specified A', label: 'Specified A', image: 'https://img.test/spec-a.jpg', audio: 'https://audio.test/spec-a.mp3' };
  const specifiedB = { id: 403, title: 'Specified B', label: 'Specified B', image: 'https://img.test/spec-b.jpg', audio: 'https://audio.test/spec-b.mp3' };
  const normalDistractor = { id: 404, title: 'Normal distractor', label: 'Normal distractor', image: 'https://img.test/normal.jpg', audio: 'https://audio.test/normal.mp3' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 6,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, normalDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, normalDistractor, specifiedA, specifiedB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Override category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([401, 402, 403]);
  expect(pickedIds.includes(404)).toBe(false);
});

test('specific wrong-answer list respects card-cap and shows as many as fit', async ({ page }) => {
  const category = 'Cap category';
  const targetWord = {
    id: 451,
    title: 'Owner',
    label: 'Owner',
    image: 'https://img.test/owner-2.jpg',
    audio: 'https://audio.test/owner-2.mp3',
    specific_wrong_answer_ids: [452, 453, 454]
  };
  const specifiedA = { id: 452, title: 'Specified A', label: 'Specified A', image: 'https://img.test/spec2-a.jpg', audio: 'https://audio.test/spec2-a.mp3' };
  const specifiedB = { id: 453, title: 'Specified B', label: 'Specified B', image: 'https://img.test/spec2-b.jpg', audio: 'https://audio.test/spec2-b.mp3' };
  const specifiedC = { id: 454, title: 'Specified C', label: 'Specified C', image: 'https://img.test/spec2-c.jpg', audio: 'https://audio.test/spec2-c.mp3' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 8,
    maxCards: 3,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, specifiedC]
    },
    optionWordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, specifiedC]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Cap category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([451, 452, 453]);
  expect(pickedIds.includes(454)).toBe(false);
});

test('reserved wrong-answer words are not used as distractors for other targets', async ({ page }) => {
  const category = 'Owner scope category';
  const targetWord = { id: 501, title: 'Target', label: 'Target', image: 'https://img.test/tgt.jpg', audio: 'https://audio.test/tgt.mp3' };
  const allowedDistractor = { id: 502, title: 'Allowed distractor', label: 'Allowed distractor', image: 'https://img.test/allowed.jpg', audio: 'https://audio.test/allowed.mp3' };
  const reservedForOther = {
    id: 503,
    title: 'Reserved for other',
    label: 'Reserved for other',
    image: 'https://img.test/reserved.jpg',
    audio: 'https://audio.test/reserved.mp3',
    specific_wrong_answer_owner_ids: [999]
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord, allowedDistractor, reservedForOther]
    },
    optionWordsByCategory: {
      [category]: [targetWord, reservedForOther, allowedDistractor]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Owner scope category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([501, 502]);
  expect(pickedIds.includes(503)).toBe(false);
});

test('text-to-text mode pulls wrong answers from other text options', async ({ page }) => {
  const category = 'Text only category';
  const targetWord = { id: 601, title: 'Casa', label: 'House' };
  const distractorA = { id: 602, title: 'Perro', label: 'Dog' };
  const distractorB = { id: 603, title: 'Gato', label: 'Cat' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'text_translation', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 3,
    wordsByCategory: {
      [category]: [targetWord, distractorA, distractorB]
    },
    optionWordsByCategory: {
      [category]: [targetWord, distractorA, distractorB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Text only category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([601, 602, 603]);
});

test('text-to-text mode respects specific wrong-answer overrides', async ({ page }) => {
  const category = 'Text override category';
  const targetWord = {
    id: 651,
    title: 'Owner',
    label: 'Owner',
    specific_wrong_answer_ids: [652, 653]
  };
  const specifiedA = { id: 652, title: 'Specified A', label: 'Specified A' };
  const specifiedB = { id: 653, title: 'Specified B', label: 'Specified B' };
  const normalDistractor = { id: 654, title: 'Normal distractor', label: 'Normal distractor' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'text_translation', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 6,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, normalDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, normalDistractor, specifiedA, specifiedB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Text override category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([651, 652, 653]);
  expect(pickedIds.includes(654)).toBe(false);
});

test('text-to-text mode uses specific wrong-answer texts without backing word posts', async ({ page }) => {
  const category = 'Text meta override category';
  const targetWord = {
    id: 661,
    title: 'Correct',
    label: 'Correct',
    specific_wrong_answer_texts: ['Wrong One', 'Wrong Two']
  };
  const normalDistractor = { id: 662, title: 'Normal distractor', label: 'Normal distractor' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'text_translation', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 6,
    wordsByCategory: {
      [category]: [targetWord, normalDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, normalDistractor]
    }
  });

  const picked = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Text meta override category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => String(el.getAttribute('data-word-id') || ''));
  }, targetWord);

  expect(picked).toContain('661');
  expect(picked).toContain('661-wrong-text-1');
  expect(picked).toContain('661-wrong-text-2');
  expect(picked).not.toContain('662');
});

test('falls back to category distractors when specific wrong-answer IDs are broken', async ({ page }) => {
  const category = 'Broken overrides';
  const targetWord = {
    id: 701,
    title: 'Owner',
    label: 'Owner',
    specific_wrong_answer_ids: [999]
  };
  const availableDistractor = {
    id: 702,
    title: 'Available',
    label: 'Available'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord, availableDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, availableDistractor]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Broken overrides' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([701, 702]);
});

test('learning mode matches introduced IDs even when word payload IDs are strings', async ({ page }) => {
  const category = 'Learning string IDs';
  const targetWord = {
    id: '801',
    title: 'Target',
    label: 'Target'
  };
  const introducedDistractor = {
    id: '802',
    title: 'Distractor',
    label: 'Distractor'
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord, introducedDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, introducedDistractor]
    },
    state: {
      isLearningMode: true,
      categoryNames: [category],
      introducedWordIDs: [801, 802],
      wordsByCategory: {
        [category]: [targetWord, introducedDistractor]
      }
    }
  });

  const result = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Learning string IDs' }, word);
    try {
      window.LLFlashcards.Selection.fillQuizOptions(target);
      const ids = Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
        .map((el) => Number(el.getAttribute('data-word-id')) || 0)
        .filter((id) => id > 0);
      return { threw: false, ids };
    } catch (error) {
      return {
        threw: true,
        code: String((error && error.code) || '')
      };
    }
  }, targetWord);

  expect(result.threw).toBe(false);
  expect(result.ids).toEqual([801, 802]);
});

test('throws a hard error when fewer than two options are truly available', async ({ page }) => {
  const category = 'Unrecoverable overrides';
  const targetWord = {
    id: 711,
    title: 'Solo',
    label: 'Solo',
    specific_wrong_answer_ids: [999]
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord]
    },
    optionWordsByCategory: {
      [category]: [targetWord]
    }
  });

  const result = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Unrecoverable overrides' }, word);
    try {
      window.LLFlashcards.Selection.fillQuizOptions(target);
      return { threw: false, code: '' };
    } catch (error) {
      return {
        threw: true,
        code: String((error && error.code) || ''),
        message: String((error && error.message) || '')
      };
    }
  }, targetWord);

  expect(result.threw).toBe(true);
  expect(result.code).toBe('LL_MINIMUM_OPTIONS_VIOLATION');
});
