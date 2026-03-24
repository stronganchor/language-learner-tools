const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);
const wordsetCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

function buildProgressAnalytics() {
  return {
    scope: {
      wordset_id: 77,
      category_ids: [11],
      category_count: 1,
      mode: 'all'
    },
    summary: {
      total_words: 3,
      mastered_words: 0,
      studied_words: 2,
      new_words: 1,
      hard_words: 1,
      starred_words: 1
    },
    daily_activity: {
      days: [],
      max_events: 0,
      window_days: 14
    },
    gender_progress: {
      enabled: false,
      tracked_word_total: 0,
      not_started_words: 0,
      level_1_words: 0,
      level_2_words: 0,
      level_3_words: 0,
      categories: []
    },
    categories: [
      {
        id: 11,
        label: 'Cat A',
        word_count: 3,
        mastered_words: 0,
        studied_words: 2,
        new_words: 1,
        exposure_total: 3,
        exposure_by_mode: {
          learning: 2,
          practice: 1,
          listening: 0,
          gender: 0,
          'self-check': 0
        },
        last_mode: 'practice',
        last_seen_at: '2026-03-20 10:00:00',
        gender_progress: {
          tracked_word_total: 0,
          not_started_words: 0,
          level_1_words: 0,
          level_2_words: 0,
          level_3_words: 0,
          last_seen_at: ''
        }
      }
    ],
    words: [
      {
        id: 101,
        title: 'Text Only',
        translation: 'Only text',
        image: '',
        audio_url: 'https://example.com/audio/text-only-isolation.mp3',
        audio_recording_type: 'isolation',
        category_id: 11,
        category_label: 'Cat A',
        category_ids: [11],
        category_labels: ['Cat A'],
        status: 'studied',
        difficulty_score: 5,
        total_coverage: 24,
        incorrect: 10,
        last_seen_at: '2026-03-20 10:00:00',
        normalized_grammatical_gender: '',
        gender_marked: false,
        gender_progress_tracked: false,
        gender_eligible: false,
        gender_level: 0,
        gender_seen_total: 0,
        gender_last_seen_at: '',
        gender_progress: {}
      },
      {
        id: 102,
        title: 'With Image',
        translation: 'Has image',
        image: 'https://example.com/test-word.jpg',
        audio_url: 'https://example.com/audio/with-image-isolation.mp3',
        audio_recording_type: 'isolation',
        category_id: 11,
        category_label: 'Cat A',
        category_ids: [11],
        category_labels: ['Cat A'],
        status: 'studied',
        difficulty_score: 2,
        total_coverage: 5,
        incorrect: 0,
        last_seen_at: '2026-03-19 08:30:00',
        normalized_grammatical_gender: '',
        gender_marked: false,
        gender_progress_tracked: false,
        gender_eligible: false,
        gender_level: 0,
        gender_seen_total: 0,
        gender_last_seen_at: '',
        gender_progress: {}
      },
      {
        id: 103,
        title: 'Image Without Audio',
        translation: 'Image only',
        image: 'https://example.com/test-word-2.jpg',
        category_id: 11,
        category_label: 'Cat A',
        category_ids: [11],
        category_labels: ['Cat A'],
        status: 'new',
        difficulty_score: 1,
        total_coverage: 3,
        incorrect: 1,
        last_seen_at: '2026-03-19 08:00:00',
        normalized_grammatical_gender: '',
        gender_marked: false,
        gender_progress_tracked: false,
        gender_eligible: false,
        gender_level: 0,
        gender_seen_total: 0,
        gender_last_seen_at: '',
        gender_progress: {}
      }
    ],
    generated_at: '2026-03-20T10:00:00Z'
  };
}

function buildSkewedProgressAnalytics() {
  const seenValues = [0, 0, 0, 0, 1, 1, 1, 2, 2, 3, 4, 7, 12, 20, 35];
  const wrongValues = [0, 0, 0, 0, 0, 1, 1, 1, 2, 2, 3, 4, 6, 8, 12];
  const words = seenValues.map((seen, index) => {
    const wordNumber = index + 1;
    const status = index < 11 ? 'studied' : 'new';
    return {
      id: 200 + wordNumber,
      title: `Skewed ${wordNumber}`,
      translation: `Word ${wordNumber}`,
      image: '',
      category_id: 11,
      category_label: 'Cat A',
      category_ids: [11],
      category_labels: ['Cat A'],
      status,
      difficulty_score: status === 'studied' ? ((index % 5) + 1) : 0,
      total_coverage: seen,
      incorrect: wrongValues[index],
      last_seen_at: `2026-03-${String((wordNumber % 9) + 10).padStart(2, '0')} 08:00:00`,
      normalized_grammatical_gender: '',
      gender_marked: false,
      gender_progress_tracked: false,
      gender_eligible: false,
      gender_level: 0,
      gender_seen_total: 0,
      gender_last_seen_at: '',
      gender_progress: {}
    };
  });
  return {
    scope: {
      wordset_id: 77,
      category_ids: [11],
      category_count: 1,
      mode: 'all'
    },
    summary: {
      total_words: words.length,
      mastered_words: 0,
      studied_words: words.filter((row) => row.status === 'studied').length,
      new_words: words.filter((row) => row.status === 'new').length,
      hard_words: words.filter((row) => row.status === 'studied' && row.difficulty_score >= 4).length,
      starred_words: 1
    },
    daily_activity: {
      days: [],
      max_events: 0,
      window_days: 14
    },
    gender_progress: {
      enabled: false,
      tracked_word_total: 0,
      not_started_words: 0,
      level_1_words: 0,
      level_2_words: 0,
      level_3_words: 0,
      categories: []
    },
    categories: [
      {
        id: 11,
        label: 'Cat A',
        word_count: words.length,
        mastered_words: 0,
        studied_words: words.filter((row) => row.status === 'studied').length,
        new_words: words.filter((row) => row.status === 'new').length,
        exposure_total: words.length,
        exposure_by_mode: {
          learning: words.length,
          practice: 0,
          listening: 0,
          gender: 0,
          'self-check': 0
        },
        last_mode: 'learning',
        last_seen_at: '2026-03-20 10:00:00',
        gender_progress: {
          tracked_word_total: 0,
          not_started_words: 0,
          level_1_words: 0,
          level_2_words: 0,
          level_3_words: 0,
          last_seen_at: ''
        }
      }
    ],
    words,
    generated_at: '2026-03-20T10:00:00Z'
  };
}

function buildProgressPageConfig(overrides = {}) {
  const config = {
    view: 'progress',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: 'nonce-1',
    isLoggedIn: true,
    wordsetId: 77,
    wordsetSlug: 'test-wordset',
    wordsetName: 'Test Wordset',
    links: {
      base: '/wordsets/test-wordset/',
      progress: '/wordsets/test-wordset/progress/',
      hidden: '/wordsets/test-wordset/hidden-categories/',
      settings: '/wordsets/test-wordset/settings/'
    },
    progressIncludeHidden: false,
    categories: [
      {
        id: 11,
        slug: 'cat-a',
        name: 'Cat A',
        translation: 'Cat A',
        count: 3,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      }
    ],
    visibleCategoryIds: [11],
    hiddenCategoryIds: [],
    state: {
      wordset_id: 77,
      category_ids: [],
      starred_word_ids: [101],
      star_mode: 'normal',
      fast_transitions: false
    },
    goals: {
      enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
      ignored_category_ids: [],
      preferred_wordset_ids: [77],
      placement_known_category_ids: [],
      daily_new_word_target: 0,
      priority_focus: ''
    },
    analytics: buildProgressAnalytics(),
    summaryCountsDeferred: false,
    hardWordDifficultyThreshold: 4,
    i18n: {
      analyticsSelectionCount: '%d selected words',
      analyticsScopeAll: 'All categories (%d)',
      analyticsWord: 'Word',
      analyticsCategory: 'Category',
      analyticsActivity: 'Activity',
      analyticsWordProgress: 'Word Progress',
      analyticsMastered: 'Learned',
      analyticsStudied: 'In progress',
      analyticsNew: 'New',
      analyticsStarred: 'Starred',
      analyticsHard: 'Hard',
      analyticsLast: 'Last',
      analyticsNoRows: 'No data yet.',
      analyticsPlayAudio: 'Play audio',
      analyticsPlayAudioFor: 'Play audio for %s',
      analyticsFilterStatus: 'Status',
      analyticsFilterDifficulty: 'Difficulty Score',
      analyticsFilterSeen: 'Seen',
      analyticsFilterWrong: 'Wrong',
      analyticsGenderTitle: 'Gender',
      analyticsGenderNote: 'Only words with marked gender are counted.',
      analyticsGenderTrackedWords: '%d tracked words',
      analyticsGenderNotStarted: 'Not started',
      analyticsGenderLevel1: 'Level 1',
      analyticsGenderLevel2: 'Level 2',
      analyticsGenderLevel3: 'Level 3',
      analyticsGenderTracked: 'Tracked',
      analyticsGenderTableProgress: 'Gender progress',
      analyticsGenderTableGender: 'Gender',
      analyticsGenderTableLevel: 'Level',
      analyticsGenderToggleAria: 'Show gender progress in the tables',
      analyticsGenderToggleAriaActive: 'Show general progress in the tables',
      analyticsGenderFilterGender: 'Filter gender',
      analyticsGenderFilterLevel: 'Filter level',
      analyticsGenderLastPracticed: 'Last practiced'
    },
    gender: {
      enabled: false,
      options: [],
      visual_config: {},
      min_count: 2
    },
    modeUi: {}
  };
  return {
    ...config,
    ...overrides
  };
}

function buildProgressPageMarkup() {
  return `
    <div class="ll-wordset-page ll-wordset-page--progress" data-ll-wordset-page data-ll-wordset-view="progress" data-ll-wordset-id="77">
      <section class="ll-wordset-progress-view" data-ll-wordset-progress-root>
        <div class="ll-wordset-progress-scope" data-ll-wordset-progress-scope></div>
        <div class="ll-wordset-progress-status" data-ll-wordset-progress-status></div>
        <div class="ll-wordset-progress-summary" data-ll-wordset-progress-summary></div>
        <div class="ll-wordset-progress-graph" data-ll-wordset-progress-graph></div>
        <section
          class="ll-wordset-progress-gender"
          data-ll-wordset-progress-gender
          data-ll-wordset-progress-gender-toggle
          role="button"
          tabindex="0"
          aria-pressed="false"
          hidden
        >
          <div class="ll-wordset-progress-gender__head">
            <div class="ll-wordset-progress-gender__copy">
              <h2 class="ll-wordset-progress-gender__title">Gender</h2>
              <p class="ll-wordset-progress-gender__note">Only words with marked gender are counted.</p>
            </div>
          </div>
          <div class="ll-wordset-progress-gender__cards" data-ll-wordset-progress-gender-cards></div>
          <div class="ll-wordset-progress-gender__overview" data-ll-wordset-progress-gender-overview></div>
        </section>

        <div class="ll-wordset-progress-tabs" role="tablist" aria-label="Progress">
          <button type="button" class="ll-wordset-progress-tab active" data-ll-wordset-progress-tab="categories" aria-selected="true">Categories</button>
          <button type="button" class="ll-wordset-progress-tab" data-ll-wordset-progress-tab="words" aria-selected="false">Words</button>
        </div>

        <div class="ll-wordset-progress-panel" data-ll-wordset-progress-panel="categories">
          <div class="ll-wordset-progress-category-tools">
            <div class="ll-wordset-progress-search ll-wordset-progress-search--categories">
              <input class="ll-wordset-progress-search__input" type="search" data-ll-wordset-progress-category-search />
              <span class="ll-wordset-progress-search__loading" data-ll-wordset-progress-category-search-loading hidden aria-hidden="true"></span>
            </div>
          </div>
          <div class="ll-wordset-progress-table-wrap">
            <table class="ll-wordset-progress-table ll-wordset-progress-table--categories">
              <thead>
                <tr>
                  <th scope="col" data-ll-wordset-progress-category-sort-th="category" aria-sort="none">
                    <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="category">
                      <span data-ll-wordset-progress-category-header-label="category">Category</span>
                      <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                    </button>
                  </th>
                  <th scope="col" data-ll-wordset-progress-category-sort-th="progress" aria-sort="none">
                    <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="progress">
                      <span class="ll-wordset-progress-sort-label ll-wordset-progress-sort-label--progress" data-ll-wordset-progress-category-header-label="progress" data-mobile-label="Progress">Word Progress</span>
                      <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                    </button>
                  </th>
                  <th scope="col" data-ll-wordset-progress-category-sort-th="activity" aria-sort="none">
                    <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="activity">
                      <span data-ll-wordset-progress-category-header-label="activity">Activity</span>
                      <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                    </button>
                  </th>
                  <th scope="col" data-ll-wordset-progress-category-sort-th="last" aria-sort="none">
                    <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-category-sort="last">
                      <span data-ll-wordset-progress-category-header-label="last">Last</span>
                      <span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody data-ll-wordset-progress-categories-body></tbody>
            </table>
          </div>
        </div>

        <div class="ll-wordset-progress-panel" data-ll-wordset-progress-panel="words" hidden>
          <div class="ll-wordset-progress-search-tools">
            <div class="ll-wordset-progress-search">
              <input class="ll-wordset-progress-search__input" type="search" data-ll-wordset-progress-search />
              <span class="ll-wordset-progress-search__loading" data-ll-wordset-progress-search-loading hidden aria-hidden="true"></span>
            </div>
            <button type="button" class="ll-wordset-select-all ll-wordset-progress-select-all" data-ll-wordset-progress-select-all aria-pressed="false">Select all</button>
            <button type="button" class="ll-wordset-progress-clear-filters" data-ll-wordset-progress-clear-filters hidden>Clear filters</button>
          </div>

          <div class="ll-wordset-progress-mobile-legend" data-ll-wordset-progress-mobile-legend aria-label="Word table key">
            <span class="ll-wordset-progress-mobile-legend__title">Key</span>
            <ul class="ll-wordset-progress-mobile-legend__items" role="list">
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--mastered"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 64 64"><polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline></svg></span><span class="ll-wordset-progress-mobile-legend__text">Learned</span></li>
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--studied"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle><path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path></svg></span><span class="ll-wordset-progress-mobile-legend__text">In progress</span></li>
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--new"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 64 64"><line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line><line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line></svg></span><span class="ll-wordset-progress-mobile-legend__text">New</span></li>
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--hard"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 64 64"><path d="M32 8 L58 52 H6 Z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"></path><line x1="32" y1="23" x2="32" y2="37" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line><circle cx="32" cy="45" r="3.2" fill="currentColor"></circle></svg></span><span class="ll-wordset-progress-mobile-legend__text">Hard</span></li>
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--seen"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/></svg></span><span class="ll-wordset-progress-mobile-legend__text">Seen</span></li>
              <li class="ll-wordset-progress-mobile-legend__item ll-wordset-progress-mobile-legend__item--wrong"><span class="ll-wordset-progress-mobile-legend__icon" aria-hidden="true"><svg viewBox="0 0 64 64"><line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line><line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line></svg></span><span class="ll-wordset-progress-mobile-legend__text">Wrong</span></li>
            </ul>
          </div>

          <div class="ll-wordset-progress-table-wrap">
            <table class="ll-wordset-progress-table ll-wordset-progress-table--words">
              <thead>
                <tr>
                  <th scope="col">
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="star" aria-haspopup="true" aria-expanded="false" aria-label="Filter star status">
                        <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
                      </button>
                      <span class="screen-reader-text">Starred</span>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="star" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter star status</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="star"></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="word" data-mobile-label="Word" aria-sort="none">
                    <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="word"><span data-ll-wordset-progress-word-header-label="word">Word</span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="category" data-mobile-label="Category" aria-sort="none">
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="category" aria-haspopup="true" aria-expanded="false" aria-label="Filter category">
                        <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
                      </button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="category"><span data-ll-wordset-progress-word-header-label="category">Category</span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop ll-wordset-progress-filter-pop--category" data-ll-wordset-progress-filter-pop="category" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter category</legend><div class="ll-wordset-progress-filter-options ll-wordset-progress-category-filter-options" data-ll-wordset-progress-category-filter-options></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="status" data-mobile-label="Status" aria-sort="none">
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="status" aria-haspopup="true" aria-expanded="false" aria-label="Filter status">
                        <span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
                      </button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="status"><span data-ll-wordset-progress-word-header-label="status">Status</span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="status" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter status</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="status"></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="difficulty" data-mobile-label="Difficulty" aria-sort="none">
                    <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--difficulty" aria-hidden="true">
                      <svg viewBox="0 0 64 64" class="ll-wordset-progress-mobile-header-icon-svg"><path d="M32 8 L58 52 H6 Z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"></path><line x1="32" y1="23" x2="32" y2="37" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line><circle cx="32" cy="45" r="3.2" fill="currentColor"></circle></svg>
                    </span>
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="difficulty" aria-haspopup="true" aria-expanded="false" aria-label="Filter difficulty score"><span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span></button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="difficulty"><span class="screen-reader-text">Difficulty Score</span><span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="difficulty">Difficulty</span><span class="ll-wordset-progress-sort-difficulty-icon-wrap" aria-hidden="true"><svg viewBox="0 0 64 64" class="ll-wordset-progress-sort-difficulty-icon"><path d="M32 8 L58 52 H6 Z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"></path><line x1="32" y1="23" x2="32" y2="37" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line><circle cx="32" cy="45" r="3.2" fill="currentColor"></circle></svg></span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="difficulty" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter difficulty score</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="difficulty"></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="seen" data-mobile-label="Seen" aria-sort="none">
                    <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--seen" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="16" height="16" class="ll-wordset-progress-mobile-header-icon-svg"><path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/></svg>
                    </span>
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="seen" aria-haspopup="true" aria-expanded="false" aria-label="Filter seen count"><span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span></button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="seen"><span class="screen-reader-text">Seen</span><span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="seen">Seen</span><span class="ll-wordset-progress-sort-seen-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" class="ll-wordset-progress-sort-seen-icon"><path d="M12 5c5.8 0 9.8 4.6 11.3 6.8a1 1 0 0 1 0 1.1C21.8 15 17.8 19.5 12 19.5S2.2 15 0.7 12.9a1 1 0 0 1 0-1.1C2.2 9.6 6.2 5 12 5Zm0 2C7.5 7 4.2 10.4 2.8 12 4.2 13.6 7.5 17 12 17s7.8-3.4 9.2-5C19.8 10.4 16.5 7 12 7Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/></svg></span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="seen" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter seen count</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="seen"></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="wrong" data-mobile-label="Wrong" aria-sort="none">
                    <span class="ll-wordset-progress-mobile-header-icon ll-wordset-progress-mobile-header-icon--wrong" aria-hidden="true">
                      <svg viewBox="0 0 64 64" class="ll-wordset-progress-mobile-header-icon-svg"><line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line><line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line></svg>
                    </span>
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="wrong" aria-haspopup="true" aria-expanded="false" aria-label="Filter wrong count"><span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span></button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="wrong"><span class="screen-reader-text">Wrong</span><span class="ll-wordset-progress-sort-text-label ll-wordset-progress-sort-text-label--aux" data-ll-wordset-progress-word-header-label="wrong">Wrong</span><span class="ll-wordset-progress-sort-wrong-icon-wrap" aria-hidden="true"><svg viewBox="0 0 64 64" class="ll-wordset-progress-sort-wrong-icon"><line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line><line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line></svg></span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="wrong" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter wrong count</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="wrong"></div></fieldset></div>
                  </th>
                  <th scope="col" data-ll-wordset-progress-sort-th="last" data-mobile-label="Last" aria-sort="none">
                    <div class="ll-wordset-progress-th-controls">
                      <button type="button" class="ll-wordset-progress-filter-trigger" data-ll-wordset-progress-filter-trigger="last" aria-haspopup="true" aria-expanded="false" aria-label="Filter last seen"><span class="ll-wordset-progress-filter-trigger__icon" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M2 3h12L9.5 8v4.4l-3 1.6V8L2 3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg></span></button>
                      <button type="button" class="ll-wordset-progress-sort" data-ll-wordset-progress-sort="last"><span data-ll-wordset-progress-word-header-label="last">Last</span><span class="ll-wordset-progress-sort-indicator" aria-hidden="true"></span></button>
                    </div>
                    <div class="ll-wordset-progress-filter-pop" data-ll-wordset-progress-filter-pop="last" hidden><fieldset class="ll-wordset-progress-filter-fieldset"><legend class="screen-reader-text">Filter last seen</legend><div class="ll-wordset-progress-filter-options" data-ll-wordset-progress-column-filter-options="last"></div></fieldset></div>
                  </th>
                </tr>
              </thead>
              <tbody data-ll-wordset-progress-words-body></tbody>
            </table>
          </div>

          <div class="ll-wordset-selection-bar ll-wordset-progress-selection-bar" data-ll-wordset-progress-selection-bar hidden>
            <span class="ll-wordset-selection-bar__text" data-ll-wordset-progress-selection-count>0 selected words</span>
            <div class="ll-wordset-selection-bar__actions">
              <button type="button" class="ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-wordset-progress-selection-mode data-mode="practice">Practice</button>
            </div>
            <button type="button" class="ll-wordset-selection-bar__clear" data-ll-wordset-progress-selection-clear aria-label="Clear selection"><span class="ll-wordset-selection-bar__clear-icon" aria-hidden="true">x</span></button>
          </div>
        </div>
      </section>
    </div>
  `;
}

async function waitForLayoutFrame(page) {
  await page.evaluate(() => new Promise((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(resolve);
    });
  }));
}

async function addVisibleAdminBar(page, height) {
  await page.evaluate((barHeight) => {
    let adminBar = document.getElementById('wpadminbar');
    if (!adminBar) {
      adminBar = document.createElement('div');
      adminBar.id = 'wpadminbar';
      document.body.prepend(adminBar);
    }
    Object.assign(adminBar.style, {
      position: 'fixed',
      top: '0',
      left: '0',
      right: '0',
      height: `${barHeight}px`,
      display: 'block',
      visibility: 'visible',
      zIndex: '99999'
    });
    window.dispatchEvent(new Event('resize'));
  }, height);
  await waitForLayoutFrame(page);
}

async function mountProgressPage(page, viewport = { width: 344, height: 844 }, configOverrides = {}) {
  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push(String(error && error.message ? error.message : error));
  });
  await page.setViewportSize(viewport);
  await page.goto(process.env.LL_E2E_BASE_URL || 'https://starter-english-local.local/');
  await page.setContent(buildProgressPageMarkup());
  await page.addStyleTag({ content: wordsetCssSource });
  await page.addScriptTag({ content: jquerySource });
  const config = buildProgressPageConfig(configOverrides);
  await page.evaluate((cfg) => {
    window.llWordsetPageData = cfg;
    window.alert = function () {};
    window.Audio = function (url) {
      this.src = url;
      this.currentSrc = url;
      this.currentTime = 0;
      this.paused = true;
      this._handlers = {};
    };
    window.Audio.prototype.addEventListener = function (type, handler) {
      if (!this._handlers[type]) {
        this._handlers[type] = [];
      }
      this._handlers[type].push(handler);
    };
    window.Audio.prototype.play = function () {
      this.paused = false;
      (this._handlers.play || []).forEach((handler) => handler.call(this));
      return Promise.resolve();
    };
    window.Audio.prototype.pause = function () {
      if (this.paused) {
        return;
      }
      this.paused = true;
      (this._handlers.pause || []).forEach((handler) => handler.call(this));
    };
    const analytics = JSON.parse(JSON.stringify(cfg.analytics || {}));
    jQuery.post = function (_url, request) {
      const deferred = jQuery.Deferred();
      const action = request && request.action ? String(request.action) : '';
      if (action === 'll_user_study_analytics') {
        deferred.resolve({
          success: true,
          data: {
            analytics
          }
        });
        return deferred.promise();
      }
      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: null,
            recommendation_queue: []
          }
        });
        return deferred.promise();
      }
      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, config);
  await page.addScriptTag({ content: wordsetScriptSource });
  expect(pageErrors).toEqual([]);
  await page.locator('[data-ll-wordset-progress-tab="words"]').click();
  const expectedWordCount = Array.isArray(config.analytics && config.analytics.words)
    ? config.analytics.words.length
    : 0;
  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(expectedWordCount);
}

test('mobile progress words table keeps the layout stable and renders audio controls', async ({ page }) => {
  await mountProgressPage(page);

  await expect(page.locator('[data-ll-wordset-progress-mobile-legend]')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-progress-mobile-legend]')).not.toContainText('Starred');
  await expect(page.locator('[data-ll-wordset-progress-mobile-legend]')).toContainText('Seen');
  await expect(page.locator('[data-ll-wordset-progress-mobile-legend]')).toContainText('Wrong');

  const firstWordCell = page.locator('[data-word-id="101"] td').nth(1);
  await expect(firstWordCell.locator('.ll-wordset-progress-word-cell--text-only')).toHaveCount(1);
  await expect(firstWordCell.locator('.ll-wordset-progress-word-thumb')).toHaveCount(0);

  const imageAudioWordCell = page.locator('[data-word-id="102"] td').nth(1);
  await expect(imageAudioWordCell.locator('.ll-wordset-progress-word-thumb')).toHaveCount(1);
  await expect(imageAudioWordCell.locator('[data-ll-wordset-progress-word-audio]')).toHaveCount(1);

  const noAudioWordCell = page.locator('[data-word-id="103"] td').nth(1);
  await expect(noAudioWordCell.locator('.ll-wordset-progress-word-thumb')).toHaveCount(1);
  await expect(noAudioWordCell.locator('[data-ll-wordset-progress-word-audio]')).toHaveCount(0);

  const firstAudioButton = firstWordCell.locator('[data-ll-wordset-progress-word-audio]');
  await expect(firstAudioButton).toHaveCount(1);
  await expect(firstAudioButton).toHaveAttribute('data-audio-url', /text-only-isolation\.mp3$/);

  await firstAudioButton.click();
  await expect(firstAudioButton).toHaveClass(/is-playing/);
  await firstAudioButton.click();
  await expect(firstAudioButton).not.toHaveClass(/is-playing/);

  await page.locator('[data-ll-wordset-progress-filter-trigger="category"]').click();
  const categoryFilterPop = page.locator('[data-ll-wordset-progress-filter-pop="category"]');
  await expect(categoryFilterPop).toBeVisible();
  await expect(categoryFilterPop).toContainText('Cat A (3)');

  const categoryFilterMetrics = await page.evaluate(() => {
    const pop = document.querySelector('[data-ll-wordset-progress-filter-pop="category"]');
    const header = pop ? pop.closest('th') : null;
    const nextHeader = header ? header.nextElementSibling : null;
    return {
      isFloating: !!(pop && pop.classList.contains('ll-wordset-progress-filter-pop--floating')),
      headerZ: header ? (parseInt(window.getComputedStyle(header).zIndex, 10) || 0) : 0,
      nextHeaderZ: nextHeader ? (parseInt(window.getComputedStyle(nextHeader).zIndex, 10) || 0) : 0,
      popZ: pop ? (parseInt(window.getComputedStyle(pop).zIndex, 10) || 0) : 0
    };
  });

  expect(categoryFilterMetrics.isFloating).toBe(true);
  expect(categoryFilterMetrics.headerZ).toBeGreaterThan(categoryFilterMetrics.nextHeaderZ);
  expect(categoryFilterMetrics.popZ).toBeGreaterThan(1000);

  await page.locator('[data-ll-wordset-progress-filter-trigger="status"]').click();
  const statusFilterPop = page.locator('[data-ll-wordset-progress-filter-pop="status"]');
  await expect(statusFilterPop).toBeVisible();
  await expect(statusFilterPop).toContainText('In progress (2)');
  await expect(statusFilterPop).toContainText('New (1)');

  const metrics = await page.evaluate(() => {
    const legend = document.querySelector('[data-ll-wordset-progress-mobile-legend]');
    const legendTitle = legend ? legend.querySelector('.ll-wordset-progress-mobile-legend__title') : null;
    const legendItems = legend ? legend.querySelector('.ll-wordset-progress-mobile-legend__items') : null;
    const difficultyControls = document.querySelector('th[data-ll-wordset-progress-sort-th="difficulty"] .ll-wordset-progress-th-controls');
    const seenControls = document.querySelector('th[data-ll-wordset-progress-sort-th="seen"] .ll-wordset-progress-th-controls');
    const wrongControls = document.querySelector('th[data-ll-wordset-progress-sort-th="wrong"] .ll-wordset-progress-th-controls');
    const row = document.querySelector('tr[data-word-id="101"]');
    const imageAudioRow = document.querySelector('tr[data-word-id="102"]');
    if (!row || !imageAudioRow || !legendTitle || !legendItems || !difficultyControls || !seenControls || !wrongControls) {
      return null;
    }

    const starCell = row.children[0];
    const wordCell = row.children[1];
    const button = row.querySelector('[data-ll-wordset-progress-word-star]');
    const audioButton = row.querySelector('[data-ll-wordset-progress-word-audio]');
    const wordBody = row.querySelector('.ll-wordset-progress-word-body');
    const wordMain = row.querySelector('.ll-wordset-progress-word-main');
    if (!starCell || !wordCell || !button) {
      return null;
    }

    const legendTitleRect = legendTitle.getBoundingClientRect();
    const legendItemsRect = legendItems.getBoundingClientRect();
    const starCellRect = starCell.getBoundingClientRect();
    const wordCellRect = wordCell.getBoundingClientRect();
    const buttonRect = button.getBoundingClientRect();
    const audioButtonRect = audioButton ? audioButton.getBoundingClientRect() : null;
    const wordMainRect = wordMain ? wordMain.getBoundingClientRect() : null;
    const imageThumb = imageAudioRow.querySelector('.ll-wordset-progress-word-thumb');
    const imageAudioButton = imageAudioRow.querySelector('[data-ll-wordset-progress-word-audio]');
    const imageThumbRect = imageThumb ? imageThumb.getBoundingClientRect() : null;
    const imageAudioButtonRect = imageAudioButton ? imageAudioButton.getBoundingClientRect() : null;
    const headerCell = document.querySelector('th[data-ll-wordset-progress-sort-th="word"]');
    const wordsWrap = document.querySelector('[data-ll-wordset-progress-panel="words"] .ll-wordset-progress-table-wrap');
    const wordsTable = wordsWrap ? wordsWrap.querySelector('.ll-wordset-progress-table--words') : null;

    return {
      difficultyControlsDirection: window.getComputedStyle(difficultyControls).flexDirection,
      seenControlsDirection: window.getComputedStyle(seenControls).flexDirection,
      wrongControlsDirection: window.getComputedStyle(wrongControls).flexDirection,
      wordBodyDirection: wordBody ? window.getComputedStyle(wordBody).flexDirection : '',
      headerPosition: headerCell ? window.getComputedStyle(headerCell).position : '',
      headerTop: headerCell ? window.getComputedStyle(headerCell).top : '',
      legendTitleBottom: legendTitleRect.bottom,
      legendItemsTop: legendItemsRect.top,
      wrapWidth: wordsWrap ? wordsWrap.getBoundingClientRect().width : 0,
      tableWidth: wordsTable ? wordsTable.getBoundingClientRect().width : 0,
      starCellLeft: starCellRect.left,
      starCellRight: starCellRect.right,
      wordCellLeft: wordCellRect.left,
      buttonLeft: buttonRect.left,
      buttonRight: buttonRect.right,
      audioGap: audioButtonRect && wordMainRect ? audioButtonRect.top - wordMainRect.bottom : null,
      imageAudioBelowThumb: imageAudioButtonRect && imageThumbRect ? imageAudioButtonRect.top - imageThumbRect.bottom : null
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.difficultyControlsDirection).toBe('column');
  expect(metrics.seenControlsDirection).toBe('column');
  expect(metrics.wrongControlsDirection).toBe('column');
  expect(metrics.wordBodyDirection).toBe('column');
  expect(metrics.headerPosition).toBe('sticky');
  expect(metrics.headerTop).toBe('0px');
  expect(metrics.legendItemsTop).toBeGreaterThanOrEqual(metrics.legendTitleBottom + 6);
  expect(Math.abs(metrics.tableWidth - metrics.wrapWidth)).toBeLessThanOrEqual(2);
  expect(metrics.buttonLeft).toBeGreaterThanOrEqual(metrics.starCellLeft - 0.5);
  expect(metrics.buttonRight).toBeLessThanOrEqual(metrics.starCellRight + 0.5);
  expect(metrics.buttonRight).toBeLessThanOrEqual(metrics.wordCellLeft + 0.5);
  expect(metrics.audioGap).not.toBeNull();
  expect(metrics.audioGap).toBeLessThanOrEqual(4);
  expect(metrics.imageAudioBelowThumb).not.toBeNull();
  expect(metrics.imageAudioBelowThumb).toBeGreaterThanOrEqual(-0.5);

  await page.setViewportSize({ width: 430, height: 844 });
  const resizedMetrics = await page.evaluate(() => {
    const wordsWrap = document.querySelector('[data-ll-wordset-progress-panel="words"] .ll-wordset-progress-table-wrap');
    const wordsTable = wordsWrap ? wordsWrap.querySelector('.ll-wordset-progress-table--words') : null;
    return {
      wrapWidth: wordsWrap ? wordsWrap.getBoundingClientRect().width : 0,
      tableWidth: wordsTable ? wordsTable.getBoundingClientRect().width : 0
    };
  });

  expect(resizedMetrics.wrapWidth).toBeGreaterThan(metrics.wrapWidth);
  expect(Math.abs(resizedMetrics.tableWidth - resizedMetrics.wrapWidth)).toBeLessThanOrEqual(2);
});

test('progress view toggles the main tables into gender progress mode', async ({ page }) => {
  const masculineSymbol = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="15" r="5" fill="none" stroke="currentColor" stroke-width="2"></circle><path d="M13 11L21 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path><path d="M16 3h5v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
  const feminineSymbol = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="9" r="5" fill="none" stroke="currentColor" stroke-width="2"></circle><path d="M12 14v7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path><path d="M9 18h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
  const analytics = buildProgressAnalytics();
  analytics.gender_progress = {
    enabled: true,
    tracked_word_total: 3,
    not_started_words: 1,
    level_1_words: 1,
    level_2_words: 1,
    level_3_words: 0,
    categories: [
      {
        id: 11,
        label: 'Cat A',
        tracked_word_total: 3,
        not_started_words: 1,
        level_1_words: 1,
        level_2_words: 1,
        level_3_words: 0,
        last_gender_seen_at: '2026-03-20 10:00:00'
      }
    ]
  };
  analytics.categories[0].gender_progress = {
    tracked_word_total: 3,
    not_started_words: 1,
    level_1_words: 1,
    level_2_words: 1,
    level_3_words: 0,
    last_seen_at: '2026-03-20 10:00:00'
  };
  analytics.words = [
    {
      ...analytics.words[0],
      normalized_grammatical_gender: 'Masculine',
      gender_marked: true,
      gender_progress_tracked: true,
      gender_eligible: true,
      gender_level: 1,
      gender_seen_total: 3,
      gender_last_seen_at: '2026-03-19 10:00:00',
      gender_progress: {
        level: 1,
        seen_total: 3,
        last_seen_at: '2026-03-19 10:00:00'
      }
    },
    {
      ...analytics.words[1],
      normalized_grammatical_gender: 'Feminine',
      gender_marked: true,
      gender_progress_tracked: true,
      gender_eligible: true,
      gender_level: 2,
      gender_seen_total: 6,
      gender_last_seen_at: '2026-03-20 09:00:00',
      gender_progress: {
        level: 2,
        seen_total: 6,
        last_seen_at: '2026-03-20 09:00:00'
      }
    },
    {
      ...analytics.words[2],
      normalized_grammatical_gender: 'Masculine',
      gender_marked: true,
      gender_progress_tracked: true,
      gender_eligible: true,
      gender_level: 0,
      gender_seen_total: 0,
      gender_last_seen_at: '',
      gender_progress: {}
    }
  ];

  await mountProgressPage(page, { width: 390, height: 844 }, {
    analytics,
    gender: {
      enabled: true,
      options: ['masculine', 'feminine'],
      visual_config: {
        colors: {
          masculine: '#1D4D99',
          feminine: '#C2185B',
          other: '#6B7280'
        },
        symbols: {
          masculine: {
            type: 'svg',
            value: masculineSymbol
          },
          feminine: {
            type: 'svg',
            value: feminineSymbol
          }
        },
        options: [
          {
            value: 'Masculine',
            normalized: 'masculine',
            label: 'Masculine',
            role: 'masculine',
            color: '#1D4D99',
            style: '--ll-gender-accent:#1D4D99;--ll-gender-bg:rgba(29,77,153,0.14);--ll-gender-border:rgba(29,77,153,0.38);',
            symbol: {
              type: 'svg',
              value: masculineSymbol
            }
          },
          {
            value: 'Feminine',
            normalized: 'feminine',
            label: 'Feminine',
            role: 'feminine',
            color: '#C2185B',
            style: '--ll-gender-accent:#C2185B;--ll-gender-bg:rgba(194,24,91,0.14);--ll-gender-border:rgba(194,24,91,0.38);',
            symbol: {
              type: 'svg',
              value: feminineSymbol
            }
          }
        ]
      },
      min_count: 2
    }
  });

  const section = page.locator('[data-ll-wordset-progress-gender]');
  await expect(section).toBeVisible();
  await expect(section).toContainText('Gender');
  await expect(section).toContainText('Only words with marked gender are counted.');
  await expect(section).toContainText('3 tracked words');
  await expect(section.locator('.ll-wordset-progress-gender-card')).toHaveCount(4);
  await expect(page.locator('[data-ll-wordset-progress-gender-categories]')).toHaveCount(0);

  await section.click();
  await expect(section).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-ll-wordset-progress-root]')).toHaveClass(/is-gender-view/);

  await page.locator('[data-ll-wordset-progress-tab="categories"]').click();
  await expect(page.locator('[data-ll-wordset-progress-category-header-label="progress"]')).toHaveText('Gender progress');
  await expect(page.locator('[data-ll-wordset-progress-category-header-label="activity"]')).toHaveText('Tracked');
  await expect(page.locator('[data-ll-wordset-progress-categories-body] tr')).toHaveCount(1);
  await expect(page.locator('[data-ll-wordset-progress-categories-body] tr').first().locator('td').nth(2)).toHaveText('3');

  await page.locator('[data-ll-wordset-progress-tab="words"]').click();
  await expect(page.locator('[data-ll-wordset-progress-mobile-legend]')).toBeHidden();
  await expect(page.locator('[data-ll-wordset-progress-word-header-label="status"]')).toHaveText('Gender');
  await expect(page.locator('[data-ll-wordset-progress-word-header-label="difficulty"]')).toHaveText('Level');
  await expect(page.locator('[data-ll-wordset-progress-word-header-label="seen"]')).toHaveText('Seen');
  await expect(page.locator('[data-ll-wordset-progress-words-body] tr')).toHaveCount(3);
  await expect(page.locator('tr[data-word-id="101"] .ll-wordset-progress-word-gender-pill .ll-gender-symbol--svg svg')).toHaveCount(1);
  await expect(page.locator('tr[data-word-id="102"] .ll-wordset-progress-word-gender-pill .ll-gender-symbol--svg svg')).toHaveCount(1);
  await expect(page.locator('tr[data-word-id="101"] .ll-wordset-progress-word-gender-pill__label')).toBeHidden();
  await expect(page.locator('tr[data-word-id="101"]')).toContainText('Level 1');
  await expect(page.locator('tr[data-word-id="102"]')).toContainText('Level 2');
  await expect(page.locator('tr[data-word-id="103"]')).toContainText('Not started');

  await page.setViewportSize({ width: 980, height: 844 });
  await waitForLayoutFrame(page);
  await expect(page.locator('tr[data-word-id="101"] .ll-wordset-progress-word-gender-pill__label')).toBeVisible();
  await expect(page.locator('tr[data-word-id="101"] .ll-wordset-progress-word-gender-pill')).toContainText('Masculine');
  await expect(page.locator('tr[data-word-id="102"] .ll-wordset-progress-word-gender-pill')).toContainText('Feminine');
});

test('desktop progress words table sticky header respects the visible admin bar while scrolling', async ({ page }) => {
  await mountProgressPage(page, { width: 1280, height: 900 });
  await addVisibleAdminBar(page, 32);

  await page.evaluate(() => {
    const root = document.querySelector('[data-ll-wordset-page]');
    if (!root || !root.parentNode) {
      return;
    }
    let topSpacer = document.querySelector('[data-test-progress-sticky-spacer="top"]');
    if (!topSpacer) {
      topSpacer = document.createElement('div');
      topSpacer.setAttribute('data-test-progress-sticky-spacer', 'top');
      topSpacer.style.height = '720px';
      root.parentNode.insertBefore(topSpacer, root);
    }
    let bottomSpacer = document.querySelector('[data-test-progress-sticky-spacer="bottom"]');
    if (!bottomSpacer) {
      bottomSpacer = document.createElement('div');
      bottomSpacer.setAttribute('data-test-progress-sticky-spacer', 'bottom');
      bottomSpacer.style.height = '960px';
      if (root.nextSibling) {
        root.parentNode.insertBefore(bottomSpacer, root.nextSibling);
      } else {
        root.parentNode.appendChild(bottomSpacer);
      }
    }
    window.scrollTo(0, 0);
    window.dispatchEvent(new Event('scroll'));
  });
  await waitForLayoutFrame(page);

  const beforeScroll = await page.evaluate(() => {
    const headerCell = document.querySelector('th[data-ll-wordset-progress-sort-th="word"]');
    if (!headerCell) {
      return null;
    }
    const rect = headerCell.getBoundingClientRect();
    return {
      position: window.getComputedStyle(headerCell).position,
      topStyle: window.getComputedStyle(headerCell).top,
      top: rect.top
    };
  });

  expect(beforeScroll).not.toBeNull();
  expect(beforeScroll.position).toBe('sticky');
  expect(beforeScroll.topStyle).toBe('32px');
  expect(beforeScroll.top).toBeGreaterThan(200);

  await page.evaluate(() => {
    const headerCell = document.querySelector('th[data-ll-wordset-progress-sort-th="word"]');
    if (!headerCell) {
      return;
    }
    const rect = headerCell.getBoundingClientRect();
    const targetScrollY = Math.max(0, window.scrollY + rect.top - 32 + 24);
    window.scrollTo(0, targetScrollY);
    window.dispatchEvent(new Event('scroll'));
  });
  await waitForLayoutFrame(page);

  const afterScroll = await page.evaluate(() => {
    const headerCell = document.querySelector('th[data-ll-wordset-progress-sort-th="word"]');
    if (!headerCell) {
      return null;
    }
    const rect = headerCell.getBoundingClientRect();
    return {
      top: rect.top
    };
  });

  expect(afterScroll).not.toBeNull();
  expect(afterScroll.top).toBeGreaterThanOrEqual(31);
  expect(afterScroll.top).toBeLessThanOrEqual(34);
});

test('progress filter trigger stays neutral after closing under theme button focus styles', async ({ page }) => {
  await mountProgressPage(page, { width: 1280, height: 900 });
  await page.addStyleTag({
    content: `
      .ll-wordset-page button:hover,
      .ll-wordset-page button:focus,
      .ll-wordset-page button:active {
        background: #0b67c2 !important;
        background-color: #0b67c2 !important;
        color: #ffffff !important;
        box-shadow: inset 0 0 0 999px #0b67c2 !important;
      }
    `
  });

  const trigger = page.locator('[data-ll-wordset-progress-filter-trigger="seen"]');
  const filterPop = page.locator('[data-ll-wordset-progress-filter-pop="seen"]');

  await trigger.click();
  await expect(filterPop).toBeVisible();
  await trigger.click();
  await expect(filterPop).toBeHidden();

  const styles = await trigger.evaluate((element) => {
    const computed = window.getComputedStyle(element);
    return {
      expanded: element.getAttribute('aria-expanded'),
      backgroundColor: computed.backgroundColor,
      color: computed.color
    };
  });

  expect(styles.expanded).toBe('false');
  expect(styles.backgroundColor).toBe('rgb(255, 255, 255)');
  expect(styles.color).not.toBe('rgb(255, 255, 255)');
});

test('progress word filters split skewed numeric ranges and show option counts', async ({ page }) => {
  const analytics = buildSkewedProgressAnalytics();
  await mountProgressPage(page, { width: 390, height: 844 }, {
    analytics,
    categories: [
      {
        id: 11,
        slug: 'cat-a',
        name: 'Cat A',
        translation: 'Cat A',
        count: analytics.words.length,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      }
    ]
  });

  await page.locator('[data-ll-wordset-progress-filter-trigger="seen"]').click();
  const seenFilterPop = page.locator('[data-ll-wordset-progress-filter-pop="seen"]');
  await expect(seenFilterPop).toBeVisible();

  const seenLabels = await seenFilterPop.locator('.ll-wordset-progress-filter-option__label').allTextContents();
  expect(seenLabels.length).toBeGreaterThanOrEqual(4);
  seenLabels.forEach((label) => {
    expect(label).toMatch(/\(\d+\)$/);
  });

  const lowRangeCount = seenLabels.filter((label) => {
    const rangeText = label.replace(/\s+\(\d+\)$/, '');
    const match = rangeText.match(/^(\d+)(?:-(\d+))?$/);
    if (!match) {
      return false;
    }
    const max = parseInt(match[2] || match[1], 10);
    return max <= 5;
  }).length;
  expect(lowRangeCount).toBeGreaterThanOrEqual(2);
  expect(seenLabels.some((label) => /^0-5\s+\(\d+\)$/.test(label))).toBe(false);

  const totalCount = seenLabels.reduce((sum, label) => {
    const match = label.match(/\((\d+)\)$/);
    return sum + (match ? parseInt(match[1], 10) : 0);
  }, 0);
  expect(totalCount).toBe(analytics.words.length);
});
