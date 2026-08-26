const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const genderScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/modes/gender.js');
const resultsScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/results.js');
const BASE_URL = process.env.LL_E2E_BASE_URL || 'https://starter-english-local.local';
const TEST_PROGRESS_SCOPE_A = 'a'.repeat(32);
const TEST_PROGRESS_SCOPE_B = 'b'.repeat(32);

function genderStorageKey({ wordsetId, runtimeMode = 'wp', isUserLoggedIn = false, progressStorageScope = '' }) {
  if (String(runtimeMode).toLowerCase() === 'offline') {
    return `lltools_gender_progress_v1::wordset:${wordsetId}`;
  }
  if (!isUserLoggedIn) {
    return `lltools_gender_progress_v2::guest::wordset:${wordsetId}`;
  }
  return progressStorageScope
    ? `lltools_gender_progress_v2::user:${progressStorageScope}::wordset:${wordsetId}`
    : '';
}

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

function makeGenderProgress(overrides = {}) {
  return Object.assign({
    level: 3,
    confidence: 6,
    intro_seen: true,
    quick_correct_streak: 2,
    level1_passes: 3,
    level1_failures: 0,
    level2_correct: 3,
    level2_wrong: 0,
    level3_correct: 2,
    level3_wrong: 0,
    dont_know_count: 0,
    seen_total: 8,
    category_name: 'CatA',
    last_seen_at: '2026-03-20 10:00:00',
    updated_at: Date.parse('2026-03-20T10:00:00Z')
  }, overrides || {});
}

async function openHarnessPage(page) {
  const url = `${String(BASE_URL).replace(/\/$/, '')}/ll-tools-e2e-gender-harness`;
  await page.route(url, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><html><head></head><body></body></html>'
    });
  });
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
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
  const runtimeMode = String(options.runtimeMode || 'wp');
  const isUserLoggedIn = options.isUserLoggedIn === true;
  const progressStorageScope = String(options.progressStorageScope || '');
  const storageKey = genderStorageKey({
    wordsetId,
    runtimeMode,
    isUserLoggedIn,
    progressStorageScope
  });
  const clearStorage = options.clearStorage !== false;

  return page.evaluate(({ wordsetId: wsId, wordsByCategory, source, context, plan, seedStore, shouldTrackIntroCalls, shouldTrackFeedbackOrdering, runtime, loggedIn, storageScope, seedStorageKey, shouldClearStorage }) => {
    if (shouldClearStorage) {
      window.localStorage.clear();
    }
    window.__llIntroPlayCount = 0;
    window.__llEventLog = [];

    if (seedStore && seedStorageKey) {
      window.localStorage.setItem(
        seedStorageKey,
        JSON.stringify(seedStore)
      );
    }

    window.llToolsFlashcardsData = {
      runtimeMode: runtime,
      isUserLoggedIn: loggedIn,
      progressStorageScope: storageScope,
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
    shouldTrackFeedbackOrdering: trackFeedbackOrdering,
    runtime: runtimeMode,
    loggedIn: isUserLoggedIn,
    storageScope: progressStorageScope,
    seedStorageKey: storageKey,
    shouldClearStorage: clearStorage
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
      window.localStorage.getItem('lltools_gender_progress_v2::guest::wordset:77') || '{}'
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

test('gender mode abort suppresses delayed intro replay after a wrong answer', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 79,
    categoryWords: {
      CatA: [
        makeNounWord(791, 'CatA', 'masculine', {
          introduction_audio_url: 'intro-791.mp3'
        })
      ]
    },
    sessionPlan: {
      level: 3,
      word_ids: [791],
      launch_source: 'dashboard',
      reason_code: 'test_abort_blocks_delayed_intro_replay'
    },
    trackIntroCalls: true
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();

    const target = Gender.selectTargetWord();
    Gender.handleAnswer({
      targetWord: target,
      isCorrect: false,
      isDontKnow: false
    });

    await wait(40);
    window.LLFlashcards.State.abortAllOperations = true;
    await wait(420);

    return {
      introCalls: Number(window.__llIntroPlayCount || 0)
    };
  });

  expect(result.introCalls).toBe(0);
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

test('level-one intro prefers a mixed-gender first pair when available', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 67,
    categoryWords: {
      CatA: [
        makeNounWord(671, 'CatA', 'masculine'),
        makeNounWord(672, 'CatA', 'masculine'),
        makeNounWord(673, 'CatA', 'feminine'),
        makeNounWord(674, 'CatA', 'feminine')
      ]
    },
    sessionPlan: {
      level: 1,
      word_ids: [671, 672, 673, 674],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_level1_intro_mixed_gender_pair'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const firstPick = Gender.selectTargetWord();
    const words = Array.isArray(firstPick) ? firstPick.filter(Boolean) : [];
    const genders = words.map((word) => String(word.grammatical_gender || '').toLowerCase());
    return {
      ids: words.map((word) => Number(word && word.id) || 0).filter((id) => id > 0),
      uniqueGenderCount: Array.from(new Set(genders.filter(Boolean))).length
    };
  });

  expect(result.ids).toHaveLength(2);
  expect(result.uniqueGenderCount).toBe(2);
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
    let pendingPick = null;
    for (let i = 0; i < 240; i++) {
      const probe = Gender.selectTargetWord();
      if (!Array.isArray(probe)) {
        pendingPick = probe;
        break;
      }
      await wait(50);
    }

    let answersBeforeThirdIntro = 0;
    let thirdIntroLength = 0;
    for (let i = 0; i < 8; i++) {
      const pick = pendingPick || Gender.selectTargetWord();
      pendingPick = null;
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

    const startedAt = Date.now();
    while ((Number(window.__llIntroPlayCount) || 0) < 3 && (Date.now() - startedAt) < 12000) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    return {
      introCalls: Number(window.__llIntroPlayCount || 0)
    };
  });

  expect(introStats.introCalls).toBe(3);
});

test('level-one intro clears compact gender-option layout state before rendering intro cards', async ({ page }) => {
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
    wordsetId: 690,
    categoryWords: {
      CatA: [makeNounWord(6901, 'CatA', 'feminine')]
    },
    sessionPlan: {
      level: 1,
      word_ids: [6901],
      launch_source: 'direct',
      force_intro: true,
      reason_code: 'test_intro_clears_compact_layout_state'
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    const contentEl = document.getElementById('ll-tools-flashcard-content');
    const containerEl = document.getElementById('ll-tools-flashcard');
    Gender.initialize();

    contentEl.classList.add('ll-gender-options-mode', 'll-gender-layout-compact');
    containerEl.classList.add('ll-gender-options-layout');
    contentEl.style.setProperty('--ll-gender-layout-scale', '0.68');
    contentEl.style.setProperty('--ll-gender-safe-bottom', '42px');
    containerEl.style.setProperty('--ll-gender-layout-scale', '0.68');

    const intro = Gender.selectTargetWord();
    Gender.handlePostSelection(intro, { startQuizRound: function () {} });

    return {
      hasOptionsMode: contentEl.classList.contains('ll-gender-options-mode'),
      hasCompactMode: contentEl.classList.contains('ll-gender-layout-compact'),
      hasOptionsLayout: containerEl.classList.contains('ll-gender-options-layout'),
      contentLayoutScale: contentEl.style.getPropertyValue('--ll-gender-layout-scale'),
      contentSafeBottom: contentEl.style.getPropertyValue('--ll-gender-safe-bottom'),
      containerLayoutScale: containerEl.style.getPropertyValue('--ll-gender-layout-scale'),
      introCardCount: containerEl.querySelectorAll('.ll-gender-intro-card').length
    };
  });

  expect(result.hasOptionsMode).toBe(false);
  expect(result.hasCompactMode).toBe(false);
  expect(result.hasOptionsLayout).toBe(false);
  expect(result.contentLayoutScale).toBe('');
  expect(result.contentSafeBottom).toBe('');
  expect(result.containerLayoutScale).toBe('');
  expect(result.introCardCount).toBe(1);
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
    let one = null;
    for (let i = 0; i < 240; i++) {
      const probe = Gender.selectTargetWord();
      if (!Array.isArray(probe)) {
        one = probe;
        break;
      }
      await wait(50);
    }
    if (!one) {
      return {
        firstCompleted: false,
        secondCompleted: false,
        thirdCompleted: false
      };
    }
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
    for (let i = 0; i < 240; i++) {
      const probe = Gender.selectTargetWord();
      if (!Array.isArray(probe)) {
        break;
      }
      await wait(50);
    }

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

    let firstPickAfterThirdIntro = null;
    for (let i = 0; i < 240; i++) {
      const probe = Gender.selectTargetWord();
      if (!Array.isArray(probe)) {
        firstPickAfterThirdIntro = probe;
        break;
      }
      await wait(50);
    }

    let introducedTooSoon = false;
    let sawFirstCorrectForThirdWord = false;
    for (let i = 0; i < 30; i++) {
      const pick = firstPickAfterThirdIntro || Gender.selectTargetWord();
      firstPickAfterThirdIntro = null;
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

test('gender WP storage isolates authenticated accounts and guests in one browser profile', async ({ page }) => {
  await openHarnessPage(page);
  const wordsetId = 93;
  const categoryWords = {
    CatA: [makeNounWord(931, 'CatA', 'masculine')]
  };
  const accountAStore = {
    words: {
      '931': makeGenderProgress()
    },
    updated_at: Date.parse('2026-03-20T10:00:00Z')
  };

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'wp',
    isUserLoggedIn: true,
    progressStorageScope: TEST_PROGRESS_SCOPE_A,
    preseedStore: accountAStore
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const accountAStartsWithIntro = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return Array.isArray(Gender.selectTargetWord());
  });

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'wp',
    isUserLoggedIn: true,
    progressStorageScope: TEST_PROGRESS_SCOPE_B,
    clearStorage: false
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const accountBStartsWithIntro = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return Array.isArray(Gender.selectTargetWord());
  });

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'wp',
    isUserLoggedIn: false,
    clearStorage: false
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const result = await page.evaluate(({ accountAKey, accountBKey, guestKey }) => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return {
      guestStartsWithIntro: Array.isArray(Gender.selectTargetWord()),
      accountAStored: !!window.localStorage.getItem(accountAKey),
      accountBStored: !!window.localStorage.getItem(accountBKey),
      guestStored: !!window.localStorage.getItem(guestKey)
    };
  }, {
    accountAKey: genderStorageKey({
      wordsetId,
      isUserLoggedIn: true,
      progressStorageScope: TEST_PROGRESS_SCOPE_A
    }),
    accountBKey: genderStorageKey({
      wordsetId,
      isUserLoggedIn: true,
      progressStorageScope: TEST_PROGRESS_SCOPE_B
    }),
    guestKey: genderStorageKey({ wordsetId })
  });

  expect(accountAStartsWithIntro).toBe(false);
  expect(accountBStartsWithIntro).toBe(true);
  expect(result.guestStartsWithIntro).toBe(true);
  expect(result.accountAStored).toBe(true);
  expect(result.accountBStored).toBe(false);
  expect(result.guestStored).toBe(false);
});

test('gender WP quarantines v1 state, missing user scope stays memory-only, and offline retains v1 compatibility', async ({ page }) => {
  await openHarnessPage(page);
  const wordsetId = 94;
  const legacyKey = genderStorageKey({ wordsetId, runtimeMode: 'offline' });
  const scopedKey = genderStorageKey({
    wordsetId,
    isUserLoggedIn: true,
    progressStorageScope: TEST_PROGRESS_SCOPE_A
  });
  const categoryWords = {
    CatA: [makeNounWord(941, 'CatA', 'masculine')]
  };
  const legacyStore = {
    words: {
      '941': makeGenderProgress()
    },
    updated_at: Date.parse('2026-03-20T10:00:00Z')
  };
  const legacyRaw = JSON.stringify(legacyStore);

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'wp',
    isUserLoggedIn: true,
    progressStorageScope: TEST_PROGRESS_SCOPE_A
  });
  await page.evaluate(({ key, value }) => window.localStorage.setItem(key, value), {
    key: legacyKey,
    value: legacyRaw
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const wpResult = await page.evaluate(({ oldKey, newKey }) => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return {
      startsWithIntro: Array.isArray(Gender.selectTargetWord()),
      legacyRaw: window.localStorage.getItem(oldKey),
      scopedRaw: window.localStorage.getItem(newKey)
    };
  }, { oldKey: legacyKey, newKey: scopedKey });

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'wp',
    isUserLoggedIn: true,
    clearStorage: false
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const missingScopeResult = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return {
      startsWithIntro: Array.isArray(Gender.selectTargetWord()),
      emptyKeyWritten: window.localStorage.getItem('') !== null
    };
  });

  await bootstrapGenderHarness(page, {
    wordsetId,
    categoryWords,
    runtimeMode: 'offline',
    isUserLoggedIn: true,
    preseedStore: legacyStore,
    clearStorage: false
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  const offlineStartsWithIntro = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    return Array.isArray(Gender.selectTargetWord());
  });

  expect(wpResult.startsWithIntro).toBe(true);
  expect(wpResult.legacyRaw).toBe(legacyRaw);
  expect(wpResult.scopedRaw).toBeNull();
  expect(missingScopeResult.startsWithIntro).toBe(true);
  expect(missingScopeResult.emptyKeyWritten).toBe(false);
  expect(offlineStartsWithIntro).toBe(false);
});

test('gender merge honors stored timestamps for answered local and server progress', async ({ page }) => {
  await openHarnessPage(page);
  const olderLocalTs = Date.parse('2026-03-20T10:00:00Z');
  const newerServerTs = Date.parse('2026-03-22T10:00:00Z');
  const olderServerTs = Date.parse('2026-03-21T10:00:00Z');
  const newerLocalTs = Date.parse('2026-03-23T10:00:00Z');

  await bootstrapGenderHarness(page, {
    wordsetId: 95,
    categoryWords: {
      CatA: [
        makeNounWord(951, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({
            level: 3,
            level3_correct: 5,
            last_seen_at: '2026-03-22 10:00:00',
            updated_at: newerServerTs
          })
        }),
        makeNounWord(952, 'CatA', 'feminine', {
          gender_progress: makeGenderProgress({
            level: 2,
            level2_correct: 2,
            level3_correct: 0,
            last_seen_at: '2026-03-21 10:00:00',
            updated_at: olderServerTs
          })
        }),
        makeNounWord(953, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({
            level: 2,
            level2_correct: 4,
            level3_correct: 0,
            last_seen_at: '2026-03-21 10:00:00',
            updated_at: olderServerTs
          })
        })
      ]
    },
    preseedStore: {
      words: {
        '951': makeGenderProgress({
          level: 2,
          level2_correct: 1,
          level3_correct: 0,
          last_seen_at: '2026-03-20 10:00:00',
          updated_at: olderLocalTs
        }),
        '952': makeGenderProgress({
          level: 3,
          level3_correct: 7,
          last_seen_at: '2026-03-23 10:00:00',
          updated_at: newerLocalTs
        }),
        '953': makeGenderProgress({
          level: 3,
          level3_correct: 9,
          last_seen_at: '',
          updated_at: 0
        })
      },
      updated_at: newerLocalTs
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const saved = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    Gender.selectTargetWord();
    return JSON.parse(
      window.localStorage.getItem('lltools_gender_progress_v2::guest::wordset:95') || '{}'
    );
  });

  expect(saved.words['951']).toMatchObject({
    level: 3,
    level3_correct: 5,
    updated_at: newerServerTs
  });
  expect(saved.words['952']).toMatchObject({
    level: 3,
    level3_correct: 7,
    updated_at: newerLocalTs
  });
  expect(saved.words['953']).toMatchObject({
    level: 2,
    level2_correct: 4,
    updated_at: olderServerTs
  });
});

test('gender mode merges newer local intro state with server-backed answered progress before planning', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 91,
    categoryWords: {
      CatA: [makeNounWord(911, 'CatA', 'masculine', {
        normalized_grammatical_gender: 'masculine',
        gender_progress: {
          level: 3,
          confidence: 7,
          intro_seen: true,
          quick_correct_streak: 2,
          level1_passes: 3,
          level1_failures: 0,
          level2_correct: 4,
          level2_wrong: 0,
          level3_correct: 2,
          level3_wrong: 0,
          dont_know_count: 0,
          seen_total: 9,
          category_name: 'CatA',
          last_seen_at: '2026-03-20 10:00:00',
          updated_at: Date.parse('2026-03-20T10:00:00Z')
        }
      })]
    },
    preseedStore: {
      words: {
        '911': {
          level: 1,
          confidence: 0,
          intro_seen: true,
          quick_correct_streak: 0,
          level1_passes: 0,
          level1_failures: 0,
          level2_correct: 0,
          level2_wrong: 0,
          level3_correct: 0,
          level3_wrong: 0,
          dont_know_count: 0,
          seen_total: 2,
          category_name: 'CatA',
          updated_at: Date.parse('2026-03-21T10:00:00Z')
        }
      },
      updated_at: Date.parse('2026-03-21T10:00:00Z')
    }
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(() => {
    const Gender = window.LLFlashcards.Modes.Gender;
    Gender.initialize();
    const selection = Gender.selectTargetWord();
    const saved = JSON.parse(
      window.localStorage.getItem('lltools_gender_progress_v2::guest::wordset:91') || '{}'
    );
    const entry = (saved.words && saved.words['911']) || {};
    return {
      selectionIsIntroBatch: Array.isArray(selection),
      mergedLevel: Number(entry.level || 0),
      mergedLevel2Correct: Number(entry.level2_correct || 0),
      mergedSeenTotal: Number(entry.seen_total || 0)
    };
  });

  expect(result.selectionIsIntroBatch).toBe(false);
  expect(result.mergedLevel).toBe(3);
  expect(result.mergedLevel2Correct).toBe(4);
  expect(result.mergedSeenTotal).toBe(9);
});

test('gender results render chunk progress summary after completion', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <head></head>
      <body>
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard"></div>
        <div id="ll-quiz-star-row"></div>
        <div id="ll-tools-listening-controls"></div>
        <button id="ll-tools-repeat-flashcard" type="button"></button>
        <div id="ll-tools-category-stack"></div>
        <div id="ll-tools-category-display"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="quiz-results" style="display:none;">
          <h2 id="quiz-results-title"></h2>
          <p id="quiz-results-message" style="display:none;"></p>
          <p><strong>Correct:</strong> <span id="correct-count">0</span> / <span id="total-questions">0</span></p>
          <p id="quiz-results-categories" style="display:none;"></p>
          <div id="ll-gender-results-progress" style="display:none;"></div>
          <div id="quiz-mode-buttons" style="display:none;">
            <button id="restart-practice-mode" type="button"></button>
            <button id="restart-learning-mode" type="button"><span class="ll-learning-results-label">Learning</span></button>
            <button id="restart-self-check-mode" type="button"></button>
            <button id="restart-gender-mode" type="button" style="display:none;"><span class="ll-gender-results-label">Gender</span></button>
            <button id="restart-listening-mode" type="button" style="display:none;"></button>
          </div>
          <div id="ll-gender-results-actions" style="display:none;">
            <button id="ll-gender-next-activity" type="button" style="display:none;"></button>
            <button id="ll-gender-next-chunk" type="button" style="display:none;"></button>
          </div>
          <div id="ll-study-results-actions" style="display:none;">
            <p id="ll-study-results-suggestion" style="display:none;"></p>
            <button id="ll-study-results-same-chunk" type="button" style="display:none;"></button>
            <button id="ll-study-results-different-chunk" type="button" style="display:none;"></button>
            <button id="ll-study-results-next-chunk" type="button" style="display:none;"></button>
          </div>
          <button id="restart-quiz" type="button" style="display:none;"></button>
        </div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });

  await bootstrapGenderHarness(page, {
    wordsetId: 92,
    categoryWords: {
      CatA: [
        makeNounWord(921, 'CatA', 'masculine'),
        makeNounWord(922, 'CatA', 'feminine')
      ]
    },
    sessionPlan: {
      level: 2,
      word_ids: [921, 922],
      launch_source: 'dashboard',
      reason_code: 'test_gender_results_progress_summary'
    },
    preseedStore: {
      words: {
        '921': {
          level: 2,
          confidence: 5,
          intro_seen: true,
          quick_correct_streak: 1,
          level1_passes: 3,
          level1_failures: 0,
          level2_correct: 3,
          level2_wrong: 0,
          level3_correct: 0,
          level3_wrong: 0,
          dont_know_count: 0,
          seen_total: 5,
          category_name: 'CatA',
          updated_at: Date.parse('2026-03-20T09:00:00Z')
        },
        '922': {
          level: 2,
          confidence: 0,
          intro_seen: true,
          quick_correct_streak: 0,
          level1_passes: 3,
          level1_failures: 0,
          level2_correct: 1,
          level2_wrong: 1,
          level3_correct: 0,
          level3_wrong: 0,
          dont_know_count: 0,
          seen_total: 5,
          category_name: 'CatA',
          updated_at: Date.parse('2026-03-20T09:00:00Z')
        }
      },
      updated_at: Date.parse('2026-03-20T09:00:00Z')
    }
  });

  await page.evaluate(() => {
    window.llToolsFlashcardsMessages = {
      genderProgressTitle: 'Gender progress',
      genderProgressCurrentSet: 'Current set',
      genderProgressWords: '%d words',
      genderProgressLevel1: 'Level 1',
      genderProgressLevel2: 'Level 2',
      genderProgressLevel3: 'Level 3'
    };
    window.llToolsFlashcardsData = Object.assign({}, window.llToolsFlashcardsData || {}, {
      modeUi: {}
    });
    window.LLFlashcards.Dom.hideLoading = function () {};
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  await page.addScriptTag({ content: fs.readFileSync(resultsScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    const Results = window.LLFlashcards.Results;
    Gender.initialize();

    const first = Gender.selectTargetWord();
    await Gender.handleAnswer({
      targetWord: first,
      isCorrect: true,
      isDontKnow: false
    });

    const second = Gender.selectTargetWord();
    await Gender.handleAnswer({
      targetWord: second,
      isCorrect: true,
      isDontKnow: false
    });

    Results.showResults();

    const root = document.getElementById('ll-gender-results-progress');
    const level2 = root ? root.querySelector('.ll-gender-results-progress-card__stat--level-2 .ll-gender-results-progress-card__stat-value') : null;
    const level3 = root ? root.querySelector('.ll-gender-results-progress-card__stat--level-3 .ll-gender-results-progress-card__stat-value') : null;

    return {
      visible: !!root && window.getComputedStyle(root).display !== 'none',
      text: root ? root.textContent.replace(/\s+/g, ' ').trim() : '',
      level2Value: level2 ? level2.textContent.trim() : '',
      level3Value: level3 ? level3.textContent.trim() : ''
    };
  });

  expect(result.visible).toBe(true);
  expect(result.text).toContain('Gender progress');
  expect(result.text).toContain('Current set');
  expect(result.text).toContain('2 words');
  expect(result.level2Value).toBe('1');
  expect(result.level3Value).toBe('1');
});

test('bounded gender checkpoints emit mode completion once at the final logical results', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <div id="ll-tools-mode-switcher-wrap"></div>
        <div id="ll-tools-prompt"></div>
        <div id="ll-tools-flashcard"></div>
        <div id="ll-quiz-star-row"></div>
        <div id="ll-tools-listening-controls"></div>
        <button id="ll-tools-repeat-flashcard" type="button"></button>
        <div id="ll-tools-category-stack"></div>
        <div id="ll-tools-category-display"></div>
        <div id="ll-tools-learning-progress"></div>
        <div id="quiz-results" style="display:none;">
          <h2 id="quiz-results-title"></h2>
          <p id="quiz-results-message" style="display:none;"></p>
          <p><span id="correct-count">0</span> / <span id="total-questions">0</span></p>
          <p id="quiz-results-categories" style="display:none;"></p>
          <div id="ll-gender-results-progress" style="display:none;"></div>
          <div id="quiz-mode-buttons" style="display:none;">
            <button id="restart-practice-mode" type="button"></button>
            <button id="restart-learning-mode" type="button"></button>
            <button id="restart-self-check-mode" type="button"></button>
            <button id="restart-gender-mode" type="button"><span class="ll-gender-results-label">Gender</span></button>
            <button id="restart-listening-mode" type="button"></button>
          </div>
          <div id="ll-gender-results-actions" style="display:none;">
            <button id="ll-gender-next-activity" type="button" style="display:none;"></button>
            <button id="ll-gender-next-chunk" type="button" style="display:none;"></button>
          </div>
          <div id="ll-study-results-actions" style="display:none;">
            <p id="ll-study-results-suggestion" style="display:none;"></p>
            <button id="ll-study-results-same-chunk" type="button" style="display:none;"></button>
            <button id="ll-study-results-different-chunk" type="button" style="display:none;"></button>
            <button id="ll-study-results-next-chunk" type="button" style="display:none;"></button>
          </div>
          <button id="restart-quiz" type="button" style="display:none;"></button>
        </div>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: jquerySource });
  await bootstrapGenderHarness(page, {
    wordsetId: 101,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [makeNounWord(1011, 'CatA', 'masculine', {
        gender_progress: makeGenderProgress({ level: 3 })
      })],
      CatB: [makeNounWord(1012, 'CatB', 'feminine', {
        gender_progress: makeGenderProgress({ level: 3, category_name: 'CatB' })
      })]
    },
    sessionPlan: {
      level: 3,
      word_ids: [1011],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    }
  });

  await page.evaluate(() => {
    const data = window.llToolsFlashcardsData;
    data.logicalSessionWordIds = [1011, 1012];
    data.logical_session_word_ids = [1011, 1012];
    data.logicalSessionTotal = 2;
    data.logical_session_total = 2;
    data.logicalSessionCompletedBefore = 0;
    data.logical_session_completed_before = 0;
    data.boundedSessionContinuation = function () { return Promise.resolve({ success: true }); };
    data.modeUi = {};

    window.__genderCompletionEvents = [];
    window.__genderCompletionFlushes = 0;
    window.LLFlashcards.State.modeSessionCompleteTracked = false;
    window.LLFlashcards.Dom.hideLoading = function () {};
    window.LLFlashcards.ProgressTracker = {
      categoryNameToId: function (name) { return name === 'CatA' ? 11 : 22; },
      trackModeSessionComplete: function (event) {
        window.__genderCompletionEvents.push(Object.assign({}, event));
        return 'gender-complete-' + window.__genderCompletionEvents.length;
      },
      flush: function () {
        window.__genderCompletionFlushes += 1;
        return Promise.resolve(true);
      }
    };
  });

  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });
  await page.addScriptTag({ content: fs.readFileSync(resultsScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    const Results = window.LLFlashcards.Results;
    const State = window.LLFlashcards.State;
    const data = window.llToolsFlashcardsData;

    Gender.initialize();
    const firstTarget = Gender.selectTargetWord();
    const firstOutcome = await Gender.handleAnswer({
      targetWord: firstTarget,
      isCorrect: true,
      isDontKnow: false
    });
    const tracksAtCheckpoint = Gender.shouldTrackModeSessionCompletion();
    Results.showResults();
    const intermediate = {
      events: window.__genderCompletionEvents.length,
      trackedFlag: !!State.modeSessionCompleteTracked,
      resultsVisible: window.getComputedStyle(document.getElementById('quiz-results')).display !== 'none',
      continueVisible: window.getComputedStyle(document.getElementById('ll-gender-next-activity')).display !== 'none',
      continueLabel: document.getElementById('ll-gender-next-activity').textContent.trim()
    };

    data.genderSessionPlan = {
      level: 3,
      word_ids: [1012],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    data.genderSessionPlanArmed = true;
    data.gender_session_plan_armed = true;
    data.logicalSessionCompletedBefore = 1;
    data.logical_session_completed_before = 1;
    const appended = Gender.appendBoundedSelectionChunk(['CatB']);
    const secondTarget = Gender.selectTargetWord();
    const secondOutcome = await Gender.handleAnswer({
      targetWord: secondTarget,
      isCorrect: true,
      isDontKnow: false
    });
    const tracksAtFinal = Gender.shouldTrackModeSessionCompletion();
    Results.showResults();
    Results.showResults();

    return {
      firstCompleted: !!(firstOutcome && firstOutcome.completed),
      secondCompleted: !!(secondOutcome && secondOutcome.completed),
      tracksAtCheckpoint,
      tracksAtFinal,
      appended,
      intermediate,
      finalEvents: window.__genderCompletionEvents.slice(),
      finalFlushes: window.__genderCompletionFlushes,
      finalTrackedFlag: !!State.modeSessionCompleteTracked
    };
  });

  expect(result.firstCompleted).toBe(true);
  expect(result.secondCompleted).toBe(true);
  expect(result.tracksAtCheckpoint).toBe(false);
  expect(result.tracksAtFinal).toBe(true);
  expect(result.appended).toBe(true);
  expect(result.intermediate).toMatchObject({
    events: 0,
    trackedFlag: false,
    resultsVisible: true,
    continueVisible: true
  });
  expect(result.intermediate.continueLabel).not.toBe('');
  expect(result.finalEvents).toHaveLength(1);
  expect(result.finalEvents[0].mode).toBe('gender');
  expect(result.finalFlushes).toBe(1);
  expect(result.finalTrackedFlag).toBe(true);
});

test('gender bounded append consumes an explicit homogeneous plan and keeps logical progress monotonic', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 96,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [
        makeNounWord(961, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        }),
        makeNounWord(962, 'CatA', 'feminine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        })
      ],
      CatB: [
        makeNounWord(963, 'CatB', 'masculine', {
          gender_progress: makeGenderProgress({ level: 3 })
        }),
        makeNounWord(964, 'CatB', 'feminine', {
          gender_progress: makeGenderProgress({ level: 3 })
        })
      ]
    },
    sessionPlan: {
      level: 2,
      word_ids: [961, 962],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    const data = window.llToolsFlashcardsData;
    const progress = [];
    const playedIds = [];
    window.LLFlashcards.Dom.updateSimpleProgress = function (current, total) {
      progress.push([Number(current) || 0, Number(total) || 0]);
    };
    data.logicalSessionWordIds = [961, 962, 963, 964];
    data.logical_session_word_ids = [961, 962, 963, 964];
    data.logicalSessionTotal = 4;
    data.logical_session_total = 4;
    data.logicalSessionCompletedBefore = 0;
    data.logical_session_completed_before = 0;
    data.boundedSessionContinuation = function () { return Promise.resolve({ success: true }); };

    Gender.initialize();
    let firstOutcome = null;
    for (let i = 0; i < 2; i += 1) {
      const target = Gender.selectTargetWord();
      playedIds.push(Number(target && target.id) || 0);
      firstOutcome = await Gender.handleAnswer({
        targetWord: target,
        isCorrect: true,
        isDontKnow: false
      });
    }
    const autoContinuesFirstChunk = Gender.shouldAutoContinue();

    data.genderSessionPlan = {
      level: 3,
      word_ids: [963, 964],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    data.genderSessionPlanArmed = true;
    data.gender_session_plan_armed = true;
    data.logicalSessionCompletedBefore = 2;
    data.logical_session_completed_before = 2;

    const appended = Gender.appendBoundedSelectionChunk(['CatB']);
    const autoContinuesBeforeAnswer = Gender.shouldAutoContinue();
    const planWasConsumed = !data.genderSessionPlan &&
      !data.genderSessionPlanArmed &&
      !data.gender_session_plan_armed;

    let secondOutcome = null;
    for (let i = 0; i < 2; i += 1) {
      const target = Gender.selectTargetWord();
      playedIds.push(Number(target && target.id) || 0);
      secondOutcome = await Gender.handleAnswer({
        targetWord: target,
        isCorrect: true,
        isDontKnow: false
      });
    }
    delete data.boundedSessionContinuation;
    delete data.bounded_session_continuation;

    return {
      firstCompleted: !!(firstOutcome && firstOutcome.completed),
      secondCompleted: !!(secondOutcome && secondOutcome.completed),
      autoContinuesFirstChunk,
      autoContinuesBeforeAnswer,
      autoContinuesFinalChunk: Gender.shouldAutoContinue(),
      appended,
      planWasConsumed,
      playedIds,
      progress,
      resultCategories: Gender.getResultsCategoryNames()
    };
  });

  expect(result.firstCompleted).toBe(true);
  expect(result.secondCompleted).toBe(true);
  expect(result.autoContinuesFirstChunk).toBe(true);
  expect(result.autoContinuesBeforeAnswer).toBe(false);
  expect(result.autoContinuesFinalChunk).toBe(false);
  expect(result.appended).toBe(true);
  expect(result.planWasConsumed).toBe(true);
  expect(new Set(result.playedIds)).toEqual(new Set([961, 962, 963, 964]));
  expect(result.progress.length).toBeGreaterThan(0);
  expect(result.progress.every((entry) => entry[1] === 4)).toBe(true);
  expect(result.progress.map((entry) => entry[0])).toEqual(
    result.progress.map((entry) => entry[0]).slice().sort((left, right) => left - right)
  );
  expect(result.progress[result.progress.length - 1]).toEqual([4, 4]);
  expect(result.resultCategories).toEqual(['CatB']);
});

test('gender bounded append rejects an already accepted word without replacing the completed chunk', async ({ page }) => {
  await openHarnessPage(page);
  await bootstrapGenderHarness(page, {
    wordsetId: 97,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [makeNounWord(971, 'CatA', 'masculine', {
        gender_progress: makeGenderProgress({ level: 3 })
      })],
      CatB: [makeNounWord(972, 'CatB', 'feminine', {
        gender_progress: makeGenderProgress({ level: 3 })
      })]
    },
    sessionPlan: {
      level: 3,
      word_ids: [971],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const Gender = window.LLFlashcards.Modes.Gender;
    const data = window.llToolsFlashcardsData;
    data.logicalSessionWordIds = [971, 972];
    data.logical_session_word_ids = [971, 972];
    data.logicalSessionTotal = 2;
    data.logical_session_total = 2;
    data.logicalSessionCompletedBefore = 0;
    data.logical_session_completed_before = 0;
    data.boundedSessionContinuation = function () { return Promise.resolve({ success: true }); };

    Gender.initialize();
    const first = Gender.selectTargetWord();
    const firstOutcome = await Gender.handleAnswer({
      targetWord: first,
      isCorrect: true,
      isDontKnow: false
    });

    data.genderSessionPlan = {
      level: 3,
      word_ids: [971],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    data.genderSessionPlanArmed = true;
    data.gender_session_plan_armed = true;
    data.logicalSessionCompletedBefore = 1;
    data.logical_session_completed_before = 1;
    const duplicateAccepted = Gender.appendBoundedSelectionChunk(['CatA']);
    const duplicatePlanRemainsArmed = !!data.genderSessionPlanArmed &&
      Array.isArray(data.genderSessionPlan && data.genderSessionPlan.word_ids) &&
      data.genderSessionPlan.word_ids[0] === 971;
    const categoriesAfterReject = Gender.getResultsCategoryNames();

    data.genderSessionPlan = {
      level: 3,
      word_ids: [972],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    data.genderSessionPlanArmed = true;
    data.gender_session_plan_armed = true;
    const validAccepted = Gender.appendBoundedSelectionChunk(['CatB']);
    const next = Gender.selectTargetWord();

    return {
      firstCompleted: !!(firstOutcome && firstOutcome.completed),
      duplicateAccepted,
      duplicatePlanRemainsArmed,
      categoriesAfterReject,
      validAccepted,
      nextId: Number(next && next.id) || 0
    };
  });

  expect(result.firstCompleted).toBe(true);
  expect(result.duplicateAccepted).toBe(false);
  expect(result.duplicatePlanRemainsArmed).toBe(true);
  expect(result.categoriesAfterReject).toEqual(['CatA']);
  expect(result.validAccepted).toBe(true);
  expect(result.nextId).toBe(972);
});

test('stale bounded levels re-bucket without loss and level-one results preserve native overflow continuation', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html><body>
      <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
      <div id="ll-tools-flashcard"></div>
    </body></html>
  `);
  await page.addScriptTag({ content: jquerySource });
  await bootstrapGenderHarness(page, {
    wordsetId: 99,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [
        makeNounWord(991, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        }),
        makeNounWord(992, 'CatA', 'feminine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        }),
        makeNounWord(993, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        })
      ]
    },
    sessionPlan: {
      level: 2,
      word_ids: [991, 992, 993],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    },
    preseedStore: {
      words: {
        '992': makeGenderProgress({
          level: 1,
          confidence: 0,
          intro_seen: false,
          quick_correct_streak: 0,
          level1_passes: 0,
          seen_total: 0,
          updated_at: Date.parse('2026-03-21T10:00:00Z')
        }),
        '993': makeGenderProgress({
          level: 3,
          updated_at: Date.parse('2026-03-21T10:00:00Z')
        })
      },
      updated_at: Date.parse('2026-03-21T10:00:00Z')
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    const data = window.llToolsFlashcardsData;
    const playedIds = [];
    const progress = [];
    data.logicalSessionWordIds = [991, 992, 993];
    data.logical_session_word_ids = [991, 992, 993];
    data.logicalSessionTotal = 3;
    data.logical_session_total = 3;
    data.logicalSessionCompletedBefore = 0;
    data.logical_session_completed_before = 0;
    window.LLFlashcards.Dom.updateSimpleProgress = function (current, total) {
      progress.push([Number(current) || 0, Number(total) || 0]);
    };

    Gender.initialize();
    const levelTwoTarget = Gender.selectTargetWord();
    playedIds.push(Number(levelTwoTarget && levelTwoTarget.id) || 0);
    const levelTwoOutcome = await Gender.handleAnswer({
      targetWord: levelTwoTarget,
      isCorrect: true,
      isDontKnow: false
    });

    const levelOneIntro = Gender.selectTargetWord();
    Gender.handlePostSelection(levelOneIntro, { startQuizRound: function () {} });
    let levelOneTarget = null;
    const introStartedAt = Date.now();
    while ((Date.now() - introStartedAt) < 12000) {
      const candidate = Gender.selectTargetWord();
      if (!Array.isArray(candidate)) {
        levelOneTarget = candidate;
        break;
      }
      await wait(50);
    }

    let levelOneOutcome = null;
    for (let i = 0; i < 3; i += 1) {
      const current = levelOneTarget || Gender.selectTargetWord();
      levelOneTarget = null;
      if (i === 0) playedIds.push(Number(current && current.id) || 0);
      levelOneOutcome = await Gender.handleAnswer({
        targetWord: current,
        isCorrect: true,
        isDontKnow: false
      });
    }

    const actions = Gender.getResultsActions();
    const overflowPlan = actions && actions.secondary ? actions.secondary.plan : null;
    const overflowQueued = Gender.queueResultsAction('secondary');
    Gender.initialize();
    const levelThreeTarget = Gender.selectTargetWord();
    playedIds.push(Number(levelThreeTarget && levelThreeTarget.id) || 0);
    const levelThreeOutcome = await Gender.handleAnswer({
      targetWord: levelThreeTarget,
      isCorrect: true,
      isDontKnow: false
    });

    return {
      levelTwoId: Number(levelTwoTarget && levelTwoTarget.id) || 0,
      levelTwoCompleted: !!(levelTwoOutcome && levelTwoOutcome.completed),
      introIds: Array.isArray(levelOneIntro)
        ? levelOneIntro.map((word) => Number(word && word.id) || 0)
        : [],
      levelOneCompleted: !!(levelOneOutcome && levelOneOutcome.completed),
      overflowQueued,
      overflowLevel: Number(overflowPlan && overflowPlan.level) || 0,
      overflowIds: overflowPlan && Array.isArray(overflowPlan.word_ids)
        ? overflowPlan.word_ids.map((id) => Number(id) || 0)
        : [],
      levelThreeId: Number(levelThreeTarget && levelThreeTarget.id) || 0,
      levelThreeCompleted: !!(levelThreeOutcome && levelThreeOutcome.completed),
      playedIds,
      progress,
      armedPlanConsumed: !data.genderSessionPlan && !data.genderSessionPlanArmed
    };
  });

  expect(result.levelTwoId).toBe(991);
  expect(result.levelTwoCompleted).toBe(false);
  expect(result.introIds).toEqual([992]);
  expect(result.levelOneCompleted).toBe(true);
  expect(result.overflowQueued).toBe(true);
  expect(result.overflowLevel).toBe(3);
  expect(result.overflowIds).toEqual([993]);
  expect(result.levelThreeId).toBe(993);
  expect(result.levelThreeCompleted).toBe(true);
  expect(result.playedIds).toEqual([991, 992, 993]);
  expect(result.progress[result.progress.length - 1]).toEqual([3, 3]);
  expect(result.armedPlanConsumed).toBe(true);
});

test('server continuation ingests its chunk behind older local level overflow', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html><body>
      <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
      <div id="ll-tools-flashcard"></div>
    </body></html>
  `);
  await page.addScriptTag({ content: jquerySource });
  await bootstrapGenderHarness(page, {
    wordsetId: 100,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [
        makeNounWord(1001, 'CatA', 'masculine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        }),
        makeNounWord(1002, 'CatA', 'feminine', {
          gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
        })
      ],
      CatB: [makeNounWord(1003, 'CatB', 'masculine', {
        gender_progress: makeGenderProgress({ level: 2, confidence: 2, quick_correct_streak: 0 })
      })]
    },
    sessionPlan: {
      level: 2,
      word_ids: [1001, 1002],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    },
    preseedStore: {
      words: {
        '1001': makeGenderProgress({
          level: 1,
          confidence: 0,
          intro_seen: false,
          quick_correct_streak: 0,
          level1_passes: 0,
          seen_total: 0,
          updated_at: Date.parse('2026-03-21T11:00:00Z')
        }),
        '1002': makeGenderProgress({
          level: 3,
          updated_at: Date.parse('2026-03-21T11:00:00Z')
        })
      },
      updated_at: Date.parse('2026-03-21T11:00:00Z')
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    const data = window.llToolsFlashcardsData;
    const playedIds = [];
    data.logicalSessionWordIds = [1001, 1002, 1003];
    data.logical_session_word_ids = [1001, 1002, 1003];
    data.logicalSessionTotal = 3;
    data.logical_session_total = 3;
    data.logicalSessionCompletedBefore = 0;
    data.logical_session_completed_before = 0;
    data.boundedSessionContinuation = function () { return Promise.resolve({ success: true }); };

    Gender.initialize();
    const intro = Gender.selectTargetWord();
    Gender.handlePostSelection(intro, { startQuizRound: function () {} });
    let levelOneTarget = null;
    const introStartedAt = Date.now();
    while ((Date.now() - introStartedAt) < 12000) {
      const candidate = Gender.selectTargetWord();
      if (!Array.isArray(candidate)) {
        levelOneTarget = candidate;
        break;
      }
      await wait(50);
    }
    let levelOneOutcome = null;
    for (let i = 0; i < 3; i += 1) {
      const current = levelOneTarget || Gender.selectTargetWord();
      levelOneTarget = null;
      if (i === 0) playedIds.push(Number(current && current.id) || 0);
      levelOneOutcome = await Gender.handleAnswer({
        targetWord: current,
        isCorrect: true,
        isDontKnow: false
      });
    }
    const resultsActionsBeforeAppend = Gender.getResultsActions();

    data.genderSessionPlan = {
      level: 2,
      word_ids: [1003],
      launch_source: 'dashboard',
      reason_code: 'bounded_level_chunk'
    };
    data.genderSessionPlanArmed = true;
    data.gender_session_plan_armed = true;
    data.logicalSessionCompletedBefore = 2;
    data.logical_session_completed_before = 2;
    const appended = Gender.appendBoundedSelectionChunk(['CatB']);

    const olderOverflowTarget = Gender.selectTargetWord();
    playedIds.push(Number(olderOverflowTarget && olderOverflowTarget.id) || 0);
    const overflowOutcome = await Gender.handleAnswer({
      targetWord: olderOverflowTarget,
      isCorrect: true,
      isDontKnow: false
    });
    const transportedTarget = Gender.selectTargetWord();
    playedIds.push(Number(transportedTarget && transportedTarget.id) || 0);
    const transportedOutcome = await Gender.handleAnswer({
      targetWord: transportedTarget,
      isCorrect: true,
      isDontKnow: false
    });

    return {
      levelOneCompleted: !!(levelOneOutcome && levelOneOutcome.completed),
      hasNativeSecondaryBeforeAppend: !!(resultsActionsBeforeAppend && resultsActionsBeforeAppend.secondary),
      appended,
      olderOverflowId: Number(olderOverflowTarget && olderOverflowTarget.id) || 0,
      overflowCompleted: !!(overflowOutcome && overflowOutcome.completed),
      transportedId: Number(transportedTarget && transportedTarget.id) || 0,
      transportedCompleted: !!(transportedOutcome && transportedOutcome.completed),
      playedIds
    };
  });

  expect(result.levelOneCompleted).toBe(true);
  expect(result.hasNativeSecondaryBeforeAppend).toBe(false);
  expect(result.appended).toBe(true);
  expect(result.olderOverflowId).toBe(1002);
  expect(result.overflowCompleted).toBe(false);
  expect(result.transportedId).toBe(1003);
  expect(result.transportedCompleted).toBe(true);
  expect(result.playedIds).toEqual([1001, 1002, 1003]);
});

test('completed level one keeps adaptive results instead of auto-continuing', async ({ page }) => {
  await openHarnessPage(page);
  await page.setContent(`
    <!doctype html>
    <html><body>
      <div id="ll-tools-flashcard-content"><div id="ll-tools-prompt"></div></div>
      <div id="ll-tools-flashcard"></div>
    </body></html>
  `);
  await page.addScriptTag({ content: jquerySource });
  await bootstrapGenderHarness(page, {
    wordsetId: 98,
    launchSource: 'dashboard',
    categoryWords: {
      CatA: [makeNounWord(981, 'CatA', 'masculine')]
    },
    sessionPlan: {
      level: 1,
      word_ids: [981],
      launch_source: 'dashboard',
      force_intro: true,
      reason_code: 'bounded_level_chunk'
    }
  });
  await page.addScriptTag({ content: fs.readFileSync(genderScriptPath, 'utf8') });

  const result = await page.evaluate(async () => {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const Gender = window.LLFlashcards.Modes.Gender;
    const data = window.llToolsFlashcardsData;
    data.logicalSessionWordIds = [981, 982];
    data.logical_session_word_ids = [981, 982];
    data.logicalSessionTotal = 2;
    data.logical_session_total = 2;
    data.boundedSessionContinuation = function () { return Promise.resolve({ success: true }); };

    let resultsCalls = 0;
    let continuationCalls = 0;
    window.LLFlashcards.Results.showResults = function () { resultsCalls += 1; };

    Gender.initialize();
    const intro = Gender.selectTargetWord();
    Gender.handlePostSelection(intro, { startQuizRound: function () {} });

    let target = null;
    const introStartedAt = Date.now();
    while ((Date.now() - introStartedAt) < 12000) {
      const candidate = Gender.selectTargetWord();
      if (!Array.isArray(candidate)) {
        target = candidate;
        break;
      }
      await wait(50);
    }

    let outcome = null;
    for (let i = 0; i < 3; i += 1) {
      const current = target || Gender.selectTargetWord();
      target = null;
      outcome = await Gender.handleAnswer({
        targetWord: current,
        isCorrect: true,
        isDontKnow: false
      });
    }

    const autoContinues = Gender.shouldAutoContinue();
    const handled = Gender.handleNoTarget({
      tryContinueLogicalSession: function () {
        continuationCalls += 1;
        return true;
      }
    });
    const actions = Gender.getResultsActions();
    const primaryQueued = Gender.queueResultsAction('primary');
    window.LLFlashcards.State.wordsByCategory.CatA = [];
    Gender.initialize();
    const resumedPrimaryTarget = Gender.selectTargetWord();

    return {
      completed: !!(outcome && outcome.completed),
      autoContinues,
      handled,
      continuationCalls,
      resultsCalls,
      hasSecondary: !!(actions && actions.secondary),
      primaryQueued,
      resumedPrimaryId: Number(resumedPrimaryTarget && resumedPrimaryTarget.id) || 0,
      primaryReason: actions && actions.primary && actions.primary.plan
        ? actions.primary.plan.reason_code
        : ''
    };
  });

  expect(result.completed).toBe(true);
  expect(result.autoContinues).toBe(false);
  expect(result.handled).toBe(true);
  expect(result.continuationCalls).toBe(0);
  expect(result.resultsCalls).toBe(1);
  expect(result.hasSecondary).toBe(false);
  expect(result.primaryQueued).toBe(true);
  expect(result.resumedPrimaryId).toBe(981);
  expect(result.primaryReason).toBe('advance_to_level2');
});
