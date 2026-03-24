const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const optionConflictsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/option-conflicts.js'),
  'utf8'
);
const wordsetGamesSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-games.js'),
  'utf8'
);
const wordsetGamesStyles = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-games.css'),
  'utf8'
);
const wordsetPagesSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

function buildGameCardMarkup(slug, title, description) {
  return `
    <article class="ll-wordset-game-card" data-ll-wordset-game-card data-game-slug="${slug}">
      <div class="ll-wordset-game-card__icon" aria-hidden="true"></div>
      <div class="ll-wordset-game-card__body">
        <h2 class="ll-wordset-game-card__title">${title}</h2>
        <p class="ll-wordset-game-card__description">${description}</p>
        <p class="ll-wordset-game-card__status" data-ll-wordset-game-status></p>
      </div>
      <div class="ll-wordset-game-card__actions">
        <span class="ll-wordset-game-card__count" data-ll-wordset-game-count>—</span>
        <button type="button" class="ll-wordset-game-card__launch" data-ll-wordset-game-launch disabled>Play</button>
      </div>
    </article>
  `;
}

function buildGamesMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="games" data-ll-wordset-id="77">
      <header class="ll-wordset-subpage-head">
        <a class="ll-wordset-back ll-vocab-lesson-back" data-ll-wordset-games-back href="/wordsets/test-wordset/">
          <span class="ll-wordset-back__label" data-ll-wordset-games-back-label>Test Wordset</span>
        </a>
        <h1 class="ll-wordset-title" data-ll-wordset-games-page-title>Games</h1>
      </header>
      <section class="ll-wordset-games-page" data-ll-wordset-games-root>
        <div class="ll-wordset-games-catalog" data-ll-wordset-games-catalog>
          ${buildGameCardMarkup('space-shooter', 'Arcane Space Shooter', 'Hear the word. Blast the matching picture.')}
          ${buildGameCardMarkup('bubble-pop', 'Bubble Pop', 'Hear the word. Pop the matching bubble.')}
        </div>

        <section class="ll-wordset-game-stage" data-ll-wordset-game-stage data-ll-wordset-active-game="" hidden>
          <div class="ll-wordset-game-stage__hud">
            <div class="ll-wordset-game-stage__stats">
              <span data-ll-wordset-game-coins>0</span>
              <span data-ll-wordset-game-lives>3</span>
            </div>
            <div class="ll-wordset-game-stage__hud-actions">
              <button type="button" class="ll-wordset-game-stage__nav ll-wordset-game-stage__nav--replay ll-prompt-audio-button" data-ll-wordset-game-replay-audio>
                <span class="ll-repeat-audio-ui">
                  <span class="ll-repeat-icon-wrap" aria-hidden="true">
                    <span class="ll-audio-play-icon" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true">
                        <path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"></path>
                      </svg>
                    </span>
                  </span>
                  <span class="ll-audio-mini-visualizer" aria-hidden="true">
                    <span class="bar" data-bar="1"></span>
                    <span class="bar" data-bar="2"></span>
                    <span class="bar" data-bar="3"></span>
                    <span class="bar" data-bar="4"></span>
                    <span class="bar" data-bar="5"></span>
                    <span class="bar" data-bar="6"></span>
                  </span>
                </span>
              </button>
              <button type="button" class="ll-wordset-game-stage__nav ll-wordset-game-stage__nav--pause" data-ll-wordset-game-pause-toggle aria-label="Pause run">
                <span data-ll-wordset-game-pause-icon>||</span>
              </button>
            </div>
          </div>
          <div class="ll-wordset-game-stage__canvas-wrap">
            <canvas data-ll-wordset-game-canvas width="720" height="960"></canvas>
          </div>
          <div class="ll-wordset-game-stage__controls" data-ll-wordset-game-controls>
            <button type="button" data-ll-wordset-game-control="left" aria-label="Move left">
              <span class="ll-wordset-game-stage__control-icon" aria-hidden="true">
                <svg class="ll-wordset-game-stage__control-arrow" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M14.5 5.5L8 12l6.5 6.5"></path>
                  <path d="M8.5 12H19.5"></path>
                </svg>
              </span>
            </button>
            <button type="button" data-ll-wordset-game-control="fire" aria-label="Fire or press space bar">
              <span class="ll-wordset-game-stage__control-fire-stack" aria-hidden="true">
                <svg class="ll-wordset-game-stage__control-burst" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M12 2.75L13.98 8.02L19.25 6.04L16.98 11.31L22.25 13.29L16.98 15.27L19.25 20.54L13.98 18.56L12 23.25L10.02 18.56L4.75 20.54L7.02 15.27L1.75 13.29L7.02 11.31L4.75 6.04L10.02 8.02L12 2.75Z"></path>
                </svg>
                <span class="ll-wordset-game-stage__control-keycap ll-wordset-game-stage__control-keycap--space" data-ll-wordset-game-fire-keycap>
                  <span class="ll-wordset-game-stage__control-keycap-bar"></span>
                </span>
              </span>
            </button>
            <button type="button" data-ll-wordset-game-control="right" aria-label="Move right">
              <span class="ll-wordset-game-stage__control-icon" aria-hidden="true">
                <svg class="ll-wordset-game-stage__control-arrow" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M9.5 5.5L16 12l-6.5 6.5"></path>
                  <path d="M15.5 12H4.5"></path>
                </svg>
              </span>
            </button>
          </div>
          <div class="ll-wordset-game-stage__overlay" data-ll-wordset-game-overlay hidden>
            <div class="ll-wordset-game-stage__overlay-card">
              <h2 data-ll-wordset-game-overlay-title></h2>
              <p data-ll-wordset-game-overlay-summary></p>
              <button type="button" class="ll-wordset-game-stage__overlay-button" data-ll-wordset-game-replay>Replay</button>
              <button type="button" class="ll-wordset-game-stage__overlay-button ll-wordset-game-stage__overlay-button--ghost" data-ll-wordset-game-return>Back</button>
            </div>
          </div>
        </section>
      </section>
    </div>
  `;
}

function gameCard(page, slug) {
  return page.locator(`[data-ll-wordset-game-card][data-game-slug="${slug}"]`).first();
}

function gameLaunchButton(page, slug) {
  return gameCard(page, slug).locator('[data-ll-wordset-game-launch]');
}

function gameStatus(page, slug) {
  return gameCard(page, slug).locator('[data-ll-wordset-game-status]');
}

async function waitForActivePrompt(page, slug) {
  await page.waitForFunction((expectedSlug) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.slug === expectedSlug && run.targetWordId && run.activeCardCount > 0 && !run.awaitingPrompt);
  }, slug);
}

function buildSvgImage(width, height, color) {
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
      <rect width="${width}" height="${height}" rx="18" fill="${color}"/>
    </svg>`
  )}`;
}

function buildSpaceShooterWords() {
  return [
    {
      id: 101,
      title: 'Sun',
      label: 'Sun',
      prompt_label: 'Sun',
      translation: 'Sun',
      image: buildSvgImage(240, 140, '#f59e0b'),
      audio: '',
      audio_files: [{ url: 'https://example.test/audio/101-question.mp3', recording_type: 'question' }],
      practice_recording_types: ['question'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [102],
      category_id: 11,
      category_ids: [11],
      category_name: 'Set A',
      category_names: ['Set A'],
      similar_word_id: ''
    },
    {
      id: 102,
      title: 'Moon',
      label: 'Moon',
      prompt_label: 'Moon',
      translation: 'Moon',
      image: buildSvgImage(140, 240, '#38bdf8'),
      audio: '',
      audio_files: [{ url: 'https://example.test/audio/102-isolation.mp3', recording_type: 'isolation' }],
      practice_recording_types: ['isolation'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [101],
      category_id: 11,
      category_ids: [11],
      category_name: 'Set A',
      category_names: ['Set A'],
      similar_word_id: ''
    },
    {
      id: 103,
      title: 'River',
      label: 'River',
      prompt_label: 'River',
      translation: 'River',
      image: buildSvgImage(220, 140, '#10b981'),
      audio: '',
      audio_files: [{ url: 'https://example.test/audio/103-introduction.mp3', recording_type: 'introduction' }],
      practice_recording_types: ['introduction'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [],
      category_id: 11,
      category_ids: [11],
      category_name: 'Set A',
      category_names: ['Set A'],
      similar_word_id: ''
    },
    {
      id: 104,
      title: 'Lake',
      label: 'Lake',
      prompt_label: 'Lake',
      translation: 'Lake',
      image: buildSvgImage(220, 140, '#10b981'),
      audio: '',
      audio_files: [
        { url: 'https://example.test/audio/104-question.mp3', recording_type: 'question' },
        { url: 'https://example.test/audio/104-isolation.mp3', recording_type: 'isolation' }
      ],
      practice_recording_types: ['question', 'isolation'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [],
      category_id: 11,
      category_ids: [11],
      category_name: 'Set A',
      category_names: ['Set A'],
      similar_word_id: ''
    },
    {
      id: 105,
      title: 'Sword',
      label: 'Sword',
      prompt_label: 'Sword',
      translation: 'Sword',
      image: buildSvgImage(150, 240, '#ef4444'),
      audio: '',
      audio_files: [
        { url: 'https://example.test/audio/105-isolation.mp3', recording_type: 'isolation' },
        { url: 'https://example.test/audio/105-introduction.mp3', recording_type: 'introduction' }
      ],
      practice_recording_types: ['isolation', 'introduction'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [],
      category_id: 22,
      category_ids: [22],
      category_name: 'Set B',
      category_names: ['Set B'],
      similar_word_id: 106
    },
    {
      id: 106,
      title: 'Shield',
      label: 'Shield',
      prompt_label: 'Shield',
      translation: 'Shield',
      image: buildSvgImage(240, 150, '#8b5cf6'),
      audio: '',
      audio_files: [
        { url: 'https://example.test/audio/106-question.mp3', recording_type: 'question' },
        { url: 'https://example.test/audio/106-introduction.mp3', recording_type: 'introduction' }
      ],
      practice_recording_types: ['question', 'introduction'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [],
      category_id: 22,
      category_ids: [22],
      category_name: 'Set B',
      category_names: ['Set B'],
      similar_word_id: 105
    },
    {
      id: 107,
      title: 'Tree',
      label: 'Tree',
      prompt_label: 'Tree',
      translation: 'Tree',
      image: buildSvgImage(180, 180, '#0ea5e9'),
      audio: '',
      audio_files: [
        { url: 'https://example.test/audio/107-question.mp3', recording_type: 'question' },
        { url: 'https://example.test/audio/107-isolation.mp3', recording_type: 'isolation' },
        { url: 'https://example.test/audio/107-introduction.mp3', recording_type: 'introduction' }
      ],
      practice_recording_types: ['question', 'isolation', 'introduction'],
      preferred_speaker_user_id: 0,
      option_blocked_ids: [],
      category_id: 22,
      category_ids: [22],
      category_name: 'Set B',
      category_names: ['Set B'],
      similar_word_id: ''
    }
  ];
}

function buildGamesConfig(isLoggedIn) {
  return {
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: isLoggedIn ? 'nonce-77' : '',
    isLoggedIn: !!isLoggedIn,
    sortLocale: 'en_US',
    view: 'games',
    wordsetId: 77,
    wordsetSlug: 'test-wordset',
    wordsetName: 'Test Wordset',
    links: {
      base: '/wordsets/test-wordset/',
      progress: '/wordsets/test-wordset/progress/',
      games: '/wordsets/test-wordset/games/',
      hidden: '/wordsets/test-wordset/hidden-categories/',
      settings: '/wordsets/test-wordset/settings/'
    },
    progressReset: {
      enabled: false,
      actionUrl: '',
      nonce: '',
      wordsetId: 77
    },
    progressIncludeHidden: false,
    categories: [
      {
        id: 11,
        slug: 'set-a',
        name: 'Set A',
        translation: 'Set A',
        count: 12,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: [],
        has_images: true
      },
      {
        id: 22,
        slug: 'set-b',
        name: 'Set B',
        translation: 'Set B',
        count: 12,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: [],
        has_images: true
      }
    ],
    visibleCategoryIds: [11, 22],
    hiddenCategoryIds: [],
    state: {
      wordset_id: 77,
      category_ids: [11, 22],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    goals: {
      enabled_modes: ['practice'],
      ignored_category_ids: [],
      preferred_wordset_ids: [77],
      placement_known_category_ids: [],
      daily_new_word_target: 0,
      priority_focus: ''
    },
    nextActivity: null,
    recommendationQueue: [],
    analytics: {
      scope: {},
      summary: {},
      daily_activity: { days: [], max_events: 0, window_days: 14 },
      categories: [],
      words: []
    },
    modeUi: {},
    gender: {
      enabled: false,
      options: [],
      min_count: 2
    },
    games: {
      bootstrapAction: 'll_wordset_games_bootstrap',
      minimumWordCount: 5,
      catalog: {
        'space-shooter': {
          slug: 'space-shooter',
          title: 'Arcane Space Shooter',
          description: 'Hear the word. Blast the matching picture.'
        },
        'bubble-pop': {
          slug: 'bubble-pop',
          title: 'Bubble Pop',
          description: 'Hear the word. Pop the matching bubble.'
        }
      },
      spaceShooter: {
        slug: 'space-shooter',
        lives: 3,
        cardCount: 4,
        maxLoadedWords: 6,
        fireIntervalMs: 165,
        correctCoinReward: 1,
        wrongHitLifePenalty: 1,
        timeoutCoinPenalty: 1,
        timeoutLifePenalty: 1,
        audioSafeLineRatio: 0.6,
        cardEntryRevealMs: 560,
        promptAutoReplayGapMs: 420,
        promptAudioVolume: 1,
        correctHitVolume: 0.28,
        wrongHitVolume: 0.2,
        correctHitAudioSources: ['https://example.test/media/space-shooter-correct-hit.mp3'],
        wrongHitAudioSources: ['https://example.test/media/space-shooter-wrong-hit.mp3']
      },
      bubblePop: {
        slug: 'bubble-pop',
        lives: 3,
        cardCount: 4,
        maxLoadedWords: 6,
        correctCoinReward: 1,
        wrongHitLifePenalty: 1,
        timeoutCoinPenalty: 1,
        timeoutLifePenalty: 1,
        audioSafeLineRatio: 0.58,
        cardEntryRevealMs: 520,
        promptAutoReplayGapMs: 420,
        promptAudioVolume: 1,
        correctHitVolume: 0.28,
        wrongHitVolume: 0.2,
        correctHitAudioSources: ['https://example.test/media/bubble-pop.mp3'],
        wrongHitAudioSources: ['https://example.test/media/space-shooter-wrong-hit.mp3']
      }
    },
    summaryCounts: {
      mastered: 0,
      studied: 6,
      new: 0,
      starred: 0,
      hard: 0
    },
    summaryCountsDeferred: false,
    learningMinChunkSize: 8,
    hardWordDifficultyThreshold: 4,
    i18n: {
      nextNone: 'Loading next recommendation...',
      nextLoading: 'Loading next recommendation...',
      nextReady: 'Recommended: %1$s in %2$s (%3$d words).',
      nextReadyNoCount: 'Recommended: %1$s in %2$s.',
      categoriesLabel: 'Categories',
      starredWordsLabel: 'Starred words',
      repeatLabel: 'Repeat',
      continueLabel: 'Continue',
      resultsDifferentChunk: 'Categories',
      resultsDifferentChunkCount: 'Categories (%2$d)',
      resultsRecommendedActivity: 'Recommended',
      resultsRecommendedLoading: 'Loading recommendation...',
      resultsRecommendedActivityCount: 'Recommended (%2$d)',
      selectionLabel: 'Select categories to study together',
      selectionCount: '%d selected',
      selectionCountWords: '%1$d selected · %2$d words',
      selectionWordsOnly: '%d words',
      selectionStarredOnly: 'Starred only',
      selectionHardOnly: 'Hard words only',
      selectAll: 'Select all',
      deselectAll: 'Deselect all',
      priorityFocusNew: 'New words',
      priorityFocusStudied: 'In progress words',
      priorityFocusLearned: 'Learned words',
      priorityFocusStarred: 'Starred words',
      priorityFocusHard: 'Hard words',
      clearSelection: 'Clear',
      saveError: 'Unable to save right now.',
      noCategoriesSelected: 'Select at least one category.',
      noWordsInSelection: 'No quiz words are available for this selection.',
      noStarredWordsInSelection: 'No starred words are available for this selection.',
      noHardWordsInSelection: 'No hard words are available for this selection.',
      noStarredHardWordsInSelection: 'No starred hard words are available for this selection.',
      hiddenEmpty: 'No hidden categories in this word set.',
      hiddenCountLabel: 'Hidden categories: %d',
      queueEmpty: 'No upcoming activities yet.',
      queueRemove: 'Remove activity',
      queueWordCount: '%d words',
      analyticsLabel: 'Progress',
      analyticsLoading: 'Loading progress...',
      analyticsUnavailable: 'Progress is unavailable right now.',
      analyticsScopeSelected: 'Selected categories (%d)',
      analyticsScopeAll: 'All categories (%d)',
      analyticsMastered: 'Learned',
      analyticsStudied: 'In progress',
      analyticsNew: 'New',
      analyticsStarred: 'Starred',
      analyticsHard: 'Hard',
      analyticsDaily: 'Last 14 days',
      analyticsDailyEmpty: 'No activity yet.',
      analyticsTabCategories: 'Categories',
      analyticsTabWords: 'Words',
      analyticsNoRows: 'No data yet.',
      analyticsWordFilterAll: 'All',
      analyticsWordFilterHard: 'Hardest',
      analyticsWordFilterNew: 'New',
      analyticsUnseen: 'New',
      analyticsWordStatusMastered: 'Learned',
      analyticsWordStatusStudied: 'In progress',
      analyticsWordStatusNew: 'New',
      analyticsStarWord: 'Star word',
      analyticsUnstarWord: 'Unstar word',
      analyticsFilterAny: 'Any',
      analyticsFilterStar: 'Starred',
      analyticsFilterStatus: 'Status',
      analyticsFilterLast: 'Last',
      analyticsFilterDifficulty: 'Difficulty Score',
      analyticsFilterSeen: 'Seen',
      analyticsFilterWrong: 'Wrong',
      analyticsFilterStarredOnly: 'Starred only',
      analyticsFilterUnstarredOnly: 'Unstarred only',
      analyticsFilterDifficultyHard: 'Hard words',
      analyticsClearFilters: 'Clear filters',
      analyticsFilterLast24h: 'Last 24h',
      analyticsFilterLast7d: 'Last 7d',
      analyticsFilterLast30d: 'Last 30d',
      analyticsFilterLastOlder: 'Older',
      analyticsFilterLastNever: 'Never',
      analyticsSortAsc: 'Sort ascending',
      analyticsSortDesc: 'Sort descending',
      analyticsSelectAllShown: 'Select all',
      analyticsDeselectAllShown: 'Deselect all',
      analyticsSelectAllWithContext: 'Select all: %1$s',
      analyticsDeselectAllWithContext: 'Deselect all: %1$s',
      analyticsSelectAllContextFiltered: 'Filtered words',
      analyticsSelectionCount: '%d selected words',
      analyticsLast: 'Last',
      analyticsNever: 'Never',
      analyticsDayEvents: '%1$d events, %2$d words',
      modePractice: 'Practice',
      modeLearning: 'Learn',
      modeListening: 'Listen',
      modeGender: 'Gender',
      modeSelfCheck: 'Self Check',
      progressResetCategoryConfirm: '',
      progressResetCategoryAria: '',
      gamesLoading: 'Checking game availability...',
      gamesPreparingRun: 'Preparing game...',
      gamesLoginRequired: 'Sign in to play with your in-progress words.',
      gamesLoadError: 'Unable to load games right now.',
      gamesReadyCount: '%d words ready',
      gamesNeedWords: 'Need %d more words to unlock this game.',
      gamesNeedCompatibleWords: 'This word set does not have a playable mix of picture cards yet.',
      gamesPlay: 'Play',
      gamesLocked: 'Locked',
      gamesBack: 'Games',
      gamesReplayAudio: 'Replay prompt',
      gamesPauseRun: 'Pause run',
      gamesResumeRun: 'Resume',
      gamesPaused: 'Paused',
      gamesInactivePauseSummary: 'Paused after %d rounds without input.',
      gamesCoins: 'Coins',
      gamesLives: 'Lives',
      gamesControlLeft: 'Move left',
      gamesControlRight: 'Move right',
      gamesControlFire: 'Fire',
      gamesGameOver: 'Run Complete',
      gamesSummary: 'Coins: %1$d · Prompts: %2$d',
      gamesReplayRun: 'Replay',
      gamesBackToCatalog: 'Back to games',
      gamesBoardLabelDefault: 'Wordset game board',
      gamesBoardLabelSpaceShooter: 'Arcane Space Shooter game board',
      gamesBoardLabelBubblePop: 'Bubble Pop game board'
    }
  };
}

async function mountGamesPage(page, {
  isLoggedIn,
  words = buildSpaceShooterWords(),
  audioLoadDelayMs = 60,
  promptAudioDurationSeconds = 4.2,
  promptAutoReplayGapMs = null,
  spaceShooterOverrides = null,
  bubblePopOverrides = null
} = {}) {
  await page.setContent(buildGamesMarkup(), { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(
    ({ config, gameWords, audioLoadDelay, promptAudioDuration, replayGapMs, shooterOverrides, bubbleOverrides }) => {
      window.llWordsetPageData = config;
      if (
        window.llWordsetPageData
        && window.llWordsetPageData.games
        && window.llWordsetPageData.games.spaceShooter
        && replayGapMs !== null
        && Number.isFinite(Number(replayGapMs))
      ) {
        window.llWordsetPageData.games.spaceShooter.promptAutoReplayGapMs = Number(replayGapMs);
      }
      if (
        window.llWordsetPageData
        && window.llWordsetPageData.games
        && window.llWordsetPageData.games.spaceShooter
        && shooterOverrides
        && typeof shooterOverrides === 'object'
      ) {
        Object.assign(window.llWordsetPageData.games.spaceShooter, shooterOverrides);
      }
      if (
        window.llWordsetPageData
        && window.llWordsetPageData.games
        && window.llWordsetPageData.games.bubblePop
        && bubbleOverrides
        && typeof bubbleOverrides === 'object'
      ) {
        Object.assign(window.llWordsetPageData.games.bubblePop, bubbleOverrides);
      }
      window.__gameBootstrapWords = gameWords;
      window.__queuedProgressEvents = [];
      window.__flushCount = 0;
      window.__scrollCalls = [];
      window.__audioLoadDelay = Number(audioLoadDelay || 0);
      window.__promptAudioDurationSeconds = Number(promptAudioDuration || 4.2);
      window.__audioEventLog = [];

      window.scrollTo = function (leftOrOptions, top) {
        if (typeof leftOrOptions === 'object' && leftOrOptions !== null) {
          window.__scrollCalls.push({
            top: Number(leftOrOptions.top || 0),
            left: Number(leftOrOptions.left || 0),
            behavior: String(leftOrOptions.behavior || '')
          });
          return;
        }

        window.__scrollCalls.push({
          left: Number(leftOrOptions || 0),
          top: Number(top || 0),
          behavior: ''
        });
      };

      window.FlashcardAudio = {
        selectBestAudio(word, preferredTypes) {
          const files = Array.isArray(word && word.audio_files) ? word.audio_files : [];
          const preferred = Array.isArray(preferredTypes) && preferredTypes.length ? preferredTypes : ['question', 'isolation', 'introduction'];
          for (const rawType of preferred) {
            const type = String(rawType || '').trim().toLowerCase();
            const match = files.find((file) => file && String(file.recording_type || '').trim().toLowerCase() === type && file.url);
            if (match) {
              return match.url;
            }
          }
          return String(word && word.audio || '');
        }
      };

      window.Audio = class FakeAudio extends EventTarget {
        constructor() {
          super();
          this._src = '';
          this.currentTime = 0;
          this.preload = 'auto';
          this.readyState = 0;
          this.duration = Number.NaN;
          this.paused = true;
          this._loadTimer = 0;
          this._playTimer = 0;
        }

        get src() {
          return this._src;
        }

        set src(value) {
          this._clearPlayTimer();
          this._src = String(value || '');
          this.readyState = 0;
          this._queueReady();
        }

        load() {
          this._queueReady();
        }

        play() {
          this._queueReady();
          return new Promise((resolve) => {
            window.setTimeout(() => {
              if (!this._src) {
                resolve();
                return;
              }
              this.paused = false;
              this._logEvent('play');
              this.dispatchEvent(new Event('play'));
              this.dispatchEvent(new Event('playing'));
              this._queueEnded();
              resolve();
            }, 0);
          });
        }

        pause() {
          this._clearPlayTimer();
          this.paused = true;
          this.dispatchEvent(new Event('pause'));
        }

        _clearPlayTimer() {
          if (this._playTimer) {
            window.clearTimeout(this._playTimer);
            this._playTimer = 0;
          }
        }

        _describeSource() {
          const src = String(this._src || '');
          if (src.includes('bubble-pop.mp3')) {
            return 'bubble-pop-feedback';
          }
          if (src.includes('space-shooter-correct-hit')) {
            return 'correct-feedback';
          }
          if (src.includes('space-shooter-wrong-hit')) {
            return 'wrong-feedback';
          }
          return src ? `prompt:${src.split('/').pop()}` : '';
        }

        _logEvent(eventType) {
          const label = this._describeSource();
          if (!label) {
            return;
          }
          window.__audioEventLog.push(eventType === 'ended' ? `${label}-ended` : label);
        }

        _queueReady() {
          if (!this._src) {
            return;
          }
          if (this._loadTimer) {
            window.clearTimeout(this._loadTimer);
          }
          const expectedSrc = this._src;
          this._loadTimer = window.setTimeout(() => {
            if (this._src !== expectedSrc) {
              return;
            }
            this.readyState = 4;
            this.duration = (expectedSrc.includes('space-shooter-') || expectedSrc.includes('bubble-pop.mp3'))
              ? 0.12
              : Number(window.__promptAudioDurationSeconds || 4.2);
            this.dispatchEvent(new Event('loadeddata'));
            this.dispatchEvent(new Event('canplay'));
            this.dispatchEvent(new Event('canplaythrough'));
          }, Math.max(0, Number(window.__audioLoadDelay || 0)));
        }

        _queueEnded() {
          this._clearPlayTimer();
          const expectedSrc = this._src;
          const durationMs = Math.max(20, Math.round((Number(this.duration) || 0.12) * 1000));
          this._playTimer = window.setTimeout(() => {
            if (this._src !== expectedSrc) {
              return;
            }
            this.paused = true;
            this._logEvent('ended');
            this.dispatchEvent(new Event('ended'));
          }, durationMs);
        }
      };

      window.LLFlashcards = {
        ProgressTracker: {
          setContext(ctx) {
            window.__progressContext = ctx;
            return ctx;
          },
          trackWordExposure(entry) {
            window.__queuedProgressEvents.push({ type: 'word_exposure', entry });
            return `exp-${window.__queuedProgressEvents.length}`;
          },
          trackWordOutcome(entry) {
            window.__queuedProgressEvents.push({ type: 'word_outcome', entry });
            return `out-${window.__queuedProgressEvents.length}`;
          },
          flush() {
            window.__flushCount += 1;
            if (window.jQuery) {
              window.jQuery(document).trigger('lltools:progress-updated', [{ ok: true }]);
            }
            return Promise.resolve({ queued: 0 });
          }
        }
      };

      const $ = window.jQuery;
      $.post = function (_url, data) {
        const deferred = $.Deferred();
        if (data && data.action === 'll_wordset_games_bootstrap') {
          deferred.resolve({
            success: true,
            data: {
              wordset_id: 77,
              games: {
                'space-shooter': {
                  slug: 'space-shooter',
                  title: 'Arcane Space Shooter',
                  description: 'Hear the word. Blast the matching picture.',
                  minimum_word_count: 5,
                  available_word_count: window.__gameBootstrapWords.length,
                  launch_word_cap: Number((((window.llWordsetPageData || {}).games || {}).spaceShooter || {}).maxLoadedWords || 6),
                  launch_word_count: Math.min(
                    window.__gameBootstrapWords.length,
                    Number((((window.llWordsetPageData || {}).games || {}).spaceShooter || {}).maxLoadedWords || 6)
                  ),
                  launchable: true,
                  category_ids: [11, 22],
                  words: window.__gameBootstrapWords
                },
                'bubble-pop': {
                  slug: 'bubble-pop',
                  title: 'Bubble Pop',
                  description: 'Hear the word. Pop the matching bubble.',
                  minimum_word_count: 5,
                  available_word_count: window.__gameBootstrapWords.length,
                  launch_word_cap: Number((((window.llWordsetPageData || {}).games || {}).bubblePop || {}).maxLoadedWords || 6),
                  launch_word_count: Math.min(
                    window.__gameBootstrapWords.length,
                    Number((((window.llWordsetPageData || {}).games || {}).bubblePop || {}).maxLoadedWords || 6)
                  ),
                  launchable: true,
                  category_ids: [11, 22],
                  words: window.__gameBootstrapWords
                }
              }
            }
          });
        } else {
          deferred.reject(new Error('Unexpected ajax call'));
        }
        return deferred.promise();
      };
    },
    {
      config: buildGamesConfig(isLoggedIn),
      gameWords: words,
      audioLoadDelay: audioLoadDelayMs,
      promptAudioDuration: promptAudioDurationSeconds,
      replayGapMs: promptAutoReplayGapMs,
      shooterOverrides: spaceShooterOverrides,
      bubbleOverrides: bubblePopOverrides
    }
  );
  await page.addStyleTag({ content: wordsetGamesStyles });
  await page.addScriptTag({ content: optionConflictsSource });
  await page.addScriptTag({ content: wordsetGamesSource });
  await page.addScriptTag({ content: wordsetPagesSource });
}

test('games page keeps launch disabled when logged out', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: false });

  await expect(gameLaunchButton(page, 'space-shooter')).toBeDisabled();
  await expect(gameStatus(page, 'space-shooter')).toHaveText(
    'Sign in to play with your in-progress words.'
  );
  await expect(gameLaunchButton(page, 'bubble-pop')).toBeDisabled();
});

test('games catalog keeps cards compact on wide screens and uses the bubble theme for bubble pop launch', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1080 });
  await mountGamesPage(page, { isLoggedIn: true });

  await expect(gameLaunchButton(page, 'space-shooter')).toBeEnabled();
  await expect(gameLaunchButton(page, 'bubble-pop')).toBeEnabled();

  const catalogStyles = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-games-root]');
    const spaceCard = document.querySelector('[data-game-slug="space-shooter"]');
    const bubbleCard = document.querySelector('[data-game-slug="bubble-pop"]');
    const bubbleButton = bubbleCard ? bubbleCard.querySelector('[data-ll-wordset-game-launch]') : null;
    const buttonStyles = bubbleButton ? window.getComputedStyle(bubbleButton) : null;

    return {
      rootWidth: root ? Math.round(root.getBoundingClientRect().width) : 0,
      spaceCardWidth: spaceCard ? Math.round(spaceCard.getBoundingClientRect().width) : 0,
      bubbleCardWidth: bubbleCard ? Math.round(bubbleCard.getBoundingClientRect().width) : 0,
      bubbleButtonBackgroundImage: buttonStyles ? String(buttonStyles.backgroundImage || '') : '',
      bubbleButtonBackgroundColor: buttonStyles ? String(buttonStyles.backgroundColor || '') : ''
    };
  });

  expect(catalogStyles.rootWidth).toBeGreaterThan(900);
  expect(catalogStyles.spaceCardWidth).toBeLessThan(catalogStyles.rootWidth * 0.75);
  expect(catalogStyles.bubbleCardWidth).toBeLessThan(catalogStyles.rootWidth * 0.75);
  expect(
    catalogStyles.bubbleButtonBackgroundImage.includes('154, 221, 255')
      || catalogStyles.bubbleButtonBackgroundImage.includes('120, 203, 245')
      || catalogStyles.bubbleButtonBackgroundColor.includes('154, 221, 255')
  ).toBe(true);
});

test('space shooter auto-replays the prompt once after a short pause', async ({ page }) => {
  await mountGamesPage(page, {
    isLoggedIn: true,
    audioLoadDelayMs: 20,
    promptAudioDurationSeconds: 0.35,
    promptAutoReplayGapMs: 120,
    spaceShooterOverrides: {
      introRampStartFactor: 1
    }
  });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch();
  });

  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.promptId && run.promptAudioDurationMs > 0 && run.promptAutoReplayDelayMs > 0);
  });

  const replayTiming = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(replayTiming.promptAutoReplaySafeLineGated).toBe(true);
  expect(replayTiming.promptSafeLineCrossDelayMs).toBeGreaterThan(replayTiming.promptAutoReplayBaseDelayMs);
  expect(replayTiming.promptAutoReplayDelayMs).toBeGreaterThan(replayTiming.promptSafeLineCrossDelayMs);

  await page.waitForTimeout(replayTiming.promptAutoReplayBaseDelayMs + 500);
  const earlyPromptPlayCount = await page.evaluate(() =>
    window.__audioEventLog.filter((entry) => entry.startsWith('prompt:') && !entry.endsWith('-ended')).length
  );
  expect(earlyPromptPlayCount).toBe(1);

  await page.waitForFunction(() => {
    const events = Array.isArray(window.__audioEventLog) ? window.__audioEventLog : [];
    return events.filter((entry) => entry.startsWith('prompt:') && !entry.endsWith('-ended')).length >= 2;
  }, null, { timeout: Math.max(7000, replayTiming.promptAutoReplayDelayMs + 2000) });

  const promptEvents = await page.evaluate(() =>
    window.__audioEventLog.filter((entry) => entry.startsWith('prompt:'))
  );
  const firstPromptPlay = promptEvents.findIndex((entry) => !entry.endsWith('-ended'));
  const firstPromptEnd = promptEvents.findIndex((entry, index) => index > firstPromptPlay && entry.endsWith('-ended'));
  const secondPromptPlay = promptEvents.findIndex((entry, index) => index > firstPromptEnd && !entry.endsWith('-ended'));
  expect(firstPromptPlay).toBeGreaterThanOrEqual(0);
  expect(firstPromptEnd).toBeGreaterThan(firstPromptPlay);
  expect(secondPromptPlay).toBeGreaterThan(firstPromptEnd);

  await page.waitForTimeout(700);
  const replayedPromptCount = await page.evaluate(() =>
    window.__audioEventLog.filter((entry) => entry.startsWith('prompt:') && !entry.endsWith('-ended')).length
  );
  expect(replayedPromptCount).toBe(2);
});

test('wrong answers replay the prompt quickly and never cost more than one life for that prompt', async ({ page }) => {
  await mountGamesPage(page, {
    isLoggedIn: true,
    audioLoadDelayMs: 15,
    promptAudioDurationSeconds: 0.35,
    spaceShooterOverrides: {
      introRampStartFactor: 1
    }
  });

  for (const slug of ['space-shooter', 'bubble-pop']) {
    await page.evaluate((requestedSlug) => {
      window.LLWordsetGames.__debug.launch(requestedSlug);
    }, slug);
    await waitForActivePrompt(page, slug);

    await page.evaluate(() => {
      window.__audioEventLog = [];
      window.LLWordsetGames.__debug.resolvePrompt('wrong');
    });

    await page.waitForFunction(() => {
      const events = Array.isArray(window.__audioEventLog) ? window.__audioEventLog : [];
      return events.includes('wrong-feedback')
        && events.includes('wrong-feedback-ended')
        && events.some((entry) => entry.startsWith('prompt:') && !entry.endsWith('-ended'));
    });

    const audioEventsAfterWrong = await page.evaluate(() => window.__audioEventLog.slice());
    const wrongFeedbackIndex = audioEventsAfterWrong.indexOf('wrong-feedback');
    const wrongFeedbackEndedIndex = audioEventsAfterWrong.indexOf('wrong-feedback-ended');
    const replayedPromptIndex = audioEventsAfterWrong.findIndex((entry, index) =>
      index > wrongFeedbackIndex
      && entry.startsWith('prompt:')
      && !entry.endsWith('-ended')
    );

    expect(wrongFeedbackIndex).toBeGreaterThanOrEqual(0);
    expect(wrongFeedbackEndedIndex).toBeGreaterThan(wrongFeedbackIndex);
    expect(replayedPromptIndex).toBeGreaterThan(wrongFeedbackIndex);
    expect(replayedPromptIndex).toBeLessThan(wrongFeedbackEndedIndex);

    const afterWrongState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
    expect(afterWrongState).toBeTruthy();
    expect(afterWrongState.lives).toBe(2);

    await page.waitForTimeout(260);
    await page.evaluate(() => {
      window.LLWordsetGames.__debug.resolvePrompt('timeout');
    });

    await page.waitForFunction(({ expectedSlug, previousPromptId }) => {
      const run = window.LLWordsetGames.__debug.getRunState();
      return !!(run
        && run.slug === expectedSlug
        && run.promptId !== previousPromptId
        && run.targetWordId
        && !run.awaitingPrompt);
    }, {
      expectedSlug: slug,
      previousPromptId: afterWrongState.promptId
    });

    const afterTimeoutState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
    expect(afterTimeoutState.lives).toBe(2);

    if (slug === 'bubble-pop') {
      expect(
        afterTimeoutState.cardSnapshot.some((card) =>
          card.promptId === afterWrongState.promptId
          && card.resolvedFalling
          && !card.exploding
        )
      ).toBe(true);
    }

    await page.click('[data-ll-wordset-games-back]');
    await expect(page.locator('[data-ll-wordset-game-stage]')).toBeHidden();
    await expect(page.locator('[data-ll-wordset-games-catalog]')).toBeVisible();
  }
});

test('bubble pop floats options upward and resolves clicks through the canvas', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await expect(gameLaunchButton(page, 'bubble-pop')).toBeEnabled();
  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch('bubble-pop');
  });

  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.slug === 'bubble-pop' && run.targetWordId && run.activeCardCount === 4 && !run.awaitingPrompt);
  });

  await expect(page.locator('[data-ll-wordset-game-stage]')).toHaveAttribute('data-ll-wordset-active-game', 'bubble-pop');
  await expect(page.locator('[data-ll-wordset-game-controls]')).toBeHidden();

  const initialState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  await page.waitForTimeout(260);
  const movedState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  const initialTarget = initialState.cardSnapshot.find((card) => card.isTarget && card.promptId === initialState.promptId);
  const movedTarget = movedState.cardSnapshot.find((card) => card.isTarget && card.promptId === movedState.promptId);
  expect(initialTarget).toBeTruthy();
  expect(movedTarget).toBeTruthy();
  expect(movedTarget.y).toBeLessThan(initialTarget.y - 6);

  await page.waitForTimeout(240);
  const handoffState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  await page.waitForTimeout(90);
  const settledState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  const handoffTarget = handoffState.cardSnapshot.find((card) => card.isTarget && card.promptId === handoffState.promptId);
  const settledTarget = settledState.cardSnapshot.find((card) => card.isTarget && card.promptId === settledState.promptId);
  expect(handoffTarget).toBeTruthy();
  expect(settledTarget).toBeTruthy();
  expect(Math.abs(settledTarget.x - handoffTarget.x)).toBeLessThan(12);

  const blastCandidate = movedState.cardSnapshot
    .filter((card) => !card.isTarget && card.promptId === movedState.promptId)
    .map((card) => ({
      wordId: card.wordId,
      distance: Math.hypot(card.x - movedTarget.x, card.y - movedTarget.y)
    }))
    .sort((left, right) => left.distance - right.distance)[0];
  expect(blastCandidate).toBeTruthy();

  const clickPoint = await page.evaluate(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    const canvas = document.querySelector('[data-ll-wordset-game-canvas]');
    const target = run && Array.isArray(run.cardSnapshot)
      ? run.cardSnapshot.find((card) => card.isTarget && card.promptId === run.promptId && !card.exploding)
      : null;
    if (!run || !canvas || !target) {
      return null;
    }
    const rect = canvas.getBoundingClientRect();
    return {
      x: rect.left + ((target.x / Math.max(1, canvas.clientWidth || run.width)) * rect.width),
      y: rect.top + ((target.y / Math.max(1, canvas.clientHeight || run.height)) * rect.height)
    };
  });
  expect(clickPoint).toBeTruthy();
  await page.mouse.click(clickPoint.x, clickPoint.y);

  await page.waitForTimeout(60);
  const blastState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  const poppedTarget = blastState.cardSnapshot.find((card) => card.isTarget && card.promptId === movedState.promptId);
  const pushedNeighbor = blastState.cardSnapshot.find((card) => card.wordId === blastCandidate.wordId);
  const originalNeighbor = movedState.cardSnapshot.find((card) => card.wordId === blastCandidate.wordId);
  expect(poppedTarget).toBeTruthy();
  expect(pushedNeighbor).toBeTruthy();
  expect(originalNeighbor).toBeTruthy();
  expect(
    Math.hypot(
      pushedNeighbor.x - originalNeighbor.x,
      pushedNeighbor.y - originalNeighbor.y
    )
  ).toBeGreaterThan(4);

  await page.waitForFunction(() => {
    const queued = Array.isArray(window.__queuedProgressEvents) ? window.__queuedProgressEvents : [];
    return queued.length >= 2;
  });
  await page.waitForFunction(() => {
    const events = Array.isArray(window.__audioEventLog) ? window.__audioEventLog : [];
    return events.includes('bubble-pop-feedback');
  });

  const progressEvents = await page.evaluate(() => window.__queuedProgressEvents.slice());
  expect(progressEvents[0].entry.payload.game_slug).toBe('bubble-pop');
  expect(progressEvents[1].entry.payload.game_slug).toBe('bubble-pop');
  expect(progressEvents[1].entry.isCorrect).toBe(true);
  await expect(page.locator('[data-ll-wordset-game-coins]')).toHaveText('1');
});

test('bubble pop pause overlay uses the bubble theme for resume', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch('bubble-pop');
  });

  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.slug === 'bubble-pop' && run.targetWordId && !run.awaitingPrompt);
  });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.togglePause();
  });

  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-replay]')).toHaveText('Resume');

  const overlayStyles = await page.evaluate(() => {
    const button = document.querySelector('[data-ll-wordset-game-replay]');
    const overlay = document.querySelector('[data-ll-wordset-game-overlay]');
    if (!button || !overlay) {
      return null;
    }

    const styles = window.getComputedStyle(button);
    return {
      mode: overlay.getAttribute('data-ll-wordset-game-overlay-mode') || '',
      backgroundImage: styles.backgroundImage,
      backgroundColor: styles.backgroundColor
    };
  });

  expect(overlayStyles).toBeTruthy();
  expect(overlayStyles.mode).toBe('paused');
  expect(
    overlayStyles.backgroundImage.includes('154, 221, 255')
      || overlayStyles.backgroundColor.includes('154, 221, 255')
  ).toBe(true);
});

test('games page header back returns to the games catalog while a run is open', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch('bubble-pop');
  });
  await waitForActivePrompt(page, 'bubble-pop');

  await expect(page.locator('[data-ll-wordset-games-back-label]')).toHaveText('Games');
  await expect(page.locator('[data-ll-wordset-games-page-title]')).toHaveText('Bubble Pop');

  await page.click('[data-ll-wordset-games-back]');

  await expect(page.locator('[data-ll-wordset-game-stage]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-games-catalog]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-games-back-label]')).toHaveText('Test Wordset');
  await expect(page.locator('[data-ll-wordset-games-page-title]')).toHaveText('Games');

  const contextState = await page.evaluate(() => window.LLWordsetGames.__debug.getContext());
  expect(contextState).toBeTruthy();
  expect(contextState.gameRunning).toBe(false);
  expect(contextState.stageHidden).toBe(true);
});

test('bubble pop decorative bubbles pop without affecting score or progress', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch('bubble-pop');
  });

  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.slug === 'bubble-pop' && run.decorativeBubbleCount > 0 && run.targetWordId && !run.awaitingPrompt);
  });

  const decorativeTarget = await page.evaluate(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    const canvas = document.querySelector('[data-ll-wordset-game-canvas]');
    if (!run || !canvas) {
      return null;
    }

    const bubble = (run.decorativeBubbleSnapshot || []).find((entry) => {
      if (!entry || entry.exploding) {
        return false;
      }

      return (run.cardSnapshot || []).every((card) => {
        const cardRadius = Math.max(card.width, card.height) * 0.56;
        const dx = entry.x - card.x;
        const dy = entry.y - card.y;
        return ((dx * dx) + (dy * dy)) > Math.pow(entry.radius + cardRadius + 18, 2);
      });
    });

    if (!bubble) {
      return null;
    }

    const rect = canvas.getBoundingClientRect();
    return {
      id: bubble.id,
      x: rect.left + ((bubble.x / Math.max(1, canvas.clientWidth || run.width)) * rect.width),
      y: rect.top + ((bubble.y / Math.max(1, canvas.clientHeight || run.height)) * rect.height)
    };
  });
  expect(decorativeTarget).toBeTruthy();

  const beforeState = await page.evaluate(() => ({
    progressCount: Array.isArray(window.__queuedProgressEvents) ? window.__queuedProgressEvents.length : 0,
    coins: String(document.querySelector('[data-ll-wordset-game-coins]')?.textContent || ''),
    lives: String(document.querySelector('[data-ll-wordset-game-lives]')?.textContent || '')
  }));

  await page.mouse.click(decorativeTarget.x, decorativeTarget.y);

  await page.waitForFunction((bubbleId) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    if (!run) {
      return false;
    }
    const bubbles = Array.isArray(run.decorativeBubbleSnapshot) ? run.decorativeBubbleSnapshot : [];
    return bubbles.some((bubble) => bubble.id === bubbleId && bubble.exploding) || !bubbles.some((bubble) => bubble.id === bubbleId);
  }, decorativeTarget.id);
  await page.waitForFunction(() => {
    const events = Array.isArray(window.__audioEventLog) ? window.__audioEventLog : [];
    return events.includes('bubble-pop-feedback');
  });

  const afterState = await page.evaluate(() => ({
    progressCount: Array.isArray(window.__queuedProgressEvents) ? window.__queuedProgressEvents.length : 0,
    coins: String(document.querySelector('[data-ll-wordset-game-coins]')?.textContent || ''),
    lives: String(document.querySelector('[data-ll-wordset-game-lives]')?.textContent || '')
  }));

  expect(afterState.progressCount).toBe(beforeState.progressCount);
  expect(afterState.coins).toBe(beforeState.coins);
  expect(afterState.lives).toBe(beforeState.lives);
});

test('both games auto-pause after three inactive rounds and resume into the next prompt', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  for (const slug of ['space-shooter', 'bubble-pop']) {
    await expect(gameLaunchButton(page, slug)).toBeEnabled();
    await page.evaluate((requestedSlug) => {
      window.LLWordsetGames.__debug.launch(requestedSlug);
    }, slug);
    await waitForActivePrompt(page, slug);

    let priorPromptId = await page.evaluate(() => {
      const run = window.LLWordsetGames.__debug.getRunState();
      return run ? run.promptId : 0;
    });

    for (let inactiveRound = 1; inactiveRound <= 2; inactiveRound += 1) {
      await page.evaluate(() => {
        window.LLWordsetGames.__debug.resolvePrompt('timeout');
      });
      await page.waitForFunction(({ expectedSlug, previousPromptId, expectedInactiveRound }) => {
        const run = window.LLWordsetGames.__debug.getRunState();
        return !!(run
          && run.slug === expectedSlug
          && !run.paused
          && run.inactiveRounds === expectedInactiveRound
          && run.promptId !== previousPromptId
          && run.targetWordId
          && !run.awaitingPrompt);
      }, {
        expectedSlug: slug,
        previousPromptId: priorPromptId,
        expectedInactiveRound: inactiveRound
      });

      priorPromptId = await page.evaluate(() => {
        const run = window.LLWordsetGames.__debug.getRunState();
        return run ? run.promptId : 0;
      });
    }

    await page.evaluate(() => {
      window.LLWordsetGames.__debug.resolvePrompt('timeout');
    });
    await page.waitForFunction((expectedSlug) => {
      const run = window.LLWordsetGames.__debug.getRunState();
      return !!(run
        && run.slug === expectedSlug
        && run.paused
        && run.inactiveRounds === 3
        && run.pauseReason === 'inactivity');
    }, slug);

    await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeVisible();
    await expect(page.locator('[data-ll-wordset-game-overlay-title]')).toHaveText('Paused');
    await expect(page.locator('[data-ll-wordset-game-overlay-summary]')).toHaveText('Paused after 3 rounds without input.');

    const pausedPromptId = await page.evaluate(() => {
      const run = window.LLWordsetGames.__debug.getRunState();
      return run ? run.promptId : 0;
    });

    await page.click('[data-ll-wordset-game-replay]');
    await page.waitForFunction(({ expectedSlug, previousPromptId }) => {
      const run = window.LLWordsetGames.__debug.getRunState();
      return !!(run
        && run.slug === expectedSlug
        && !run.paused
        && run.inactiveRounds === 0
        && run.promptId !== previousPromptId
        && run.targetWordId
        && !run.awaitingPrompt);
    }, {
      expectedSlug: slug,
      previousPromptId: pausedPromptId
    });

    await page.click('[data-ll-wordset-games-back]');
    await expect(page.locator('[data-ll-wordset-game-stage]')).toBeHidden();
    await expect(page.locator('[data-ll-wordset-games-catalog]')).toBeVisible();
  }
});

test('space shooter launches with safe option mixes and records progress flows', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await expect(gameLaunchButton(page, 'space-shooter')).toBeEnabled();
  await expect(gameStatus(page, 'space-shooter')).toHaveText('7 words ready');
  await expect(gameLaunchButton(page, 'bubble-pop')).toBeEnabled();
  await expect(gameStatus(page, 'bubble-pop')).toHaveText('7 words ready');
  const catalogContext = await page.evaluate(() => window.LLWordsetGames.__debug.getContext());
  expect(catalogContext.availableWordCount).toBe(7);
  expect(catalogContext.launchWordCap).toBe(6);
  expect(catalogContext.launchWordCount).toBe(6);

  const catalogDimensions = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-games-root]');
    const card = document.querySelector('[data-game-slug="space-shooter"]');
    return {
      rootWidth: root ? Math.round(root.getBoundingClientRect().width) : 0,
      cardWidth: card ? Math.round(card.getBoundingClientRect().width) : 0
    };
  });
  expect(catalogDimensions.cardWidth).toBeGreaterThan(0);
  expect(catalogDimensions.rootWidth).toBeGreaterThanOrEqual(catalogDimensions.cardWidth);

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch();
  });

  await expect(page.locator('[data-ll-wordset-game-stage]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-overlay-title]')).toHaveText('Preparing game...');
  await expect(page.locator('[data-ll-wordset-game-fire-keycap]')).toBeVisible();
  await page.waitForFunction(() => Array.isArray(window.__scrollCalls) && window.__scrollCalls.length > 0);
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.awaitingPrompt && !run.targetWordId);
  });
  const stageDimensions = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-games-root]');
    const stage = document.querySelector('[data-ll-wordset-game-stage]');
    return {
      rootWidth: root ? Math.round(root.getBoundingClientRect().width) : 0,
      stageWidth: stage ? Math.round(stage.getBoundingClientRect().width) : 0
    };
  });
  expect(stageDimensions.stageWidth).toBeGreaterThan(0);
  expect(stageDimensions.rootWidth).toBeGreaterThanOrEqual(stageDimensions.stageWidth);
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.targetWordId && run.activeCardCount === 4 && !run.awaitingPrompt);
  });
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-games-back-label]')).toHaveText('Games');
  await expect(page.locator('[data-ll-wordset-games-page-title]')).toHaveText('Arcane Space Shooter');

  const initialRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(initialRun.activeCardCount).toBe(4);
  expect(['question', 'isolation', 'introduction']).toContain(initialRun.promptRecordingType);
  expect(initialRun.promptAudioDurationMs).toBeGreaterThanOrEqual(4000);
  expect(initialRun.cardSpeed).toBeLessThan(86);
  expect(initialRun.promptDistractorMode).toBe('mixed');
  expect(new Set(initialRun.cardWordIds).size).toBeLessThanOrEqual(6);
  expect(
    initialRun.cardSnapshot
      .filter((card) => card.promptId === initialRun.promptId)
      .some((card) => (card.y - (card.height / 2)) < 0)
  ).toBe(true);

  await page.waitForTimeout(160);
  const midEntryRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  const initialCardYByWordId = Object.fromEntries(
    initialRun.cardSnapshot
      .filter((card) => card.promptId === initialRun.promptId)
      .map((card) => [card.wordId, card.y])
  );
  expect(
    midEntryRun.cardSnapshot
      .filter((card) => card.promptId === midEntryRun.promptId)
      .some((card) => card.y > ((initialCardYByWordId[card.wordId] || 0) + 8))
  ).toBe(true);

  await page.waitForTimeout(460);
  const revealedRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  const revealedCards = revealedRun.cardSnapshot.filter((card) => card.promptId === revealedRun.promptId);
  const revealedCardYByWordId = Object.fromEntries(revealedCards.map((card) => [card.wordId, card.y]));
  expect(
    revealedCards.every((card) => (card.y - (card.height / 2)) >= 0)
  ).toBe(true);
  expect(
    midEntryRun.cardSnapshot
      .filter((card) => card.promptId === midEntryRun.promptId)
      .some((card) => card.y < ((revealedCardYByWordId[card.wordId] || card.y) - 6))
  ).toBe(true);
  expect(Math.max(...revealedCards.map((card) => card.y)) - Math.min(...revealedCards.map((card) => card.y))).toBeGreaterThanOrEqual(18);
  expect(new Set(revealedCards.map((card) => card.speed)).size).toBeGreaterThan(1);

  const disallowedPairs = [
    [101, 102],
    [103, 104],
    [105, 106]
  ];
  disallowedPairs.forEach(([left, right]) => {
    expect(initialRun.cardWordIds).not.toEqual(expect.arrayContaining([left, right]));
  });
  const wrongResolved = await page.evaluate(() => window.LLWordsetGames.__debug.resolvePrompt('wrong'));
  expect(wrongResolved).toBe(true);
  await page.waitForFunction((targetWordId) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    if (!run || run.targetWordId !== targetWordId) {
      return false;
    }
    return run.activeCardCount === 3
      && Array.isArray(run.cardSnapshot)
      && run.cardSnapshot.filter((card) => card.promptId === run.promptId && !card.exploding).length === 3;
  }, initialRun.targetWordId);

  let progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(2);
  expect(progressEvents[0].type).toBe('word_exposure');
  expect(progressEvents[1].type).toBe('word_outcome');
  expect(progressEvents[1].entry.isCorrect).toBe(false);
  expect(progressEvents[1].entry.hadWrongBefore).toBe(false);
  expect(progressEvents[1].entry.payload.recording_type).toBe(initialRun.promptRecordingType);
  await expect(page.locator('[data-ll-wordset-game-lives]')).toHaveText('2');

  await page.evaluate(() => {
    window.__audioEventLog = [];
    window.LLWordsetGames.__debug.resolvePrompt('correct');
  });
  await page.waitForFunction((priorPromptId, priorTargetWordId) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run
      && run.promptsResolved === 1
      && run.promptId !== priorPromptId
      && run.targetWordId !== priorTargetWordId
      && run.activeCardCount === 4
      && Array.isArray(run.cardSnapshot)
      && run.cardSnapshot.length > 4
      && run.cardSnapshot.some((card) => card.promptId === priorPromptId && card.resolvedFalling && !card.exploding));
  }, initialRun.promptId, initialRun.targetWordId);

  let resolvedState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(resolvedState.cardSnapshot.some((card) => card.promptId === initialRun.promptId && card.resolvedFalling && !card.exploding)).toBe(true);
  expect(resolvedState.cardSpeed).toBeGreaterThan(initialRun.cardSpeed);
  expect(resolvedState.promptDistractorMode).toBe('same-category');
  expect(resolvedState.sameCategoryDistractorCount).toBeGreaterThanOrEqual(1);

  const audioEventsAfterCorrect = await page.evaluate(() => window.__audioEventLog.slice());
  const correctFeedbackIndex = audioEventsAfterCorrect.indexOf('correct-feedback');
  const correctFeedbackEndedIndex = audioEventsAfterCorrect.indexOf('correct-feedback-ended');
  const nextPromptAudioIndex = audioEventsAfterCorrect.findIndex((entry) => entry.startsWith('prompt:'));
  expect(correctFeedbackIndex).toBeGreaterThanOrEqual(0);
  expect(correctFeedbackEndedIndex).toBeGreaterThan(correctFeedbackIndex);
  expect(nextPromptAudioIndex).toBeGreaterThan(correctFeedbackEndedIndex);

  progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(3);
  expect(progressEvents[2].entry.isCorrect).toBe(true);
  expect(progressEvents[2].entry.hadWrongBefore).toBe(true);
  expect(progressEvents[2].entry.payload.recording_type).toBe(initialRun.promptRecordingType);
  await expect(page.locator('[data-ll-wordset-game-coins]')).toHaveText('1');

  await page.keyboard.down('Space');
  await expect(page.locator('[data-ll-wordset-game-control="fire"]')).toHaveClass(/is-active/);
  await page.keyboard.up('Space');
  await expect(page.locator('[data-ll-wordset-game-control="fire"]')).not.toHaveClass(/is-active/);

  const pauseSnapshot = await page.evaluate(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    const activeTarget = run && Array.isArray(run.cardSnapshot)
      ? run.cardSnapshot.find((card) => card.isTarget && card.promptId === run.promptId && !card.exploding)
      : null;
    return {
      promptId: run ? run.promptId : 0,
      targetWordId: run ? run.targetWordId : 0,
      targetY: activeTarget ? activeTarget.y : 0
    };
  });

  await page.click('[data-ll-wordset-game-pause-toggle]');
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-overlay-title]')).toHaveText('Paused');
  await expect(page.locator('[data-ll-wordset-game-replay]')).toHaveText('Resume');
  await expect(page.locator('[data-ll-wordset-game-pause-toggle]')).toHaveClass(/is-paused/);
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.paused);
  });
  const pausedBaseline = await page.evaluate(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    const activeTarget = run && Array.isArray(run.cardSnapshot)
      ? run.cardSnapshot.find((card) => card.isTarget && card.promptId === run.promptId && !card.exploding)
      : null;
    return {
      paused: !!(run && run.paused),
      promptId: run ? run.promptId : 0,
      targetWordId: run ? run.targetWordId : 0,
      targetY: activeTarget ? activeTarget.y : 0
    };
  });
  await page.waitForTimeout(220);
  const pausedRun = await page.evaluate(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    const activeTarget = run && Array.isArray(run.cardSnapshot)
      ? run.cardSnapshot.find((card) => card.isTarget && card.promptId === run.promptId && !card.exploding)
      : null;
    return {
      paused: !!(run && run.paused),
      promptId: run ? run.promptId : 0,
      targetWordId: run ? run.targetWordId : 0,
      targetY: activeTarget ? activeTarget.y : 0
    };
  });
  expect(pausedBaseline.paused).toBe(true);
  expect(pausedBaseline.promptId).toBe(pauseSnapshot.promptId);
  expect(pausedBaseline.targetWordId).toBe(pauseSnapshot.targetWordId);
  expect(pausedRun.paused).toBe(true);
  expect(pausedRun.promptId).toBe(pausedBaseline.promptId);
  expect(pausedRun.targetWordId).toBe(pausedBaseline.targetWordId);
  expect(Math.abs(pausedRun.targetY - pausedBaseline.targetY)).toBeLessThanOrEqual(1);

  await page.click('[data-ll-wordset-game-replay]');
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeHidden();
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && !run.paused);
  });

  const resumedRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(resumedRun.promptId).toBe(pauseSnapshot.promptId);
  expect(resumedRun.targetWordId).toBe(pauseSnapshot.targetWordId);

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.resolvePrompt('timeout');
  });
  await page.waitForTimeout(300);

  progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(5);
  expect(progressEvents[3].type).toBe('word_exposure');
  expect(progressEvents[4].entry.isCorrect).toBe(false);
  expect(progressEvents[4].entry.payload.timeout).toBe(true);
  expect(['question', 'isolation', 'introduction']).toContain(progressEvents[4].entry.payload.recording_type);
  await expect(page.locator('[data-ll-wordset-game-coins]')).toHaveText('0');
  await expect(page.locator('[data-ll-wordset-game-lives]')).toHaveText('2');

  await page.waitForFunction((priorPromptId) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.promptId !== priorPromptId && run.targetWordId && !run.awaitingPrompt);
  }, resumedRun.promptId);
  const postWrongTimeout = await page.evaluate(() => {
    const ctx = window.LLWordsetGames.__ctx;
    if (ctx && ctx.run && ctx.run.prompt) {
      const priorPromptId = ctx.run.prompt.promptId;
      ctx.run.lives = 1;
      ctx.run.prompt.hadWrongBefore = true;
      window.LLWordsetGames.__debug.resolvePrompt('timeout');
      return {
        priorPromptId,
        lives: ctx.run.lives
      };
    }
    return null;
  });
  expect(postWrongTimeout).not.toBeNull();

  progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(5);
  expect(progressEvents.filter((entry) => entry.type === 'word_exposure')).toHaveLength(2);
  expect(progressEvents.filter((entry) => entry.type === 'word_outcome')).toHaveLength(3);
  await expect(page.locator('[data-ll-wordset-game-lives]')).toHaveText('1');

  await page.waitForFunction(({ priorPromptId, lives }) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run
      && run.promptId !== priorPromptId
      && run.targetWordId
      && !run.awaitingPrompt
      && run.lives === lives);
  }, postWrongTimeout);
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeHidden();

  await page.evaluate(() => {
    const ctx = window.LLWordsetGames.__ctx;
    if (ctx && ctx.run) {
      ctx.run.lives = 1;
    }
    window.LLWordsetGames.__debug.resolvePrompt('wrong');
  });

  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-overlay-title]')).toHaveText('Run Complete');

  const flushCountAfterGameOver = await page.evaluate(() => window.__flushCount);
  expect(flushCountAfterGameOver).toBeGreaterThanOrEqual(1);

  await page.click('[data-ll-wordset-game-replay]');
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.targetWordId && run.lives === 3);
  });
  let replayState = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(replayState.coins).toBe(0);
  expect(replayState.lives).toBe(3);

  await page.click('[data-ll-wordset-games-back]');
  await expect(page.locator('[data-ll-wordset-game-stage]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-games-catalog]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-games-back-label]')).toHaveText('Test Wordset');
  await expect(page.locator('[data-ll-wordset-games-page-title]')).toHaveText('Games');

  const scrollCallCount = await page.evaluate(() => window.__scrollCalls.length);
  expect(scrollCallCount).toBeGreaterThanOrEqual(2);

  const finalFlushCount = await page.evaluate(() => window.__flushCount);
  expect(finalFlushCount).toBeGreaterThan(flushCountAfterGameOver);

  const progressContext = await page.evaluate(() => window.__progressContext);
  expect(progressContext.wordsetId || progressContext.wordset_id).toBe(77);
});

test('space shooter only deducts one life when buffered shots hit wrong cards in one prompt', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch();
  });
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.targetWordId && run.activeCardCount === 4 && !run.awaitingPrompt);
  });

  const preparedCollision = await page.evaluate(() => {
    const ctx = window.LLWordsetGames.__ctx;
    if (!ctx || !ctx.run || !ctx.run.prompt) {
      return null;
    }

    const run = ctx.run;
    const activeCards = run.cards.filter((card) =>
      card
      && card.promptId === run.prompt.promptId
      && !card.exploding
      && !card.resolvedFalling
    );
    const wrongCards = activeCards.filter((card) => !card.isTarget);
    const targetCard = activeCards.find((card) => card.isTarget);
    if (wrongCards.length < 2 || !targetCard) {
      return null;
    }

    const firstWrong = wrongCards[0];
    const secondWrong = wrongCards[1];

    firstWrong.entryRevealMs = 0;
    secondWrong.entryRevealMs = 0;
    targetCard.entryRevealMs = 0;

    firstWrong.x = run.width * 0.28;
    secondWrong.x = run.width * 0.72;
    targetCard.x = run.width * 0.5;

    firstWrong.y = run.height * 0.42;
    secondWrong.y = run.height * 0.42;
    targetCard.y = run.height * 0.24;

    firstWrong.speed = 0;
    secondWrong.speed = 0;
    targetCard.speed = 0;

    run.controls.fire = true;
    run.lastFireAt = Number.NEGATIVE_INFINITY;
    run.bullets = [
      { x: firstWrong.x, y: firstWrong.y, radius: 3, speed: 0 },
      { x: secondWrong.x, y: secondWrong.y, radius: 3, speed: 0 }
    ];

    return {
      lives: run.lives,
      promptId: run.prompt.promptId,
      targetWordId: run.prompt.target ? run.prompt.target.id : 0
    };
  });

  expect(preparedCollision).not.toBeNull();

  await page.waitForFunction(({ promptId, targetWordId, lives }) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run
      && run.promptId === promptId
      && run.targetWordId === targetWordId
      && run.lives === (lives - 1)
      && run.activeCardCount === 3);
  }, preparedCollision);

  const postCollisionState = await page.evaluate(() => {
    const ctx = window.LLWordsetGames.__ctx;
    return {
      run: window.LLWordsetGames.__debug.getRunState(),
      progressEvents: window.__queuedProgressEvents.slice(),
      bulletCount: ctx && ctx.run ? ctx.run.bullets.length : -1,
      fireHeld: !!(ctx && ctx.run && ctx.run.controls && ctx.run.controls.fire)
    };
  });

  expect(postCollisionState.run.lives).toBe(preparedCollision.lives - 1);
  expect(postCollisionState.run.promptId).toBe(preparedCollision.promptId);
  expect(postCollisionState.run.targetWordId).toBe(preparedCollision.targetWordId);
  expect(postCollisionState.run.activeCardCount).toBe(3);
  expect(postCollisionState.progressEvents).toHaveLength(2);
  expect(postCollisionState.progressEvents[0].type).toBe('word_exposure');
  expect(postCollisionState.progressEvents[1].type).toBe('word_outcome');
  expect(postCollisionState.progressEvents[1].entry.isCorrect).toBe(false);
  expect(postCollisionState.bulletCount).toBe(0);
  expect(postCollisionState.fireHeld).toBe(false);
});

test('space shooter recovers when the active target card disappears unexpectedly', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch();
  });
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.targetWordId && run.activeCardCount === 4 && !run.awaitingPrompt);
  });

  const disruptedPrompt = await page.evaluate(() => {
    const ctx = window.LLWordsetGames.__ctx;
    if (!ctx || !ctx.run || !ctx.run.prompt) {
      return null;
    }

    const promptId = ctx.run.prompt.promptId;
    ctx.run.cards = ctx.run.cards.filter((card) => !(card && card.isTarget && card.promptId === promptId));

    return {
      promptId,
      lives: ctx.run.lives
    };
  });

  expect(disruptedPrompt).not.toBeNull();
  await page.waitForFunction(({ promptId, lives }) => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run
      && run.promptId !== promptId
      && run.lives === lives
      && run.targetWordId
      && !run.awaitingPrompt);
  }, disruptedPrompt);

  const recoveredRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(recoveredRun.promptId).not.toBe(disruptedPrompt.promptId);
  expect(recoveredRun.lives).toBe(disruptedPrompt.lives);
});
