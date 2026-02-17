const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const genderScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/modes/gender.js');
const BASE_URL = process.env.LL_E2E_BASE_URL || 'https://starter-english-local.local';

function makeNounWord(id, categoryName, gender = 'masculine', extras = {}) {
  const base = {
    id,
    title: `word-${id}`,
    label: `word-${id}`,
    image: `image-${id}.jpg`,
    audio: `audio-${id}.mp3`,
    grammatical_gender: gender,
    part_of_speech: ['noun'],
    all_categories: [categoryName]
  };
  return Object.assign(base, extras || {});
}

async function openHarnessPage(page) {
  const url = `${String(BASE_URL).replace(/\/$/, '')}/`;
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.setContent('<!doctype html><html><head></head><body></body></html>');
}

function bootstrapGenderHarness(page, options = {}) {
  const wordsetId = Number(options.wordsetId || 77);
  const categoryWords = options.categoryWords || {};
  const launchSource = String(options.launchSource || 'direct');
  const launchContext = String(options.launchContext || '');
  const sessionPlan = options.sessionPlan && typeof options.sessionPlan === 'object'
    ? options.sessionPlan
    : null;
  const preseedStore = options.preseedStore && typeof options.preseedStore === 'object'
    ? options.preseedStore
    : null;
  const trackIntroCalls = !!options.trackIntroCalls;
  const trackFeedbackOrdering = !!options.trackFeedbackOrdering;

  return page.evaluate(({ wordsetId: wsId, wordsByCategory, source, context, plan, seedStore, shouldTrackIntroCalls, shouldTrackFeedbackOrdering }) => {
    window.localStorage.clear();
    window.__llIntroPlayCount = 0;
    window.__llEventLog = [];

    if (seedStore) {
      window.localStorage.setItem(
        `lltools_gender_progress_v1::wordset:${wsId}`,
        JSON.stringify(seedStore)
      );
    }

    window.llToolsFlashcardsData = {
      genderOptions: ['masculine', 'feminine'],
      userStudyState: { wordset_id: wsId },
      genderLaunchSource: source
    };
    if (context) {
      window.llToolsFlashcardsData.launchContext = context;
      window.llToolsFlashcardsData.launch_context = context;
    }
    if (plan) {
      window.llToolsFlashcardsData.genderSessionPlan = plan;
      window.llToolsFlashcardsData.genderSessionPlanArmed = true;
      window.llToolsFlashcardsData.gender_session_plan_armed = true;
    }

    window.LLFlashcards = {
      State: {
        STATES: {
          INTRODUCING_WORDS: 'INTRODUCING_WORDS',
          QUIZ_READY: 'QUIZ_READY',
          SHOWING_RESULTS: 'SHOWING_RESULTS'
        },
        wordsByCategory,
        categoryNames: Object.keys(wordsByCategory),
        categoryRoundCount: {},
        currentCategoryRoundCount: 0,
        currentCategoryName: '',
        currentCategory: [],
        completedCategories: {},
        wrongIndexes: [],
        abortAllOperations: false,
        addTimeout: function () {},
        transitionTo: function () {}
      },
      Selection: {
        getCategoryConfig: function () {
          return {
            prompt_type: 'audio',
            option_type: 'image'
          };
        }
      },
      Dom: {},
      Effects: {
        startConfetti: function () {}
      },
      Results: {
        showResults: function () {}
      },
      Util: {},
      Modes: {}
    };

    window.FlashcardAudio = {
      selectBestAudio: function (word) {
        return (word && word.audio) || '';
      },
      createIntroductionAudio: function () {
        return {
          audio: null,
          playUntilEnd: function () {
            if (shouldTrackFeedbackOrdering) {
              window.__llEventLog.push('intro');
            }
            if (shouldTrackIntroCalls) {
              window.__llIntroPlayCount = (Number(window.__llIntroPlayCount) || 0) + 1;
            }
            return Promise.resolve();
          },
          stop: function () {},
          cleanup: function () {}
        };
      },
      getCorrectAudioURL: function () {
        return 'feedback-correct.mp3';
      },
      getWrongAudioURL: function () {
        return 'feedback-wrong.mp3';
      },
      createAudio: function (url) {
        const listeners = { ended: [], error: [] };
        const audio = {
          src: String(url || ''),
          onended: null,
          onerror: null,
          addEventListener: function (type, handler) {
            if (!listeners[type] || typeof handler !== 'function') return;
            listeners[type].push(handler);
          },
          removeEventListener: function (type, handler) {
            if (!listeners[type]) return;
            listeners[type] = listeners[type].filter((fn) => fn !== handler);
          },
          __emit: function (type) {
            const callbacks = (listeners[type] || []).slice();
            callbacks.forEach((fn) => {
              try { fn.call(audio); } catch (_) {}
            });
            if (type === 'ended' && typeof audio.onended === 'function') {
              try { audio.onended(); } catch (_) {}
            }
            if (type === 'error' && typeof audio.onerror === 'function') {
              try { audio.onerror(); } catch (_) {}
            }
          }
        };
        return audio;
      },
      playAudio: function (audio) {
        return new Promise((resolve) => {
          const src = String((audio && audio.src) || '');
          if (shouldTrackFeedbackOrdering && src.includes('feedback-wrong')) {
            window.__llEventLog.push('wrong-feedback');
          }
          if (shouldTrackFeedbackOrdering && src.includes('feedback-correct')) {
            window.__llEventLog.push('correct-feedback');
          }
          setTimeout(() => {
            if (audio && typeof audio.__emit === 'function') {
              audio.__emit('ended');
            }
            resolve();
          }, 8);
        });
      },
      pauseAllAudio: function () {},
      setTargetAudioHasPlayed: function () {}
    };
  }, {
    wordsetId,
    wordsByCategory: categoryWords,
    source: launchSource,
    context: launchContext,
    plan: sessionPlan,
    seedStore: preseedStore,
    shouldTrackIntroCalls: trackIntroCalls,
    shouldTrackFeedbackOrdering: trackFeedbackOrdering
  });
}

test('gender mode treats "I do not know" as wrong and requires two consecutive correct answers after a miss', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 77,
    categoryWords: {
      CatA: [makeNounWord(101, 'CatA', 'masculine')]
    },
    sessionPlan: {
      level: 3,
      word_ids: [101],
      launch_source: 'dashboard',
      reason_code: 'test_level3'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const first = Gender.selectTargetWord();
    const wrong = await Gender.handleAnswer({
      targetWord: first,
      isCorrect: false,
      isDontKnow: true
    });

    const second = Gender.selectTargetWord();
    const firstCorrect = await Gender.handleAnswer({
      targetWord: second,
      isCorrect: true,
      isDontKnow: false
    });

    const third = Gender.selectTargetWord();
    const secondCorrect = await Gender.handleAnswer({
      targetWord: third,
      isCorrect: true,
      isDontKnow: false
    });

    const saved = JSON.parse(
      window.localStorage.getItem('lltools_gender_progress_v1::wordset:77') || '{}'
    );
    const entry = (saved.words && saved.words['101']) || {};

    return {
      wrongCompleted: !!wrong.completed,
      firstCorrectCompleted: !!firstCorrect.completed,
      secondCorrectCompleted: !!secondCorrect.completed,
      wrongMarkedDontKnow: !!(wrong.progressPayload && wrong.progressPayload.gender_dont_know),
      dontKnowCount: Number(entry.dont_know_count || 0),
      levelAfterRun: Number(entry.level || 0)
    };
  });

  expect(result.wrongCompleted).toBe(false);
  expect(result.firstCorrectCompleted).toBe(false);
  expect(result.secondCorrectCompleted).toBe(true);
  expect(result.wrongMarkedDontKnow).toBe(true);
  expect(result.dontKnowCount).toBeGreaterThanOrEqual(1);
  expect(result.levelAfterRun).toBe(3);
});

test('level-one gender starts with a two-word intro batch instead of introducing the full chunk at once', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 66,
    categoryWords: {
      CatA: [
        makeNounWord(601, 'CatA', 'masculine'),
        makeNounWord(602, 'CatA', 'feminine'),
        makeNounWord(603, 'CatA', 'masculine'),
        makeNounWord(604, 'CatA', 'feminine'),
        makeNounWord(605, 'CatA', 'masculine')
      ]
    },
    sessionPlan: {
      level: 1,
      word_ids: [601, 602, 603, 604, 605],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_level1_intro_batch'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const firstPick = Gender.selectTargetWord();
    const ids = Array.isArray(firstPick)
      ? firstPick.map((word) => Number(word && word.id) || 0).filter((id) => id > 0)
      : [];
    return {
      introCount: ids.length,
      uniqueCount: Array.from(new Set(ids)).length
    };
  });

  expect(result.introCount).toBe(2);
  expect(result.uniqueCount).toBe(2);
});

test('level-one still schedules intro for the first pair even when words were introduced before', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 68,
    categoryWords: {
      CatA: [
        makeNounWord(681, 'CatA', 'masculine'),
        makeNounWord(682, 'CatA', 'feminine'),
        makeNounWord(683, 'CatA', 'masculine')
      ]
    },
    sessionPlan: {
      level: 1,
      word_ids: [681, 682, 683],
      launch_source: 'direct',
      force_intro: false,
      reason_code: 'test_level1_intro_even_if_seen'
    },
    preseedStore: {
      words: {
        '681': { level: 1, intro_seen: true, seen_total: 5 },
        '682': { level: 1, intro_seen: true, seen_total: 4 },
        '683': { level: 1, intro_seen: true, seen_total: 3 }
      },
      updated_at: Date.now()
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const firstPick = Gender.selectTargetWord();
    const ids = Array.isArray(firstPick)
      ? firstPick.map((word) => Number(word && word.id) || 0).filter((id) => id > 0)
      : [];
    return {
      introCount: ids.length,
      uniqueCount: Array.from(new Set(ids)).length
    };
  });

  expect(result.introCount).toBe(2);
  expect(result.uniqueCount).toBe(2);
});

test('level-one introduces the third word after one successful pass on the first pair', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <head></head>
      <body>
        <div id="ll-tools-category-display"></div>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
        <div id="ll-tools-flashcard"></div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });

  await bootstrapGenderHarness(page, {
    wordsetId: 78,
    categoryWords: {
      CatA: [
        makeNounWord(781, 'CatA', 'masculine'),
        makeNounWord(782, 'CatA', 'feminine'),
        makeNounWord(783, 'CatA', 'masculine')
      ]
    },
    sessionPlan: {
      level: 1,
      word_ids: [781, 782, 783],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_level1_intro_then_third_word'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const firstIntro = Gender.selectTargetWord();
    Gender.handlePostSelection(firstIntro, { startQuizRound: function () {} });
    await wait(4300);

    let answersBeforeThirdIntro = 0;
    let thirdIntroLength = 0;
    for (let i = 0; i < 8; i++) {
      const pick = Gender.selectTargetWord();
      if (Array.isArray(pick)) {
        thirdIntroLength = pick.length;
        break;
      }
      answersBeforeThirdIntro += 1;
      await Gender.handleAnswer({
        targetWord: pick,
        isCorrect: true,
        isDontKnow: false
      });
    }

    return {
      firstIntroLength: Array.isArray(firstIntro) ? firstIntro.length : 0,
      answersBeforeThirdIntro,
      thirdIntroLength
    };
  });

  expect(result.firstIntroLength).toBe(2);
  expect(result.answersBeforeThirdIntro).toBe(2);
  expect(result.thirdIntroLength).toBe(1);
});

test('level-one intro sequence plays three clips when only one recording is available', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <head></head>
      <body>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
        <div id="ll-tools-flashcard"></div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });

  await bootstrapGenderHarness(page, {
    wordsetId: 69,
    categoryWords: {
      CatA: [makeNounWord(691, 'CatA', 'masculine')]
    },
    sessionPlan: {
      level: 1,
      word_ids: [691],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_single_recording_intro_repeats'
    },
    trackIntroCalls: true
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const introStats = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const firstPick = Gender.selectTargetWord();
    Gender.handlePostSelection(firstPick, {
      startQuizRound: function () {}
    });

    await new Promise((resolve) => setTimeout(resolve, 2800));
    return {
      introCalls: Number(window.__llIntroPlayCount || 0)
    };
  });

  expect(introStats.introCalls).toBe(3);
});

test('level-one requires three correct answers before a word is marked complete', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <head></head>
      <body>
        <div id="ll-tools-category-display"></div>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
        <div id="ll-tools-flashcard"></div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });

  await bootstrapGenderHarness(page, {
    wordsetId: 79,
    categoryWords: {
      CatA: [makeNounWord(791, 'CatA', 'masculine')]
    },
    sessionPlan: {
      level: 1,
      word_ids: [791],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_level1_three_correct_required'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const intro = Gender.selectTargetWord();
    Gender.handlePostSelection(intro, { startQuizRound: function () {} });
    await wait(2600);

    const one = Gender.selectTargetWord();
    const first = await Gender.handleAnswer({
      targetWord: one,
      isCorrect: true,
      isDontKnow: false
    });

    const two = Gender.selectTargetWord();
    const second = await Gender.handleAnswer({
      targetWord: two,
      isCorrect: true,
      isDontKnow: false
    });

    const three = Gender.selectTargetWord();
    const third = await Gender.handleAnswer({
      targetWord: three,
      isCorrect: true,
      isDontKnow: false
    });

    return {
      firstCompleted: !!first.completed,
      secondCompleted: !!second.completed,
      thirdCompleted: !!third.completed
    };
  });

  expect(result.firstCompleted).toBe(false);
  expect(result.secondCompleted).toBe(false);
  expect(result.thirdCompleted).toBe(true);
});

test('level-one does not introduce the next word immediately after the first correct answer on a newly introduced word', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <head></head>
      <body>
        <div id="ll-tools-category-display"></div>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
        <div id="ll-tools-flashcard"></div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });

  await bootstrapGenderHarness(page, {
    wordsetId: 80,
    categoryWords: {
      CatA: [
        makeNounWord(801, 'CatA', 'masculine'),
        makeNounWord(802, 'CatA', 'feminine'),
        makeNounWord(803, 'CatA', 'masculine'),
        makeNounWord(804, 'CatA', 'feminine')
      ]
    },
    sessionPlan: {
      level: 1,
      word_ids: [801, 802, 803, 804],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_level1_no_early_next_intro'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const firstIntro = Gender.selectTargetWord();
    Gender.handlePostSelection(firstIntro, { startQuizRound: function () {} });
    await wait(4300);

    let thirdIntroSelection = null;
    for (let i = 0; i < 40; i++) {
      const pick = Gender.selectTargetWord();
      if (Array.isArray(pick) && pick.length === 1) {
        thirdIntroSelection = pick;
        break;
      }
      if (!Array.isArray(pick)) {
        await Gender.handleAnswer({
          targetWord: pick,
          isCorrect: true,
          isDontKnow: false
        });
      }
    }

    if (!thirdIntroSelection) {
      return {
        foundThirdIntro: false,
        introducedTooSoon: false
      };
    }

    const thirdWordId = Number(thirdIntroSelection[0] && thirdIntroSelection[0].id) || 0;
    Gender.handlePostSelection(thirdIntroSelection, { startQuizRound: function () {} });
    await wait(2600);

    let introducedTooSoon = false;
    let sawFirstCorrectForThirdWord = false;
    for (let i = 0; i < 30; i++) {
      const pick = Gender.selectTargetWord();
      if (Array.isArray(pick)) {
        introducedTooSoon = true;
        break;
      }
      await Gender.handleAnswer({
        targetWord: pick,
        isCorrect: true,
        isDontKnow: false
      });
      if ((Number(pick && pick.id) || 0) === thirdWordId) {
        sawFirstCorrectForThirdWord = true;
        const nextPick = Gender.selectTargetWord();
        introducedTooSoon = Array.isArray(nextPick);
        break;
      }
    }

    return {
      foundThirdIntro: true,
      sawFirstCorrectForThirdWord,
      introducedTooSoon
    };
  });

  expect(result.foundThirdIntro).toBe(true);
  expect(result.sawFirstCorrectForThirdWord).toBe(true);
  expect(result.introducedTooSoon).toBe(false);
});

test('wrong-answer feedback plays before intro replay in gender rounds', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 71,
    categoryWords: {
      CatA: [makeNounWord(711, 'CatA', 'masculine', {
        audio_files: [
          { recording_type: 'isolation', url: 'isolation-711.mp3' },
          { recording_type: 'introduction', url: 'intro-711.mp3' }
        ]
      })]
    },
    sessionPlan: {
      level: 3,
      word_ids: [711],
      launch_source: 'direct',
      reason_code: 'test_wrong_feedback_before_intro'
    },
    trackFeedbackOrdering: true
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const word = Gender.selectTargetWord();
    await Gender.handleAnswer({
      targetWord: word,
      isCorrect: false,
      isDontKnow: false
    });
    return {
      eventLog: Array.isArray(window.__llEventLog) ? window.__llEventLog.slice() : []
    };
  });

  const wrongIndex = result.eventLog.indexOf('wrong-feedback');
  const introIndex = result.eventLog.indexOf('intro');
  expect(wrongIndex).toBeGreaterThanOrEqual(0);
  expect(introIndex).toBeGreaterThanOrEqual(0);
  expect(wrongIndex).toBeLessThan(introIndex);
});

test('correct answers do not replay intro audio after selection', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 72,
    categoryWords: {
      CatA: [makeNounWord(721, 'CatA', 'masculine', {
        audio_files: [
          { recording_type: 'isolation', url: 'isolation-721.mp3' },
          { recording_type: 'introduction', url: 'intro-721.mp3' }
        ]
      })]
    },
    sessionPlan: {
      level: 3,
      word_ids: [721],
      launch_source: 'direct',
      reason_code: 'test_no_intro_replay_on_correct'
    },
    trackFeedbackOrdering: true
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const word = Gender.selectTargetWord();
    await Gender.handleAnswer({
      targetWord: word,
      isCorrect: true,
      isDontKnow: false
    });
    return {
      eventLog: Array.isArray(window.__llEventLog) ? window.__llEventLog.slice() : []
    };
  });

  expect(result.eventLog.includes('correct-feedback')).toBe(true);
  expect(result.eventLog.includes('intro')).toBe(false);
});

test('wrong answers skip intro replay when no explicit introduction recording exists', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 73,
    categoryWords: {
      CatA: [makeNounWord(731, 'CatA', 'masculine')]
    },
    sessionPlan: {
      level: 3,
      word_ids: [731],
      launch_source: 'direct',
      reason_code: 'test_no_intro_replay_without_intro_recording'
    },
    trackFeedbackOrdering: true
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const word = Gender.selectTargetWord();
    await Gender.handleAnswer({
      targetWord: word,
      isCorrect: false,
      isDontKnow: true
    });
    return {
      eventLog: Array.isArray(window.__llEventLog) ? window.__llEventLog.slice() : []
    };
  });

  expect(result.eventLog.includes('intro')).toBe(false);
});

test('gender mode never repeats the same word in consecutive rounds when another word is available', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 67,
    categoryWords: {
      CatA: [
        makeNounWord(701, 'CatA', 'masculine'),
        makeNounWord(702, 'CatA', 'feminine')
      ]
    },
    sessionPlan: {
      level: 3,
      word_ids: [701, 702],
      launch_source: 'dashboard',
      reason_code: 'test_no_consecutive_repeat'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const first = Gender.selectTargetWord();
    const firstId = Number(first && first.id) || 0;
    await Gender.handleAnswer({
      targetWord: first,
      isCorrect: false,
      isDontKnow: false
    });

    const second = Gender.selectTargetWord();
    const secondId = Number(second && second.id) || 0;

    return { firstId, secondId };
  });

  expect(result.firstId).toBeGreaterThan(0);
  expect(result.secondId).toBeGreaterThan(0);
  expect(result.secondId).not.toBe(result.firstId);
});

test('vocab lesson launch with mixed levels (1/2/3) starts level one using the full lesson set', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 91,
    launchSource: 'direct',
    launchContext: 'vocab_lesson',
    categoryWords: {
      CatA: [
        makeNounWord(911, 'CatA', 'masculine'),
        makeNounWord(912, 'CatA', 'feminine'),
        makeNounWord(913, 'CatA', 'masculine'),
        makeNounWord(914, 'CatA', 'feminine')
      ]
    },
    preseedStore: {
      words: {
        '911': { level: 1, seen_total: 0, intro_seen: false, confidence: -1 },
        '912': { level: 2, seen_total: 3, intro_seen: true, confidence: 2 },
        '913': { level: 3, seen_total: 9, intro_seen: true, confidence: 7 },
        '914': { level: 3, seen_total: 12, intro_seen: true, confidence: 8 }
      },
      updated_at: Date.now()
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const firstPick = Gender.selectTargetWord();
    const ids = Array.isArray(firstPick)
      ? firstPick.map((word) => Number(word && word.id) || 0).filter((id) => id > 0)
      : [];
    return {
      introCount: ids.length,
      uniqueCount: Array.from(new Set(ids)).length
    };
  });

  // With one level-one word, this proves level-one includes higher-level lesson words.
  expect(result.introCount).toBe(2);
  expect(result.uniqueCount).toBe(2);
});

test('vocab lesson launch with mixed levels (2/3) runs level two across the lesson, not only existing level-two words', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 92,
    launchSource: 'direct',
    launchContext: 'vocab_lesson',
    categoryWords: {
      CatA: [
        makeNounWord(921, 'CatA', 'masculine'),
        makeNounWord(922, 'CatA', 'feminine'),
        makeNounWord(923, 'CatA', 'masculine')
      ]
    },
    preseedStore: {
      words: {
        '921': { level: 2, seen_total: 4, intro_seen: true, confidence: 1 },
        '922': { level: 3, seen_total: 7, intro_seen: true, confidence: 6 },
        '923': { level: 3, seen_total: 9, intro_seen: true, confidence: 7 }
      },
      updated_at: Date.now()
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const first = Gender.selectTargetWord();
    const firstId = Number(first && first.id) || 0;
    await Gender.handleAnswer({
      targetWord: first,
      isCorrect: false,
      isDontKnow: false
    });

    const second = Gender.selectTargetWord();
    const secondId = Number(second && second.id) || 0;
    return { firstId, secondId };
  });

  expect(result.firstId).toBeGreaterThan(0);
  expect(result.secondId).toBeGreaterThan(0);
  expect(result.secondId).not.toBe(result.firstId);
});

test('dashboard gender planning mixes categories into a level chunk when one category dominates low-seen words', async ({ page }) => {
  await openHarnessPage(page);

  const catA = Array.from({ length: 20 }, (_, idx) => makeNounWord(1000 + idx + 1, 'CatA', 'masculine'));
  const catB = [makeNounWord(2001, 'CatB', 'feminine'), makeNounWord(2002, 'CatB', 'feminine')];

  const words = {};
  catA.forEach((word) => {
    words[String(word.id)] = { level: 1, seen_total: 0, intro_seen: false };
  });
  catB.forEach((word) => {
    words[String(word.id)] = { level: 1, seen_total: 10, intro_seen: false };
  });

  await bootstrapGenderHarness(page, {
    wordsetId: 88,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: catA,
      CatB: catB
    },
    preseedStore: {
      words,
      updated_at: Date.now()
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const selection = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const firstPick = Gender.selectTargetWord();
    const introWords = Array.isArray(firstPick) ? firstPick : [];
    const ids = introWords.map((word) => Number(word && word.id) || 0).filter((id) => id > 0);
    const categories = introWords.map((word) => String((word && (word.__categoryName || ((word.all_categories || [])[0] || ''))) || ''));

    return {
      introLength: ids.length,
      hasCatA: categories.includes('CatA'),
      hasCatB: categories.includes('CatB')
    };
  });

  expect(selection.introLength).toBe(2);
  expect(selection.hasCatA).toBe(true);
  expect(selection.hasCatB).toBe(true);
});

test('dashboard gender results always expose both actions and only return chunk categories', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 90,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [makeNounWord(901, 'CatA', 'masculine')],
      CatB: [makeNounWord(902, 'CatB', 'feminine')],
      CatC: [makeNounWord(903, 'CatC', 'masculine')]
    },
    sessionPlan: {
      level: 2,
      word_ids: [901, 902],
      launch_source: 'dashboard',
      reason_code: 'test_dashboard_results_actions_and_categories'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    Gender.selectTargetWord();
    const actions = Gender.getResultsActions();
    const categories = (typeof Gender.getResultsCategoryNames === 'function')
      ? Gender.getResultsCategoryNames()
      : [];
    return {
      hasPrimary: !!(actions && actions.primary),
      hasSecondary: !!(actions && actions.secondary),
      secondaryWordIds: actions && actions.secondary && actions.secondary.plan
        ? (actions.secondary.plan.word_ids || []).map((id) => Number(id) || 0).filter((id) => id > 0)
        : [],
      categories
    };
  });

  expect(result.hasPrimary).toBe(true);
  expect(result.hasSecondary).toBe(true);
  expect(result.secondaryWordIds.length).toBeGreaterThan(0);
  expect(result.categories).toContain('CatA');
  expect(result.categories).toContain('CatB');
  expect(result.categories).not.toContain('CatC');
});
