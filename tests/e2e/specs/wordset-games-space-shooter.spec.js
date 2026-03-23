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

function buildGamesMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="games" data-ll-wordset-id="77">
      <section class="ll-wordset-games-page" data-ll-wordset-games-root>
        <div class="ll-wordset-games-catalog" data-ll-wordset-games-catalog>
          <article class="ll-wordset-game-card" data-ll-wordset-game-card data-game-slug="space-shooter">
            <div class="ll-wordset-game-card__body">
              <h2 class="ll-wordset-game-card__title">Arcane Space Shooter</h2>
              <p class="ll-wordset-game-card__description">Hear the word. Blast the matching picture.</p>
              <p class="ll-wordset-game-card__status" data-ll-wordset-game-status></p>
            </div>
            <div class="ll-wordset-game-card__actions">
              <span class="ll-wordset-game-card__count" data-ll-wordset-game-count>—</span>
              <button type="button" data-ll-wordset-game-launch disabled>Play</button>
            </div>
          </article>
        </div>

        <section class="ll-wordset-game-stage" data-ll-wordset-game-stage hidden>
          <div class="ll-wordset-game-stage__hud">
            <button type="button" class="ll-wordset-game-stage__nav" data-ll-wordset-game-close>Games</button>
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
          <div class="ll-wordset-game-stage__controls">
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
              <button type="button" data-ll-wordset-game-replay>Replay</button>
              <button type="button" data-ll-wordset-game-return>Back</button>
            </div>
          </div>
        </section>
      </section>
    </div>
  `;
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
        }
      },
      spaceShooter: {
        slug: 'space-shooter',
        lives: 3,
        cardCount: 4,
        fireIntervalMs: 165,
        timeoutCoinPenalty: 1
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
      gamesCoins: 'Coins',
      gamesLives: 'Lives',
      gamesControlLeft: 'Move left',
      gamesControlRight: 'Move right',
      gamesControlFire: 'Fire',
      gamesGameOver: 'Run Complete',
      gamesSummary: 'Coins: %1$d · Prompts: %2$d',
      gamesReplayRun: 'Replay',
      gamesBackToCatalog: 'Back to games'
    }
  };
}

async function mountGamesPage(page, { isLoggedIn, words = buildSpaceShooterWords() }) {
  await page.setContent(buildGamesMarkup(), { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(
    ({ config, gameWords }) => {
      window.llWordsetPageData = config;
      window.__gameBootstrapWords = gameWords;
      window.__queuedProgressEvents = [];
      window.__flushCount = 0;
      window.__scrollCalls = [];

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
    { config: buildGamesConfig(isLoggedIn), gameWords: words }
  );
  await page.addStyleTag({ content: wordsetGamesStyles });
  await page.addScriptTag({ content: optionConflictsSource });
  await page.addScriptTag({ content: wordsetGamesSource });
  await page.addScriptTag({ content: wordsetPagesSource });
}

test('games page keeps launch disabled when logged out', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: false });

  await expect(page.locator('[data-ll-wordset-game-launch]')).toBeDisabled();
  await expect(page.locator('[data-ll-wordset-game-status]')).toHaveText(
    'Sign in to play with your in-progress words.'
  );
});

test('space shooter launches with safe option mixes and records progress flows', async ({ page }) => {
  await mountGamesPage(page, { isLoggedIn: true });

  await expect(page.locator('[data-ll-wordset-game-launch]')).toBeEnabled();
  await expect(page.locator('[data-ll-wordset-game-status]')).toHaveText('7 words ready');

  const catalogDimensions = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-games-root]');
    const card = document.querySelector('[data-ll-wordset-game-card]');
    return {
      rootWidth: root ? Math.round(root.getBoundingClientRect().width) : 0,
      cardWidth: card ? Math.round(card.getBoundingClientRect().width) : 0
    };
  });
  expect(catalogDimensions.cardWidth).toBeGreaterThan(0);
  expect(catalogDimensions.rootWidth - catalogDimensions.cardWidth).toBeGreaterThan(80);

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.launch();
  });

  await expect(page.locator('[data-ll-wordset-game-stage]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-game-overlay]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-game-fire-keycap]')).toBeVisible();
  await page.waitForFunction(() => Array.isArray(window.__scrollCalls) && window.__scrollCalls.length > 0);
  const stageDimensions = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-games-root]');
    const stage = document.querySelector('[data-ll-wordset-game-stage]');
    return {
      rootWidth: root ? Math.round(root.getBoundingClientRect().width) : 0,
      stageWidth: stage ? Math.round(stage.getBoundingClientRect().width) : 0
    };
  });
  expect(stageDimensions.stageWidth).toBeGreaterThan(0);
  expect(stageDimensions.rootWidth - stageDimensions.stageWidth).toBeGreaterThan(80);
  await page.keyboard.down('Space');
  await expect(page.locator('[data-ll-wordset-game-control="fire"]')).toHaveClass(/is-active/);
  await page.keyboard.up('Space');
  await expect(page.locator('[data-ll-wordset-game-control="fire"]')).not.toHaveClass(/is-active/);
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run && run.targetWordId && run.activeCardCount === 4);
  });
  await page.waitForFunction(() => {
    const run = window.LLWordsetGames.__debug.getRunState();
    return !!(run
      && Array.isArray(run.cardSnapshot)
      && run.cardSnapshot.some((card) => card.promptId === run.promptId && Math.abs(card.width - card.height) > 8));
  });

  const initialRun = await page.evaluate(() => window.LLWordsetGames.__debug.getRunState());
  expect(initialRun.activeCardCount).toBe(4);
  expect(['question', 'isolation', 'introduction']).toContain(initialRun.promptRecordingType);
  expect(initialRun.cardSnapshot.some((card) => card.promptId === initialRun.promptId && Math.abs(card.width - card.height) > 8)).toBe(true);

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

  const disallowedPairs = [
    [101, 102],
    [103, 104],
    [105, 106]
  ];
  disallowedPairs.forEach(([left, right]) => {
    expect(initialRun.cardWordIds).not.toEqual(expect.arrayContaining([left, right]));
  });

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.resolvePrompt('wrong');
  });
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
  await expect(page.locator('[data-ll-wordset-game-lives]')).toHaveText('3');

  await page.evaluate(() => {
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

  progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(3);
  expect(progressEvents[2].entry.isCorrect).toBe(true);
  expect(progressEvents[2].entry.hadWrongBefore).toBe(true);
  expect(progressEvents[2].entry.payload.recording_type).toBe(initialRun.promptRecordingType);
  await expect(page.locator('[data-ll-wordset-game-coins]')).toHaveText('2');

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
  await expect(page.locator('[data-ll-wordset-game-coins]')).toHaveText('1');
  await expect(page.locator('[data-ll-wordset-game-lives]')).toHaveText('2');

  await page.evaluate(() => {
    window.LLWordsetGames.__debug.resolvePrompt('timeout');
  });
  await page.waitForTimeout(260);
  await page.evaluate(() => {
    window.LLWordsetGames.__debug.resolvePrompt('timeout');
  });
  await page.waitForTimeout(320);

  progressEvents = await page.evaluate(() => window.__queuedProgressEvents);
  expect(progressEvents).toHaveLength(9);
  expect(progressEvents.filter((entry) => entry.type === 'word_exposure')).toHaveLength(4);
  expect(progressEvents.filter((entry) => entry.type === 'word_outcome')).toHaveLength(5);

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

  await page.click('[data-ll-wordset-game-close]');
  await expect(page.locator('[data-ll-wordset-game-stage]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-games-catalog]')).toBeVisible();

  const scrollCallCount = await page.evaluate(() => window.__scrollCalls.length);
  expect(scrollCallCount).toBeGreaterThanOrEqual(2);

  const finalFlushCount = await page.evaluate(() => window.__flushCount);
  expect(finalFlushCount).toBeGreaterThan(flushCountAfterGameOver);

  const progressContext = await page.evaluate(() => window.__progressContext);
  expect(progressContext.wordsetId || progressContext.wordset_id).toBe(77);
});
