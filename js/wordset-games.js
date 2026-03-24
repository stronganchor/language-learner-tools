(function (root, $) {
    'use strict';

    const api = root.LLWordsetGames = root.LLWordsetGames || {};
    const DEFAULT_GAME_SLUG = 'space-shooter';
    const BUBBLE_POP_GAME_SLUG = 'bubble-pop';
    const MODULE_NS = '.llWordsetGames';
    const GAME_PROMPT_RECORDING_TYPES = ['question', 'isolation', 'introduction'];
    const CARD_RATIO_MIN = 0.55;
    const CARD_RATIO_MAX = 2.5;
    const CARD_RATIO_DEFAULT = 1;
    const ASSET_PRELOAD_TIMEOUT_MS = 8000;
    const SHORT_PROMPT_AUTO_REPLAY_MIN_MS = 1400;
    const POST_SAFE_LINE_REPLAY_BUFFER_MS = 80;
    const INACTIVITY_ROUND_PAUSE_LIMIT = 3;
    const RESUME_ACTION_NEXT_PROMPT = 'next-prompt';
    const PAUSE_REASON_INACTIVITY = 'inactivity';

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function clamp(value, min, max) {
        const num = Number(value) || 0;
        return Math.max(min, Math.min(max, num));
    }

    function randomBetween(min, max) {
        const start = Number(min) || 0;
        const end = Number(max);
        if (!isFinite(end) || end <= start) {
            return start;
        }
        return start + (Math.random() * (end - start));
    }

    function lerp(start, end, progress) {
        return Number(start || 0) + ((Number(end || 0) - Number(start || 0)) * clamp(progress, 0, 1));
    }

    function uniqueIntList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [])
            .map(toInt)
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function normalizeRecordingType(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
    }

    function normalizeGameSlug(value) {
        const slug = normalizeRecordingType(value);
        return slug || DEFAULT_GAME_SLUG;
    }

    function uniqueStringList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [])
            .map(function (value) {
                return normalizeRecordingType(value);
            })
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function normalizeUrlList(values) {
        const seen = {};
        return (Array.isArray(values) ? values : [values])
            .map(function (value) {
                return String(value || '').trim();
            })
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }
                seen[value] = true;
                return true;
            });
    }

    function formatMessage(template, values) {
        const source = String(template || '');
        const args = Array.isArray(values) ? values.slice() : [];
        let sequential = 0;
        return source
            .replace(/%(\d+)\$[sd]/g, function (_match, rawIndex) {
                const index = Math.max(0, parseInt(rawIndex, 10) - 1);
                return (typeof args[index] !== 'undefined') ? String(args[index]) : '';
            })
            .replace(/%[sd]/g, function () {
                const value = (typeof args[sequential] !== 'undefined') ? args[sequential] : '';
                sequential += 1;
                return String(value);
            });
    }

    function clampAspectRatio(value) {
        const num = Number(value);
        if (!num || !isFinite(num) || num <= 0) {
            return null;
        }
        return Math.min(CARD_RATIO_MAX, Math.max(CARD_RATIO_MIN, num));
    }

    function easeOutCubic(progress) {
        const clamped = clamp(progress, 0, 1);
        return 1 - Math.pow(1 - clamped, 3);
    }

    function shuffle(list) {
        const copy = Array.isArray(list) ? list.slice() : [];
        for (let index = copy.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            const temp = copy[index];
            copy[index] = copy[swapIndex];
            copy[swapIndex] = temp;
        }
        return copy;
    }

    function getWordCategoryKey(word) {
        const categoryId = toInt(word && word.category_id)
            || toInt(Array.isArray(word && word.category_ids) ? word.category_ids[0] : 0);
        return categoryId > 0 ? String(categoryId) : 'default';
    }

    function getWordCategoryIds(word) {
        const categoryIds = uniqueIntList(word && word.category_ids);
        const primaryCategoryId = toInt(word && word.category_id);
        if (primaryCategoryId > 0 && categoryIds.indexOf(primaryCategoryId) === -1) {
            categoryIds.unshift(primaryCategoryId);
        }
        return categoryIds;
    }

    function limitLaunchWords(words, wordCap) {
        const list = Array.isArray(words) ? words.slice() : [];
        const cap = Math.max(1, toInt(wordCap) || list.length || 1);
        if (list.length <= cap) {
            return list;
        }

        const groups = {};
        const groupOrder = [];
        list.forEach(function (word) {
            const key = getWordCategoryKey(word);
            if (!groups[key]) {
                groups[key] = [];
                groupOrder.push(key);
            }
            groups[key].push(word);
        });

        const selected = [];
        const selectedIds = {};
        while (selected.length < cap) {
            let added = false;
            groupOrder.forEach(function (key) {
                if (selected.length >= cap || !groups[key] || !groups[key].length) {
                    return;
                }

                const word = groups[key].shift();
                const wordId = toInt(word && word.id);
                if (wordId && selectedIds[wordId]) {
                    return;
                }

                selected.push(word);
                if (wordId) {
                    selectedIds[wordId] = true;
                }
                added = true;
            });

            if (!added) {
                break;
            }
        }

        if (selected.length >= cap) {
            return selected.slice(0, cap);
        }

        list.forEach(function (word) {
            if (selected.length >= cap) {
                return;
            }
            const wordId = toInt(word && word.id);
            if (wordId && selectedIds[wordId]) {
                return;
            }
            selected.push(word);
            if (wordId) {
                selectedIds[wordId] = true;
            }
        });

        return selected.slice(0, cap);
    }

    function getOptionConflicts() {
        return (root.LLToolsOptionConflicts && typeof root.LLToolsOptionConflicts === 'object')
            ? root.LLToolsOptionConflicts
            : {};
    }

    function wordsShareSimilarLink(leftWord, rightWord) {
        const leftId = toInt(leftWord && leftWord.id);
        const rightId = toInt(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) {
            return false;
        }
        return String(leftWord && leftWord.similar_word_id) === String(rightId)
            || String(rightWord && rightWord.similar_word_id) === String(leftId);
    }

    function wordsCanShareRound(leftWord, rightWord) {
        const helper = getOptionConflicts();
        const hasConflict = (typeof helper.wordsConflictForOptions === 'function')
            ? helper.wordsConflictForOptions(leftWord, rightWord)
            : false;
        if (hasConflict) {
            return false;
        }
        return !wordsShareSimilarLink(leftWord, rightWord);
    }

    function wordsShareCategory(leftWord, rightWord) {
        const leftCategories = getWordCategoryIds(leftWord);
        const rightCategories = getWordCategoryIds(rightWord);
        if (!leftCategories.length || !rightCategories.length) {
            return false;
        }

        return leftCategories.some(function (categoryId) {
            return rightCategories.indexOf(categoryId) !== -1;
        });
    }

    function normalizeAudioFiles(word) {
        return (Array.isArray(word && word.audio_files) ? word.audio_files : [])
            .filter(function (file) {
                return file && typeof file === 'object' && String(file.url || '').trim() !== '';
            })
            .map(function (file) {
                return {
                    url: String(file.url || ''),
                    recording_type: normalizeRecordingType(file.recording_type || ''),
                    speaker_user_id: toInt(file.speaker_user_id),
                    recording_text: String(file.recording_text || '')
                };
            });
    }

    function normalizeWord(rawWord) {
        const word = (rawWord && typeof rawWord === 'object') ? rawWord : {};
        const categoryIds = uniqueIntList(word.category_ids || []);
        const categoryId = toInt(word.category_id) || (categoryIds.length ? categoryIds[0] : 0);
        return {
            id: toInt(word.id),
            title: String(word.title || ''),
            label: String(word.label || word.title || ''),
            prompt_label: String(word.prompt_label || word.title || ''),
            translation: String(word.translation || ''),
            image: String(word.image || ''),
            audio: String(word.audio || ''),
            audio_files: normalizeAudioFiles(word),
            practice_recording_types: Array.isArray(word.practice_recording_types)
                ? uniqueStringList(word.practice_recording_types)
                : [],
            preferred_speaker_user_id: toInt(word.preferred_speaker_user_id),
            option_blocked_ids: uniqueIntList(word.option_blocked_ids || []),
            category_id: categoryId,
            category_ids: categoryIds,
            category_name: String(word.category_name || ''),
            category_names: Array.isArray(word.category_names)
                ? word.category_names.map(function (entry) { return String(entry || ''); }).filter(Boolean)
                : [],
            similar_word_id: String(word.similar_word_id || ''),
            practice_correct_recording_types: Array.isArray(word.practice_correct_recording_types)
                ? uniqueStringList(word.practice_correct_recording_types)
                : [],
            practice_exposure_count: toInt(word.practice_exposure_count),
            game_prompt_recording_types: Array.isArray(word.game_prompt_recording_types)
                ? uniqueStringList(word.game_prompt_recording_types)
                : []
        };
    }

    function getDefaultCatalogSlug(ctx) {
        if (ctx && ctx.defaultCatalogSlug) {
            return normalizeGameSlug(ctx.defaultCatalogSlug);
        }
        return DEFAULT_GAME_SLUG;
    }

    function getGameConfig(ctx, slugOrRun) {
        const requestedSlug = normalizeGameSlug(
            slugOrRun && typeof slugOrRun === 'object'
                ? slugOrRun.slug
                : slugOrRun
        );
        const gameConfigs = (ctx && ctx.gameConfigs && typeof ctx.gameConfigs === 'object')
            ? ctx.gameConfigs
            : {};
        if (gameConfigs[requestedSlug]) {
            return gameConfigs[requestedSlug];
        }

        const fallbackSlug = getDefaultCatalogSlug(ctx);
        if (gameConfigs[fallbackSlug]) {
            return gameConfigs[fallbackSlug];
        }

        return null;
    }

    function isBubblePopRun(ctx, run) {
        return normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG;
    }

    function isSpaceShooterRun(ctx, run) {
        return !isBubblePopRun(ctx, run);
    }

    function getAssetPreloadTimeoutMs(ctx, runOrSlug) {
        const gameConfig = getGameConfig(ctx, runOrSlug);
        return Math.max(1000, toInt(gameConfig && gameConfig.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS);
    }

    function getCurrentGameSlug(ctx) {
        if (ctx && ctx.run) {
            return normalizeGameSlug(ctx.run.slug);
        }
        if (ctx && ctx.activeGameSlug) {
            return normalizeGameSlug(ctx.activeGameSlug);
        }
        return getDefaultCatalogSlug(ctx);
    }

    function getBoardLabel(ctx, slugOrRun) {
        const requestedSlug = normalizeGameSlug(
            slugOrRun && typeof slugOrRun === 'object'
                ? slugOrRun.slug
                : slugOrRun
        );
        if (requestedSlug === BUBBLE_POP_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelBubblePop || 'Bubble Pop game board');
        }
        if (requestedSlug === DEFAULT_GAME_SLUG) {
            return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelSpaceShooter || 'Arcane Space Shooter game board');
        }
        return String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelDefault || 'Wordset game board');
    }

    function getGamePromptRecordingTypes(word) {
        const explicit = uniqueStringList(word && word.game_prompt_recording_types);
        const availableAudioTypes = uniqueStringList((Array.isArray(word && word.audio_files) ? word.audio_files : []).map(function (file) {
            return file && file.recording_type;
        }));
        const practiceTypes = uniqueStringList(word && word.practice_recording_types);
        const source = explicit.length ? explicit : practiceTypes.concat(availableAudioTypes);

        return GAME_PROMPT_RECORDING_TYPES.filter(function (type) {
            return source.indexOf(type) !== -1 && availableAudioTypes.indexOf(type) !== -1;
        });
    }

    function selectPromptAudio(word, preferredTypes) {
        const preferred = uniqueStringList(preferredTypes);
        const availableTypes = getGamePromptRecordingTypes(word);
        const orderedTypes = preferred.concat(availableTypes, GAME_PROMPT_RECORDING_TYPES).filter(function (type, index, list) {
            return type && list.indexOf(type) === index;
        });

        if (!availableTypes.length) {
            return {
                url: '',
                recordingType: ''
            };
        }

        if (root.FlashcardAudio && typeof root.FlashcardAudio.selectBestAudio === 'function') {
            for (let index = 0; index < orderedTypes.length; index += 1) {
                const type = orderedTypes[index];
                const selected = root.FlashcardAudio.selectBestAudio(word, [type]);
                if (selected) {
                    return {
                        url: String(selected),
                        recordingType: type
                    };
                }
            }
        }

        const files = Array.isArray(word && word.audio_files) ? word.audio_files : [];
        for (let index = 0; index < orderedTypes.length; index += 1) {
            const type = orderedTypes[index];
            for (let fileIndex = 0; fileIndex < files.length; fileIndex += 1) {
                const entry = files[fileIndex] || {};
                if (normalizeRecordingType(entry.recording_type || '') === type && entry.url) {
                    return {
                        url: String(entry.url),
                        recordingType: type
                    };
                }
            }
        }

            return {
                url: String(word && word.audio || ''),
                recordingType: orderedTypes[0] || ''
            };
    }

    function buildStageMetrics(run) {
        const width = Math.max(280, run.width || 720);
        const height = Math.max(360, run.height || 720);
        const laneCount = run.cardCount || 4;
        const cardWidth = clamp(width * 0.18, 84, 126);
        const cardHeight = clamp(cardWidth * 1.28, 112, 172);
        const laneWidth = width / laneCount;
        const shipWidth = clamp(width * 0.14, 54, 90);
        const shipHeight = shipWidth * 0.58;
        return {
            width: width,
            height: height,
            laneCount: laneCount,
            laneWidth: laneWidth,
            cardWidth: cardWidth,
            cardHeight: cardHeight,
            shipWidth: shipWidth,
            shipHeight: shipHeight,
            shipY: height - Math.max(44, shipHeight * 0.8),
            bulletSpeed: clamp(height * 1.2, 640, 1020),
            shipSpeed: clamp(width * 0.78, 320, 580)
        };
    }

    function laneCenterX(run, laneIndex) {
        const metrics = run.metrics;
        return (metrics.laneWidth * laneIndex) + (metrics.laneWidth / 2);
    }

    function createStageStars(run) {
        const stars = [];
        for (let index = 0; index < 46; index += 1) {
            stars.push({
                x: Math.random() * run.width,
                y: Math.random() * run.height,
                radius: (Math.random() * 1.7) + 0.4,
                alpha: (Math.random() * 0.45) + 0.18
            });
        }
        return stars;
    }

    function scaleBubbleRunLayout(run, widthRatio, heightRatio) {
        if (!run || !isFinite(widthRatio) || !isFinite(heightRatio)) {
            return;
        }

        const decorativeBubbles = Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles : [];
        if (!Array.isArray(run.decorativeBubbles)) {
            run.decorativeBubbles = decorativeBubbles;
        }

        run.cards.forEach(function (card) {
            [
                'bubbleVisibleX',
                'bubbleBaseX',
                'entryVisibleX',
                'entryStartX',
                'x'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * widthRatio;
                }
            });
            [
                'bubbleVisibleY',
                'bubbleBaseY',
                'entryVisibleY',
                'entryStartY',
                'y'
            ].forEach(function (key) {
                if (isFinite(Number(card && card[key]))) {
                    card[key] = Number(card[key]) * heightRatio;
                }
            });
        });

        decorativeBubbles.forEach(function (bubble) {
            [
                'baseX',
                'x'
            ].forEach(function (key) {
                if (isFinite(Number(bubble && bubble[key]))) {
                    bubble[key] = Number(bubble[key]) * widthRatio;
                }
            });
            [
                'baseY',
                'y'
            ].forEach(function (key) {
                if (isFinite(Number(bubble && bubble[key]))) {
                    bubble[key] = Number(bubble[key]) * heightRatio;
                }
            });
        });
    }

    function syncCanvasSize(ctx) {
        if (!ctx || !ctx.canvas || !ctx.canvas.getContext) {
            return;
        }

        const run = ctx.run;
        const previousWidth = run ? Math.max(1, Number(run.width) || 1) : 1;
        const previousHeight = run ? Math.max(1, Number(run.height) || 1) : 1;
        const wrapWidth = Math.max(280, Math.round(ctx.$canvasWrap.innerWidth() || ctx.$stage.innerWidth() || 720));
        const viewportCap = root.innerHeight ? Math.round(root.innerHeight * 0.68) : 820;
        const cssHeight = clamp(Math.round(wrapWidth * 1.18), 430, Math.max(430, viewportCap));
        const dpr = clamp(root.devicePixelRatio || 1, 1, 2);

        ctx.canvas.width = Math.round(wrapWidth * dpr);
        ctx.canvas.height = Math.round(cssHeight * dpr);
        ctx.canvas.style.width = wrapWidth + 'px';
        ctx.canvas.style.height = cssHeight + 'px';

        ctx.canvasContext = ctx.canvas.getContext('2d');
        if (!ctx.canvasContext) {
            return;
        }
        ctx.canvasContext.setTransform(dpr, 0, 0, dpr, 0, 0);

        if (!run) {
            return;
        }

        run.width = wrapWidth;
        run.height = cssHeight;
        run.dpr = dpr;
        run.metrics = buildStageMetrics(run);
        run.shipY = run.metrics.shipY;
        run.shipX = clamp(run.shipX || (run.width / 2), run.metrics.shipWidth / 2, run.width - (run.metrics.shipWidth / 2));
        run.stars = createStageStars(run);

        run.cards.forEach(function (card) {
            applyCardDimensions(ctx, run, card);
        });
        if (isBubblePopRun(ctx, run)) {
            scaleBubbleRunLayout(run, run.width / previousWidth, run.height / previousHeight);
            seedDecorativeBubbles(run);
            refreshBubblePromptCardPositions(run, currentTimestamp());
        }
        run.bullets.forEach(function (bullet) {
            bullet.x = clamp(bullet.x, 0, run.width);
            bullet.y = clamp(bullet.y, -20, run.height + 20);
        });
        run.explosions.forEach(function (explosion) {
            explosion.x = clamp(explosion.x, 0, run.width);
            explosion.y = clamp(explosion.y, 0, run.height);
        });
    }

    function getTracker() {
        return (root.LLFlashcards && root.LLFlashcards.ProgressTracker && typeof root.LLFlashcards.ProgressTracker.setContext === 'function')
            ? root.LLFlashcards.ProgressTracker
            : null;
    }

    function setTrackerContext(ctx) {
        const tracker = getTracker();
        if (!tracker) {
            return null;
        }

        tracker.setContext({
            mode: 'practice',
            wordsetId: ctx.wordsetId,
            categoryIds: ctx.visibleCategoryIds
        });
        return tracker;
    }

    function buildProgressPayload(ctx, prompt, extra) {
        const word = prompt && prompt.target ? prompt.target : null;
        return $.extend({}, extra || {}, {
            recording_type: normalizeRecordingType(prompt && prompt.recordingType || ''),
            available_recording_types: Array.isArray(word && word.practice_recording_types)
                ? word.practice_recording_types.slice()
                : [],
            game_slug: normalizeGameSlug(prompt && prompt.gameSlug || getCurrentGameSlug(ctx))
        });
    }

    function queueExposureOnce(ctx, prompt, extraPayload) {
        const tracker = setTrackerContext(ctx);
        if (!tracker || !prompt || prompt.exposureTracked || !prompt.target) {
            return null;
        }

        prompt.exposureTracked = true;
        return tracker.trackWordExposure({
            mode: 'practice',
            word: prompt.target,
            wordsetId: ctx.wordsetId,
            categoryId: prompt.target.category_id,
            categoryName: prompt.target.category_name,
            payload: buildProgressPayload(ctx, prompt, extraPayload || {})
        });
    }

    function queueOutcome(ctx, prompt, isCorrect, hadWrongBefore, extraPayload) {
        const tracker = setTrackerContext(ctx);
        if (!tracker || !prompt || !prompt.target) {
            return null;
        }

        return tracker.trackWordOutcome({
            mode: 'practice',
            word: prompt.target,
            wordsetId: ctx.wordsetId,
            categoryId: prompt.target.category_id,
            categoryName: prompt.target.category_name,
            isCorrect: !!isCorrect,
            hadWrongBefore: !!hadWrongBefore,
            payload: buildProgressPayload(ctx, prompt, extraPayload || {})
        });
    }

    function flushProgress(ctx) {
        const tracker = getTracker();
        if (!tracker || typeof tracker.flush !== 'function') {
            return null;
        }
        return tracker.flush({ immediate: true });
    }

    function updateProgressGlobals(ctx) {
        root.llToolsStudyData = $.extend({}, root.llToolsStudyData || {}, {
            ajaxUrl: ctx.ajaxUrl,
            nonce: ctx.nonce,
            isLoggedIn: ctx.isLoggedIn
        });
    }

    function drawRoundedRect(context, x, y, width, height, radius) {
        const r = Math.max(4, Math.min(radius, width / 2, height / 2));
        context.beginPath();
        context.moveTo(x + r, y);
        context.lineTo(x + width - r, y);
        context.quadraticCurveTo(x + width, y, x + width, y + r);
        context.lineTo(x + width, y + height - r);
        context.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
        context.lineTo(x + r, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - r);
        context.lineTo(x, y + r);
        context.quadraticCurveTo(x, y, x + r, y);
        context.closePath();
    }

    function getCardRadius(card) {
        return Math.max(10, Math.min(18, Math.min(card.width, card.height) * 0.18));
    }

    function getCardAmbientMotion(card, now) {
        const motionTime = Math.max(0, Number(now) || 0) / 1000;
        const revealMs = Math.max(0, toInt(card && card.entryRevealMs));
        const revealProgress = revealMs > 0
            ? clamp((Math.max(0, Number(now) - Number(card.entryStartedAt || 0))) / revealMs, 0, 1)
            : 1;
        const motionStrength = 0.3 + (0.7 * revealProgress);
        const bobPrimary = Math.sin((motionTime * Number(card && card.motionBobHzPrimary || 0.18) * Math.PI * 2) + Number(card && card.motionBobPhasePrimary || 0))
            * Number(card && card.motionBobAmplitudePrimary || 0);
        const bobSecondary = Math.sin((motionTime * Number(card && card.motionBobHzSecondary || 0.27) * Math.PI * 2) + Number(card && card.motionBobPhaseSecondary || 0))
            * Number(card && card.motionBobAmplitudeSecondary || 0);
        const tiltPrimary = Math.sin((motionTime * Number(card && card.motionTiltHzPrimary || 0.12) * Math.PI * 2) + Number(card && card.motionTiltPhasePrimary || 0))
            * Number(card && card.motionTiltAmplitudePrimary || 0);
        const tiltSecondary = Math.sin((motionTime * Number(card && card.motionTiltHzSecondary || 0.19) * Math.PI * 2) + Number(card && card.motionTiltPhaseSecondary || 0))
            * Number(card && card.motionTiltAmplitudeSecondary || 0);

        return {
            bobY: (bobPrimary + bobSecondary) * motionStrength,
            tilt: (tiltPrimary + tiltSecondary) * motionStrength
        };
    }

    function getCardDimensions(run, aspectRatio) {
        const ratio = clampAspectRatio(aspectRatio) || CARD_RATIO_DEFAULT;
        const maxWidth = Math.max(1, Number(run && run.metrics && run.metrics.cardWidth) || 1);
        const maxHeight = Math.max(1, Number(run && run.metrics && run.metrics.cardHeight) || 1);

        let width = maxWidth;
        let height = width / ratio;
        if (height > maxHeight) {
            height = maxHeight;
            width = height * ratio;
        }

        return {
            width: Math.round(width * 100) / 100,
            height: Math.round(height * 100) / 100,
            aspectRatio: ratio
        };
    }

    function getWordImageAspectRatio(ctx, word) {
        const image = loadWordImage(ctx, word);
        if (!image || !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
            return null;
        }
        return clampAspectRatio(image.naturalWidth / image.naturalHeight);
    }

    function applyCardDimensions(ctx, run, card) {
        if (!run || !card) {
            return;
        }

        const dimensions = getCardDimensions(run, getWordImageAspectRatio(ctx, card.word));
        card.width = dimensions.width;
        card.height = dimensions.height;
        card.aspectRatio = dimensions.aspectRatio;
        if (isBubblePopRun(ctx, run)) {
            if (isFinite(Number(card.entryStartX))) {
                card.x = Number(card.entryStartX);
            } else if (isFinite(Number(card.bubbleVisibleX))) {
                card.x = Number(card.bubbleVisibleX);
            }
            return;
        }
        card.x = laneCenterX(run, card.laneIndex);
    }

    function getImageResource(ctx, word) {
        const url = String(word && word.image || '');
        if (!url) {
            return null;
        }
        if (ctx.imageCache[url]) {
            return ctx.imageCache[url];
        }

        const image = new Image();
        const resource = {
            image: image,
            status: 'loading',
            promise: null
        };

        image.decoding = 'async';
        resource.promise = new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                image.removeEventListener('load', onLoad);
                image.removeEventListener('error', onError);
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
            };

            const finish = function (isLoaded) {
                if (settled) {
                    return;
                }
                settled = true;
                resource.status = isLoaded ? 'loaded' : 'error';
                cleanup();
                resolve(!!isLoaded);
            };

            const onLoad = function () {
                const run = ctx && ctx.run;
                if (run && Array.isArray(run.cards)) {
                    run.cards.forEach(function (card) {
                        if (toInt(card && card.word && card.word.id) === toInt(word && word.id)) {
                            applyCardDimensions(ctx, run, card);
                        }
                    });
                }
                finish(image.naturalWidth > 0 && image.naturalHeight > 0);
            };

            const onError = function () {
                finish(false);
            };

            image.addEventListener('load', onLoad);
            image.addEventListener('error', onError);
            timeoutId = root.setTimeout(function () {
                finish(false);
            }, getAssetPreloadTimeoutMs(ctx));

            try {
                image.src = url;
                if (image.complete) {
                    onLoad();
                }
            } catch (_) {
                onError();
            }
        });

        ctx.imageCache[url] = resource;
        return resource;
    }

    function loadWordImage(ctx, word) {
        const resource = getImageResource(ctx, word);
        return resource ? resource.image : null;
    }

    function ensureWordImageLoaded(ctx, word) {
        const resource = getImageResource(ctx, word);
        return resource ? resource.promise : Promise.resolve(false);
    }

    function getAudioPreloadResource(ctx, url) {
        const source = String(url || '');
        if (!source) {
            return null;
        }
        if (ctx.audioPreloadCache[source]) {
            return ctx.audioPreloadCache[source];
        }

        const audio = new Audio();
        const resource = {
            audio: audio,
            status: 'loading',
            promise: null
        };

        audio.preload = 'auto';
        resource.promise = new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                audio.removeEventListener('canplaythrough', onReady);
                audio.removeEventListener('canplay', onReady);
                audio.removeEventListener('loadeddata', onReady);
                audio.removeEventListener('error', onError);
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
            };

            const finish = function (isLoaded) {
                if (settled) {
                    return;
                }
                settled = true;
                resource.status = isLoaded ? 'loaded' : 'error';
                cleanup();
                resolve(!!isLoaded);
            };

            const onReady = function () {
                finish(true);
            };

            const onError = function () {
                finish(false);
            };

            audio.addEventListener('canplaythrough', onReady);
            audio.addEventListener('canplay', onReady);
            audio.addEventListener('loadeddata', onReady);
            audio.addEventListener('error', onError);
            timeoutId = root.setTimeout(function () {
                finish(false);
            }, getAssetPreloadTimeoutMs(ctx));

            try {
                audio.src = source;
                if (audio.readyState >= 2) {
                    onReady();
                    return;
                }
                if (typeof audio.load === 'function') {
                    audio.load();
                }
            } catch (_) {
                onError();
            }
        });

        ctx.audioPreloadCache[source] = resource;
        return resource;
    }

    function ensureAudioLoaded(ctx, url) {
        const resource = getAudioPreloadResource(ctx, url);
        return resource ? resource.promise : Promise.resolve(false);
    }

    function getLoadedAudioDurationMs(ctx, url) {
        const resource = getAudioPreloadResource(ctx, url);
        const durationSeconds = Number(resource && resource.audio && resource.audio.duration);
        if (!durationSeconds || !isFinite(durationSeconds) || durationSeconds <= 0) {
            return 0;
        }
        return durationSeconds * 1000;
    }

    function renderBackground(ctx, run) {
        const context = ctx.canvasContext;
        context.clearRect(0, 0, run.width, run.height);

        if (isBubblePopRun(ctx, run)) {
            const bubbleGradient = context.createLinearGradient(0, 0, 0, run.height);
            bubbleGradient.addColorStop(0, '#A9E7FF');
            bubbleGradient.addColorStop(0.46, '#8AD8FC');
            bubbleGradient.addColorStop(1, '#6ABBE9');
            context.fillStyle = bubbleGradient;
            context.fillRect(0, 0, run.width, run.height);

            const glow = context.createRadialGradient(run.width * 0.36, run.height * 0.12, 0, run.width * 0.36, run.height * 0.12, run.width * 0.58);
            glow.addColorStop(0, 'rgba(255, 255, 255, 0.34)');
            glow.addColorStop(1, 'rgba(255, 255, 255, 0)');
            context.fillStyle = glow;
            context.fillRect(0, 0, run.width, run.height);

            const lowerGlow = context.createRadialGradient(run.width * 0.78, run.height * 0.82, 0, run.width * 0.78, run.height * 0.82, run.width * 0.48);
            lowerGlow.addColorStop(0, 'rgba(225, 245, 255, 0.24)');
            lowerGlow.addColorStop(1, 'rgba(225, 245, 255, 0)');
            context.fillStyle = lowerGlow;
            context.fillRect(0, 0, run.width, run.height);

            run.stars.forEach(function (star, index) {
                const radius = star.radius * (index % 3 === 0 ? 4.8 : 3.2);
                context.globalAlpha = Math.min(0.24, star.alpha * 0.48);
                context.fillStyle = index % 2 === 0 ? '#F8FDFF' : '#D7F0FF';
                context.beginPath();
                context.arc(star.x, star.y, radius, 0, Math.PI * 2);
                context.fill();
            });
            context.globalAlpha = 1;
            return;
        }

        const gradient = context.createLinearGradient(0, 0, 0, run.height);
        gradient.addColorStop(0, '#0F172A');
        gradient.addColorStop(0.48, '#132848');
        gradient.addColorStop(1, '#181B2B');
        context.fillStyle = gradient;
        context.fillRect(0, 0, run.width, run.height);

        const glow = context.createRadialGradient(run.width * 0.5, 36, 0, run.width * 0.5, 36, run.width * 0.45);
        glow.addColorStop(0, 'rgba(56, 189, 248, 0.18)');
        glow.addColorStop(1, 'rgba(56, 189, 248, 0)');
        context.fillStyle = glow;
        context.fillRect(0, 0, run.width, run.height);

        run.stars.forEach(function (star) {
            context.globalAlpha = star.alpha;
            context.fillStyle = '#F8FAFC';
            context.beginPath();
            context.arc(star.x, star.y, star.radius, 0, Math.PI * 2);
            context.fill();
        });
        context.globalAlpha = 1;

        context.strokeStyle = 'rgba(255,255,255,0.06)';
        context.lineWidth = 1;
        for (let laneIndex = 1; laneIndex < run.cardCount; laneIndex += 1) {
            const x = laneCenterX(run, laneIndex) - (run.metrics.laneWidth / 2);
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, run.height);
            context.stroke();
        }
    }

    function renderShip(ctx, run) {
        if (!isSpaceShooterRun(ctx, run)) {
            return;
        }
        const context = ctx.canvasContext;
        const width = run.metrics.shipWidth;
        const height = run.metrics.shipHeight;
        const x = run.shipX;
        const y = run.metrics.shipY;

        context.save();
        context.translate(x, y);
        context.fillStyle = '#E2E8F0';
        context.beginPath();
        context.moveTo(0, -height);
        context.lineTo(width * 0.48, height * 0.72);
        context.lineTo(0, height * 0.3);
        context.lineTo(-width * 0.48, height * 0.72);
        context.closePath();
        context.fill();

        context.fillStyle = '#38BDF8';
        context.beginPath();
        context.moveTo(0, -height * 0.6);
        context.lineTo(width * 0.18, height * 0.34);
        context.lineTo(-width * 0.18, height * 0.34);
        context.closePath();
        context.fill();

        context.fillStyle = 'rgba(15, 118, 110, 0.92)';
        context.beginPath();
        context.arc(0, height * 0.38, width * 0.14, 0, Math.PI, false);
        context.fill();
        context.restore();
    }

    function renderBullets(ctx, run) {
        if (!isSpaceShooterRun(ctx, run)) {
            return;
        }
        const context = ctx.canvasContext;
        context.fillStyle = '#67E8F9';
        run.bullets.forEach(function (bullet) {
            context.beginPath();
            context.arc(bullet.x, bullet.y, bullet.radius, 0, Math.PI * 2);
            context.fill();
        });
    }

    function drawImageContain(context, image, x, y, width, height) {
        if (!image || !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
            return false;
        }

        const containScale = Math.min(
            width / Math.max(1, image.naturalWidth),
            height / Math.max(1, image.naturalHeight)
        );
        const drawWidth = image.naturalWidth * containScale;
        const drawHeight = image.naturalHeight * containScale;
        const drawX = x + ((width - drawWidth) / 2);
        const drawY = y + ((height - drawHeight) / 2);

        context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
        return true;
    }

    function getBubbleRadius(card) {
        return Math.max(28, Math.min(card.width, card.height) * 0.5);
    }

    function getFloatingBodyRadius(body) {
        const explicitRadius = Number(body && body.radius);
        if (isFinite(explicitRadius) && explicitRadius > 0) {
            return explicitRadius;
        }
        return getBubbleRadius(body);
    }

    function getBubbleMovementBounds(run, radius) {
        const bubbleRadius = Math.max(10, Number(radius) || 10);
        const sidePadding = Math.max(20, run.width * 0.04);
        const topPadding = Math.max(12, run.height * 0.02);
        const bottomPadding = Math.max(72, run.height * 0.08);
        return {
            minX: bubbleRadius + sidePadding,
            maxX: run.width - bubbleRadius - sidePadding,
            minY: bubbleRadius + topPadding,
            maxY: run.height - bubbleRadius - bottomPadding
        };
    }

    function setFloatingBodyPosition(run, body, x, y, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const bounds = getBubbleMovementBounds(run, getFloatingBodyRadius(body));
        body.x = clamp(Number(x) || 0, bounds.minX, bounds.maxX);
        body.y = opts.clampY === false
            ? (Number(y) || 0)
            : clamp(Number(y) || 0, bounds.minY, bounds.maxY);
    }

    function getBubbleFloatOffsets(card, now, strength) {
        const time = Math.max(0, Number(now) || 0) / 1000;
        const scale = isFinite(Number(strength)) ? Number(strength) : 1;
        return {
            x: (
                Math.sin((time * Number(card && card.bubbleFloatHzXPrimary || 0.14) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseXPrimary || 0))
                    * Number(card && card.bubbleFloatAmplitudeXPrimary || 0)
            ) + (
                Math.sin((time * Number(card && card.bubbleFloatHzXSecondary || 0.26) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseXSecondary || 0))
                    * Number(card && card.bubbleFloatAmplitudeXSecondary || 0)
            ) * scale,
            y: (
                Math.sin((time * Number(card && card.bubbleFloatHzYPrimary || 0.18) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseYPrimary || 0))
                    * Number(card && card.bubbleFloatAmplitudeYPrimary || 0)
            ) + (
                Math.sin((time * Number(card && card.bubbleFloatHzYSecondary || 0.31) * Math.PI * 2) + Number(card && card.bubbleFloatPhaseYSecondary || 0))
                    * Number(card && card.bubbleFloatAmplitudeYSecondary || 0)
            ) * scale
        };
    }

    function resolveFloatingBubbleOverlaps(run, bodies, gap) {
        const list = Array.isArray(bodies) ? bodies.filter(Boolean) : [];
        const padding = Math.max(0, Number(gap) || 0);
        for (let pass = 0; pass < 3; pass += 1) {
            let moved = false;
            for (let leftIndex = 0; leftIndex < list.length; leftIndex += 1) {
                for (let rightIndex = leftIndex + 1; rightIndex < list.length; rightIndex += 1) {
                    const left = list[leftIndex];
                    const right = list[rightIndex];
                    const dx = Number(right.x) - Number(left.x);
                    const dy = Number(right.y) - Number(left.y);
                    const distance = Math.sqrt((dx * dx) + (dy * dy)) || 0.001;
                    const minimumDistance = getFloatingBodyRadius(left) + getFloatingBodyRadius(right) + padding;
                    if (distance >= minimumDistance) {
                        continue;
                    }

                    const overlap = minimumDistance - distance;
                    const normalX = dx / distance;
                    const normalY = dy / distance;
                    setFloatingBodyPosition(run, left, Number(left.x) - (normalX * overlap * 0.5), Number(left.y) - (normalY * overlap * 0.5), {
                        clampY: false
                    });
                    setFloatingBodyPosition(run, right, Number(right.x) + (normalX * overlap * 0.5), Number(right.y) + (normalY * overlap * 0.5), {
                        clampY: false
                    });
                    moved = true;
                }
            }
            if (!moved) {
                break;
            }
        }
    }

    function placeBubblePromptCards(run, cards) {
        const promptCards = Array.isArray(cards) ? cards.slice() : [];
        const orderedCards = promptCards.sort(function (left, right) {
            return getBubbleRadius(right) - getBubbleRadius(left);
        });
        const bandCenters = shuffle([0.61, 0.68, 0.74, 0.8, 0.65, 0.77]);
        const placed = [];

        orderedCards.forEach(function (card, index) {
            const radius = getBubbleRadius(card);
            const bounds = getBubbleMovementBounds(run, radius);
            const bandCenter = bandCenters[index % bandCenters.length];
            const bandMin = clamp((run.height * bandCenter) - (run.height * 0.03), bounds.minY, bounds.maxY);
            const bandMax = clamp((run.height * bandCenter) + (run.height * 0.03), bandMin, bounds.maxY);
            let bestCandidate = null;
            let bestClearance = -Infinity;

            for (let attempt = 0; attempt < 90; attempt += 1) {
                const candidate = {
                    x: randomBetween(bounds.minX, bounds.maxX),
                    y: randomBetween(bandMin, bandMax)
                };
                let minimumClearance = placed.length ? Infinity : 9999;
                let overlapping = false;

                placed.forEach(function (existing) {
                    const distance = Math.sqrt(Math.pow(candidate.x - existing.x, 2) + Math.pow(candidate.y - existing.y, 2));
                    const clearance = distance - (radius + existing.radius + 18);
                    minimumClearance = Math.min(minimumClearance, clearance);
                    if (clearance < 0) {
                        overlapping = true;
                    }
                });

                if (minimumClearance > bestClearance) {
                    bestCandidate = candidate;
                    bestClearance = minimumClearance;
                }
                if (!overlapping) {
                    bestCandidate = candidate;
                    break;
                }
            }

            const chosen = bestCandidate || {
                x: (bounds.minX + bounds.maxX) / 2,
                y: clamp(run.height * bandCenter, bounds.minY, bounds.maxY)
            };
            card.bubbleVisibleX = chosen.x;
            card.bubbleVisibleY = chosen.y;
            card.bubbleBaseX = chosen.x;
            card.bubbleBaseY = chosen.y;
            card.entryVisibleX = chosen.x;
            card.entryVisibleY = chosen.y;
            card.entryStartX = clamp(chosen.x + randomBetween(-34, 34), bounds.minX, bounds.maxX);
            card.bubbleWanderVelocityX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(8, 20);
            card.bubbleFloatAmplitudeXPrimary = randomBetween(10, 24);
            card.bubbleFloatAmplitudeXSecondary = randomBetween(4, 11);
            card.bubbleFloatAmplitudeYPrimary = randomBetween(7, 16);
            card.bubbleFloatAmplitudeYSecondary = randomBetween(3, 8);
            card.bubbleFloatHzXPrimary = randomBetween(0.11, 0.24);
            card.bubbleFloatHzXSecondary = randomBetween(0.21, 0.41);
            card.bubbleFloatHzYPrimary = randomBetween(0.14, 0.28);
            card.bubbleFloatHzYSecondary = randomBetween(0.24, 0.44);
            card.bubbleFloatPhaseXPrimary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseXSecondary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseYPrimary = randomBetween(0, Math.PI * 2);
            card.bubbleFloatPhaseYSecondary = randomBetween(0, Math.PI * 2);
            card.releaseDriftX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(16, 42);
            setFloatingBodyPosition(run, card, chosen.x, chosen.y);

            placed.push({
                x: chosen.x,
                y: chosen.y,
                radius: radius
            });
        });
    }

    function refreshBubblePromptCardPositions(run, now) {
        const activeCards = [];
        run.cards.forEach(function (card) {
            if (!card || card.exploding || card.entryRevealMs > 0) {
                return;
            }

            const motionStrength = card.resolvedFalling ? 0.32 : 1;
            const offsets = getBubbleFloatOffsets(card, now, motionStrength);
            setFloatingBodyPosition(
                run,
                card,
                Number(card.bubbleBaseX || card.entryVisibleX || card.x) + offsets.x,
                Number(card.bubbleBaseY || card.entryVisibleY || card.y) + offsets.y,
                {
                    clampY: false
                }
            );

            if (!card.resolvedFalling) {
                activeCards.push(card);
            }
        });

        resolveFloatingBubbleOverlaps(run, activeCards, 12);
    }

    function getDecorativeBubbleTargetCount(run) {
        return run.width < 480 ? 9 : 13;
    }

    function createDecorativeBubble(run, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const radius = randomBetween(10, 23);
        const bounds = getBubbleMovementBounds(run, radius);
        const bubble = {
            id: toInt(run.decorativeBubbleIdCounter) + 1,
            radius: radius,
            baseX: clamp(Number(opts.baseX) || randomBetween(bounds.minX, bounds.maxX), bounds.minX, bounds.maxX),
            baseY: isFinite(Number(opts.baseY)) ? Number(opts.baseY) : randomBetween(bounds.minY, run.height + radius),
            x: 0,
            y: 0,
            speed: randomBetween(8, 24),
            wanderVelocityX: (Math.random() < 0.5 ? -1 : 1) * randomBetween(2, 8),
            driftXAmplitudePrimary: randomBetween(3, 10),
            driftXAmplitudeSecondary: randomBetween(1.5, 5.5),
            driftYAmplitudePrimary: randomBetween(2, 8),
            driftYAmplitudeSecondary: randomBetween(1, 4),
            driftHzXPrimary: randomBetween(0.08, 0.2),
            driftHzXSecondary: randomBetween(0.18, 0.36),
            driftHzYPrimary: randomBetween(0.1, 0.24),
            driftHzYSecondary: randomBetween(0.22, 0.4),
            driftPhaseXPrimary: randomBetween(0, Math.PI * 2),
            driftPhaseXSecondary: randomBetween(0, Math.PI * 2),
            driftPhaseYPrimary: randomBetween(0, Math.PI * 2),
            driftPhaseYSecondary: randomBetween(0, Math.PI * 2),
            alpha: randomBetween(0.14, 0.28),
            exploding: false,
            removeAt: 0
        };
        run.decorativeBubbleIdCounter = bubble.id;
        setFloatingBodyPosition(run, bubble, bubble.baseX, bubble.baseY, {
            clampY: false
        });
        return bubble;
    }

    function seedDecorativeBubbles(run) {
        if (!run) {
            return;
        }
        if (!Array.isArray(run.decorativeBubbles)) {
            run.decorativeBubbles = [];
        }
        const targetCount = getDecorativeBubbleTargetCount(run);
        while (run.decorativeBubbles.length < targetCount) {
            run.decorativeBubbles.push(createDecorativeBubble(run));
        }
    }

    function updateDecorativeBubblePositions(run, now, dt) {
        if (!run) {
            return;
        }

        run.decorativeBubbles.forEach(function (bubble) {
            if (!bubble || bubble.exploding) {
                return;
            }

            const bounds = getBubbleMovementBounds(run, bubble.radius);
            bubble.baseY -= bubble.speed * dt;
            bubble.baseX += bubble.wanderVelocityX * dt;
            if (bubble.baseX <= bounds.minX || bubble.baseX >= bounds.maxX) {
                bubble.wanderVelocityX *= -1;
                bubble.baseX = clamp(bubble.baseX, bounds.minX, bounds.maxX);
            }

            const offsets = {
                x: (
                    Math.sin((now / 1000 * bubble.driftHzXPrimary * Math.PI * 2) + bubble.driftPhaseXPrimary) * bubble.driftXAmplitudePrimary
                ) + (
                    Math.sin((now / 1000 * bubble.driftHzXSecondary * Math.PI * 2) + bubble.driftPhaseXSecondary) * bubble.driftXAmplitudeSecondary
                ),
                y: (
                    Math.sin((now / 1000 * bubble.driftHzYPrimary * Math.PI * 2) + bubble.driftPhaseYPrimary) * bubble.driftYAmplitudePrimary
                ) + (
                    Math.sin((now / 1000 * bubble.driftHzYSecondary * Math.PI * 2) + bubble.driftPhaseYSecondary) * bubble.driftYAmplitudeSecondary
                )
            };

            setFloatingBodyPosition(run, bubble, bubble.baseX + offsets.x, bubble.baseY + offsets.y, {
                clampY: false
            });
        });

        const floatingBubbles = run.decorativeBubbles.filter(function (bubble) {
            return bubble && !bubble.exploding;
        });
        resolveFloatingBubbleOverlaps(run, floatingBubbles, 6);

        run.decorativeBubbles = run.decorativeBubbles.filter(function (bubble) {
            if (!bubble) {
                return false;
            }
            if (bubble.exploding) {
                return now < bubble.removeAt;
            }
            return (bubble.y + bubble.radius) > (-bubble.radius * 1.8);
        });
        seedDecorativeBubbles(run);
    }

    function renderDecorativeBubbles(ctx, run, now) {
        const context = ctx.canvasContext;
        run.decorativeBubbles.forEach(function (bubble) {
            if (!bubble) {
                return;
            }

            const exploding = !!bubble.exploding && now < bubble.removeAt;
            const duration = Math.max(140, toInt(bubble.explosionDuration) || 180);
            const progress = exploding ? clamp(1 - ((bubble.removeAt - now) / duration), 0, 1) : 0;

            context.save();
            context.translate(Number(bubble.x) || 0, Number(bubble.y) || 0);
            context.globalAlpha = Math.max(0, Number(bubble.alpha) || 0.18) * (exploding ? (1 - progress) : 1);
            context.scale(1 + (progress * 0.28), 1 + (progress * 0.28));

            const gradient = context.createRadialGradient(-bubble.radius * 0.32, -bubble.radius * 0.36, bubble.radius * 0.08, 0, 0, bubble.radius);
            gradient.addColorStop(0, 'rgba(255,255,255,0.72)');
            gradient.addColorStop(0.34, 'rgba(255,255,255,0.26)');
            gradient.addColorStop(1, 'rgba(255,255,255,0.04)');
            context.fillStyle = gradient;
            context.beginPath();
            context.arc(0, 0, bubble.radius, 0, Math.PI * 2);
            context.fill();

            context.strokeStyle = 'rgba(255,255,255,0.42)';
            context.lineWidth = 1.2;
            context.beginPath();
            context.arc(0, 0, bubble.radius, 0, Math.PI * 2);
            context.stroke();

            context.strokeStyle = 'rgba(255,255,255,0.34)';
            context.lineWidth = 0.9;
            context.beginPath();
            context.arc(-bubble.radius * 0.2, -bubble.radius * 0.24, bubble.radius * 0.24, Math.PI * 1.08, Math.PI * 1.86);
            context.stroke();
            context.restore();
        });
    }

    function popDecorativeBubble(run, bubble) {
        if (!run || !bubble || bubble.exploding) {
            return false;
        }

        const now = currentTimestamp();
        bubble.exploding = true;
        bubble.explosionDuration = 180;
        bubble.removeAt = now + 180;
        spawnExplosion(run, {
            x: bubble.x,
            y: bubble.y,
            radius: bubble.radius * 2.1,
            primaryColor: 'rgba(233, 248, 255, 0.88)',
            secondaryColor: 'rgba(154, 221, 255, 0.78)',
            duration: 220,
            style: 'bubble-pop',
            rayCount: 8
        });
        return true;
    }

    function renderBubbleCards(ctx, run, now) {
        const context = ctx.canvasContext;
        run.cards.forEach(function (card) {
            const ambientMotion = (!card.exploding && !card.resolvedFalling)
                ? getCardAmbientMotion(card, now)
                : { bobY: 0, tilt: 0 };
            const exploding = !!card.exploding && now < card.removeAt;
            const explosionDuration = Math.max(180, toInt(card.explosionDuration) || 240);
            const progress = exploding ? clamp(1 - ((card.removeAt - now) / explosionDuration), 0, 1) : 0;
            const radius = getBubbleRadius(card);
            const renderX = card.x;
            const renderY = card.y;
            const image = loadWordImage(ctx, card.word);
            const isCorrectPop = card.explosionStyle === 'bubble-correct';
            const isWrongBurst = card.explosionStyle === 'bubble-wrong';

            context.save();
            context.translate(renderX, renderY);

            if (exploding) {
                context.globalAlpha = clamp(1 - progress, 0, 1);
            }
            if (isWrongBurst) {
                context.rotate((Math.sin(progress * 24) * 0.08) + (progress * 0.24));
                context.scale(1 + (progress * 0.52), 1 + (progress * 0.52));
            } else if (isCorrectPop) {
                context.scale(1 + (progress * 0.18), 1 + (progress * 0.18));
            } else if (!card.resolvedFalling) {
                context.rotate(ambientMotion.tilt * 0.32);
            }

            context.shadowColor = 'rgba(15, 23, 42, 0.22)';
            context.shadowBlur = 18;
            context.shadowOffsetY = 10;

            const bubbleGradient = context.createRadialGradient(-radius * 0.34, -radius * 0.4, radius * 0.12, 0, 0, radius);
            bubbleGradient.addColorStop(0, 'rgba(255,255,255,0.92)');
            bubbleGradient.addColorStop(0.22, 'rgba(244, 252, 255, 0.76)');
            bubbleGradient.addColorStop(0.68, 'rgba(191, 231, 255, 0.28)');
            bubbleGradient.addColorStop(1, 'rgba(125, 211, 252, 0.14)');
            context.fillStyle = bubbleGradient;
            context.beginPath();
            context.arc(0, 0, radius, 0, Math.PI * 2);
            context.fill();

            context.shadowBlur = 0;
            context.shadowOffsetY = 0;

            context.save();
            context.beginPath();
            context.arc(0, 0, radius * 0.8, 0, Math.PI * 2);
            context.clip();
            if (!drawImageContain(context, image, -radius * 0.8, -radius * 0.8, radius * 1.6, radius * 1.6)) {
                const placeholder = context.createLinearGradient(-radius, -radius, radius, radius);
                placeholder.addColorStop(0, '#F5FCFF');
                placeholder.addColorStop(1, '#DDEFFD');
                context.fillStyle = placeholder;
                context.fillRect(-radius, -radius, radius * 2, radius * 2);
                context.fillStyle = 'rgba(15, 23, 42, 0.48)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('●', 0, 0);
            }
            context.restore();

            context.strokeStyle = 'rgba(255,255,255,0.82)';
            context.lineWidth = 2;
            context.beginPath();
            context.arc(0, 0, radius, 0, Math.PI * 2);
            context.stroke();

            context.strokeStyle = 'rgba(191, 219, 254, 0.7)';
            context.lineWidth = 1.2;
            context.beginPath();
            context.arc(-radius * 0.22, -radius * 0.26, radius * 0.26, Math.PI * 1.08, Math.PI * 1.86);
            context.stroke();

            context.fillStyle = 'rgba(255,255,255,0.54)';
            context.beginPath();
            context.arc(-radius * 0.34, -radius * 0.38, radius * 0.12, 0, Math.PI * 2);
            context.fill();

            if (exploding && isCorrectPop) {
                context.strokeStyle = 'rgba(154, 221, 255, 0.96)';
                context.lineWidth = 3.8;
                context.beginPath();
                context.arc(0, 0, radius * (0.68 + (progress * 0.92)), 0, Math.PI * 2);
                context.stroke();

                context.strokeStyle = 'rgba(255,255,255,0.88)';
                context.lineWidth = 2;
                context.beginPath();
                context.arc(0, 0, radius * (0.42 + (progress * 0.62)), 0, Math.PI * 2);
                context.stroke();
            } else if (exploding && isWrongBurst) {
                const rayCount = 12;
                context.strokeStyle = 'rgba(255, 134, 105, 0.96)';
                context.lineWidth = 3.4;
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.42;
                    const outerRadius = radius * (0.92 + (progress * 1.18) + ((index % 2) * 0.14));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.strokeStyle = 'rgba(255, 241, 236, 0.84)';
                context.lineWidth = 2.2;
                context.beginPath();
                context.arc(0, 0, radius * (0.58 + (progress * 0.9)), 0, Math.PI * 2);
                context.stroke();
            }
            context.restore();
        });
    }

    function renderCards(ctx, run, now) {
        if (isBubblePopRun(ctx, run)) {
            renderBubbleCards(ctx, run, now);
            return;
        }
        const context = ctx.canvasContext;
        run.cards.forEach(function (card) {
            const ambientMotion = (!card.exploding && !card.resolvedFalling)
                ? getCardAmbientMotion(card, now)
                : { bobY: 0, tilt: 0 };
            const renderX = card.x;
            const renderY = card.y + ambientMotion.bobY;
            const left = renderX - (card.width / 2);
            const top = renderY - (card.height / 2);
            const exploding = !!card.exploding && now < card.removeAt;
            const explosionDuration = Math.max(180, toInt(card.explosionDuration) || (card.explosionStyle === 'dramatic' ? 320 : 220));
            const progress = exploding ? clamp(1 - ((card.removeAt - now) / explosionDuration), 0, 1) : 0;
            const dramatic = card.explosionStyle === 'dramatic';

            context.save();
            if (exploding) {
                context.globalAlpha = clamp(1 - progress, 0, 1);
            }
            if (dramatic) {
                const wobble = Math.sin(progress * 34) * (1 - progress) * 12;
                context.translate(renderX, renderY);
                context.rotate((Math.sin(progress * 28) * 0.08) + (progress * 0.28));
                context.scale(1 + (progress * 0.42), 1 + (progress * 0.36));
                context.translate(-renderX + wobble, -renderY);
            } else if (!card.resolvedFalling) {
                context.translate(renderX, renderY);
                context.rotate(ambientMotion.tilt);
                context.translate(-renderX, -renderY);
            } else if (card.resolvedFalling) {
                context.translate(renderX, renderY);
                context.rotate((card.laneIndex % 2 === 0 ? -1 : 1) * 0.045);
                context.translate(-renderX, -renderY);
            }
            context.shadowColor = 'rgba(15, 23, 42, 0.22)';
            context.shadowBlur = 16;
            context.shadowOffsetY = 10;
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.fillStyle = '#FFFFFF';
            context.fill();
            context.shadowBlur = 0;
            context.shadowOffsetY = 0;

            const image = loadWordImage(ctx, card.word);
            context.save();
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.clip();
            if (!drawImageContain(context, image, left, top, card.width, card.height)) {
                const placeholder = context.createLinearGradient(left, top, left + card.width, top + card.height);
                placeholder.addColorStop(0, '#DFF7FF');
                placeholder.addColorStop(1, '#C7F0EB');
                context.fillStyle = placeholder;
                context.fillRect(left, top, card.width, card.height);
                context.fillStyle = 'rgba(15, 23, 42, 0.42)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('✦', renderX, renderY);
            }
            context.restore();

            context.strokeStyle = 'rgba(15, 23, 42, 0.08)';
            context.lineWidth = 1.5;
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.stroke();

            if (dramatic) {
                const flash = context.createRadialGradient(renderX, renderY, card.width * 0.08, renderX, renderY, card.width * 0.7);
                flash.addColorStop(0, 'rgba(255,255,255,0.92)');
                flash.addColorStop(0.38, 'rgba(251, 191, 36, 0.78)');
                flash.addColorStop(1, 'rgba(248, 113, 113, 0)');
                context.globalCompositeOperation = 'screen';
                context.fillStyle = flash;
                context.fillRect(left, top, card.width, card.height);
                context.globalCompositeOperation = 'source-over';

                context.fillStyle = 'rgba(127, 29, 29, 0.18)';
                drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
                context.fill();

                context.strokeStyle = 'rgba(255, 241, 242, 0.82)';
                context.lineWidth = 2.4;
                context.beginPath();
                context.moveTo(left + (card.width * 0.18), top + (card.height * 0.16));
                context.lineTo(left + (card.width * 0.72), top + (card.height * 0.38));
                context.lineTo(left + (card.width * 0.46), top + (card.height * 0.8));
                context.stroke();
            }

            if (exploding) {
                context.strokeStyle = card.isTarget ? 'rgba(16, 185, 129, 0.9)' : 'rgba(248, 113, 113, 0.92)';
                context.lineWidth = dramatic ? 4 : 3;
                if (dramatic) {
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.26) + (progress * Math.max(card.width, card.height) * 1.02), 0, Math.PI * 2);
                    context.stroke();

                    context.strokeStyle = 'rgba(255, 228, 230, 0.7)';
                    context.lineWidth = 2.2;
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.12) + (progress * Math.max(card.width, card.height) * 0.68), 0, Math.PI * 2);
                    context.stroke();
                } else {
                    context.beginPath();
                    context.arc(renderX, renderY, (Math.max(card.width, card.height) * 0.2) + (progress * Math.max(card.width, card.height) * 0.55), 0, Math.PI * 2);
                    context.stroke();
                }
            }
            context.restore();
        });
    }

    function renderExplosions(ctx, run, now) {
        const context = ctx.canvasContext;
        run.explosions.forEach(function (explosion) {
            const progress = clamp((now - explosion.startedAt) / explosion.duration, 0, 1);
            const radius = explosion.radius * (0.28 + (progress * 0.9));
            context.save();
            context.translate(explosion.x, explosion.y);
            context.rotate(progress * (Number(explosion.spin) || 0) * Math.PI * 6);
            context.globalAlpha = 1 - progress;

            if (explosion.style === 'bubble-pop') {
                context.strokeStyle = String(explosion.primaryColor || 'rgba(154, 221, 255, 0.94)');
                context.lineWidth = 3.6 - (progress * 1.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.92, 0, Math.PI * 2);
                context.stroke();

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.88)');
                context.lineWidth = 1.8;
                context.beginPath();
                context.arc(0, 0, radius * 0.56, 0, Math.PI * 2);
                context.stroke();

                context.fillStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.82)');
                const dropletCount = Math.max(6, toInt(explosion.rayCount) || 8);
                for (let index = 0; index < dropletCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / dropletCount;
                    const dotRadius = Math.max(1.6, radius * 0.08 * (1 - (progress * 0.3)));
                    const offset = radius * (0.46 + (progress * 0.62));
                    context.beginPath();
                    context.arc(Math.cos(angle) * offset, Math.sin(angle) * offset, dotRadius, 0, Math.PI * 2);
                    context.fill();
                }
            } else if (explosion.style === 'bubble-ray-burst') {
                const rayCount = Math.max(10, toInt(explosion.rayCount) || 14);
                context.strokeStyle = String(explosion.primaryColor || 'rgba(255, 134, 105, 0.96)');
                context.lineWidth = 2.8 + ((1 - progress) * 3.4);
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.14;
                    const outerRadius = radius * (1 + ((index % 2) * 0.18));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255, 241, 236, 0.84)');
                context.lineWidth = 2.2 + ((1 - progress) * 1.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.72, 0, Math.PI * 2);
                context.stroke();
            } else if (explosion.style === 'burst') {
                const rayCount = Math.max(6, toInt(explosion.rayCount) || 10);
                context.strokeStyle = String(explosion.primaryColor || 'rgba(248, 113, 113, 0.96)');
                context.lineWidth = 2.4 + ((1 - progress) * 3.8);
                for (let index = 0; index < rayCount; index += 1) {
                    const angle = (Math.PI * 2 * index) / rayCount;
                    const innerRadius = radius * 0.18;
                    const outerRadius = radius * (1.02 + ((index % 2) * 0.18));
                    context.beginPath();
                    context.moveTo(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius);
                    context.lineTo(Math.cos(angle) * outerRadius, Math.sin(angle) * outerRadius);
                    context.stroke();
                }

                context.fillStyle = String(explosion.secondaryColor || 'rgba(251, 191, 36, 0.88)');
                context.beginPath();
                context.arc(0, 0, radius * 0.24, 0, Math.PI * 2);
                context.fill();

                context.strokeStyle = String(explosion.secondaryColor || 'rgba(255,255,255,0.85)');
                context.lineWidth = 2 + ((1 - progress) * 2.4);
                context.beginPath();
                context.arc(0, 0, radius * 0.62, 0, Math.PI * 2);
                context.stroke();
            }

            if (explosion.style !== 'bubble-pop' && explosion.style !== 'bubble-ray-burst') {
                context.strokeStyle = String(explosion.primaryColor || explosion.color || 'rgba(255,255,255,0.9)');
                context.lineWidth = 2 + ((1 - progress) * (explosion.style === 'burst' ? 3.4 : 4));
                context.beginPath();
                context.arc(0, 0, radius, 0, Math.PI * 2);
                context.stroke();
            }
            context.restore();
        });
    }

    function renderRun(ctx, now) {
        const run = ctx.run;
        if (!run || !ctx.canvasContext) {
            return;
        }
        renderBackground(ctx, run);
        if (isBubblePopRun(ctx, run)) {
            renderDecorativeBubbles(ctx, run, now);
        }
        renderCards(ctx, run, now);
        renderBullets(ctx, run);
        renderExplosions(ctx, run, now);
        renderShip(ctx, run);
    }

    function spawnExplosion(run, options, y, color, radius) {
        const config = (options && typeof options === 'object')
            ? options
            : {
                x: options,
                y: y,
                primaryColor: color,
                radius: radius
            };
        run.explosions.push({
            x: Number(config.x) || 0,
            y: Number(config.y) || 0,
            color: String(config.primaryColor || config.color || 'rgba(255,255,255,0.9)'),
            primaryColor: String(config.primaryColor || config.color || 'rgba(255,255,255,0.9)'),
            secondaryColor: String(config.secondaryColor || ''),
            radius: Math.max(8, Number(config.radius) || 24),
            startedAt: currentTimestamp(),
            duration: Math.max(160, toInt(config.duration) || 280),
            style: String(config.style || 'ring'),
            rayCount: Math.max(0, toInt(config.rayCount) || 0),
            spin: Number(config.spin) || 0
        });
    }

    function createCard(run, word, laneIndex, isTarget, offsetFactor, promptId) {
        const baseOffset = Math.max(0, Number(offsetFactor) || 0);
        const dimensions = getCardDimensions(run, null);
        return {
            word: word,
            promptId: toInt(promptId),
            laneIndex: laneIndex,
            x: laneCenterX(run, laneIndex),
            y: -dimensions.height,
            width: dimensions.width,
            height: dimensions.height,
            aspectRatio: dimensions.aspectRatio,
            entryOffsetFactor: baseOffset,
            entryDepthJitter: Math.random() * 0.08,
            entryRevealMs: 0,
            entryStartedAt: 0,
            entryStartX: laneCenterX(run, laneIndex),
            entryStartY: -dimensions.height,
            entryVisibleX: laneCenterX(run, laneIndex),
            entryVisibleY: dimensions.height / 2,
            bubbleVisibleX: null,
            bubbleVisibleY: null,
            bubbleBaseX: null,
            bubbleBaseY: null,
            bubbleWanderVelocityX: 0,
            bubbleFloatAmplitudeXPrimary: 0,
            bubbleFloatAmplitudeXSecondary: 0,
            bubbleFloatAmplitudeYPrimary: 0,
            bubbleFloatAmplitudeYSecondary: 0,
            bubbleFloatHzXPrimary: 0,
            bubbleFloatHzXSecondary: 0,
            bubbleFloatHzYPrimary: 0,
            bubbleFloatHzYSecondary: 0,
            bubbleFloatPhaseXPrimary: 0,
            bubbleFloatPhaseXSecondary: 0,
            bubbleFloatPhaseYPrimary: 0,
            bubbleFloatPhaseYSecondary: 0,
            releaseDriftX: 0,
            fallSpeedFactor: isTarget ? 1 : (0.9 + (Math.random() * 0.2)),
            speed: run.cardSpeed,
            motionBobAmplitudePrimary: 3.2 + (Math.random() * 2.4),
            motionBobAmplitudeSecondary: 1.4 + (Math.random() * 1.8),
            motionBobHzPrimary: 0.11 + (Math.random() * 0.08),
            motionBobHzSecondary: 0.17 + (Math.random() * 0.11),
            motionBobPhasePrimary: Math.random() * Math.PI * 2,
            motionBobPhaseSecondary: Math.random() * Math.PI * 2,
            motionTiltAmplitudePrimary: 0.01 + (Math.random() * 0.015),
            motionTiltAmplitudeSecondary: 0.004 + (Math.random() * 0.01),
            motionTiltHzPrimary: 0.08 + (Math.random() * 0.06),
            motionTiltHzSecondary: 0.13 + (Math.random() * 0.08),
            motionTiltPhasePrimary: Math.random() * Math.PI * 2,
            motionTiltPhaseSecondary: Math.random() * Math.PI * 2,
            isTarget: !!isTarget,
            resolvedFalling: false,
            exploding: false,
            explosionStyle: '',
            removeAt: 0
        };
    }

    function getCardEntryRevealMs(ctx, card) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const maxRevealMs = Math.max(220, toInt(gameConfig && gameConfig.cardEntryRevealMs) || 560);
        const baseOffset = Math.max(0, Number(card && card.entryOffsetFactor) || 0);
        return Math.min(maxRevealMs, 380 + Math.round(baseOffset * 150));
    }

    function getCardEntryVisibleY(ctx, run, card) {
        if (isBubblePopRun(ctx, run) && isFinite(Number(card && card.bubbleVisibleY))) {
            return Number(card.bubbleVisibleY);
        }

        const cardHeight = Math.max(1, Number(card && card.height) || 1);
        const metricsCardHeight = Math.max(cardHeight, Number(run && run.metrics && run.metrics.cardHeight) || cardHeight);
        const offsetFactor = Math.max(0, Number(card && card.entryOffsetFactor) || 0);
        const jitter = Number(card && card.entryDepthJitter || 0);
        const depthRatio = clamp(0.06 + (offsetFactor * 0.28) + jitter, 0.05, 0.38);

        if (isBubblePopRun(ctx, run)) {
            const liftDepth = Math.min(Math.max(48, run.height * 0.24), metricsCardHeight * 1.18);
            return run.height - (cardHeight / 2) - (liftDepth * depthRatio);
        }

        const maxDepth = Math.min(Math.max(40, run.height * 0.24), metricsCardHeight * 0.95);
        return (cardHeight / 2) + (maxDepth * depthRatio);
    }

    function getCardSafeLineCrossDelayMs(ctx, run, card) {
        if (!run || !card) {
            return 0;
        }

        const speed = Number(card.speed) || 0;
        if (!isFinite(speed) || speed <= 0) {
            return 0;
        }

        const gameConfig = getGameConfig(ctx, run);
        const safeLineRatio = clamp(Number(gameConfig && gameConfig.audioSafeLineRatio) || 0.6, 0.35, 0.7);
        const safeLineY = isBubblePopRun(ctx, run)
            ? Math.max(0, Number(run.height) || 0) * (1 - safeLineRatio)
            : Math.max(0, Number(run.height) || 0) * safeLineRatio;
        const visibleY = Number(card.entryVisibleY);
        const entryRevealMs = Math.max(0, toInt(card.entryRevealMs));

        if (!isFinite(visibleY)) {
            return entryRevealMs;
        }

        if (isBubblePopRun(ctx, run)) {
            if (safeLineY >= visibleY) {
                return entryRevealMs;
            }
            return entryRevealMs + Math.round(((visibleY - safeLineY) / speed) * 1000);
        }

        if (safeLineY <= visibleY) {
            return entryRevealMs;
        }

        return entryRevealMs + Math.round(((safeLineY - visibleY) / speed) * 1000);
    }

    function getPromptAutoReplayTiming(ctx, run, promptDurationMs, targetCard) {
        const gameConfig = getGameConfig(ctx, run);
        const replayGapMs = Math.max(220, toInt(gameConfig && gameConfig.promptAutoReplayGapMs) || 420);
        const baseDelayMs = Math.max(0, toInt(promptDurationMs)) + replayGapMs;
        const shortPromptLimitMs = Math.max(SHORT_PROMPT_AUTO_REPLAY_MIN_MS, replayGapMs * 3);
        const safeLineCrossDelayMs = getCardSafeLineCrossDelayMs(ctx, run, targetCard);
        const safeLineReplayDelayMs = safeLineCrossDelayMs > 0
            ? safeLineCrossDelayMs + POST_SAFE_LINE_REPLAY_BUFFER_MS
            : 0;
        const gatedBySafeLine = promptDurationMs > 0
            && promptDurationMs <= shortPromptLimitMs
            && safeLineReplayDelayMs > baseDelayMs;

        return {
            baseDelayMs: baseDelayMs,
            delayMs: gatedBySafeLine ? safeLineReplayDelayMs : baseDelayMs,
            safeLineCrossDelayMs: safeLineCrossDelayMs,
            gatedBySafeLine: gatedBySafeLine
        };
    }

    function buildCompatiblePromptWordSet(targetWord, pool, requiredCount) {
        const selected = [targetWord];

        for (let index = 0; index < pool.length; index += 1) {
            const candidate = pool[index];
            const compatible = selected.every(function (existingWord) {
                return wordsCanShareRound(existingWord, candidate);
            });
            if (!compatible) {
                continue;
            }
            selected.push(candidate);
            if (selected.length >= requiredCount) {
                break;
            }
        }

        if (selected.length < requiredCount) {
            return null;
        }

        return selected;
    }

    function selectCompatiblePromptWords(targetWord, words, requiredCount, strategy) {
        const distractorStrategy = String(strategy || 'mixed');
        const allDistractors = shuffle((Array.isArray(words) ? words : []).filter(function (word) {
            return toInt(word && word.id) !== toInt(targetWord && targetWord.id);
        }));
        let pool = allDistractors;

        if (distractorStrategy === 'same-category') {
            const sameCategory = [];
            const otherCategories = [];

            allDistractors.forEach(function (candidate) {
                if (wordsShareCategory(targetWord, candidate)) {
                    sameCategory.push(candidate);
                    return;
                }
                otherCategories.push(candidate);
            });
            pool = sameCategory.concat(otherCategories);
        }

        return buildCompatiblePromptWordSet(targetWord, pool, requiredCount);
    }

    function hasCompatibleSameCategoryDistractor(targetWord, words) {
        return (Array.isArray(words) ? words : []).some(function (candidate) {
            return toInt(candidate && candidate.id) !== toInt(targetWord && targetWord.id)
                && wordsShareCategory(targetWord, candidate)
                && wordsCanShareRound(targetWord, candidate);
        });
    }

    function selectDistractorStrategy(run, targetWord, words) {
        const sameCategoryAvailable = hasCompatibleSameCategoryDistractor(targetWord, words);
        const shouldPreferSameCategory = !!(run && run.useSameCategoryDistractorsNext);

        if (!run) {
            return sameCategoryAvailable ? 'same-category' : 'mixed';
        }

        if (!sameCategoryAvailable) {
            run.useSameCategoryDistractorsNext = true;
            return 'mixed';
        }

        run.useSameCategoryDistractorsNext = !shouldPreferSameCategory;
        return shouldPreferSameCategory ? 'same-category' : 'mixed';
    }

    function buildPromptCards(ctx, run, targetWord, words, promptId, distractorStrategy) {
        const selected = selectCompatiblePromptWords(targetWord, words, run.cardCount, distractorStrategy);
        if (!selected) {
            return null;
        }

        const shuffledWords = shuffle(selected);
        const stagger = shuffle([0.1, 0.38, 0.72, 1.02]);
        const cards = shuffledWords.map(function (word, index) {
            const card = createCard(
                run,
                word,
                index,
                toInt(word.id) === toInt(targetWord.id),
                stagger[index] || (index * 0.28),
                promptId
            );
            applyCardDimensions(ctx, run, card);
            return card;
        });

        if (isBubblePopRun(ctx, run)) {
            placeBubblePromptCards(run, cards);
        }

        return cards;
    }

    function findPlayableTargets(words, cardCount) {
        return (Array.isArray(words) ? words : []).filter(function (word) {
            return !!selectCompatiblePromptWords(word, words, cardCount);
        });
    }

    function buildPreparedEntry(ctx, slug, rawEntry) {
        const entry = $.extend({}, rawEntry || {});
        const gameConfig = getGameConfig(ctx, slug);
        const minimumCount = Math.max(1, toInt(entry.minimum_word_count) || ctx.minimumWordCount);
        const maxLoadedWords = Math.max(
            minimumCount,
            toInt(entry.launch_word_cap)
                || toInt(gameConfig && gameConfig.maxLoadedWords)
                || minimumCount
        );
        const eligibleWords = (Array.isArray(entry.words) ? entry.words : [])
            .map(normalizeWord)
            .filter(function (word) {
                const audio = selectPromptAudio(word);
                return word.id > 0 && word.image !== '' && audio.url !== '';
            });
        const words = limitLaunchWords(eligibleWords, maxLoadedWords);
        const playableTargets = findPlayableTargets(words, Math.max(2, toInt(gameConfig && gameConfig.cardCount) || 4));
        const prepared = $.extend({}, entry, {
            slug: normalizeGameSlug(entry.slug || slug),
            words: words,
            playableTargets: playableTargets,
            available_word_count: toInt(entry.available_word_count) || eligibleWords.length,
            launch_word_cap: maxLoadedWords,
            launch_word_count: words.length,
            launchable: !!entry.launchable && words.length >= minimumCount && playableTargets.length > 0,
            minimum_word_count: minimumCount,
            category_ids: uniqueIntList(entry.category_ids || [])
        });

        if (prepared.words.length >= minimumCount && !prepared.playableTargets.length) {
            prepared.launchable = false;
            prepared.reason_code = 'not_enough_compatible_words';
        }

        return prepared;
    }

    function getCardStatusText(ctx, entry) {
        if (!ctx.isLoggedIn) {
            return String(ctx.i18n.gamesLoginRequired || 'Sign in to play with your in-progress words.');
        }
        if (!entry) {
            return String(ctx.i18n.gamesLoadError || 'Unable to load games right now.');
        }
        if (entry.launchable) {
            return formatMessage(ctx.i18n.gamesReadyCount || '%d words ready', [entry.available_word_count || 0]);
        }
        if (String(entry.reason_code || '') === 'not_enough_compatible_words') {
            return String(ctx.i18n.gamesNeedCompatibleWords || 'This word set does not have a playable mix of picture cards yet.');
        }
        const missing = Math.max(0, (entry.minimum_word_count || ctx.minimumWordCount) - (entry.available_word_count || 0));
        return formatMessage(ctx.i18n.gamesNeedWords || 'Need %d more words to unlock this game.', [missing]);
    }

    function renderCatalogCard(ctx, slug, entry, isLoading) {
        const normalizedSlug = normalizeGameSlug(slug);
        const card = ctx && ctx.catalogCards ? ctx.catalogCards[normalizedSlug] : null;
        if (!card) {
            return;
        }
        const buttonLabel = (entry && entry.launchable)
            ? String(ctx.i18n.gamesPlay || 'Play')
            : String(ctx.i18n.gamesLocked || 'Locked');

        card.$status.text(isLoading ? String(ctx.i18n.gamesLoading || 'Checking game availability...') : getCardStatusText(ctx, entry));
        card.$count.text(entry ? String(entry.available_word_count || 0) : '\u2014');
        card.$launchButton.text(ctx.isLoggedIn ? buttonLabel : String(ctx.i18n.gamesLocked || 'Locked'));
        card.$launchButton.prop('disabled', isLoading || !ctx.isLoggedIn || !(entry && entry.launchable));
        card.$card.toggleClass('is-launchable', !!(entry && entry.launchable));
        card.$card.toggleClass('is-loading', !!isLoading);
    }

    function renderAllCatalogCards(ctx, entries, isLoading) {
        const catalogEntries = (entries && typeof entries === 'object') ? entries : {};
        (Array.isArray(ctx.catalogOrder) ? ctx.catalogOrder : []).forEach(function (slug) {
            renderCatalogCard(ctx, slug, catalogEntries[slug] || null, !!isLoading);
        });
    }

    function updateReplayAudioUi(ctx, isPlaying) {
        if (!ctx || !ctx.$replayAudioButton || !ctx.$replayAudioButton.length) {
            return;
        }
        ctx.$replayAudioButton.toggleClass('playing', !!isPlaying);
        ctx.$replayAudioButton.find('.ll-audio-mini-visualizer').toggleClass('active', !!isPlaying);
    }

    function ensurePromptAudio(ctx) {
        if (ctx.promptAudio) {
            return ctx.promptAudio;
        }

        ctx.promptAudio = new Audio();
        ctx.promptAudio.preload = 'auto';

        ['playing', 'play'].forEach(function (eventName) {
            ctx.promptAudio.addEventListener(eventName, function () {
                updateReplayAudioUi(ctx, true);
            });
        });
        ['pause', 'ended', 'error'].forEach(function (eventName) {
            ctx.promptAudio.addEventListener(eventName, function () {
                updateReplayAudioUi(ctx, false);
            });
        });

        return ctx.promptAudio;
    }

    function clearPromptReplayTimer(ctx) {
        if (!ctx || !ctx.promptReplayTimer) {
            return;
        }

        root.clearTimeout(ctx.promptReplayTimer);
        ctx.promptReplayTimer = 0;
    }

    function cancelQueuedPromptPlayback(ctx) {
        clearPromptReplayTimer(ctx);
        ctx.promptPlaybackRequestId = toInt(ctx.promptPlaybackRequestId) + 1;
    }

    function pausePromptAudio(ctx, options) {
        const opts = options || {};
        if (opts.cancelQueued !== false) {
            cancelQueuedPromptPlayback(ctx);
        }
        if (ctx.promptAudio && typeof ctx.promptAudio.pause === 'function') {
            try {
                ctx.promptAudio.pause();
            } catch (_) { /* no-op */ }
        }
        updateReplayAudioUi(ctx, false);
    }

    function ensureFeedbackAudio(ctx) {
        if (ctx.feedbackAudio) {
            return ctx.feedbackAudio;
        }

        ctx.feedbackAudio = new Audio();
        ctx.feedbackAudio.preload = 'auto';
        return ctx.feedbackAudio;
    }

    function getFeedbackAudioSources(ctx, type) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        return type === 'correct'
            ? normalizeUrlList(gameConfig && gameConfig.correctHitAudioSources)
            : normalizeUrlList(gameConfig && gameConfig.wrongHitAudioSources);
    }

    function getFeedbackAudioVolume(ctx, type) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const configured = type === 'correct'
            ? Number(gameConfig && gameConfig.correctHitVolume)
            : Number(gameConfig && gameConfig.wrongHitVolume);
        return clamp(configured, 0.05, 1);
    }

    function waitForFeedbackQueue(ctx) {
        return ctx && ctx.feedbackQueue && typeof ctx.feedbackQueue.then === 'function'
            ? ctx.feedbackQueue.catch(function () {})
            : Promise.resolve();
    }

    function stopFeedbackAudio(ctx) {
        if (!ctx) {
            return;
        }

        ctx.feedbackQueueVersion = toInt(ctx.feedbackQueueVersion) + 1;
        ctx.feedbackPlaying = false;
        ctx.feedbackQueue = Promise.resolve();

        if (ctx.feedbackAudio && typeof ctx.feedbackAudio.pause === 'function') {
            try {
                ctx.feedbackAudio.pause();
            } catch (_) { /* no-op */ }
        }
        if (ctx.feedbackAudio) {
            try {
                ctx.feedbackAudio.currentTime = 0;
            } catch (_) { /* no-op */ }
        }
    }

    function resolveReadyAudioSource(ctx, sources, cacheKey) {
        const sourceList = normalizeUrlList(sources);
        if (!sourceList.length) {
            return Promise.resolve('');
        }

        if (ctx.feedbackAudioSourceCache[cacheKey]) {
            return Promise.resolve(String(ctx.feedbackAudioSourceCache[cacheKey]));
        }

        let chain = Promise.resolve('');
        sourceList.forEach(function (source) {
            chain = chain.then(function (resolvedSource) {
                if (resolvedSource) {
                    return resolvedSource;
                }

                return ensureAudioLoaded(ctx, source).then(function (loaded) {
                    if (loaded) {
                        ctx.feedbackAudioSourceCache[cacheKey] = source;
                        return source;
                    }
                    return '';
                }).catch(function () {
                    return '';
                });
            });
        });

        return chain;
    }

    function waitForFeedbackAudioToFinish(ctx, audio, sequenceVersion, fallbackMs) {
        return new Promise(function (resolve) {
            let settled = false;
            let timeoutId = 0;

            const cleanup = function () {
                if (timeoutId) {
                    root.clearTimeout(timeoutId);
                    timeoutId = 0;
                }
                audio.removeEventListener('ended', onDone);
                audio.removeEventListener('error', onDone);
                audio.removeEventListener('pause', onPause);
            };

            const finish = function () {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve();
            };

            const onDone = function () {
                finish();
            };
            const onPause = function () {
                if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                    finish();
                }
            };

            if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                finish();
                return;
            }

            audio.addEventListener('ended', onDone);
            audio.addEventListener('error', onDone);
            audio.addEventListener('pause', onPause);
            timeoutId = root.setTimeout(onDone, Math.max(140, toInt(fallbackMs) || 0) + 80);
        });
    }

    function playFeedbackSound(ctx, type) {
        const soundType = (type === 'correct') ? 'correct' : 'wrong';
        const sources = getFeedbackAudioSources(ctx, soundType);
        if (!sources.length) {
            return waitForFeedbackQueue(ctx);
        }

        pausePromptAudio(ctx);

        const sequenceVersion = toInt(ctx.feedbackQueueVersion);
        const queue = waitForFeedbackQueue(ctx);
        ctx.feedbackQueue = queue.then(function () {
            if ((ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                return;
            }

            return resolveReadyAudioSource(ctx, sources, soundType).then(function (source) {
                if (!source || (ctx.feedbackQueueVersion || 0) !== sequenceVersion) {
                    return;
                }

                const feedbackAudio = ensureFeedbackAudio(ctx);
                const feedbackDurationMs = getLoadedAudioDurationMs(ctx, source);

                try {
                    feedbackAudio.pause();
                } catch (_) { /* no-op */ }
                try {
                    feedbackAudio.currentTime = 0;
                } catch (_) { /* no-op */ }

                feedbackAudio.volume = getFeedbackAudioVolume(ctx, soundType);
                if (feedbackAudio.src !== source) {
                    feedbackAudio.src = source;
                }

                ctx.feedbackPlaying = true;
                return Promise.resolve(feedbackAudio.play()).catch(function () {
                    return false;
                }).then(function () {
                    return waitForFeedbackAudioToFinish(ctx, feedbackAudio, sequenceVersion, feedbackDurationMs);
                }).finally(function () {
                    if ((ctx.feedbackQueueVersion || 0) === sequenceVersion) {
                        ctx.feedbackPlaying = false;
                    }
                });
            });
        }).catch(function () {});

        return ctx.feedbackQueue;
    }

    function schedulePromptAutoReplay(ctx, run, source, requestId) {
        const promptDurationMs = Math.max(0, toInt(run && run.prompt && run.prompt.audioDurationMs) || getLoadedAudioDurationMs(ctx, source));
        const replayDelayMs = Math.max(0, toInt(run && run.prompt && run.prompt.autoReplayDelayMs));
        if (promptDurationMs <= 0 || replayDelayMs <= 0) {
            return;
        }

        clearPromptReplayTimer(ctx);
        ctx.promptReplayTimer = root.setTimeout(function () {
            ctx.promptReplayTimer = 0;
            if ((ctx.promptPlaybackRequestId || 0) !== requestId) {
                return;
            }
            if (!ctx.run || ctx.run !== run || run.paused || run.ended || !run.prompt || run.prompt.resolved) {
                return;
            }
            if (String(run.prompt.audioUrl || '') !== source) {
                return;
            }

            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        }, replayDelayMs);
    }

    function playPromptAudio(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx.run;
        if (!run || run.paused || !run.prompt || !run.prompt.target) {
            return Promise.resolve(false);
        }

        const source = String(run.prompt.audioUrl || '');
        if (!source) {
            return Promise.resolve(false);
        }

        const requestId = toInt(ctx.promptPlaybackRequestId) + 1;
        ctx.promptPlaybackRequestId = requestId;
        clearPromptReplayTimer(ctx);

        return waitForFeedbackQueue(ctx).then(function () {
            if ((ctx.promptPlaybackRequestId || 0) !== requestId) {
                return false;
            }
            if (!ctx.run || ctx.run !== run || run.paused || !run.prompt || !run.prompt.target || String(run.prompt.audioUrl || '') !== source) {
                return false;
            }

            const promptAudio = ensurePromptAudio(ctx);

            try {
                promptAudio.pause();
            } catch (_) { /* no-op */ }

            try {
                promptAudio.currentTime = 0;
            } catch (_) { /* no-op */ }

            const gameConfig = getGameConfig(ctx, run);
            promptAudio.volume = clamp(Number(gameConfig && gameConfig.promptAudioVolume) || 1, 0.05, 1);
            if (promptAudio.src !== source) {
                promptAudio.src = source;
            }

            updateReplayAudioUi(ctx, false);
            const playAttempt = promptAudio.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                return playAttempt.then(function () {
                    updateReplayAudioUi(ctx, true);
                    if (opts.allowAutoReplay) {
                        schedulePromptAutoReplay(ctx, run, source, requestId);
                    }
                    return true;
                }).catch(function () {
                    updateReplayAudioUi(ctx, false);
                    return false;
                });
            }

            updateReplayAudioUi(ctx, true);
            if (opts.allowAutoReplay) {
                schedulePromptAutoReplay(ctx, run, source, requestId);
            }
            return true;
        });
    }

    function updateHud(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        ctx.$coins.text(String(run.coins));
        ctx.$lives.text(String(run.lives));
    }

    function updatePauseUi(ctx) {
        const run = ctx.run;
        const isPaused = !!(run && run.paused);
        const pauseLabel = String(ctx.i18n.gamesPauseRun || 'Pause run');
        const resumeLabel = String(ctx.i18n.gamesResumeRun || 'Resume');

        if (ctx.$pauseButton && ctx.$pauseButton.length) {
            ctx.$pauseButton
                .toggleClass('is-paused', isPaused)
                .attr('aria-label', isPaused ? resumeLabel : pauseLabel);
        }
        if (ctx.$pauseIcon && ctx.$pauseIcon.length) {
            ctx.$pauseIcon.html(isPaused ? '&#9654;' : '&#10074;&#10074;');
        }
    }

    function resetRunControls(run) {
        run.controls.left = false;
        run.controls.right = false;
        run.controls.fire = false;
    }

    function clearControlUi(ctx) {
        ctx.$controls.removeClass('is-active');
    }

    function scrollStageIntoView(ctx) {
        const stage = ctx && ctx.$stage ? ctx.$stage.get(0) : null;
        if (!stage) {
            return;
        }

        const performScroll = function () {
            const currentScroll = Number(root.pageYOffset || root.scrollY || 0);
            const targetTop = Math.max(0, Math.round(stage.getBoundingClientRect().top + currentScroll - 16));

            if (typeof root.scrollTo === 'function') {
                try {
                    root.scrollTo({
                        top: targetTop,
                        behavior: 'smooth'
                    });
                    return;
                } catch (_) { /* no-op */ }

                try {
                    root.scrollTo(0, targetTop);
                    return;
                } catch (_) { /* no-op */ }
            }

            if (typeof stage.scrollIntoView === 'function') {
                try {
                    stage.scrollIntoView({
                        block: 'start',
                        inline: 'nearest',
                        behavior: 'smooth'
                    });
                    return;
                } catch (_) { /* no-op */ }

                stage.scrollIntoView(true);
            }
        };

        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(performScroll);
            return;
        }

        root.setTimeout(performScroll, 0);
    }

    function updateStageGameUi(ctx, slugOrRun) {
        const rawSlug = slugOrRun && typeof slugOrRun === 'object'
            ? slugOrRun.slug
            : slugOrRun;
        const gameSlug = String(rawSlug || '').trim() === ''
            ? ''
            : normalizeGameSlug(rawSlug);
        if (ctx && ctx.$stage && ctx.$stage.length) {
            ctx.$stage.attr('data-ll-wordset-active-game', gameSlug || '');
        }
        if (ctx && ctx.$controlsWrap && ctx.$controlsWrap.length) {
            ctx.$controlsWrap.prop('hidden', gameSlug === BUBBLE_POP_GAME_SLUG);
        }
        if (ctx && ctx.canvas && typeof ctx.canvas.setAttribute === 'function') {
            ctx.canvas.setAttribute('aria-label', gameSlug ? getBoardLabel(ctx, gameSlug) : String(ctx && ctx.i18n && ctx.i18n.gamesBoardLabelDefault || 'Wordset game board'));
        }
    }

    function setControlState(ctx, control, isActive) {
        const run = ctx.run;
        if (!run || run.paused || !run.controls || !Object.prototype.hasOwnProperty.call(run.controls, control)) {
            return;
        }
        if (isBubblePopRun(ctx, run)) {
            return;
        }
        run.controls[control] = !!isActive;
        ctx.$controls.filter('[data-ll-wordset-game-control="' + control + '"]').toggleClass('is-active', !!isActive);
    }

    function clearPromptTimer(run, preserveRemaining) {
        if (!run) {
            return;
        }

        if (run.promptTimer) {
            root.clearTimeout(run.promptTimer);
            run.promptTimer = 0;
        }

        if (preserveRemaining) {
            run.promptTimerRemainingMs = Math.max(
                0,
                run.promptTimerReadyAt > 0 ? run.promptTimerReadyAt - currentTimestamp() : run.promptTimerRemainingMs || 0
            );
            run.promptTimerReadyAt = 0;
            return;
        }

        run.promptTimerReadyAt = 0;
        run.promptTimerRemainingMs = 0;
    }

    function schedulePrompt(ctx, delayMs) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        clearPromptTimer(run, false);
        run.promptTimerRemainingMs = Math.max(0, delayMs || 0);
        run.promptTimerReadyAt = currentTimestamp() + run.promptTimerRemainingMs;
        run.promptTimer = root.setTimeout(function () {
            run.promptTimer = 0;
            run.promptTimerReadyAt = 0;
            run.promptTimerRemainingMs = 0;
            startNextPrompt(ctx);
        }, run.promptTimerRemainingMs);
    }

    function selectNextTarget(run) {
        if (!run.promptDeck.length) {
            run.promptDeck = shuffle(run.playableTargets);
        }
        while (run.promptDeck.length) {
            const candidate = run.promptDeck.shift();
            if (candidate) {
                return candidate;
            }
        }
        return null;
    }

    function refreshRunPlayableTargets(run) {
        if (!run) {
            return;
        }
        run.playableTargets = findPlayableTargets(run.words, run.cardCount);
        const playableLookup = {};
        run.playableTargets.forEach(function (word) {
            const wordId = toInt(word && word.id);
            if (wordId) {
                playableLookup[wordId] = true;
            }
        });
        run.promptDeck = run.promptDeck.filter(function (word) {
            return !!playableLookup[toInt(word && word.id)];
        });
        if (run.nextPreparedPrompt && !playableLookup[toInt(run.nextPreparedPrompt.target && run.nextPreparedPrompt.target.id)]) {
            run.nextPreparedPrompt = null;
        }
    }

    function removeWordFromRun(run, wordId) {
        const targetId = toInt(wordId);
        if (!run || !targetId) {
            return;
        }

        run.words = run.words.filter(function (word) {
            return toInt(word && word.id) !== targetId;
        });
        run.promptDeck = run.promptDeck.filter(function (word) {
            return toInt(word && word.id) !== targetId;
        });
        refreshRunPlayableTargets(run);
    }

    function buildPromptCandidate(ctx, run, targetWord) {
        const promptAudio = selectPromptAudio(targetWord, shuffle(getGamePromptRecordingTypes(targetWord)));
        if (!promptAudio.url) {
            return null;
        }

        const promptId = run.promptIdCounter + 1;
        const distractorMode = selectDistractorStrategy(run, targetWord, run.words);
        const cards = buildPromptCards(ctx, run, targetWord, run.words, promptId, distractorMode);
        if (!cards) {
            return null;
        }

        return {
            target: targetWord,
            promptId: promptId,
            audioUrl: promptAudio.url,
            recordingType: promptAudio.recordingType,
            cards: cards,
            distractorMode: distractorMode
        };
    }

    function preloadPromptCandidate(ctx, candidate) {
        if (!candidate || !candidate.target || !candidate.audioUrl) {
            return Promise.resolve({
                ready: false,
                failedWordIds: [],
                failedAudio: false
            });
        }

        const imageWords = candidate.cards.map(function (card) {
            return card.word;
        });

        return Promise.all([
            ensureAudioLoaded(ctx, candidate.audioUrl),
            Promise.all(imageWords.map(function (word) {
                return ensureWordImageLoaded(ctx, word).then(function (loaded) {
                    return {
                        wordId: toInt(word && word.id),
                        loaded: !!loaded
                    };
                });
            }))
        ]).then(function (results) {
            const audioReady = !!results[0];
            const imageResults = Array.isArray(results[1]) ? results[1] : [];
            const failedWordIds = imageResults.filter(function (entry) {
                return !entry.loaded && entry.wordId > 0;
            }).map(function (entry) {
                return entry.wordId;
            });

            return {
                ready: audioReady && failedWordIds.length === 0,
                failedWordIds: failedWordIds,
                failedAudio: !audioReady
            };
        });
    }

    function preparePromptCandidate(ctx, run) {
        const maxAttempts = Math.max(1, run.playableTargets.length);
        let chain = Promise.resolve(null);

        for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
            chain = chain.then(function (candidate) {
                if (candidate || !ctx.run || ctx.run !== run || run.ended) {
                    return candidate;
                }

                const targetWord = selectNextTarget(run);
                if (!targetWord) {
                    return null;
                }

                const nextCandidate = buildPromptCandidate(ctx, run, targetWord);
                if (!nextCandidate) {
                    return null;
                }

                return preloadPromptCandidate(ctx, nextCandidate).then(function (preload) {
                    if (preload.ready) {
                        return nextCandidate;
                    }

                    preload.failedWordIds.forEach(function (wordId) {
                        removeWordFromRun(run, wordId);
                    });
                    if (preload.failedAudio) {
                        removeWordFromRun(run, toInt(targetWord && targetWord.id));
                    }
                    return null;
                });
            });
        }

        return chain;
    }

    function queueNextPreparedPrompt(ctx, run) {
        if (!run || run.ended || run.nextPreparedPrompt || run.nextPromptPromise) {
            return null;
        }

        run.nextPromptPromise = preparePromptCandidate(ctx, run).then(function (candidate) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return null;
            }
            run.nextPreparedPrompt = candidate;
            return candidate;
        }).finally(function () {
            if (ctx.run === run) {
                run.nextPromptPromise = null;
            }
        });

        return run.nextPromptPromise;
    }

    function applyPreparedPrompt(ctx, run, candidate) {
        if (!run || !candidate || !candidate.target) {
            return false;
        }

        const gameConfig = getGameConfig(ctx, run);
        if (isBubblePopRun(ctx, run)) {
            placeBubblePromptCards(run, candidate.cards);
        }
        const targetCard = candidate.cards.find(function (card) {
            return !!card && card.isTarget;
        });
        const promptDurationMs = getLoadedAudioDurationMs(ctx, candidate.audioUrl);
        const safeLineRatio = clamp(Number(gameConfig && gameConfig.audioSafeLineRatio) || 0.6, 0.35, 0.7);
        const safeLineBufferMs = Math.max(0, toInt(gameConfig && gameConfig.audioSafeLineBufferMs) || 180);
        const safeLineY = isBubblePopRun(ctx, run)
            ? run.height * (1 - safeLineRatio)
            : run.height * safeLineRatio;
        let promptCardSpeed = run.cardSpeed;
        const targetRevealMs = targetCard ? getCardEntryRevealMs(ctx, targetCard) : 0;
        const targetVisibleY = targetCard ? getCardEntryVisibleY(ctx, run, targetCard) : 0;

        if (targetCard && promptDurationMs > 0) {
            const distanceToSafeLine = isBubblePopRun(ctx, run)
                ? Math.max(48, targetVisibleY - safeLineY)
                : Math.max(48, safeLineY - targetVisibleY);
            const minTravelSeconds = Math.max(0.1, ((promptDurationMs + safeLineBufferMs - targetRevealMs) / 1000));
            const maxSafeSpeed = distanceToSafeLine / minTravelSeconds;
            if (isFinite(maxSafeSpeed) && maxSafeSpeed > 0) {
                promptCardSpeed = Math.min(run.cardSpeed, maxSafeSpeed);
            }
        }

        const promptStartedAt = currentTimestamp();
        candidate.cards.forEach(function (card) {
            const entryRevealMs = getCardEntryRevealMs(ctx, card);
            const entryVisibleY = getCardEntryVisibleY(ctx, run, card);
            const hiddenDistance = Math.max(card.height * 0.9, promptCardSpeed * (entryRevealMs / 1000));
            const availableTravelSeconds = Math.max(0.1, ((promptDurationMs + safeLineBufferMs - entryRevealMs) / 1000));
            const maxSafeSpeed = isBubblePopRun(ctx, run)
                ? Math.max(48, entryVisibleY - safeLineY) / availableTravelSeconds
                : Math.max(48, safeLineY - entryVisibleY) / availableTravelSeconds;
            const variedCardSpeed = promptCardSpeed * Math.max(0.86, Number(card.fallSpeedFactor) || 1);
            card.entryRevealMs = entryRevealMs;
            card.entryStartedAt = promptStartedAt;
            card.entryVisibleX = isBubblePopRun(ctx, run)
                ? Number(card.bubbleVisibleX || card.entryVisibleX || card.x)
                : laneCenterX(run, card.laneIndex);
            card.entryStartX = isBubblePopRun(ctx, run)
                ? clamp(
                    Number(card.entryStartX || card.entryVisibleX || card.x),
                    getBubbleMovementBounds(run, getBubbleRadius(card)).minX,
                    getBubbleMovementBounds(run, getBubbleRadius(card)).maxX
                )
                : card.entryVisibleX;
            card.entryStartY = isBubblePopRun(ctx, run)
                ? entryVisibleY + hiddenDistance
                : entryVisibleY - hiddenDistance;
            card.entryVisibleY = entryVisibleY;
            if (isBubblePopRun(ctx, run)) {
                card.bubbleBaseX = card.entryVisibleX;
                card.bubbleBaseY = card.entryVisibleY;
                card.x = card.entryStartX;
            }
            card.y = card.entryStartY;
            card.speed = Math.min(variedCardSpeed, maxSafeSpeed);
        });
        const replayTiming = getPromptAutoReplayTiming(ctx, run, promptDurationMs, targetCard);

        run.promptIdCounter = candidate.promptId;
        run.prompt = {
            target: candidate.target,
            promptId: candidate.promptId,
            audioUrl: candidate.audioUrl,
            recordingType: candidate.recordingType,
            distractorMode: String(candidate.distractorMode || 'mixed'),
            cardSpeed: promptCardSpeed,
            audioDurationMs: promptDurationMs,
            autoReplayDelayMs: replayTiming.delayMs,
            autoReplayBaseDelayMs: replayTiming.baseDelayMs,
            safeLineCrossDelayMs: replayTiming.safeLineCrossDelayMs,
            autoReplaySafeLineGated: replayTiming.gatedBySafeLine,
            gameSlug: normalizeGameSlug(run.slug),
            hadWrongBefore: false,
            wrongCount: 0,
            wrongHitRecoveryUntil: 0,
            exposureTracked: false,
            hadUserActivity: false,
            activityFinalized: false,
            resolved: false
        };
        run.cards = run.cards.concat(candidate.cards);
        if (isBubblePopRun(ctx, run)) {
            refreshBubblePromptCardPositions(run, promptStartedAt);
        }
        playPromptAudio(ctx, {
            allowAutoReplay: true
        });
        queueNextPreparedPrompt(ctx, run);
        return true;
    }

    function activePromptId(run) {
        return toInt(run && run.prompt && run.prompt.promptId);
    }

    function isWrongHitRecoveryActive(run, now) {
        return !!(
            run
            && run.prompt
            && !run.prompt.resolved
            && Number(run.prompt.wrongHitRecoveryUntil || 0) > Number(now || 0)
        );
    }

    function isActivePromptCard(run, card) {
        return !!card && activePromptId(run) > 0 && toInt(card.promptId) === activePromptId(run);
    }

    function startNextPrompt(ctx) {
        const run = ctx.run;
        if (!run || run.ended || run.awaitingPrompt) {
            return;
        }

        run.awaitingPrompt = true;
        removeResolvedObjects(run, currentTimestamp());
        const preparedPromptPromise = run.nextPreparedPrompt
            ? Promise.resolve(run.nextPreparedPrompt)
            : (run.nextPromptPromise || preparePromptCandidate(ctx, run));

        preparedPromptPromise.then(function (candidate) {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }

            run.awaitingPrompt = false;
            run.nextPreparedPrompt = null;

            if (applyPreparedPrompt(ctx, run, candidate)) {
                hideOverlay(ctx);
                return;
            }

            endRun(ctx);
        }).catch(function () {
            if (!ctx.run || ctx.run !== run) {
                return;
            }
            run.awaitingPrompt = false;
            run.nextPreparedPrompt = null;
            endRun(ctx);
        });
    }

    function currentTimestamp() {
        return (root.performance && typeof root.performance.now === 'function') ? root.performance.now() : Date.now();
    }

    function getWrongHitRecoveryMs(ctx) {
        const gameConfig = getGameConfig(ctx, ctx && ctx.run);
        const fireIntervalMs = Math.max(80, toInt(gameConfig && gameConfig.fireIntervalMs) || 165);
        return Math.max(180, fireIntervalMs + 40);
    }

    function getRunEventSource(run) {
        return normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG
            ? 'bubble_pop'
            : 'space_shooter';
    }

    function markRunActivity(ctx) {
        const run = ctx && ctx.run;
        if (!run || run.paused || run.ended || !run.prompt || run.prompt.resolved) {
            return false;
        }

        run.prompt.hadUserActivity = true;
        return true;
    }

    function finalizePromptActivity(run) {
        if (!run || !run.prompt || run.prompt.activityFinalized) {
            return Math.max(0, toInt(run && run.inactiveRounds));
        }

        const hadUserActivity = !!run.prompt.hadUserActivity;
        run.inactiveRounds = hadUserActivity
            ? 0
            : Math.max(0, toInt(run.inactiveRounds)) + 1;
        run.lastResolvedPromptHadUserActivity = hadUserActivity;
        run.prompt.activityFinalized = true;
        return run.inactiveRounds;
    }

    function maybePauseForInactivity(ctx) {
        const run = ctx && ctx.run;
        if (!run || !run.prompt || !run.prompt.resolved || run.ended) {
            return false;
        }

        const inactiveRounds = finalizePromptActivity(run);
        if (inactiveRounds < INACTIVITY_ROUND_PAUSE_LIMIT) {
            return false;
        }

        pauseRun(ctx, {
            reason: PAUSE_REASON_INACTIVITY,
            resumeAction: RESUME_ACTION_NEXT_PROMPT,
            summary: formatMessage(
                ctx.i18n.gamesInactivePauseSummary || 'Paused after %d rounds without input.',
                [INACTIVITY_ROUND_PAUSE_LIMIT]
            )
        });
        return true;
    }

    function removeResolvedObjects(run, now) {
        run.cards = run.cards.filter(function (card) {
            if (card.resolvedFalling) {
                if (normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG) {
                    if ((card.y + (card.height / 2)) < -card.height) {
                        return false;
                    }
                } else if ((card.y - (card.height / 2)) > (run.height + card.height)) {
                    return false;
                }
            }
            return !(card.exploding && now >= card.removeAt);
        });
        run.explosions = run.explosions.filter(function (explosion) {
            return now < (explosion.startedAt + explosion.duration);
        });
    }

    function fireBullet(run) {
        if (normalizeGameSlug(run && run.slug) !== DEFAULT_GAME_SLUG) {
            return;
        }
        run.bullets.push({
            x: run.shipX,
            y: run.metrics.shipY - (run.metrics.shipHeight * 0.8),
            radius: 3,
            speed: run.metrics.bulletSpeed
        });
    }

    function getSteadyStateCardSpeed(run, promptsResolved) {
        const resolvedCount = Math.max(0, toInt(promptsResolved));
        const rampTurns = Math.max(1, toInt(run && run.speedRampTurns) || 10);
        const resolvedBeyondRamp = Math.max(0, resolvedCount - rampTurns);
        return clamp(
            86 + (Math.floor(resolvedBeyondRamp / 3) * 10),
            86,
            run.width < 480 ? 148 : 178
        );
    }

    function getRunCardSpeed(run) {
        const steadyStateSpeed = getSteadyStateCardSpeed(run, run && run.promptsResolved);
        const rampTurns = Math.max(1, toInt(run && run.speedRampTurns) || 10);
        const rampProgress = clamp((run && run.promptsResolved) / rampTurns, 0, 1);
        const introFactor = clamp(Number(run && run.speedRampStartFactor) || 0.5, 0.25, 0.95);
        const introSpeed = steadyStateSpeed * introFactor;

        if (rampProgress >= 1) {
            return steadyStateSpeed;
        }

        return introSpeed + ((steadyStateSpeed - introSpeed) * rampProgress);
    }

    function markPromptResolved(run) {
        if (!run.prompt || run.prompt.resolved) {
            return false;
        }
        run.prompt.resolved = true;
        run.promptsResolved += 1;
        run.cardSpeed = getRunCardSpeed(run);
        return true;
    }

    function releaseResolvedPromptCards(run, targetCard) {
        const targetPromptId = toInt(targetCard && targetCard.promptId);
        run.cards.forEach(function (entry) {
            if (!entry || entry === targetCard || entry.exploding || toInt(entry.promptId) !== targetPromptId) {
                return;
            }
            entry.resolvedFalling = true;
            entry.speed = Math.max(entry.speed * 3.3, run.cardSpeed * 3.6);
            if (normalizeGameSlug(run && run.slug) === BUBBLE_POP_GAME_SLUG) {
                entry.releaseDriftX = (Math.random() < 0.5 ? -1 : 1) * randomBetween(18, 44);
            }
        });
    }

    function handleCorrectHit(ctx, card) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        const isBubbleGame = isBubblePopRun(ctx, run);

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, true, !!run.prompt.hadWrongBefore, { event_source: getRunEventSource(run) });

        run.coins += Math.max(1, toInt(gameConfig && gameConfig.correctCoinReward) || 1);
        updateHud(ctx);
        card.exploding = true;
        card.explosionStyle = isBubbleGame ? 'bubble-correct' : 'correct';
        card.explosionDuration = isBubbleGame ? 280 : 220;
        card.removeAt = currentTimestamp() + card.explosionDuration;
        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: isBubbleGame ? (card.width * 0.98) : (card.width * 0.72),
            primaryColor: isBubbleGame ? 'rgba(154, 221, 255, 0.98)' : 'rgba(16, 185, 129, 0.96)',
            secondaryColor: isBubbleGame ? 'rgba(255, 255, 255, 0.9)' : 'rgba(103, 232, 249, 0.78)',
            duration: isBubbleGame ? 340 : 300,
            style: isBubbleGame ? 'bubble-pop' : 'ring',
            rayCount: isBubbleGame ? 10 : 0
        });

        markPromptResolved(run);
        releaseResolvedPromptCards(run, card);
        playFeedbackSound(ctx, 'correct').finally(function () {
            if (!ctx.run || ctx.run !== run || run.ended) {
                return;
            }
            if (maybePauseForInactivity(ctx)) {
                return;
            }
            startNextPrompt(ctx);
        });
    }

    function handleWrongHit(ctx, card) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        const isBubbleGame = isBubblePopRun(ctx, run);
        const now = currentTimestamp();
        if (isWrongHitRecoveryActive(run, now)) {
            return;
        }

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, false, false, { event_source: getRunEventSource(run), wrong_hit: true });
        run.prompt.hadWrongBefore = true;
        run.prompt.wrongCount += 1;
        run.prompt.wrongHitRecoveryUntil = now + getWrongHitRecoveryMs(ctx);

        run.lives = Math.max(0, run.lives - Math.max(1, toInt(gameConfig && gameConfig.wrongHitLifePenalty) || 1));
        run.coins = Math.max(0, run.coins - Math.max(0, toInt(gameConfig && gameConfig.wrongHitCoinPenalty)));
        run.bullets.length = 0;
        run.lastFireAt = now;
        setControlState(ctx, 'fire', false);
        updateHud(ctx);

        card.exploding = true;
        card.explosionStyle = isBubbleGame ? 'bubble-wrong' : 'dramatic';
        card.explosionDuration = isBubbleGame ? 520 : 420;
        card.removeAt = now + card.explosionDuration;
        if (isBubbleGame) {
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.52,
                primaryColor: 'rgba(255, 134, 105, 0.98)',
                secondaryColor: 'rgba(255, 241, 236, 0.88)',
                duration: 560,
                style: 'bubble-ray-burst',
                rayCount: 18,
                spin: 0.16
            });
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.9,
                primaryColor: 'rgba(255, 173, 153, 0.62)',
                secondaryColor: 'rgba(255, 255, 255, 0.54)',
                duration: 420,
                style: 'bubble-ray-burst',
                rayCount: 10,
                spin: -0.12
            });
        } else {
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.12,
                primaryColor: 'rgba(248, 113, 113, 0.98)',
                secondaryColor: 'rgba(251, 191, 36, 0.92)',
                duration: 520,
                style: 'burst',
                rayCount: 18,
                spin: 0.18
            });
            spawnExplosion(run, {
                x: card.x + ((Math.random() * 18) - 9),
                y: card.y + ((Math.random() * 18) - 9),
                radius: Math.max(card.width, card.height) * 0.82,
                primaryColor: 'rgba(255, 255, 255, 0.94)',
                secondaryColor: 'rgba(249, 115, 22, 0.9)',
                duration: 360,
                style: 'burst',
                rayCount: 12,
                spin: -0.22
            });
            spawnExplosion(run, {
                x: card.x,
                y: card.y,
                radius: Math.max(card.width, card.height) * 1.34,
                primaryColor: 'rgba(255, 214, 10, 0.7)',
                secondaryColor: 'rgba(248, 113, 113, 0.55)',
                duration: 420,
                style: 'ring'
            });
        }

        const feedbackPlayback = playFeedbackSound(ctx, 'wrong');
        if (run.lives <= 0) {
            markPromptResolved(run);
            resetRunControls(run);
            clearControlUi(ctx);
            feedbackPlayback.finally(function () {
                if (ctx.run === run) {
                    endRun(ctx);
                }
            });
        }
    }

    function handlePromptTimeout(ctx) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }
        const gameConfig = getGameConfig(ctx, run);
        pausePromptAudio(ctx);
        const untouchedTimeout = !run.prompt.hadWrongBefore;

        if (untouchedTimeout) {
            queueExposureOnce(ctx, run.prompt);
            queueOutcome(ctx, run.prompt, false, false, {
                event_source: getRunEventSource(run),
                timeout: true
            });
        }

        run.coins = Math.max(0, run.coins - Math.max(0, toInt(gameConfig && gameConfig.timeoutCoinPenalty) || 1));
        if (!untouchedTimeout && !isSpaceShooterRun(ctx, run)) {
            run.lives = Math.max(0, run.lives - Math.max(0, toInt(gameConfig && gameConfig.timeoutLifePenalty) || 1));
        }
        updateHud(ctx);
        markPromptResolved(run);
        run.cards = run.cards.filter(function (card) {
            return !isActivePromptCard(run, card);
        });
        if (run.lives <= 0) {
            run.ended = true;
            root.setTimeout(function () {
                endRun(ctx);
            }, 220);
            return;
        }
        if (maybePauseForInactivity(ctx)) {
            return;
        }
        schedulePrompt(ctx, 220);
    }

    function findTargetCard(run) {
        for (let index = 0; index < run.cards.length; index += 1) {
            if (run.cards[index] && run.cards[index].isTarget && !run.cards[index].exploding && isActivePromptCard(run, run.cards[index])) {
                return run.cards[index];
            }
        }
        return null;
    }

    function getCanvasPoint(ctx, event) {
        if (!ctx || !ctx.canvas || !ctx.run) {
            return null;
        }

        const rect = ctx.canvas.getBoundingClientRect();
        if (!rect || rect.width <= 0 || rect.height <= 0) {
            return null;
        }

        const originalEvent = event && event.originalEvent ? event.originalEvent : event;
        const touch = originalEvent && originalEvent.touches && originalEvent.touches.length
            ? originalEvent.touches[0]
            : (originalEvent && originalEvent.changedTouches && originalEvent.changedTouches.length
                ? originalEvent.changedTouches[0]
                : originalEvent);
        if (!touch) {
            return null;
        }

        return {
            x: (Number(touch.clientX || 0) - rect.left) * (ctx.run.width / rect.width),
            y: (Number(touch.clientY || 0) - rect.top) * (ctx.run.height / rect.height)
        };
    }

    function findBubbleCardAtPoint(run, point) {
        if (!run || !point) {
            return null;
        }

        for (let index = run.cards.length - 1; index >= 0; index -= 1) {
            const card = run.cards[index];
            if (!card || card.exploding || card.resolvedFalling || !isActivePromptCard(run, card)) {
                continue;
            }

            const radius = getBubbleRadius(card) * 1.04;
            const dx = Number(point.x) - Number(card.x);
            const dy = Number(point.y) - Number(card.y);
            if ((dx * dx) + (dy * dy) <= radius * radius) {
                return card;
            }
        }

        return null;
    }

    function findDecorativeBubbleAtPoint(run, point) {
        if (!run || !point) {
            return null;
        }

        for (let index = run.decorativeBubbles.length - 1; index >= 0; index -= 1) {
            const bubble = run.decorativeBubbles[index];
            if (!bubble || bubble.exploding) {
                continue;
            }

            const radius = getFloatingBodyRadius(bubble) * 1.08;
            const dx = Number(point.x) - Number(bubble.x);
            const dy = Number(point.y) - Number(bubble.y);
            if ((dx * dx) + (dy * dy) <= radius * radius) {
                return bubble;
            }
        }

        return null;
    }

    function handleCanvasPress(ctx, event) {
        const run = ctx.run;
        if (!run || run.paused || !isBubblePopRun(ctx, run)) {
            return false;
        }

        const point = getCanvasPoint(ctx, event);
        if (!point) {
            return false;
        }

        if (run.prompt && !run.prompt.resolved) {
            const card = findBubbleCardAtPoint(run, point);
            if (card) {
                if (card.isTarget) {
                    handleCorrectHit(ctx, card);
                } else {
                    handleWrongHit(ctx, card);
                }
                registerPromptActivity(run);
                return true;
            }
        }

        const decorativeBubble = findDecorativeBubbleAtPoint(run, point);
        if (decorativeBubble) {
            popDecorativeBubble(run, decorativeBubble);
            registerPromptActivity(run);
            return true;
        }

        return false;
    }

    function stepRun(ctx, now, dtMs) {
        const run = ctx.run;
        if (!run || !run.prompt) {
            return;
        }

        const dt = Math.min(40, Math.max(0, dtMs || 0)) / 1000;
        if (isBubblePopRun(ctx, run)) {
            run.cards.forEach(function (card) {
                if (!card || card.exploding) {
                    return;
                }

                if (!card.resolvedFalling && card.entryRevealMs > 0) {
                    const elapsedMs = Math.max(0, now - Number(card.entryStartedAt || 0));
                    if (elapsedMs < card.entryRevealMs) {
                        const easedProgress = easeOutCubic(elapsedMs / card.entryRevealMs);
                        setFloatingBodyPosition(run, card, lerp(card.entryStartX, card.entryVisibleX, easedProgress), lerp(card.entryStartY, card.entryVisibleY, easedProgress), {
                            clampY: false
                        });
                        return;
                    }

                    card.entryRevealMs = 0;
                    card.bubbleBaseX = Number(card.entryVisibleX);
                    card.bubbleBaseY = Number(card.entryVisibleY);
                }

                if (card.resolvedFalling) {
                    card.bubbleBaseY = Number(card.bubbleBaseY || card.y) - (card.speed * dt);
                    card.bubbleBaseX = Number(card.bubbleBaseX || card.x) + (Number(card.releaseDriftX) * dt);
                    return;
                }

                const bounds = getBubbleMovementBounds(run, getBubbleRadius(card));
                card.bubbleBaseY = Number(card.bubbleBaseY || card.entryVisibleY || card.y) - (card.speed * dt);
                card.bubbleBaseX = Number(card.bubbleBaseX || card.entryVisibleX || card.x) + (Number(card.bubbleWanderVelocityX) * dt);
                if (card.bubbleBaseX <= bounds.minX || card.bubbleBaseX >= bounds.maxX) {
                    card.bubbleWanderVelocityX *= -1;
                    card.bubbleBaseX = clamp(card.bubbleBaseX, bounds.minX, bounds.maxX);
                }
            });

            refreshBubblePromptCardPositions(run, now);
            updateDecorativeBubblePositions(run, now, dt);
        } else {
            const gameConfig = getGameConfig(ctx, run);
            const direction = (run.controls.right ? 1 : 0) - (run.controls.left ? 1 : 0);
            if (direction !== 0) {
                run.shipX = clamp(
                    run.shipX + (direction * run.metrics.shipSpeed * dt),
                    run.metrics.shipWidth / 2,
                    run.width - (run.metrics.shipWidth / 2)
                );
            }

            if (run.controls.fire && (now - run.lastFireAt) >= Math.max(80, toInt(gameConfig && gameConfig.fireIntervalMs) || 165)) {
                fireBullet(run);
                run.lastFireAt = now;
            }

            run.bullets.forEach(function (bullet) {
                bullet.y -= bullet.speed * dt;
            });
            run.bullets = run.bullets.filter(function (bullet) {
                return bullet.y > -16;
            });

            run.cards.forEach(function (card) {
                if (!card.exploding) {
                    if (!card.resolvedFalling && card.entryRevealMs > 0) {
                        const elapsedMs = Math.max(0, now - Number(card.entryStartedAt || 0));
                        if (elapsedMs < card.entryRevealMs) {
                            const easedProgress = easeOutCubic(elapsedMs / card.entryRevealMs);
                            card.y = card.entryStartY + ((card.entryVisibleY - card.entryStartY) * easedProgress);
                            return;
                        }
                        card.entryRevealMs = 0;
                        card.y = card.entryVisibleY;
                    }
                    card.y += card.speed * dt;
                }
            });
        }

        let collisionHandled = false;
        if (!run.prompt.resolved && isSpaceShooterRun(ctx, run)) {
            outerLoop:
            for (let bulletIndex = run.bullets.length - 1; bulletIndex >= 0; bulletIndex -= 1) {
                const bullet = run.bullets[bulletIndex];
                for (let cardIndex = 0; cardIndex < run.cards.length; cardIndex += 1) {
                    const card = run.cards[cardIndex];
                    if (!card || card.exploding || card.resolvedFalling || !isActivePromptCard(run, card)) {
                        continue;
                    }
                    const hit = bullet.x >= (card.x - (card.width / 2))
                        && bullet.x <= (card.x + (card.width / 2))
                        && bullet.y >= (card.y - (card.height / 2))
                        && bullet.y <= (card.y + (card.height / 2));
                    if (!hit) {
                        continue;
                    }

                    run.bullets.splice(bulletIndex, 1);
                    if (card.isTarget) {
                        handleCorrectHit(ctx, card);
                    } else {
                        handleWrongHit(ctx, card);
                    }
                    registerPromptActivity(run);
                    collisionHandled = true;
                    break outerLoop;
                }
            }
        }

        if (collisionHandled) {
            removeResolvedObjects(run, now);
            return;
        }

        const targetCard = findTargetCard(run);
        if (!run.prompt.resolved && !isWrongHitRecoveryActive(run, now)) {
            if (!targetCard) {
                handlePromptTimeout(ctx);
            } else if (
                (isBubblePopRun(ctx, run) && (targetCard.y + (targetCard.height / 2)) < 0)
                || (isSpaceShooterRun(ctx, run) && (targetCard.y - (targetCard.height / 2)) > run.height)
            ) {
                handlePromptTimeout(ctx);
            }
        }

        removeResolvedObjects(run, now);
    }

    function runLoop(ctx, timestamp) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        if (!run.lastFrameAt) {
            run.lastFrameAt = timestamp;
        }

        if (run.paused) {
            renderRun(ctx, timestamp);
            run.lastFrameAt = timestamp;
            run.rafId = root.requestAnimationFrame(function (nextFrameAt) {
                runLoop(ctx, nextFrameAt);
            });
            return;
        }

        stepRun(ctx, timestamp, timestamp - run.lastFrameAt);
        renderRun(ctx, timestamp);
        run.lastFrameAt = timestamp;
        run.rafId = root.requestAnimationFrame(function (nextFrameAt) {
            runLoop(ctx, nextFrameAt);
        });
    }

    function showOverlay(ctx, title, summary, options) {
        const opts = options || {};
        const summaryText = String(summary || '');
        const primaryLabel = Object.prototype.hasOwnProperty.call(opts, 'primaryLabel')
            ? String(opts.primaryLabel || '')
            : String(ctx.i18n.gamesReplayRun || 'Replay');
        const secondaryLabel = Object.prototype.hasOwnProperty.call(opts, 'secondaryLabel')
            ? String(opts.secondaryLabel || '')
            : String(ctx.i18n.gamesBackToCatalog || 'Back to games');

        ctx.overlayMode = String(opts.mode || '');
        ctx.$overlayTitle.text(title);
        ctx.$overlaySummary.text(summaryText).prop('hidden', summaryText === '');
        if (ctx.$overlayPrimary && ctx.$overlayPrimary.length) {
            ctx.$overlayPrimary.text(primaryLabel).prop('hidden', primaryLabel === '');
        }
        if (ctx.$overlaySecondary && ctx.$overlaySecondary.length) {
            ctx.$overlaySecondary.text(secondaryLabel).prop('hidden', secondaryLabel === '');
        }
        ctx.$overlay.prop('hidden', false);
    }

    function hideOverlay(ctx) {
        ctx.overlayMode = '';
        if (ctx.$overlayPrimary && ctx.$overlayPrimary.length) {
            ctx.$overlayPrimary.prop('hidden', false);
        }
        if (ctx.$overlaySecondary && ctx.$overlaySecondary.length) {
            ctx.$overlaySecondary.prop('hidden', false);
        }
        ctx.$overlay.prop('hidden', true);
    }

    function pauseRun(ctx, options) {
        const opts = (options && typeof options === 'object') ? options : {};
        const run = ctx.run;
        if (!run || run.ended || run.paused) {
            return;
        }

        run.paused = true;
        clearPromptTimer(run, opts.preservePromptTimer !== false);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        run.resumeAction = String(opts.resumeAction || '');
        if (!run.resumeAction && (!run.prompt || run.prompt.resolved)) {
            run.resumeAction = RESUME_ACTION_NEXT_PROMPT;
        }
        run.pauseReason = String(opts.reason || 'manual');
        updatePauseUi(ctx);
        showOverlay(
            ctx,
            Object.prototype.hasOwnProperty.call(opts, 'title')
                ? String(opts.title || '')
                : String(ctx.i18n.gamesPaused || 'Paused'),
            Object.prototype.hasOwnProperty.call(opts, 'summary')
                ? String(opts.summary || '')
                : '',
            {
                mode: 'paused',
                primaryLabel: String(ctx.i18n.gamesResumeRun || 'Resume'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function resumeRun(ctx) {
        const run = ctx.run;
        if (!run || !run.paused) {
            return;
        }

        const resumeAction = String(run.resumeAction || '');
        const pauseReason = String(run.pauseReason || '');
        run.paused = false;
        run.lastFrameAt = 0;
        run.resumeAction = '';
        run.pauseReason = '';
        if (pauseReason === PAUSE_REASON_INACTIVITY) {
            run.inactiveRounds = 0;
        }
        hideOverlay(ctx);
        updatePauseUi(ctx);

        if (resumeAction === RESUME_ACTION_NEXT_PROMPT) {
            startNextPrompt(ctx);
            return;
        }

        if (run.promptTimerRemainingMs > 0) {
            schedulePrompt(ctx, run.promptTimerRemainingMs);
            return;
        }

        if (run.prompt && !run.prompt.resolved) {
            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        }
    }

    function endRun(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        run.ended = true;
        run.awaitingPrompt = false;
        run.nextPreparedPrompt = null;
        run.nextPromptPromise = null;
        if (run.rafId) {
            root.cancelAnimationFrame(run.rafId);
            run.rafId = 0;
        }
        run.paused = false;
        run.resumeAction = '';
        run.pauseReason = '';
        clearPromptTimer(run, false);
        resetRunControls(run);
        clearControlUi(ctx);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        updatePauseUi(ctx);
        flushProgress(ctx);

        const summary = formatMessage(ctx.i18n.gamesSummary || 'Coins: %1$d · Prompts: %2$d', [
            run.coins,
            run.promptsResolved
        ]);
        showOverlay(
            ctx,
            String(ctx.i18n.gamesGameOver || 'Run Complete'),
            summary,
            {
                mode: 'game-over',
                primaryLabel: String(ctx.i18n.gamesReplayRun || 'Replay'),
                secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
            }
        );
    }

    function stopRun(ctx, options) {
        const opts = options || {};
        const run = ctx.run;
        if (!run) {
            return;
        }

        if (run.rafId) {
            root.cancelAnimationFrame(run.rafId);
            run.rafId = 0;
        }
        run.paused = false;
        run.awaitingPrompt = false;
        run.nextPreparedPrompt = null;
        run.nextPromptPromise = null;
        run.resumeAction = '';
        run.pauseReason = '';
        clearPromptTimer(run, false);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        if (opts.flush !== false) {
            flushProgress(ctx);
        }
        ctx.run = null;
        updatePauseUi(ctx);
    }

    function showCatalog(ctx) {
        stopRun(ctx, { flush: true });
        hideOverlay(ctx);
        ctx.activeGameSlug = '';
        updateStageGameUi(ctx, '');
        ctx.$stage.prop('hidden', true);
        ctx.$catalog.prop('hidden', false);
        syncCanvasSize(ctx);
    }

    function startRun(ctx, entry) {
        const gameSlug = normalizeGameSlug(entry && entry.slug);
        const gameConfig = getGameConfig(ctx, gameSlug);
        if (!gameConfig) {
            return;
        }
        showCatalog(ctx);
        ctx.$catalog.prop('hidden', true);
        ctx.$stage.prop('hidden', false);
        ctx.activeGameSlug = gameSlug;
        updateStageGameUi(ctx, gameSlug);
        showOverlay(ctx, String(ctx.i18n.gamesPreparingRun || 'Preparing game...'), '', {
            mode: 'loading',
            primaryLabel: '',
            secondaryLabel: ''
        });

        ctx.run = {
            slug: gameSlug,
            words: entry.words.slice(),
            playableTargets: shuffle(entry.playableTargets.slice()),
            promptDeck: [],
            prompt: null,
            cards: [],
            bullets: [],
            explosions: [],
            decorativeBubbles: [],
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: gameConfig.lives,
            promptsResolved: 0,
            lastFireAt: 0,
            lastFrameAt: 0,
            shipX: 0,
            shipY: 0,
            width: 720,
            height: 960,
            dpr: 1,
            metrics: null,
            stars: [],
            cardCount: gameConfig.cardCount,
            cardSpeed: 86,
            promptIdCounter: 0,
            promptTimer: 0,
            promptTimerReadyAt: 0,
            promptTimerRemainingMs: 0,
            decorativeBubbleIdCounter: 0,
            speedRampTurns: gameConfig.introRampTurns,
            speedRampStartFactor: gameConfig.introRampStartFactor,
            useSameCategoryDistractorsNext: false,
            awaitingPrompt: false,
            nextPreparedPrompt: null,
            nextPromptPromise: null,
            inactiveRounds: 0,
            lastResolvedPromptHadUserActivity: false,
            resumeAction: '',
            pauseReason: '',
            paused: false,
            ended: false,
            rafId: 0
        };

        syncCanvasSize(ctx);
        ctx.run.cardSpeed = getRunCardSpeed(ctx.run);
        ctx.run.shipX = ctx.run.width / 2;
        ctx.run.shipY = ctx.run.metrics.shipY;
        ctx.run.stars = createStageStars(ctx.run);
        updateHud(ctx);
        updatePauseUi(ctx);
        setTrackerContext(ctx);
        scrollStageIntoView(ctx);
        startNextPrompt(ctx);
        ctx.run.rafId = root.requestAnimationFrame(function (timestamp) {
            runLoop(ctx, timestamp);
        });
    }

    function bootstrapCatalog(ctx) {
        renderAllCatalogCards(ctx, null, true);
        if (!ctx.isLoggedIn || !ctx.ajaxUrl || !ctx.wordsetId || !ctx.bootstrapAction) {
            renderAllCatalogCards(ctx, null, false);
            return;
        }

        if (ctx.bootstrapRequest && typeof ctx.bootstrapRequest.abort === 'function') {
            ctx.bootstrapRequest.abort();
        }

        ctx.bootstrapRequest = $.post(ctx.ajaxUrl, {
            action: ctx.bootstrapAction,
            nonce: ctx.nonce,
            wordset_id: ctx.wordsetId
        }).done(function (response) {
            const payload = response && response.success && response.data && typeof response.data === 'object'
                ? response.data
                : null;
            if (!payload || !payload.games || typeof payload.games !== 'object') {
                renderAllCatalogCards(ctx, null, false);
                return;
            }

            const nextEntries = {};
            Object.keys(ctx.catalogCards || {}).forEach(function (slug) {
                nextEntries[slug] = buildPreparedEntry(ctx, slug, payload.games[slug] || {});
            });
            ctx.catalogEntries = nextEntries;
            ctx.catalogEntry = nextEntries[getDefaultCatalogSlug(ctx)] || null;
            renderAllCatalogCards(ctx, nextEntries, false);
        }).fail(function () {
            renderAllCatalogCards(ctx, null, false);
        });
    }

    function bindLifecycle(ctx) {
        if (ctx.boundLifecycle) {
            return;
        }

        function matchesKey(event, keys, codes) {
            const key = String(event && event.key || '').toLowerCase();
            const code = String(event && event.code || '').toLowerCase();
            return keys.indexOf(key) !== -1 || codes.indexOf(code) !== -1;
        }

        ctx.onVisibilityChange = function () {
            if (root.document && root.document.visibilityState === 'hidden') {
                flushProgress(ctx);
            }
        };
        ctx.onPageHide = function () {
            flushProgress(ctx);
        };
        ctx.onResize = function () {
            syncCanvasSize(ctx);
        };
        ctx.onKeyDown = function (event) {
            if (!ctx.run || ctx.run.paused || ctx.$stage.prop('hidden') || isBubblePopRun(ctx, ctx.run)) {
                return;
            }
            if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'left', true);
            } else if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'right', true);
            } else if (matchesKey(event, [' ', 'space', 'spacebar'], ['space'])) {
                event.preventDefault();
                if (!event.repeat) {
                    markRunActivity(ctx);
                }
                setControlState(ctx, 'fire', true);
            }
        };
        ctx.onKeyUp = function (event) {
            if (!ctx.run || ctx.run.paused || isBubblePopRun(ctx, ctx.run)) {
                return;
            }
            if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
                setControlState(ctx, 'left', false);
            } else if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
                setControlState(ctx, 'right', false);
            } else if (matchesKey(event, [' ', 'space', 'spacebar'], ['space'])) {
                setControlState(ctx, 'fire', false);
            }
        };

        if (root.document && root.document.addEventListener) {
            root.document.addEventListener('visibilitychange', ctx.onVisibilityChange);
        }
        if (root.addEventListener) {
            root.addEventListener('pagehide', ctx.onPageHide);
            root.addEventListener('resize', ctx.onResize);
            root.addEventListener('keydown', ctx.onKeyDown);
            root.addEventListener('keyup', ctx.onKeyUp);
        }
        ctx.boundLifecycle = true;
    }

    function unbindLifecycle(ctx) {
        if (!ctx || !ctx.boundLifecycle) {
            return;
        }
        if (root.document && root.document.removeEventListener && ctx.onVisibilityChange) {
            root.document.removeEventListener('visibilitychange', ctx.onVisibilityChange);
        }
        if (root.removeEventListener) {
            if (ctx.onPageHide) {
                root.removeEventListener('pagehide', ctx.onPageHide);
            }
            if (ctx.onResize) {
                root.removeEventListener('resize', ctx.onResize);
            }
            if (ctx.onKeyDown) {
                root.removeEventListener('keydown', ctx.onKeyDown);
            }
            if (ctx.onKeyUp) {
                root.removeEventListener('keyup', ctx.onKeyUp);
            }
        }
        ctx.boundLifecycle = false;
    }

    function bindDom(ctx) {
        ctx.$page.off(MODULE_NS);
        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-launch]', function (event) {
            event.preventDefault();
            const slug = normalizeGameSlug($(this).closest('[data-ll-wordset-game-card]').attr('data-game-slug') || '');
            const entry = ctx.catalogEntries[slug] || null;
            if (!entry || !entry.launchable) {
                return;
            }
            startRun(ctx, entry);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-close], [data-ll-wordset-game-return]', function (event) {
            event.preventDefault();
            showCatalog(ctx);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-replay]', function (event) {
            event.preventDefault();
            if (ctx.overlayMode === 'paused') {
                resumeRun(ctx);
                return;
            }
            const replaySlug = normalizeGameSlug(ctx.activeGameSlug || getDefaultCatalogSlug(ctx));
            const entry = ctx.catalogEntries[replaySlug]
                || (replaySlug === getDefaultCatalogSlug(ctx) ? ctx.catalogEntry : null);
            if (!entry || !entry.launchable) {
                return;
            }
            stopRun(ctx, { flush: true });
            startRun(ctx, entry);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-replay-audio]', function (event) {
            event.preventDefault();
            if (ctx.run && !ctx.run.paused) {
                markRunActivity(ctx);
            }
            playPromptAudio(ctx, {
                allowAutoReplay: false
            });
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-pause-toggle]', function (event) {
            event.preventDefault();
            if (!ctx.run) {
                return;
            }
            if (ctx.run.paused) {
                resumeRun(ctx);
                return;
            }
            pauseRun(ctx);
        });

        const pointerControls = 'pointerdown' + MODULE_NS + ' mousedown' + MODULE_NS + ' touchstart' + MODULE_NS;
        const pointerRelease = 'pointerup' + MODULE_NS + ' mouseup' + MODULE_NS + ' mouseleave' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS;

        ctx.$page.on(pointerControls, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.paused) {
                return;
            }
            markRunActivity(ctx);
            const control = String($(this).attr('data-ll-wordset-game-control') || '');
            setControlState(ctx, control, true);
        });
        ctx.$page.on(pointerRelease, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
            if (!ctx.run || ctx.run.paused) {
                return;
            }
            const control = String($(this).attr('data-ll-wordset-game-control') || '');
            setControlState(ctx, control, false);
        });

        ctx.$page.on('pointerdown' + MODULE_NS + ' mousedown' + MODULE_NS + ' touchstart' + MODULE_NS, '[data-ll-wordset-game-canvas]', function (event) {
            if (!ctx.run || ctx.run.paused || !isBubblePopRun(ctx, ctx.run)) {
                return;
            }
            markRunActivity(ctx);
            if (handleCanvasPress(ctx, event)) {
                event.preventDefault();
            }
        });

        $(root.document).off('mouseup' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS).on(
            'mouseup' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS,
            function () {
                if (!ctx.run) {
                    return;
                }
                setControlState(ctx, 'left', false);
                setControlState(ctx, 'right', false);
                setControlState(ctx, 'fire', false);
            }
        );
    }

    function buildGameConfig(rawConfig, defaults) {
        const cfg = (rawConfig && typeof rawConfig === 'object') ? rawConfig : {};
        const fallback = (defaults && typeof defaults === 'object') ? defaults : {};
        return {
            slug: normalizeGameSlug(cfg.slug || fallback.slug),
            lives: Math.max(1, toInt(cfg.lives) || toInt(fallback.lives) || 3),
            cardCount: Math.max(2, toInt(cfg.cardCount) || toInt(fallback.cardCount) || 4),
            maxLoadedWords: Math.max(5, toInt(cfg.maxLoadedWords) || toInt(fallback.maxLoadedWords) || 60),
            fireIntervalMs: Math.max(0, toInt(cfg.fireIntervalMs) || toInt(fallback.fireIntervalMs)),
            introRampTurns: Math.max(1, toInt(cfg.introRampTurns) || toInt(fallback.introRampTurns) || 10),
            introRampStartFactor: clamp(Number(cfg.introRampStartFactor) || Number(fallback.introRampStartFactor) || 0.5, 0.25, 0.95),
            audioSafeLineRatio: clamp(Number(cfg.audioSafeLineRatio) || Number(fallback.audioSafeLineRatio) || 0.6, 0.2, 0.8),
            audioSafeLineBufferMs: Math.max(0, toInt(cfg.audioSafeLineBufferMs) || toInt(fallback.audioSafeLineBufferMs) || 180),
            correctCoinReward: Math.max(1, toInt(cfg.correctCoinReward) || toInt(fallback.correctCoinReward) || 1),
            wrongHitCoinPenalty: Math.max(0, toInt(cfg.wrongHitCoinPenalty) || toInt(fallback.wrongHitCoinPenalty)),
            wrongHitLifePenalty: Math.max(1, toInt(cfg.wrongHitLifePenalty) || toInt(fallback.wrongHitLifePenalty) || 1),
            timeoutCoinPenalty: Math.max(0, toInt(cfg.timeoutCoinPenalty) || toInt(fallback.timeoutCoinPenalty) || 1),
            timeoutLifePenalty: Math.max(0, toInt(cfg.timeoutLifePenalty) || toInt(fallback.timeoutLifePenalty) || 1),
            assetPreloadTimeoutMs: Math.max(1500, toInt(cfg.assetPreloadTimeoutMs) || toInt(fallback.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS),
            cardEntryRevealMs: Math.max(220, toInt(cfg.cardEntryRevealMs) || toInt(fallback.cardEntryRevealMs) || 560),
            promptAutoReplayGapMs: Math.max(220, toInt(cfg.promptAutoReplayGapMs) || toInt(fallback.promptAutoReplayGapMs) || 420),
            promptAudioVolume: clamp(Number(cfg.promptAudioVolume) || Number(fallback.promptAudioVolume) || 1, 0.05, 1),
            correctHitVolume: clamp(Number(cfg.correctHitVolume) || Number(fallback.correctHitVolume) || 0.28, 0.05, 1),
            wrongHitVolume: clamp(Number(cfg.wrongHitVolume) || Number(fallback.wrongHitVolume) || 0.2, 0.05, 1),
            correctHitAudioSources: normalizeUrlList(cfg.correctHitAudioSources || cfg.correctHitAudioUrl || fallback.correctHitAudioSources || fallback.correctHitAudioUrl),
            wrongHitAudioSources: normalizeUrlList(cfg.wrongHitAudioSources || cfg.wrongHitAudioUrl || fallback.wrongHitAudioSources || fallback.wrongHitAudioUrl)
        };
    }

    function createContext(rootEl, cfg) {
        const $page = $(rootEl);
        const $gamesRoot = $page.find('[data-ll-wordset-games-root]').first();
        if (!$gamesRoot.length) {
            return null;
        }

        const gamesCfg = (cfg.games && typeof cfg.games === 'object') ? cfg.games : {};
        const spaceShooter = (gamesCfg.spaceShooter && typeof gamesCfg.spaceShooter === 'object')
            ? gamesCfg.spaceShooter
            : {};
        const bubblePop = (gamesCfg.bubblePop && typeof gamesCfg.bubblePop === 'object')
            ? gamesCfg.bubblePop
            : {};
        const catalogCards = {};
        const catalogOrder = [];
        const $allCards = $gamesRoot.find('[data-ll-wordset-game-card]');
        $allCards.each(function () {
            const $card = $(this);
            const slug = normalizeGameSlug($card.attr('data-game-slug') || '');
            if (!slug || catalogCards[slug]) {
                return;
            }
            catalogOrder.push(slug);
            catalogCards[slug] = {
                slug: slug,
                $card: $card,
                $status: $card.find('[data-ll-wordset-game-status]').first(),
                $count: $card.find('[data-ll-wordset-game-count]').first(),
                $launchButton: $card.find('[data-ll-wordset-game-launch]').first()
            };
        });
        const defaultCatalogSlug = catalogCards[DEFAULT_GAME_SLUG]
            ? DEFAULT_GAME_SLUG
            : (catalogOrder[0] || DEFAULT_GAME_SLUG);
        const defaultCard = catalogCards[defaultCatalogSlug] || null;
        const $canvas = $gamesRoot.find('[data-ll-wordset-game-canvas]').first();
        const gameConfigs = {};
        gameConfigs[DEFAULT_GAME_SLUG] = buildGameConfig(spaceShooter, {
            slug: DEFAULT_GAME_SLUG,
            lives: 3,
            cardCount: 4,
            maxLoadedWords: 60,
            fireIntervalMs: 165,
            introRampTurns: 10,
            introRampStartFactor: 0.5,
            audioSafeLineRatio: 0.6,
            audioSafeLineBufferMs: 180,
            correctCoinReward: 1,
            wrongHitCoinPenalty: 0,
            wrongHitLifePenalty: 1,
            timeoutCoinPenalty: 1,
            timeoutLifePenalty: 1,
            assetPreloadTimeoutMs: ASSET_PRELOAD_TIMEOUT_MS,
            cardEntryRevealMs: 560,
            promptAutoReplayGapMs: 420,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2
        });
        gameConfigs[BUBBLE_POP_GAME_SLUG] = buildGameConfig(bubblePop, {
            slug: BUBBLE_POP_GAME_SLUG,
            lives: 3,
            cardCount: 4,
            maxLoadedWords: 60,
            introRampTurns: 10,
            introRampStartFactor: 0.5,
            audioSafeLineRatio: 0.58,
            audioSafeLineBufferMs: 180,
            correctCoinReward: 1,
            wrongHitCoinPenalty: 0,
            wrongHitLifePenalty: 1,
            timeoutCoinPenalty: 1,
            timeoutLifePenalty: 1,
            assetPreloadTimeoutMs: ASSET_PRELOAD_TIMEOUT_MS,
            cardEntryRevealMs: 520,
            promptAutoReplayGapMs: 420,
            promptAudioVolume: 1,
            correctHitVolume: 0.28,
            wrongHitVolume: 0.2
        });

        return {
            rootEl: rootEl,
            $page: $page,
            $gamesRoot: $gamesRoot,
            $catalog: $gamesRoot.find('[data-ll-wordset-games-catalog]').first(),
            catalogCards: catalogCards,
            catalogOrder: catalogOrder,
            defaultCatalogSlug: defaultCatalogSlug,
            catalogEntries: {},
            activeGameSlug: '',
            $card: defaultCard ? defaultCard.$card : $(),
            $cardStatus: defaultCard ? defaultCard.$status : $(),
            $cardCount: defaultCard ? defaultCard.$count : $(),
            $launchButton: defaultCard ? defaultCard.$launchButton : $(),
            $stage: $gamesRoot.find('[data-ll-wordset-game-stage]').first(),
            $canvasWrap: $gamesRoot.find('.ll-wordset-game-stage__canvas-wrap').first(),
            $controlsWrap: $gamesRoot.find('[data-ll-wordset-game-controls]').first(),
            $canvas: $canvas,
            canvas: $canvas.get(0) || null,
            canvasContext: null,
            $overlay: $gamesRoot.find('[data-ll-wordset-game-overlay]').first(),
            $overlayTitle: $gamesRoot.find('[data-ll-wordset-game-overlay-title]').first(),
            $overlaySummary: $gamesRoot.find('[data-ll-wordset-game-overlay-summary]').first(),
            $overlayPrimary: $gamesRoot.find('[data-ll-wordset-game-replay]').first(),
            $overlaySecondary: $gamesRoot.find('[data-ll-wordset-game-return]').first(),
            $coins: $gamesRoot.find('[data-ll-wordset-game-coins]').first(),
            $lives: $gamesRoot.find('[data-ll-wordset-game-lives]').first(),
            $controls: $gamesRoot.find('[data-ll-wordset-game-control]'),
            $replayAudioButton: $gamesRoot.find('[data-ll-wordset-game-replay-audio]').first(),
            $pauseButton: $gamesRoot.find('[data-ll-wordset-game-pause-toggle]').first(),
            $pauseIcon: $gamesRoot.find('[data-ll-wordset-game-pause-icon]').first(),
            ajaxUrl: String(cfg.ajaxUrl || ''),
            nonce: String(cfg.nonce || ''),
            isLoggedIn: !!cfg.isLoggedIn,
            wordsetId: toInt(cfg.wordsetId),
            visibleCategoryIds: uniqueIntList(cfg.visibleCategoryIds || []),
            i18n: (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {},
            bootstrapAction: String(gamesCfg.bootstrapAction || ''),
            minimumWordCount: Math.max(1, toInt(gamesCfg.minimumWordCount) || 5),
            catalogEntry: null,
            imageCache: {},
            audioPreloadCache: {},
            promptAudio: null,
            feedbackAudio: null,
            feedbackQueue: Promise.resolve(),
            feedbackQueueVersion: 0,
            feedbackPlaying: false,
            feedbackAudioSourceCache: {},
            promptPlaybackRequestId: 0,
            promptReplayTimer: 0,
            bootstrapRequest: null,
            run: null,
            overlayMode: '',
            boundLifecycle: false,
            gameConfigs: gameConfigs,
            spaceShooter: gameConfigs[DEFAULT_GAME_SLUG],
            bubblePop: gameConfigs[BUBBLE_POP_GAME_SLUG]
        };
    }

    api.init = function (rootEl, cfg) {
        if (!rootEl) {
            return null;
        }

        if (api.__ctx) {
            stopRun(api.__ctx, { flush: true });
            unbindLifecycle(api.__ctx);
        }

        const ctx = createContext(rootEl, cfg || {});
        if (!ctx) {
            return null;
        }

        api.__ctx = ctx;
        updateProgressGlobals(ctx);
        bindLifecycle(ctx);
        bindDom(ctx);
        syncCanvasSize(ctx);
        updateStageGameUi(ctx, '');
        renderAllCatalogCards(ctx, null, !!ctx.isLoggedIn);

        if (ctx.isLoggedIn) {
            bootstrapCatalog(ctx);
        } else {
            renderAllCatalogCards(ctx, null, false);
        }

        return ctx;
    };

    api.__debug = {
        getContext: function () {
            const ctx = api.__ctx;
            if (!ctx) {
                return null;
            }
            const activeEntry = ctx.catalogEntries[getDefaultCatalogSlug(ctx)] || ctx.catalogEntry || null;
            return {
                hasCatalogEntry: !!activeEntry,
                launchable: !!(activeEntry && activeEntry.launchable),
                availableWordCount: activeEntry ? toInt(activeEntry.available_word_count) : 0,
                launchWordCount: activeEntry ? toInt(activeEntry.launch_word_count) : 0,
                launchWordCap: activeEntry ? toInt(activeEntry.launch_word_cap) : 0,
                stageHidden: !!ctx.$stage.prop('hidden'),
                gameRunning: !!ctx.run,
                catalogEntries: Object.keys(ctx.catalogEntries || {})
            };
        },
        getRunState: function () {
            const ctx = api.__ctx;
            const run = ctx && ctx.run;
            if (!run) {
                return null;
            }
            return {
                slug: normalizeGameSlug(run.slug),
                coins: run.coins,
                lives: run.lives,
                promptsResolved: run.promptsResolved,
                inactiveRounds: Math.max(0, toInt(run.inactiveRounds)),
                paused: !!run.paused,
                pauseReason: String(run.pauseReason || ''),
                resumeAction: String(run.resumeAction || ''),
                awaitingPrompt: !!run.awaitingPrompt,
                currentPromptHadUserActivity: !!(run.prompt && run.prompt.hadUserActivity),
                lastResolvedPromptHadUserActivity: !!run.lastResolvedPromptHadUserActivity,
                cardSpeed: Math.round(Number(run.prompt && run.prompt.cardSpeed ? run.prompt.cardSpeed : run.cardSpeed) || 0),
                promptAudioDurationMs: Math.round(Number(run.prompt && run.prompt.audioDurationMs) || 0),
                promptAutoReplayDelayMs: Math.round(Number(run.prompt && run.prompt.autoReplayDelayMs) || 0),
                promptAutoReplayBaseDelayMs: Math.round(Number(run.prompt && run.prompt.autoReplayBaseDelayMs) || 0),
                promptSafeLineCrossDelayMs: Math.round(Number(run.prompt && run.prompt.safeLineCrossDelayMs) || 0),
                promptAutoReplaySafeLineGated: !!(run.prompt && run.prompt.autoReplaySafeLineGated),
                promptDistractorMode: run.prompt ? String(run.prompt.distractorMode || '') : '',
                cardWordIds: run.cards.map(function (card) { return toInt(card.word && card.word.id); }),
                targetWordId: run.prompt && run.prompt.target ? toInt(run.prompt.target.id) : 0,
                promptId: activePromptId(run),
                promptRecordingType: run.prompt ? String(run.prompt.recordingType || '') : '',
                activeCardCount: run.cards.filter(function (card) {
                    return isActivePromptCard(run, card) && !card.exploding;
                }).length,
                sameCategoryDistractorCount: run.prompt && run.prompt.target
                    ? run.cards.filter(function (card) {
                        return isActivePromptCard(run, card)
                            && !card.exploding
                            && !card.isTarget
                            && wordsShareCategory(card.word, run.prompt.target);
                    }).length
                    : 0,
                decorativeBubbleCount: Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles.length : 0,
                cardSnapshot: run.cards.map(function (card) {
                    return {
                        wordId: toInt(card.word && card.word.id),
                        promptId: toInt(card.promptId),
                        categoryId: toInt(card.word && card.word.category_id),
                        x: Math.round(Number(card.x) || 0),
                        y: Math.round(Number(card.y) || 0),
                        speed: Math.round((Number(card.speed) || 0) * 100) / 100,
                        width: Math.round(Number(card.width) || 0),
                        height: Math.round(Number(card.height) || 0),
                        exploding: !!card.exploding,
                        resolvedFalling: !!card.resolvedFalling,
                        isTarget: !!card.isTarget
                    };
                }),
                decorativeBubbleSnapshot: (Array.isArray(run.decorativeBubbles) ? run.decorativeBubbles : []).map(function (bubble) {
                    return {
                        id: toInt(bubble && bubble.id),
                        x: Math.round(Number(bubble && bubble.x) || 0),
                        y: Math.round(Number(bubble && bubble.y) || 0),
                        radius: Math.round((Number(bubble && bubble.radius) || 0) * 100) / 100,
                        exploding: !!(bubble && bubble.exploding)
                    };
                })
            };
        },
        launch: function (slug) {
            if (!api.__ctx) {
                return;
            }
            const requestedSlug = normalizeGameSlug(slug || getDefaultCatalogSlug(api.__ctx));
            const entry = api.__ctx.catalogEntries[requestedSlug]
                || (requestedSlug === getDefaultCatalogSlug(api.__ctx) ? api.__ctx.catalogEntry : null);
            if (entry && entry.launchable) {
                startRun(api.__ctx, entry);
            }
        },
        togglePause: function () {
            if (!api.__ctx || !api.__ctx.run) {
                return false;
            }
            if (api.__ctx.run.paused) {
                resumeRun(api.__ctx);
                return true;
            }
            pauseRun(api.__ctx);
            return true;
        },
        resolvePrompt: function (type) {
            const ctx = api.__ctx;
            const run = ctx && ctx.run;
            if (!run || !run.prompt) {
                return false;
            }
            if (type === 'timeout') {
                handlePromptTimeout(ctx);
                return true;
            }
            const targetCard = findTargetCard(run);
            if (type === 'correct' && targetCard) {
                handleCorrectHit(ctx, targetCard);
                return true;
            }
            if (type === 'wrong') {
                for (let index = 0; index < run.cards.length; index += 1) {
                    if (!run.cards[index].isTarget && !run.cards[index].exploding && isActivePromptCard(run, run.cards[index])) {
                        handleWrongHit(ctx, run.cards[index]);
                        return true;
                    }
                }
            }
            return false;
        },
        flushProgress: function () {
            if (api.__ctx) {
                return flushProgress(api.__ctx);
            }
            return null;
        }
    };
})(window, window.jQuery);
