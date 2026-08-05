const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordsetScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

function buildWordsetMarkup() {
  return `
    <div class="ll-wordset-page" data-ll-wordset-page data-ll-wordset-view="main" data-ll-wordset-id="77">
      <div class="ll-wordset-grid">
        <button type="button" data-ll-wordset-start-mode data-mode="practice">Practice</button>
        <button type="button" data-ll-wordset-start-mode data-mode="listening">Listen</button>
        <button type="button" data-ll-wordset-start-mode data-mode="gender">Gender</button>
        <button type="button" data-ll-wordset-select-all>Select all</button>
        <label><input type="checkbox" data-ll-wordset-select value="11" />Cat A</label>
        <label><input type="checkbox" data-ll-wordset-select value="22" />Cat B</label>
        <label><input type="checkbox" data-ll-wordset-select value="33" />Cat C</label>
      </div>

      <div data-ll-wordset-next-shell>
        <button type="button" data-ll-wordset-next>
          <span data-ll-wordset-next-icon></span>
          <span data-ll-wordset-next-preview></span>
          <span data-ll-wordset-next-text></span>
        </button>
        <span>
          <span data-ll-wordset-next-count hidden></span>
          <button type="button" data-ll-wordset-next-remove hidden>Remove</button>
        </span>
      </div>

      <div data-ll-wordset-selection-bar hidden>
        <span data-ll-wordset-selection-text>Select categories to study together</span>
        <label class="ll-wordset-selection-bar__priority-toggle" hidden>
          <input type="checkbox" data-ll-wordset-selection-priority-only />
          <span data-ll-wordset-selection-priority-icon></span>
          <span data-ll-wordset-selection-priority-label>Priority words only</span>
        </label>
        <label class="ll-wordset-selection-bar__starred-toggle">
          <input type="checkbox" data-ll-wordset-selection-starred-only />
          <span data-ll-wordset-selection-starred-icon>☆</span>
          <span data-ll-wordset-selection-starred-label>Starred only</span>
        </label>
        <label class="ll-wordset-selection-bar__hard-toggle" hidden>
          <input type="checkbox" data-ll-wordset-selection-hard-only />
          <span data-ll-wordset-selection-hard-icon></span>
          <span data-ll-wordset-selection-hard-label>Hard words only</span>
        </label>
        <button type="button" data-ll-wordset-selection-mode data-mode="practice">Selection Practice</button>
        <button type="button" data-ll-wordset-selection-mode data-mode="learning">Selection Learn</button>
        <button type="button" data-ll-wordset-selection-mode data-mode="listening">Selection Listen</button>
        <button type="button" data-ll-wordset-selection-clear>Clear</button>
      </div>
    </div>

    <p id="ll-study-results-suggestion" style="display:none;"></p>
    <div id="ll-study-results-actions" style="display:none;">
      <button id="ll-study-results-same-chunk" type="button" style="display:none;">Repeat</button>
      <button id="ll-study-results-different-chunk" type="button" style="display:none;">New words</button>
      <button id="ll-study-results-next-chunk" type="button" style="display:none;">Recommended</button>
    </div>
    <div id="ll-gender-results-actions" style="display:none;"></div>
    <button id="restart-quiz" type="button" style="display:none;">Restart</button>
    <div id="quiz-mode-buttons" style="display:none;"></div>
    <div id="quiz-results" style="display:none;">Completed chunk results</div>

    <div id="ll-tools-flashcard-popup" style="display:none;">
      <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
        <div id="ll-tools-loading-animation" class="ll-tools-loading-animation" style="display:none;" aria-hidden="true"></div>
        <div id="ll-tools-loading-status" role="status" aria-live="polite" hidden>Loading quiz...</div>
        <div id="ll-tools-flashcard-content"></div>
      </div>
    </div>
  `;
}

function buildCategoryWords() {
  return {
    11: [
      { id: 1101, title: 'A1', translation: 'A1', label: 'A1', audio: '', image: '', audio_files: [] },
      { id: 1102, title: 'A2', translation: 'A2', label: 'A2', audio: '', image: '', audio_files: [] }
    ],
    22: [
      { id: 2201, title: 'B1', translation: 'B1', label: 'B1', audio: '', image: '', audio_files: [] },
      { id: 2202, title: 'B2', translation: 'B2', label: 'B2', audio: '', image: '', audio_files: [] }
    ],
    33: [
      { id: 3301, title: 'C1', translation: 'C1', label: 'C1', audio: '', image: '', audio_files: [] },
      { id: 3302, title: 'C2', translation: 'C2', label: 'C2', audio: '', image: '', audio_files: [] }
    ]
  };
}

function buildCategoryWordRows(categoryId, count, prefix = 'W') {
  const cid = Number(categoryId) || 0;
  const total = Math.max(0, Number(count) || 0);
  const base = cid * 100;
  const rows = [];

  for (let idx = 0; idx < total; idx += 1) {
    const seq = idx + 1;
    const id = base + seq;
    const label = `${prefix}${seq}`;
    rows.push({
      id,
      title: label,
      translation: label,
      label,
      audio: '',
      image: '',
      audio_files: []
    });
  }

  return rows;
}

function buildAnalyticsWordsWithHardCount(hardCount) {
  const count = Math.max(0, Number(hardCount) || 0);
  const rows = [];
  const wordIds = [1101, 1102, 2201, 2202, 3301, 3302];

  wordIds.forEach((id, index) => {
    const categoryId = Number(String(id).slice(0, 2));
    rows.push({
      id,
      title: `W${id}`,
      translation: `W${id}`,
      category_id: categoryId,
      category_ids: [categoryId],
      status: 'studied',
      difficulty_score: index < count ? 4 : 0,
      total_coverage: 3,
      incorrect: 0,
      lapse_count: 0,
      last_seen_at: '',
      is_starred: false
    });
  });

  return rows;
}

function buildBoundedChunkFixture() {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 10, 'A').map((row) => Object.assign({}, row, {
      category_id: 11,
      category_ids: [11],
      status: 'studied'
    })),
    22: buildCategoryWordRows(22, 10, 'B').map((row) => Object.assign({}, row, {
      category_id: 22,
      category_ids: [22],
      status: 'studied'
    })),
    33: buildCategoryWordRows(33, 10, 'C').map((row) => Object.assign({}, row, {
      category_id: 33,
      category_ids: [33],
      status: 'studied'
    }))
  };
  const firstChunkWordIds = wordsByCategory[11].map((row) => row.id)
    .concat(wordsByCategory[22].slice(0, 5).map((row) => row.id));
  const secondChunkWordIds = wordsByCategory[22].slice(5).map((row) => row.id)
    .concat(wordsByCategory[33].map((row) => row.id));
  const allPlannedWordIds = firstChunkWordIds.concat(secondChunkWordIds);

  return {
    wordsByCategory,
    firstChunkWordIds,
    secondChunkWordIds,
    allPlannedWordIds,
    selectionLaunchPlan: {
      category_ids: [11, 22],
      word_ids: firstChunkWordIds,
      chunks: [
        { category_ids: [11, 22], word_ids: firstChunkWordIds },
        { category_ids: [22, 33], word_ids: secondChunkWordIds }
      ],
      criteria: 'studied',
      mode: 'practice',
      matched_count: allPlannedWordIds.length,
      planned_count: allPlannedWordIds.length,
      chunk_count: 2,
      truncated: false
    },
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: allPlannedWordIds.length,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  };
}

function buildDeferredCategorySelectionFixture() {
  const categoryIds = [11, 22, 33, 44, 55, 66, 77, 88, 99, 110, 121, 132];
  const categories = categoryIds.map((categoryId, index) => ({
    id: categoryId,
    slug: `cat-${String.fromCharCode(97 + index)}`,
    name: `Cat ${String.fromCharCode(65 + index)}`,
    translation: `Cat ${String.fromCharCode(65 + index)}`,
    count: index < 6 ? 29 : 28,
    url: '#',
    mode: 'image',
    prompt_type: 'audio',
    option_type: 'image',
    learning_supported: true,
    gender_supported: false,
    aspect_bucket: 'ratio:1_1',
    hidden: false,
    preview: []
  }));
  const wordsByCategory = {};
  const ownerByWordId = {};
  categories.forEach((category, index) => {
    wordsByCategory[category.id] = buildCategoryWordRows(category.id, category.count, `L${index + 1}-`)
      .map((row) => Object.assign({}, row, {
        category_id: category.id,
        category_ids: [category.id],
        status: 'studied'
      }));
    wordsByCategory[category.id].forEach((row) => {
      ownerByWordId[row.id] = category.id;
    });
  });

  const categoryGroups = [
    [11, 44, 77],
    [22, 55, 88],
    [33, 66, 99],
    [110, 121, 132]
  ];
  const chunksByGroup = categoryGroups.map((group) => {
    const interleavedWordIds = [];
    const maximumCategorySize = Math.max(...group.map((categoryId) => wordsByCategory[categoryId].length));
    for (let wordIndex = 0; wordIndex < maximumCategorySize; wordIndex += 1) {
      group.forEach((categoryId) => {
        const row = wordsByCategory[categoryId][wordIndex];
        if (row) {
          interleavedWordIds.push(row.id);
        }
      });
    }
    const groupChunks = [];
    for (let offset = 0; offset < interleavedWordIds.length; offset += 15) {
      const wordIds = interleavedWordIds.slice(offset, offset + 15);
      groupChunks.push({
        category_ids: Array.from(new Set(wordIds.map((wordId) => ownerByWordId[wordId]))),
        word_ids: wordIds
      });
    }
    return groupChunks;
  });
  const chunks = [];
  const maximumGroupChunks = Math.max(...chunksByGroup.map((groupChunks) => groupChunks.length));
  for (let chunkIndex = 0; chunkIndex < maximumGroupChunks; chunkIndex += 1) {
    chunksByGroup.forEach((groupChunks) => {
      if (groupChunks[chunkIndex]) {
        chunks.push(groupChunks[chunkIndex]);
      }
    });
  }
  const allPlannedWordIds = chunks.flatMap((chunk) => chunk.word_ids);

  return {
    categoryIds,
    categories,
    wordsByCategory,
    ownerByWordId,
    allPlannedWordIds,
    selectionLaunchPlan: {
      category_ids: chunks[0].category_ids,
      word_ids: chunks[0].word_ids,
      chunks,
      criteria: 'studied',
      mode: 'practice',
      matched_count: allPlannedWordIds.length,
      planned_count: allPlannedWordIds.length,
      chunk_count: chunks.length,
      truncated: false
    }
  };
}

function buildPageConfig({ isLoggedIn }) {
  return {
    view: 'main',
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: isLoggedIn ? 'nonce-1' : '',
    isLoggedIn: !!isLoggedIn,
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
        count: 30,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      },
      {
        id: 22,
        slug: 'cat-b',
        name: 'Cat B',
        translation: 'Cat B',
        count: 30,
        url: '#',
        mode: 'image',
        prompt_type: 'audio',
        option_type: 'image',
        learning_supported: true,
        gender_supported: false,
        aspect_bucket: 'ratio:1_1',
        hidden: false,
        preview: []
      },
      {
        id: 33,
        slug: 'cat-c',
        name: 'Cat C',
        translation: 'Cat C',
        count: 30,
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
    visibleCategoryIds: [11, 22, 33],
    hiddenCategoryIds: [],
    state: {
      wordset_id: 77,
      category_ids: [],
      starred_word_ids: [],
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
    nextActivity: {
      mode: 'listening',
      category_ids: [11, 22, 33],
      session_word_ids: [1101, 2201],
      type: 'review_chunk',
      reason_code: 'review_chunk_balanced',
      details: { chunk_size: 2 }
    },
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
    summaryCounts: {
      mastered: 0,
      studied: 0,
      new: 0
    },
    hardWordDifficultyThreshold: 4,
    i18n: {
      selectionLabel: 'Select categories to study together',
      selectionWordsOnly: '%d words',
      selectionNewOnly: 'New words only',
      selectionStudiedOnly: 'In progress only',
      selectionLearnedOnly: 'Learned only',
      selectAll: 'Select all',
      deselectAll: 'Deselect all',
      noCategoriesSelected: 'Select at least one category.',
      noWordsInSelection: 'No quiz words are available for this selection.',
      noNewWordsInSelection: 'No new words are available for this selection.',
      noStudiedWordsInSelection: 'No in progress words are available for this selection.',
      noLearnedWordsInSelection: 'No learned words are available for this selection.',
      selectionLaunchError: 'Something went wrong. Please try again.',
      priorityFocusNew: 'New words',
      priorityFocusStudied: 'In progress words',
      priorityFocusLearned: 'Learned words',
      priorityFocusStarred: 'Starred words',
      priorityFocusHard: 'Hard words',
      continueLabel: 'Continue',
      repeatLabel: 'Repeat',
      categoriesLabel: 'Categories'
    }
  };
}

async function mountWordsetPage(page, options = {}) {
  const isLoggedIn = !!options.isLoggedIn;
  const wordsByCategory = (options.wordsByCategory && typeof options.wordsByCategory === 'object')
    ? options.wordsByCategory
    : buildCategoryWords();
  let config = buildPageConfig({ isLoggedIn });
  if (options.configPatch && typeof options.configPatch === 'object') {
    config = Object.assign({}, config, options.configPatch);
  }
  const wordsByCategoryName = {};
  (Array.isArray(config.categories) ? config.categories : []).forEach((cat) => {
    const cid = Number(cat && cat.id) || 0;
    const name = String((cat && cat.name) || '');
    if (!cid || !name) {
      return;
    }
    wordsByCategoryName[name] = Array.isArray(wordsByCategory[cid]) ? wordsByCategory[cid] : [];
  });

  await page.goto('about:blank');
  await page.setContent(buildWordsetMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    window.llWordsetPageData = bootstrap.config;
    window.__llLaunches = [];
    window.__llAlerts = [];
    window.__llLaunchTrace = [];
    window.__llVisualizerWarmups = 0;
    window.__llPublicCategoryRequests = {
      active: 0,
      maxActive: 0,
      categories: [],
      actions: [],
      requests: []
    };
    window.__llSelectionPlanRequests = [];
    window.__llFailPublicCategoryOnce = '';
    window.__llPartialPublicCategoryOnce = null;
    window.__llInitAttempts = [];
    window.__llInitFailureOnce = '';
    window.__llBoundedSessionAppends = [];
    window.__llBoundedSessionAppendFailureOnce = '';
    let publicCategoryWarmingRemaining = Math.max(
      0,
      Number(bootstrap.publicCategoryWarmingResponses) || 0
    );
    window.alert = function (message) {
      window.__llAlerts.push(String(message || ''));
    };

    window.LLFlashcards = window.LLFlashcards || {};
    window.LLFlashcards.AudioVisualizer = window.LLFlashcards.AudioVisualizer || {};
    window.LLFlashcards.AudioVisualizer.warmup = function () {
      window.__llVisualizerWarmups = Number(window.__llVisualizerWarmups || 0) + 1;
      window.__llLaunchTrace.push('warmup');
      return Promise.resolve(true);
    };
    window.LLFlashcards.Main = window.LLFlashcards.Main || {};
    window.LLFlashcards.Main.appendBoundedSelectionChunk = function (catNames) {
      const flash = window.llToolsFlashcardsData || {};
      window.__llBoundedSessionAppends.push({
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        sessionWordIds: Array.isArray(flash.sessionWordIds) ? flash.sessionWordIds.slice() : [],
        logicalSessionWordIds: Array.isArray(flash.logicalSessionWordIds)
          ? flash.logicalSessionWordIds.slice()
          : [],
        boundedCandidateCategoryIds: Object.keys(flash.boundedCandidateRowsByCategoryId || {})
          .map((value) => Number(value) || 0)
          .filter((value) => value > 0),
        sessionWordIdsByCategoryId: JSON.parse(JSON.stringify(flash.sessionWordIdsByCategoryId || {}))
      });

      const appendFailure = String(window.__llBoundedSessionAppendFailureOnce || '');
      if (appendFailure) {
        window.__llBoundedSessionAppendFailureOnce = '';
        if (appendFailure === 'throw') {
          throw new Error('test bounded append throw');
        }
        return Promise.reject(new Error('test bounded append rejection'));
      }

      return Promise.resolve({ success: true });
    };

    window.initFlashcardWidget = function (catNames, mode) {
      const flash = window.llToolsFlashcardsData || {};
      const plan = (flash.lastLaunchPlan && typeof flash.lastLaunchPlan === 'object')
        ? flash.lastLaunchPlan
        : ((flash.last_launch_plan && typeof flash.last_launch_plan === 'object') ? flash.last_launch_plan : {});
      const userStudyState = (flash.userStudyState && typeof flash.userStudyState === 'object')
        ? flash.userStudyState
        : {};
      const initAttempt = {
        mode: String(mode || ''),
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        sessionWordIds: Array.isArray(flash.sessionWordIds) ? flash.sessionWordIds.slice() : [],
        source: String(plan.source || ''),
        sessionStarModeOverride: String(flash.sessionStarModeOverride || flash.session_star_mode_override || '')
      };
      window.__llInitAttempts.push(initAttempt);
      const initFailure = String(window.__llInitFailureOnce || '');
      if (initFailure) {
        window.__llInitFailureOnce = '';
        if (initFailure === 'throw') {
          throw new Error('test init throw');
        }
        return Promise.reject(new Error('test init rejection'));
      }

      window.__llLaunches.push({
        mode: String(mode || ''),
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        sessionWordIds: Array.isArray(flash.sessionWordIds) ? flash.sessionWordIds.slice() : [],
        logicalSessionWordIds: Array.isArray(flash.logicalSessionWordIds)
          ? flash.logicalSessionWordIds.slice()
          : [],
        boundedSessionContinuationType: typeof flash.boundedSessionContinuation,
        categoryIds: Array.isArray(plan.category_ids)
          ? plan.category_ids.slice()
          : (Array.isArray(userStudyState.category_ids) ? userStudyState.category_ids.slice() : []),
        hideCategoryDisplay: !!(
          plan.hide_category_display ||
          flash.hideCategoryDisplay ||
          flash.hide_category_display
        ),
        categoryDisplayOverride: String(
          flash.categoryDisplayOverride ||
          flash.category_display_override ||
          ''
        ),
        boundedSelectionPlan: !!(flash.boundedSelectionPlan || flash.bounded_selection_plan),
        boundedCandidateCategoryIds: Object.keys(flash.boundedCandidateRowsByCategoryId || {})
          .map((value) => Number(value) || 0)
          .filter((value) => value > 0),
        sessionWordIdsByCategoryId: JSON.parse(JSON.stringify(flash.sessionWordIdsByCategoryId || {})),
        sessionStarModeOverride: String(flash.sessionStarModeOverride || flash.session_star_mode_override || ''),
        source: String(plan.source || '')
      });
      window.__llLaunchTrace.push('init');
      return Promise.resolve();
    };

    const $ = window.jQuery;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_get_words_by_category' || action === 'll_get_flashcard_payload_page') {
        const categoryName = String((request && request.category) || '');
        const candidateIds = String((request && request.candidate_word_ids) || '')
          .split(',')
          .map((value) => Number(value) || 0)
          .filter((value, index, values) => value > 0 && values.indexOf(value) === index);
        window.__llPublicCategoryRequests.active += 1;
        window.__llPublicCategoryRequests.maxActive = Math.max(
          window.__llPublicCategoryRequests.maxActive,
          window.__llPublicCategoryRequests.active
        );
        window.__llPublicCategoryRequests.categories.push(categoryName);
        window.__llPublicCategoryRequests.actions.push(action);
        window.__llPublicCategoryRequests.requests.push({
          categoryName,
          candidateIds,
          includeOptionPool: String((request && request.include_option_pool) || ''),
          optionPoolLimit: String((request && request.option_pool_limit) || '')
        });
        const resolveCategoryRequest = () => {
          window.__llPublicCategoryRequests.active = Math.max(
            0,
            window.__llPublicCategoryRequests.active - 1
          );
          if (String(window.__llFailPublicCategoryOnce || '') === categoryName) {
            window.__llFailPublicCategoryOnce = '';
            deferred.reject({
              status: 500,
              responseJSON: {
                success: false,
                data: { code: 'test_category_failure' }
              },
              getResponseHeader: () => ''
            });
            return;
          }
          if (publicCategoryWarmingRemaining > 0) {
            publicCategoryWarmingRemaining -= 1;
            deferred.reject({
              status: 429,
              responseJSON: {
                success: false,
                data: {
                  code: 'cache_warming',
                  retry_after: 0,
                  reason: String(bootstrap.publicCategoryWarmingReason || '')
                }
              },
              getResponseHeader: () => ''
            });
            return;
          }
          const categoryRows = Array.isArray(bootstrap.wordsByCategoryName[categoryName])
            ? bootstrap.wordsByCategoryName[categoryName]
            : [];
          let responseRows = categoryRows;
          const partialSpec = window.__llPartialPublicCategoryOnce;
          if (
            partialSpec
            && typeof partialSpec === 'object'
            && String(partialSpec.categoryName || '') === categoryName
          ) {
            const omitId = Number(partialSpec.omitId) || 0;
            window.__llPartialPublicCategoryOnce = null;
            responseRows = categoryRows.filter((row) => (Number(row && row.id) || 0) !== omitId);
          }
          deferred.resolve({
            success: true,
            data: action === 'll_get_flashcard_payload_page'
              ? {
                  schema: 1,
                  rows: responseRows,
                  next_cursor: '',
                  complete: true
                }
              : responseRows
          });
        };
        if (bootstrap.publicCategoryDelayMs > 0) {
          window.setTimeout(resolveCategoryRequest, bootstrap.publicCategoryDelayMs);
        } else {
          resolveCategoryRequest();
        }
        return deferred.promise();
      }

      if (action === 'll_user_study_selection_launch_plan') {
        window.__llSelectionPlanRequests.push({
          categoryIds: Array.isArray(request.category_ids) ? request.category_ids.slice() : [],
          criteria: String(request.criteria || ''),
          mode: String(request.mode || '')
        });
        if (bootstrap.selectionPlanFailureStatus > 0) {
          deferred.reject({
            status: bootstrap.selectionPlanFailureStatus,
            responseJSON: {
              success: false,
              data: {
                code: bootstrap.selectionPlanFailureStatus === 429 ? 'rate_limited' : 'selection_failed',
                message: 'Something went wrong. Please try again.'
              }
            },
            getResponseHeader: () => ''
          });
          return deferred.promise();
        }

        let plan = (bootstrap.selectionLaunchPlan && typeof bootstrap.selectionLaunchPlan === 'object')
          ? bootstrap.selectionLaunchPlan
          : null;
        if (!plan) {
          const requestedCategoryIds = Array.isArray(request.category_ids)
            ? request.category_ids.map((id) => Number(id) || 0).filter(Boolean)
            : [];
          const criteria = String(request.criteria || '');
          const starredLookup = {};
          const state = (bootstrap.config.state && typeof bootstrap.config.state === 'object')
            ? bootstrap.config.state
            : {};
          (Array.isArray(state.starred_word_ids) ? state.starred_word_ids : []).forEach((id) => {
            starredLookup[Number(id) || 0] = true;
          });
          const selectedRows = [];
          const selectedCategoryIds = [];
          const seenWordIds = {};
          requestedCategoryIds.forEach((categoryId) => {
            const category = (Array.isArray(bootstrap.config.categories) ? bootstrap.config.categories : [])
              .find((item) => Number(item && item.id) === categoryId);
            const categoryName = String((category && category.name) || '');
            const rows = Array.isArray(bootstrap.wordsByCategoryName[categoryName])
              ? bootstrap.wordsByCategoryName[categoryName]
              : [];
            let categoryHasMatch = false;
            rows.forEach((row) => {
              const wordId = Number(row && row.id) || 0;
              const status = String((row && row.status) || 'new');
              const difficulty = Number((row && row.difficulty_score) || 0);
              const matches = !criteria
                || (criteria === 'new' && status === 'new')
                || (criteria === 'studied' && status === 'studied')
                || (criteria === 'learned' && (status === 'mastered' || status === 'learned'))
                || (criteria === 'starred' && !!starredLookup[wordId])
                || (criteria === 'hard' && status !== 'new' && difficulty >= Number(bootstrap.config.hardWordDifficultyThreshold || 4));
              if (!wordId || !matches || seenWordIds[wordId] || selectedRows.length >= 15) {
                return;
              }
              seenWordIds[wordId] = true;
              selectedRows.push(wordId);
              categoryHasMatch = true;
            });
            if (categoryHasMatch) {
              selectedCategoryIds.push(categoryId);
            }
          });
          plan = {
            category_ids: selectedCategoryIds,
            word_ids: selectedRows,
            chunks: selectedRows.length ? [{
              category_ids: selectedCategoryIds,
              word_ids: selectedRows
            }] : [],
            criteria,
            mode: String(request.mode || 'practice'),
            matched_count: selectedRows.length,
            planned_count: selectedRows.length,
            chunk_count: selectedRows.length ? 1 : 0,
            truncated: false
          };
        }
        deferred.resolve({
          success: true,
          data: { plan }
        });
        return deferred.promise();
      }

      if (action === 'll_user_study_recommendation') {
        deferred.resolve({
          success: true,
          data: {
            next_activity: bootstrap.config.nextActivity,
            recommendation_queue: bootstrap.config.recommendationQueue || []
          }
        });
        return deferred.promise();
      }

      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, {
    config,
    wordsByCategory,
    wordsByCategoryName,
    publicCategoryDelayMs: Math.max(0, Number(options.publicCategoryDelayMs) || 0),
    publicCategoryWarmingResponses: Math.max(0, Number(options.publicCategoryWarmingResponses) || 0),
    publicCategoryWarmingReason: String(options.publicCategoryWarmingReason || ''),
    selectionLaunchPlan: options.selectionLaunchPlan || null,
    selectionPlanFailureStatus: Math.max(0, Number(options.selectionPlanFailureStatus) || 0)
  });

  await page.addScriptTag({ content: wordsetScriptSource });
}

async function startInProgressPracticeSelection(page) {
  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();
}

async function invokeBoundedSessionContinuation(page, callCount = 1) {
  return page.evaluate(async (requestedCallCount) => {
    const flash = window.llToolsFlashcardsData || {};
    const continuation = flash.boundedSessionContinuation;
    if (typeof continuation !== 'function') {
      return {
        callable: false,
        samePromise: false,
        outcomes: []
      };
    }

    const count = Math.max(1, Number(requestedCallCount) || 1);
    const promises = [];
    for (let index = 0; index < count; index += 1) {
      try {
        promises.push(continuation());
      } catch (error) {
        promises.push(Promise.reject(error));
      }
    }
    const samePromise = promises.length < 2 || promises.every((promise) => promise === promises[0]);
    const outcomes = await Promise.all(promises.map((promise) => Promise.resolve(promise).then(
      (value) => ({ status: 'fulfilled', value }),
      (error) => ({ status: 'rejected', message: String((error && error.message) || error || '') })
    )));

    return {
      callable: true,
      samePromise,
      outcomes
    };
  }, callCount);
}

test('logged-out select-all shows real word count and allows listening launch', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: false });

  await page.locator('[data-ll-wordset-select-all]').click();

  await expect.poll(async () => {
    return page.locator('[data-ll-wordset-selection-text]').innerText();
  }).toContain('90');

  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]')).toBeEnabled();

  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('listening');
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
});

test('logged-out public practice hydration serializes category cache misses', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: false,
    publicCategoryDelayMs: 100
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const requestStats = await page.evaluate(() => window.__llPublicCategoryRequests);
  expect(requestStats.categories).toEqual(['Cat A', 'Cat B', 'Cat C']);
  expect(requestStats.maxActive).toBe(1);
  expect(requestStats.active).toBe(0);
});

test('logged-out public practice keeps loading while a category cache warms', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: false,
    publicCategoryDelayMs: 50,
    publicCategoryWarmingResponses: 1
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toHaveAttribute('aria-busy', 'true');
  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const requestStats = await page.evaluate(() => window.__llPublicCategoryRequests);
  expect(requestStats.categories).toEqual(['Cat A', 'Cat A', 'Cat B', 'Cat C']);
  expect(requestStats.maxActive).toBe(1);
});

test('logged-in listening launches ignore recommendation chunk IDs for top and selection starts', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: true });

  await page.locator('[data-ll-wordset-start-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const topLaunch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });

  expect(topLaunch).not.toBeNull();
  expect(topLaunch.mode).toBe('listening');
  expect(topLaunch.sessionWordIds).toEqual([]);
  expect(topLaunch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]')).toBeEnabled();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(2);

  const selectionLaunch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[1]) || null;
  });

  expect(selectionLaunch).not.toBeNull();
  expect(selectionLaunch.mode).toBe('listening');
  expect(selectionLaunch.sessionWordIds).toEqual([]);
  expect(selectionLaunch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
});

test('listening top launch warms visualizer before async init path', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: true });

  await page.locator('[data-ll-wordset-start-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const trace = await page.evaluate(() => ({
    warmups: Number(window.__llVisualizerWarmups || 0),
    order: Array.isArray(window.__llLaunchTrace) ? window.__llLaunchTrace.slice() : []
  }));

  expect(trace.warmups).toBeGreaterThan(0);
  expect(trace.order.length).toBeGreaterThan(1);
  expect(trace.order[0]).toBe('warmup');
  expect(trace.order).toContain('init');
});

test('selection listening launch skips dashboard bulk word fetch and opens immediately', async ({ page }) => {
  await mountWordsetPage(page, { isLoggedIn: true });

  await page.evaluate(() => {
    const $ = window.jQuery;
    const originalPost = $.post.bind($);

    window.__llFetchWordsCalls = 0;
    window.__llFetchWordsPending = 0;
    window.__llFetchWordsResolvers = [];

    $.post = function (url, request) {
      const action = request && request.action ? String(request.action) : '';
      if (action === 'll_get_flashcard_payload_page') {
        window.__llFetchWordsCalls += 1;
        window.__llFetchWordsPending += 1;
        const deferred = $.Deferred();
        window.__llFetchWordsResolvers.push(() => {
          window.__llFetchWordsPending = Math.max(0, (window.__llFetchWordsPending || 0) - 1);
          deferred.resolve({
            success: true,
            data: {
              schema: 1,
              rows: [],
              next_cursor: '',
              complete: true
            }
          });
        });
        return deferred.promise();
      }
      return originalPost(url, request);
    };

    window.__llReleaseFetchWords = function () {
      const resolvers = Array.isArray(window.__llFetchWordsResolvers)
        ? window.__llFetchWordsResolvers.splice(0)
        : [];
      resolvers.forEach((resolve) => {
        try { resolve(); } catch (_) {}
      });
    };
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]')).toBeEnabled();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const state = await page.evaluate(() => ({
    fetchCalls: Number(window.__llFetchWordsCalls || 0),
    fetchPending: Number(window.__llFetchWordsPending || 0)
  }));

  expect(state.fetchCalls).toBe(0);
  expect(state.fetchPending).toBe(0);

  const launch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('listening');
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);

  await page.evaluate(() => {
    if (typeof window.__llReleaseFetchWords === 'function') {
      window.__llReleaseFetchWords();
    }
  });
});

test('practice selection opens loading popup before selected categories finish loading', async ({ page }) => {
  const responseWords = buildCategoryWords();
  await mountWordsetPage(page, { isLoggedIn: true, wordsByCategory: responseWords });

  await page.evaluate((wordsByCategory) => {
    const $ = window.jQuery;
    const originalPost = $.post.bind($);

    window.__llFetchWordsCalls = 0;
    window.__llFetchWordsPending = 0;
    window.__llFetchWordsResolvers = [];
    window.__llReleaseAllFetchWords = false;

    $.post = function (url, request) {
      const action = request && request.action ? String(request.action) : '';
      if (action === 'll_get_flashcard_payload_page') {
        window.__llFetchWordsCalls += 1;
        window.__llFetchWordsPending += 1;
        const deferred = $.Deferred();
        const categoryId = Number(request && request.category_id) || 0;
        const resolveRequest = () => {
          window.__llFetchWordsPending = Math.max(0, (window.__llFetchWordsPending || 0) - 1);
          deferred.resolve({
            success: true,
            data: {
              schema: 1,
              rows: Array.isArray(wordsByCategory[categoryId])
                ? wordsByCategory[categoryId]
                : [],
              next_cursor: '',
              complete: true
            }
          });
        };
        if (window.__llReleaseAllFetchWords) {
          window.setTimeout(resolveRequest, 0);
        } else {
          window.__llFetchWordsResolvers.push(resolveRequest);
        }
        return deferred.promise();
      }
      return originalPost(url, request);
    };

    window.__llReleaseFetchWords = function () {
      window.__llReleaseAllFetchWords = true;
      const resolvers = Array.isArray(window.__llFetchWordsResolvers)
        ? window.__llFetchWordsResolvers.splice(0)
        : [];
      resolvers.forEach((resolve) => {
        try { resolve(); } catch (_) {}
      });
    };
  }, responseWords);

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]')).toBeEnabled();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Number(window.__llFetchWordsPending || 0));
  }).toBe(1);

  const loadingState = await page.evaluate(() => {
    const popup = document.getElementById('ll-tools-flashcard-popup');
    const quizPopup = document.getElementById('ll-tools-flashcard-quiz-popup');
    const loader = document.getElementById('ll-tools-loading-animation');

    return {
      popupDisplay: popup ? window.getComputedStyle(popup).display : '',
      quizDisplay: quizPopup ? window.getComputedStyle(quizPopup).display : '',
      loaderDisplay: loader ? window.getComputedStyle(loader).display : '',
      quizLoading: quizPopup ? quizPopup.classList.contains('ll-round-loading-active') : false,
      quizBusy: quizPopup ? quizPopup.getAttribute('aria-busy') : '',
      bodyOpen: document.body.classList.contains('ll-tools-flashcard-open'),
      launchCount: Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0,
      fetchCalls: Number(window.__llFetchWordsCalls || 0)
    };
  });

  expect(loadingState.fetchCalls).toBe(1);
  expect(loadingState.launchCount).toBe(0);
  expect(loadingState.popupDisplay).not.toBe('none');
  expect(loadingState.quizDisplay).not.toBe('none');
  expect(loadingState.loaderDisplay).not.toBe('none');
  expect(loadingState.quizLoading).toBe(true);
  expect(loadingState.quizBusy).toBe('true');
  expect(loadingState.bodyOpen).toBe(true);

  await page.evaluate(() => {
    if (typeof window.__llReleaseFetchWords === 'function') {
      window.__llReleaseFetchWords();
    }
  });

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_selection_start');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
});

test('logged-in practice top launch falls back to visible categories when recommendation categories are stale', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: true,
    configPatch: {
      nextActivity: {
        mode: 'practice',
        category_ids: [9999],
        session_word_ids: [999901],
        type: 'review_chunk',
        reason_code: 'review_chunk_balanced',
        details: { chunk_size: 1 }
      },
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-start-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const launch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });
  const alerts = await page.evaluate(() => {
    return Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : [];
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
  expect(alerts).toEqual([]);
});

test('hard-word practice recommendations randomize the starting category order for session launches', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 5, 'A'),
    22: buildCategoryWordRows(22, 5, 'B'),
    33: buildCategoryWordRows(33, 5, 'C')
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      nextActivity: {
        mode: 'practice',
        category_ids: [11, 22, 33],
        session_word_ids: [1101, 1102, 1103, 2201, 2202, 3301],
        type: 'priority_focus',
        reason_code: 'priority_focus',
        details: {
          chunk_size: 6,
          priority_focus: 'hard'
        }
      },
      recommendationQueue: []
    }
  });

  await page.evaluate(() => {
    // Keep the shuffle deterministic even if unrelated UI work also consumes randomness.
    Math.random = function () {
      return 0;
    };
  });

  await page.locator('[data-ll-wordset-start-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0;
    });
  }).toBe(1);

  const launch = await page.evaluate(() => {
    return (window.__llLaunches && window.__llLaunches[0]) || null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_top_start_queue');
  expect(launch.sessionWordIds).toEqual([1101, 1102, 1103, 2201, 2202, 3301]);
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
  expect(launch.categoryIds).not.toEqual([11, 22, 33]);
  expect(launch.catNames).toEqual(launch.categoryIds.map((categoryId) => ({
    11: 'Cat A',
    22: 'Cat B',
    33: 'Cat C'
  }[categoryId])));
});

test('gender top launch does not downgrade to practice when queued recommendations target non-gender categories', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 5, 'A'),
    22: buildCategoryWordRows(22, 5, 'B'),
    33: buildCategoryWordRows(33, 5, 'C')
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: true,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        }
      ],
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'gender', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: ''
      },
      gender: {
        enabled: true,
        options: ['masculine', 'feminine'],
        min_count: 2
      },
      nextActivity: {
        mode: 'practice',
        category_ids: [11, 22],
        session_word_ids: [1101, 1102, 1103, 2201, 2202],
        type: 'review_chunk',
        reason_code: 'review_chunk_balanced',
        details: { chunk_size: 5 }
      },
      recommendationQueue: [
        {
          mode: 'practice',
          category_ids: [11, 22],
          session_word_ids: [1101, 1102, 1103, 2201, 2202],
          type: 'review_chunk',
          reason_code: 'review_chunk_balanced',
          queue_id: 'queue-practice-only',
          details: { chunk_size: 5 }
        }
      ]
    }
  });

  const genderButton = page.locator('[data-ll-wordset-start-mode][data-mode="gender"]');
  await expect(genderButton).toBeEnabled();
  await genderButton.click();
  await page.waitForTimeout(300);

  const launchState = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches.slice() : [];
    const alerts = Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : [];
    return {
      launches,
      alerts
    };
  });

  expect(launchState.launches.every((entry) => String(entry && entry.mode || '') !== 'practice')).toBeTruthy();
  if (launchState.launches.length > 0) {
    const launch = launchState.launches[launchState.launches.length - 1];
    expect(String(launch.mode || '')).toBe('gender');
    expect(Array.isArray(launch.categoryIds) ? launch.categoryIds : []).toContain(33);
  }
});

test('next learning recommendation with five starred words is expanded to a minimum-size compatible session', async ({ page }) => {
  const wordsByCategory = {
    11: [
      { id: 1101, title: 'A1', translation: 'A1', label: 'A1', audio: '', image: '', audio_files: [] },
      { id: 1102, title: 'A2', translation: 'A2', label: 'A2', audio: '', image: '', audio_files: [] },
      { id: 1103, title: 'A3', translation: 'A3', label: 'A3', audio: '', image: '', audio_files: [] },
      { id: 1104, title: 'A4', translation: 'A4', label: 'A4', audio: '', image: '', audio_files: [] },
      { id: 1105, title: 'A5', translation: 'A5', label: 'A5', audio: '', image: '', audio_files: [] }
    ],
    22: [
      { id: 2201, title: 'B1', translation: 'B1', label: 'B1', audio: '', image: '', audio_files: [] },
      { id: 2202, title: 'B2', translation: 'B2', label: 'B2', audio: '', image: '', audio_files: [] },
      { id: 2203, title: 'B3', translation: 'B3', label: 'B3', audio: '', image: '', audio_files: [] },
      { id: 2204, title: 'B4', translation: 'B4', label: 'B4', audio: '', image: '', audio_files: [] },
      { id: 2205, title: 'B5', translation: 'B5', label: 'B5', audio: '', image: '', audio_files: [] }
    ],
    33: [
      { id: 3301, title: 'C1', translation: 'C1', label: 'C1', audio: '', image: '', audio_files: [] },
      { id: 3302, title: 'C2', translation: 'C2', label: 'C2', audio: '', image: '', audio_files: [] },
      { id: 3303, title: 'C3', translation: 'C3', label: 'C3', audio: '', image: '', audio_files: [] },
      { id: 3304, title: 'C4', translation: 'C4', label: 'C4', audio: '', image: '', audio_files: [] },
      { id: 3305, title: 'C5', translation: 'C5', label: 'C5', audio: '', image: '', audio_files: [] }
    ]
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 30,
          url: '#',
          mode: 'text',
          prompt_type: 'audio',
          option_type: 'text_translation',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        }
      ],
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: [1101, 1102, 1103, 2201, 2202],
        star_mode: 'normal',
        fast_transitions: false
      },
      nextActivity: {
        mode: 'learning',
        category_ids: [11, 22, 33],
        session_word_ids: [1101, 1102, 1103, 2201, 2202],
        type: 'review_chunk',
        reason_code: 'review_chunk_balanced',
        details: { chunk_size: 5, priority_focus: 'starred' }
      }
    }
  });

  await page.locator('[data-ll-wordset-next]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('learning');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);
  expect(launch.sessionWordIds.length).toBeGreaterThanOrEqual(8);
  expect(launch.sessionWordIds.every((id) => (id >= 1101 && id <= 1105) || (id >= 2201 && id <= 2205))).toBeTruthy();

  const categoryDisplayOverride = await page.evaluate(() => {
    const flash = window.llToolsFlashcardsData || {};
    return String(flash.categoryDisplayOverride || flash.category_display_override || '');
  });
  expect(categoryDisplayOverride).toBe('Starred words');
});

test('selection keeps starred-only hidden when fewer than eight starred words are available', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: true,
    configPatch: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: [1101, 1102, 1103, 2201, 2202, 2203, 3301],
        star_mode: 'normal',
        fast_transitions: false
      }
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();

  await expect(page.locator('.ll-wordset-selection-bar__starred-toggle')).toBeHidden();
});

test('priority-only practice selection filters to the current study focus', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 8, 'A').map((row, index) => Object.assign({}, row, {
      category_id: 11,
      category_ids: [11],
      status: index < 5 ? 'studied' : 'new',
      difficulty_score: index < 5 ? 2 : 0
    })),
    22: buildCategoryWordRows(22, 8, 'B').map((row, index) => Object.assign({}, row, {
      category_id: 22,
      category_ids: [22],
      status: index < 4 ? 'studied' : 'new',
      difficulty_score: index < 4 ? 1 : 0
    })),
    33: buildCategoryWordRows(33, 8, 'C').map((row) => Object.assign({}, row, {
      category_id: 33,
      category_ids: [33],
      status: 'new',
      difficulty_score: 0
    }))
  };
  const expectedStudiedWordIds = Object.values(wordsByCategory)
    .flat()
    .filter((row) => row.status === 'studied')
    .map((row) => Number(row && row.id) || 0)
    .filter(Boolean)
    .sort((a, b) => a - b);

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 8,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 8,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 8,
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
      visibleCategoryIds: [11, 22, 33],
      hiddenCategoryIds: [],
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: expectedStudiedWordIds.length,
        new: 24 - expectedStudiedWordIds.length,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  const selectionPracticeButton = page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]');
  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('.ll-wordset-selection-bar__priority-toggle')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-selection-priority-label]')).toHaveText('In progress only');
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await expect(selectionPracticeButton).toBeEnabled();
  await selectionPracticeButton.click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_selection_bounded_start');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);
  expect(launch.sessionWordIds.slice().sort((a, b) => a - b)).toEqual(expectedStudiedWordIds);
  const requestStats = await page.evaluate(() => ({
    plans: Array.isArray(window.__llSelectionPlanRequests) ? window.__llSelectionPlanRequests.slice() : [],
    publicRequests: window.__llPublicCategoryRequests
  }));
  expect(requestStats.plans).toEqual([{
    categoryIds: [11, 22, 33],
    criteria: 'studied',
    mode: 'practice'
  }]);
  expect(requestStats.publicRequests.categories).toEqual(['Cat A', 'Cat B']);
  expect(requestStats.publicRequests.actions).toEqual([
    'll_get_words_by_category',
    'll_get_words_by_category'
  ]);
  expect(requestStats.publicRequests.maxActive).toBe(1);
});

test('bounded selection continuation coalesces one serial next-batch request', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch
  });

  await startInProgressPracticeSelection(page);

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const initialLaunch = await page.evaluate(() => window.__llLaunches[0]);
  expect(initialLaunch.source).toBe('wordset_chunk_start');
  expect(initialLaunch.categoryDisplayOverride).toBe('In progress words');
  expect(initialLaunch.categoryIds).toEqual([11, 22, 33]);
  expect(initialLaunch.sessionWordIds).toEqual(fixture.firstChunkWordIds);
  expect(initialLaunch.logicalSessionWordIds).toEqual(fixture.allPlannedWordIds);
  expect(initialLaunch.boundedCandidateCategoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);

  const continuation = await invokeBoundedSessionContinuation(page, 2);
  expect(continuation.callable).toBeTruthy();
  expect(continuation.samePromise).toBeTruthy();
  expect(continuation.outcomes).toEqual([
    { status: 'fulfilled', value: { success: true, index: 1, chunk_count: 2 } },
    { status: 'fulfilled', value: { success: true, index: 1, chunk_count: 2 } }
  ]);

  const result = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    planRequests: window.__llSelectionPlanRequests.slice(),
    publicRequests: {
      maxActive: window.__llPublicCategoryRequests.maxActive,
      categories: window.__llPublicCategoryRequests.categories.slice(),
      requests: window.__llPublicCategoryRequests.requests.map((request) => ({
        categoryName: request.categoryName,
        candidateIds: request.candidateIds.slice(),
        includeOptionPool: request.includeOptionPool,
        optionPoolLimit: request.optionPoolLimit
      }))
    }
  }));
  expect(result.launches).toHaveLength(1);
  expect(result.appends).toHaveLength(1);
  expect(result.appends[0].catNames.slice().sort()).toEqual(['Cat B', 'Cat C']);
  expect(result.appends[0].sessionWordIds).toEqual(fixture.secondChunkWordIds);
  expect(result.appends[0].logicalSessionWordIds).toEqual(fixture.allPlannedWordIds);
  expect(result.appends[0].boundedCandidateCategoryIds.slice().sort((a, b) => a - b)).toEqual([22, 33]);
  expect(result.appends[0].sessionWordIdsByCategoryId['22'])
    .toEqual(fixture.wordsByCategory[22].slice(5).map((row) => row.id));
  expect(result.appends[0].sessionWordIdsByCategoryId['33'])
    .toEqual(fixture.wordsByCategory[33].map((row) => row.id));
  expect(result.planRequests).toHaveLength(1);
  expect(result.publicRequests.maxActive).toBe(1);
  expect(result.publicRequests.categories).toEqual(['Cat A', 'Cat B', 'Cat B', 'Cat C']);
  expect(result.publicRequests.requests).toHaveLength(4);
  result.publicRequests.requests.forEach((request, index) => {
    const expectedChunkIds = index < 2 ? fixture.firstChunkWordIds : fixture.secondChunkWordIds;
    expect(request.candidateIds.slice().sort((a, b) => a - b))
      .toEqual(expectedChunkIds.slice().sort((a, b) => a - b));
    expect(request.candidateIds.length).toBeLessThanOrEqual(15);
    expect(request.includeOptionPool).toBe('1');
    expect(request.optionPoolLimit).toBe('12');
  });
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect(page.locator('#ll-study-results-next-chunk')).toBeHidden();
});

test('bounded candidate hydration keeps retrying while its materialized option pool warms', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch,
    publicCategoryWarmingResponses: 3,
    publicCategoryWarmingReason: 'option_pool_materializing'
  });

  await startInProgressPracticeSelection(page);

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const requests = await page.evaluate(() => ({
    categories: window.__llPublicCategoryRequests.categories.slice(),
    actions: window.__llPublicCategoryRequests.actions.slice()
  }));
  expect(requests.categories.slice(0, 4)).toEqual(['Cat A', 'Cat A', 'Cat A', 'Cat A']);
  expect(requests.actions.every((action) => action === 'll_get_words_by_category')).toBeTruthy();
});

test('bounded non-practice sessions retain explicit next-chunk navigation', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  fixture.selectionLaunchPlan = Object.assign({}, fixture.selectionLaunchPlan, {
    mode: 'listening'
  });
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="listening"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const initial = await page.evaluate(() => ({
    launch: window.__llLaunches[0],
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation
  }));
  expect(initial.launch.mode).toBe('listening');
  expect(initial.launch.source).toBe('wordset_chunk_start');
  expect(initial.launch.sessionWordIds).toEqual(fixture.firstChunkWordIds);
  expect(initial.continuationType).toBe('undefined');

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:flashcard-results-shown', [{ mode: 'listening' }]);
  });
  const continueButton = page.locator('#ll-study-results-next-chunk');
  await expect(continueButton).toBeVisible();
  await continueButton.click();

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(2);
  const nextLaunch = await page.evaluate(() => window.__llLaunches[1]);
  expect(nextLaunch.mode).toBe('listening');
  expect(nextLaunch.source).toBe('wordset_chunk_continue');
  expect(nextLaunch.sessionWordIds).toEqual(fixture.secondChunkWordIds);
});

test('deferred-category select all hands one 342-word runtime session to bounded hydration', async ({ page }) => {
  const fixture = buildDeferredCategorySelectionFixture();
  const chunks = fixture.selectionLaunchPlan.chunks;
  const expectedWordIds = Object.values(fixture.wordsByCategory)
    .flatMap((rows) => rows.map((row) => row.id));
  const plannedWordIds = chunks.flatMap((chunk) => chunk.word_ids);
  const plannedCategoryIds = Array.from(new Set(chunks.flatMap((chunk) => chunk.category_ids)));
  const categoryNameById = Object.fromEntries(
    fixture.categories.map((category) => [category.id, category.name])
  );

  expect(plannedWordIds).toHaveLength(342);
  expect(new Set(plannedWordIds).size).toBe(342);
  expect(plannedWordIds.slice().sort((a, b) => a - b))
    .toEqual(expectedWordIds.slice().sort((a, b) => a - b));
  chunks.forEach((chunk) => {
    expect(chunk.word_ids.length).toBeGreaterThanOrEqual(5);
    expect(chunk.word_ids.length).toBeLessThanOrEqual(15);
    expect(chunk.category_ids.length).toBeLessThanOrEqual(8);
    expect(Array.from(new Set(chunk.word_ids.map((wordId) => fixture.ownerByWordId[wordId]))))
      .toEqual(chunk.category_ids);
  });

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: {
      categories: fixture.categories,
      visibleCategoryIds: fixture.categoryIds,
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: 342,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  const renderedCategoryIds = await page.locator('[data-ll-wordset-select]').evaluateAll((checks) => (
    checks.map((check) => Number(check.value) || 0)
  ));
  expect(renderedCategoryIds).toEqual([11, 22, 33]);
  expect(fixture.categoryIds).toHaveLength(12);
  expect(chunks[0].category_ids).toEqual([11, 44, 77]);
  expect(chunks[0].category_ids.filter((categoryId) => !renderedCategoryIds.includes(categoryId)))
    .toEqual([44, 77]);

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('[data-ll-wordset-selection-text]')).toHaveText('342 words');
  await expect(page.locator('[data-ll-wordset-selection-priority-label]')).toHaveText('In progress only');
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const firstResult = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    planRequests: window.__llSelectionPlanRequests.slice(),
    publicRequests: {
      maxActive: window.__llPublicCategoryRequests.maxActive,
      categories: window.__llPublicCategoryRequests.categories.slice(),
      actions: window.__llPublicCategoryRequests.actions.slice(),
      requests: window.__llPublicCategoryRequests.requests.map((request) => ({
        categoryName: request.categoryName,
        candidateIds: request.candidateIds.slice(),
        includeOptionPool: request.includeOptionPool,
        optionPoolLimit: request.optionPoolLimit
      }))
    }
  }));
  const firstLaunch = firstResult.launches[0];
  const expectedFirstCategoryNames = chunks[0].category_ids.map((categoryId) => categoryNameById[categoryId]);
  expect(firstResult.planRequests).toEqual([{
    categoryIds: fixture.categoryIds,
    criteria: 'studied',
    mode: 'practice'
  }]);
  expect(firstLaunch.source).toBe('wordset_chunk_start');
  expect(firstLaunch.categoryDisplayOverride).toBe('In progress words');
  expect(firstLaunch.categoryIds).toEqual(plannedCategoryIds);
  expect(firstLaunch.boundedCandidateCategoryIds.slice().sort((a, b) => a - b))
    .toEqual(chunks[0].category_ids.slice().sort((a, b) => a - b));
  chunks[0].category_ids.forEach((categoryId) => {
    expect(firstLaunch.sessionWordIdsByCategoryId[String(categoryId)])
      .toEqual(chunks[0].word_ids.filter((wordId) => fixture.ownerByWordId[wordId] === categoryId));
  });
  expect(firstResult.publicRequests.maxActive).toBe(1);
  expect(firstResult.publicRequests.categories.slice().sort())
    .toEqual(expectedFirstCategoryNames.slice().sort());
  expect(new Set(firstResult.publicRequests.categories).size).toBe(firstResult.publicRequests.categories.length);
  expect(firstResult.publicRequests.actions).toEqual(
    Array(chunks[0].category_ids.length).fill('ll_get_words_by_category')
  );
  expect(firstResult.publicRequests.requests).toHaveLength(chunks[0].category_ids.length);
  firstResult.publicRequests.requests.forEach((request) => {
    expect(request.candidateIds).toEqual(chunks[0].word_ids);
    expect(request.candidateIds.length).toBeLessThanOrEqual(15);
    expect(request.includeOptionPool).toBe('1');
    expect(request.optionPoolLimit).toBe('12');
  });

  // The stubbed widget cannot answer through a hydration boundary. Its init
  // snapshot is the runtime handoff: the loader-scoped session stays on the
  // first chunk, while a separate logical session and continuation hook keep
  // planner boundaries inside one uninterrupted quiz.
  expect(firstLaunch.sessionWordIds).toEqual(chunks[0].word_ids);
  expect.soft({
    count: firstLaunch.logicalSessionWordIds.length,
    uniqueCount: new Set(firstLaunch.logicalSessionWordIds).size,
    matchesPlan: JSON.stringify(firstLaunch.logicalSessionWordIds) === JSON.stringify(plannedWordIds)
  }).toEqual({
    count: 342,
    uniqueCount: 342,
    matchesPlan: true
  });
  expect.soft(firstLaunch.boundedSessionContinuationType).toBe('function');
  expect(firstResult.launches).toHaveLength(1);
  expect(firstResult.planRequests).toHaveLength(1);

  const continueButton = page.locator('#ll-study-results-next-chunk');
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect(continueButton).toBeHidden();
});

test('bounded continuation advances only after append acceptance and keeps a failed batch retryable', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  await page.evaluate(() => {
    window.__llBoundedSessionAppendFailureOnce = 'throw';
  });
  const failedContinuation = await invokeBoundedSessionContinuation(page);
  expect(failedContinuation).toEqual({
    callable: true,
    samePromise: true,
    outcomes: [{
      status: 'rejected',
      message: 'The bounded practice continuation failed to load.'
    }]
  });

  const failedState = await page.evaluate(() => ({
    appends: window.__llBoundedSessionAppends.slice(),
    launches: window.__llLaunches.slice(),
    initAttempts: window.__llInitAttempts.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice(),
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation,
    flashSessionWordIds: Array.isArray((window.llToolsFlashcardsData || {}).sessionWordIds)
      ? window.llToolsFlashcardsData.sessionWordIds.slice()
      : [],
    flashBoundedCandidateCategoryIds: Object.keys(
      (window.llToolsFlashcardsData || {}).boundedCandidateRowsByCategoryId || {}
    ).map((value) => Number(value) || 0).sort((a, b) => a - b),
    flashSessionWordIdsByCategoryId: Object.assign(
      {},
      (window.llToolsFlashcardsData || {}).sessionWordIdsByCategoryId || {}
    )
  }));
  expect(failedState.appends).toHaveLength(1);
  expect(failedState.appends[0].catNames.slice().sort()).toEqual(['Cat B', 'Cat C']);
  expect(failedState.appends[0].sessionWordIds).toEqual(fixture.secondChunkWordIds);
  expect(failedState.launches).toHaveLength(1);
  expect(failedState.initAttempts).toHaveLength(1);
  expect(failedState.publicCategories).toEqual(['Cat A', 'Cat B', 'Cat B', 'Cat C']);
  expect(failedState.continuationType).toBe('function');
  expect(failedState.flashSessionWordIds).toEqual(failedState.launches[0].sessionWordIds);
  expect(failedState.flashBoundedCandidateCategoryIds)
    .toEqual(failedState.launches[0].boundedCandidateCategoryIds.slice().sort((a, b) => a - b));
  expect(failedState.flashSessionWordIdsByCategoryId)
    .toEqual(failedState.launches[0].sessionWordIdsByCategoryId);

  const acceptedContinuation = await invokeBoundedSessionContinuation(page);
  expect(acceptedContinuation).toEqual({
    callable: true,
    samePromise: true,
    outcomes: [{
      status: 'fulfilled',
      value: { success: true, index: 1, chunk_count: 2 }
    }]
  });

  const result = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    initAttempts: window.__llInitAttempts.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice(),
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation
  }));
  expect(result.launches).toHaveLength(1);
  expect(result.initAttempts).toHaveLength(1);
  expect(result.appends).toHaveLength(2);
  expect(result.appends[1].catNames.slice().sort()).toEqual(result.appends[0].catNames.slice().sort());
  expect(result.appends[1].sessionWordIds).toEqual(result.appends[0].sessionWordIds);
  expect(result.appends[1].logicalSessionWordIds).toEqual(result.appends[0].logicalSessionWordIds);
  expect(result.appends[1].boundedCandidateCategoryIds.slice().sort((a, b) => a - b))
    .toEqual(result.appends[0].boundedCandidateCategoryIds.slice().sort((a, b) => a - b));
  expect(result.appends[1].sessionWordIdsByCategoryId).toEqual(result.appends[0].sessionWordIdsByCategoryId);
  expect(result.publicCategories).toEqual(['Cat A', 'Cat B', 'Cat B', 'Cat C', 'Cat B', 'Cat C']);
  expect(result.continuationType).toBe('undefined');
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect(page.locator('#ll-study-results-next-chunk')).toBeHidden();
});

test('closing a logical session rejects a queued bounded append before hydration', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch,
    publicCategoryDelayMs: 25
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);

  const closedOutcome = await page.evaluate(async () => {
    const continuation = (window.llToolsFlashcardsData || {}).boundedSessionContinuation;
    const pending = continuation();
    window.jQuery(document).trigger('lltools:flashcard-closed');
    return Promise.resolve(pending).then(
      (value) => ({ status: 'fulfilled', value }),
      (error) => ({ status: 'rejected', message: String((error && error.message) || error || '') })
    );
  });
  expect(closedOutcome).toEqual({
    status: 'rejected',
    message: 'The bounded practice continuation failed to load.'
  });

  const result = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation,
    publicCategories: window.__llPublicCategoryRequests.categories.slice()
  }));
  expect(result.launches).toHaveLength(1);
  expect(result.appends).toHaveLength(0);
  expect(result.continuationType).toBe('undefined');
  // Closing synchronously must prevent the queued continuation from starting network work.
  expect(result.publicCategories).toEqual(['Cat A', 'Cat B']);
});

test('a stale initial hydration cannot clear the replacement logical session', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch,
    publicCategoryDelayMs: 40
  });

  await startInProgressPracticeSelection(page);
  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:flashcard-closed');
  });
  await startInProgressPracticeSelection(page);

  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const result = await page.evaluate(() => ({
    launch: window.__llLaunches[0],
    appends: window.__llBoundedSessionAppends.slice(),
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation,
    planRequests: window.__llSelectionPlanRequests.slice()
  }));
  expect(result.launch.source).toBe('wordset_chunk_start');
  expect(result.launch.sessionWordIds).toEqual(fixture.firstChunkWordIds);
  expect(result.appends).toHaveLength(0);
  expect(result.continuationType).toBe('function');
  expect(result.planRequests).toHaveLength(2);
});

test('final repeat restarts the first bounded batch with the full logical scope', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const continuation = await invokeBoundedSessionContinuation(page);
  expect(continuation.outcomes).toEqual([{
    status: 'fulfilled',
    value: { success: true, index: 1, chunk_count: 2 }
  }]);
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect(page.locator('#ll-study-results-next-chunk')).toBeHidden();

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:flashcard-results-shown', [{ mode: 'practice' }]);
    const repeatButton = window.document.querySelector('#ll-study-results-same-chunk');
    if (repeatButton) {
      repeatButton.click();
    }
  });
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(2);
  const result = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    planRequests: window.__llSelectionPlanRequests.slice()
  }));
  const repeatedLaunch = result.launches[1];
  expect(repeatedLaunch.source).toBe('wordset_logical_session_repeat');
  expect(repeatedLaunch.categoryIds).toEqual([11, 22, 33]);
  expect(repeatedLaunch.sessionWordIds).toEqual(fixture.firstChunkWordIds);
  expect(repeatedLaunch.logicalSessionWordIds).toEqual(fixture.allPlannedWordIds);
  expect(repeatedLaunch.boundedCandidateCategoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);
  expect(repeatedLaunch.boundedSessionContinuationType).toBe('function');
  expect(result.appends).toHaveLength(1);
  expect(result.planRequests).toHaveLength(1);
  await expect(page.locator('#ll-study-results-next-chunk')).toBeHidden();
});

test('partial bounded hydration fails closed and refetches the same batch on continuation retry', async ({ page }) => {
  const fixture = buildBoundedChunkFixture();
  const omittedWordId = fixture.wordsByCategory[33][0].id;
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: fixture.wordsByCategory,
    selectionLaunchPlan: fixture.selectionLaunchPlan,
    configPatch: fixture.configPatch
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  await page.evaluate(({ omitId }) => {
    window.__llPartialPublicCategoryOnce = {
      categoryName: 'Cat C',
      omitId
    };
  }, { omitId: omittedWordId });
  const failedContinuation = await invokeBoundedSessionContinuation(page);
  expect(failedContinuation).toEqual({
    callable: true,
    samePromise: true,
    outcomes: [{
      status: 'rejected',
      message: 'The bounded practice continuation failed to load.'
    }]
  });

  const failedState = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    launches: window.__llLaunches.slice(),
    initAttempts: window.__llInitAttempts.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    categories: window.__llPublicCategoryRequests.categories.slice(),
    requests: window.__llPublicCategoryRequests.requests.map((request) => ({
      categoryName: request.categoryName,
      candidateIds: request.candidateIds.slice()
    })),
    maxActive: window.__llPublicCategoryRequests.maxActive,
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation
  }));
  expect(failedState.alerts).toEqual([]);
  expect(failedState.launches).toHaveLength(1);
  expect(failedState.initAttempts).toHaveLength(1);
  expect(failedState.appends).toHaveLength(0);
  expect(failedState.categories).toEqual(['Cat A', 'Cat B', 'Cat B', 'Cat C']);
  expect(failedState.requests.slice(-2).every((request) => (
    JSON.stringify(request.candidateIds) === JSON.stringify(fixture.secondChunkWordIds)
  ))).toBeTruthy();
  expect(failedState.maxActive).toBe(1);
  expect(failedState.continuationType).toBe('function');

  const acceptedContinuation = await invokeBoundedSessionContinuation(page);
  expect(acceptedContinuation.outcomes).toEqual([{
    status: 'fulfilled',
    value: { success: true, index: 1, chunk_count: 2 }
  }]);
  const result = await page.evaluate(() => ({
    launches: window.__llLaunches.slice(),
    appends: window.__llBoundedSessionAppends.slice(),
    categories: window.__llPublicCategoryRequests.categories.slice(),
    requests: window.__llPublicCategoryRequests.requests.map((request) => ({
      categoryName: request.categoryName,
      candidateIds: request.candidateIds.slice()
    })),
    maxActive: window.__llPublicCategoryRequests.maxActive,
    continuationType: typeof (window.llToolsFlashcardsData || {}).boundedSessionContinuation
  }));
  expect(result.launches).toHaveLength(1);
  expect(result.appends).toHaveLength(1);
  expect(result.appends[0].catNames.slice().sort()).toEqual(['Cat B', 'Cat C']);
  expect(result.appends[0].sessionWordIds).toEqual(fixture.secondChunkWordIds);
  expect(result.categories).toEqual(['Cat A', 'Cat B', 'Cat B', 'Cat C', 'Cat B', 'Cat C']);
  expect(result.requests.slice(-2).every((request) => (
    request.candidateIds.length === 15
      && JSON.stringify(request.candidateIds) === JSON.stringify(fixture.secondChunkWordIds)
  ))).toBeTruthy();
  expect(result.maxActive).toBe(1);
  expect(result.continuationType).toBe('undefined');
  await expect(page.locator('#quiz-results')).toBeHidden();
  await expect(page.locator('#ll-study-results-next-chunk')).toBeHidden();
});

test('bounded hydration rejects a declared category that owns no planned word', async ({ page }) => {
  const plannedRows = buildCategoryWordRows(11, 8, 'A').map((row) => Object.assign({}, row, {
    category_id: 11,
    category_ids: [11],
    status: 'studied'
  }));
  const plannedWordIds = plannedRows.map((row) => row.id);
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: {
      11: plannedRows,
      22: [],
      33: []
    },
    selectionLaunchPlan: {
      category_ids: [11, 22],
      word_ids: plannedWordIds,
      chunks: [{ category_ids: [11, 22], word_ids: plannedWordIds }],
      criteria: 'studied',
      mode: 'practice',
      matched_count: plannedWordIds.length,
      planned_count: plannedWordIds.length,
      chunk_count: 1,
      truncated: false
    },
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: plannedWordIds.length,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(0);
  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    categories: window.__llPublicCategoryRequests.categories.slice()
  }));
  expect(result.alerts).toEqual(['Something went wrong. Please try again.']);
  expect(result.categories).toEqual(['Cat A', 'Cat B']);
});

test('bounded hydration recognizes prompt-card answer and progress word identities', async ({ page }) => {
  const plannedAnswerWordIds = [1101, 1102, 1103, 1104, 1105, 1106, 1107, 1108];
  const promptCardRows = plannedAnswerWordIds.map((answerWordId, index) => ({
    id: 9101 + index,
    answer_word_id: answerWordId,
    progress_word_id: answerWordId,
    is_prompt_card: true,
    title: `Prompt ${index + 1}`,
    translation: `Answer ${index + 1}`,
    label: `Answer ${index + 1}`,
    audio: '',
    image: '',
    audio_files: [],
    status: 'studied'
  }));
  const selectionLaunchPlan = {
    category_ids: [11],
    word_ids: plannedAnswerWordIds,
    chunks: [{ category_ids: [11], word_ids: plannedAnswerWordIds }],
    criteria: 'studied',
    mode: 'practice',
    matched_count: plannedAnswerWordIds.length,
    planned_count: plannedAnswerWordIds.length,
    chunk_count: 1,
    truncated: false
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: {
      11: promptCardRows,
      22: [],
      33: []
    },
    selectionLaunchPlan,
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: plannedAnswerWordIds.length,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await startInProgressPracticeSelection(page);
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);
  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    launches: window.__llLaunches.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice(),
    firstCategoryData: Array.isArray(window.llToolsFlashcardsData.firstCategoryData)
      ? window.llToolsFlashcardsData.firstCategoryData.map((row) => ({
          id: Number(row && row.id) || 0,
          answerWordId: Number(row && row.answer_word_id) || 0,
          progressWordId: Number(row && row.progress_word_id) || 0
        }))
      : []
  }));

  expect(result.alerts).toEqual([]);
  expect(result.publicCategories).toEqual(['Cat A']);
  expect(result.launches[0].sessionWordIds).toEqual(plannedAnswerWordIds);
  expect(result.launches[0].boundedSelectionPlan).toBeTruthy();
  expect(result.launches[0].sessionWordIdsByCategoryId['11']).toEqual(plannedAnswerWordIds);
  expect(result.firstCategoryData).toHaveLength(plannedAnswerWordIds.length);
  expect(result.firstCategoryData.every((row) => row.id !== row.answerWordId)).toBeTruthy();
  expect(result.firstCategoryData.map((row) => row.answerWordId).sort((a, b) => a - b))
    .toEqual(plannedAnswerWordIds);
  expect(result.firstCategoryData.map((row) => row.progressWordId).sort((a, b) => a - b))
    .toEqual(plannedAnswerWordIds);
});

test('bounded starred prompt-card launch relies on the exact plan without wrapper-id star filtering', async ({ page }) => {
  const plannedAnswerWordIds = [1101, 1102, 1103, 1104, 1105, 1106, 1107, 1108];
  const promptCardRows = plannedAnswerWordIds.map((answerWordId, index) => ({
    id: 9101 + index,
    answer_word_id: answerWordId,
    progress_word_id: answerWordId,
    is_prompt_card: true,
    title: `Prompt ${index + 1}`,
    translation: `Answer ${index + 1}`,
    label: `Answer ${index + 1}`,
    audio: '',
    image: '',
    audio_files: [],
    status: 'studied'
  }));
  const selectionLaunchPlan = {
    category_ids: [11],
    word_ids: plannedAnswerWordIds,
    chunks: [{ category_ids: [11], word_ids: plannedAnswerWordIds }],
    criteria: 'starred',
    mode: 'practice',
    matched_count: plannedAnswerWordIds.length,
    planned_count: plannedAnswerWordIds.length,
    chunk_count: 1,
    truncated: false
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: {
      11: promptCardRows,
      22: [],
      33: []
    },
    selectionLaunchPlan,
    configPatch: {
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: plannedAnswerWordIds,
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
      summaryCounts: {
        mastered: 0,
        studied: plannedAnswerWordIds.length,
        new: 0,
        starred: plannedAnswerWordIds.length,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-starred-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();
  await expect.poll(async () => page.evaluate(() => window.__llLaunches.length)).toBe(1);

  const result = await page.evaluate(() => ({
    planRequests: window.__llSelectionPlanRequests.slice(),
    launches: window.__llLaunches.slice(),
    alerts: window.__llAlerts.slice()
  }));
  expect(result.alerts).toEqual([]);
  expect(result.planRequests).toHaveLength(1);
  expect(result.planRequests[0].criteria).toBe('starred');
  expect(result.launches[0].sessionWordIds).toEqual(plannedAnswerWordIds);
  expect(result.launches[0].sessionStarModeOverride).toBe('normal');
  expect(result.launches[0].sessionWordIdsByCategoryId['11']).toEqual(plannedAnswerWordIds);
});

test('bounded selection plans reject malformed metadata aliases categories and hard bounds', async ({ page }) => {
  const baseFixture = buildBoundedChunkFixture();
  const copyPlan = () => JSON.parse(JSON.stringify(baseFixture.selectionLaunchPlan));
  const overBoundWordIds = Array.from({ length: 31 }, (_, index) => 9001 + index);
  const scenarios = [
    {
      name: 'missing chunks contract',
      plan: (() => {
        const plan = copyPlan();
        delete plan.chunks;
        return plan;
      })()
    },
    {
      name: 'planned count mismatch',
      plan: Object.assign(copyPlan(), { planned_count: baseFixture.allPlannedWordIds.length - 1 })
    },
    {
      name: 'chunk count mismatch',
      plan: Object.assign(copyPlan(), { chunk_count: 1 })
    },
    {
      name: 'truncated plan',
      plan: Object.assign(copyPlan(), { truncated: true })
    },
    {
      name: 'top aliases differ from first chunk',
      plan: Object.assign(copyPlan(), { category_ids: [22, 11] })
    },
    {
      name: 'invalid category is not filtered out',
      plan: (() => {
        const plan = copyPlan();
        plan.chunks[1].category_ids = [22, 33, 999];
        return plan;
      })()
    },
    {
      name: 'word bound exceeded',
      plan: {
        category_ids: [11],
        word_ids: overBoundWordIds,
        chunks: [{ category_ids: [11], word_ids: overBoundWordIds }],
        criteria: 'studied',
        mode: 'practice',
        matched_count: overBoundWordIds.length,
        planned_count: overBoundWordIds.length,
        chunk_count: 1,
        truncated: false
      }
    },
    {
      name: 'category bound exceeded',
      plan: {
        category_ids: [11, 22, 33, 44, 55, 66, 77, 88, 99],
        word_ids: baseFixture.firstChunkWordIds,
        chunks: [{
          category_ids: [11, 22, 33, 44, 55, 66, 77, 88, 99],
          word_ids: baseFixture.firstChunkWordIds
        }],
        criteria: 'studied',
        mode: 'practice',
        matched_count: baseFixture.firstChunkWordIds.length,
        planned_count: baseFixture.firstChunkWordIds.length,
        chunk_count: 1,
        truncated: false
      }
    }
  ];

  for (const scenario of scenarios) {
    await test.step(scenario.name, async () => {
      await mountWordsetPage(page, {
        isLoggedIn: true,
        wordsByCategory: baseFixture.wordsByCategory,
        selectionLaunchPlan: scenario.plan,
        configPatch: baseFixture.configPatch
      });
      await startInProgressPracticeSelection(page);
      await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);
      const result = await page.evaluate(() => ({
        alerts: window.__llAlerts.slice(),
        launches: window.__llLaunches.slice(),
        publicCategories: window.__llPublicCategoryRequests.categories.slice(),
        initAttempts: window.__llInitAttempts.slice()
      }));
      expect(result.alerts).toEqual(['Something went wrong. Please try again.']);
      expect(result.launches).toEqual([]);
      expect(result.publicCategories).toEqual([]);
      expect(result.initAttempts).toEqual([]);
    });
  }
});

test('empty bounded selection plan keeps the criteria-specific no-matches message', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 8, 'A').map((row) => Object.assign({}, row, { status: 'studied' })),
    22: buildCategoryWordRows(22, 8, 'B').map((row) => Object.assign({}, row, { status: 'studied' })),
    33: buildCategoryWordRows(33, 8, 'C').map((row) => Object.assign({}, row, { status: 'new' }))
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    selectionLaunchPlan: {
      category_ids: [],
      word_ids: [],
      chunks: [],
      criteria: 'studied',
      mode: 'practice',
      matched_count: 0,
      planned_count: 0,
      chunk_count: 0,
      truncated: false
    },
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: 16,
        new: 8,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);
  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    launches: window.__llLaunches.slice(),
    planRequests: window.__llSelectionPlanRequests.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice(),
    popupDisplay: window.getComputedStyle(document.getElementById('ll-tools-flashcard-popup')).display,
    quizBusy: document.getElementById('ll-tools-flashcard-quiz-popup').getAttribute('aria-busy')
  }));

  expect(result.alerts).toEqual(['No in progress words are available for this selection.']);
  expect(result.launches).toEqual([]);
  expect(result.planRequests).toHaveLength(1);
  expect(result.publicCategories).toEqual([]);
  expect(result.popupDisplay).toBe('none');
  expect(result.quizBusy).toBeNull();
});

test('bounded plan below the quiz minimum keeps the no-matches path', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 8, 'A').map((row) => Object.assign({}, row, { status: 'studied' })),
    22: buildCategoryWordRows(22, 8, 'B').map((row) => Object.assign({}, row, { status: 'studied' })),
    33: buildCategoryWordRows(33, 8, 'C').map((row) => Object.assign({}, row, { status: 'new' }))
  };
  const belowMinimumWordIds = wordsByCategory[11].slice(0, 3).map((row) => row.id);

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    selectionLaunchPlan: {
      category_ids: [11],
      word_ids: belowMinimumWordIds,
      chunks: [
        { category_ids: [11], word_ids: belowMinimumWordIds }
      ],
      criteria: 'studied',
      mode: 'practice',
      matched_count: belowMinimumWordIds.length,
      planned_count: belowMinimumWordIds.length,
      chunk_count: 1,
      truncated: false
    },
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: 16,
        new: 8,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);
  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    launches: window.__llLaunches.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice()
  }));

  expect(result.alerts).toEqual(['No in progress words are available for this selection.']);
  expect(result.launches).toEqual([]);
  expect(result.publicCategories).toEqual([]);
});

test('malformed explicit bounded chunks still fail closed', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 8, 'A').map((row) => Object.assign({}, row, { status: 'studied' })),
    22: buildCategoryWordRows(22, 8, 'B').map((row) => Object.assign({}, row, { status: 'studied' })),
    33: buildCategoryWordRows(33, 8, 'C').map((row) => Object.assign({}, row, { status: 'new' }))
  };
  const firstChunkWordIds = wordsByCategory[11].slice(0, 5).map((row) => row.id);
  const secondChunkWordIds = [firstChunkWordIds[0]].concat(
    wordsByCategory[22].slice(0, 4).map((row) => row.id)
  );

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    selectionLaunchPlan: {
      category_ids: [11],
      word_ids: firstChunkWordIds,
      chunks: [
        { category_ids: [11], word_ids: firstChunkWordIds },
        { category_ids: [11, 22], word_ids: secondChunkWordIds }
      ],
      criteria: 'studied',
      mode: 'practice',
      matched_count: 9,
      planned_count: 9,
      chunk_count: 2,
      truncated: false
    },
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: 16,
        new: 8,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => page.evaluate(() => window.__llAlerts.length)).toBe(1);
  const result = await page.evaluate(() => ({
    alerts: window.__llAlerts.slice(),
    launches: window.__llLaunches.slice(),
    publicCategories: window.__llPublicCategoryRequests.categories.slice()
  }));

  expect(result.alerts).toEqual(['Something went wrong. Please try again.']);
  expect(result.launches).toEqual([]);
  expect(result.publicCategories).toEqual([]);
});

test('priority-only practice stops cleanly when the bounded launch plan is rate-limited', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 8, 'A').map((row) => Object.assign({}, row, {
      category_id: 11,
      category_ids: [11],
      status: 'studied',
      difficulty_score: 1
    })),
    22: buildCategoryWordRows(22, 8, 'B').map((row) => Object.assign({}, row, {
      category_id: 22,
      category_ids: [22],
      status: 'studied',
      difficulty_score: 1
    })),
    33: buildCategoryWordRows(33, 8, 'C').map((row) => Object.assign({}, row, {
      category_id: 33,
      category_ids: [33],
      status: 'new',
      difficulty_score: 0
    }))
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    selectionPlanFailureStatus: 429,
    configPatch: {
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [77],
        placement_known_category_ids: [],
        daily_new_word_target: 0,
        priority_focus: 'studied'
      },
      summaryCounts: {
        mastered: 0,
        studied: 16,
        new: 8,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await page.locator('[data-ll-wordset-selection-priority-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llAlerts) ? window.__llAlerts.length : 0);
  }).toBe(1);

  const result = await page.evaluate(() => {
    const popup = document.getElementById('ll-tools-flashcard-popup');
    const quizPopup = document.getElementById('ll-tools-flashcard-quiz-popup');
    const loader = document.getElementById('ll-tools-loading-animation');
    return {
      alerts: Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : [],
      launches: Array.isArray(window.__llLaunches) ? window.__llLaunches.slice() : [],
      planRequests: Array.isArray(window.__llSelectionPlanRequests) ? window.__llSelectionPlanRequests.slice() : [],
      publicRequests: window.__llPublicCategoryRequests,
      popupDisplay: popup ? window.getComputedStyle(popup).display : '',
      quizDisplay: quizPopup ? window.getComputedStyle(quizPopup).display : '',
      loaderDisplay: loader ? window.getComputedStyle(loader).display : '',
      quizBusy: quizPopup ? quizPopup.getAttribute('aria-busy') : ''
    };
  });

  expect(result.alerts).toEqual([
    'Something went wrong. Please try again.'
  ]);
  expect(result.launches).toEqual([]);
  expect(result.planRequests).toHaveLength(1);
  expect(result.publicRequests.categories).toEqual([]);
  expect(result.popupDisplay).toBe('none');
  expect(result.quizDisplay).toBe('none');
  expect(result.loaderDisplay).toBe('none');
  expect(result.quizBusy).toBeNull();
});

test('starred-only practice selection launches one bounded filtered activity', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 13, 'A'),
    22: buildCategoryWordRows(22, 12, 'B'),
    33: buildCategoryWordRows(33, 12, 'C')
  };
  const starredWordIds = Object.values(wordsByCategory)
    .flat()
    .map((row) => Number(row && row.id) || 0)
    .filter(Boolean);

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 13,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 12,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 12,
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
      visibleCategoryIds: [11, 22, 33],
      hiddenCategoryIds: [],
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: starredWordIds,
        star_mode: 'normal',
        fast_transitions: false
      },
      summaryCounts: {
        mastered: 0,
        studied: 0,
        new: 0,
        starred: starredWordIds.length,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  const selectionPracticeButton = page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]');
  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('.ll-wordset-selection-bar__starred-toggle')).toBeVisible();
  await page.locator('[data-ll-wordset-selection-starred-only]').check();
  await expect(selectionPracticeButton).toBeEnabled();
  await selectionPracticeButton.click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });
  const alerts = await page.evaluate(() => Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : []);

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_selection_bounded_start');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);
  expect(launch.sessionWordIds.length).toBe(15);
  expect(new Set(launch.sessionWordIds).size).toBe(15);
  expect(alerts).toEqual([]);
});

test('practice selection launches the full selected category scope', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 7, 'ImgA'),
    22: buildCategoryWordRows(22, 6, 'ImgB'),
    33: buildCategoryWordRows(33, 8, 'TxtC')
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 7,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 6,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 8,
          url: '#',
          mode: 'text',
          prompt_type: 'audio',
          option_type: 'text_translation',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'no-image',
          hidden: false,
          preview: []
        }
      ],
      summaryCounts: {
        mastered: 0,
        studied: 0,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  const selectionPracticeButton = page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]');
  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(selectionPracticeButton).toBeEnabled();
  await selectionPracticeButton.click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });
  const alerts = await page.evaluate(() => Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : []);

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_selection_start');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
  expect(launch.sessionWordIds).toEqual([]);
  expect(alerts).toEqual([]);
});

test('starred-only practice selection caps a multi-category activity at fifteen words', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 7, 'ImgA'),
    22: buildCategoryWordRows(22, 6, 'ImgB'),
    33: buildCategoryWordRows(33, 4, 'TxtC')
  };
  const starredWordIds = Object.values(wordsByCategory)
    .flat()
    .map((row) => Number(row && row.id) || 0)
    .filter(Boolean);

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 7,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 6,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 4,
          url: '#',
          mode: 'text',
          prompt_type: 'audio',
          option_type: 'text_translation',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'no-image',
          hidden: false,
          preview: []
        }
      ],
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: starredWordIds,
        star_mode: 'normal',
        fast_transitions: false
      },
      summaryCounts: {
        mastered: 0,
        studied: 0,
        new: 0,
        starred: starredWordIds.length,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  const selectionPracticeButton = page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]');
  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('.ll-wordset-selection-bar__starred-toggle')).toBeVisible();
  await page.locator('[data-ll-wordset-selection-starred-only]').check();
  await expect(selectionPracticeButton).toBeEnabled();
  await selectionPracticeButton.click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });
  const alerts = await page.evaluate(() => Array.isArray(window.__llAlerts) ? window.__llAlerts.slice() : []);

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.source).toBe('wordset_selection_bounded_start');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22, 33]);
  expect(launch.sessionWordIds.length).toBe(15);
  expect(alerts).toEqual([]);
});

test('practice selection keeps category titles visible for small multi-category runs', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 4, 'MixA'),
    22: buildCategoryWordRows(22, 4, 'MixB')
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 4,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 4,
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
      summaryCounts: {
        mastered: 0,
        studied: 0,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select][value="11"]').check();
  await page.locator('[data-ll-wordset-select][value="22"]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });
  const flashState = await page.evaluate(() => {
    const flash = window.llToolsFlashcardsData || {};
    return {
      hideCategoryDisplay: !!(flash.hideCategoryDisplay || flash.hide_category_display),
      categoryDisplayOverride: String(flash.categoryDisplayOverride || flash.category_display_override || '')
    };
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('practice');
  expect(launch.categoryIds.slice().sort((a, b) => a - b)).toEqual([11, 22]);
  expect(launch.sessionWordIds).toEqual([]);
  expect(launch.hideCategoryDisplay).toBeFalsy();
  expect(flashState.hideCategoryDisplay).toBeFalsy();
  expect(flashState.categoryDisplayOverride).toBe('');
});

test('selection shows hard-only only when at least five hard words are in selected categories', async ({ page }) => {
  const hardToggle = page.locator('.ll-wordset-selection-bar__hard-toggle');

  await mountWordsetPage(page, {
    isLoggedIn: true,
    configPatch: {
      analytics: {
        scope: {},
        summary: {},
        daily_activity: { days: [], max_events: 0, window_days: 14 },
        categories: [],
        words: buildAnalyticsWordsWithHardCount(4)
      }
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(hardToggle).toBeHidden();

  await mountWordsetPage(page, {
    isLoggedIn: true,
    configPatch: {
      analytics: {
        scope: {},
        summary: {},
        daily_activity: { days: [], max_events: 0, window_days: 14 },
        categories: [],
        words: buildAnalyticsWordsWithHardCount(5)
      }
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(hardToggle).toBeVisible();
});

test('selection launch clears checked categories as soon as the flashcard popup opens', async ({ page }) => {
  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory: {
      11: buildCategoryWordRows(11, 3, 'OpenA'),
      22: buildCategoryWordRows(22, 3, 'OpenB'),
      33: buildCategoryWordRows(33, 1, 'OpenC')
    },
    configPatch: {
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
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
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
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 1,
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
    }
  });

  const selectionBar = page.locator('[data-ll-wordset-selection-bar]');
  const catA = page.locator('[data-ll-wordset-select][value="11"]');
  const catB = page.locator('[data-ll-wordset-select][value="22"]');

  await catA.check();
  await catB.check();
  await expect(selectionBar).toBeVisible();

  await page.locator('[data-ll-wordset-selection-mode][data-mode="practice"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:flashcard-opened', [{
      mode: 'practice',
      categories: ['Cat A', 'Cat B']
    }]);
  });

  await expect(selectionBar).toBeHidden();
  await expect(catA).not.toBeChecked();
  await expect(catB).not.toBeChecked();
});

test('learning starred selection mixes only compatible categories and fills to eight words', async ({ page }) => {
  const wordsByCategory = {
    11: [
      { id: 1101, title: 'A1', translation: 'A1', label: 'A1', audio: '', image: '', audio_files: [] },
      { id: 1102, title: 'A2', translation: 'A2', label: 'A2', audio: '', image: '', audio_files: [] },
      { id: 1103, title: 'A3', translation: 'A3', label: 'A3', audio: '', image: '', audio_files: [] },
      { id: 1104, title: 'A4', translation: 'A4', label: 'A4', audio: '', image: '', audio_files: [] },
      { id: 1105, title: 'A5', translation: 'A5', label: 'A5', audio: '', image: '', audio_files: [] },
      { id: 1106, title: 'A6', translation: 'A6', label: 'A6', audio: '', image: '', audio_files: [] },
      { id: 1107, title: 'A7', translation: 'A7', label: 'A7', audio: '', image: '', audio_files: [] },
      { id: 1108, title: 'A8', translation: 'A8', label: 'A8', audio: '', image: '', audio_files: [] },
      { id: 1109, title: 'A9', translation: 'A9', label: 'A9', audio: '', image: '', audio_files: [] },
      { id: 1110, title: 'A10', translation: 'A10', label: 'A10', audio: '', image: '', audio_files: [] }
    ],
    22: [
      { id: 2201, title: 'B1', translation: 'B1', label: 'B1', audio: '', image: '', audio_files: [] },
      { id: 2202, title: 'B2', translation: 'B2', label: 'B2', audio: '', image: '', audio_files: [] },
      { id: 2203, title: 'B3', translation: 'B3', label: 'B3', audio: '', image: '', audio_files: [] },
      { id: 2204, title: 'B4', translation: 'B4', label: 'B4', audio: '', image: '', audio_files: [] },
      { id: 2205, title: 'B5', translation: 'B5', label: 'B5', audio: '', image: '', audio_files: [] },
      { id: 2206, title: 'B6', translation: 'B6', label: 'B6', audio: '', image: '', audio_files: [] },
      { id: 2207, title: 'B7', translation: 'B7', label: 'B7', audio: '', image: '', audio_files: [] },
      { id: 2208, title: 'B8', translation: 'B8', label: 'B8', audio: '', image: '', audio_files: [] },
      { id: 2209, title: 'B9', translation: 'B9', label: 'B9', audio: '', image: '', audio_files: [] },
      { id: 2210, title: 'B10', translation: 'B10', label: 'B10', audio: '', image: '', audio_files: [] }
    ],
    33: [
      { id: 3301, title: 'C1', translation: 'C1', label: 'C1', audio: '', image: '', audio_files: [] },
      { id: 3302, title: 'C2', translation: 'C2', label: 'C2', audio: '', image: '', audio_files: [] }
    ]
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 30,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:16_9',
          hidden: false,
          preview: []
        },
        {
          id: 33,
          slug: 'cat-c',
          name: 'Cat C',
          translation: 'Cat C',
          count: 10,
          url: '#',
          mode: 'text',
          prompt_type: 'audio',
          option_type: 'text_translation',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        }
      ],
      state: {
        wordset_id: 77,
        category_ids: [],
        starred_word_ids: [1101, 1102, 1103, 1104, 1105, 1106, 2201, 2202, 2203, 2204],
        star_mode: 'normal',
        fast_transitions: false
      },
      analytics: {
        scope: {},
        summary: {},
        daily_activity: { days: [], max_events: 0, window_days: 14 },
        categories: [],
        words: [
          { id: 1101, title: 'A1', translation: 'A1', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 1102, title: 'A2', translation: 'A2', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 1103, title: 'A3', translation: 'A3', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 1104, title: 'A4', translation: 'A4', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 1105, title: 'A5', translation: 'A5', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 1106, title: 'A6', translation: 'A6', category_id: 11, category_ids: [11], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 2201, title: 'B1', translation: 'B1', category_id: 22, category_ids: [22], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 2202, title: 'B2', translation: 'B2', category_id: 22, category_ids: [22], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 2203, title: 'B3', translation: 'B3', category_id: 22, category_ids: [22], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true },
          { id: 2204, title: 'B4', translation: 'B4', category_id: 22, category_ids: [22], status: 'studied', difficulty_score: 0, total_coverage: 1, incorrect: 0, lapse_count: 0, last_seen_at: '', is_starred: true }
        ]
      }
    }
  });

  await page.locator('[data-ll-wordset-select-all]').click();
  await expect(page.locator('.ll-wordset-selection-bar__starred-toggle')).toBeVisible();
  await page.locator('[data-ll-wordset-selection-starred-only]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="learning"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('learning');
  expect(launch.categoryIds).toEqual([11]);
  expect(launch.sessionWordIds.length).toBeGreaterThanOrEqual(8);
  expect(launch.sessionWordIds.every((id) => id >= 1101 && id <= 1110)).toBeTruthy();
  expect(launch.sessionWordIds.some((id) => id > 1106)).toBeTruthy();

  const categoryDisplayOverride = await page.evaluate(() => {
    const flash = window.llToolsFlashcardsData || {};
    return String(flash.categoryDisplayOverride || flash.category_display_override || '');
  });
  expect(categoryDisplayOverride).toBe('Starred words');
});

test('learning selection prefers a single compatible category when one category can satisfy the quiz alone', async ({ page }) => {
  const wordsByCategory = {
    11: buildCategoryWordRows(11, 7, 'LearnA'),
    22: buildCategoryWordRows(22, 10, 'LearnB')
  };

  await mountWordsetPage(page, {
    isLoggedIn: true,
    wordsByCategory,
    configPatch: {
      categories: [
        {
          id: 11,
          slug: 'cat-a',
          name: 'Cat A',
          translation: 'Cat A',
          count: 7,
          url: '#',
          mode: 'image',
          prompt_type: 'audio',
          option_type: 'image',
          learning_supported: true,
          gender_supported: false,
          aspect_bucket: 'ratio:1_1',
          hidden: false,
          preview: []
        },
        {
          id: 22,
          slug: 'cat-b',
          name: 'Cat B',
          translation: 'Cat B',
          count: 10,
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
      summaryCounts: {
        mastered: 0,
        studied: 0,
        new: 0,
        starred: 0,
        hard: 0
      },
      nextActivity: null,
      recommendationQueue: []
    }
  });

  await page.locator('[data-ll-wordset-select][value="11"]').check();
  await page.locator('[data-ll-wordset-select][value="22"]').check();
  await page.locator('[data-ll-wordset-selection-mode][data-mode="learning"]').click();

  await expect.poll(async () => {
    return page.evaluate(() => Array.isArray(window.__llLaunches) ? window.__llLaunches.length : 0);
  }).toBe(1);

  const launch = await page.evaluate(() => {
    const launches = Array.isArray(window.__llLaunches) ? window.__llLaunches : [];
    return launches.length ? launches[launches.length - 1] : null;
  });
  const flashState = await page.evaluate(() => {
    const flash = window.llToolsFlashcardsData || {};
    return {
      hideCategoryDisplay: !!(flash.hideCategoryDisplay || flash.hide_category_display),
      categoryDisplayOverride: String(flash.categoryDisplayOverride || flash.category_display_override || '')
    };
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('learning');
  expect(launch.categoryIds).toEqual([22]);
  expect(launch.sessionWordIds.length).toBeGreaterThanOrEqual(8);
  expect(launch.sessionWordIds.every((id) => id >= 2201 && id <= 2210)).toBeTruthy();
  expect(launch.hideCategoryDisplay).toBeFalsy();
  expect(flashState.hideCategoryDisplay).toBeFalsy();
  expect(flashState.categoryDisplayOverride).toBe('');
});
