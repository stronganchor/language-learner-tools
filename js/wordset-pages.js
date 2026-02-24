(function ($) {
    'use strict';

    const cfg = (window.llWordsetPageData && typeof window.llWordsetPageData === 'object')
        ? window.llWordsetPageData
        : {};

    const $root = $('[data-ll-wordset-page]');
    if (!$root.length) { return; }

    const view = String(cfg.view || 'main');
    const ajaxUrl = String(cfg.ajaxUrl || '');
    const nonce = String(cfg.nonce || '');
    const isLoggedIn = !!cfg.isLoggedIn;
    const wordsetId = parseInt(cfg.wordsetId, 10) || 0;
    const wordsetSlug = String(cfg.wordsetSlug || '');
    const modeUi = (cfg.modeUi && typeof cfg.modeUi === 'object') ? cfg.modeUi : {};
    const i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};
    const genderCfg = (cfg.gender && typeof cfg.gender === 'object') ? cfg.gender : {};
    const progressResetCfg = (cfg.progressReset && typeof cfg.progressReset === 'object') ? cfg.progressReset : {};
    const progressIncludeHidden = !!cfg.progressIncludeHidden;
    const sortLocales = buildSortLocales(cfg.sortLocale || document.documentElement.lang || '');
    const turkishSortLocales = withTurkishSortLocales(sortLocales);

    const CHUNK_SIZE = 15;
    const LEARNING_MIN_CHUNK_SIZE = Math.max(8, parseInt(cfg.learningMinChunkSize, 10) || 8);
    const HARD_WORD_DIFFICULTY_THRESHOLD = Math.max(1, parseInt(cfg.hardWordDifficultyThreshold, 10) || 4);
    const RESULTS_FOLLOWUP_PREFETCH_PROGRESS_RATIO = 0.8;
    const RESULTS_FOLLOWUP_PREFETCH_UNKNOWN_TOTAL_TRIGGER = 8;

    let categories = normalizeCategories(cfg.categories || []);
    let goals = normalizeGoals(cfg.goals || {});
    let state = normalizeState(cfg.state || {});
    let nextActivity = normalizeNextActivity(cfg.nextActivity || null);
    let recommendationQueue = normalizeRecommendationQueue(cfg.recommendationQueue || []);
    let summaryCounts = normalizeSummaryCounts(cfg.summaryCounts || {});
    let analytics = normalizeAnalytics(cfg.analytics || null);
    let summaryMetricsLoading = !!cfg.summaryCountsDeferred;
    let summaryMetricsLoadingToken = 0;
    let selectedCategoryIds = [];
    let selectionStarredOnly = false;
    let selectionHardOnly = false;
    let wordsByCategory = {};
    let saveStateTimer = null;
    let goalsSaveRequestToken = 0;
    let analyticsTimer = null;
    let analyticsRequestToken = 0;
    let analyticsTab = 'categories';
    const progressTabStorageKey = 'llToolsWordsetProgressTab:' + String(wordsetId || 0);
    let analyticsWordSearchQuery = '';
    let analyticsCategorySearchQuery = '';
    let analyticsWordSort = { key: '', direction: '' };
    let analyticsCategorySort = { key: '', direction: '' };
    let analyticsSummaryFilter = '';
    let analyticsWordColumnFilters = {
        star: [],
        status: [],
        last: [],
        difficulty: [],
        seen: [],
        wrong: []
    };
    let analyticsWordCategoryFilterIds = [];
    let progressSelectedWordIds = [];
    let analyticsWordRenderTimer = null;
    let analyticsWordLoadingTimer = null;
    let analyticsWordRenderToken = 0;
    let analyticsCategoryRenderTimer = null;
    let analyticsCategoryLoadingTimer = null;
    let analyticsCategoryRenderToken = 0;
    let chunkSession = null;
    let lastFlashcardLaunch = null;
    let resultsFollowupPrefetchState = null;
    let resultsFollowupRefreshToken = 0;
    let queueItemWidthTimer = null;
    let selectAllAlignmentTimer = null;
    let hasInitialSelectAllAlignment = false;
    let progressKpiFeedbackTimer = null;
    let progressWordTableFxTimer = null;
    let nextCardRevealTimer = null;
    let nextCardWidthReleaseTimer = null;
    let progressMiniCountRaf = 0;
    let progressMiniCountCleanupTimer = null;
    let progressMiniCountPendingComplete = null;
    let progressMiniStickyHostEl = null;
    let progressMiniStickyRaf = 0;
    let progressMiniStickyHoldTimer = 0;
    let progressMiniStickyReleaseTimer = 0;
    let progressMiniStickyReleaseFinalize = null;
    let progressMiniStickySession = null;

    function protectMaqafNoBreak(value) {
        const text = (value === null || value === undefined) ? '' : String(value);
        if (!text) { return ''; }
        if (text.indexOf('\u05BE') === -1 && text.indexOf('\u2060') === -1) { return text; }
        return text.replace(/\u2060*\u05BE\u2060*/gu, '\u2060\u05BE\u2060');
    }
    let progressMiniBurstCleanupTimer = null;
    let progressMiniCountToken = 0;
    let isFlashcardOpen = false;
    let pendingSummaryRefreshAfterClose = false;
    let pendingPerfectCelebration = false;
    let pendingPerfectCelebrationAt = 0;
    let categoryProgressVisibilityObserver = null;
    let categoryProgressVisibilityRaf = 0;
    let categoryProgressPostMetricsTimer = 0;
    let categoryProgressHoldForMetrics = false;
    let categoryProgressFallbackBound = false;
    let categoryProgressVisibilityEventBound = false;
    const pendingCategoryProgressUpdates = {};

    const SUMMARY_COUNT_KEYS = ['mastered', 'studied', 'new', 'starred', 'hard'];
    const PERFECT_CELEBRATION_MAX_AGE_MS = 15000;
    const CATEGORY_PROGRESS_ANIMATION_DURATION_MS = 1520;
    const CATEGORY_PROGRESS_CENTER_BAND_RATIO = 0.3;
    const CATEGORY_PROGRESS_CENTER_MIN_BAND_PX = 120;
    const CATEGORY_PROGRESS_POST_METRICS_DELAY_MS = 650;
    const PROGRESS_MINI_STICKY_HOLD_AFTER_ANIMATION_MS = 1500;

    const $nextCard = $root.find('[data-ll-wordset-next]');
    const $nextShell = $root.find('[data-ll-wordset-next-shell]');
    const $nextText = $root.find('[data-ll-wordset-next-text]');
    const $nextIcon = $root.find('[data-ll-wordset-next-icon]');
    const $nextPreview = $root.find('[data-ll-wordset-next-preview]');
    const $nextCount = $root.find('[data-ll-wordset-next-count]');
    const $nextRemove = $root.find('[data-ll-wordset-next-remove]');
    const $topModeButtons = $root.find('[data-ll-wordset-start-mode]');
    const $grid = $root.find('.ll-wordset-grid').first();
    const $selectAllButton = $root.find('[data-ll-wordset-select-all]');
    const $selectionBar = $root.find('[data-ll-wordset-selection-bar]');
    const $selectionText = $root.find('[data-ll-wordset-selection-text]');
    const $selectionStarredToggle = $root.find('[data-ll-wordset-selection-starred-only]');
    const $selectionStarredIcon = $root.find('[data-ll-wordset-selection-starred-icon]');
    const $selectionStarredLabel = $root.find('[data-ll-wordset-selection-starred-label]');
    const $selectionStarredWrap = $selectionStarredToggle.closest('.ll-wordset-selection-bar__starred-toggle');
    const $selectionHardToggle = $root.find('[data-ll-wordset-selection-hard-only]');
    const $selectionHardIcon = $root.find('[data-ll-wordset-selection-hard-icon]');
    const $selectionHardLabel = $root.find('[data-ll-wordset-selection-hard-label]');
    const $selectionHardWrap = $selectionHardToggle.closest('.ll-wordset-selection-bar__hard-toggle');
    const $miniMastered = $root.find('[data-ll-progress-mini-mastered]');
    const $miniStudied = $root.find('[data-ll-progress-mini-studied]');
    const $miniNew = $root.find('[data-ll-progress-mini-new]');
    const $miniStarred = $root.find('[data-ll-progress-mini-starred]');
    const $miniHard = $root.find('[data-ll-progress-mini-hard]');
    const $progressMiniChip = $root.find('[data-ll-wordset-progress-mini-root]').first();
    const $hiddenCount = $root.find('[data-ll-wordset-hidden-count]');
    const $hiddenLink = $root.find('[data-ll-wordset-hidden-link]');
    const $progressRoot = $root.find('[data-ll-wordset-progress-root]');
    const $progressScope = $root.find('[data-ll-wordset-progress-scope]');
    const $progressStatus = $root.find('[data-ll-wordset-progress-status]');
    const $progressSummary = $root.find('[data-ll-wordset-progress-summary]');
    const $progressGraph = $root.find('[data-ll-wordset-progress-graph]');
    const $progressTabButtons = $root.find('[data-ll-wordset-progress-tab]');
    const $progressPanels = $root.find('[data-ll-wordset-progress-panel]');
    const $progressCategoryRows = $root.find('[data-ll-wordset-progress-categories-body]');
    const $progressWordRows = $root.find('[data-ll-wordset-progress-words-body]');
    const $progressWordSearchInput = $root.find('[data-ll-wordset-progress-search]');
    const $progressWordSearchLoading = $root.find('[data-ll-wordset-progress-search-loading]');
    const $progressClearFiltersButton = $root.find('[data-ll-wordset-progress-clear-filters]');
    const $progressSelectAllButton = $root.find('[data-ll-wordset-progress-select-all]');
    const $progressCategorySearchInput = $root.find('[data-ll-wordset-progress-category-search]');
    const $progressCategorySearchLoading = $root.find('[data-ll-wordset-progress-category-search-loading]');
    const $progressWordColumnFilterOptions = $root.find('[data-ll-wordset-progress-column-filter-options]');
    const $progressCategoryFilterOptions = $root.find('[data-ll-wordset-progress-category-filter-options]');
    const $progressFilterTriggers = $root.find('[data-ll-wordset-progress-filter-trigger]');
    const $progressFilterPops = $root.find('[data-ll-wordset-progress-filter-pop]');
    const $progressSortButtons = $root.find('[data-ll-wordset-progress-sort]');
    const $progressSortHeaders = $root.find('[data-ll-wordset-progress-sort-th]');
    const $progressCategorySortButtons = $root.find('[data-ll-wordset-progress-category-sort]');
    const $progressCategorySortHeaders = $root.find('[data-ll-wordset-progress-category-sort-th]');
    const $progressTableWraps = $root.find('.ll-wordset-progress-table-wrap');
    const $progressWordTableWrap = $root.find('[data-ll-wordset-progress-panel="words"] .ll-wordset-progress-table-wrap').first();
    const $progressSelectionBar = $root.find('[data-ll-wordset-progress-selection-bar]');
    const $progressSelectionCount = $root.find('[data-ll-wordset-progress-selection-count]');
    const $progressSelectionModeButtons = $root.find('[data-ll-wordset-progress-selection-mode]');
    const $progressSelectionClear = $root.find('[data-ll-wordset-progress-selection-clear]');
    const $settingsQueueList = $root.find('[data-ll-wordset-queue-list]');
    const $settingsQueueEmpty = $root.find('[data-ll-wordset-queue-empty]');
    const WORDSET_THUMB_IMAGE_SELECTOR = [
        '.ll-wordset-preview-item--image img',
        '.ll-wordset-queue-thumb--image img',
        '.ll-wordset-next-thumb--image img',
        '.ll-wordset-progress-word-thumb img',
        '.ll-wordset-progress-category-thumb.is-image img'
    ].join(', ');
    const WORDSET_THUMB_IMAGE_WRAPPER_SELECTOR = [
        '.ll-wordset-preview-item--image',
        '.ll-wordset-queue-thumb--image',
        '.ll-wordset-next-thumb--image',
        '.ll-wordset-progress-word-thumb',
        '.ll-wordset-progress-category-thumb.is-image'
    ].join(', ');
    let wordsetThumbImageObserver = null;

    function getWordsetThumbImageWrapper(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG' || !img.closest) { return null; }
        return img.closest(WORDSET_THUMB_IMAGE_WRAPPER_SELECTOR);
    }

    function setWordsetThumbImagePending(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        img.classList.remove('ll-image-loaded');
        img.classList.add('ll-image-load-pending');
        const wrapper = getWordsetThumbImageWrapper(img);
        if (wrapper) {
            wrapper.classList.remove('ll-image-loaded');
            wrapper.classList.add('ll-image-load-pending');
        }
    }

    function markWordsetThumbImageLoaded(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        img.classList.remove('ll-image-load-pending');
        img.classList.add('ll-image-loaded');
        const wrapper = getWordsetThumbImageWrapper(img);
        if (wrapper) {
            wrapper.classList.remove('ll-image-load-pending');
            wrapper.classList.add('ll-image-loaded');
        }
    }

    function bindWordsetThumbImageLoadState(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        if (img.dataset.llImgLoadBound === '1') {
            if (img.complete) {
                markWordsetThumbImageLoaded(img);
            }
            return;
        }
        img.dataset.llImgLoadBound = '1';
        if (img.complete) {
            markWordsetThumbImageLoaded(img);
            return;
        }
        setWordsetThumbImagePending(img);
        const finish = function () {
            markWordsetThumbImageLoaded(img);
        };
        img.addEventListener('load', finish, { once: true });
        img.addEventListener('error', finish, { once: true });
    }

    function scanWordsetThumbImages(rootNode) {
        if (!rootNode || rootNode.nodeType !== 1) { return; }
        if (rootNode.matches && rootNode.matches(WORDSET_THUMB_IMAGE_SELECTOR)) {
            bindWordsetThumbImageLoadState(rootNode);
        }
        if (rootNode.querySelectorAll) {
            rootNode.querySelectorAll(WORDSET_THUMB_IMAGE_SELECTOR).forEach(bindWordsetThumbImageLoadState);
        }
    }

    function initWordsetThumbImageLoadingState() {
        scanWordsetThumbImages($root[0]);
        if (!window.MutationObserver) { return; }
        wordsetThumbImageObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (!mutation || !mutation.addedNodes) { return; }
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) { return; }
                    scanWordsetThumbImages(node);
                });
            });
        });
        wordsetThumbImageObserver.observe($root[0], { childList: true, subtree: true });
    }

    initWordsetThumbImageLoadingState();

    function normalizeCategories(raw) {
        return (Array.isArray(raw) ? raw : []).map(function (cat) {
            const id = parseInt(cat && cat.id, 10) || 0;
            if (!id) { return null; }
            return {
                id: id,
                slug: String((cat && cat.slug) || ''),
                name: String((cat && cat.name) || ''),
                translation: String((cat && cat.translation) || ''),
                aspect_bucket: String((cat && cat.aspect_bucket) || 'no-image') || 'no-image',
                count: Math.max(0, parseInt(cat && cat.count, 10) || 0),
                url: String((cat && cat.url) || ''),
                mode: String((cat && cat.mode) || 'image'),
                prompt_type: String((cat && cat.prompt_type) || 'audio'),
                option_type: String((cat && cat.option_type) || 'image'),
                learning_supported: cat && Object.prototype.hasOwnProperty.call(cat, 'learning_supported')
                    ? !!cat.learning_supported
                    : true,
                gender_supported: !!(cat && cat.gender_supported),
                hidden: !!(cat && cat.hidden),
                preview: normalizeCategoryPreview(cat && cat.preview)
            };
        }).filter(Boolean);
    }

    function normalizeCategoryPreview(raw) {
        return (Array.isArray(raw) ? raw : []).slice(0, 2).map(function (item) {
            const source = (item && typeof item === 'object') ? item : {};
            const type = String(source.type || '').toLowerCase() === 'image' ? 'image' : 'text';
            if (type === 'image') {
                return {
                    type: 'image',
                    url: String(source.url || ''),
                    alt: String(source.alt || '')
                };
            }
            return {
                type: 'text',
                label: String(source.label || '')
            };
        });
    }

    function normalizeGoals(raw) {
        const source = (raw && typeof raw === 'object') ? raw : {};
        const priorityFocus = normalizePriorityFocus(
            Object.prototype.hasOwnProperty.call(source, 'priority_focus')
                ? source.priority_focus
                : (
                    source.prioritize_new_words ? 'new'
                        : (source.prioritize_studied_words ? 'studied'
                            : (source.prioritize_learned_words ? 'learned'
                                : (source.prefer_starred_words ? 'starred'
                                    : (source.prefer_hard_words ? 'hard' : ''))))
                )
        );
        return {
            enabled_modes: uniqueModeList(source.enabled_modes || ['learning', 'practice', 'listening', 'gender', 'self-check']),
            ignored_category_ids: uniqueIntList(source.ignored_category_ids || []),
            preferred_wordset_ids: uniqueIntList(source.preferred_wordset_ids || []),
            placement_known_category_ids: uniqueIntList(source.placement_known_category_ids || []),
            daily_new_word_target: Math.max(0, Math.min(12, parseInt(source.daily_new_word_target, 10) || 0)),
            priority_focus: priorityFocus,
            prioritize_new_words: priorityFocus === 'new',
            prioritize_studied_words: priorityFocus === 'studied',
            prioritize_learned_words: priorityFocus === 'learned',
            prefer_starred_words: priorityFocus === 'starred',
            prefer_hard_words: priorityFocus === 'hard'
        };
    }

    function normalizePriorityFocus(value) {
        const key = String(value || '').trim().toLowerCase();
        if (key === 'new' || key === 'studied' || key === 'learned' || key === 'starred' || key === 'hard') {
            return key;
        }
        return '';
    }

    function normalizeState(raw) {
        const source = (raw && typeof raw === 'object') ? raw : {};
        return {
            wordset_id: parseInt(source.wordset_id, 10) || wordsetId,
            category_ids: uniqueIntList(source.category_ids || []),
            starred_word_ids: uniqueIntList(source.starred_word_ids || []),
            star_mode: normalizeStarMode(source.star_mode || source.starMode || 'normal'),
            fast_transitions: !!source.fast_transitions
        };
    }

    function normalizeSummaryCounts(raw) {
        const source = (raw && typeof raw === 'object') ? raw : {};
        return {
            mastered: Math.max(0, parseInt(source.mastered, 10) || 0),
            studied: Math.max(0, parseInt(source.studied, 10) || 0),
            new: Math.max(0, parseInt(source.new, 10) || 0),
            starred: Math.max(0, parseInt(source.starred, 10) || 0),
            hard: Math.max(0, parseInt(source.hard, 10) || 0)
        };
    }

    function normalizeAnalytics(raw) {
        const src = (raw && typeof raw === 'object') ? raw : {};
        const scopeRaw = (src.scope && typeof src.scope === 'object') ? src.scope : {};
        const summaryRaw = (src.summary && typeof src.summary === 'object') ? src.summary : {};
        const dailyRaw = (src.daily_activity && typeof src.daily_activity === 'object') ? src.daily_activity : {};
        const categoriesRaw = Array.isArray(src.categories) ? src.categories : [];
        const wordsRaw = Array.isArray(src.words) ? src.words : [];

        const dailyDays = (Array.isArray(dailyRaw.days) ? dailyRaw.days : []).map(function (entry) {
            const row = (entry && typeof entry === 'object') ? entry : {};
            return {
                date: String(row.date || ''),
                events: Math.max(0, parseInt(row.events, 10) || 0),
                unique_words: Math.max(0, parseInt(row.unique_words, 10) || 0),
                outcomes: Math.max(0, parseInt(row.outcomes, 10) || 0)
            };
        });

        const categories = categoriesRaw.map(function (entry) {
            const row = (entry && typeof entry === 'object') ? entry : {};
            const byModeRaw = (row.exposure_by_mode && typeof row.exposure_by_mode === 'object') ? row.exposure_by_mode : {};
            return {
                id: parseInt(row.id, 10) || 0,
                label: String(row.label || ''),
                word_count: Math.max(0, parseInt(row.word_count, 10) || 0),
                studied_words: Math.max(0, parseInt(row.studied_words, 10) || 0),
                mastered_words: Math.max(0, parseInt(row.mastered_words, 10) || 0),
                new_words: Math.max(0, parseInt(row.new_words, 10) || 0),
                exposure_total: Math.max(0, parseInt(row.exposure_total, 10) || 0),
                exposure_by_mode: {
                    learning: Math.max(0, parseInt(byModeRaw.learning, 10) || 0),
                    practice: Math.max(0, parseInt(byModeRaw.practice, 10) || 0),
                    listening: Math.max(0, parseInt(byModeRaw.listening, 10) || 0),
                    gender: Math.max(0, parseInt(byModeRaw.gender, 10) || 0),
                    'self-check': Math.max(0, parseInt(byModeRaw['self-check'], 10) || 0)
                },
                last_seen_at: String(row.last_seen_at || ''),
                last_mode: normalizeMode(row.last_mode) || 'practice'
            };
        }).filter(function (row) {
            return row.id > 0;
        });

        const words = wordsRaw.map(function (entry) {
            const row = (entry && typeof entry === 'object') ? entry : {};
            const status = String(row.status || 'new');
            const normalizedStatus = (status === 'mastered' || status === 'studied' || status === 'new') ? status : 'new';
            return {
                id: parseInt(row.id, 10) || 0,
                title: String(row.title || ''),
                translation: String(row.translation || ''),
                image: String(row.image || ''),
                category_id: parseInt(row.category_id, 10) || 0,
                category_label: String(row.category_label || ''),
                category_ids: uniqueIntList(row.category_ids || []),
                category_labels: Array.isArray(row.category_labels) ? row.category_labels.map(function (label) { return String(label || ''); }).filter(Boolean) : [],
                status: normalizedStatus,
                difficulty_score: parseInt(row.difficulty_score, 10) || 0,
                total_coverage: Math.max(0, parseInt(row.total_coverage, 10) || 0),
                incorrect: Math.max(0, parseInt(row.incorrect, 10) || 0),
                lapse_count: Math.max(0, parseInt(row.lapse_count, 10) || 0),
                last_seen_at: String(row.last_seen_at || ''),
                is_starred: !!row.is_starred
            };
        }).filter(function (row) {
            return row.id > 0;
        });

        return {
            scope: {
                wordset_id: parseInt(scopeRaw.wordset_id, 10) || 0,
                category_ids: uniqueIntList(scopeRaw.category_ids || []),
                category_count: Math.max(0, parseInt(scopeRaw.category_count, 10) || 0),
                mode: (scopeRaw.mode === 'selected') ? 'selected' : 'all'
            },
            summary: {
                total_words: Math.max(0, parseInt(summaryRaw.total_words, 10) || 0),
                mastered_words: Math.max(0, parseInt(summaryRaw.mastered_words, 10) || 0),
                studied_words: Math.max(0, parseInt(summaryRaw.studied_words, 10) || 0),
                new_words: Math.max(0, parseInt(summaryRaw.new_words, 10) || 0),
                hard_words: Math.max(0, parseInt(summaryRaw.hard_words, 10) || 0),
                starred_words: Math.max(0, parseInt(summaryRaw.starred_words, 10) || 0)
            },
            daily_activity: {
                days: dailyDays,
                max_events: Math.max(0, parseInt(dailyRaw.max_events, 10) || 0),
                window_days: Math.max(0, parseInt(dailyRaw.window_days, 10) || dailyDays.length)
            },
            categories: categories,
            words: words
        };
    }

    function normalizeNextActivity(raw) {
        if (!raw || typeof raw !== 'object') { return null; }
        const mode = normalizeMode(raw.mode || '');
        if (!mode) { return null; }
        const details = (raw.details && typeof raw.details === 'object') ? raw.details : {};
        return {
            mode: mode,
            category_ids: uniqueIntList(raw.category_ids || []),
            session_word_ids: uniqueIntList(raw.session_word_ids || []),
            type: String(raw.type || ''),
            reason_code: String(raw.reason_code || ''),
            queue_id: String(raw.queue_id || ''),
            details: details
        };
    }

    function normalizeRecommendationQueue(raw) {
        const seen = {};
        return (Array.isArray(raw) ? raw : []).map(function (item) {
            const activity = normalizeNextActivity(item);
            if (!activity) { return null; }
            const queueId = String((item && item.queue_id) || activity.queue_id || '');
            activity.queue_id = queueId;
            if (queueId && seen[queueId]) {
                return null;
            }
            if (queueId) {
                seen[queueId] = true;
            }
            return activity;
        }).filter(Boolean);
    }

    function recommendationQueueHead(preferredMode) {
        const mode = normalizeMode(preferredMode || '');
        if (mode) {
            for (let idx = 0; idx < recommendationQueue.length; idx += 1) {
                const activity = normalizeNextActivity(recommendationQueue[idx]);
                if (activity && activity.mode === mode) {
                    return activity;
                }
            }
        }
        for (let idx = 0; idx < recommendationQueue.length; idx += 1) {
            const activity = normalizeNextActivity(recommendationQueue[idx]);
            if (activity) {
                return activity;
            }
        }
        return null;
    }

    function resolveQueueIdForActivity(activity) {
        const item = normalizeNextActivity(activity);
        if (!item || !item.mode) {
            return '';
        }
        const directId = String((activity && activity.queue_id) || item.queue_id || '').trim();
        if (directId) {
            return directId;
        }
        const mode = normalizeMode(item.mode);
        const targetCategoryIds = uniqueIntList(item.category_ids || []);
        if (!mode) {
            return '';
        }
        for (let idx = 0; idx < recommendationQueue.length; idx += 1) {
            const queued = normalizeNextActivity(recommendationQueue[idx]);
            if (!queued || normalizeMode(queued.mode) !== mode) {
                continue;
            }
            if (areCategorySetsEqual(queued.category_ids || [], targetCategoryIds)) {
                const queueId = String((recommendationQueue[idx] && recommendationQueue[idx].queue_id) || queued.queue_id || '').trim();
                if (queueId) {
                    return queueId;
                }
            }
        }
        return '';
    }

    function applyRecommendationPayload(data, options) {
        const payload = (data && typeof data === 'object') ? data : {};
        const opts = (options && typeof options === 'object') ? options : {};
        if (Object.prototype.hasOwnProperty.call(payload, 'recommendation_queue')) {
            recommendationQueue = normalizeRecommendationQueue(payload.recommendation_queue || []);
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'next_activity')) {
            nextActivity = normalizeNextActivity(payload.next_activity);
        }
        if ((!nextActivity || !nextActivity.mode) && recommendationQueue.length) {
            const preferredMode = normalizeMode(opts.preferredMode || '');
            nextActivity = recommendationQueueHead(preferredMode);
        }
        renderNextCard();
        renderSettingsQueue();
    }

    function uniqueIntList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : []).map(function (val) {
            return parseInt(val, 10) || 0;
        }).filter(function (id) {
            if (!id || seen[id]) { return false; }
            seen[id] = true;
            return true;
        });
    }

    function uniqueModeList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : []).map(function (mode) {
            return normalizeMode(mode);
        }).filter(function (mode) {
            if (!mode || seen[mode]) { return false; }
            seen[mode] = true;
            return true;
        });
    }

    function normalizeStarMode(mode) {
        const value = String(mode || '').toLowerCase();
        if (value === 'weighted' || value === 'only' || value === 'normal') {
            return value;
        }
        return 'normal';
    }

    function normalizeMode(mode) {
        const key = String(mode || '').trim().toLowerCase();
        if (key === 'selfcheck') { return 'self-check'; }
        if (key === 'practice' || key === 'learning' || key === 'listening' || key === 'gender' || key === 'self-check') {
            return key;
        }
        return '';
    }

    function readProgressTabPreference() {
        if (!progressTabStorageKey || !window.localStorage) {
            return '';
        }
        try {
            const saved = String(window.localStorage.getItem(progressTabStorageKey) || '');
            return (saved === 'words' || saved === 'categories') ? saved : '';
        } catch (_) {
            return '';
        }
    }

    function writeProgressTabPreference(tab) {
        if (!progressTabStorageKey || !window.localStorage) {
            return;
        }
        const value = (tab === 'words') ? 'words' : 'categories';
        try {
            window.localStorage.setItem(progressTabStorageKey, value);
        } catch (_) { /* no-op */ }
    }

    function modeIconFallback(mode) {
        const key = normalizeMode(mode);
        if (key === 'practice') { return '❓'; }
        if (key === 'learning') { return '🎓'; }
        if (key === 'listening') { return '🎧'; }
        if (key === 'gender') { return '⚥'; }
        if (key === 'self-check') { return '✔✖'; }
        return '❓';
    }

    function modeLabel(mode) {
        const key = normalizeMode(mode);
        if (!key) { return ''; }
        if (key === 'practice') { return String(i18n.modePractice || 'Practice'); }
        if (key === 'learning') { return String(i18n.modeLearning || 'Learn'); }
        if (key === 'listening') { return String(i18n.modeListening || 'Listen'); }
        if (key === 'gender') { return String(i18n.modeGender || 'Gender'); }
        if (key === 'self-check') { return String(i18n.modeSelfCheck || 'Self check'); }
        return key;
    }

    function formatTemplate(template, values) {
        const text = String(template || '');
        const list = Array.isArray(values) ? values : [];
        let out = text;
        list.forEach(function (value, index) {
            const slot = index + 1;
            const safe = String(value === undefined || value === null ? '' : value);
            out = out.replace(new RegExp('%' + slot + '\\$s', 'g'), safe);
            out = out.replace(new RegExp('%' + slot + '\\$d', 'g'), safe);
        });
        if (list.length) {
            out = out.replace(/%s/g, String(list[0]));
            out = out.replace(/%d/g, String(list[0]));
        }
        return out;
    }

    function getModeIconMarkup(mode, className) {
        const key = normalizeMode(mode);
        const cfgMode = (key && modeUi[key] && typeof modeUi[key] === 'object') ? modeUi[key] : {};
        const cls = className || 'll-vocab-lesson-mode-icon';
        const svg = String(cfgMode.svg || '').trim();
        if (svg) {
            return '<span class="' + escapeHtml(cls) + '" aria-hidden="true">' + svg + '</span>';
        }
        const emoji = String(cfgMode.icon || modeIconFallback(key)).trim();
        return '<span class="' + escapeHtml(cls) + '" aria-hidden="true" data-emoji="' + escapeHtml(emoji) + '"></span>';
    }

    function getLoadingModeIconMarkup(className) {
        const cls = String(className || 'll-vocab-lesson-mode-icon').trim() || 'll-vocab-lesson-mode-icon';
        return '<span class="' + escapeHtml(cls + ' ll-vocab-lesson-mode-icon--loading') + '" aria-hidden="true">'
            + '<span class="ll-wordset-inline-skeleton"></span>'
            + '</span>';
    }

    function getLoadingResultsButtonLabelMarkup() {
        return '<span class="ll-vocab-lesson-mode-label ll-vocab-lesson-mode-label--loading" aria-hidden="true">'
            + '<span class="ll-wordset-inline-skeleton ll-wordset-inline-skeleton--line-1"></span>'
            + '<span class="ll-wordset-inline-skeleton ll-wordset-inline-skeleton--line-2"></span>'
            + '</span>';
    }

    function escapeHtml(text) {
        return String(text || '').replace(/[&<>"']/g, function (char) {
            if (char === '&') { return '&amp;'; }
            if (char === '<') { return '&lt;'; }
            if (char === '>') { return '&gt;'; }
            if (char === '"') { return '&quot;'; }
            return '&#39;';
        });
    }

    function buildSortLocales(rawLocale) {
        const fromConfig = String(rawLocale || '').trim().replace('_', '-');
        const locales = [];
        const pushLocale = function (value) {
            const normalized = String(value || '').trim();
            if (!normalized) { return; }
            if (locales.indexOf(normalized) === -1) {
                locales.push(normalized);
            }
        };

        if (fromConfig) {
            pushLocale(fromConfig);
            const primary = fromConfig.split('-')[0];
            if (primary) {
                pushLocale(primary);
                if (primary.toLowerCase() === 'tr') {
                    pushLocale('tr-TR');
                }
            }
        }
        pushLocale('en-US');
        return locales;
    }

    function withTurkishSortLocales(baseLocales) {
        const combined = [];
        const pushLocale = function (value) {
            const normalized = String(value || '').trim();
            if (!normalized || combined.indexOf(normalized) !== -1) { return; }
            combined.push(normalized);
        };
        pushLocale('tr-TR');
        pushLocale('tr');
        (Array.isArray(baseLocales) ? baseLocales : []).forEach(pushLocale);
        return combined;
    }

    function textHasTurkishCharacters(value) {
        return /[çğıöşüÇĞİÖŞÜıİ]/.test(String(value || ''));
    }

    function localeTextCompare(left, right, options) {
        const a = String(left || '');
        const b = String(right || '');
        if (a === b) { return 0; }
        const opts = Object.assign({
            numeric: true,
            sensitivity: 'base'
        }, (options && typeof options === 'object') ? options : {});
        const locales = (textHasTurkishCharacters(a) || textHasTurkishCharacters(b))
            ? turkishSortLocales
            : sortLocales;

        try {
            return a.localeCompare(b, locales, opts);
        } catch (_) {
            try {
                return a.localeCompare(b, undefined, opts);
            } catch (_) {
                if (a < b) { return -1; }
                if (a > b) { return 1; }
                return 0;
            }
        }
    }

    function shuffleList(list) {
        const items = Array.isArray(list) ? list.slice() : [];
        for (let idx = items.length - 1; idx > 0; idx -= 1) {
            const swapIndex = Math.floor(Math.random() * (idx + 1));
            const temp = items[idx];
            items[idx] = items[swapIndex];
            items[swapIndex] = temp;
        }
        return items;
    }

    function isWordStarred(id) {
        const wid = parseInt(id, 10) || 0;
        if (!wid) { return false; }
        return uniqueIntList(state.starred_word_ids || []).indexOf(wid) !== -1;
    }

    function parseMysqlUtcDate(raw) {
        const val = String(raw || '').trim();
        if (!val) { return null; }
        let iso = val;
        if (iso.indexOf('T') === -1) {
            if (iso.length === 10) {
                iso += 'T00:00:00';
            } else {
                iso = iso.replace(' ', 'T');
            }
        }
        if (!/Z$/i.test(iso)) {
            iso += 'Z';
        }
        const parsed = new Date(iso);
        if (Number.isNaN(parsed.getTime())) {
            return null;
        }
        return parsed;
    }

    function formatAnalyticsDayLabel(rawDate) {
        const parsed = parseMysqlUtcDate(rawDate);
        if (!parsed) { return ''; }
        try {
            return parsed.toLocaleDateString(undefined, { month: 'numeric', day: 'numeric' });
        } catch (_) {
            return '';
        }
    }

    function formatAnalyticsLastSeen(rawDate) {
        const parsed = parseMysqlUtcDate(rawDate);
        if (!parsed) {
            return i18n.analyticsNever || 'Never';
        }
        try {
            return parsed.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        } catch (_) {
            return parsed.toISOString();
        }
    }

    function formatAnalyticsLastSeenDate(rawDate) {
        const parsed = parseMysqlUtcDate(rawDate);
        if (!parsed) {
            return i18n.analyticsNever || 'Never';
        }
        try {
            return parsed.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric'
            });
        } catch (_) {
            return formatAnalyticsLastSeen(rawDate);
        }
    }

    function hardWordsIconSvgMarkup() {
        return '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none">' +
            '<path d="M12 3.5L2.5 20.5H21.5L12 3.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path>' +
            '<line x1="12" y1="9" x2="12" y2="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>' +
            '<circle cx="12" cy="17" r="1.2" fill="currentColor"></circle>' +
            '</svg>';
    }

    function progressHardIconSvgMarkup() {
        return '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">' +
            '<path d="M32 8 L58 52 H6 Z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"></path>' +
            '<line x1="32" y1="23" x2="32" y2="37" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line>' +
            '<circle cx="32" cy="45" r="3.2" fill="currentColor"></circle>' +
            '</svg>';
    }

    function buildProgressIconMarkup(status, className) {
        const key = String(status || '').toLowerCase();
        const cls = String(className || 'll-wordset-progress-inline-icon');
        if (key === 'mastered') {
            return '<span class="' + escapeHtml(cls) + '" aria-hidden="true"><svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline></svg></span>';
        }
        if (key === 'studied') {
            return '<span class="' + escapeHtml(cls) + '" aria-hidden="true"><svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle><path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path></svg></span>';
        }
        if (key === 'new') {
            return '<span class="' + escapeHtml(cls) + '" aria-hidden="true"><svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line><line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line></svg></span>';
        }
        if (key === 'starred') {
            return '<span class="' + escapeHtml(cls) + ' ll-wordset-progress-star-glyph-icon" aria-hidden="true"></span>';
        }
        if (key === 'hard') {
            return '<span class="' + escapeHtml(cls) + '" aria-hidden="true">' + progressHardIconSvgMarkup() + '</span>';
        }
        return '';
    }

    function progressResetIsEnabled() {
        const nonceValue = String(progressResetCfg.nonce || '').trim();
        const actionUrl = String(progressResetCfg.actionUrl || '').trim();
        const resetWordsetId = parseInt(progressResetCfg.wordsetId, 10) || 0;
        return !!progressResetCfg.enabled && nonceValue !== '' && actionUrl !== '' && resetWordsetId > 0;
    }

    function buildProgressResetIconMarkup(className) {
        const cls = String(className || 'll-wordset-progress-category-reset-icon');
        return '<svg class="' + escapeHtml(cls) + '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"'
            + ' fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"'
            + ' aria-hidden="true" focusable="false">'
            + '<circle cx="12" cy="12" r="9"></circle>'
            + '<path d="M9 9l6 6"></path>'
            + '<path d="M15 9l-6 6"></path>'
            + '</svg>';
    }

    function buildProgressSortIconMarkup(direction) {
        const dir = String(direction || 'both');
        if (dir === 'asc') {
            return '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M8 13V3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5.5 5.5L8 3l2.5 2.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        if (dir === 'desc') {
            return '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M8 3v10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5.5 10.5L8 13l2.5-2.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        return '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M8 2.5v11" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M5.8 5L8 2.7 10.2 5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.8 11L8 13.3 10.2 11" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    function getProgressSummaryCounts() {
        const words = Array.isArray(analytics.words) ? analytics.words : [];
        let starredCount = 0;
        words.forEach(function (row) {
            if (isWordStarred(row.id)) {
                starredCount += 1;
            }
        });
        const summary = (analytics.summary && typeof analytics.summary === 'object') ? analytics.summary : {};
        const mastered = Math.max(0, parseInt(summary.mastered_words, 10) || 0);
        const studiedTotal = Math.max(0, parseInt(summary.studied_words, 10) || 0);
        const studied = Math.max(0, studiedTotal - mastered);
        const newWords = Math.max(0, parseInt(summary.new_words, 10) || 0);
        const hard = Math.max(0, parseInt(summary.hard_words, 10) || 0);
        const starred = Math.max(starredCount, Math.max(0, parseInt(summary.starred_words, 10) || 0));
        return {
            mastered: mastered,
            studied: studied,
            studiedTotal: studiedTotal,
            newWords: newWords,
            starred: starred,
            hard: hard
        };
    }

    function buildProgressScopeText() {
        const scope = (analytics.scope && typeof analytics.scope === 'object') ? analytics.scope : {};
        const count = Math.max(0, parseInt(scope.category_count, 10) || 0);
        const template = (scope.mode === 'selected')
            ? (i18n.analyticsScopeSelected || 'Selected categories (%d)')
            : (i18n.analyticsScopeAll || 'All categories (%d)');
        return formatTemplate(template, [count]);
    }

    function analyticsStatusLabel(status) {
        if (status === 'mastered') { return i18n.analyticsWordStatusMastered || 'Learned'; }
        if (status === 'studied') { return i18n.analyticsWordStatusStudied || 'In progress'; }
        return i18n.analyticsWordStatusNew || 'New';
    }

    function categoryMetaById(categoryId) {
        const cid = parseInt(categoryId, 10) || 0;
        if (!cid) { return null; }
        for (let idx = 0; idx < categories.length; idx += 1) {
            const cat = categories[idx] || {};
            if ((parseInt(cat.id, 10) || 0) === cid) {
                return {
                    id: cid,
                    label: String(cat.translation || cat.name || ''),
                    name: String(cat.name || ''),
                    url: String(cat.url || ''),
                    preview: Array.isArray(cat.preview) ? cat.preview.slice(0, 2) : []
                };
            }
        }
        return null;
    }

    function getQueuePreviewItems(categoryIds, limit) {
        const maxItems = Math.max(1, parseInt(limit, 10) || 2);
        const items = [];
        const seen = {};
        const ids = uniqueIntList(categoryIds || []);
        ids.forEach(function (catId) {
            if (items.length >= maxItems) { return; }
            const meta = categoryMetaById(catId);
            const preview = (meta && Array.isArray(meta.preview)) ? meta.preview : [];
            preview.forEach(function (rawItem) {
                if (items.length >= maxItems) { return; }
                const entry = (rawItem && typeof rawItem === 'object') ? rawItem : {};
                const type = (String(entry.type || '').toLowerCase() === 'image' && String(entry.url || '').trim() !== '')
                    ? 'image'
                    : 'text';
                if (type === 'image') {
                    const url = String(entry.url || '').trim();
                    if (!url) { return; }
                    const key = 'img:' + url;
                    if (seen[key]) { return; }
                    seen[key] = true;
                    items.push({
                        type: 'image',
                        url: url,
                        alt: String(entry.alt || '')
                    });
                    return;
                }
                const label = String(entry.label || '').trim();
                if (!label) { return; }
                const key = 'txt:' + label;
                if (seen[key]) { return; }
                seen[key] = true;
                items.push({
                    type: 'text',
                    label: label
                });
            });
        });
        return items;
    }

    function scheduleUniformQueueItemWidth() {
        if (!$settingsQueueList.length) { return; }
        clearTimeout(queueItemWidthTimer);
        queueItemWidthTimer = window.setTimeout(function () {
            const $rows = $settingsQueueList.find('.ll-wordset-queue-item');
            if (!$rows.length) {
                $settingsQueueList.css('--ll-wordset-queue-item-width', '');
                return;
            }

            $settingsQueueList.css('--ll-wordset-queue-item-width', 'fit-content');
            let maxWidth = 0;
            $rows.each(function () {
                const rowWidth = Math.ceil($(this).outerWidth() || 0);
                if (rowWidth > maxWidth) {
                    maxWidth = rowWidth;
                }
            });
            if (!Number.isFinite(maxWidth) || maxWidth <= 0) {
                return;
            }

            const listWidth = Math.max(0, Math.floor($settingsQueueList.innerWidth() || 0));
            if (listWidth > 0) {
                maxWidth = Math.min(maxWidth, listWidth);
            }
            $settingsQueueList.css('--ll-wordset-queue-item-width', String(maxWidth) + 'px');
        }, 70);
    }

    function analyticsWordDifficulty(row) {
        return Math.max(0, parseInt(row && row.difficulty_score, 10) || 0);
    }

    function analyticsWordIsDifficult(row) {
        const status = String(row && row.status || 'new');
        if (status !== 'studied') { return false; }
        return analyticsWordDifficulty(row) >= HARD_WORD_DIFFICULTY_THRESHOLD;
    }

    function analyticsWordSeen(row) {
        return Math.max(0, parseInt(row && row.total_coverage, 10) || 0);
    }

    function analyticsWordWrong(row) {
        return Math.max(0, parseInt(row && row.incorrect, 10) || 0);
    }

    function analyticsWordLastTimestamp(row) {
        const parsed = parseMysqlUtcDate(row && row.last_seen_at);
        return parsed ? parsed.getTime() : 0;
    }

    function analyticsBuildNumericRanges(values, targetCount) {
        const count = Math.max(1, parseInt(targetCount, 10) || 5);
        const nums = (Array.isArray(values) ? values : []).map(function (value) {
            return Math.max(0, parseInt(value, 10) || 0);
        });
        if (!nums.length) { return []; }
        const min = Math.min.apply(null, nums);
        const max = Math.max.apply(null, nums);
        if (max <= min) {
            return [{
                value: String(min) + ':' + String(max),
                label: String(min)
            }];
        }

        const step = (max - min + 1) / count;
        const ranges = [];
        let start = min;
        for (let idx = 0; idx < count; idx += 1) {
            let end = (idx === count - 1)
                ? max
                : Math.floor(min + ((idx + 1) * step) - 1);
            if (end < start) { end = start; }
            ranges.push({
                value: String(start) + ':' + String(end),
                label: (start === end) ? String(start) : (String(start) + '-' + String(end))
            });
            start = end + 1;
            if (start > max) { break; }
        }

        const seen = {};
        return ranges.filter(function (range) {
            if (seen[range.value]) { return false; }
            seen[range.value] = true;
            return true;
        });
    }

    function analyticsParseRangeToken(token) {
        const raw = String(token || '').trim();
        const match = raw.match(/^(-?\d+):(-?\d+)$/);
        if (!match) { return null; }
        const min = parseInt(match[1], 10);
        const max = parseInt(match[2], 10);
        if (!Number.isFinite(min) || !Number.isFinite(max)) { return null; }
        return {
            min: Math.min(min, max),
            max: Math.max(min, max)
        };
    }

    function analyticsWordMatchesRange(value, token) {
        const range = analyticsParseRangeToken(token);
        if (!range) { return true; }
        const number = Math.max(0, parseInt(value, 10) || 0);
        return number >= range.min && number <= range.max;
    }

    function normalizeSummaryFilter(value) {
        const key = String(value || '').trim().toLowerCase();
        if (key === 'mastered' || key === 'studied' || key === 'new' || key === 'starred' || key === 'hard') {
            return key;
        }
        return '';
    }

    function summaryFilterLabel(key) {
        const normalized = normalizeSummaryFilter(key);
        if (normalized === 'mastered') {
            return String(i18n.analyticsMastered || 'Learned');
        }
        if (normalized === 'studied') {
            return String(i18n.analyticsStudied || 'In progress');
        }
        if (normalized === 'new') {
            return String(i18n.analyticsNew || 'New');
        }
        if (normalized === 'starred') {
            return String(i18n.analyticsStarred || 'Starred');
        }
        if (normalized === 'hard') {
            return String(i18n.analyticsHard || 'Hard');
        }
        return '';
    }

    function hasActiveWordColumnFilters() {
        return Object.keys(analyticsWordColumnFilters).some(function (key) {
            return normalizeFilterSelectionList(analyticsWordColumnFilters[key]).length > 0;
        });
    }

    function getProgressSelectAllContextLabel() {
        const summaryKey = normalizeSummaryFilter(analyticsSummaryFilter);
        if (summaryKey) {
            return summaryFilterLabel(summaryKey);
        }
        const hasSearch = String(analyticsWordSearchQuery || '').trim() !== '';
        const hasCategoryFilter = Array.isArray(analyticsWordCategoryFilterIds) && analyticsWordCategoryFilterIds.length > 0;
        if (hasSearch || hasCategoryFilter || hasActiveWordColumnFilters()) {
            return String(i18n.analyticsSelectAllContextFiltered || 'Filtered words');
        }
        return '';
    }

    function buildProgressSelectAllButtonLabel(allVisibleSelected) {
        const context = getProgressSelectAllContextLabel();
        const template = allVisibleSelected
            ? String(i18n.analyticsDeselectAllWithContext || '')
            : String(i18n.analyticsSelectAllWithContext || '');
        if (template && context) {
            return formatTemplate(template, [context]);
        }
        return allVisibleSelected
            ? (i18n.analyticsDeselectAllShown || 'Deselect all')
            : (i18n.analyticsSelectAllShown || 'Select all');
    }

    function clearProgressSelection() {
        if (!progressSelectedWordIds.length) {
            return;
        }
        progressSelectedWordIds = [];
    }

    function pruneProgressSelectionWordIfHidden(wordId) {
        const wid = parseInt(wordId, 10) || 0;
        if (!wid) { return; }
        const selectedIds = getProgressSelectedWordIds();
        if (selectedIds.indexOf(wid) === -1) {
            return;
        }
        const stillVisible = buildProgressWordRowsForDisplay().some(function (row) {
            return (parseInt(row && row.id, 10) || 0) === wid;
        });
        if (stillVisible) {
            return;
        }
        progressSelectedWordIds = selectedIds.filter(function (id) {
            return id !== wid;
        });
    }

    function triggerProgressKpiFeedback($button) {
        if (!$button || !$button.length) { return; }
        const $cards = $progressSummary.find('[data-ll-wordset-progress-kpi-filter]');
        $cards.removeClass('is-click-feedback');
        if ($button[0]) {
            // Force reflow so repeated clicks still replay the animation.
            void $button[0].offsetWidth;
        }
        $button.addClass('is-click-feedback');
        clearTimeout(progressKpiFeedbackTimer);
        progressKpiFeedbackTimer = setTimeout(function () {
            $button.removeClass('is-click-feedback');
        }, 280);
    }

    function triggerProgressWordFilterAnimation() {
        if (!$progressWordTableWrap.length) { return; }
        clearTimeout(progressWordTableFxTimer);
        $progressWordTableWrap.removeClass('is-filtering');
        if ($progressWordTableWrap[0]) {
            // Force reflow so repeated clicks still replay the animation.
            void $progressWordTableWrap[0].offsetWidth;
        }
        $progressWordTableWrap.addClass('is-filtering');
        progressWordTableFxTimer = setTimeout(function () {
            $progressWordTableWrap.removeClass('is-filtering');
        }, 300);
    }

    function analyticsWordMatchesDifficultyFilter(row, filterValue) {
        const token = String(filterValue || '').trim().toLowerCase();
        if (!token) { return true; }
        if (token === 'hard') {
            return analyticsWordIsDifficult(row);
        }
        return analyticsWordMatchesRange(analyticsWordDifficulty(row), token);
    }

    function analyticsWordMatchesStarFilter(row, filterValue) {
        const token = String(filterValue || '').trim().toLowerCase();
        if (!token) { return true; }
        const isStarred = isWordStarred(parseInt(row && row.id, 10) || 0);
        if (token === 'starred') {
            return isStarred;
        }
        if (token === 'unstarred') {
            return !isStarred;
        }
        return true;
    }

    function normalizeFilterSelectionList(values) {
        const seen = {};
        const list = Array.isArray(values) ? values : [values];
        return list.map(function (value) {
            return String(value || '').trim();
        }).filter(function (value) {
            if (!value || value === 'all' || seen[value]) { return false; }
            seen[value] = true;
            return true;
        });
    }

    function setProgressColumnFilterCheckboxOptions($container, key, options, selectedValues) {
        if (!$container || !$container.length) { return normalizeFilterSelectionList(selectedValues); }
        const list = (Array.isArray(options) ? options : []).map(function (option) {
            const item = (option && typeof option === 'object') ? option : {};
            const value = String(item.value || '').trim();
            const label = String(item.label || value).trim();
            if (!value || !label) { return null; }
            return {
                value: value,
                label: label,
                iconHtml: String(item.iconHtml || '').trim(),
                ariaLabel: String(item.ariaLabel || label).trim(),
                optionClass: String(item.optionClass || '').trim()
            };
        }).filter(Boolean);

        const allowed = {};
        list.forEach(function (item) {
            allowed[item.value] = true;
        });
        const selected = normalizeFilterSelectionList(selectedValues).filter(function (value) {
            return !!allowed[value];
        });
        const selectedLookup = {};
        selected.forEach(function (value) {
            selectedLookup[value] = true;
        });

        $container.empty();
        list.forEach(function (item, index) {
            const inputId = 'll-wordset-progress-filter-' + key + '-' + String(index + 1);
            const $row = $('<label>', {
                class: 'll-wordset-progress-filter-option' + (item.optionClass ? (' ' + item.optionClass) : ''),
                for: inputId
            });
            $('<input>', {
                id: inputId,
                type: 'checkbox',
                'data-ll-wordset-progress-column-filter-check': key,
                'data-ll-wordset-progress-filter-value': item.value,
                checked: !!selectedLookup[item.value],
                'aria-label': item.ariaLabel
            }).appendTo($row);
            if (item.iconHtml) {
                $('<span>', {
                    class: 'll-wordset-progress-filter-option__icon',
                    'aria-hidden': 'true',
                    html: item.iconHtml
                }).appendTo($row);
            }
            $('<span>', {
                class: 'll-wordset-progress-filter-option__label',
                text: item.label
            }).appendTo($row);
            $container.append($row);
        });
        return selected;
    }

    function analyticsWordMatchesLastFilter(row, filterValue) {
        const key = String(filterValue || 'all');
        if (key === 'all') { return true; }
        const timestamp = analyticsWordLastTimestamp(row);
        if (key === 'never') {
            return timestamp <= 0;
        }
        if (timestamp <= 0) {
            return false;
        }
        const now = Date.now();
        const age = Math.max(0, now - timestamp);
        const day = 24 * 60 * 60 * 1000;
        if (key === '24h') { return age <= day; }
        if (key === '7d') { return age <= 7 * day; }
        if (key === '30d') { return age <= 30 * day; }
        if (key === 'older') { return age > 30 * day; }
        return true;
    }

    function analyticsApplyWordColumnFilters(rows) {
        const starFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.star || []);
        const statusFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.status || []);
        const lastFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.last || []);
        const difficultyFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.difficulty || []);
        const seenFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.seen || []);
        const wrongFilters = normalizeFilterSelectionList(analyticsWordColumnFilters.wrong || []);

        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            const status = String(row && row.status || 'new');
            if (statusFilters.length && statusFilters.indexOf(status) === -1) {
                return false;
            }
            if (starFilters.length && !starFilters.some(function (value) {
                return analyticsWordMatchesStarFilter(row, value);
            })) {
                return false;
            }
            if (!analyticsWordMatchesCategoryFilter(row)) {
                return false;
            }
            if (lastFilters.length && !lastFilters.some(function (value) {
                return analyticsWordMatchesLastFilter(row, value);
            })) {
                return false;
            }
            if (difficultyFilters.length && !difficultyFilters.some(function (value) {
                return analyticsWordMatchesDifficultyFilter(row, value);
            })) {
                return false;
            }
            if (seenFilters.length && !seenFilters.some(function (value) {
                return analyticsWordMatchesRange(analyticsWordSeen(row), value);
            })) {
                return false;
            }
            if (wrongFilters.length && !wrongFilters.some(function (value) {
                return analyticsWordMatchesRange(analyticsWordWrong(row), value);
            })) {
                return false;
            }
            return true;
        });
    }

    function analyticsApplySummaryWordFilter(rows) {
        const key = normalizeSummaryFilter(analyticsSummaryFilter);
        if (!key) {
            return Array.isArray(rows) ? rows.slice() : [];
        }

        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            if (key === 'mastered') {
                return String(row && row.status || '') === 'mastered';
            }
            if (key === 'studied') {
                return String(row && row.status || '') === 'studied';
            }
            if (key === 'new') {
                return String(row && row.status || '') === 'new';
            }
            if (key === 'starred') {
                return isWordStarred(parseInt(row && row.id, 10) || 0);
            }
            if (key === 'hard') {
                return analyticsWordIsDifficult(row);
            }
            return true;
        });
    }

    function analyticsSortWordRows(rows) {
        const key = String(analyticsWordSort.key || '');
        const dir = String(analyticsWordSort.direction || '');
        if (!key || (dir !== 'asc' && dir !== 'desc')) {
            return Array.isArray(rows) ? rows.slice() : [];
        }

        const rank = { new: 0, studied: 1, mastered: 2 };
        const direction = dir === 'asc' ? 1 : -1;
        const sorted = Array.isArray(rows) ? rows.slice() : [];
        sorted.sort(function (left, right) {
            let compare = 0;
            if (key === 'status') {
                compare = (rank[String(left && left.status || 'new')] || 0) - (rank[String(right && right.status || 'new')] || 0);
            } else if (key === 'word') {
                compare = localeTextCompare(String(left && left.title || ''), String(right && right.title || ''));
                if (compare === 0) {
                    compare = localeTextCompare(String(left && left.translation || ''), String(right && right.translation || ''));
                }
            } else if (key === 'category') {
                compare = localeTextCompare(analyticsWordPrimaryCategoryLabel(left), analyticsWordPrimaryCategoryLabel(right));
            } else if (key === 'difficulty') {
                compare = analyticsWordDifficulty(left) - analyticsWordDifficulty(right);
            } else if (key === 'seen') {
                compare = analyticsWordSeen(left) - analyticsWordSeen(right);
            } else if (key === 'wrong') {
                compare = analyticsWordWrong(left) - analyticsWordWrong(right);
            } else if (key === 'last') {
                compare = analyticsWordLastTimestamp(left) - analyticsWordLastTimestamp(right);
            }
            if (compare === 0) {
                compare = localeTextCompare(String(left && left.title || ''), String(right && right.title || ''));
            }
            return compare * direction;
        });
        return sorted;
    }

    function renderProgressWordColumnFilterOptions() {
        if (!$progressWordColumnFilterOptions.length) { return; }
        const rows = Array.isArray(analytics.words) ? analytics.words : [];
        const starOptions = [
            { value: 'starred', label: i18n.analyticsFilterStarredOnly || 'Starred only' },
            { value: 'unstarred', label: i18n.analyticsFilterUnstarredOnly || 'Unstarred only' }
        ];
        const statuses = [
            { value: 'mastered', label: i18n.analyticsWordStatusMastered || 'Learned' },
            { value: 'studied', label: i18n.analyticsWordStatusStudied || 'In progress' },
            { value: 'new', label: i18n.analyticsWordStatusNew || 'New' }
        ];
        const lastOptions = [
            { value: '24h', label: i18n.analyticsFilterLast24h || 'Last 24h' },
            { value: '7d', label: i18n.analyticsFilterLast7d || 'Last 7d' },
            { value: '30d', label: i18n.analyticsFilterLast30d || 'Last 30d' },
            { value: 'older', label: i18n.analyticsFilterLastOlder || 'Older' },
            { value: 'never', label: i18n.analyticsFilterLastNever || 'Never' }
        ];
        const hardRows = rows.filter(function (row) {
            return analyticsWordIsDifficult(row);
        });
        const hardValues = hardRows.map(analyticsWordDifficulty);
        let nonHardValues = rows.filter(function (row) {
            return !analyticsWordIsDifficult(row);
        }).map(analyticsWordDifficulty);
        let hardMin = 0;
        let hardMax = 0;
        if (hardValues.length) {
            hardMin = Math.min.apply(null, hardValues);
            hardMax = Math.max.apply(null, hardValues);
            nonHardValues = nonHardValues.filter(function (value) {
                return value < hardMin || value > hardMax;
            });
        }
        const difficultyRanges = analyticsBuildNumericRanges(nonHardValues, 4);
        const seenRanges = analyticsBuildNumericRanges(rows.map(analyticsWordSeen), 5);
        const wrongRanges = analyticsBuildNumericRanges(rows.map(analyticsWordWrong), 5);
        const rangeToOptions = function (ranges) {
            const options = [];
            (Array.isArray(ranges) ? ranges : []).forEach(function (range) {
                if (!range || typeof range !== 'object') { return; }
                options.push({
                    value: String(range.value || ''),
                    label: String(range.label || range.value || '')
                });
            });
            return options;
        };

        $progressWordColumnFilterOptions.each(function () {
            const $field = $(this);
            const key = String($field.attr('data-ll-wordset-progress-column-filter-options') || '');
            if (!Object.prototype.hasOwnProperty.call(analyticsWordColumnFilters, key)) {
                return;
            }
            let options = [];
            if (key === 'status') {
                options = statuses;
            } else if (key === 'star') {
                options = starOptions;
            } else if (key === 'last') {
                options = lastOptions;
            } else if (key === 'difficulty') {
                options = rangeToOptions(difficultyRanges);
                if (hardValues.length) {
                    const hardWordsLabel = String(i18n.analyticsFilterDifficultyHard || 'Hard words');
                    options.push({
                        value: 'hard',
                        label: hardWordsLabel,
                        iconHtml: hardWordsIconSvgMarkup(),
                        ariaLabel: hardWordsLabel,
                        optionClass: 'll-wordset-progress-filter-option--hard-range'
                    });
                }
            } else if (key === 'seen') {
                options = rangeToOptions(seenRanges);
            } else if (key === 'wrong') {
                options = rangeToOptions(wrongRanges);
            }
            analyticsWordColumnFilters[key] = setProgressColumnFilterCheckboxOptions(
                $field,
                key,
                options,
                analyticsWordColumnFilters[key]
            );
        });
        positionOpenProgressFilterPop();
    }

    function renderProgressCategoryFilterOptions() {
        if (!$progressCategoryFilterOptions.length) { return; }
        const selectedLookup = {};
        (Array.isArray(analyticsWordCategoryFilterIds) ? analyticsWordCategoryFilterIds : []).forEach(function (id) {
            const cid = parseInt(id, 10) || 0;
            if (cid > 0) { selectedLookup[cid] = true; }
        });
        $progressCategoryFilterOptions.empty();

        const sorted = categories.slice().sort(function (left, right) {
            const a = String(left && (left.translation || left.name) || '');
            const b = String(right && (right.translation || right.name) || '');
            return localeTextCompare(a, b);
        });

        sorted.forEach(function (cat) {
            const cid = parseInt(cat && cat.id, 10) || 0;
            if (!cid) { return; }
            const label = String(cat.translation || cat.name || '').trim();
            if (!label) { return; }
            const inputId = 'll-wordset-progress-category-filter-' + String(cid);
            const $row = $('<label>', {
                class: 'll-wordset-progress-filter-option ll-wordset-progress-category-filter-option',
                for: inputId
            });
            $('<input>', {
                id: inputId,
                type: 'checkbox',
                'data-ll-wordset-progress-category-filter-check': cid,
                checked: !!selectedLookup[cid]
            }).appendTo($row);
            $('<span>', { text: label }).appendTo($row);
            $progressCategoryFilterOptions.append($row);
        });
        positionOpenProgressFilterPop();
    }

    function renderProgressFilterTriggerStates() {
        let anyActive = !!normalizeSummaryFilter(analyticsSummaryFilter);
        if (!$progressFilterTriggers.length) { return; }
        $progressFilterTriggers.each(function () {
            const $trigger = $(this);
            const key = String($trigger.attr('data-ll-wordset-progress-filter-trigger') || '');
            let hasFilter = false;
            if (key === 'category') {
                hasFilter = Array.isArray(analyticsWordCategoryFilterIds) && analyticsWordCategoryFilterIds.length > 0;
            } else if (Object.prototype.hasOwnProperty.call(analyticsWordColumnFilters, key)) {
                hasFilter = normalizeFilterSelectionList(analyticsWordColumnFilters[key]).length > 0;
            }
            if (hasFilter) {
                anyActive = true;
            }
            $trigger.toggleClass('has-filter', hasFilter);
        });
        if ($progressClearFiltersButton.length) {
            $progressClearFiltersButton.prop('hidden', !anyActive);
        }
    }

    function renderProgressSortState() {
        if (!$progressSortButtons.length) { return; }
        const activeKey = String(analyticsWordSort.key || '');
        const activeDirection = String(analyticsWordSort.direction || '');
        const nextAsc = i18n.analyticsSortAsc || 'Sort ascending';
        const nextDesc = i18n.analyticsSortDesc || 'Sort descending';

        $progressSortHeaders.attr('aria-sort', 'none');
        $progressSortButtons.each(function () {
            const $button = $(this);
            const key = String($button.attr('data-ll-wordset-progress-sort') || '');
            const isActive = key !== '' && key === activeKey && (activeDirection === 'asc' || activeDirection === 'desc');
            let ariaSort = 'none';
            let direction = '';
            let indicatorDirection = 'both';
            if (isActive) {
                direction = activeDirection;
                if (direction === 'asc') {
                    ariaSort = 'ascending';
                    indicatorDirection = 'asc';
                } else if (direction === 'desc') {
                    ariaSort = 'descending';
                    indicatorDirection = 'desc';
                }
            }
            $button.toggleClass('is-active', isActive);
            $button.attr('data-direction', direction);
            $button.attr('title', (isActive && direction === 'asc') ? nextDesc : nextAsc);
            $button.find('.ll-wordset-progress-sort-indicator').html(buildProgressSortIconMarkup(indicatorDirection));
            $button.closest('[data-ll-wordset-progress-sort-th]').attr('aria-sort', ariaSort);
        });
    }

    function renderProgressCategorySortState() {
        if (!$progressCategorySortButtons.length) { return; }
        const activeKey = String(analyticsCategorySort.key || '');
        const activeDirection = String(analyticsCategorySort.direction || '');
        const nextAsc = i18n.analyticsSortAsc || 'Sort ascending';
        const nextDesc = i18n.analyticsSortDesc || 'Sort descending';

        $progressCategorySortHeaders.attr('aria-sort', 'none');
        $progressCategorySortButtons.each(function () {
            const $button = $(this);
            const key = String($button.attr('data-ll-wordset-progress-category-sort') || '');
            const isActive = key !== '' && key === activeKey && (activeDirection === 'asc' || activeDirection === 'desc');
            let ariaSort = 'none';
            let direction = '';
            let indicatorDirection = 'both';
            if (isActive) {
                direction = activeDirection;
                if (direction === 'asc') {
                    ariaSort = 'ascending';
                    indicatorDirection = 'asc';
                } else if (direction === 'desc') {
                    ariaSort = 'descending';
                    indicatorDirection = 'desc';
                }
            }
            $button.toggleClass('is-active', isActive);
            $button.attr('data-direction', direction);
            $button.attr('title', (isActive && direction === 'asc') ? nextDesc : nextAsc);
            $button.find('.ll-wordset-progress-sort-indicator').html(buildProgressSortIconMarkup(indicatorDirection));
            $button.closest('[data-ll-wordset-progress-category-sort-th]').attr('aria-sort', ariaSort);
        });
    }

    function analyticsApplyWordSearch(rows) {
        const query = String(analyticsWordSearchQuery || '').trim().toLowerCase();
        if (!query) {
            return Array.isArray(rows) ? rows.slice() : [];
        }
        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            const title = String(row && row.title || '').toLowerCase();
            const translation = String(row && row.translation || '').toLowerCase();
            return title.indexOf(query) !== -1 || translation.indexOf(query) !== -1;
        });
    }

    function analyticsApplyCategorySearch(rows) {
        const query = String(analyticsCategorySearchQuery || '').trim().toLowerCase();
        if (!query) {
            return Array.isArray(rows) ? rows.slice() : [];
        }
        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            const label = String(row && row.label || '').toLowerCase();
            if (label.indexOf(query) !== -1) {
                return true;
            }
            const meta = categoryMetaById(row && row.id);
            const name = String(meta && meta.name || '').toLowerCase();
            return name.indexOf(query) !== -1;
        });
    }

    function setProgressWordLoading(isLoading) {
        if (!$progressWordSearchLoading.length) { return; }
        $progressWordSearchLoading.prop('hidden', !isLoading);
    }

    function setProgressCategoryLoading(isLoading) {
        if (!$progressCategorySearchLoading.length) { return; }
        $progressCategorySearchLoading.prop('hidden', !isLoading);
    }

    function scheduleProgressWordTableRender(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const shouldShowLoading = !!opts.showLoading;
        const token = ++analyticsWordRenderToken;
        const loadingDelay = shouldShowLoading ? 80 : 0;
        const renderDelay = shouldShowLoading ? 95 : 0;
        clearTimeout(analyticsWordRenderTimer);
        clearTimeout(analyticsWordLoadingTimer);

        if (shouldShowLoading) {
            analyticsWordLoadingTimer = setTimeout(function () {
                if (token !== analyticsWordRenderToken) { return; }
                setProgressWordLoading(true);
            }, loadingDelay);
        } else {
            setProgressWordLoading(false);
        }

        analyticsWordRenderTimer = setTimeout(function () {
            if (token !== analyticsWordRenderToken) { return; }
            renderProgressWordTable();
            clearTimeout(analyticsWordLoadingTimer);
            analyticsWordLoadingTimer = null;
            setProgressWordLoading(false);
        }, renderDelay);
    }

    function scheduleProgressCategoryTableRender(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const shouldShowLoading = !!opts.showLoading;
        const token = ++analyticsCategoryRenderToken;
        const loadingDelay = shouldShowLoading ? 80 : 0;
        const renderDelay = shouldShowLoading ? 95 : 0;
        clearTimeout(analyticsCategoryRenderTimer);
        clearTimeout(analyticsCategoryLoadingTimer);

        if (shouldShowLoading) {
            analyticsCategoryLoadingTimer = setTimeout(function () {
                if (token !== analyticsCategoryRenderToken) { return; }
                setProgressCategoryLoading(true);
            }, loadingDelay);
        } else {
            setProgressCategoryLoading(false);
        }

        analyticsCategoryRenderTimer = setTimeout(function () {
            if (token !== analyticsCategoryRenderToken) { return; }
            renderProgressCategoryTable();
            clearTimeout(analyticsCategoryLoadingTimer);
            analyticsCategoryLoadingTimer = null;
            setProgressCategoryLoading(false);
        }, renderDelay);
    }

    function closeProgressFilterPops(exceptKey) {
        const keepKey = String(exceptKey || '');
        $progressFilterPops.each(function () {
            const $pop = $(this);
            const key = String($pop.attr('data-ll-wordset-progress-filter-pop') || '');
            const keepOpen = keepKey && key === keepKey;
            $pop.prop('hidden', !keepOpen);
            if (keepOpen) {
                $pop.addClass('ll-wordset-progress-filter-pop--floating');
            } else {
                $pop.removeClass('ll-wordset-progress-filter-pop--floating');
                $pop.css({ left: '', top: '' });
            }
        });
        $progressFilterTriggers.each(function () {
            const $trigger = $(this);
            const key = String($trigger.attr('data-ll-wordset-progress-filter-trigger') || '');
            const expanded = !!(keepKey && key === keepKey);
            $trigger.attr('aria-expanded', expanded ? 'true' : 'false');
            $trigger.toggleClass('is-active', expanded);
        });
    }

    function getOpenProgressFilterKey() {
        let openKey = '';
        $progressFilterTriggers.each(function () {
            if (openKey) { return; }
            const $trigger = $(this);
            if (String($trigger.attr('aria-expanded') || '') !== 'true') { return; }
            openKey = String($trigger.attr('data-ll-wordset-progress-filter-trigger') || '');
        });
        return openKey;
    }

    function positionProgressFilterPop(filterKey) {
        const key = String(filterKey || '');
        if (!key) { return; }
        const $trigger = $progressFilterTriggers.filter('[data-ll-wordset-progress-filter-trigger="' + key + '"]').first();
        const $pop = $progressFilterPops.filter('[data-ll-wordset-progress-filter-pop="' + key + '"]').first();
        if (!$trigger.length || !$pop.length || $pop.prop('hidden')) { return; }
        const triggerRect = $trigger[0].getBoundingClientRect();
        if (!triggerRect || (triggerRect.width === 0 && triggerRect.height === 0)) { return; }

        const viewportWidth = Math.max(0, window.innerWidth || document.documentElement.clientWidth || 0);
        const viewportHeight = Math.max(0, window.innerHeight || document.documentElement.clientHeight || 0);
        const margin = 8;
        const gap = 6;

        const popWidth = Math.ceil($pop.outerWidth() || 0);
        const popHeight = Math.ceil($pop.outerHeight() || 0);

        let left = triggerRect.left;
        let top = triggerRect.bottom + gap;

        if (popWidth > 0 && left + popWidth > viewportWidth - margin) {
            left = triggerRect.right - popWidth;
        }
        if (left < margin) {
            left = margin;
        }

        if (popHeight > 0 && top + popHeight > viewportHeight - margin) {
            top = triggerRect.top - popHeight - gap;
        }
        if (top < margin) {
            top = margin;
        }

        $pop.css({
            left: String(Math.round(left)) + 'px',
            top: String(Math.round(top)) + 'px'
        });
    }

    function positionOpenProgressFilterPop() {
        const key = getOpenProgressFilterKey();
        if (!key) { return; }
        positionProgressFilterPop(key);
    }

    function toggleProgressFilterPop(key) {
        const filterKey = String(key || '');
        if (!filterKey) {
            closeProgressFilterPops('');
            return;
        }
        const $pop = $progressFilterPops.filter('[data-ll-wordset-progress-filter-pop="' + filterKey + '"]');
        if (!$pop.length) { return; }
        const isOpen = !$pop.prop('hidden');
        if (isOpen) {
            closeProgressFilterPops('');
        } else {
            closeProgressFilterPops(filterKey);
            positionProgressFilterPop(filterKey);
        }
    }

    function analyticsWordPrimaryCategoryLabel(row) {
        if (!row || typeof row !== 'object') { return ''; }
        if (Array.isArray(row.category_labels) && row.category_labels.length) {
            return String(row.category_labels[0] || '').trim();
        }
        return String(row.category_label || '').trim();
    }

    function analyticsWordMatchesCategoryFilter(row) {
        if (!Array.isArray(analyticsWordCategoryFilterIds) || !analyticsWordCategoryFilterIds.length) {
            return true;
        }
        const selectedLookup = {};
        analyticsWordCategoryFilterIds.forEach(function (id) {
            const cid = parseInt(id, 10) || 0;
            if (cid > 0) { selectedLookup[cid] = true; }
        });
        const rowIds = Array.isArray(row && row.category_ids) ? row.category_ids : [];
        return rowIds.some(function (id) {
            const cid = parseInt(id, 10) || 0;
            return cid > 0 && !!selectedLookup[cid];
        });
    }

    function analyticsCategoryActivityTotal(row) {
        const byMode = (row && row.exposure_by_mode && typeof row.exposure_by_mode === 'object')
            ? row.exposure_by_mode
            : {};
        let total = 0;
        ['learning', 'practice', 'listening', 'gender', 'self-check'].forEach(function (mode) {
            total += Math.max(0, parseInt(byMode[mode], 10) || 0);
        });
        return total;
    }

    function analyticsCategoryWordProgress(row) {
        const total = Math.max(0, parseInt(row && row.word_count, 10) || 0);
        const mastered = Math.max(0, parseInt(row && row.mastered_words, 10) || 0);
        const studiedTotal = Math.max(0, parseInt(row && row.studied_words, 10) || 0);
        const studied = Math.max(0, studiedTotal - mastered);
        const newWords = Math.max(0, total - studiedTotal);
        return {
            total: total,
            mastered: mastered,
            studied: studied,
            newWords: newWords
        };
    }

    function analyticsCategoryHasRecordedProgress(row) {
        if (!row || typeof row !== 'object') {
            return false;
        }
        const metrics = analyticsCategoryWordProgress(row);
        if (metrics.mastered > 0 || metrics.studied > 0) {
            return true;
        }
        if (analyticsCategoryActivityTotal(row) > 0) {
            return true;
        }
        return String(row.last_seen_at || '').trim() !== '';
    }

    function analyticsCategoryProgressScore(row) {
        const metrics = analyticsCategoryWordProgress(row);
        if (metrics.total <= 0) {
            return 0;
        }
        return ((metrics.mastered * 2) + metrics.studied) / (metrics.total * 2);
    }

    function analyticsSortCategoryRows(rows) {
        const key = String(analyticsCategorySort.key || '');
        const dir = String(analyticsCategorySort.direction || '');
        if (!key || (dir !== 'asc' && dir !== 'desc')) {
            return Array.isArray(rows) ? rows.slice() : [];
        }
        const direction = dir === 'asc' ? 1 : -1;
        const sorted = Array.isArray(rows) ? rows.slice() : [];
        sorted.sort(function (left, right) {
            let compare = 0;
            if (key === 'category') {
                compare = localeTextCompare(String(left && left.label || ''), String(right && right.label || ''));
            } else if (key === 'progress') {
                compare = analyticsCategoryProgressScore(left) - analyticsCategoryProgressScore(right);
                if (compare === 0) {
                    compare = (parseInt(left && left.mastered_words, 10) || 0) - (parseInt(right && right.mastered_words, 10) || 0);
                }
                if (compare === 0) {
                    compare = (parseInt(left && left.studied_words, 10) || 0) - (parseInt(right && right.studied_words, 10) || 0);
                }
            } else if (key === 'activity') {
                compare = analyticsCategoryActivityTotal(left) - analyticsCategoryActivityTotal(right);
            } else if (key === 'last') {
                compare = analyticsWordLastTimestamp(left) - analyticsWordLastTimestamp(right);
            }
            if (compare === 0) {
                compare = localeTextCompare(String(left && left.label || ''), String(right && right.label || ''));
            }
            return compare * direction;
        });
        return sorted;
    }

    function renderProgressScope() {
        if (!$progressScope.length) { return; }
        $progressScope.text(buildProgressScopeText());
    }

    function renderProgressSummary() {
        if (!$progressSummary.length) { return; }
        $progressSummary.empty();

        const counts = getProgressSummaryCounts();
        summaryCounts = {
            mastered: counts.mastered,
            studied: counts.studied,
            new: counts.newWords,
            starred: counts.starred,
            hard: counts.hard
        };
        renderMiniCounts();

        const items = [
            { key: 'mastered', value: counts.mastered, label: i18n.analyticsMastered || 'Learned', icon: buildProgressIconMarkup('mastered', 'll-wordset-progress-kpi-icon') },
            { key: 'studied', value: counts.studied, label: i18n.analyticsStudied || 'In progress', icon: buildProgressIconMarkup('studied', 'll-wordset-progress-kpi-icon') },
            { key: 'new', value: counts.newWords, label: i18n.analyticsNew || 'New', icon: buildProgressIconMarkup('new', 'll-wordset-progress-kpi-icon') },
            { key: 'starred', value: counts.starred, label: i18n.analyticsStarred || 'Starred', icon: buildProgressIconMarkup('starred', 'll-wordset-progress-kpi-icon') },
            { key: 'hard', value: counts.hard, label: i18n.analyticsHard || 'Hard', icon: buildProgressIconMarkup('hard', 'll-wordset-progress-kpi-icon') }
        ];

        items.forEach(function (item) {
            const isActive = normalizeSummaryFilter(analyticsSummaryFilter) === item.key;
            const $card = $('<button>', {
                type: 'button',
                class: 'll-wordset-progress-kpi ll-wordset-progress-kpi--' + item.key + (isActive ? ' is-active' : ''),
                'data-ll-wordset-progress-kpi-filter': item.key,
                'aria-pressed': isActive ? 'true' : 'false'
            });
            if (item.icon) {
                $('<span>', { class: 'll-wordset-progress-kpi-icon-wrap', html: item.icon }).appendTo($card);
            }
            $('<span>', { class: 'll-wordset-progress-kpi-value', text: String(item.value) }).appendTo($card);
            $('<span>', { class: 'll-wordset-progress-kpi-label', text: item.label }).appendTo($card);
            $progressSummary.append($card);
        });
    }

    function renderProgressDailyGraph() {
        if (!$progressGraph.length) { return; }
        $progressGraph.empty();
        const daily = (analytics.daily_activity && typeof analytics.daily_activity === 'object') ? analytics.daily_activity : {};
        const days = Array.isArray(daily.days) ? daily.days : [];
        if (!days.length) {
            $('<p>', { class: 'll-wordset-progress-empty', text: i18n.analyticsDailyEmpty || 'No activity yet.' }).appendTo($progressGraph);
            return;
        }

        const maxEvents = Math.max(1, parseInt(daily.max_events, 10) || 0);
        const $bars = $('<div>', { class: 'll-wordset-progress-bars' });
        days.forEach(function (entry) {
            const events = Math.max(0, parseInt(entry.events, 10) || 0);
            const uniqueWords = Math.max(0, parseInt(entry.unique_words, 10) || 0);
            const ratio = events > 0 ? Math.min(1, events / maxEvents) : 0;
            const label = formatAnalyticsDayLabel(entry.date);
            const title = formatTemplate(i18n.analyticsDayEvents || '%1$d events, %2$d words', [events, uniqueWords]);

            const $day = $('<div>', {
                class: 'll-wordset-progress-day',
                title: title
            });
            $('<span>', { class: 'll-wordset-progress-day-count', text: String(events) }).appendTo($day);
            $('<span>', { class: 'll-wordset-progress-day-bar', style: '--ratio:' + ratio.toFixed(3) }).appendTo($day);
            $('<span>', { class: 'll-wordset-progress-day-label', text: label }).appendTo($day);
            $bars.append($day);
        });
        $progressGraph.append($bars);
    }

    function renderProgressActivityPills(row) {
        const byMode = (row && row.exposure_by_mode && typeof row.exposure_by_mode === 'object')
            ? row.exposure_by_mode
            : {};
        const order = ['learning', 'practice', 'listening', 'gender', 'self-check'];
        const $wrap = $('<div>', { class: 'll-wordset-progress-activity' });

        order.forEach(function (mode) {
            const count = Math.max(0, parseInt(byMode[mode], 10) || 0);
            if (!count) { return; }
            const label = modeLabel(mode);
            const $pill = $('<span>', {
                class: 'll-wordset-progress-activity-pill',
                title: label
            });
            $pill.append(getModeIconMarkup(mode, 'll-vocab-lesson-mode-icon ll-wordset-progress-activity-icon'));
            $('<span>', { text: String(count) }).appendTo($pill);
            $wrap.append($pill);
        });

        return $wrap;
    }

    function renderProgressCategoryTable() {
        if (!$progressCategoryRows.length) { return; }
        $progressCategoryRows.empty();
        let rows = Array.isArray(analytics.categories) ? analytics.categories : [];
        rows = analyticsApplyCategorySearch(rows);
        rows = analyticsSortCategoryRows(rows);
        if (!rows.length) {
            $('<tr>').append(
                $('<td>', { colspan: 4, text: i18n.analyticsNoRows || 'No data yet.' })
            ).appendTo($progressCategoryRows);
            return;
        }

        rows.forEach(function (row) {
            const metrics = analyticsCategoryWordProgress(row);
            const $tr = $('<tr>');
            const $categoryCell = $('<td>');
            const meta = categoryMetaById(row.id);
            const categoryUrl = String(meta && meta.url || '').trim();
            const categoryLabel = String(row.label || (meta && meta.label) || '').trim();
            const preview = (meta && Array.isArray(meta.preview)) ? meta.preview.slice(0, 2) : [];
            const $categoryWrap = $('<div>', { class: 'll-wordset-progress-category-cell' });
            const $thumbs = $('<span>', { class: 'll-wordset-progress-category-thumbs' });
            preview.forEach(function (item) {
                const entry = (item && typeof item === 'object') ? item : {};
                const type = String(entry.type || '').toLowerCase();
                const $thumb = $('<span>', { class: 'll-wordset-progress-category-thumb' + (type === 'image' && entry.url ? ' is-image' : '') });
                if (type === 'image' && entry.url) {
                    $('<img>', {
                        src: String(entry.url),
                        alt: String(entry.alt || categoryLabel || ''),
                        loading: 'lazy',
                        decoding: 'async',
                        fetchpriority: 'low'
                    }).appendTo($thumb);
                } else {
                    $('<span>', {
                        class: 'll-wordset-progress-category-thumb-text',
                        dir: 'auto',
                        text: String(entry.label || '').trim().slice(0, 2)
                    }).appendTo($thumb);
                }
                $thumbs.append($thumb);
            });
            if ($thumbs.children().length) {
                $categoryWrap.append($thumbs);
            }
            if (categoryUrl) {
                $('<a>', {
                    class: 'll-wordset-progress-category-link',
                    href: categoryUrl,
                    dir: 'auto',
                    text: categoryLabel
                }).appendTo($categoryWrap);
            } else {
                $('<span>', { dir: 'auto', text: categoryLabel }).appendTo($categoryWrap);
            }

            if (progressResetIsEnabled() && row.id > 0 && analyticsCategoryHasRecordedProgress(row)) {
                const resetActionUrl = String(progressResetCfg.actionUrl || '').trim();
                const resetNonce = String(progressResetCfg.nonce || '').trim();
                const resetWordsetId = parseInt(progressResetCfg.wordsetId, 10) || 0;
                const resetCategoryId = parseInt(row.id, 10) || 0;
                if (resetActionUrl && resetNonce && resetWordsetId > 0 && resetCategoryId > 0) {
                    const categoryNameForMessages = String(categoryLabel || (i18n.categoriesLabel || 'category')).trim() || 'category';
                    const resetConfirmMessage = formatTemplate(
                        i18n.progressResetCategoryConfirm || 'This will permanently delete your progress for %s. This cannot be undone. Continue?',
                        [categoryNameForMessages]
                    );
                    const resetAriaLabel = formatTemplate(
                        i18n.progressResetCategoryAria || 'Reset progress for %s',
                        [categoryNameForMessages]
                    );

                    const $categoryResetForm = $('<form>', {
                        class: 'll-wordset-progress-category-reset-form',
                        method: 'post',
                        action: resetActionUrl,
                        'data-ll-wordset-progress-reset-form': '1',
                        'data-confirm': resetConfirmMessage
                    });

                    $('<input>', { type: 'hidden', name: 'll_wordset_progress_reset_action', value: 'category' }).appendTo($categoryResetForm);
                    $('<input>', { type: 'hidden', name: 'll_wordset_progress_reset_nonce', value: resetNonce }).appendTo($categoryResetForm);
                    $('<input>', { type: 'hidden', name: 'll_wordset_progress_reset_wordset_id', value: String(resetWordsetId) }).appendTo($categoryResetForm);
                    $('<input>', { type: 'hidden', name: 'll_wordset_progress_reset_category_id', value: String(resetCategoryId) }).appendTo($categoryResetForm);

                    $('<button>', {
                        type: 'submit',
                        class: 'll-wordset-progress-category-reset-button',
                        title: resetAriaLabel,
                        'aria-label': resetAriaLabel,
                        html: buildProgressResetIconMarkup('ll-wordset-progress-category-reset-icon')
                    }).appendTo($categoryResetForm);

                    $categoryWrap.append($categoryResetForm);
                }
            }
            $categoryCell.append($categoryWrap).appendTo($tr);

            const progressTitle = [
                String(metrics.mastered) + ' ' + (i18n.analyticsMastered || 'Learned'),
                String(metrics.studied) + ' ' + (i18n.analyticsStudied || 'In progress'),
                String(metrics.newWords) + ' ' + (i18n.analyticsNew || 'New')
            ].join(' · ');
            const $progressCell = $('<td>');
            const $progressMini = $('<span>', {
                class: 'll-wordset-progress-mini ll-wordset-progress-mini--static',
                title: progressTitle
            });
            const progressPills = [
                { key: 'mastered', value: metrics.mastered },
                { key: 'studied', value: metrics.studied },
                { key: 'new', value: metrics.newWords }
            ];
            progressPills.forEach(function (pill) {
                const icon = buildProgressIconMarkup(pill.key, 'll-wordset-progress-pill__icon');
                const $pill = $('<span>', {
                    class: 'll-wordset-progress-pill ll-wordset-progress-pill--' + pill.key
                });
                $pill.append(icon);
                $('<span>', { class: 'll-wordset-progress-pill__value', text: String(pill.value) }).appendTo($pill);
                $progressMini.append($pill);
            });
            $progressCell.append($progressMini).appendTo($tr);

            $('<td>').append(renderProgressActivityPills(row)).appendTo($tr);
            const categoryLastFull = formatAnalyticsLastSeen(row.last_seen_at);
            const categoryLastDate = formatAnalyticsLastSeenDate(row.last_seen_at);
            const $categoryLastCell = $('<td>', { class: 'll-wordset-progress-last-cell' });
            $('<span>', { class: 'll-wordset-progress-last-full', text: categoryLastFull }).appendTo($categoryLastCell);
            $('<span>', { class: 'll-wordset-progress-last-date', text: categoryLastDate }).appendTo($categoryLastCell);
            $categoryLastCell.appendTo($tr);
            $progressCategoryRows.append($tr);
        });
    }

    function buildProgressWordRowsForDisplay() {
        let rows = Array.isArray(analytics.words) ? analytics.words.slice() : [];
        rows = analyticsApplySummaryWordFilter(rows);
        rows = analyticsApplyWordSearch(rows);
        rows = analyticsApplyWordColumnFilters(rows);
        rows = analyticsSortWordRows(rows);
        return rows;
    }

    function normalizeProgressSelectedWordIds(values) {
        const availableLookup = {};
        (Array.isArray(analytics.words) ? analytics.words : []).forEach(function (row) {
            const wordId = parseInt(row && row.id, 10) || 0;
            if (wordId > 0) {
                availableLookup[wordId] = true;
            }
        });

        return uniqueIntList(values || []).filter(function (wordId) {
            return !!availableLookup[wordId];
        });
    }

    function getProgressSelectedWordIds() {
        progressSelectedWordIds = normalizeProgressSelectedWordIds(progressSelectedWordIds);
        return progressSelectedWordIds.slice();
    }

    function getProgressSelectionCategoryIds(wordIds) {
        const selectedLookup = {};
        uniqueIntList(wordIds || []).forEach(function (id) {
            selectedLookup[id] = true;
        });

        if (!Object.keys(selectedLookup).length) {
            return [];
        }

        const categoryIds = [];
        (Array.isArray(analytics.words) ? analytics.words : []).forEach(function (row) {
            const wordId = parseInt(row && row.id, 10) || 0;
            if (!wordId || !selectedLookup[wordId]) {
                return;
            }
            const rowCategoryIds = uniqueIntList((row && row.category_ids) || []);
            if (rowCategoryIds.length) {
                categoryIds.push.apply(categoryIds, rowCategoryIds);
                return;
            }
            const categoryId = parseInt(row && row.category_id, 10) || 0;
            if (categoryId > 0) {
                categoryIds.push(categoryId);
            }
        });

        return uniqueIntList(categoryIds);
    }

    function syncProgressSelectionControls(visibleRows) {
        const rows = Array.isArray(visibleRows) ? visibleRows : [];
        const visibleWordIds = uniqueIntList(rows.map(function (row) {
            return parseInt(row && row.id, 10) || 0;
        }));
        const selectedWordIds = getProgressSelectedWordIds();
        const selectedLookup = {};
        selectedWordIds.forEach(function (id) {
            selectedLookup[id] = true;
        });
        const allVisibleSelected = visibleWordIds.length > 0 && visibleWordIds.every(function (id) {
            return !!selectedLookup[id];
        });

        if ($progressSelectAllButton.length) {
            $progressSelectAllButton
                .prop('disabled', visibleWordIds.length === 0)
                .attr('aria-pressed', allVisibleSelected ? 'true' : 'false')
                .text(buildProgressSelectAllButtonLabel(allVisibleSelected));
        }

        if (!$progressSelectionBar.length) {
            return;
        }

        const selectedCount = selectedWordIds.length;
        if (analyticsTab !== 'words') {
            $progressSelectionBar.prop('hidden', true);
            return;
        }
        if (selectedCount <= 0) {
            $progressSelectionBar.prop('hidden', true);
            if ($progressSelectionCount.length) {
                $progressSelectionCount.text(formatTemplate(i18n.analyticsSelectionCount || '%d selected words', [0]));
            }
            if ($progressSelectionModeButtons.length) {
                $progressSelectionModeButtons
                    .prop('disabled', true)
                    .attr('aria-disabled', 'true')
                    .addClass('is-disabled');
            }
            if ($progressSelectionClear.length) {
                $progressSelectionClear.prop('disabled', true);
            }
            return;
        }

        $progressSelectionBar.prop('hidden', false);
        if ($progressSelectionCount.length) {
            $progressSelectionCount.text(formatTemplate(i18n.analyticsSelectionCount || '%d selected words', [selectedCount]));
        }

        const selectedCategoryIds = getProgressSelectionCategoryIds(selectedWordIds);
        const allowGender = selectionHasGenderSupport(selectedCategoryIds);
        const hasEnoughWords = selectedCount >= getSelectionMinimumWordCount();

        if ($progressSelectionModeButtons.length) {
            $progressSelectionModeButtons.each(function () {
                const $button = $(this);
                const mode = normalizeMode($button.attr('data-mode') || '');
                const disabled = !mode || !hasEnoughWords || (mode === 'gender' && !allowGender);
                $button
                    .prop('disabled', disabled)
                    .attr('aria-disabled', disabled ? 'true' : 'false')
                    .toggleClass('is-disabled', disabled)
                    .toggleClass('is-unavailable', mode === 'gender' && !allowGender);
            });
        }
        if ($progressSelectionClear.length) {
            $progressSelectionClear.prop('disabled', false);
        }
    }

    function launchProgressSelectionMode(mode) {
        const normalizedMode = normalizeMode(mode) || 'practice';
        const selectedWordIds = getProgressSelectedWordIds();
        if (!selectedWordIds.length) {
            alert(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
            return;
        }

        const selectedCategoryIds = getProgressSelectionCategoryIds(selectedWordIds);
        const categoryIds = filterCategoryIdsForMode(normalizedMode, selectedCategoryIds);
        if (!categoryIds.length) {
            alert(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
            return;
        }

        chunkSession = null;
        launchFlashcards(normalizedMode, categoryIds, shuffleList(selectedWordIds), {
            source: 'wordset_progress_selection_start',
            chunked: false,
            sessionStarMode: 'normal',
            randomizeSessionCategoryOrder: true
        });
    }

    function renderProgressWordTable() {
        if (!$progressWordRows.length) { return; }
        $progressWordRows.empty();
        const rows = buildProgressWordRowsForDisplay();
        const selectedLookup = {};
        getProgressSelectedWordIds().forEach(function (id) {
            selectedLookup[id] = true;
        });
        if (!rows.length) {
            syncProgressSelectionControls([]);
            $('<tr>').append(
                $('<td>', { colspan: 8, text: i18n.analyticsNoRows || 'No data yet.' })
            ).appendTo($progressWordRows);
            return;
        }

        rows.forEach(function (row) {
            const isStarred = isWordStarred(row.id);
            const starLabel = isStarred ? (i18n.analyticsUnstarWord || 'Unstar word') : (i18n.analyticsStarWord || 'Star word');
            const rowId = parseInt(row && row.id, 10) || 0;
            const isSelected = !!selectedLookup[rowId];
            const $tr = $('<tr>', {
                'data-word-id': row.id,
                class: isSelected ? 'is-selected' : ''
            });

            const $starCell = $('<td>');
            $('<button>', {
                type: 'button',
                class: 'll-wordset-progress-star ll-tools-star-button' + (isStarred ? ' active' : ''),
                'data-ll-wordset-progress-word-star': row.id,
                'aria-pressed': isStarred ? 'true' : 'false',
                'aria-label': starLabel,
                title: starLabel
            }).appendTo($starCell);
            $starCell.appendTo($tr);

            const primaryWord = String(row.title || '').trim();
            const secondaryWord = String(row.translation || '').trim();
            const $wordCell = $('<td>');
            const $wordContent = $('<div>', { class: 'll-wordset-progress-word-cell' });
            const imageUrl = String(row.image || '').trim();
            const $wordThumb = $('<span>', { class: 'll-wordset-progress-word-thumb' + (imageUrl ? '' : ' is-empty') });
            if (imageUrl) {
                $('<img>', {
                    src: imageUrl,
                    alt: primaryWord || secondaryWord || '',
                    loading: 'lazy',
                    decoding: 'async',
                    fetchpriority: 'low'
                }).appendTo($wordThumb);
            } else {
                $('<span>', { class: 'll-wordset-progress-word-thumb-fallback', 'aria-hidden': 'true' }).appendTo($wordThumb);
            }
            const $wordMain = $('<div>', { class: 'll-wordset-progress-word-main' });
            $('<span>', { class: 'll-wordset-progress-word-main-text', dir: 'auto', text: protectMaqafNoBreak(primaryWord) }).appendTo($wordMain);
            $('<span>', { class: 'll-wordset-progress-word-main-sub', dir: 'auto', text: protectMaqafNoBreak(secondaryWord) }).appendTo($wordMain);
            $wordContent.append($wordThumb).append($wordMain);
            $wordCell.append($wordContent).appendTo($tr);

            const $categoryCell = $('<td>');
            const categoryIds = Array.isArray(row.category_ids) ? row.category_ids : [];
            const categoryLabels = Array.isArray(row.category_labels) ? row.category_labels : [];
            let renderedCategories = 0;
            categoryIds.forEach(function (categoryId, index) {
                const cid = parseInt(categoryId, 10) || 0;
                if (!cid) { return; }
                const meta = categoryMetaById(cid);
                const label = String(categoryLabels[index] || (meta && meta.label) || '').trim();
                if (!label) { return; }
                if (renderedCategories > 0) {
                    $categoryCell.append(document.createTextNode(', '));
                }
                const url = String(meta && meta.url || '').trim();
                if (url) {
                    $('<a>', {
                        class: 'll-wordset-progress-category-link',
                        href: url,
                        dir: 'auto',
                        text: label
                    }).appendTo($categoryCell);
                } else {
                    $('<span>', { dir: 'auto', text: label }).appendTo($categoryCell);
                }
                renderedCategories += 1;
            });
            if (!renderedCategories) {
                $('<span>', { dir: 'auto', text: row.category_label || '' }).appendTo($categoryCell);
            }
            $categoryCell.appendTo($tr);

            const iconKey = (row.status === 'mastered') ? 'mastered' : ((row.status === 'studied') ? 'studied' : 'new');
            const statusHtml = buildProgressIconMarkup(iconKey, 'll-wordset-progress-status-icon');
            const $statusCell = $('<td>');
            $('<span>', {
                class: 'll-wordset-progress-status-pill ll-wordset-progress-status-pill--' + iconKey,
                html: statusHtml + '<span class="ll-wordset-progress-status-label">' + escapeHtml(analyticsStatusLabel(row.status)) + '</span>'
            }).appendTo($statusCell);
            $statusCell.appendTo($tr);

            const difficulty = analyticsWordDifficulty(row);
            $('<td>', {
                class: 'll-wordset-progress-num-cell ll-wordset-progress-num-cell--difficulty' + (analyticsWordIsDifficult(row) ? ' is-difficult' : ''),
                text: difficulty
            }).appendTo($tr);
            $('<td>', { class: 'll-wordset-progress-num-cell ll-wordset-progress-num-cell--seen', text: analyticsWordSeen(row) }).appendTo($tr);
            const wrong = analyticsWordWrong(row);
            $('<td>', { class: 'll-wordset-progress-num-cell ll-wordset-progress-num-cell--wrong', text: wrong }).appendTo($tr);
            const wordLastFull = formatAnalyticsLastSeen(row.last_seen_at);
            const wordLastDate = formatAnalyticsLastSeenDate(row.last_seen_at);
            const $wordLastCell = $('<td>', { class: 'll-wordset-progress-last-cell' });
            $('<span>', { class: 'll-wordset-progress-last-full', text: wordLastFull }).appendTo($wordLastCell);
            $('<span>', { class: 'll-wordset-progress-last-date', text: wordLastDate }).appendTo($wordLastCell);
            $wordLastCell.appendTo($tr);
            $progressWordRows.append($tr);
        });

        syncProgressSelectionControls(rows);
    }

    function setProgressTab(nextTab, options) {
        if (!$progressRoot.length) { return; }
        const opts = (options && typeof options === 'object') ? options : {};
        analyticsTab = (nextTab === 'words') ? 'words' : 'categories';
        $progressTabButtons.each(function () {
            const tab = String($(this).attr('data-ll-wordset-progress-tab') || '');
            const active = tab === analyticsTab;
            $(this).toggleClass('active', active).attr('aria-selected', active ? 'true' : 'false');
        });
        $progressPanels.each(function () {
            const panel = String($(this).attr('data-ll-wordset-progress-panel') || '');
            $(this).prop('hidden', panel !== analyticsTab);
        });
        if (!opts.skipPersist) {
            writeProgressTabPreference(analyticsTab);
        }
        if (analyticsTab === 'words') {
            syncProgressSelectionControls(buildProgressWordRowsForDisplay());
        } else if ($progressSelectionBar.length) {
            $progressSelectionBar.prop('hidden', true);
        }
    }

    function renderProgressAnalytics() {
        if (!$progressRoot.length) { return; }
        renderProgressScope();
        renderProgressSummary();
        renderProgressDailyGraph();
        renderProgressWordColumnFilterOptions();
        renderProgressCategoryFilterOptions();
        renderProgressFilterTriggerStates();
        renderProgressSortState();
        renderProgressCategorySortState();
        closeProgressFilterPops('');
        setProgressWordLoading(false);
        setProgressCategoryLoading(false);
        renderProgressCategoryTable();
        renderProgressWordTable();
        setProgressTab(analyticsTab);
        const hasRows = (Array.isArray(analytics.words) && analytics.words.length > 0) ||
            (Array.isArray(analytics.categories) && analytics.categories.length > 0);
        if (hasRows) {
            setProgressStatus('', '');
        }
    }

    function setProgressStatus(message, stateClass) {
        if (!$progressStatus.length) { return; }
        const text = String(message || '').trim();
        $progressStatus.removeClass('is-loading is-error');
        if (!text) {
            $progressStatus.text('').hide();
            return;
        }
        if (stateClass === 'loading') {
            $progressStatus.addClass('is-loading');
        } else if (stateClass === 'error') {
            $progressStatus.addClass('is-error');
        }
        $progressStatus.text(text).show();
    }

    function refreshProgressAnalyticsNow(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (!$progressRoot.length || !isLoggedIn || !ajaxUrl || !nonce) {
            return $.Deferred().resolve(null).promise();
        }
        const token = ++analyticsRequestToken;
        if (!opts.silent) {
            setProgressStatus(i18n.analyticsLoading || 'Loading progress...', 'loading');
        }
        const analyticsRequestData = {
            action: 'll_user_study_analytics',
            nonce: nonce,
            wordset_id: wordsetId,
            days: 14
        };
        if (progressIncludeHidden) {
            analyticsRequestData.include_ignored = 1;
            analyticsRequestData.category_ids = [];
        } else {
            analyticsRequestData.category_ids = getVisibleCategoryIds();
        }

        return $.post(ajaxUrl, analyticsRequestData).done(function (res) {
            if (token !== analyticsRequestToken) { return; }
            if (res && res.success && res.data && res.data.analytics) {
                analytics = normalizeAnalytics(res.data.analytics);
                renderProgressAnalytics();
                setProgressStatus('', '');
                return;
            }
            setProgressStatus(i18n.analyticsUnavailable || 'Progress is unavailable right now.', 'error');
        }).fail(function () {
            if (token !== analyticsRequestToken) { return; }
            setProgressStatus(i18n.analyticsUnavailable || 'Progress is unavailable right now.', 'error');
        });
    }

    function scheduleProgressAnalyticsRefresh(delay, options) {
        if (!$progressRoot.length) { return; }
        const ms = Math.max(80, parseInt(delay, 10) || 250);
        const opts = (options && typeof options === 'object') ? options : {};
        clearTimeout(analyticsTimer);
        analyticsTimer = setTimeout(function () {
            analyticsTimer = null;
            refreshProgressAnalyticsNow(opts);
        }, ms);
    }

    function getIgnoredLookup() {
        const lookup = {};
        uniqueIntList(goals.ignored_category_ids || []).forEach(function (id) {
            lookup[id] = true;
        });
        return lookup;
    }

    function isCategoryHidden(catId) {
        const lookup = getIgnoredLookup();
        return !!lookup[parseInt(catId, 10) || 0];
    }

    function getCategoryById(catId) {
        const id = parseInt(catId, 10) || 0;
        if (!id) { return null; }
        for (let i = 0; i < categories.length; i++) {
            if (categories[i].id === id) {
                return categories[i];
            }
        }
        return null;
    }

    function getCategoryAspectBucket(catId) {
        const cat = getCategoryById(catId);
        if (!cat) { return 'no-image'; }
        const bucket = String(cat.aspect_bucket || '').trim();
        return bucket || 'no-image';
    }

    function normalizePromptTypeForCompatibility(value) {
        const key = String(value || '').trim().toLowerCase();
        if (key === 'text_title' || key === 'text_translation') {
            return 'text';
        }
        return key || 'audio';
    }

    function normalizeOptionTypeForCompatibility(value) {
        const key = String(value || '').trim().toLowerCase();
        if (key === 'text_title' || key === 'text_translation') {
            return 'text';
        }
        return key || 'image';
    }

    function getCategoryQuizPresentationKey(catId) {
        const cat = getCategoryById(catId);
        if (!cat) {
            return 'audio->image';
        }
        const promptType = normalizePromptTypeForCompatibility(cat.prompt_type || 'audio');
        const optionType = normalizeOptionTypeForCompatibility(cat.option_type || cat.mode || 'image');
        return promptType + '->' + optionType;
    }

    function getCategoryCompatibilityKey(catId, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const requireMatchingPresentation = !!opts.requireMatchingPresentation;
        const aspectBucket = getCategoryAspectBucket(catId);
        if (!requireMatchingPresentation) {
            return aspectBucket;
        }
        return aspectBucket + '|' + getCategoryQuizPresentationKey(catId);
    }

    function filterCategoryIdsByAspectBucket(categoryIds, options) {
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        if (ids.length < 2) {
            return ids;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const bucketGroups = {};
        const bucketById = {};
        ids.forEach(function (id) {
            const bucket = getCategoryCompatibilityKey(id, opts);
            bucketById[id] = bucket;
            if (!bucketGroups[bucket]) {
                bucketGroups[bucket] = [];
            }
            bucketGroups[bucket].push(id);
        });

        const bucketKeys = Object.keys(bucketGroups);
        if (bucketKeys.length < 2) {
            return ids;
        }

        const preferredCategoryId = parseInt(opts.preferCategoryId, 10) || ids[0];
        let preferredBucket = bucketById[preferredCategoryId] || '';
        if (!preferredBucket || !bucketGroups[preferredBucket] || !bucketGroups[preferredBucket].length) {
            preferredBucket = bucketKeys[0];
        }
        if (!preferredBucket || !bucketGroups[preferredBucket] || !bucketGroups[preferredBucket].length) {
            return ids;
        }

        return ids.filter(function (id) {
            return bucketById[id] === preferredBucket;
        });
    }

    function selectCompatibleCategoryIdsByWordCounts(categoryIds, options) {
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        if (ids.length < 2) {
            return ids;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const priorityCounts = (opts.priorityCounts && typeof opts.priorityCounts === 'object') ? opts.priorityCounts : {};
        const totalCounts = (opts.totalCounts && typeof opts.totalCounts === 'object') ? opts.totalCounts : {};
        const groups = {};
        const keyById = {};

        ids.forEach(function (id, index) {
            const key = getCategoryCompatibilityKey(id, opts);
            keyById[id] = key;
            if (!groups[key]) {
                groups[key] = {
                    key: key,
                    ids: [],
                    order: index,
                    priorityCount: 0,
                    totalCount: 0
                };
            }
            groups[key].ids.push(id);
            groups[key].priorityCount += Math.max(0, parseInt(priorityCounts[id], 10) || 0);
            groups[key].totalCount += Math.max(0, parseInt(totalCounts[id], 10) || 0);
        });

        const groupList = Object.keys(groups).map(function (key) { return groups[key]; });
        if (groupList.length < 2) {
            return ids;
        }

        const preferredCategoryId = parseInt(opts.preferCategoryId, 10) || ids[0] || 0;
        let best = null;
        groupList.forEach(function (group) {
            group.hasPreferred = group.ids.indexOf(preferredCategoryId) !== -1;
            if (!best) {
                best = group;
                return;
            }

            if (group.priorityCount !== best.priorityCount) {
                if (group.priorityCount > best.priorityCount) {
                    best = group;
                }
                return;
            }
            if (group.hasPreferred !== best.hasPreferred) {
                if (group.hasPreferred) {
                    best = group;
                }
                return;
            }
            if (group.totalCount !== best.totalCount) {
                if (group.totalCount > best.totalCount) {
                    best = group;
                }
                return;
            }
            if (group.order < best.order) {
                best = group;
            }
        });

        if (!best || !Array.isArray(best.ids) || !best.ids.length) {
            return ids;
        }

        return ids.filter(function (id) {
            return keyById[id] === best.key;
        });
    }

    function getCategoryLabel(catId) {
        const cat = getCategoryById(catId);
        if (!cat) { return ''; }
        return String(cat.translation || cat.name || '');
    }

    function priorityFocusLabel(focus) {
        const key = normalizePriorityFocus(focus);
        if (key === 'new') { return String(i18n.priorityFocusNew || 'New words'); }
        if (key === 'studied') { return String(i18n.priorityFocusStudied || 'In progress words'); }
        if (key === 'learned') { return String(i18n.priorityFocusLearned || 'Learned words'); }
        if (key === 'starred') { return String(i18n.priorityFocusStarred || 'Starred words'); }
        if (key === 'hard') { return String(i18n.priorityFocusHard || 'Hard words'); }
        return '';
    }

    function resolveSelectionCriteriaKey(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const starOnly = !!opts.starOnly;
        const hardOnly = !!opts.hardOnly;
        if (starOnly && hardOnly) { return 'starred'; }
        if (starOnly) { return 'starred'; }
        if (hardOnly) { return 'hard'; }
        return '';
    }

    function resolveSelectionCriteriaLabel(criteriaKey) {
        const key = normalizePriorityFocus(criteriaKey);
        if (!key) {
            return '';
        }
        return priorityFocusLabel(key);
    }

    function resolveLearningCategoryLabelOverride(mode, categoryIds, details, fallbackFocus) {
        const normalizedMode = normalizeMode(mode);
        if (normalizedMode !== 'learning') {
            return '';
        }

        const ids = uniqueIntList(categoryIds || []);
        if (ids.length < 2) {
            return '';
        }

        const detailData = (details && typeof details === 'object') ? details : {};
        const focus = normalizePriorityFocus(detailData.priority_focus || fallbackFocus || '');
        if (!focus) {
            return '';
        }
        return priorityFocusLabel(focus);
    }

    function activityCategoryText(activity) {
        const item = (activity && typeof activity === 'object') ? activity : {};
        const details = (item.details && typeof item.details === 'object') ? item.details : {};
        const focusLabel = priorityFocusLabel(details.priority_focus || '');
        if (focusLabel) {
            return focusLabel;
        }
        const labels = uniqueIntList(item.category_ids || []).map(getCategoryLabel).filter(Boolean);
        if (labels.length) {
            return labels.join(', ');
        }
        return String(i18n.categoriesLabel || 'Categories');
    }

    function getVisibleCategoryIds() {
        const ignored = getIgnoredLookup();
        return categories.map(function (cat) {
            return parseInt(cat && cat.id, 10) || 0;
        }).filter(function (id) {
            return id > 0 && !ignored[id];
        });
    }

    function getKnownVisibleCategoryIds(rawIds) {
        return uniqueIntList(rawIds || []).filter(function (id) {
            return !isCategoryHidden(id) && !!getCategoryById(id);
        });
    }

    function resolveLaunchCategoryIds(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const requestedIds = getKnownVisibleCategoryIds(categoryIds || []);
        const fallbackIds = getKnownVisibleCategoryIds(opts.fallbackCategoryIds || []);
        const baseIds = requestedIds.length ? requestedIds : fallbackIds;
        if (!baseIds.length) {
            return [];
        }
        const launchMode = normalizeMode(opts.mode || '');
        return filterCategoryIdsByAspectBucket(baseIds, {
            preferCategoryId: parseInt(opts.preferCategoryId, 10) || baseIds[0] || 0,
            requireMatchingPresentation: launchMode === 'learning'
        });
    }

    function selectionHasGenderSupport(categoryIds) {
        const ids = uniqueIntList(categoryIds || []);
        if (!ids.length || !genderCfg.enabled) { return false; }
        for (let i = 0; i < ids.length; i++) {
            const cat = getCategoryById(ids[i]);
            if (cat && cat.gender_supported) {
                return true;
            }
        }
        return false;
    }

    function wordsetHasGenderSupport() {
        return selectionHasGenderSupport(getVisibleCategoryIds());
    }

    function getSelectionMinimumWordCount() {
        return Math.max(1, parseInt(genderCfg.min_count, 10) || 1);
    }

    function getSelectionFilteredMinimumWordCount() {
        return Math.max(getSelectionMinimumWordCount(), LEARNING_MIN_CHUNK_SIZE);
    }

    function hasActiveCategorySelection() {
        const ids = uniqueIntList(selectedCategoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        return ids.length > 0;
    }

    function getHardWordLookup() {
        const lookup = {};
        const rows = Array.isArray(analytics.words) ? analytics.words : [];
        rows.forEach(function (row) {
            const wordId = parseInt(row && row.id, 10) || 0;
            if (!wordId) { return; }
            if (analyticsWordIsDifficult(row)) {
                lookup[wordId] = true;
            }
        });
        return lookup;
    }

    function buildSelectionWordMetrics(categoryIds) {
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        const metrics = { total: 0, starred: 0, hard: 0, starredHard: 0 };
        if (!ids.length) {
            return metrics;
        }

        const categoryLookup = {};
        ids.forEach(function (id) {
            categoryLookup[id] = true;
        });
        const starredLookup = {};
        uniqueIntList(state.starred_word_ids || []).forEach(function (id) {
            starredLookup[id] = true;
        });
        const hardLookup = getHardWordLookup();
        const seen = {};
        const selectionMatchesVisibleScope = areCategorySetsEqual(ids, getVisibleCategoryIds());
        const analyticsScopeCategoryIds = uniqueIntList(
            analytics && analytics.scope && typeof analytics.scope === 'object'
                ? (analytics.scope.category_ids || [])
                : []
        );
        const analyticsScopeLookup = {};
        analyticsScopeCategoryIds.forEach(function (id) {
            analyticsScopeLookup[id] = true;
        });
        const analyticsCoversSelection = !!analyticsScopeCategoryIds.length && ids.every(function (id) {
            return !!analyticsScopeLookup[id];
        });
        const applyWord = function (wordId) {
            if (!wordId || seen[wordId]) { return; }
            seen[wordId] = true;
            metrics.total += 1;

            const isStarred = !!starredLookup[wordId];
            const isHard = !!hardLookup[wordId];
            if (isStarred) {
                metrics.starred += 1;
            }
            if (isHard) {
                metrics.hard += 1;
            }
            if (isStarred && isHard) {
                metrics.starredHard += 1;
            }
        };

        // Logged-in dashboards usually have this ready; keep it as the primary source.
        const analyticsRows = Array.isArray(analytics.words) ? analytics.words : [];
        analyticsRows.forEach(function (row) {
            const wordId = parseInt(row && row.id, 10) || 0;
            if (!wordId || seen[wordId]) { return; }
            const rowCategoryIds = uniqueIntList((row && row.category_ids) || []);
            if (!rowCategoryIds.length) {
                const categoryId = parseInt(row && row.category_id, 10) || 0;
                if (categoryId > 0) {
                    rowCategoryIds.push(categoryId);
                }
            }
            const inSelection = rowCategoryIds.some(function (id) {
                return !!categoryLookup[id];
            });
            if (!inSelection) { return; }
            applyWord(wordId);
        });
        if (analyticsCoversSelection) {
            return metrics;
        }

        // Fallback for logged-out selection flows where analytics words are often empty.
        let loadedCategoryCount = 0;
        ids.forEach(function (categoryId) {
            const rows = Array.isArray(wordsByCategory[categoryId]) ? wordsByCategory[categoryId] : [];
            if (!Array.isArray(wordsByCategory[categoryId])) { return; }
            loadedCategoryCount += 1;
            rows.forEach(function (row) {
                applyWord(parseInt(row && row.id, 10) || 0);
            });
        });
        if (selectionMatchesVisibleScope) {
            const summary = normalizeSummaryCounts(summaryCounts || {});
            if (summary.starred > metrics.starred) {
                metrics.starred = summary.starred;
            }
            if (summary.hard > metrics.hard) {
                metrics.hard = summary.hard;
            }
        }
        if (loadedCategoryCount === ids.length) {
            return metrics;
        }

        // Last resort: estimate from category totals so selection actions stay usable.
        // Avoid returning partial cached-word counts (common on the main view where
        // analytics.words is intentionally not bootstrapped and only some categories
        // may have been fetched yet).
        metrics.total = ids.reduce(function (sum, id) {
            const cat = getCategoryById(id);
            return sum + Math.max(0, parseInt(cat && cat.count, 10) || 0);
        }, 0);
        if (selectionMatchesVisibleScope) {
            const summary = normalizeSummaryCounts(summaryCounts || {});
            metrics.starred = Math.max(metrics.starred, summary.starred);
            metrics.hard = Math.max(metrics.hard, summary.hard);
        }

        return metrics;
    }

    function getSelectionEffectiveWordCount(metrics) {
        const data = (metrics && typeof metrics === 'object') ? metrics : { total: 0, starred: 0, hard: 0, starredHard: 0 };
        if (selectionStarredOnly) {
            return Math.max(0, parseInt(data.starred, 10) || 0);
        }
        if (selectionHardOnly) {
            return Math.max(0, parseInt(data.hard, 10) || 0);
        }
        return Math.max(0, parseInt(data.total, 10) || 0);
    }

    function getSelectableCategoryIdsFromUI() {
        const ids = [];
        $root.find('[data-ll-wordset-select]').each(function () {
            const id = parseInt($(this).val(), 10) || 0;
            if (id > 0 && !isCategoryHidden(id)) {
                ids.push(id);
            }
        });
        return uniqueIntList(ids);
    }

    function setSummaryMetricsLoadingState(isLoading) {
        summaryMetricsLoading = !!isLoading;

        if ($progressMiniChip.length) {
            $progressMiniChip
                .toggleClass('is-loading', summaryMetricsLoading)
                .attr('aria-busy', summaryMetricsLoading ? 'true' : 'false');
        }

        syncSelectAllButton();
    }

    function syncSelectAllButton() {
        if (!$selectAllButton.length) { return; }
        const summaryLoading = !!summaryMetricsLoading;
        $selectAllButton
            .toggleClass('is-loading', summaryLoading)
            .prop('disabled', summaryLoading)
            .attr('aria-disabled', summaryLoading ? 'true' : 'false');
        if (summaryLoading) {
            $selectAllButton.attr('aria-busy', 'true');
        } else {
            $selectAllButton.removeAttr('aria-busy');
        }

        const $selectAllWrap = $selectAllButton.closest('.ll-wordset-grid-tools');
        const allIds = getSelectableCategoryIdsFromUI();
        if (allIds.length <= 1) {
            $selectAllButton.prop('hidden', true);
            if ($selectAllWrap.length) {
                $selectAllWrap.prop('hidden', true);
            }
            return;
        }
        $selectAllButton.prop('hidden', false);
        if ($selectAllWrap.length) {
            $selectAllWrap.prop('hidden', false);
        }

        const selectedLookup = {};
        uniqueIntList(selectedCategoryIds || []).forEach(function (id) {
            selectedLookup[id] = true;
        });
        const selectedCount = allIds.filter(function (id) {
            return !!selectedLookup[id];
        }).length;
        const allSelected = selectedCount > 0 && selectedCount === allIds.length;
        $selectAllButton
            .attr('aria-pressed', allSelected ? 'true' : 'false')
            .text(allSelected ? (i18n.deselectAll || 'Deselect all') : (i18n.selectAll || 'Select all'));
    }

    function getVisibleCategoryCardRects() {
        const cards = [];
        $root.find('.ll-wordset-card[data-cat-id]').each(function () {
            const card = this;
            if (!card || !card.getBoundingClientRect) { return; }
            const style = window.getComputedStyle ? window.getComputedStyle(card) : null;
            if (style && style.display === 'none') { return; }
            const rect = card.getBoundingClientRect();
            if (!rect || rect.width <= 0) { return; }
            cards.push(rect);
        });
        return cards;
    }

    function syncSingleCategoryLayoutState(cardRects) {
        const cards = Array.isArray(cardRects) ? cardRects : getVisibleCategoryCardRects();
        const count = cards.length;
        $root
            .toggleClass('ll-wordset-page--single-category', count === 1)
            .attr('data-ll-visible-category-count', String(count));
        return cards;
    }

    function syncSelectAllAlignment() {
        if (!$grid.length) {
            $root.removeClass('ll-wordset-page--single-category').attr('data-ll-visible-category-count', '0');
            $root.css('--ll-wordset-grid-right-offset', '0px');
            return;
        }

        const gridEl = $grid.get(0);
        if (!gridEl || !gridEl.getBoundingClientRect) {
            $root.removeClass('ll-wordset-page--single-category').attr('data-ll-visible-category-count', '0');
            $root.css('--ll-wordset-grid-right-offset', '0px');
            return;
        }

        const visibleCardRects = syncSingleCategoryLayoutState();
        if (visibleCardRects.length <= 1) {
            $root.css('--ll-wordset-grid-right-offset', '0px');
            return;
        }

        let maxRight = null;
        visibleCardRects.forEach(function (rect) {
            maxRight = (maxRight === null) ? rect.right : Math.max(maxRight, rect.right);
        });

        if (maxRight === null) {
            $root.css('--ll-wordset-grid-right-offset', '0px');
            return;
        }

        const gridRect = gridEl.getBoundingClientRect();
        const offset = Math.max(0, Math.round(gridRect.right - maxRight));
        $root.css('--ll-wordset-grid-right-offset', String(offset) + 'px');
    }

    function scheduleSelectAllAlignment() {
        if (!hasInitialSelectAllAlignment) {
            // Run the first alignment sync immediately so the tool row offset is set before paint.
            hasInitialSelectAllAlignment = true;
            syncSelectAllAlignment();
            return;
        }
        if (selectAllAlignmentTimer) {
            clearTimeout(selectAllAlignmentTimer);
        }
        selectAllAlignmentTimer = setTimeout(function () {
            syncSelectAllAlignment();
        }, 0);
    }

    function syncPrimaryActionState() {
        const active = hasActiveCategorySelection();
        if ($topModeButtons.length) {
            $topModeButtons.each(function () {
                $(this)
                    .prop('disabled', active)
                    .attr('aria-disabled', active ? 'true' : 'false')
                    .toggleClass('is-disabled', active);
            });
        }
        if ($nextCard.length) {
            if (active) {
                $nextCard.addClass('is-disabled').attr('aria-disabled', 'true').prop('disabled', true);
            } else {
                $nextCard.prop('disabled', false);
                const next = normalizeNextActivity(nextActivity) || recommendationQueueHead();
                const hasNext = !!(next && next.mode);
                $nextCard.toggleClass('is-disabled', !hasNext).attr('aria-disabled', hasNext ? 'false' : 'true');
            }
        }
    }

    function syncGlobalPrefs() {
        window.llToolsStudyPrefs = window.llToolsStudyPrefs || {};
        window.llToolsStudyPrefs.starMode = state.star_mode;
        window.llToolsStudyPrefs.star_mode = state.star_mode;
        window.llToolsStudyPrefs.fastTransitions = !!state.fast_transitions;
        window.llToolsStudyPrefs.fast_transitions = !!state.fast_transitions;
        window.llToolsStudyPrefs.starredWordIds = uniqueIntList(state.starred_word_ids || []);

        if (!window.llToolsFlashcardsData || typeof window.llToolsFlashcardsData !== 'object') {
            return;
        }
        window.llToolsFlashcardsData.starMode = state.star_mode;
        window.llToolsFlashcardsData.star_mode = state.star_mode;
        window.llToolsFlashcardsData.fastTransitions = !!state.fast_transitions;
        window.llToolsFlashcardsData.fast_transitions = !!state.fast_transitions;

        const userStudy = window.llToolsFlashcardsData.userStudyState || {};
        userStudy.wordset_id = wordsetId;
        userStudy.category_ids = uniqueIntList(state.category_ids || []);
        userStudy.starred_word_ids = uniqueIntList(state.starred_word_ids || []);
        userStudy.star_mode = state.star_mode;
        userStudy.fast_transitions = !!state.fast_transitions;
        window.llToolsFlashcardsData.userStudyState = userStudy;
    }

    function saveStateDebounced(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (!isLoggedIn || !ajaxUrl || !nonce) { return; }
        const saveNow = function () {
            $.post(ajaxUrl, {
                action: 'll_user_study_save',
                nonce: nonce,
                wordset_id: wordsetId,
                category_ids: uniqueIntList(state.category_ids || []),
                starred_word_ids: uniqueIntList(state.starred_word_ids || []),
                star_mode: normalizeStarMode(state.star_mode),
                fast_transitions: state.fast_transitions ? 1 : 0
            }).done(function (res) {
                if (res && res.success && res.data && res.data.state) {
                    state = normalizeState(Object.assign({}, state, res.data.state));
                    if (Object.prototype.hasOwnProperty.call(res.data, 'next_activity') || Object.prototype.hasOwnProperty.call(res.data, 'recommendation_queue')) {
                        applyRecommendationPayload(res.data);
                    }
                    syncSettingsButtons();
                    syncGlobalPrefs();
                    renderProgressAnalytics();
                    scheduleProgressAnalyticsRefresh(180, { silent: true });
                }
            });
        };

        clearTimeout(saveStateTimer);
        if (opts.immediate) {
            saveNow();
            return;
        }
        saveStateTimer = setTimeout(saveNow, 250);
    }

    function isLatestGoalsSaveRequest(token) {
        const requestToken = parseInt(token, 10) || 0;
        return requestToken > 0 && requestToken === goalsSaveRequestToken;
    }

    function saveGoalsNow() {
        if (!isLoggedIn || !ajaxUrl || !nonce) {
            const rejected = $.Deferred().reject().promise();
            rejected.llToolsRequestToken = 0;
            return rejected;
        }

        const requestToken = ++goalsSaveRequestToken;
        const request = $.post(ajaxUrl, {
            action: 'll_user_study_save_goals',
            nonce: nonce,
            wordset_id: wordsetId,
            category_ids: getVisibleCategoryIds(),
            goals: JSON.stringify(goals)
        }).done(function (res) {
            if (!isLatestGoalsSaveRequest(requestToken)) {
                return;
            }
            if (res && res.success && res.data) {
                if (res.data.goals && typeof res.data.goals === 'object') {
                    goals = normalizeGoals(Object.assign({}, goals, res.data.goals));
                    renderHiddenCount();
                }
                applyRecommendationPayload(res.data);
                syncSettingsButtons();
            }
        });
        request.llToolsRequestToken = requestToken;
        return request;
    }

    function getMiniCountElements() {
        return {
            mastered: $miniMastered,
            studied: $miniStudied,
            new: $miniNew,
            starred: $miniStarred,
            hard: $miniHard
        };
    }

    function getRenderedMiniCountValues() {
        const elements = getMiniCountElements();
        const fallback = normalizeSummaryCounts(summaryCounts || {});
        const current = {};

        SUMMARY_COUNT_KEYS.forEach(function (key) {
            const $target = elements[key];
            if (!$target || !$target.length) {
                current[key] = fallback[key];
                return;
            }

            const rawText = String($target.text() || '').trim();
            const parsed = parseInt(rawText.replace(/[^\d-]/g, ''), 10);
            if (!Number.isFinite(parsed)) {
                current[key] = fallback[key];
                return;
            }
            current[key] = Math.max(0, parsed);
        });

        return normalizeSummaryCounts(current);
    }

    function clearMiniCountAnimationState() {
        if (typeof progressMiniCountPendingComplete === 'function') {
            const pendingComplete = progressMiniCountPendingComplete;
            progressMiniCountPendingComplete = null;
            try {
                pendingComplete();
            } catch (_) { /* no-op */ }
        }
        if (progressMiniCountRaf) {
            const cancelFrame = window.cancelAnimationFrame || window.clearTimeout;
            cancelFrame(progressMiniCountRaf);
            progressMiniCountRaf = 0;
        }
        if (progressMiniCountCleanupTimer) {
            window.clearTimeout(progressMiniCountCleanupTimer);
            progressMiniCountCleanupTimer = null;
        }
        if (progressMiniBurstCleanupTimer) {
            window.clearTimeout(progressMiniBurstCleanupTimer);
            progressMiniBurstCleanupTimer = null;
        }
    }

    function clearMiniProgressBurstParticles() {
        if (!$progressMiniChip.length) { return; }
        $progressMiniChip.find('.ll-wordset-progress-burst-dot').remove();
    }

    function clearProgressMiniStickyTimers() {
        if (progressMiniStickyRaf) {
            const cancelFrame = window.cancelAnimationFrame || window.clearTimeout;
            cancelFrame(progressMiniStickyRaf);
            progressMiniStickyRaf = 0;
        }
        if (progressMiniStickyHoldTimer) {
            window.clearTimeout(progressMiniStickyHoldTimer);
            progressMiniStickyHoldTimer = 0;
        }
        if (progressMiniStickyReleaseTimer) {
            window.clearTimeout(progressMiniStickyReleaseTimer);
            progressMiniStickyReleaseTimer = 0;
        }
        if (typeof progressMiniStickyReleaseFinalize === 'function') {
            const finalize = progressMiniStickyReleaseFinalize;
            progressMiniStickyReleaseFinalize = null;
            try {
                finalize();
            } catch (_) { /* no-op */ }
        }
    }

    function getViewportTopOcclusionOffsetPx() {
        const adminBar = document.getElementById('wpadminbar');
        if (!adminBar || typeof adminBar.getBoundingClientRect !== 'function') {
            return 0;
        }
        const style = window.getComputedStyle ? window.getComputedStyle(adminBar) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden')) {
            return 0;
        }
        const rect = adminBar.getBoundingClientRect();
        if (!rect || rect.height <= 0) {
            return 0;
        }
        if (rect.bottom <= 0) {
            return 0;
        }
        return Math.max(0, rect.bottom);
    }

    function getProgressMiniStickyAdminOffsetPx() {
        return getViewportTopOcclusionOffsetPx();
    }

    function isRectMeaningfullyVisibleToUser(rect) {
        if (!rect) { return false; }
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const topOcclusion = getViewportTopOcclusionOffsetPx();

        const horizontalVisible = rect.right >= 0 && rect.left <= viewportWidth;
        if (!horizontalVisible) {
            return false;
        }

        const visibleTop = Math.max(rect.top, topOcclusion);
        const visibleBottom = Math.min(rect.bottom, viewportHeight);
        const visibleHeight = Math.max(0, visibleBottom - visibleTop);
        if (visibleHeight <= 0) {
            return false;
        }

        const rectHeight = Math.max(1, rect.height || (rect.bottom - rect.top) || 1);
        const minVisibleHeight = Math.min(rectHeight, Math.max(14, rectHeight * 0.55));
        return visibleHeight >= minVisibleHeight;
    }

    function ensureProgressMiniStickyHost() {
        if (progressMiniStickyHostEl && progressMiniStickyHostEl.parentNode) {
            return progressMiniStickyHostEl;
        }
        const rootEl = ($root && $root.length) ? $root.get(0) : null;
        const mountTarget = (rootEl && rootEl.nodeType === 1) ? rootEl : document.body;
        if (!mountTarget) {
            return null;
        }
        const hostEl = document.createElement('div');
        hostEl.className = 'll-wordset-progress-mini-sticky-host';
        hostEl.setAttribute('aria-hidden', 'true');
        mountTarget.appendChild(hostEl);
        progressMiniStickyHostEl = hostEl;
        return progressMiniStickyHostEl;
    }

    function releaseStickyProgressMiniForMetricsAnimation(session, options) {
        const target = (session && typeof session === 'object') ? session : progressMiniStickySession;
        if (!target || target.released) {
            return;
        }

        target.released = true;
        if (progressMiniStickySession === target) {
            progressMiniStickySession = null;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const immediate = !!opts.immediate || prefersReducedMotion();
        clearProgressMiniStickyTimers();
        let finalized = false;

        const finalize = function () {
            if (finalized) { return; }
            finalized = true;
            if (progressMiniStickyReleaseFinalize === finalize) {
                progressMiniStickyReleaseFinalize = null;
            }
            const chipEl = target.chipEl;
            const placeholderEl = target.placeholderEl;
            const hostEl = target.hostEl;
            let restoreParent = null;
            let restoreBefore = null;

            if (placeholderEl && placeholderEl.parentNode) {
                restoreParent = placeholderEl.parentNode;
                restoreBefore = placeholderEl;
            } else if (target.sourceParent) {
                restoreParent = target.sourceParent;
                restoreBefore = (target.sourceNextSibling && target.sourceNextSibling.parentNode === restoreParent)
                    ? target.sourceNextSibling
                    : null;
            }

            if (chipEl) {
                if (restoreParent && chipEl.parentNode !== restoreParent) {
                    if (restoreBefore && restoreBefore.parentNode === restoreParent) {
                        restoreParent.insertBefore(chipEl, restoreBefore);
                    } else {
                        restoreParent.appendChild(chipEl);
                    }
                }
                chipEl.classList.remove('ll-wordset-progress-mini--sticky-floating');
            }

            if (placeholderEl && placeholderEl.parentNode) {
                placeholderEl.parentNode.removeChild(placeholderEl);
            }

            if (hostEl && hostEl.classList) {
                hostEl.classList.remove('is-active');
                if (!hostEl.childNodes.length) {
                    hostEl.classList.remove('is-mounted');
                }
            }
        };

        if (target.hostEl && target.hostEl.classList) {
            target.hostEl.classList.remove('is-active');
        }

        if (immediate) {
            finalize();
            return;
        }

        progressMiniStickyReleaseFinalize = finalize;
        progressMiniStickyReleaseTimer = window.setTimeout(function () {
            progressMiniStickyReleaseTimer = 0;
            finalize();
        }, 240);
    }

    function scheduleStickyProgressMiniReleaseForMetricsAnimation(session, options) {
        const target = (session && typeof session === 'object') ? session : progressMiniStickySession;
        if (!target || target.released) {
            return;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const immediate = !!opts.immediate || prefersReducedMotion();
        const holdMs = immediate ? 0 : Math.max(0, parseInt(opts.holdMs, 10) || PROGRESS_MINI_STICKY_HOLD_AFTER_ANIMATION_MS);

        if (progressMiniStickyHoldTimer) {
            window.clearTimeout(progressMiniStickyHoldTimer);
            progressMiniStickyHoldTimer = 0;
        }

        if (holdMs <= 0) {
            releaseStickyProgressMiniForMetricsAnimation(target, { immediate: immediate });
            return;
        }

        progressMiniStickyHoldTimer = window.setTimeout(function () {
            progressMiniStickyHoldTimer = 0;
            releaseStickyProgressMiniForMetricsAnimation(target, { immediate: immediate });
        }, holdMs);
    }

    function showStickyProgressMiniForMetricsAnimationIfOffscreen() {
        if (!$progressMiniChip.length) {
            return null;
        }
        const chipEl = $progressMiniChip.get(0);
        if (!chipEl || !chipEl.parentNode || typeof chipEl.getBoundingClientRect !== 'function') {
            return null;
        }

        const rect = chipEl.getBoundingClientRect();
        if (!rect || rect.width <= 0 || rect.height <= 0 || isRectMeaningfullyVisibleToUser(rect)) {
            return null;
        }

        if (progressMiniStickySession) {
            releaseStickyProgressMiniForMetricsAnimation(progressMiniStickySession, { immediate: true });
        }

        const hostEl = ensureProgressMiniStickyHost();
        if (!hostEl) {
            return null;
        }

        clearProgressMiniStickyTimers();

        const placeholderEl = document.createElement('span');
        placeholderEl.className = 'll-wordset-progress-mini-sticky-placeholder';
        placeholderEl.setAttribute('aria-hidden', 'true');
        placeholderEl.style.display = 'inline-block';
        placeholderEl.style.width = Math.max(1, Math.round(rect.width)) + 'px';
        placeholderEl.style.height = Math.max(1, Math.round(rect.height)) + 'px';

        const sourceParent = chipEl.parentNode;
        const sourceNextSibling = chipEl.nextSibling;
        sourceParent.insertBefore(placeholderEl, chipEl);

        const adminOffsetPx = getProgressMiniStickyAdminOffsetPx();
        const enterTravelPx = adminOffsetPx > 0 ? 10 : 18;
        const enterOffsetY = '-' + enterTravelPx + 'px';

        hostEl.style.setProperty('--ll-wordset-progress-mini-sticky-admin-offset', adminOffsetPx + 'px');
        hostEl.style.setProperty('--ll-wordset-progress-mini-sticky-enter-y', enterOffsetY);
        hostEl.classList.remove('is-active');
        hostEl.classList.add('is-mounted');

        chipEl.classList.add('ll-wordset-progress-mini--sticky-floating');
        hostEl.appendChild(chipEl);

        const session = {
            chipEl: chipEl,
            hostEl: hostEl,
            placeholderEl: placeholderEl,
            sourceParent: sourceParent,
            sourceNextSibling: sourceNextSibling,
            released: false
        };
        progressMiniStickySession = session;

        const raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 0); };
        progressMiniStickyRaf = raf(function () {
            progressMiniStickyRaf = 0;
            if (progressMiniStickySession !== session || session.released || !session.hostEl) {
                return;
            }
            session.hostEl.classList.add('is-active');
        });

        return session;
    }

    function buildMiniCountAnimationPlan(from, to, celebratePerfect) {
        const start = Math.max(0, parseInt(from, 10) || 0);
        const end = Math.max(0, parseInt(to, 10) || 0);
        const delta = Math.abs(end - start);
        if (!delta) {
            return null;
        }

        let maxVisibleSteps = 12;
        if (delta <= 6) {
            maxVisibleSteps = delta;
        } else if (delta <= 20) {
            maxVisibleSteps = 8;
        } else if (delta <= 80) {
            maxVisibleSteps = 10;
        }

        const stepSize = Math.max(1, Math.ceil(delta / Math.max(1, maxVisibleSteps)));
        const stepCount = Math.max(1, Math.ceil(delta / stepSize));
        const perStepMs = (delta <= 6) ? 190 : ((delta <= 20) ? 145 : 100);
        const maxDuration = celebratePerfect ? 1060 : 920;
        const duration = Math.max(460, Math.min(maxDuration, stepCount * perStepMs));

        return {
            from: start,
            to: end,
            delta: delta,
            stepSize: stepSize,
            stepCount: stepCount,
            direction: end > start ? 1 : -1,
            duration: duration,
            lastStep: 0,
            lastValue: start
        };
    }

    function triggerMiniCountTickPop($target) {
        if (!$target || !$target.length || prefersReducedMotion()) { return; }
        const el = $target.get(0);
        if (!el) { return; }
        $target.removeClass('is-count-tick');
        void el.offsetWidth;
        $target.addClass('is-count-tick');
    }

    function applyMiniCountValues(counts) {
        const normalized = normalizeSummaryCounts(counts || {});
        const elements = getMiniCountElements();
        SUMMARY_COUNT_KEYS.forEach(function (key) {
            const $target = elements[key];
            if ($target && $target.length) {
                $target.text(String(normalized[key]));
            }
        });
    }

    function launchMiniProgressConfetti() {
        if (!$progressMiniChip.length || prefersReducedMotion() || typeof window.confetti !== 'function') {
            return;
        }
        const chipEl = $progressMiniChip.get(0);
        if (!chipEl || typeof chipEl.getBoundingClientRect !== 'function') {
            return;
        }
        const rect = chipEl.getBoundingClientRect();
        const viewportWidth = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
        const viewportHeight = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
        const clamp = function (value, min, max) {
            return Math.min(max, Math.max(min, value));
        };
        const origin = {
            x: clamp((rect.left + (rect.width * 0.5)) / viewportWidth, 0.04, 0.96),
            y: clamp((rect.top + (rect.height * 0.55)) / viewportHeight, 0.04, 0.9)
        };

        try {
            window.confetti({
                particleCount: 34,
                spread: 62,
                startVelocity: 36,
                gravity: 1.05,
                scalar: 0.84,
                ticks: 90,
                zIndex: 1001,
                origin: origin
            });
            window.confetti({
                particleCount: 20,
                angle: 78,
                spread: 48,
                startVelocity: 31,
                gravity: 1.1,
                scalar: 0.68,
                ticks: 84,
                zIndex: 1001,
                origin: { x: Math.max(0.02, origin.x - 0.09), y: origin.y }
            });
            window.confetti({
                particleCount: 20,
                angle: 102,
                spread: 48,
                startVelocity: 31,
                gravity: 1.1,
                scalar: 0.68,
                ticks: 84,
                zIndex: 1001,
                origin: { x: Math.min(0.98, origin.x + 0.09), y: origin.y }
            });
        } catch (_) { /* no-op */ }
    }

    function spawnMiniProgressBurstParticles() {
        if (!$progressMiniChip.length || prefersReducedMotion()) { return; }
        const chipEl = $progressMiniChip.get(0);
        if (!chipEl) { return; }

        clearMiniProgressBurstParticles();

        const count = 14;
        for (let idx = 0; idx < count; idx += 1) {
            const dot = document.createElement('span');
            const angle = ((Math.PI * 2) * (idx / count)) + ((Math.random() - 0.5) * 0.32);
            const distance = 18 + (Math.random() * 24);
            const offsetX = Math.cos(angle) * distance;
            const offsetY = Math.sin(angle) * distance;
            const delay = Math.random() * 0.12;
            const size = 4 + (Math.random() * 4.6);

            dot.className = 'll-wordset-progress-burst-dot';
            dot.style.setProperty('--ll-burst-x', offsetX.toFixed(2) + 'px');
            dot.style.setProperty('--ll-burst-y', offsetY.toFixed(2) + 'px');
            dot.style.setProperty('--ll-burst-delay', delay.toFixed(3) + 's');
            dot.style.setProperty('--ll-burst-size', size.toFixed(2) + 'px');
            chipEl.appendChild(dot);
        }

        if (progressMiniBurstCleanupTimer) {
            window.clearTimeout(progressMiniBurstCleanupTimer);
        }
        progressMiniBurstCleanupTimer = window.setTimeout(function () {
            clearMiniProgressBurstParticles();
            progressMiniBurstCleanupTimer = null;
        }, 1180);
    }

    function animateMiniCountTransition(previousCounts, nextCounts, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const before = normalizeSummaryCounts(previousCounts || {});
        const after = normalizeSummaryCounts(nextCounts || {});
        const shouldAnimate = !!opts.animate && !prefersReducedMotion();
        const celebratePerfect = !!opts.celebratePerfect && !prefersReducedMotion();
        const onComplete = (typeof opts.onComplete === 'function') ? opts.onComplete : null;
        let didSignalComplete = false;
        const signalComplete = function () {
            if (didSignalComplete) { return; }
            didSignalComplete = true;
            if (progressMiniCountPendingComplete === signalComplete) {
                progressMiniCountPendingComplete = null;
            }
            if (!onComplete) { return; }
            try {
                onComplete();
            } catch (_) { /* no-op */ }
        };
        const elements = getMiniCountElements();
        const pills = {};
        SUMMARY_COUNT_KEYS.forEach(function (key) {
            const $target = elements[key];
            pills[key] = ($target && $target.length)
                ? $target.closest('.ll-wordset-progress-pill').first()
                : $();
        });

        clearMiniCountAnimationState();
        progressMiniCountPendingComplete = signalComplete;
        clearMiniProgressBurstParticles();

        if ($progressMiniChip.length) {
            $progressMiniChip.removeClass('is-syncing is-perfect-burst');
        }
        SUMMARY_COUNT_KEYS.forEach(function (key) {
            const $pill = pills[key];
            if ($pill && $pill.length) {
                $pill.removeClass('is-updating is-rising is-falling is-steady');
            }
            const $target = elements[key];
            if ($target && $target.length) {
                $target.removeClass('is-count-tick');
            }
        });

        if (!shouldAnimate) {
            applyMiniCountValues(after);
            if (celebratePerfect && $progressMiniChip.length) {
                if ($progressMiniChip[0]) {
                    void $progressMiniChip[0].offsetWidth;
                }
                $progressMiniChip.addClass('is-perfect-burst');
                spawnMiniProgressBurstParticles();
                launchMiniProgressConfetti();
                progressMiniCountCleanupTimer = window.setTimeout(function () {
                    $progressMiniChip.removeClass('is-perfect-burst');
                    clearMiniProgressBurstParticles();
                    progressMiniCountCleanupTimer = null;
                    signalComplete();
                }, 980);
                return;
            }
            signalComplete();
            return;
        }

        const settleDelay = celebratePerfect ? 980 : 340;
        const token = ++progressMiniCountToken;
        const raf = window.requestAnimationFrame || function (cb) {
            return window.setTimeout(function () { cb(Date.now()); }, 16);
        };
        const nowMs = function () {
            if (window.performance && typeof window.performance.now === 'function') {
                return window.performance.now();
            }
            return Date.now();
        };

        applyMiniCountValues(before);

        if ($progressMiniChip.length) {
            if ($progressMiniChip[0]) {
                void $progressMiniChip[0].offsetWidth;
            }
            $progressMiniChip.addClass('is-syncing');
        }

        const finalizeSequence = function () {
            if (token !== progressMiniCountToken) { return; }
            progressMiniCountRaf = 0;
            applyMiniCountValues(after);
            SUMMARY_COUNT_KEYS.forEach(function (key) {
                const $target = elements[key];
                if ($target && $target.length) {
                    $target.removeClass('is-count-tick');
                }
            });

            if (celebratePerfect && $progressMiniChip.length) {
                if ($progressMiniChip[0]) {
                    void $progressMiniChip[0].offsetWidth;
                }
                $progressMiniChip.addClass('is-perfect-burst');
                spawnMiniProgressBurstParticles();
                launchMiniProgressConfetti();
            }

            progressMiniCountCleanupTimer = window.setTimeout(function () {
                if (token !== progressMiniCountToken) { return; }
                if ($progressMiniChip.length) {
                    $progressMiniChip.removeClass('is-syncing is-perfect-burst');
                }
                SUMMARY_COUNT_KEYS.forEach(function (key) {
                    const $pill = pills[key];
                    if ($pill && $pill.length) {
                        $pill.removeClass('is-updating is-rising is-falling is-steady');
                    }
                    const $target = elements[key];
                    if ($target && $target.length) {
                        $target.removeClass('is-count-tick');
                    }
                });
                clearMiniProgressBurstParticles();
                progressMiniCountCleanupTimer = null;
                signalComplete();
            }, settleDelay);
        };

        const plans = {};
        const orderedKeys = [];
        SUMMARY_COUNT_KEYS.forEach(function (key) {
            const $target = elements[key];
            const $pill = pills[key];
            const from = before[key];
            const to = after[key];

            if (!$target || !$target.length) {
                return;
            }

            if (!$pill || !$pill.length || from === to) {
                $target.text(String(to));
                return;
            }

            const plan = buildMiniCountAnimationPlan(from, to, celebratePerfect);
            if (!plan) {
                $target.text(String(to));
                return;
            }

            plans[key] = plan;
            orderedKeys.push(key);
        });

        if (!orderedKeys.length) {
            finalizeSequence();
            return;
        }

        const runPlanAtIndex = function (index) {
            if (token !== progressMiniCountToken) { return; }
            if (index >= orderedKeys.length) {
                finalizeSequence();
                return;
            }

            const key = orderedKeys[index];
            const plan = plans[key];
            const $target = elements[key];
            const $pill = pills[key];
            if (!plan || !$target || !$target.length || !$pill || !$pill.length) {
                runPlanAtIndex(index + 1);
                return;
            }

            $pill.removeClass('is-updating is-rising is-falling is-steady');
            $pill.addClass('is-updating').addClass(plan.direction > 0 ? 'is-rising' : 'is-falling');

            const planStartTs = nowMs();
            const tick = function (timestamp) {
                if (token !== progressMiniCountToken) { return; }
                const ts = Number.isFinite(timestamp) ? timestamp : nowMs();
                const elapsed = Math.max(0, ts - planStartTs);
                const progress = Math.max(0, Math.min(1, elapsed / plan.duration));
                let desiredStep = Math.floor(progress * plan.stepCount);
                if (progress >= 1) {
                    desiredStep = plan.stepCount;
                }

                if (desiredStep > plan.lastStep) {
                    const moved = Math.min(plan.delta, desiredStep * plan.stepSize);
                    let value = plan.from + (plan.direction * moved);
                    if (plan.direction > 0) {
                        value = Math.min(plan.to, value);
                    } else {
                        value = Math.max(plan.to, value);
                    }

                    if (value !== plan.lastValue) {
                        plan.lastValue = value;
                        $target.text(String(value));
                        triggerMiniCountTickPop($target);
                    }
                    plan.lastStep = desiredStep;
                }

                if (progress < 1) {
                    progressMiniCountRaf = raf(tick);
                    return;
                }

                progressMiniCountRaf = 0;
                if (plan.lastValue !== plan.to) {
                    plan.lastValue = plan.to;
                    $target.text(String(plan.to));
                    triggerMiniCountTickPop($target);
                }

                const nextDelay = celebratePerfect ? 170 : 130;
                window.setTimeout(function () {
                    if (token !== progressMiniCountToken) { return; }
                    $pill.removeClass('is-updating is-rising is-falling is-steady');
                    runPlanAtIndex(index + 1);
                }, nextDelay);
            };

            progressMiniCountRaf = raf(tick);
        };

        runPlanAtIndex(0);
    }

    function renderMiniCounts(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const previousCounts = Object.prototype.hasOwnProperty.call(opts, 'previousCounts')
            ? normalizeSummaryCounts(opts.previousCounts || {})
            : normalizeSummaryCounts(summaryCounts || {});
        const nextCounts = normalizeSummaryCounts(summaryCounts || {});
        animateMiniCountTransition(previousCounts, nextCounts, {
            animate: !!opts.animate,
            celebratePerfect: !!opts.celebratePerfect,
            onComplete: (typeof opts.onComplete === 'function') ? opts.onComplete : null
        });
    }

    function summaryCountsChanged(leftCounts, rightCounts) {
        const left = normalizeSummaryCounts(leftCounts || {});
        const right = normalizeSummaryCounts(rightCounts || {});
        return SUMMARY_COUNT_KEYS.some(function (key) {
            return left[key] !== right[key];
        });
    }

    function hasPendingPerfectCelebration() {
        if (!pendingPerfectCelebration) {
            return false;
        }
        if (!pendingPerfectCelebrationAt) {
            return true;
        }
        if ((Date.now() - pendingPerfectCelebrationAt) > PERFECT_CELEBRATION_MAX_AGE_MS) {
            clearPerfectCelebrationPending();
            return false;
        }
        return true;
    }

    function markPerfectCelebrationPending() {
        pendingPerfectCelebration = true;
        pendingPerfectCelebrationAt = Date.now();
    }

    function clearPerfectCelebrationPending() {
        pendingPerfectCelebration = false;
        pendingPerfectCelebrationAt = 0;
    }

    function normalizeFlashcardResultSummary(detail) {
        if (!detail || typeof detail !== 'object') {
            return null;
        }
        const mode = normalizeMode(detail.mode || '');
        if (!mode) {
            return null;
        }
        const total = Math.max(0, parseInt(detail.total, 10) || 0);
        const correct = Math.max(0, parseInt(detail.correct, 10) || 0);
        return {
            mode: mode,
            total: total,
            correct: correct
        };
    }

    function isPerfectFlashcardResult(summary) {
        if (!summary || typeof summary !== 'object') {
            return false;
        }
        const mode = normalizeMode(summary.mode || '');
        if (mode !== 'practice' && mode !== 'self-check') {
            return false;
        }
        const total = Math.max(0, parseInt(summary.total, 10) || 0);
        const correct = Math.max(0, parseInt(summary.correct, 10) || 0);
        return total > 0 && correct >= total;
    }

    function getHiddenCountValue() {
        const ignored = getIgnoredLookup();
        let count = 0;
        categories.forEach(function (cat) {
            const id = parseInt(cat && cat.id, 10) || 0;
            if (id > 0 && ignored[id]) {
                count += 1;
            }
        });
        return count;
    }

    function pulseHiddenLinkAttention(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const allowScroll = opts.scrollIntoView !== false;
        if (!$hiddenLink.length) { return; }

        const el = $hiddenLink.get(0);
        if (!el) { return; }

        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const rect = el.getBoundingClientRect();
        const inView = rect.bottom >= 0 && rect.top <= viewportHeight;
        if (allowScroll && !inView && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        }

        $hiddenLink.removeClass('is-updated');
        const raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 0); };
        raf(function () {
            $hiddenLink.addClass('is-updated');
        });

        if (pulseHiddenLinkAttention.timer) {
            window.clearTimeout(pulseHiddenLinkAttention.timer);
        }
        pulseHiddenLinkAttention.timer = window.setTimeout(function () {
            $hiddenLink.removeClass('is-updated');
        }, 1100);
    }

    function renderHiddenCount(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const count = getHiddenCountValue();
        if ($hiddenCount.length) {
            $hiddenCount.text(String(count));
        }
        if ($hiddenLink.length) {
            const labelTemplate = i18n.hiddenCountLabel || 'Hidden categories: %d';
            $hiddenLink.attr('aria-label', formatTemplate(labelTemplate, [count]));
            const hasHiddenCategories = count > 0;
            $hiddenLink.prop('hidden', !hasHiddenCategories);
            if (!hasHiddenCategories) {
                $hiddenLink.removeClass('is-updated');
            }
        }
        if (opts.pulse && count > 0) {
            pulseHiddenLinkAttention({ scrollIntoView: opts.scrollIntoView });
        }
    }

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function isRectInViewport(rect) {
        if (!rect) { return false; }
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        return rect.bottom >= 0 && rect.top <= viewportHeight && rect.right >= 0 && rect.left <= viewportWidth;
    }

    function isRectNearViewportCenter(rect) {
        if (!rect) { return false; }
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        if (viewportHeight <= 0) { return false; }
        const centerY = rect.top + (rect.height * 0.5);
        const viewportCenterY = viewportHeight * 0.5;
        const allowedDistance = Math.max(CATEGORY_PROGRESS_CENTER_MIN_BAND_PX, viewportHeight * CATEGORY_PROGRESS_CENTER_BAND_RATIO);
        return Math.abs(centerY - viewportCenterY) <= allowedDistance;
    }

    function isRectInCategoryProgressFocusZone(rect) {
        return isRectInViewport(rect) && isRectNearViewportCenter(rect);
    }

    function clampProgressPercent(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) { return 0; }
        return Math.max(0, Math.min(100, numeric));
    }

    function roundProgressPercent(value) {
        return Math.round(clampProgressPercent(value) * 100) / 100;
    }

    function formatProgressPercent(value) {
        return roundProgressPercent(value).toFixed(2) + '%';
    }

    function parseCardProgressSegmentPercent($segment) {
        if (!$segment || !$segment.length) { return 0; }
        const segmentEl = $segment.get(0);
        if (!segmentEl) { return 0; }

        const cached = parseFloat(segmentEl.getAttribute('data-ll-progress-pct') || '');
        if (Number.isFinite(cached)) {
            return roundProgressPercent(cached);
        }

        const inline = String(segmentEl.style.width || '').trim();
        if (inline.slice(-1) === '%') {
            const parsedInline = parseFloat(inline.slice(0, -1));
            if (Number.isFinite(parsedInline)) {
                return roundProgressPercent(parsedInline);
            }
        }

        const styleAttr = String($segment.attr('style') || '');
        const match = styleAttr.match(/width\s*:\s*([0-9.]+)%/i);
        if (match && Number.isFinite(parseFloat(match[1]))) {
            return roundProgressPercent(parseFloat(match[1]));
        }
        return 0;
    }

    function setCardProgressSegmentPercent($segment, value) {
        if (!$segment || !$segment.length) { return; }
        const segmentEl = $segment.get(0);
        if (!segmentEl) { return; }
        const normalized = roundProgressPercent(value);
        segmentEl.style.width = formatProgressPercent(normalized);
        segmentEl.setAttribute('data-ll-progress-pct', normalized.toFixed(2));
    }

    function getWordsetCardByCategoryId(categoryId) {
        const id = parseInt(categoryId, 10) || 0;
        if (!id) { return $(); }
        return $root.find('.ll-wordset-card[data-cat-id="' + id + '"]').first();
    }

    function getWordsetCardProgressSegments($card) {
        const $source = ($card && $card.length) ? $card : $();
        const $track = $source.find('.ll-wordset-card__progress-track').first();
        return {
            track: $track,
            mastered: $track.find('.ll-wordset-card__progress-segment--mastered').first(),
            studied: $track.find('.ll-wordset-card__progress-segment--studied').first(),
            new: $track.find('.ll-wordset-card__progress-segment--new').first()
        };
    }

    function syncWordsetCardProgressSegmentVisualState($card) {
        const segments = getWordsetCardProgressSegments($card);
        const ordered = [segments.mastered, segments.studied, segments.new].filter(function ($segment) {
            return !!($segment && $segment.length);
        });
        if (!ordered.length) { return; }

        ordered.forEach(function ($segment) {
            $segment.removeClass('is-visible-edge-left is-visible-edge-right has-left-divider is-progress-hidden');
        });

        const visible = [];
        ordered.forEach(function ($segment) {
            const pct = parseCardProgressSegmentPercent($segment);
            if (pct > 0.001) {
                visible.push($segment);
            } else {
                $segment.addClass('is-progress-hidden');
            }
        });

        if (!visible.length) { return; }

        visible[0].addClass('is-visible-edge-left');
        visible[visible.length - 1].addClass('is-visible-edge-right');
        for (let i = 1; i < visible.length; i++) {
            visible[i].addClass('has-left-divider');
        }
    }

    function syncAllWordsetCardProgressSegmentVisualState() {
        $root.find('.ll-wordset-card').each(function () {
            syncWordsetCardProgressSegmentVisualState($(this));
        });
    }

    function readWordsetCardProgressPercents($card) {
        const segments = getWordsetCardProgressSegments($card);
        return {
            mastered: parseCardProgressSegmentPercent(segments.mastered),
            studied: parseCardProgressSegmentPercent(segments.studied),
            new: parseCardProgressSegmentPercent(segments.new)
        };
    }

    function buildWordsetCardProgressPercentsFromAnalyticsRow(row) {
        const source = (row && typeof row === 'object') ? row : {};
        const masteredWords = Math.max(0, parseInt(source.mastered_words, 10) || 0);
        const studiedTotal = Math.max(masteredWords, parseInt(source.studied_words, 10) || 0);
        const studiedWords = Math.max(0, studiedTotal - masteredWords);
        const totalFromRow = Math.max(0, parseInt(source.word_count, 10) || 0);
        const explicitNewWords = parseInt(source.new_words, 10);
        const newWords = Number.isFinite(explicitNewWords)
            ? Math.max(0, explicitNewWords)
            : Math.max(0, totalFromRow - studiedTotal);
        const fallbackTotal = masteredWords + studiedWords + newWords;
        const totalWords = Math.max(1, totalFromRow, fallbackTotal);

        const masteredPct = roundProgressPercent((masteredWords * 100) / totalWords);
        const studiedPct = roundProgressPercent(Math.min(100 - masteredPct, (studiedWords * 100) / totalWords));
        const newPct = roundProgressPercent(Math.max(0, 100 - masteredPct - studiedPct));

        return {
            mastered: masteredPct,
            studied: studiedPct,
            new: newPct
        };
    }

    function hasWordsetCardProgressDelta(current, next) {
        const left = (current && typeof current === 'object') ? current : {};
        const right = (next && typeof next === 'object') ? next : {};
        const tolerance = 0.09;
        return ['mastered', 'studied', 'new'].some(function (key) {
            const from = roundProgressPercent(left[key]);
            const to = roundProgressPercent(right[key]);
            return Math.abs(to - from) > tolerance;
        });
    }

    function setWordsetCardProgressPercents($card, values, options) {
        const segments = getWordsetCardProgressSegments($card);
        if (!segments.track.length) { return; }

        const next = (values && typeof values === 'object') ? values : {};
        const opts = (options && typeof options === 'object') ? options : {};
        const shouldAnimate = !!opts.animate && !prefersReducedMotion();
        const trackEl = segments.track.get(0);
        if (!trackEl) { return; }

        if (trackEl.__llProgressAnimTimer) {
            window.clearTimeout(trackEl.__llProgressAnimTimer);
            trackEl.__llProgressAnimTimer = 0;
        }

        const applyNow = function () {
            setCardProgressSegmentPercent(segments.mastered, next.mastered);
            setCardProgressSegmentPercent(segments.studied, next.studied);
            setCardProgressSegmentPercent(segments.new, next.new);
            syncWordsetCardProgressSegmentVisualState($card);
        };

        if (!shouldAnimate) {
            segments.track.removeClass('is-progress-updating');
            applyNow();
            return;
        }

        segments.track.removeClass('is-progress-updating');
        void trackEl.offsetWidth;
        segments.track.addClass('is-progress-updating');

        const raf = window.requestAnimationFrame || function (cb) {
            return window.setTimeout(function () { cb(); }, 16);
        };
        raf(function () {
            raf(applyNow);
        });

        trackEl.__llProgressAnimTimer = window.setTimeout(function () {
            segments.track.removeClass('is-progress-updating');
            trackEl.__llProgressAnimTimer = 0;
        }, CATEGORY_PROGRESS_ANIMATION_DURATION_MS);
    }

    function schedulePendingCategoryProgressVisibilityCheck() {
        if (categoryProgressVisibilityRaf) { return; }
        const raf = window.requestAnimationFrame || function (cb) {
            return window.setTimeout(function () { cb(); }, 16);
        };
        categoryProgressVisibilityRaf = raf(function () {
            categoryProgressVisibilityRaf = 0;
            processVisiblePendingCategoryProgressUpdates();
        });
    }

    function normalizePendingCategoryProgressEntry(rawEntry) {
        if (!rawEntry || typeof rawEntry !== 'object') {
            return null;
        }
        if (rawEntry.values && typeof rawEntry.values === 'object') {
            return {
                values: rawEntry.values,
                awaitNextView: !!rawEntry.awaitNextView
            };
        }
        return {
            values: rawEntry,
            awaitNextView: false
        };
    }

    function setPendingCategoryProgressEntry(categoryId, values, options) {
        const id = parseInt(categoryId, 10) || 0;
        if (!id || !values || typeof values !== 'object') { return; }
        const opts = (options && typeof options === 'object') ? options : {};
        pendingCategoryProgressUpdates[id] = {
            values: values,
            awaitNextView: !!opts.awaitNextView
        };
    }

    function applyPendingCategoryProgressForCard($card, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const force = !!opts.force;
        const cardEl = $card && $card.length ? $card.get(0) : null;
        if (!cardEl || typeof cardEl.getBoundingClientRect !== 'function') { return false; }

        const categoryId = parseInt($card.attr('data-cat-id'), 10) || 0;
        if (!categoryId) { return false; }

        const pending = normalizePendingCategoryProgressEntry(pendingCategoryProgressUpdates[categoryId]);
        if (!pending || !pending.values || typeof pending.values !== 'object') {
            return false;
        }

        if (pending.awaitNextView) {
            return false;
        }

        if (categoryProgressHoldForMetrics && !force) {
            return false;
        }

        if (!isRectInCategoryProgressFocusZone(cardEl.getBoundingClientRect())) {
            return false;
        }

        const current = readWordsetCardProgressPercents($card);
        if (!hasWordsetCardProgressDelta(current, pending.values)) {
            delete pendingCategoryProgressUpdates[categoryId];
            return false;
        }

        setWordsetCardProgressPercents($card, pending.values, { animate: true });
        delete pendingCategoryProgressUpdates[categoryId];
        return true;
    }

    function processVisiblePendingCategoryProgressUpdates() {
        const ids = Object.keys(pendingCategoryProgressUpdates);
        if (!ids.length) { return; }

        ids.forEach(function (rawId) {
            const categoryId = parseInt(rawId, 10) || 0;
            if (!categoryId) {
                delete pendingCategoryProgressUpdates[rawId];
                return;
            }
            const $card = getWordsetCardByCategoryId(categoryId);
            if (!$card.length) {
                delete pendingCategoryProgressUpdates[categoryId];
                return;
            }

            const pending = normalizePendingCategoryProgressEntry(pendingCategoryProgressUpdates[categoryId]);
            if (!pending) {
                delete pendingCategoryProgressUpdates[categoryId];
                return;
            }

            const cardEl = $card.get(0);
            const isVisible = !!(cardEl && typeof cardEl.getBoundingClientRect === 'function' && isRectInViewport(cardEl.getBoundingClientRect()));
            if (pending.awaitNextView && !isVisible) {
                pending.awaitNextView = false;
                pendingCategoryProgressUpdates[categoryId] = pending;
                return;
            }

            applyPendingCategoryProgressForCard($card);
        });
    }

    function getTopRowCategoryCardEntries() {
        const entries = [];
        let minTop = null;

        $root.find('.ll-wordset-card[data-cat-id]').each(function () {
            const cardEl = this;
            if (!cardEl || typeof cardEl.getBoundingClientRect !== 'function') { return; }

            const categoryId = parseInt(cardEl.getAttribute('data-cat-id'), 10) || 0;
            if (!categoryId) { return; }

            const rect = cardEl.getBoundingClientRect();
            if (!rect || rect.width <= 0 || rect.height <= 0) { return; }

            minTop = (minTop === null) ? rect.top : Math.min(minTop, rect.top);
            entries.push({
                id: categoryId,
                rect: rect
            });
        });

        if (!entries.length || minTop === null) {
            return [];
        }

        const topRowTolerancePx = 18;
        return entries.filter(function (entry) {
            return Math.abs(entry.rect.top - minTop) <= topRowTolerancePx;
        });
    }

    function scheduleTopRowCategoryProgressAfterMetrics() {
        if (categoryProgressPostMetricsTimer) {
            window.clearTimeout(categoryProgressPostMetricsTimer);
            categoryProgressPostMetricsTimer = 0;
        }

        // Defer briefly so the metrics chip animation can finish before firing the card update.
        categoryProgressPostMetricsTimer = window.setTimeout(function () {
            categoryProgressPostMetricsTimer = 0;

            const topRowEntries = getTopRowCategoryCardEntries();
            if (topRowEntries.length) {
                topRowEntries.forEach(function (entry) {
                    const categoryId = parseInt(entry && entry.id, 10) || 0;
                    if (!categoryId || !isRectInCategoryProgressFocusZone(entry.rect)) {
                        return;
                    }

                    const pending = normalizePendingCategoryProgressEntry(pendingCategoryProgressUpdates[categoryId]);
                    if (!pending || !pending.awaitNextView) {
                        return;
                    }

                    pending.awaitNextView = false;
                    pendingCategoryProgressUpdates[categoryId] = pending;
                    applyPendingCategoryProgressForCard(getWordsetCardByCategoryId(categoryId), { force: true });
                });
            }

            // Release "next view" deferrals so cards do not get stuck waiting for a full leave/re-enter cycle.
            Object.keys(pendingCategoryProgressUpdates).forEach(function (rawId) {
                const categoryId = parseInt(rawId, 10) || 0;
                if (!categoryId) { return; }
                const pending = normalizePendingCategoryProgressEntry(pendingCategoryProgressUpdates[categoryId]);
                if (!pending || !pending.awaitNextView) {
                    return;
                }
                pending.awaitNextView = false;
                pendingCategoryProgressUpdates[categoryId] = pending;
            });

            categoryProgressHoldForMetrics = false;
            schedulePendingCategoryProgressVisibilityCheck();
        }, CATEGORY_PROGRESS_POST_METRICS_DELAY_MS);
    }

    function observeCategoryCardsForProgressVisibility() {
        if (!categoryProgressVisibilityObserver || typeof categoryProgressVisibilityObserver.observe !== 'function') {
            return;
        }
        $root.find('.ll-wordset-card[data-cat-id]').each(function () {
            if (!this) { return; }
            try {
                categoryProgressVisibilityObserver.observe(this);
            } catch (_) { /* no-op */ }
        });
    }

    function ensureCategoryProgressVisibilityTracking() {
        if (!categoryProgressVisibilityEventBound && typeof document !== 'undefined' && document.addEventListener) {
            categoryProgressVisibilityEventBound = true;
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    schedulePendingCategoryProgressVisibilityCheck();
                }
            });
        }

        if (!categoryProgressFallbackBound) {
            categoryProgressFallbackBound = true;
            $(window)
                .off('scroll.llWordsetProgressVisibility resize.llWordsetProgressVisibility')
                .on('scroll.llWordsetProgressVisibility resize.llWordsetProgressVisibility', function () {
                    schedulePendingCategoryProgressVisibilityCheck();
                });
        }

        if (categoryProgressVisibilityObserver) {
            observeCategoryCardsForProgressVisibility();
            return;
        }

        if (typeof window.IntersectionObserver === 'function') {
            categoryProgressVisibilityObserver = new window.IntersectionObserver(function (entries) {
                (Array.isArray(entries) ? entries : []).forEach(function (entry) {
                    if (!entry || !entry.target) { return; }
                    const $card = $(entry.target);
                    if (!$card.length) { return; }

                    const categoryId = parseInt($card.attr('data-cat-id'), 10) || 0;
                    const pending = normalizePendingCategoryProgressEntry(pendingCategoryProgressUpdates[categoryId]);
                    if (categoryId && pending && !entry.isIntersecting && (entry.intersectionRatio || 0) <= 0) {
                        if (pending.awaitNextView) {
                            pending.awaitNextView = false;
                            pendingCategoryProgressUpdates[categoryId] = pending;
                        }
                        return;
                    }
                    if (!entry.isIntersecting && (entry.intersectionRatio || 0) <= 0) { return; }
                    applyPendingCategoryProgressForCard($card);
                });
            }, {
                root: null,
                threshold: [0, 0.12, 0.35]
            });
            observeCategoryCardsForProgressVisibility();
            return;
        }
    }

    function queueCategoryProgressUpdatesFromAnalytics(analyticsPayload, options) {
        const analyticsData = (analyticsPayload && typeof analyticsPayload === 'object') ? analyticsPayload : {};
        const opts = (options && typeof options === 'object') ? options : {};
        const deferVisible = !!opts.deferVisible;
        const syncAllImmediately = !!opts.syncAllImmediately;
        const animateCards = opts.animateCards !== false;
        const rows = Array.isArray(analyticsData.categories) ? analyticsData.categories : [];
        if (!rows.length) { return false; }

        let hasCategoryProgressChanges = false;

        ensureCategoryProgressVisibilityTracking();

        rows.forEach(function (row) {
            const source = (row && typeof row === 'object') ? row : {};
            const categoryId = parseInt(source.id, 10) || 0;
            if (!categoryId) { return; }

            const $card = getWordsetCardByCategoryId(categoryId);
            if (!$card.length) {
                delete pendingCategoryProgressUpdates[categoryId];
                return;
            }

            const nextValues = buildWordsetCardProgressPercentsFromAnalyticsRow(source);
            const currentValues = readWordsetCardProgressPercents($card);
            if (!hasWordsetCardProgressDelta(currentValues, nextValues)) {
                delete pendingCategoryProgressUpdates[categoryId];
                return;
            }
            hasCategoryProgressChanges = true;

            if (syncAllImmediately) {
                setWordsetCardProgressPercents($card, nextValues, { animate: animateCards });
                delete pendingCategoryProgressUpdates[categoryId];
                return;
            }

            const cardEl = $card.get(0);
            const cardRect = (cardEl && typeof cardEl.getBoundingClientRect === 'function')
                ? cardEl.getBoundingClientRect()
                : null;
            const isVisible = isRectInViewport(cardRect);
            const isInFocusZone = isRectInCategoryProgressFocusZone(cardRect);
            if (isInFocusZone && !deferVisible) {
                setWordsetCardProgressPercents($card, nextValues, { animate: true });
                delete pendingCategoryProgressUpdates[categoryId];
            } else {
                setPendingCategoryProgressEntry(categoryId, nextValues, {
                    awaitNextView: deferVisible && isVisible
                });
            }
        });

        schedulePendingCategoryProgressVisibilityCheck();
        return hasCategoryProgressChanges;
    }

    function scrollToHiddenChipForHideFlight() {
        if (!$hiddenLink.length) { return; }
        const linkEl = $hiddenLink.get(0);
        if (!linkEl || typeof linkEl.getBoundingClientRect !== 'function') { return; }
        const rect = linkEl.getBoundingClientRect();
        if (!rect || isRectInViewport(rect)) { return; }
        if (typeof linkEl.scrollIntoView !== 'function') { return; }
        linkEl.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'start',
            inline: 'nearest'
        });
    }

    function getHiddenChipAnimationTargetRect() {
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const fallbackWidth = 44;
        const fallbackHeight = 36;
        const fallbackLeft = Math.max(14, viewportWidth - fallbackWidth - 14);
        const fallbackTop = 14;

        if ($hiddenLink.length) {
            const linkEl = $hiddenLink.get(0);
            if (linkEl && typeof linkEl.getBoundingClientRect === 'function') {
                const rect = linkEl.getBoundingClientRect();
                if (rect && rect.width > 0 && rect.height > 0) {
                    const width = rect.width;
                    const height = rect.height;
                    const minLeft = 14;
                    const minTop = 14;
                    const maxLeft = Math.max(minLeft, viewportWidth - width - 14);
                    const maxTop = Math.max(minTop, viewportHeight - height - 14);
                    return {
                        left: Math.min(maxLeft, Math.max(minLeft, rect.left)),
                        top: Math.min(maxTop, Math.max(minTop, rect.top)),
                        width: width,
                        height: height
                    };
                }
            }
        }

        return {
            left: fallbackLeft,
            top: fallbackTop,
            width: fallbackWidth,
            height: fallbackHeight
        };
    }

    function animateGridReflowAfterCardRemoval($card) {
        const $remainingCards = $grid.find('.ll-wordset-card').not($card);
        if (!$card.length) {
            scheduleSelectAllAlignment();
            return;
        }

        if (prefersReducedMotion()) {
            $card.remove();
            scheduleSelectAllAlignment();
            return;
        }

        const firstRects = new Map();
        $remainingCards.each(function () {
            if (this && typeof this.getBoundingClientRect === 'function') {
                firstRects.set(this, this.getBoundingClientRect());
            }
        });

        $card.remove();
        scheduleSelectAllAlignment();

        const movingCards = [];
        $remainingCards.each(function () {
            const first = firstRects.get(this);
            if (!first || !this || !this.isConnected || typeof this.getBoundingClientRect !== 'function') {
                return;
            }
            const last = this.getBoundingClientRect();
            const deltaX = first.left - last.left;
            const deltaY = first.top - last.top;
            if (Math.abs(deltaX) < 1 && Math.abs(deltaY) < 1) {
                return;
            }
            this.style.transition = 'none';
            this.style.transform = 'translate(' + deltaX + 'px, ' + deltaY + 'px)';
            this.style.willChange = 'transform';
            movingCards.push(this);
        });

        if (!movingCards.length) {
            return;
        }

        const raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 0); };
        raf(function () {
            movingCards.forEach(function (el) {
                el.style.transition = 'transform 360ms cubic-bezier(0.22, 1, 0.36, 1)';
                el.style.transform = 'translate(0px, 0px)';
            });
            window.setTimeout(function () {
                movingCards.forEach(function (el) {
                    el.style.transition = '';
                    el.style.transform = '';
                    el.style.willChange = '';
                });
            }, 390);
        });
    }

    function animateHiddenCardRemoval($card) {
        if (!$card.length) {
            scheduleSelectAllAlignment();
            return;
        }

        const cardEl = $card.get(0);
        if (!cardEl || typeof cardEl.getBoundingClientRect !== 'function') {
            $card.remove();
            scheduleSelectAllAlignment();
            return;
        }

        const reducedMotion = prefersReducedMotion();
        const sourceRect = cardEl.getBoundingClientRect();
        let cloneEl = null;

        if (!reducedMotion && sourceRect.width > 0 && sourceRect.height > 0) {
            scrollToHiddenChipForHideFlight();
            const targetRect = getHiddenChipAnimationTargetRect();
            const sourceCenterX = sourceRect.left + (sourceRect.width / 2);
            const sourceCenterY = sourceRect.top + (sourceRect.height / 2);
            const targetCenterX = targetRect.left + (targetRect.width / 2);
            const targetCenterY = targetRect.top + (targetRect.height / 2);
            const flightX = Math.round(targetCenterX - sourceCenterX);
            const flightY = Math.round(targetCenterY - sourceCenterY);
            const midX = Math.round(flightX * 0.58);
            const lift = Math.max(44, Math.min(120, sourceRect.height * 0.8));
            const midY = Math.round((flightY * 0.5) - lift);

            cloneEl = cardEl.cloneNode(true);
            cloneEl.classList.add('ll-wordset-card--hide-flight-clone');
            cloneEl.setAttribute('aria-hidden', 'true');
            cloneEl.removeAttribute('id');
            cloneEl.style.left = sourceRect.left + 'px';
            cloneEl.style.top = sourceRect.top + 'px';
            cloneEl.style.width = sourceRect.width + 'px';
            cloneEl.style.height = sourceRect.height + 'px';
            cloneEl.style.setProperty('--ll-wordset-hide-flight-x', flightX + 'px');
            cloneEl.style.setProperty('--ll-wordset-hide-flight-y', flightY + 'px');
            cloneEl.style.setProperty('--ll-wordset-hide-flight-x-mid', midX + 'px');
            cloneEl.style.setProperty('--ll-wordset-hide-flight-y-mid', midY + 'px');
            document.body.appendChild(cloneEl);

            const raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 0); };
            raf(function () {
                if (cloneEl) {
                    cloneEl.classList.add('is-animating');
                }
            });

            window.setTimeout(function () {
                if (cloneEl && cloneEl.parentNode) {
                    cloneEl.parentNode.removeChild(cloneEl);
                }
            }, 700);
        }

        $card.addClass('is-removing');
        window.setTimeout(function () {
            animateGridReflowAfterCardRemoval($card);
        }, reducedMotion ? 0 : 140);
    }

    function refreshSummaryCounts(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const animate = opts.animate !== false;
        const celebratePerfect = !!opts.celebratePerfect;
        const stickyMiniWhenOffscreen = !!opts.stickyMiniWhenOffscreen;
        const syncAllCategoryProgressImmediately = !!opts.syncAllCategoryProgressImmediately;
        const deferVisibleCategoryProgress = !!opts.deferVisibleCategoryProgress
            || categoryProgressHoldForMetrics
            || !!categoryProgressPostMetricsTimer;
        const onComplete = (typeof opts.onComplete === 'function') ? opts.onComplete : null;
        let stickyMiniSession = null;
        const complete = function (detail) {
            if (!onComplete) { return; }
            try {
                onComplete((detail && typeof detail === 'object') ? detail : {});
            } catch (_) { /* no-op */ }
        };
        if (categoryProgressPostMetricsTimer) {
            window.clearTimeout(categoryProgressPostMetricsTimer);
            categoryProgressPostMetricsTimer = 0;
        }
        categoryProgressHoldForMetrics = deferVisibleCategoryProgress;
        if (!isLoggedIn || !ajaxUrl || !nonce) {
            setSummaryMetricsLoadingState(false);
            categoryProgressHoldForMetrics = false;
            complete({ hasCountChanges: false, skipped: true });
            return;
        }

        const metricsLoadingToken = ++summaryMetricsLoadingToken;
        setSummaryMetricsLoadingState(true);
        const finishMetricsLoading = function () {
            if (metricsLoadingToken !== summaryMetricsLoadingToken) { return; }
            setSummaryMetricsLoadingState(false);
        };

        const stableCountsBefore = normalizeSummaryCounts(summaryCounts || {});
        const renderedCountsBefore = getRenderedMiniCountValues();

        $.post(ajaxUrl, {
            action: 'll_user_study_analytics',
            nonce: nonce,
            wordset_id: wordsetId,
            category_ids: getVisibleCategoryIds(),
            days: 14
        }).done(function (res) {
            const analytics = (res && res.success && res.data && res.data.analytics && typeof res.data.analytics === 'object')
                ? res.data.analytics
                : null;
            if (!analytics || !analytics.summary || typeof analytics.summary !== 'object') {
                finishMetricsLoading();
                categoryProgressHoldForMetrics = false;
                complete({ hasCountChanges: false, skipped: true });
                return;
            }
            const hasCategoryProgressChanges = queueCategoryProgressUpdatesFromAnalytics(analytics, {
                deferVisible: deferVisibleCategoryProgress,
                syncAllImmediately: syncAllCategoryProgressImmediately,
                animateCards: animate
            });
            const summary = analytics.summary;
            const mastered = Math.max(0, parseInt(summary.mastered_words, 10) || 0);
            const studiedTotal = Math.max(0, parseInt(summary.studied_words, 10) || 0);
            const newWords = Math.max(0, parseInt(summary.new_words, 10) || 0);
            const starredWords = Math.max(0, parseInt(summary.starred_words, 10) || 0);
            const hardWords = Math.max(0, parseInt(summary.hard_words, 10) || 0);
            const nextCounts = {
                mastered: mastered,
                studied: Math.max(0, studiedTotal - mastered),
                new: newWords,
                starred: starredWords,
                hard: hardWords
            };
            const stableChanged = summaryCountsChanged(stableCountsBefore, nextCounts);
            const renderedChanged = summaryCountsChanged(renderedCountsBefore, nextCounts);
            const hasCountChanges = stableChanged || renderedChanged;
            const shouldSurfaceMetricsUpdate = hasCountChanges || !!hasCategoryProgressChanges;
            summaryCounts = nextCounts;
            finishMetricsLoading();
            if (stickyMiniWhenOffscreen && shouldSurfaceMetricsUpdate) {
                stickyMiniSession = showStickyProgressMiniForMetricsAnimationIfOffscreen();
            }
            renderMiniCounts({
                previousCounts: renderedCountsBefore,
                animate: animate && (renderedChanged || (stickyMiniWhenOffscreen && !!hasCategoryProgressChanges)),
                celebratePerfect: celebratePerfect && hasCountChanges,
                onComplete: function () {
                    if (deferVisibleCategoryProgress) {
                        scheduleTopRowCategoryProgressAfterMetrics();
                    }
                    if (stickyMiniSession) {
                        scheduleStickyProgressMiniReleaseForMetricsAnimation(stickyMiniSession);
                        stickyMiniSession = null;
                    }
                }
            });
            if (celebratePerfect) {
                clearPerfectCelebrationPending();
            }
            complete({
                hasCountChanges: hasCountChanges,
                hasCategoryProgressChanges: !!hasCategoryProgressChanges
            });
        }).fail(function () {
            finishMetricsLoading();
            categoryProgressHoldForMetrics = false;
            if (stickyMiniSession) {
                releaseStickyProgressMiniForMetricsAnimation(stickyMiniSession, { immediate: true });
                stickyMiniSession = null;
            }
            complete({ hasCountChanges: false, failed: true });
        });
    }

    function getRecommendedPreviewItems(next, limit) {
        const max = Math.max(1, parseInt(limit, 10) || 2);
        if (!next || typeof next !== 'object') { return []; }

        const ids = uniqueIntList(next.category_ids || []);
        const out = [];
        const seen = {};

        function pushImage(url, altText) {
            if (out.length >= max) { return; }
            const imageUrl = String(url || '').trim();
            if (!imageUrl) { return; }
            const key = 'img:' + imageUrl;
            if (seen[key]) { return; }
            seen[key] = true;
            out.push({
                type: 'image',
                url: imageUrl,
                alt: String(altText || '')
            });
        }

        function pushText(labelText) {
            if (out.length >= max) { return; }
            const label = String(labelText || '').trim();
            if (!label) { return; }
            const key = 'txt:' + label.toLowerCase();
            if (seen[key]) { return; }
            seen[key] = true;
            out.push({
                type: 'text',
                label: label
            });
        }

        ids.forEach(function (id) {
            if (out.length >= max) { return; }
            const cat = getCategoryById(id);
            if (!cat || !Array.isArray(cat.preview) || !cat.preview.length) { return; }
            cat.preview.forEach(function (item) {
                if (out.length >= max) { return; }
                const source = (item && typeof item === 'object') ? item : {};
                const type = String(source.type || '').toLowerCase() === 'image' ? 'image' : 'text';
                if (type === 'image') {
                    pushImage(source.url, source.alt);
                    return;
                }
                pushText(source.label);
            });
        });

        if (out.length < max) {
            ids.forEach(function (id) {
                if (out.length >= max) { return; }
                const cat = getCategoryById(id);
                if (!cat || typeof cat !== 'object') { return; }
                const translation = String(cat.translation || '').trim();
                const name = String(cat.name || '').trim();
                if (translation) {
                    pushText(translation);
                }
                if (out.length >= max) { return; }
                if (name && name.toLowerCase() !== translation.toLowerCase()) {
                    pushText(name);
                }
            });
        }

        return out;
    }

    function renderNextPreview(next) {
        if (!$nextPreview.length) { return; }

        const preview = getRecommendedPreviewItems(next, 2);
        const slots = [];

        preview.forEach(function (item) {
            const source = (item && typeof item === 'object') ? item : {};
            if (String(source.type || '') === 'image' && String(source.url || '') !== '') {
                slots.push(
                    '<span class="ll-wordset-next-thumb ll-wordset-next-thumb--image">'
                    + '<img src="' + escapeHtml(String(source.url || '')) + '" alt="" loading="lazy" decoding="async" fetchpriority="low" />'
                    + '</span>'
                );
                return;
            }

            const label = String(source.label || '').trim();
            if (label) {
                slots.push(
                    '<span class="ll-wordset-next-thumb ll-wordset-next-thumb--text">'
                    + '<span class="ll-wordset-next-thumb__text" dir="auto">' + escapeHtml(label) + '</span>'
                    + '</span>'
                );
            }
        });

        $nextPreview.html(slots.join(''));
    }

    function setNextCardText(primaryText, secondaryText) {
        if (!$nextText.length) { return; }
        const primary = String(primaryText || '').trim();
        const secondary = String(secondaryText || '').trim();
        if (!primary && !secondary) {
            $nextText.empty();
            return;
        }
        if (secondary) {
            $nextText.html(
                '<span class="ll-wordset-next-card__line" dir="auto">' + escapeHtml(primary) + '</span>'
                + '<span class="ll-wordset-next-card__line ll-wordset-next-card__line--muted" dir="auto">' + escapeHtml(secondary) + '</span>'
            );
            return;
        }
        $nextText.html('<span class="ll-wordset-next-card__line" dir="auto">' + escapeHtml(primary) + '</span>');
    }

    function setNextCardLoadingSkeletonText() {
        if (!$nextText.length) { return; }
        $nextText.html(
            '<span class="ll-wordset-next-card__line ll-wordset-next-card__line--skeleton ll-wordset-next-card__line--skeleton-primary" aria-hidden="true"></span>'
            + '<span class="ll-wordset-next-card__line ll-wordset-next-card__line--skeleton ll-wordset-next-card__line--skeleton-secondary" aria-hidden="true"></span>'
        );
    }

    function clearNextCardLoadingSkeletonWidths() {
        if (!$nextText.length) { return; }
        $nextText.css('--ll-wordset-next-skeleton-primary-width', '');
        $nextText.css('--ll-wordset-next-skeleton-secondary-width', '');
    }

    function setNextCardLoadingSkeletonWidths() {
        if (!$nextCard.length || !$nextText.length) { return; }
        const cardEl = $nextCard[0];
        const textEl = $nextText[0];
        if (!cardEl || !textEl || !cardEl.getBoundingClientRect || !textEl.getBoundingClientRect) {
            clearNextCardLoadingSkeletonWidths();
            return;
        }

        const cardRect = cardEl.getBoundingClientRect();
        const textRect = textEl.getBoundingClientRect();
        if (!cardRect.width || !textRect.width) {
            clearNextCardLoadingSkeletonWidths();
            return;
        }

        const styles = window.getComputedStyle ? window.getComputedStyle(cardEl) : null;
        const paddingRight = styles ? (parseFloat(styles.paddingRight) || 0) : 0;
        const textStart = textRect.left - cardRect.left;
        const availableRaw = cardRect.width - paddingRight - textStart - 10;
        const available = Math.max(48, Math.floor(availableRaw));
        const primary = Math.max(42, Math.floor(available * 0.84));
        const secondary = Math.max(34, Math.floor(Math.min(primary - 10, available * 0.62)));

        $nextText.css('--ll-wordset-next-skeleton-primary-width', String(primary) + 'px');
        $nextText.css('--ll-wordset-next-skeleton-secondary-width', String(secondary) + 'px');
    }

    function buildLoadingNextPreviewMarkup() {
        return '<span class="ll-wordset-next-thumb ll-wordset-next-thumb--empty ll-wordset-next-thumb--loading" aria-hidden="true"></span>'
            + '<span class="ll-wordset-next-thumb ll-wordset-next-thumb--empty ll-wordset-next-thumb--loading" aria-hidden="true"></span>';
    }

    function clearNextCardTransitionTimers() {
        if (nextCardRevealTimer) {
            clearTimeout(nextCardRevealTimer);
            nextCardRevealTimer = null;
        }
        if (nextCardWidthReleaseTimer) {
            clearTimeout(nextCardWidthReleaseTimer);
            nextCardWidthReleaseTimer = null;
        }
    }

    function lockNextCardDimensions() {
        if (!$nextCard.length) { return; }
        const shellWidth = Math.ceil(($nextShell.length ? $nextShell.outerWidth() : 0) || $nextCard.outerWidth() || 0);
        const cardHeight = Math.ceil($nextCard.outerHeight() || 0);

        if ($nextShell.length && shellWidth > 0) {
            $nextShell
                .attr('data-ll-wordset-next-lock', '1')
                .attr('data-ll-wordset-next-lock-width', String(shellWidth))
                .addClass('is-dimension-locked')
                .css({
                    width: String(shellWidth) + 'px',
                    minWidth: String(shellWidth) + 'px',
                    maxWidth: String(shellWidth) + 'px'
                });
        }
        if (cardHeight > 0) {
            $nextCard.css('min-height', String(cardHeight) + 'px');
        }
        $nextCard.css({
            width: '100%',
            maxWidth: '100%'
        });
    }

    function measureNaturalNextCardWidth() {
        if (!$nextCard.length) { return 0; }
        const $probe = $nextCard.clone(false);
        $probe
            .removeClass('is-loading')
            .css({
                position: 'absolute',
                visibility: 'hidden',
                left: '-9999px',
                top: '-9999px',
                width: 'auto',
                maxWidth: 'none',
                minWidth: '0',
                minHeight: '0',
                display: 'inline-flex'
            });
        $('body').append($probe);
        const naturalWidth = Math.ceil($probe.outerWidth() || 0);
        $probe.remove();
        return naturalWidth;
    }

    function triggerNextCardReadyPulse() {
        if (!$nextShell.length) { return; }
        $nextShell.removeClass('is-ready-pulse');
        try { void $nextShell[0].offsetWidth; } catch (_) { /* no-op */ }
        $nextShell.addClass('is-ready-pulse');
        clearNextCardTransitionTimers();
        nextCardRevealTimer = setTimeout(function () {
            $nextShell.removeClass('is-ready-pulse');
            nextCardRevealTimer = null;
        }, 380);
    }

    function releaseNextCardDimensionLockWithMotion() {
        if (!$nextShell.length || !$nextCard.length) { return; }
        if (String($nextShell.attr('data-ll-wordset-next-lock') || '') !== '1') { return; }

        const lockedWidth = parseInt($nextShell.attr('data-ll-wordset-next-lock-width') || '0', 10) || 0;
        const parentWidth = Math.ceil(($nextShell.parent().innerWidth && $nextShell.parent().innerWidth()) || 0);
        const isNearParentWidth = parentWidth > 0 && lockedWidth >= (parentWidth - 2);
        const naturalWidthRaw = measureNaturalNextCardWidth();
        const naturalWidth = (parentWidth > 0)
            ? Math.min(naturalWidthRaw || lockedWidth, parentWidth)
            : (naturalWidthRaw || lockedWidth);

        const finalizeUnlock = function () {
            clearNextCardTransitionTimers();
            $nextShell
                .removeClass('is-width-transition is-dimension-locked')
                .removeAttr('data-ll-wordset-next-lock')
                .removeAttr('data-ll-wordset-next-lock-width')
                .css({
                    width: '',
                    minWidth: '',
                    maxWidth: ''
                });
            $nextCard.css({
                width: '',
                maxWidth: '',
                minWidth: '',
                minHeight: ''
            });
            triggerNextCardReadyPulse();
        };

        if (!lockedWidth || !naturalWidth || isNearParentWidth || Math.abs(naturalWidth - lockedWidth) < 2) {
            finalizeUnlock();
            return;
        }

        clearNextCardTransitionTimers();
        $nextShell
            .addClass('is-width-transition')
            .css({
                width: String(lockedWidth) + 'px',
                minWidth: '',
                maxWidth: ''
            });
        try { void $nextShell[0].offsetWidth; } catch (_) { /* no-op */ }
        $nextShell.css('width', String(naturalWidth) + 'px');
        nextCardWidthReleaseTimer = setTimeout(finalizeUnlock, 280);
    }

    function clearNextCardLoadingState(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const preserveLock = !!opts.preserveLock;
        clearNextCardLoadingSkeletonWidths();
        if ($nextShell.length) {
            $nextShell.removeClass('is-loading').removeAttr('aria-busy');
            if (!preserveLock) {
                clearNextCardTransitionTimers();
                $nextShell
                    .removeClass('is-dimension-locked is-width-transition is-ready-pulse')
                    .removeAttr('data-ll-wordset-next-lock')
                    .removeAttr('data-ll-wordset-next-lock-width')
                    .css({
                        width: '',
                        minWidth: '',
                        maxWidth: ''
                    });
            }
        }
        if ($nextCard.length) {
            $nextCard.removeClass('is-loading');
            if (preserveLock) {
                $nextCard.css({
                    width: '100%',
                    maxWidth: '100%',
                    minWidth: ''
                });
            } else {
                $nextCard.css({
                    minWidth: '',
                    minHeight: '',
                    width: '',
                    maxWidth: ''
                });
            }
        }
        if ($nextRemove.length) {
            $nextRemove.removeClass('is-loading');
        }
    }

    function setNextCardLoadingState(options) {
        if (!$nextCard.length) { return; }
        const opts = (options && typeof options === 'object') ? options : {};
        const lockDimensions = !!opts.lockDimensions;
        const loadingText = String(i18n.nextLoading || 'Loading next recommendation...');
        clearNextCardTransitionTimers();
        if (lockDimensions) {
            lockNextCardDimensions();
        } else if ($nextShell.length) {
            $nextShell
                .removeAttr('data-ll-wordset-next-lock')
                .removeAttr('data-ll-wordset-next-lock-width')
                .removeClass('is-dimension-locked is-width-transition is-ready-pulse')
                .css({ width: '', minWidth: '', maxWidth: '' });
            $nextCard.css({ width: '', maxWidth: '', minHeight: '' });
        }
        if ($nextShell.length) {
            $nextShell.addClass('is-loading').attr('aria-busy', 'true');
        }
        $nextCard
            .addClass('is-loading is-disabled')
            .attr('aria-disabled', 'true')
            .prop('disabled', true)
            .attr('aria-label', loadingText);
        setNextCardLoadingSkeletonText();
        if ($nextIcon.length) {
            $nextIcon.html(getLoadingModeIconMarkup('ll-vocab-lesson-mode-icon'));
        }
        if ($nextPreview.length) {
            $nextPreview.html(buildLoadingNextPreviewMarkup());
        }
        if ($nextCount.length) {
            $nextCount.prop('hidden', true).text('');
        }
        if ($nextRemove.length) {
            $nextRemove
                .prop('hidden', false)
                .prop('disabled', true)
                .removeClass('is-loading');
        }
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () {
                setNextCardLoadingSkeletonWidths();
            });
        } else {
            setNextCardLoadingSkeletonWidths();
        }
    }

    function renderNextCard() {
        if (!$nextCard.length || !$nextText.length) { return; }
        const releaseLockedDimensions = $nextShell.length && String($nextShell.attr('data-ll-wordset-next-lock') || '') === '1';
        clearNextCardLoadingState({ preserveLock: releaseLockedDimensions });
        const selectionActive = hasActiveCategorySelection();
        if (selectionActive) {
            $nextCard
                .addClass('is-disabled')
                .attr('aria-disabled', 'true')
                .prop('disabled', true);
            if ($nextCount.length) {
                $nextCount.prop('hidden', true).text('');
            }
            if ($nextRemove.length) {
                $nextRemove.prop('hidden', true).prop('disabled', true).attr('data-queue-id', '');
            }
            if (releaseLockedDimensions) {
                releaseNextCardDimensionLockWithMotion();
            }
            return;
        }

        const next = normalizeNextActivity(nextActivity) || recommendationQueueHead();
        if (!next || !next.mode) {
            $nextCard.addClass('is-disabled').attr('aria-disabled', 'true').prop('disabled', true);
            setNextCardText(i18n.nextNone || 'No recommendation yet. Do one round first.', '');
            if ($nextIcon.length) {
                $nextIcon.empty();
            }
            if ($nextCount.length) {
                $nextCount.prop('hidden', true).text('');
            }
            if ($nextRemove.length) {
                $nextRemove.prop('hidden', true).prop('disabled', true).attr('data-queue-id', '');
            }
            renderNextPreview(null);
            if (releaseLockedDimensions) {
                releaseNextCardDimensionLockWithMotion();
            }
            return;
        }

        const categoryText = activityCategoryText(next);
        const modeText = modeLabel(next.mode);
        const wordCount = uniqueIntList(next.session_word_ids || []).length;
        const queueId = resolveQueueIdForActivity(next);
        const template = wordCount > 0
            ? (i18n.nextReady || 'Recommended: %1$s in %2$s (%3$d words).')
            : (i18n.nextReadyNoCount || 'Recommended: %1$s in %2$s.');
        const message = formatTemplate(template, [modeText, categoryText, wordCount]);

        $nextCard
            .removeClass('is-disabled')
            .attr('aria-disabled', 'false')
            .prop('disabled', false);
        setNextCardText(modeText, categoryText);
        $nextCard.attr('aria-label', message);
        if ($nextIcon.length) {
            $nextIcon.html(getModeIconMarkup(next.mode, 'll-vocab-lesson-mode-icon'));
        }
        if ($nextCount.length) {
            if (wordCount > 0) {
                $nextCount
                    .prop('hidden', false)
                    .text(String(wordCount))
                    .attr('title', formatTemplate(i18n.queueWordCount || '%d words', [wordCount]));
            } else {
                $nextCount.prop('hidden', true).text('');
            }
        }
        if ($nextRemove.length) {
            $nextRemove
                .prop('hidden', false)
                .prop('disabled', !queueId)
                .attr('data-queue-id', queueId);
        }
        renderNextPreview(next);
        if (releaseLockedDimensions) {
            releaseNextCardDimensionLockWithMotion();
        }
    }

    function renderSettingsQueue() {
        if (!$settingsQueueList.length) { return; }
        $settingsQueueList.empty();
        const queue = normalizeRecommendationQueue(recommendationQueue || []);
        recommendationQueue = queue.slice();
        if (!queue.length) {
            if ($settingsQueueEmpty.length) {
                $settingsQueueEmpty.prop('hidden', false).text(i18n.queueEmpty || 'No upcoming activities yet.');
            }
            $settingsQueueList.css('--ll-wordset-queue-item-width', '');
            return;
        }

        queue.forEach(function (activity) {
            const item = normalizeNextActivity(activity);
            if (!item || !item.mode) { return; }
            const queueId = String(activity.queue_id || item.queue_id || '');
            const categoryText = activityCategoryText(item);
            const wordCount = uniqueIntList(item.session_word_ids || []).length;

            const $row = $('<li>', {
                class: 'll-wordset-queue-item',
                'data-ll-wordset-queue-item': '',
                'data-queue-id': queueId
            });
            $('<span>', {
                class: 'll-wordset-queue-item__mode ll-wordset-card__quiz-btn',
                html: getModeIconMarkup(item.mode, 'll-wordset-card__quiz-icon')
            }).appendTo($row);

            const previewItems = getQueuePreviewItems(item.category_ids || [], 2);
            const $preview = $('<span>', {
                class: 'll-wordset-queue-item__preview',
                'aria-hidden': 'true'
            });
            for (let idx = 0; idx < 2; idx += 1) {
                const preview = previewItems[idx] || null;
                if (preview && preview.type === 'image' && preview.url) {
                    const $thumbImage = $('<span>', { class: 'll-wordset-queue-thumb ll-wordset-queue-thumb--image' });
                    $('<img>', {
                        src: String(preview.url),
                        alt: '',
                        loading: 'lazy',
                        decoding: 'async',
                        fetchpriority: 'low'
                    }).appendTo($thumbImage);
                    $preview.append($thumbImage);
                    continue;
                }
                if (preview && preview.type === 'text') {
                    const $thumbText = $('<span>', { class: 'll-wordset-queue-thumb ll-wordset-queue-thumb--text' });
                    $('<span>', {
                        class: 'll-wordset-queue-thumb__text',
                        text: String(preview.label || '')
                    }).appendTo($thumbText);
                    $preview.append($thumbText);
                    continue;
                }
                $preview.append($('<span>', { class: 'll-wordset-queue-thumb ll-wordset-queue-thumb--empty' }));
            }
            $row.append($preview);

            const $text = $('<span>', { class: 'll-wordset-queue-item__text' });
            $('<span>', {
                class: 'll-wordset-queue-item__line',
                text: modeLabel(item.mode)
            }).appendTo($text);
            $('<span>', {
                class: 'll-wordset-queue-item__line ll-wordset-queue-item__line--muted',
                text: categoryText
            }).appendTo($text);
            $row.append($text);

            if (wordCount > 0) {
                $('<span>', {
                    class: 'll-wordset-queue-item__count',
                    text: String(wordCount),
                    title: formatTemplate(i18n.queueWordCount || '%d words', [wordCount])
                }).appendTo($row);
            }

            $('<button>', {
                type: 'button',
                class: 'll-wordset-queue-item__remove',
                'data-ll-wordset-queue-remove': '',
                'data-queue-id': queueId,
                'aria-label': i18n.queueRemove || 'Remove activity'
            }).append($('<span>', { 'aria-hidden': 'true', text: '×' })).appendTo($row);
            $settingsQueueList.append($row);
        });

        if ($settingsQueueEmpty.length) {
            $settingsQueueEmpty.prop('hidden', true);
        }
        scheduleUniformQueueItemWidth();
    }

    function syncSettingsButtons() {
        const selectedFocus = normalizePriorityFocus(goals.priority_focus || '');
        $root.find('[data-ll-wordset-priority-focus]').each(function () {
            const value = String($(this).attr('data-ll-wordset-priority-focus') || '');
            const active = normalizePriorityFocus(value) === selectedFocus && selectedFocus !== '';
            $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
        });

        $root.find('[data-ll-wordset-transition]').each(function () {
            const speed = String($(this).attr('data-ll-wordset-transition') || 'slow');
            const active = (speed === 'fast') ? !!state.fast_transitions : !state.fast_transitions;
            $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
        });

        const enabledModes = uniqueModeList(goals.enabled_modes || []);
        const genderAvailableForWordset = wordsetHasGenderSupport();
        $root.find('[data-ll-wordset-goal-mode]').each(function () {
            const $btn = $(this);
            const mode = normalizeMode($btn.attr('data-ll-wordset-goal-mode') || '');
            const available = !!mode && (mode !== 'gender' || genderAvailableForWordset);
            const active = available && enabledModes.indexOf(mode) !== -1;
            $btn
                .toggleClass('active', active)
                .toggleClass('is-unavailable', !available)
                .attr('aria-pressed', active ? 'true' : 'false')
                .prop('disabled', !available)
                .attr('aria-disabled', available ? 'false' : 'true');
        });

        if ($selectionStarredToggle.length) {
            $selectionStarredToggle.prop('checked', !!selectionStarredOnly);
        }
        if ($selectionStarredWrap.length) {
            $selectionStarredWrap.toggleClass('is-active', !!selectionStarredOnly);
        }
        if ($selectionStarredIcon.length) {
            $selectionStarredIcon.text(selectionStarredOnly ? '★' : '☆');
        }
        if ($selectionStarredLabel.length) {
            $selectionStarredLabel.text(i18n.selectionStarredOnly || 'Starred only');
        }
        if ($selectionHardToggle.length) {
            $selectionHardToggle.prop('checked', !!selectionHardOnly);
        }
        if ($selectionHardWrap.length) {
            $selectionHardWrap.toggleClass('is-active', !!selectionHardOnly);
        }
        if ($selectionHardIcon.length) {
            $selectionHardIcon.html(hardWordsIconSvgMarkup());
        }
        if ($selectionHardLabel.length) {
            $selectionHardLabel.text(i18n.selectionHardOnly || 'Hard words only');
        }
    }

    function focusChoiceFromGoals() {
        return normalizePriorityFocus(goals.priority_focus || '');
    }

    function applyFocusChoice(choice) {
        const selected = normalizePriorityFocus(choice);
        goals.priority_focus = selected;
        goals.prioritize_new_words = selected === 'new';
        goals.prioritize_studied_words = selected === 'studied';
        goals.prioritize_learned_words = selected === 'learned';
        goals.prefer_starred_words = selected === 'starred';
        goals.prefer_hard_words = selected === 'hard';
    }

    function renderSelectionBar() {
        if (!$selectionBar.length) { return; }

        const selectedIds = uniqueIntList(selectedCategoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        selectedCategoryIds = selectedIds.slice();

        const categoryCount = selectedIds.length;
        const active = categoryCount > 0;
        $root.toggleClass('ll-wordset-selection-active', active);
        syncSelectAllButton();
        scheduleSelectAllAlignment();

        if (!active) {
            $selectionBar.prop('hidden', true);
            if ($selectionText.length) {
                $selectionText.text(i18n.selectionLabel || 'Select categories to study together');
            }
            selectionStarredOnly = false;
            selectionHardOnly = false;
            if ($selectionStarredToggle.length) {
                $selectionStarredToggle.prop('checked', false);
            }
            if ($selectionHardToggle.length) {
                $selectionHardToggle.prop('checked', false);
            }
            if ($selectionStarredWrap.length) {
                $selectionStarredWrap.prop('hidden', true);
            }
            if ($selectionHardWrap.length) {
                $selectionHardWrap.prop('hidden', true);
            }
            syncSelectionModeButtons();
            syncSettingsButtons();
            syncPrimaryActionState();
            renderNextCard();
            return;
        }

        const metrics = buildSelectionWordMetrics(selectedIds);
        const filteredMinimumWords = getSelectionFilteredMinimumWordCount();
        // Starred/hard filters are user-specific and should stay unavailable for guests.
        const showStarredOnly = isLoggedIn && metrics.starred >= filteredMinimumWords;
        const minimumHardWordsForSelectionFilter = Math.max(5, getSelectionMinimumWordCount());
        const showHardOnly = isLoggedIn && metrics.hard >= minimumHardWordsForSelectionFilter;

        if (!showStarredOnly) {
            selectionStarredOnly = false;
            if ($selectionStarredToggle.length) {
                $selectionStarredToggle.prop('checked', false);
            }
        }
        if (!showHardOnly) {
            selectionHardOnly = false;
            if ($selectionHardToggle.length) {
                $selectionHardToggle.prop('checked', false);
            }
        }
        if (selectionStarredOnly && selectionHardOnly) {
            selectionHardOnly = false;
            if ($selectionHardToggle.length) {
                $selectionHardToggle.prop('checked', false);
            }
        }

        if ($selectionStarredWrap.length) {
            $selectionStarredWrap.prop('hidden', !showStarredOnly);
        }
        if ($selectionHardWrap.length) {
            $selectionHardWrap.prop('hidden', !showHardOnly);
        }

        const effectiveWordCount = getSelectionEffectiveWordCount(metrics);
        $selectionBar.prop('hidden', false);
        if ($selectionText.length) {
            const template = i18n.selectionWordsOnly || '%d words';
            $selectionText.text(formatTemplate(template, [effectiveWordCount]));
        }
        syncSelectionModeButtons({
            categoryIds: selectedIds,
            effectiveWordCount: effectiveWordCount
        });
        syncSettingsButtons();
        syncPrimaryActionState();
    }

    function syncSelectionModeButtons(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const ids = uniqueIntList(opts.categoryIds || selectedCategoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        const hasSelection = ids.length > 0;
        const allowGender = selectionHasGenderSupport(ids);
        const effectiveWordCount = Math.max(0, parseInt(opts.effectiveWordCount, 10) || 0);
        const hasEnoughWords = effectiveWordCount >= getSelectionMinimumWordCount();
        const hasEnoughLearningWords = effectiveWordCount >= Math.max(3, getSelectionMinimumWordCount());

        $root.find('[data-ll-wordset-selection-mode]').each(function () {
            const $btn = $(this);
            const mode = normalizeMode($btn.attr('data-mode') || '');
            const disabled = !hasSelection ||
                !hasEnoughWords ||
                (mode === 'learning' && !hasEnoughLearningWords) ||
                (mode === 'gender' && !allowGender);
            $btn
                .prop('disabled', disabled)
                .attr('aria-disabled', disabled ? 'true' : 'false')
                .toggleClass('is-disabled', disabled);
            if (mode === 'gender') {
                $btn.toggleClass('is-unavailable', hasSelection && !allowGender);
            }
        });
    }

    function clearCategorySelection(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        selectedCategoryIds = [];
        selectionStarredOnly = false;
        selectionHardOnly = false;
        $root.find('[data-ll-wordset-select]').prop('checked', false);
        if ($selectionStarredToggle.length) {
            $selectionStarredToggle.prop('checked', false);
        }
        if ($selectionHardToggle.length) {
            $selectionHardToggle.prop('checked', false);
        }
        renderSelectionBar();
        if (opts.refreshRecommendation) {
            refreshRecommendation({ forceRefresh: true });
        }
    }

    function getCategoryIdsFromCheckedUI() {
        const ids = [];
        $root.find('[data-ll-wordset-select]:checked').each(function () {
            const id = parseInt($(this).val(), 10) || 0;
            if (id > 0) {
                ids.push(id);
            }
        });
        return uniqueIntList(ids);
    }

    function removeHiddenCategoryFromSelection(catId) {
        const id = parseInt(catId, 10) || 0;
        if (!id) { return; }
        selectedCategoryIds = uniqueIntList((selectedCategoryIds || []).filter(function (value) {
            return value !== id;
        }));
        $root.find('[data-ll-wordset-select][value="' + id + '"]').prop('checked', false);
    }

    function getRecommendationScopeIds() {
        return getVisibleCategoryIds();
    }

    function refreshRecommendation(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const preferredMode = normalizeMode(opts.preferredMode || '');
        const forceRefresh = !!opts.forceRefresh;
        if (!isLoggedIn || !ajaxUrl || !nonce) {
            renderNextCard();
            return $.Deferred().resolve(null).promise();
        }
        const payload = {
            action: 'll_user_study_recommendation',
            nonce: nonce,
            wordset_id: wordsetId,
            category_ids: getRecommendationScopeIds(),
            refresh: forceRefresh ? 1 : 0
        };
        if (preferredMode) {
            payload.preferred_mode = preferredMode;
        }
        return $.post(ajaxUrl, payload).done(function (res) {
            if (res && res.success && res.data) {
                applyRecommendationPayload(res.data, { preferredMode: preferredMode });
            }
        });
    }

    function ensureWordsForCategories(categoryIds) {
        const ids = uniqueIntList(categoryIds || []);
        if (!ids.length) {
            return $.Deferred().resolve(wordsByCategory).promise();
        }

        const missing = ids.filter(function (id) {
            return !Array.isArray(wordsByCategory[id]);
        });
        if (!missing.length) {
            return $.Deferred().resolve(wordsByCategory).promise();
        }

        if (!ajaxUrl) {
            return $.Deferred().resolve(wordsByCategory).promise();
        }

        if (!isLoggedIn || !nonce) {
            const publicRequests = missing.map(function (categoryId) {
                const cat = getCategoryById(categoryId);
                if (!cat || !cat.name) {
                    if (!Array.isArray(wordsByCategory[categoryId])) {
                        wordsByCategory[categoryId] = [];
                    }
                    return $.Deferred().resolve().promise();
                }

                const optionType = String(cat.option_type || cat.mode || 'image');
                const promptType = String(cat.prompt_type || 'audio');
                const wordsetSpec = wordsetSlug || String(wordsetId || '');

                return $.post(ajaxUrl, {
                    action: 'll_get_words_by_category',
                    category: String(cat.name || ''),
                    display_mode: optionType,
                    wordset: wordsetSpec,
                    wordset_fallback: 0,
                    prompt_type: promptType,
                    option_type: optionType
                }).then(function (res) {
                    if (res && res.success && Array.isArray(res.data)) {
                        wordsByCategory[categoryId] = res.data.slice();
                    } else if (!Array.isArray(wordsByCategory[categoryId])) {
                        wordsByCategory[categoryId] = [];
                    }
                }, function () {
                    if (!Array.isArray(wordsByCategory[categoryId])) {
                        wordsByCategory[categoryId] = [];
                    }
                });
            });

            if (!publicRequests.length) {
                return $.Deferred().resolve(wordsByCategory).promise();
            }

            return $.when.apply($, publicRequests).then(function () {
                return wordsByCategory;
            }, function () {
                return wordsByCategory;
            });
        }

        return $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: wordsetId,
            category_ids: ids
        }).then(function (res) {
            if (res && res.success && res.data && res.data.words_by_category && typeof res.data.words_by_category === 'object') {
                wordsByCategory = Object.assign({}, wordsByCategory, res.data.words_by_category);
            }
            return wordsByCategory;
        }, function () {
            return wordsByCategory;
        });
    }

    function collectWordIdsForCategories(categoryIds, options) {
        const lists = buildWordIdListsByCategory(categoryIds, options);
        return lists.filteredWordIds;
    }

    function buildWordIdListsByCategory(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const ids = uniqueIntList(categoryIds || []);
        const starOnly = !!opts.starOnly;
        const hardOnly = !!opts.hardOnly;
        const starredLookup = {};
        uniqueIntList(state.starred_word_ids || []).forEach(function (id) {
            starredLookup[id] = true;
        });
        const hardLookup = hardOnly ? getHardWordLookup() : {};

        const out = {
            categoryIds: ids,
            allByCategory: {},
            filteredByCategory: {},
            totalCountByCategory: {},
            filteredCountByCategory: {},
            allWordIds: [],
            filteredWordIds: []
        };

        const seenAllGlobal = {};
        const seenFilteredGlobal = {};

        ids.forEach(function (categoryId) {
            const rows = Array.isArray(wordsByCategory[categoryId]) ? wordsByCategory[categoryId] : [];
            const allIds = [];
            const filteredIds = [];
            const seenLocal = {};

            rows.forEach(function (word) {
                const wordId = parseInt(word && word.id, 10) || 0;
                if (!wordId || seenLocal[wordId]) { return; }
                seenLocal[wordId] = true;
                allIds.push(wordId);

                if (starOnly && !starredLookup[wordId]) { return; }
                if (hardOnly && !hardLookup[wordId]) { return; }
                filteredIds.push(wordId);
            });

            out.allByCategory[categoryId] = allIds;
            out.filteredByCategory[categoryId] = filteredIds;
            out.totalCountByCategory[categoryId] = allIds.length;
            out.filteredCountByCategory[categoryId] = filteredIds.length;

            allIds.forEach(function (wordId) {
                if (!seenAllGlobal[wordId]) {
                    seenAllGlobal[wordId] = true;
                    out.allWordIds.push(wordId);
                }
            });
            filteredIds.forEach(function (wordId) {
                if (!seenFilteredGlobal[wordId]) {
                    seenFilteredGlobal[wordId] = true;
                    out.filteredWordIds.push(wordId);
                }
            });
        });

        return out;
    }

    function filterWordIdsByCategories(wordIds, categoryIds, allByCategory) {
        const ids = uniqueIntList(wordIds || []);
        const categoriesForWords = uniqueIntList(categoryIds || []);
        if (!ids.length || !categoriesForWords.length) {
            return [];
        }

        const allowedLookup = {};
        categoriesForWords.forEach(function (categoryId) {
            const words = (allByCategory && Array.isArray(allByCategory[categoryId])) ? allByCategory[categoryId] : [];
            words.forEach(function (wordId) {
                const wid = parseInt(wordId, 10) || 0;
                if (wid > 0) {
                    allowedLookup[wid] = true;
                }
            });
        });

        return ids.filter(function (wordId) {
            return !!allowedLookup[wordId];
        });
    }

    function collectUniqueWordIdsFromCategoryLists(categoryIds, wordsByCategoryMap) {
        const out = [];
        const seen = {};
        uniqueIntList(categoryIds || []).forEach(function (categoryId) {
            const ids = (wordsByCategoryMap && Array.isArray(wordsByCategoryMap[categoryId])) ? wordsByCategoryMap[categoryId] : [];
            ids.forEach(function (wordId) {
                const wid = parseInt(wordId, 10) || 0;
                if (!wid || seen[wid]) { return; }
                seen[wid] = true;
                out.push(wid);
            });
        });
        return out;
    }

    function appendUniqueWordIds(baseWordIds, candidateWordIds, minimumCount) {
        const target = uniqueIntList(baseWordIds || []);
        const limit = Math.max(0, parseInt(minimumCount, 10) || 0);
        if (!limit || target.length >= limit) {
            return target;
        }

        const seen = {};
        target.forEach(function (id) {
            seen[id] = true;
        });

        const candidates = Array.isArray(candidateWordIds) ? candidateWordIds : [];
        for (let idx = 0; idx < candidates.length; idx += 1) {
            const wordId = parseInt(candidates[idx], 10) || 0;
            if (!wordId || seen[wordId]) {
                continue;
            }
            seen[wordId] = true;
            target.push(wordId);
            if (target.length >= limit) {
                break;
            }
        }

        return target;
    }

    function pickBestCategoryIdByCounts(categoryIds, options) {
        const ids = uniqueIntList(categoryIds || []);
        if (!ids.length) {
            return 0;
        }

        const opts = (options && typeof options === 'object') ? options : {};
        const priorityCounts = (opts.priorityCounts && typeof opts.priorityCounts === 'object') ? opts.priorityCounts : {};
        const totalCounts = (opts.totalCounts && typeof opts.totalCounts === 'object') ? opts.totalCounts : {};
        const preferredCategoryId = parseInt(opts.preferCategoryId, 10) || ids[0] || 0;
        let bestId = ids[0];
        let bestPriority = -1;
        let bestTotal = -1;

        ids.forEach(function (id) {
            const priority = Math.max(0, parseInt(priorityCounts[id], 10) || 0);
            const total = Math.max(0, parseInt(totalCounts[id], 10) || 0);
            if (priority > bestPriority) {
                bestPriority = priority;
                bestTotal = total;
                bestId = id;
                return;
            }
            if (priority === bestPriority) {
                if (total > bestTotal) {
                    bestTotal = total;
                    bestId = id;
                    return;
                }
                if (total === bestTotal && id === preferredCategoryId) {
                    bestId = id;
                }
            }
        });

        return bestId;
    }

    function buildLearningSelectionLaunchPlan(categoryIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        const minimumWords = Math.max(3, parseInt(opts.minimumWords, 10) || LEARNING_MIN_CHUNK_SIZE);
        const criteriaKey = resolveSelectionCriteriaKey(opts);
        if (!ids.length) {
            return {
                categoryIds: [],
                sessionWordIds: [],
                criteriaKey: criteriaKey,
                categoryLabelOverride: ''
            };
        }

        const wordLists = buildWordIdListsByCategory(ids, opts);
        let compatibleCategoryIds = selectCompatibleCategoryIdsByWordCounts(ids, {
            preferCategoryId: parseInt(opts.preferCategoryId, 10) || ids[0] || 0,
            requireMatchingPresentation: true,
            priorityCounts: wordLists.filteredCountByCategory,
            totalCounts: wordLists.totalCountByCategory
        });
        if (!compatibleCategoryIds.length) {
            compatibleCategoryIds = ids.slice();
        }

        let sessionWordIds = filterWordIdsByCategories(
            wordLists.filteredWordIds,
            compatibleCategoryIds,
            wordLists.allByCategory
        );

        if (sessionWordIds.length && sessionWordIds.length < minimumWords) {
            const allCompatibleWords = collectUniqueWordIdsFromCategoryLists(compatibleCategoryIds, wordLists.allByCategory);
            sessionWordIds = appendUniqueWordIds(sessionWordIds, allCompatibleWords, minimumWords);
        }

        if (sessionWordIds.length && sessionWordIds.length < minimumWords) {
            const anchorCategoryId = pickBestCategoryIdByCounts(compatibleCategoryIds, {
                preferCategoryId: parseInt(opts.preferCategoryId, 10) || compatibleCategoryIds[0] || 0,
                priorityCounts: wordLists.filteredCountByCategory,
                totalCounts: wordLists.totalCountByCategory
            });
            if (anchorCategoryId > 0) {
                sessionWordIds = appendUniqueWordIds(
                    sessionWordIds,
                    wordLists.allByCategory[anchorCategoryId] || [],
                    minimumWords
                );
            }
        }

        const categoryWordLookup = {};
        sessionWordIds.forEach(function (wordId) {
            categoryWordLookup[wordId] = true;
        });
        const effectiveCategoryIds = compatibleCategoryIds.filter(function (categoryId) {
            const idsForCategory = (wordLists.allByCategory && Array.isArray(wordLists.allByCategory[categoryId]))
                ? wordLists.allByCategory[categoryId]
                : [];
            return idsForCategory.some(function (wordId) {
                return !!categoryWordLookup[wordId];
            });
        });

        const resolvedCategoryIds = effectiveCategoryIds.length ? effectiveCategoryIds : compatibleCategoryIds;
        const categoryLabelOverride = resolveLearningCategoryLabelOverride(
            'learning',
            resolvedCategoryIds,
            { priority_focus: criteriaKey },
            criteriaKey
        ) || resolveSelectionCriteriaLabel(criteriaKey);

        return {
            categoryIds: resolvedCategoryIds,
            sessionWordIds: sessionWordIds,
            criteriaKey: criteriaKey,
            categoryLabelOverride: categoryLabelOverride
        };
    }

    function buildChunkList(wordIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const chunkSize = Math.max(1, parseInt(opts.chunkSize, 10) || CHUNK_SIZE);
        const minimumChunkSize = Math.max(0, parseInt(opts.minChunkSize, 10) || 0);
        const ids = shuffleList(uniqueIntList(wordIds || []));
        if (!ids.length) { return []; }
        const chunks = [];
        for (let i = 0; i < ids.length; i += chunkSize) {
            chunks.push(ids.slice(i, i + chunkSize));
        }

        if (minimumChunkSize > 1 && chunks.length > 1) {
            const lastChunk = chunks[chunks.length - 1];
            if (lastChunk.length < minimumChunkSize) {
                for (let chunkIndex = chunks.length - 2; chunkIndex >= 0 && lastChunk.length < minimumChunkSize; chunkIndex -= 1) {
                    const sourceChunk = chunks[chunkIndex];
                    while (sourceChunk.length > minimumChunkSize && lastChunk.length < minimumChunkSize) {
                        const movedWordId = sourceChunk.pop();
                        if (!movedWordId) {
                            break;
                        }
                        lastChunk.unshift(movedWordId);
                    }
                }

                if (lastChunk.length < minimumChunkSize) {
                    const merged = [];
                    chunks.forEach(function (chunk) {
                        merged.push.apply(merged, chunk);
                    });
                    return [merged];
                }
            }
        }
        return chunks;
    }

    function setResultsButtonContent($button, iconMarkup, label, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (!$button || !$button.length) { return; }
        const labelMarkup = opts.loading
            ? getLoadingResultsButtonLabelMarkup()
            : ('<span class="ll-vocab-lesson-mode-label">' + escapeHtml(label) + '</span>');
        $button
            .removeClass('quiz-mode-button')
            .removeClass('ghost')
            .removeClass('is-loading')
            .addClass('ll-study-btn ll-vocab-lesson-mode-button ll-study-followup-mode-button')
            .html((iconMarkup || '') + labelMarkup);
        if (opts.loading) {
            $button
                .addClass('is-loading')
                .attr('aria-busy', 'true')
                .attr('aria-label', String(opts.loadingAriaLabel || i18n.nextLoading || 'Loading recommendation...'));
        } else {
            $button.removeAttr('aria-busy');
            if (label) {
                $button.removeAttr('aria-label');
            }
        }
    }

    function isModeEnabledForResultsActions(mode) {
        const key = normalizeMode(mode);
        if (!key) { return false; }
        if (key === 'gender' && !genderCfg.enabled) { return false; }
        const enabledModes = uniqueModeList(goals.enabled_modes || []);
        if (enabledModes.length && enabledModes.indexOf(key) === -1) {
            return false;
        }
        return true;
    }

    function categorySupportsMode(mode, categoryId) {
        const key = normalizeMode(mode);
        const cat = getCategoryById(categoryId);
        if (!cat) { return false; }
        if (key === 'gender') {
            return !!(genderCfg.enabled && cat.gender_supported);
        }
        if (key === 'learning') {
            return cat.learning_supported !== false;
        }
        return true;
    }

    function filterCategoryIdsForMode(mode, categoryIds) {
        const key = normalizeMode(mode);
        const ids = uniqueIntList(categoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        if (!key) { return []; }
        const byMode = ids.filter(function (id) {
            return categorySupportsMode(key, id);
        });
        return filterCategoryIdsByAspectBucket(byMode, {
            preferCategoryId: byMode[0] || ids[0] || 0,
            requireMatchingPresentation: key === 'learning'
        });
    }

    function areCategorySetsEqual(left, right) {
        const a = uniqueIntList(left || []).slice().sort(function (x, y) { return x - y; });
        const b = uniqueIntList(right || []).slice().sort(function (x, y) { return x - y; });
        if (a.length !== b.length) { return false; }
        for (let idx = 0; idx < a.length; idx += 1) {
            if (a[idx] !== b[idx]) {
                return false;
            }
        }
        return true;
    }

    function arePlansDuplicate(left, right) {
        if (!left || !right) { return false; }
        const leftMode = normalizeMode(left.mode);
        const rightMode = normalizeMode(right.mode);
        if (!leftMode || !rightMode || leftMode !== rightMode) {
            return false;
        }
        return areCategorySetsEqual(left.category_ids || [], right.category_ids || []);
    }

    function buildResultsFollowupPlanKey(planLike) {
        const plan = (planLike && typeof planLike === 'object') ? planLike : {};
        const mode = normalizeMode(plan.mode || '');
        if (!mode) {
            return '';
        }
        const categoryIds = uniqueIntList(plan.category_ids || []).slice().sort(function (a, b) { return a - b; });
        const sessionWordIds = uniqueIntList(plan.session_word_ids || []).slice().sort(function (a, b) { return a - b; });
        const starMode = normalizeStarMode(plan.session_star_mode || plan.sessionStarMode || 'normal');
        return [
            mode,
            'c:' + categoryIds.join(','),
            'w:' + (sessionWordIds.length ? sessionWordIds.join(',') : '*'),
            's:' + starMode
        ].join('|');
    }

    function estimateFlashcardResultsWordCount(mode, sessionWordIds, selectedCats, effectiveLookup) {
        const explicitCount = uniqueIntList(sessionWordIds || []).length;
        if (explicitCount > 0) {
            return explicitCount;
        }

        const categoryList = Array.isArray(selectedCats) ? selectedCats : [];
        const seenWordIds = {};
        let count = 0;

        categoryList.forEach(function (cat) {
            const categoryId = parseInt(cat && cat.id, 10) || 0;
            if (!categoryId) { return; }
            const rows = Array.isArray(wordsByCategory[categoryId]) ? wordsByCategory[categoryId] : [];
            rows.forEach(function (row) {
                const wordId = parseInt(row && row.id, 10) || 0;
                if (!wordId || seenWordIds[wordId]) { return; }
                if (effectiveLookup && !effectiveLookup[wordId]) { return; }
                seenWordIds[wordId] = true;
                count += 1;
            });
        });

        return count;
    }

    function resetResultsFollowupPrefetchState() {
        resultsFollowupPrefetchState = null;
    }

    function initializeResultsFollowupPrefetchStateForLaunch(launchPlan) {
        const plan = (launchPlan && typeof launchPlan === 'object') ? launchPlan : null;
        const launchKey = buildResultsFollowupPlanKey(plan);
        if (!plan || !launchKey) {
            resetResultsFollowupPrefetchState();
            return;
        }

        const estimatedTotal = Math.max(0, parseInt(
            plan.estimated_results_total ||
            plan.estimatedResultsTotal,
            10
        ) || 0);
        const thresholdCount = estimatedTotal > 0
            ? Math.max(1, Math.ceil(estimatedTotal * RESULTS_FOLLOWUP_PREFETCH_PROGRESS_RATIO))
            : RESULTS_FOLLOWUP_PREFETCH_UNKNOWN_TOTAL_TRIGGER;

        resultsFollowupPrefetchState = {
            launchKey: launchKey,
            mode: normalizeMode(plan.mode || '') || 'practice',
            chunked: !!plan.chunked,
            estimatedTotal: estimatedTotal,
            thresholdCount: thresholdCount,
            answeredWordIds: {},
            answeredCount: 0,
            correctCount: 0,
            prefetchStarted: false,
            prefetchReady: false,
            prefetchFailed: false,
            prefetchRequestedAt: 0,
            prefetchCompletedAt: 0,
            inFlightPromise: null
        };
    }

    function getResultsFollowupPrefetchStateForPlan(planLike) {
        const key = buildResultsFollowupPlanKey(planLike);
        if (!key || !resultsFollowupPrefetchState) {
            return null;
        }
        return resultsFollowupPrefetchState.launchKey === key ? resultsFollowupPrefetchState : null;
    }

    function startResultsFollowupPrefetch(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const session = resultsFollowupPrefetchState;
        if (!session || session.chunked || !isLoggedIn || !ajaxUrl || !nonce) {
            return $.Deferred().resolve(null).promise();
        }

        if (session.prefetchReady && !opts.forceRefresh) {
            return $.Deferred().resolve(session).promise();
        }

        if (session.inFlightPromise) {
            return session.inFlightPromise;
        }

        session.prefetchStarted = true;
        session.prefetchFailed = false;
        session.prefetchRequestedAt = Date.now();

        const dfd = $.Deferred();
        session.inFlightPromise = dfd.promise();

        const preferredMode = normalizeMode(opts.preferredMode || session.mode || '') || session.mode || 'practice';
        const finalizeRequest = function () {
            const request = refreshRecommendation({
                preferredMode: preferredMode,
                forceRefresh: true
            });

            request.done(function () {
                if (resultsFollowupPrefetchState !== session) { return; }
                session.prefetchReady = true;
                session.prefetchCompletedAt = Date.now();
            }).fail(function () {
                if (resultsFollowupPrefetchState !== session) { return; }
                session.prefetchFailed = true;
            }).always(function () {
                if (resultsFollowupPrefetchState === session) {
                    session.inFlightPromise = null;
                }
                dfd.resolve(session);
            });
        };

        let flushPromise = null;
        try {
            const tracker = window.LLFlashcards && window.LLFlashcards.ProgressTracker;
            if (tracker && typeof tracker.flush === 'function') {
                flushPromise = tracker.flush();
            }
        } catch (_) {
            flushPromise = null;
        }

        if (flushPromise && typeof flushPromise.then === 'function' && typeof Promise !== 'undefined' && typeof Promise.resolve === 'function') {
            Promise.resolve(flushPromise).catch(function () {
                return null;
            }).then(finalizeRequest);
        } else {
            finalizeRequest();
        }

        return session.inFlightPromise;
    }

    function noteResultsFollowupPrefetchOutcome(detail) {
        const session = resultsFollowupPrefetchState;
        if (!session || session.chunked || !isFlashcardOpen) {
            return;
        }

        const info = (detail && typeof detail === 'object') ? detail : {};
        const mode = normalizeMode(info.mode || '');
        if (mode && mode !== session.mode) {
            return;
        }

        const wordId = parseInt(info.word_id || info.wordId, 10) || 0;
        if (!wordId || session.answeredWordIds[wordId]) {
            return;
        }

        session.answeredWordIds[wordId] = true;
        session.answeredCount += 1;
        if (info.is_correct) {
            session.correctCount += 1;
        }

        const threshold = session.thresholdCount > 0
            ? session.thresholdCount
            : RESULTS_FOLLOWUP_PREFETCH_UNKNOWN_TOTAL_TRIGGER;
        if (session.answeredCount < threshold) {
            return;
        }

        if (session.prefetchReady || session.inFlightPromise) {
            return;
        }

        startResultsFollowupPrefetch({
            preferredMode: session.mode,
            forceRefresh: true
        });
    }

    function buildCurrentResultsPlan(resultMode) {
        const mode = normalizeMode(resultMode || (lastFlashcardLaunch && lastFlashcardLaunch.mode) || '');
        if (!mode) {
            return null;
        }
        const launch = (lastFlashcardLaunch && typeof lastFlashcardLaunch === 'object') ? lastFlashcardLaunch : {};
        const data = (window.llToolsFlashcardsData && typeof window.llToolsFlashcardsData === 'object')
            ? window.llToolsFlashcardsData
            : {};
        const baseCategoryIds = uniqueIntList(launch.category_ids && launch.category_ids.length
            ? launch.category_ids
            : ((data.userStudyState && data.userStudyState.category_ids) || state.category_ids || []));
        const categoryIds = filterCategoryIdsForMode(mode, baseCategoryIds.length ? baseCategoryIds : getVisibleCategoryIds());
        if (!categoryIds.length) {
            return null;
        }
        const sessionWordIds = uniqueIntList(launch.session_word_ids || data.session_word_ids || data.sessionWordIds || []);
        const launchDetails = (launch.details && typeof launch.details === 'object') ? launch.details : {};
        return {
            mode: mode,
            category_ids: categoryIds,
            session_word_ids: sessionWordIds,
            details: launchDetails,
            source: String(launch.source || ''),
            session_star_mode: normalizeStarMode(launch.session_star_mode || launch.sessionStarMode || 'normal'),
            category_label_override: String(launch.category_label_override || launch.categoryLabelOverride || '')
        };
    }

    function buildDifferentCategoryResultsPlan(currentPlan) {
        if (!currentPlan || !currentPlan.mode) { return null; }
        const mode = normalizeMode(currentPlan.mode);
        if (!mode) { return null; }
        const currentIds = uniqueIntList(currentPlan.category_ids || []);
        const currentLookup = {};
        currentIds.forEach(function (id) {
            currentLookup[id] = true;
        });

        const pickCandidateCategoryIds = function (rawIds) {
            const eligible = filterCategoryIdsForMode(mode, rawIds);
            const different = eligible.filter(function (id) {
                return !currentLookup[id];
            });
            if (!different.length) {
                return [];
            }
            return [different[0]];
        };

        const queueItems = normalizeRecommendationQueue(recommendationQueue || []);
        for (let idx = 0; idx < queueItems.length; idx += 1) {
            const activity = normalizeNextActivity(queueItems[idx]);
            if (!activity || normalizeMode(activity.mode) !== mode) { continue; }
            const baseIds = uniqueIntList(activity.category_ids || []);
            const categoryIds = pickCandidateCategoryIds(baseIds.length ? baseIds : getVisibleCategoryIds());
            if (!categoryIds.length) { continue; }
            return {
                mode: mode,
                category_ids: categoryIds,
                session_word_ids: [],
                details: (activity.details && typeof activity.details === 'object') ? activity.details : {},
                source: 'wordset_results_different_queue'
            };
        }

        const fallbackIds = pickCandidateCategoryIds(getVisibleCategoryIds());
        if (!fallbackIds.length) {
            return null;
        }
        return {
            mode: mode,
            category_ids: fallbackIds,
            session_word_ids: [],
            details: {},
            source: 'wordset_results_different_fallback'
        };
    }

    function buildRecommendedResultsPlan(currentPlan) {
        if (!currentPlan || !currentPlan.mode) { return null; }
        const currentMode = normalizeMode(currentPlan.mode);
        const currentCategoryIds = uniqueIntList(currentPlan.category_ids || []);

        const candidates = [];
        const next = normalizeNextActivity(nextActivity);
        if (next) {
            candidates.push(next);
        }
        normalizeRecommendationQueue(recommendationQueue || []).forEach(function (item) {
            const activity = normalizeNextActivity(item);
            if (!activity) { return; }
            const duplicateNext = next && normalizeMode(activity.mode) === normalizeMode(next.mode)
                && areCategorySetsEqual(activity.category_ids || [], next.category_ids || []);
            if (!duplicateNext) {
                candidates.push(activity);
            }
        });

        for (let idx = 0; idx < candidates.length; idx += 1) {
            const activity = candidates[idx];
            const mode = normalizeMode(activity.mode);
            if (!mode || mode === currentMode || !isModeEnabledForResultsActions(mode)) {
                continue;
            }
            const baseCategoryIds = uniqueIntList(activity.category_ids || []);
            const categoryIds = filterCategoryIdsForMode(mode, baseCategoryIds.length ? baseCategoryIds : getVisibleCategoryIds());
            if (!categoryIds.length || areCategorySetsEqual(categoryIds, currentCategoryIds)) {
                continue;
            }
            return {
                mode: mode,
                category_ids: categoryIds,
                session_word_ids: uniqueIntList(activity.session_word_ids || []),
                details: (activity.details && typeof activity.details === 'object') ? activity.details : {},
                source: 'wordset_results_recommended'
            };
        }
        return null;
    }

    function buildDifferentResultsLabel(plan) {
        const fallback = String(i18n.resultsDifferentChunk || i18n.categoriesLabel || 'Categories');
        if (!plan || !plan.category_ids || !plan.category_ids.length) {
            return fallback;
        }
        const categoryText = activityCategoryText({
            category_ids: plan.category_ids,
            details: plan.details || {}
        });
        if (categoryText) {
            return categoryText;
        }
        const count = uniqueIntList(plan.category_ids || []).length;
        if (count > 1) {
            const template = String(i18n.resultsDifferentChunkCount || '');
            if (template) {
                return formatTemplate(template, [modeLabel(plan.mode), count]);
            }
            return fallback + ' (' + String(count) + ')';
        }
        return fallback;
    }

    function buildRecommendedResultsLabel(plan) {
        const fallback = String(i18n.categoriesLabel || 'Categories');
        if (!plan) {
            return fallback;
        }
        const categoryText = activityCategoryText({
            category_ids: uniqueIntList(plan.category_ids || []),
            details: (plan.details && typeof plan.details === 'object') ? plan.details : {}
        });
        if (categoryText) {
            return categoryText;
        }
        const count = uniqueIntList(plan.category_ids || []).length;
        if (count > 1) {
            const template = String(i18n.resultsDifferentChunkCount || '');
            if (template) {
                return formatTemplate(template, [modeLabel(plan.mode), count]);
            }
            return fallback + ' (' + String(count) + ')';
        }
        return fallback;
    }

    function launchResultsPlan(plan, options) {
        const item = (plan && typeof plan === 'object') ? plan : null;
        if (!item || !item.mode) {
            return;
        }
        const opts = (options && typeof options === 'object') ? options : {};
        const categoryIds = uniqueIntList(item.category_ids || []);
        if (!categoryIds.length) {
            return;
        }
        const includeSessionWords = !opts.ignoreSessionWords;
        const sessionWordIds = includeSessionWords ? uniqueIntList(item.session_word_ids || []) : [];
        const details = (item.details && typeof item.details === 'object') ? item.details : {};
        const categoryLabelOverride = String(
            opts.categoryLabelOverride ||
            item.category_label_override ||
            resolveLearningCategoryLabelOverride(item.mode, categoryIds, details, '')
        ).trim();
        chunkSession = null;
        launchFlashcards(item.mode, categoryIds, sessionWordIds, {
            source: String(opts.source || item.source || 'wordset_results_action'),
            chunked: false,
            sessionStarMode: normalizeStarMode(opts.sessionStarMode || item.session_star_mode || 'normal'),
            details: details,
            categoryLabelOverride: categoryLabelOverride
        });
    }

    function hideWordsetResultsActions() {
        const $actions = $('#ll-study-results-actions');
        const $same = $('#ll-study-results-same-chunk');
        const $different = $('#ll-study-results-different-chunk');
        const $next = $('#ll-study-results-next-chunk');
        const $suggestion = $('#ll-study-results-suggestion');

        if ($suggestion.length) {
            $suggestion.hide().text('');
        }
        if ($same.length) {
            $same.hide().off('click');
        }
        if ($different.length) {
            $different.hide().off('click');
        }
        if ($next.length) {
            $next.hide().off('click');
        }
        if ($actions.length) {
            $actions.hide();
        }
    }

    function hideChunkResultsActions() {
        hideWordsetResultsActions();
    }

    function updateWordsetResultsActionButtons(currentPlan, differentPlan, nextPlan, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const $actions = $('#ll-study-results-actions');
        const $same = $('#ll-study-results-same-chunk');
        const $different = $('#ll-study-results-different-chunk');
        const $next = $('#ll-study-results-next-chunk');
        const $suggestion = $('#ll-study-results-suggestion');
        if (!$actions.length || !$same.length || !$different.length || !$next.length) {
            return;
        }
        $same.off('click');
        $different.off('click');
        $next.off('click');

        const showSame = !!currentPlan;
        const showDifferent = !!differentPlan && !arePlansDuplicate(differentPlan, currentPlan);
        const showNext = !!nextPlan &&
            normalizeMode(nextPlan.mode) !== normalizeMode(currentPlan && currentPlan.mode) &&
            !arePlansDuplicate(nextPlan, currentPlan) &&
            !(showDifferent && arePlansDuplicate(nextPlan, differentPlan));
        const showDifferentLoading = !showDifferent && !!opts.showDifferentLoading;
        const showNextLoading = !showNext && !!opts.showNextLoading;

        if (showSame) {
            setResultsButtonContent(
                $same,
                '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="↻"></span>',
                String(i18n.repeatLabel || 'Repeat')
            );
            $same.show().prop('disabled', false).off('click.llWordsetResults').on('click.llWordsetResults', function (evt) {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                launchResultsPlan(currentPlan, {
                    source: 'wordset_results_repeat',
                    sessionStarMode: currentPlan.session_star_mode || 'normal'
                });
            });
        } else {
            $same.hide().off('click.llWordsetResults');
        }

        if (showDifferent) {
            setResultsButtonContent($different, getModeIconMarkup(currentPlan.mode, 'll-vocab-lesson-mode-icon'), buildDifferentResultsLabel(differentPlan));
            $different.show().prop('disabled', false).off('click.llWordsetResults').on('click.llWordsetResults', function (evt) {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                launchResultsPlan(differentPlan, { source: 'wordset_results_different_category' });
            });
        } else if (showDifferentLoading) {
            setResultsButtonContent(
                $different,
                getLoadingModeIconMarkup('ll-vocab-lesson-mode-icon'),
                '',
                {
                    loading: true,
                    loadingAriaLabel: String(i18n.nextLoading || 'Loading recommendation...')
                }
            );
            $different.show().prop('disabled', true).off('click.llWordsetResults');
        } else {
            $different.hide().off('click.llWordsetResults');
        }

        if (showNext) {
            setResultsButtonContent($next, getModeIconMarkup(nextPlan.mode, 'll-vocab-lesson-mode-icon'), buildRecommendedResultsLabel(nextPlan));
            $next.show().prop('disabled', false).off('click.llWordsetResults').on('click.llWordsetResults', function (evt) {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                launchResultsPlan(nextPlan, { source: 'wordset_results_recommended' });
            });
        } else if (showNextLoading) {
            setResultsButtonContent(
                $next,
                getLoadingModeIconMarkup('ll-vocab-lesson-mode-icon'),
                '',
                {
                    loading: true,
                    loadingAriaLabel: String(i18n.nextLoading || 'Loading recommendation...')
                }
            );
            $next.show().prop('disabled', true).off('click.llWordsetResults');
        } else {
            $next.hide().off('click.llWordsetResults');
        }

        if ($suggestion.length) {
            $suggestion.text('').hide();
        }

        if (showSame || showDifferent || showDifferentLoading || showNext || showNextLoading) {
            $('#quiz-mode-buttons').hide();
            $('#ll-gender-results-actions').hide();
            $('#restart-quiz').hide();
            $actions.show();
        } else {
            $actions.hide();
        }
    }

    function renderStandardResultsActions(resultMode) {
        const flashData = (window.llToolsFlashcardsData && typeof window.llToolsFlashcardsData === 'object')
            ? window.llToolsFlashcardsData
            : {};
        const launchContext = String(flashData.launchContext || flashData.launch_context || '').trim().toLowerCase();
        if (launchContext !== 'dashboard') {
            return false;
        }

        const currentPlan = buildCurrentResultsPlan(resultMode);
        if (!currentPlan) {
            return false;
        }

        const prefetchState = getResultsFollowupPrefetchStateForPlan(currentPlan);
        const followupsReady = !!(prefetchState && prefetchState.prefetchReady);
        let differentPlan = followupsReady ? buildDifferentCategoryResultsPlan(currentPlan) : null;
        let nextPlan = followupsReady ? buildRecommendedResultsPlan(currentPlan) : null;
        updateWordsetResultsActionButtons(currentPlan, differentPlan, nextPlan, {
            showDifferentLoading: !followupsReady,
            showNextLoading: !followupsReady
        });

        if (followupsReady) {
            return true;
        }

        const refreshToken = ++resultsFollowupRefreshToken;
        startResultsFollowupPrefetch({
            preferredMode: currentPlan.mode,
            forceRefresh: true
        }).always(function () {
            if (refreshToken !== resultsFollowupRefreshToken) { return; }
            differentPlan = buildDifferentCategoryResultsPlan(currentPlan);
            nextPlan = buildRecommendedResultsPlan(currentPlan);
            updateWordsetResultsActionButtons(currentPlan, differentPlan, nextPlan, {
                showDifferentLoading: false,
                showNextLoading: false
            });
        });

        return true;
    }

    function renderChunkResultsActions() {
        if (!chunkSession || !chunkSession.chunks || !chunkSession.chunks.length) {
            return false;
        }

        hideWordsetResultsActions();

        const $actions = $('#ll-study-results-actions');
        const $same = $('#ll-study-results-same-chunk');
        const $different = $('#ll-study-results-different-chunk');
        const $next = $('#ll-study-results-next-chunk');
        const hasNext = chunkSession.index < (chunkSession.chunks.length - 1);

        if (!$actions.length || !$same.length || !$next.length) {
            return false;
        }

        $('#quiz-mode-buttons').hide();
        $('#ll-gender-results-actions').hide();
        $('#restart-quiz').hide();

        setResultsButtonContent($same, '<span class="ll-vocab-lesson-mode-icon" aria-hidden="true" data-emoji="↻"></span>', i18n.repeatLabel || 'Repeat');
            $same.show().off('click').on('click.llWordsetChunk', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const currentChunk = chunkSession.chunks[chunkSession.index] || [];
                launchFlashcards(chunkSession.mode, chunkSession.category_ids, currentChunk, {
                    source: 'wordset_chunk_repeat',
                    chunked: true,
                    sessionStarMode: chunkSession.star_mode || 'normal',
                    categoryLabelOverride: String(chunkSession.category_label_override || '').trim()
                });
            });

        if ($different.length) {
            $different.hide().off('click.llWordsetChunk');
        }

        if (hasNext) {
            setResultsButtonContent($next, getModeIconMarkup(chunkSession.mode, 'll-vocab-lesson-mode-icon'), i18n.continueLabel || 'Continue');
            $next.show().off('click').on('click.llWordsetChunk', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (!chunkSession) { return; }
                if (chunkSession.index >= chunkSession.chunks.length - 1) { return; }
                chunkSession.index += 1;
                const nextChunk = chunkSession.chunks[chunkSession.index] || [];
                launchFlashcards(chunkSession.mode, chunkSession.category_ids, nextChunk, {
                    source: 'wordset_chunk_continue',
                    chunked: true,
                    sessionStarMode: chunkSession.star_mode || 'normal',
                    categoryLabelOverride: String(chunkSession.category_label_override || '').trim()
                });
            });
        } else {
            $next.hide().off('click.llWordsetChunk');
        }

        $actions.show();
        return true;
    }

    function openFlashcardLaunchLoadingState() {
        const $popup = $('#ll-tools-flashcard-popup');
        const $quizPopup = $('#ll-tools-flashcard-quiz-popup');
        const wasVisible = $popup.is(':visible') || $quizPopup.is(':visible');
        const $loading = $('#ll-tools-loading-animation');

        $('body').addClass('ll-tools-flashcard-open');
        $popup.show();
        $quizPopup
            .show()
            .addClass('ll-round-loading-active ll-round-loading-instant')
            .attr('aria-busy', 'true');

        if ($loading.length) {
            const $body = $('body');
            if ($body.length && $loading.parent()[0] !== $body[0]) {
                $loading.appendTo($body);
            }
            $loading.show();
        }

        if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.showLoading === 'function') {
            try { window.LLFlashcards.Dom.showLoading(); } catch (_) { /* no-op */ }
        }

        return {
            $popup: $popup,
            $quizPopup: $quizPopup,
            wasVisible: wasVisible
        };
    }

    function closeFlashcardLaunchLoadingState(launchUi) {
        const ui = (launchUi && typeof launchUi === 'object') ? launchUi : {};
        const $popup = (ui.$popup && ui.$popup.length) ? ui.$popup : $('#ll-tools-flashcard-popup');
        const $quizPopup = (ui.$quizPopup && ui.$quizPopup.length) ? ui.$quizPopup : $('#ll-tools-flashcard-quiz-popup');

        if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.hideLoading === 'function') {
            try { window.LLFlashcards.Dom.hideLoading(); } catch (_) { /* no-op */ }
        }

        $('#ll-tools-loading-animation').hide();
        $quizPopup
            .removeClass('ll-round-loading-active ll-round-loading-instant')
            .removeAttr('aria-busy');

        if (!ui.wasVisible) {
            $('body').removeClass('ll-tools-flashcard-open ll-qpg-popup-active').css('overflow', '');
            $('html').css('overflow', '');
            $quizPopup.hide();
            $popup.hide();
        }
    }

    function launchFlashcards(mode, categoryIds, sessionWordIds, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const normalizedMode = normalizeMode(mode) || 'practice';
        const sessionStarModeOverride = normalizeStarMode(opts.sessionStarMode || 'normal');
        const randomizeSessionCategoryOrder = !!opts.randomizeSessionCategoryOrder;
        const launchDetails = (opts.details && typeof opts.details === 'object') ? Object.assign({}, opts.details) : {};
        const requestedCategoryLabelOverride = String(opts.categoryLabelOverride || '').trim();
        const requestedIds = uniqueIntList(categoryIds || []);
        const ids = resolveLaunchCategoryIds(requestedIds, {
            preferCategoryId: requestedIds[0] || 0,
            fallbackCategoryIds: opts.fallbackCategoryIds || [],
            mode: normalizedMode
        });

        if (!ids.length) {
            alert(i18n.noCategoriesSelected || 'Select at least one category.');
            return;
        }

        const finalMode = (normalizedMode === 'gender' && !selectionHasGenderSupport(ids)) ? 'practice' : normalizedMode;
        const sessionIds = uniqueIntList(sessionWordIds || []);
        const launchUi = openFlashcardLaunchLoadingState();
        const abortLaunch = function (message) {
            closeFlashcardLaunchLoadingState(launchUi);
            if (message) {
                alert(message);
            }
        };

        ensureWordsForCategories(ids).always(function () {
            let launchCategoryIds = ids.slice();
            let effectiveSessionIds = sessionIds.slice();
            let effectiveRequestedCategoryLabelOverride = requestedCategoryLabelOverride;
            if (finalMode === 'learning') {
                const criteriaKey = normalizePriorityFocus(launchDetails.priority_focus || '');
                const isCriteriaFiltered = criteriaKey === 'starred' || criteriaKey === 'hard';
                const minimumWords = isCriteriaFiltered ? LEARNING_MIN_CHUNK_SIZE : 3;
                const shouldRebuildLearningPlan = isCriteriaFiltered || (
                    effectiveSessionIds.length > 0 && effectiveSessionIds.length < minimumWords
                );

                if (shouldRebuildLearningPlan) {
                    const learningPlan = buildLearningSelectionLaunchPlan(launchCategoryIds, {
                        starOnly: criteriaKey === 'starred',
                        hardOnly: criteriaKey === 'hard',
                        preferCategoryId: launchCategoryIds[0] || 0,
                        minimumWords: minimumWords
                    });
                    const plannedCategoryIds = uniqueIntList(learningPlan.categoryIds || []);
                    const plannedSessionWordIds = uniqueIntList(learningPlan.sessionWordIds || []);
                    if (plannedCategoryIds.length) {
                        launchCategoryIds = plannedCategoryIds;
                    }
                    if (plannedSessionWordIds.length) {
                        effectiveSessionIds = plannedSessionWordIds;
                    }
                    if (!effectiveRequestedCategoryLabelOverride) {
                        effectiveRequestedCategoryLabelOverride = String(learningPlan.categoryLabelOverride || '').trim();
                    }
                }
            }

            let selectedCats = categories.filter(function (cat) {
                return launchCategoryIds.indexOf(cat.id) !== -1 && !isCategoryHidden(cat.id);
            });
            if (!selectedCats.length) {
                abortLaunch(i18n.noCategoriesSelected || 'Select at least one category.');
                return;
            }

            const getWordRowsForCategory = function (categoryId, lookup) {
                const rows = Array.isArray(wordsByCategory[categoryId]) ? wordsByCategory[categoryId] : [];
                if (!rows.length) { return []; }
                if (!lookup) { return rows.slice(); }
                return rows.filter(function (word) {
                    const wordId = parseInt(word && word.id, 10) || 0;
                    return !!lookup[wordId];
                });
            };
            const hasWordRowsForCategory = function (categoryId, lookup) {
                return getWordRowsForCategory(categoryId, lookup).length > 0;
            };
            if (effectiveSessionIds.length) {
                const sessionLookup = {};
                effectiveSessionIds.forEach(function (id) {
                    sessionLookup[id] = true;
                });

                const withMatches = [];
                const withoutMatches = [];
                selectedCats.forEach(function (cat) {
                    const rows = Array.isArray(wordsByCategory[cat.id]) ? wordsByCategory[cat.id] : [];
                    const hasMatch = rows.some(function (word) {
                        const wordId = parseInt(word && word.id, 10) || 0;
                        return !!sessionLookup[wordId];
                    });
                    if (hasMatch) {
                        withMatches.push(cat);
                    } else {
                        withoutMatches.push(cat);
                    }
                });

                if (withMatches.length) {
                    // Keep a matched category first so first-round loading has eligible words.
                    selectedCats = withMatches.concat(withoutMatches);
                } else {
                    // Stale/invalid session ids: fall back to normal category-based launch.
                    effectiveSessionIds = [];
                }
            }

            // Exclude categories that would contribute zero words for this launch.
            let effectiveLookup = null;
            if (effectiveSessionIds.length) {
                effectiveLookup = {};
                effectiveSessionIds.forEach(function (id) {
                    effectiveLookup[id] = true;
                });
            }
            selectedCats = selectedCats.filter(function (cat) {
                return hasWordRowsForCategory(cat.id, effectiveLookup);
            });

            // If session filtering made every category empty, retry without session filter.
            if (!selectedCats.length && effectiveSessionIds.length) {
                effectiveSessionIds = [];
                selectedCats = categories.filter(function (cat) {
                    return launchCategoryIds.indexOf(cat.id) !== -1 && !isCategoryHidden(cat.id) && hasWordRowsForCategory(cat.id, null);
                });
            }

            if (!selectedCats.length) {
                abortLaunch(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                return;
            }

            if (finalMode === 'learning') {
                if (effectiveSessionIds.length > 0 && effectiveSessionIds.length <= 2) {
                    abortLaunch(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                    return;
                }
                if (!effectiveSessionIds.length) {
                    let availableWordCount = 0;
                    selectedCats.forEach(function (cat) {
                        availableWordCount += getWordRowsForCategory(cat.id, null).length;
                    });
                    if (availableWordCount <= 2) {
                        abortLaunch(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                        return;
                    }
                }
            }

            if (effectiveSessionIds.length && !randomizeSessionCategoryOrder) {
                selectedCats = selectedCats
                    .map(function (cat, index) {
                        return {
                            cat: cat,
                            index: index,
                            count: getWordRowsForCategory(cat.id, effectiveLookup).length
                        };
                    })
                    .sort(function (left, right) {
                        if (right.count !== left.count) {
                            return right.count - left.count;
                        }
                        return left.index - right.index;
                    })
                    .map(function (entry) {
                        return entry.cat;
                    });
            } else {
                selectedCats = shuffleList(selectedCats);
            }

            const effectiveCategoryIds = uniqueIntList(selectedCats.map(function (cat) {
                return parseInt(cat.id, 10) || 0;
            }));
            const resolvedCategoryLabelOverride = effectiveRequestedCategoryLabelOverride ||
                resolveLearningCategoryLabelOverride(finalMode, effectiveCategoryIds, launchDetails, '');
            const estimatedResultsTotal = estimateFlashcardResultsWordCount(
                finalMode,
                effectiveSessionIds,
                selectedCats,
                effectiveLookup
            );

            lastFlashcardLaunch = {
                mode: finalMode,
                category_ids: effectiveCategoryIds.slice(),
                session_word_ids: effectiveSessionIds.slice(),
                source: String(opts.source || ''),
                session_star_mode: sessionStarModeOverride,
                details: launchDetails,
                category_label_override: resolvedCategoryLabelOverride,
                estimated_results_total: estimatedResultsTotal,
                chunked: !!opts.chunked
            };
            initializeResultsFollowupPrefetchStateForLaunch(lastFlashcardLaunch);

            const firstCategory = selectedCats[0];
            const firstRows = shuffleList(getWordRowsForCategory(firstCategory.id, effectiveLookup));

            const flashData = (window.llToolsFlashcardsData && typeof window.llToolsFlashcardsData === 'object')
                ? window.llToolsFlashcardsData
                : {};

            // Wordset-page launches should start with a fresh gender plan scope.
            // Otherwise a stale armed plan from a previous gender results action can
            // override the requested category for card-level launches.
            try { delete flashData.genderSessionPlan; } catch (_) { /* no-op */ }
            try { delete flashData.genderSessionPlanArmed; } catch (_) { /* no-op */ }
            try { delete flashData.gender_session_plan_armed; } catch (_) { /* no-op */ }
            flashData.genderLaunchSource = effectiveCategoryIds.length > 1 ? 'dashboard' : 'direct';

            flashData.launchContext = 'dashboard';
            flashData.launch_context = 'dashboard';
            flashData.categories = selectedCats.slice();
            flashData.categoriesPreselected = true;
            flashData.firstCategoryName = String(firstCategory.name || '');
            flashData.firstCategoryData = firstRows;
            flashData.wordset = wordsetSlug || String(wordsetId || '');
            flashData.wordsetIds = wordsetId > 0 ? [wordsetId] : [];
            flashData.wordsetFallback = false;
            flashData.quiz_mode = finalMode;
            flashData.starMode = state.star_mode;
            flashData.star_mode = state.star_mode;
            flashData.sessionStarModeOverride = sessionStarModeOverride;
            flashData.session_star_mode_override = sessionStarModeOverride;
            flashData.fastTransitions = !!state.fast_transitions;
            flashData.fast_transitions = !!state.fast_transitions;
            flashData.sessionWordIds = effectiveSessionIds.slice();
            flashData.session_word_ids = effectiveSessionIds.slice();
            flashData.lastLaunchPlan = Object.assign({}, lastFlashcardLaunch);
            flashData.last_launch_plan = Object.assign({}, lastFlashcardLaunch);
            if (resolvedCategoryLabelOverride) {
                flashData.categoryDisplayOverride = resolvedCategoryLabelOverride;
                flashData.category_display_override = resolvedCategoryLabelOverride;
            } else {
                delete flashData.categoryDisplayOverride;
                delete flashData.category_display_override;
            }
            flashData.userStudyState = flashData.userStudyState || {};
            flashData.userStudyState.wordset_id = wordsetId;
            flashData.userStudyState.category_ids = effectiveCategoryIds.slice();
            flashData.userStudyState.starred_word_ids = uniqueIntList(state.starred_word_ids || []);
            flashData.userStudyState.star_mode = state.star_mode;
            flashData.userStudyState.fast_transitions = !!state.fast_transitions;

            flashData.genderEnabled = !!genderCfg.enabled;
            flashData.genderWordsetId = wordsetId;
            flashData.genderOptions = Array.isArray(genderCfg.options) ? genderCfg.options.slice() : [];
            flashData.genderMinCount = Math.max(2, parseInt(genderCfg.min_count, 10) || 2);

            window.llToolsFlashcardsData = flashData;
            syncGlobalPrefs();

            hideChunkResultsActions();

            const catNames = selectedCats.map(function (cat) {
                return String(cat.name || '');
            }).filter(Boolean);

            const $popup = launchUi.$popup;
            const $quizPopup = launchUi.$quizPopup;
            $popup.show();
            $quizPopup.show();

            if (typeof window.initFlashcardWidget === 'function') {
                const initRes = window.initFlashcardWidget(catNames, finalMode);
                if (initRes && typeof initRes.catch === 'function') {
                    initRes.catch(function () {
                        closeFlashcardLaunchLoadingState(launchUi);
                    });
                }
            } else {
                closeFlashcardLaunchLoadingState(launchUi);
            }
        });
    }

    function launchSelectionMode(mode) {
        const normalizedMode = normalizeMode(mode) || 'practice';
        const selectedIds = uniqueIntList(selectedCategoryIds || []).filter(function (id) {
            return !isCategoryHidden(id);
        });
        const starredOnlyActive = !!selectionStarredOnly;
        const hardOnlyActive = !!selectionHardOnly;
        const criteriaKey = resolveSelectionCriteriaKey({
            starOnly: starredOnlyActive,
            hardOnly: hardOnlyActive
        });
        const resolveEmptyMessage = function () {
            if (starredOnlyActive && hardOnlyActive) {
                return i18n.noStarredHardWordsInSelection || 'No starred hard words are available for this selection.';
            }
            if (starredOnlyActive) {
                return i18n.noStarredWordsInSelection || 'No starred words are available for this selection.';
            }
            if (hardOnlyActive) {
                return i18n.noHardWordsInSelection || 'No hard words are available for this selection.';
            }
            return i18n.noWordsInSelection || 'No quiz words are available for this selection.';
        };

        if (!selectedIds.length) {
            alert(i18n.noCategoriesSelected || 'Select at least one category.');
            return;
        }

        ensureWordsForCategories(selectedIds).always(function () {
            if (normalizedMode === 'learning') {
                const learningPlan = buildLearningSelectionLaunchPlan(selectedIds, {
                    starOnly: starredOnlyActive,
                    hardOnly: hardOnlyActive,
                    preferCategoryId: selectedIds[0] || 0,
                    minimumWords: LEARNING_MIN_CHUNK_SIZE
                });
                const learningCategoryIds = uniqueIntList(learningPlan.categoryIds || []);
                const learningWordIds = uniqueIntList(learningPlan.sessionWordIds || []);
                if (!learningCategoryIds.length || !learningWordIds.length) {
                    alert(resolveEmptyMessage());
                    return;
                }
                if (learningWordIds.length <= 2) {
                    alert(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                    return;
                }

                const chunks = buildChunkList(learningWordIds, {
                    minChunkSize: LEARNING_MIN_CHUNK_SIZE
                });
                if (!chunks.length) {
                    alert(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                    return;
                }

                const categoryLabelOverride = resolveLearningCategoryLabelOverride(
                    normalizedMode,
                    learningCategoryIds,
                    { priority_focus: criteriaKey },
                    criteriaKey
                ) || String(learningPlan.categoryLabelOverride || '').trim();
                const launchDetails = criteriaKey ? { priority_focus: criteriaKey } : {};

                if (chunks.length > 1) {
                    chunkSession = {
                        mode: normalizedMode,
                        category_ids: learningCategoryIds.slice(),
                        chunks: chunks,
                        index: 0,
                        star_mode: starredOnlyActive ? 'only' : 'normal',
                        category_label_override: categoryLabelOverride
                    };
                    launchFlashcards(chunkSession.mode, chunkSession.category_ids, chunkSession.chunks[0], {
                        source: 'wordset_chunk_start',
                        chunked: true,
                        sessionStarMode: chunkSession.star_mode,
                        randomizeSessionCategoryOrder: true,
                        details: launchDetails,
                        categoryLabelOverride: chunkSession.category_label_override
                    });
                    return;
                }

                chunkSession = null;
                launchFlashcards(normalizedMode, learningCategoryIds, chunks[0], {
                    source: 'wordset_selection_start',
                    chunked: false,
                    sessionStarMode: starredOnlyActive ? 'only' : 'normal',
                    randomizeSessionCategoryOrder: true,
                    details: launchDetails,
                    categoryLabelOverride: categoryLabelOverride
                });
                return;
            }

            const ids = filterCategoryIdsByAspectBucket(selectedIds, {
                preferCategoryId: selectedIds[0] || 0
            });
            if (!ids.length) {
                alert(i18n.noCategoriesSelected || 'Select at least one category.');
                return;
            }

            const wordIds = collectWordIdsForCategories(ids, {
                starOnly: starredOnlyActive,
                hardOnly: hardOnlyActive
            });
            if (!wordIds.length) {
                alert(resolveEmptyMessage());
                return;
            }

            const randomizedWordIds = shuffleList(wordIds);

            if (normalizedMode === 'listening') {
                chunkSession = null;
                launchFlashcards(normalizedMode, ids, [], {
                    source: 'wordset_selection_start',
                    chunked: false,
                    sessionStarMode: starredOnlyActive ? 'only' : 'normal'
                });
                return;
            }

            const chunks = buildChunkList(randomizedWordIds);
            if (!chunks.length) {
                alert(i18n.noWordsInSelection || 'No quiz words are available for this selection.');
                return;
            }

            if (chunks.length > 1) {
                chunkSession = {
                    mode: normalizedMode,
                    category_ids: ids.slice(),
                    chunks: chunks,
                    index: 0,
                    star_mode: starredOnlyActive ? 'only' : 'normal',
                    category_label_override: ''
                };
                launchFlashcards(chunkSession.mode, chunkSession.category_ids, chunkSession.chunks[0], {
                    source: 'wordset_chunk_start',
                    chunked: true,
                    sessionStarMode: chunkSession.star_mode,
                    randomizeSessionCategoryOrder: true
                });
                return;
            }

            chunkSession = null;
            launchFlashcards(normalizedMode, ids, chunks[0], {
                source: 'wordset_selection_start',
                chunked: false,
                sessionStarMode: starredOnlyActive ? 'only' : 'normal',
                randomizeSessionCategoryOrder: true
            });
        });
    }

    function launchRecommendedMode(mode) {
        const preferredMode = normalizeMode(mode) || 'practice';
        const recommendationScopeIds = getRecommendationScopeIds();
        // Top mode buttons choose the mode explicitly; filter recommendation scopes to
        // categories that can actually run that mode (prevents gender -> practice fallback).
        const preferredFallbackCategoryIds = filterCategoryIdsForMode(
            preferredMode,
            recommendationScopeIds.length ? recommendationScopeIds : getVisibleCategoryIds()
        );
        if (preferredMode === 'listening') {
            chunkSession = null;
            launchFlashcards(preferredMode, recommendationScopeIds, [], {
                source: 'wordset_top_start_listening_full',
                chunked: false,
                fallbackCategoryIds: recommendationScopeIds
            });
            return;
        }
        const launchWithActivity = function (activity, source) {
            const item = normalizeNextActivity(activity);
            const rawCategoryIds = item && item.category_ids && item.category_ids.length
                ? uniqueIntList(item.category_ids)
                : getVisibleCategoryIds();
            let categoryIds = filterCategoryIdsForMode(preferredMode, rawCategoryIds);
            if (!categoryIds.length && preferredFallbackCategoryIds.length) {
                categoryIds = preferredFallbackCategoryIds.slice();
            }
            if (!categoryIds.length) {
                categoryIds = getVisibleCategoryIds();
            }
            const sessionWordIds = item && item.session_word_ids && item.session_word_ids.length
                ? uniqueIntList(item.session_word_ids)
                : [];
            const details = (item && item.details && typeof item.details === 'object') ? item.details : {};
            chunkSession = null;
            launchFlashcards(preferredMode, categoryIds, sessionWordIds, {
                source: source || 'wordset_top_start_recommended',
                chunked: false,
                fallbackCategoryIds: preferredFallbackCategoryIds.length
                    ? preferredFallbackCategoryIds
                    : recommendationScopeIds,
                details: details
            });
        };

        const queued = recommendationQueueHead(preferredMode);
        if (queued) {
            launchWithActivity(queued, 'wordset_top_start_queue');
            return;
        }

        refreshRecommendation({ preferredMode: preferredMode, forceRefresh: true }).always(function () {
            const refreshed = recommendationQueueHead(preferredMode) || normalizeNextActivity(nextActivity);
            if (refreshed) {
                launchWithActivity(refreshed, 'wordset_top_start_refreshed');
                return;
            }
            chunkSession = null;
            launchFlashcards(preferredMode, getVisibleCategoryIds(), [], {
                source: 'wordset_top_start_fallback',
                chunked: false,
                fallbackCategoryIds: recommendationScopeIds
            });
        });
    }

    function hideCategory(catId) {
        const id = parseInt(catId, 10) || 0;
        if (!id || !isLoggedIn) { return; }
        const previousIgnored = uniqueIntList(goals.ignored_category_ids || []);
        if (previousIgnored.indexOf(id) !== -1) { return; }
        const ignored = previousIgnored.slice();
        ignored.push(id);
        goals.ignored_category_ids = uniqueIntList(ignored);
        renderHiddenCount();

        saveGoalsNow().done(function () {
            categories = categories.map(function (cat) {
                if (cat.id === id) {
                    cat.hidden = true;
                }
                return cat;
            });
            state.category_ids = uniqueIntList((state.category_ids || []).filter(function (value) {
                return value !== id;
            }));
            saveStateDebounced();
            removeHiddenCategoryFromSelection(id);
            const $card = $root.find('.ll-wordset-card[data-cat-id="' + id + '"]');
            if ($card.length) {
                animateHiddenCardRemoval($card);
            }
            window.setTimeout(function () {
                renderHiddenCount({ pulse: true, scrollIntoView: false });
            }, prefersReducedMotion() ? 0 : 430);
            renderSelectionBar();
        }).fail(function () {
            goals.ignored_category_ids = previousIgnored;
            renderHiddenCount();
            alert(i18n.saveError || 'Unable to save right now.');
        });
    }

    function unhideCategory(catId) {
        const id = parseInt(catId, 10) || 0;
        if (!id || !isLoggedIn) { return; }
        const previousIgnored = uniqueIntList(goals.ignored_category_ids || []);
        goals.ignored_category_ids = uniqueIntList(previousIgnored.filter(function (value) {
            return value !== id;
        }));
        renderHiddenCount();

        saveGoalsNow().done(function () {
            categories = categories.map(function (cat) {
                if (cat.id === id) {
                    cat.hidden = false;
                }
                return cat;
            });
            const $row = $root.find('[data-ll-hidden-row][data-cat-id="' + id + '"]');
            if ($row.length) {
                $row.remove();
            }
            if (!$root.find('[data-ll-hidden-row]').length) {
                $root.find('[data-ll-wordset-hidden-list]').html('<div class="ll-wordset-empty">' + escapeHtml(i18n.hiddenEmpty || 'No hidden categories in this word set.') + '</div>');
            }
        }).fail(function () {
            goals.ignored_category_ids = previousIgnored;
            renderHiddenCount();
            alert(i18n.saveError || 'Unable to save right now.');
        });
    }

    function removeQueueActivity(queueId) {
        const id = String(queueId || '').trim();
        if (!id || !isLoggedIn || !ajaxUrl || !nonce) {
            return $.Deferred().reject().promise();
        }
        return $.post(ajaxUrl, {
            action: 'll_user_study_queue_remove',
            nonce: nonce,
            wordset_id: wordsetId,
            queue_id: id
        }).done(function (res) {
            if (res && res.success && res.data) {
                applyRecommendationPayload(res.data);
            }
        });
    }

    function removeCurrentNextActivityWithTransition() {
        const next = normalizeNextActivity(nextActivity) || recommendationQueueHead();
        const queueId = resolveQueueIdForActivity(next);
        if (!queueId) {
            return;
        }
        if (($nextShell.length && $nextShell.hasClass('is-loading')) || $nextCard.hasClass('is-loading')) {
            return;
        }
        setNextCardLoadingState({ lockDimensions: true });
        removeQueueActivity(queueId).done(function (res) {
            if (!(res && res.success && res.data)) {
                clearNextCardLoadingState();
                renderNextCard();
            }
        }).fail(function () {
            clearNextCardLoadingState();
            renderNextCard();
            alert(i18n.saveError || 'Unable to save right now.');
        });
    }

    function bindSettingsControls() {
        $root.on('click', '[data-ll-wordset-transition]', function (e) {
            e.preventDefault();
            const speed = String($(this).attr('data-ll-wordset-transition') || 'slow');
            state.fast_transitions = speed === 'fast';
            syncSettingsButtons();
            syncGlobalPrefs();
            saveStateDebounced();
        });

        $root.on('click', '[data-ll-wordset-goal-mode]', function (e) {
            e.preventDefault();
            const mode = normalizeMode($(this).attr('data-ll-wordset-goal-mode') || '');
            if (!mode) { return; }
            if (mode === 'gender' && !wordsetHasGenderSupport()) { return; }

            const prevEnabledModes = uniqueModeList(goals.enabled_modes || []);
            const nextEnabledModes = prevEnabledModes.slice();
            const idx = nextEnabledModes.indexOf(mode);

            if (idx === -1) {
                nextEnabledModes.push(mode);
            } else if (nextEnabledModes.length > 1) {
                nextEnabledModes.splice(idx, 1);
            } else {
                return;
            }

            goals.enabled_modes = uniqueModeList(nextEnabledModes);
            syncSettingsButtons();
            const saveRequest = saveGoalsNow();
            const saveToken = parseInt(saveRequest && saveRequest.llToolsRequestToken, 10) || 0;
            saveRequest.fail(function () {
                if (saveToken > 0 && !isLatestGoalsSaveRequest(saveToken)) {
                    return;
                }
                goals.enabled_modes = prevEnabledModes;
                syncSettingsButtons();
                alert(i18n.saveError || 'Unable to save right now.');
            });
        });

        $root.on('click', '[data-ll-wordset-priority-focus]', function (e) {
            e.preventDefault();
            const value = normalizePriorityFocus($(this).attr('data-ll-wordset-priority-focus') || '');
            if (!value) { return; }
            const previousChoice = focusChoiceFromGoals();
            const nextChoice = (value === previousChoice) ? '' : value;
            applyFocusChoice(nextChoice);
            syncSettingsButtons();
            const saveRequest = saveGoalsNow();
            const saveToken = parseInt(saveRequest && saveRequest.llToolsRequestToken, 10) || 0;
            saveRequest.fail(function () {
                if (saveToken > 0 && !isLatestGoalsSaveRequest(saveToken)) {
                    return;
                }
                applyFocusChoice(previousChoice);
                syncSettingsButtons();
                alert(i18n.saveError || 'Unable to save right now.');
            });
        });

        $root.on('click', '[data-ll-wordset-queue-remove]', function (e) {
            e.preventDefault();
            const queueId = String($(this).attr('data-queue-id') || '');
            if (!queueId) { return; }
            removeQueueActivity(queueId).fail(function () {
                alert(i18n.saveError || 'Unable to save right now.');
            });
        });
    }

    function bindMainInteractions() {
        syncSettingsButtons();
        renderNextCard();
        renderSettingsQueue();
        renderMiniCounts();
        renderHiddenCount();
        ensureCategoryProgressVisibilityTracking();
        schedulePendingCategoryProgressVisibilityCheck();
        clearCategorySelection({ refreshRecommendation: false });
        syncGlobalPrefs();
        scheduleSelectAllAlignment();
        $(window).off('resize.llWordsetSelectAllAlign').on('resize.llWordsetSelectAllAlign', function () {
            scheduleSelectAllAlignment();
        });

        $root.on('change', '[data-ll-wordset-select]', function () {
            selectedCategoryIds = getCategoryIdsFromCheckedUI();
            renderSelectionBar();
        });

        $root.on('change', '[data-ll-wordset-selection-starred-only]', function () {
            selectionStarredOnly = !!$(this).is(':checked');
            if (selectionStarredOnly) {
                selectionHardOnly = false;
                if ($selectionHardToggle.length) {
                    $selectionHardToggle.prop('checked', false);
                }
            }
            renderSelectionBar();
        });

        $root.on('change', '[data-ll-wordset-selection-hard-only]', function () {
            selectionHardOnly = !!$(this).is(':checked');
            if (selectionHardOnly) {
                selectionStarredOnly = false;
                if ($selectionStarredToggle.length) {
                    $selectionStarredToggle.prop('checked', false);
                }
            }
            renderSelectionBar();
        });

        $root.on('click', '[data-ll-wordset-select-all]', function (e) {
            e.preventDefault();
            if ($(this).prop('disabled') || String($(this).attr('aria-disabled') || '') === 'true') {
                return;
            }
            const allIds = getSelectableCategoryIdsFromUI();
            if (!allIds.length) { return; }
            const allLookup = {};
            allIds.forEach(function (id) {
                allLookup[id] = true;
            });
            const checkedIds = getCategoryIdsFromCheckedUI().filter(function (id) {
                return !!allLookup[id];
            });
            const selectAll = checkedIds.length !== allIds.length;
            $root.find('[data-ll-wordset-select]').each(function () {
                const id = parseInt($(this).val(), 10) || 0;
                if (!id || !allLookup[id]) { return; }
                $(this).prop('checked', selectAll);
            });
            selectedCategoryIds = selectAll ? allIds.slice() : [];
            renderSelectionBar();
        });

        $root.on('click', '[data-ll-wordset-selection-clear]', function (e) {
            e.preventDefault();
            clearCategorySelection({ refreshRecommendation: true });
        });

        $root.on('click', '[data-ll-wordset-hide]', function (e) {
            e.preventDefault();
            hideCategory($(this).attr('data-cat-id'));
        });

        $root.on('click', '[data-ll-wordset-start-mode]', function (e) {
            e.preventDefault();
            if (hasActiveCategorySelection() || $(this).prop('disabled')) {
                return;
            }
            const mode = normalizeMode($(this).attr('data-mode') || 'practice') || 'practice';
            launchRecommendedMode(mode);
        });

        $root.on('click', '[data-ll-wordset-category-mode]', function (e) {
            e.preventDefault();
            const mode = normalizeMode($(this).attr('data-mode') || 'practice') || 'practice';
            const catId = parseInt($(this).attr('data-cat-id'), 10) || 0;
            if (!catId) { return; }
            chunkSession = null;
            launchFlashcards(mode, [catId], [], {
                source: 'wordset_category_start',
                chunked: false
            });
        });

        $root.on('click', '[data-ll-wordset-selection-mode]', function (e) {
            e.preventDefault();
            const mode = normalizeMode($(this).attr('data-mode') || 'practice') || 'practice';
            launchSelectionMode(mode);
        });

        if ($nextCard.length) {
            $nextCard.on('click', function (e) {
                e.preventDefault();
                if (hasActiveCategorySelection() || $nextCard.hasClass('is-disabled') || String($nextCard.attr('aria-disabled') || '') === 'true') {
                    return;
                }
                const next = normalizeNextActivity(nextActivity) || recommendationQueueHead();
                if (!next || !next.mode) {
                    refreshRecommendation({ forceRefresh: true });
                    return;
                }
                const nextIds = uniqueIntList((next.category_ids && next.category_ids.length)
                    ? next.category_ids
                    : getRecommendationScopeIds());
                const recommendationScopeIds = getRecommendationScopeIds();
                const nextDetails = (next.details && typeof next.details === 'object') ? next.details : {};
                chunkSession = null;
                launchFlashcards(next.mode, nextIds, next.session_word_ids || [], {
                    source: 'wordset_next_start',
                    chunked: false,
                    fallbackCategoryIds: recommendationScopeIds,
                    details: nextDetails
                });
            });
        }

        $root.on('click', '[data-ll-wordset-next-remove]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            removeCurrentNextActivityWithTransition();
        });

        $(document).on('lltools:flashcard-results-shown.llWordsetPage', function (_evt, detail) {
            const summary = normalizeFlashcardResultSummary(detail);
            if (summary) {
                if (isPerfectFlashcardResult(summary)) {
                    markPerfectCelebrationPending();
                } else {
                    clearPerfectCelebrationPending();
                }
            }
            const mode = normalizeMode(summary && summary.mode ? summary.mode : (detail && detail.mode ? detail.mode : ''));
            if (renderChunkResultsActions()) {
                return;
            }
            renderStandardResultsActions(mode);
        });

        $(document).on('lltools:flashcard-word-outcome-queued.llWordsetPage', function (_evt, detail) {
            noteResultsFollowupPrefetchOutcome(detail);
        });

        $(document).on('lltools:flashcard-opened.llWordsetPage', function (_evt, detail) {
            isFlashcardOpen = true;
            pendingSummaryRefreshAfterClose = false;
            clearPerfectCelebrationPending();
            if (resultsFollowupPrefetchState) {
                const info = (detail && typeof detail === 'object') ? detail : {};
                const openedMode = normalizeMode(info.mode || '');
                if (openedMode && openedMode !== resultsFollowupPrefetchState.mode) {
                    resetResultsFollowupPrefetchState();
                }
            }
            hideChunkResultsActions();
        });

        $(document).on('lltools:flashcard-closed.llWordsetPage', function () {
            isFlashcardOpen = false;
            hideChunkResultsActions();
            if (selectedCategoryIds.length || $root.find('[data-ll-wordset-select]:checked').length) {
                clearCategorySelection({ refreshRecommendation: true });
            }
            if (pendingSummaryRefreshAfterClose) {
                pendingSummaryRefreshAfterClose = false;
                refreshSummaryCounts({
                    animate: true,
                    celebratePerfect: hasPendingPerfectCelebration(),
                    deferVisibleCategoryProgress: true,
                    stickyMiniWhenOffscreen: true
                });
            }
        });

        $(document).on('lltools:progress-updated.llWordsetPage', function (_evt, detail) {
            const info = detail || {};
            if (Object.prototype.hasOwnProperty.call(info, 'next_activity') || Object.prototype.hasOwnProperty.call(info, 'recommendation_queue')) {
                applyRecommendationPayload(info);
            } else {
                refreshRecommendation({ forceRefresh: true });
            }
            if (isFlashcardOpen) {
                pendingSummaryRefreshAfterClose = true;
                return;
            }
            refreshSummaryCounts({
                animate: true,
                celebratePerfect: hasPendingPerfectCelebration(),
                deferVisibleCategoryProgress: true
            });
        });
    }

    function bindHiddenPageInteractions() {
        $root.on('click', '[data-ll-wordset-unhide]', function (e) {
            e.preventDefault();
            unhideCategory($(this).attr('data-cat-id'));
        });
    }

    function bindSettingsPageInteractions() {
        syncSettingsButtons();
        syncGlobalPrefs();
        renderSettingsQueue();
        scheduleUniformQueueItemWidth();
        $(window).off('resize.llWordsetQueueWidth').on('resize.llWordsetQueueWidth', function () {
            scheduleUniformQueueItemWidth();
        });
    }

    function bindProgressPageInteractions() {
        if (!$progressRoot.length) { return; }
        const preferredTab = readProgressTabPreference();
        if (preferredTab === 'words' || preferredTab === 'categories') {
            analyticsTab = preferredTab;
        }
        renderProgressAnalytics();
        setProgressTab(analyticsTab);

        if ($progressWordSearchInput.length) {
            $progressWordSearchInput.val(analyticsWordSearchQuery);
        }
        if ($progressCategorySearchInput.length) {
            $progressCategorySearchInput.val(analyticsCategorySearchQuery);
        }

        $root.on('submit', '[data-ll-wordset-progress-reset-form]', function (evt) {
            const message = String($(this).attr('data-confirm') || '').trim();
            if (!message) {
                return;
            }
            if (!window.confirm(message)) {
                evt.preventDefault();
            }
        });

        $root.on('click', '[data-ll-wordset-progress-tab]', function () {
            const tab = String($(this).attr('data-ll-wordset-progress-tab') || '');
            setProgressTab(tab);
        });

        $root.on('click', '[data-ll-wordset-progress-kpi-filter]', function () {
            const $button = $(this);
            const key = normalizeSummaryFilter($(this).attr('data-ll-wordset-progress-kpi-filter'));
            if (!key) { return; }
            analyticsSummaryFilter = (analyticsSummaryFilter === key) ? '' : key;
            if (analyticsSummaryFilter) {
                analyticsWordCategoryFilterIds = [];
                Object.keys(analyticsWordColumnFilters).forEach(function (filterKey) {
                    analyticsWordColumnFilters[filterKey] = [];
                });
                renderProgressWordColumnFilterOptions();
                renderProgressCategoryFilterOptions();
            }
            clearProgressSelection();
            renderProgressSummary();
            renderProgressFilterTriggerStates();
            setProgressTab('words');
            triggerProgressKpiFeedback($button);
            triggerProgressWordFilterAnimation();
            scheduleProgressWordTableRender({ showLoading: false });
        });

        $root.on('input', '[data-ll-wordset-progress-search]', function () {
            analyticsWordSearchQuery = String($(this).val() || '');
            clearProgressSelection();
            scheduleProgressWordTableRender({ showLoading: true });
        });

        $root.on('input', '[data-ll-wordset-progress-category-search]', function () {
            analyticsCategorySearchQuery = String($(this).val() || '');
            scheduleProgressCategoryTableRender({ showLoading: true });
        });

        $root.on('click', '[data-ll-wordset-progress-filter-trigger]', function (evt) {
            evt.preventDefault();
            evt.stopPropagation();
            toggleProgressFilterPop($(this).attr('data-ll-wordset-progress-filter-trigger'));
        });

        $root.on('change', '[data-ll-wordset-progress-column-filter-check]', function () {
            const key = String($(this).attr('data-ll-wordset-progress-column-filter-check') || '');
            if (!Object.prototype.hasOwnProperty.call(analyticsWordColumnFilters, key)) {
                return;
            }
            const selected = [];
            $root.find('[data-ll-wordset-progress-column-filter-check="' + key + '"]:checked').each(function () {
                const value = String($(this).attr('data-ll-wordset-progress-filter-value') || '').trim();
                if (value) {
                    selected.push(value);
                }
            });
            analyticsWordColumnFilters[key] = normalizeFilterSelectionList(selected);
            clearProgressSelection();
            renderProgressFilterTriggerStates();
            scheduleProgressWordTableRender({ showLoading: false });
        });

        $root.on('change', '[data-ll-wordset-progress-category-filter-check]', function () {
            const selected = [];
            $root.find('[data-ll-wordset-progress-category-filter-check]:checked').each(function () {
                const cid = parseInt($(this).attr('data-ll-wordset-progress-category-filter-check'), 10) || 0;
                if (cid > 0) {
                    selected.push(cid);
                }
            });
            analyticsWordCategoryFilterIds = uniqueIntList(selected);
            clearProgressSelection();
            renderProgressFilterTriggerStates();
            scheduleProgressWordTableRender({ showLoading: false });
        });

        $root.on('click', '[data-ll-wordset-progress-clear-filters]', function (evt) {
            evt.preventDefault();
            closeProgressFilterPops('');
            analyticsSummaryFilter = '';
            analyticsWordCategoryFilterIds = [];
            Object.keys(analyticsWordColumnFilters).forEach(function (key) {
                analyticsWordColumnFilters[key] = [];
            });
            renderProgressSummary();
            renderProgressWordColumnFilterOptions();
            renderProgressCategoryFilterOptions();
            renderProgressFilterTriggerStates();
            clearProgressSelection();
            triggerProgressWordFilterAnimation();
            scheduleProgressWordTableRender({ showLoading: false });
        });

        $root.on('click', '[data-ll-wordset-progress-select-all]', function (evt) {
            evt.preventDefault();
            const visibleRows = buildProgressWordRowsForDisplay();
            const visibleWordIds = uniqueIntList(visibleRows.map(function (row) {
                return parseInt(row && row.id, 10) || 0;
            }));
            if (!visibleWordIds.length) {
                return;
            }
            const selectedIds = getProgressSelectedWordIds();
            const selectedLookup = {};
            selectedIds.forEach(function (id) {
                selectedLookup[id] = true;
            });
            const allVisibleSelected = visibleWordIds.every(function (id) {
                return !!selectedLookup[id];
            });
            if (allVisibleSelected) {
                const visibleLookup = {};
                visibleWordIds.forEach(function (id) {
                    visibleLookup[id] = true;
                });
                progressSelectedWordIds = selectedIds.filter(function (id) {
                    return !visibleLookup[id];
                });
            } else {
                progressSelectedWordIds = uniqueIntList(selectedIds.concat(visibleWordIds));
            }
            renderProgressWordTable();
        });

        $root.on('click', '[data-ll-wordset-progress-selection-clear]', function (evt) {
            evt.preventDefault();
            progressSelectedWordIds = [];
            renderProgressWordTable();
        });

        $root.on('click', '[data-ll-wordset-progress-selection-mode]', function (evt) {
            evt.preventDefault();
            const mode = normalizeMode($(this).attr('data-mode') || '');
            if (!mode || $(this).prop('disabled')) {
                return;
            }
            launchProgressSelectionMode(mode);
        });

        $root.on('click', '[data-ll-wordset-progress-sort]', function () {
            const key = String($(this).attr('data-ll-wordset-progress-sort') || '');
            if (['word', 'category', 'status', 'difficulty', 'seen', 'wrong', 'last'].indexOf(key) === -1) {
                return;
            }
            if (analyticsWordSort.key !== key) {
                analyticsWordSort.key = key;
                analyticsWordSort.direction = (key === 'difficulty' || key === 'seen' || key === 'wrong' || key === 'last') ? 'desc' : 'asc';
            } else {
                analyticsWordSort.direction = analyticsWordSort.direction === 'asc' ? 'desc' : 'asc';
            }
            renderProgressSortState();
            scheduleProgressWordTableRender({ showLoading: false });
        });

        $root.on('click', '[data-ll-wordset-progress-category-sort]', function () {
            const key = String($(this).attr('data-ll-wordset-progress-category-sort') || '');
            if (['category', 'progress', 'activity', 'last'].indexOf(key) === -1) {
                return;
            }
            if (analyticsCategorySort.key !== key) {
                analyticsCategorySort.key = key;
                analyticsCategorySort.direction = (key === 'category') ? 'asc' : 'desc';
            } else {
                analyticsCategorySort.direction = analyticsCategorySort.direction === 'asc' ? 'desc' : 'asc';
            }
            renderProgressCategorySortState();
            scheduleProgressCategoryTableRender({ showLoading: false });
        });

        $root.on('keydown', function (evt) {
            if ((evt && evt.key) === 'Escape') {
                closeProgressFilterPops('');
            }
        });

        $(window)
            .off('resize.llWordsetProgressFiltersPosition scroll.llWordsetProgressFiltersPosition')
            .on('resize.llWordsetProgressFiltersPosition scroll.llWordsetProgressFiltersPosition', function () {
                positionOpenProgressFilterPop();
            });

        if ($progressTableWraps.length) {
            $progressTableWraps
                .off('scroll.llWordsetProgressFiltersPosition')
                .on('scroll.llWordsetProgressFiltersPosition', function () {
                    positionOpenProgressFilterPop();
                });
        }

        $(document).on('click.llWordsetProgressFilters', function (evt) {
            const $target = $(evt.target);
            if ($target.closest('[data-ll-wordset-progress-filter-pop]').length) { return; }
            if ($target.closest('[data-ll-wordset-progress-filter-trigger]').length) { return; }
            closeProgressFilterPops('');
        });

        $root.on('click', '[data-ll-wordset-progress-word-star]', function () {
            const wordId = parseInt($(this).attr('data-ll-wordset-progress-word-star'), 10) || 0;
            if (!wordId) { return; }
            const starred = uniqueIntList(state.starred_word_ids || []);
            const idx = starred.indexOf(wordId);
            if (idx === -1) {
                starred.push(wordId);
            } else {
                starred.splice(idx, 1);
            }
            state.starred_word_ids = uniqueIntList(starred);
            pruneProgressSelectionWordIfHidden(wordId);
            syncGlobalPrefs();
            saveStateDebounced({ immediate: true });
            renderProgressAnalytics();
        });

        $(document).on('lltools:progress-updated.llWordsetProgress', function () {
            scheduleProgressAnalyticsRefresh(220, { silent: true });
        });

        const hasBootstrapAnalytics = (Array.isArray(analytics.words) && analytics.words.length > 0)
            || (Array.isArray(analytics.categories) && analytics.categories.length > 0);
        if (hasBootstrapAnalytics) {
            scheduleProgressAnalyticsRefresh(120, { silent: true });
        } else {
            refreshProgressAnalyticsNow();
        }
    }

    if (view === 'main') {
        setSummaryMetricsLoadingState(summaryMetricsLoading);
        syncAllWordsetCardProgressSegmentVisualState();
        bindSettingsControls();
        bindMainInteractions();
        refreshSummaryCounts({
            animate: false,
            deferVisibleCategoryProgress: false,
            syncAllCategoryProgressImmediately: true
        });
    }

    if (view === 'hidden-categories') {
        bindHiddenPageInteractions();
    }

    if (view === 'progress') {
        bindProgressPageInteractions();
    }

    if (view === 'settings') {
        bindSettingsControls();
        bindSettingsPageInteractions();
    }

    if (view === 'main') {
        refreshRecommendation({ forceRefresh: false });
    }

    if (view === 'settings') {
        refreshRecommendation({ forceRefresh: false });
    }
})(jQuery);
