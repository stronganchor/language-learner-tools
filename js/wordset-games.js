(function (root, $) {
    'use strict';

    const api = root.LLWordsetGames = root.LLWordsetGames || {};
    const DEFAULT_GAME_SLUG = 'space-shooter';
    const MODULE_NS = '.llWordsetGames';
    const GAME_PROMPT_RECORDING_TYPES = ['question', 'isolation', 'introduction'];
    const CARD_RATIO_MIN = 0.55;
    const CARD_RATIO_MAX = 2.5;
    const CARD_RATIO_DEFAULT = 1;
    const ASSET_PRELOAD_TIMEOUT_MS = 8000;

    function toInt(value) {
        const parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function clamp(value, min, max) {
        const num = Number(value) || 0;
        return Math.max(min, Math.min(max, num));
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

    function syncCanvasSize(ctx) {
        if (!ctx || !ctx.canvas || !ctx.canvas.getContext) {
            return;
        }

        const run = ctx.run;
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

    function buildProgressPayload(prompt, extra) {
        const word = prompt && prompt.target ? prompt.target : null;
        return $.extend({}, extra || {}, {
            recording_type: normalizeRecordingType(prompt && prompt.recordingType || ''),
            available_recording_types: Array.isArray(word && word.practice_recording_types)
                ? word.practice_recording_types.slice()
                : [],
            game_slug: DEFAULT_GAME_SLUG
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
            payload: buildProgressPayload(prompt, extraPayload || {})
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
            payload: buildProgressPayload(prompt, extraPayload || {})
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
            }, Math.max(1000, toInt(ctx && ctx.spaceShooter && ctx.spaceShooter.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS));

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
            }, Math.max(1000, toInt(ctx && ctx.spaceShooter && ctx.spaceShooter.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS));

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
        const context = ctx.canvasContext;
        context.fillStyle = '#67E8F9';
        run.bullets.forEach(function (bullet) {
            context.beginPath();
            context.arc(bullet.x, bullet.y, bullet.radius, 0, Math.PI * 2);
            context.fill();
        });
    }

    function renderCards(ctx, run, now) {
        const context = ctx.canvasContext;
        run.cards.forEach(function (card) {
            const left = card.x - (card.width / 2);
            const top = card.y - (card.height / 2);
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
                context.translate(card.x, card.y);
                context.rotate((Math.sin(progress * 28) * 0.08) + (progress * 0.28));
                context.scale(1 + (progress * 0.42), 1 + (progress * 0.36));
                context.translate(-card.x + wobble, -card.y);
            } else if (card.resolvedFalling) {
                context.translate(card.x, card.y);
                context.rotate((card.laneIndex % 2 === 0 ? -1 : 1) * 0.045);
                context.translate(-card.x, -card.y);
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
            if (image && image.complete && image.naturalWidth > 0) {
                const availableWidth = card.width;
                const availableHeight = card.height;
                const containScale = Math.min(
                    availableWidth / Math.max(1, image.naturalWidth),
                    availableHeight / Math.max(1, image.naturalHeight)
                );
                const drawWidth = image.naturalWidth * containScale;
                const drawHeight = image.naturalHeight * containScale;
                const drawX = left + ((availableWidth - drawWidth) / 2);
                const drawY = top + ((availableHeight - drawHeight) / 2);

                context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
            } else {
                const placeholder = context.createLinearGradient(left, top, left + card.width, top + card.height);
                placeholder.addColorStop(0, '#DFF7FF');
                placeholder.addColorStop(1, '#C7F0EB');
                context.fillStyle = placeholder;
                context.fillRect(left, top, card.width, card.height);
                context.fillStyle = 'rgba(15, 23, 42, 0.42)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('✦', card.x, card.y);
            }
            context.restore();

            context.strokeStyle = 'rgba(15, 23, 42, 0.08)';
            context.lineWidth = 1.5;
            drawRoundedRect(context, left, top, card.width, card.height, getCardRadius(card));
            context.stroke();

            if (dramatic) {
                const flash = context.createRadialGradient(card.x, card.y, card.width * 0.08, card.x, card.y, card.width * 0.7);
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
                    context.arc(card.x, card.y, (Math.max(card.width, card.height) * 0.26) + (progress * Math.max(card.width, card.height) * 1.02), 0, Math.PI * 2);
                    context.stroke();

                    context.strokeStyle = 'rgba(255, 228, 230, 0.7)';
                    context.lineWidth = 2.2;
                    context.beginPath();
                    context.arc(card.x, card.y, (Math.max(card.width, card.height) * 0.12) + (progress * Math.max(card.width, card.height) * 0.68), 0, Math.PI * 2);
                    context.stroke();
                } else {
                    context.beginPath();
                    context.arc(card.x, card.y, (Math.max(card.width, card.height) * 0.2) + (progress * Math.max(card.width, card.height) * 0.55), 0, Math.PI * 2);
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

            if (explosion.style === 'burst') {
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

            context.strokeStyle = String(explosion.primaryColor || explosion.color || 'rgba(255,255,255,0.9)');
            context.lineWidth = 2 + ((1 - progress) * (explosion.style === 'burst' ? 3.4 : 4));
            context.beginPath();
            context.arc(0, 0, radius, 0, Math.PI * 2);
            context.stroke();
            context.restore();
        });
    }

    function renderRun(ctx, now) {
        const run = ctx.run;
        if (!run || !ctx.canvasContext) {
            return;
        }
        renderBackground(ctx, run);
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
            y: (dimensions.height / 2) - (run.cardSpeed * 0.24),
            width: dimensions.width,
            height: dimensions.height,
            aspectRatio: dimensions.aspectRatio,
            entryOffsetFactor: baseOffset,
            entryRevealMs: 0,
            speed: run.cardSpeed,
            isTarget: !!isTarget,
            resolvedFalling: false,
            exploding: false,
            explosionStyle: '',
            removeAt: 0
        };
    }

    function getCardEntryRevealMs(ctx, card) {
        const maxRevealMs = Math.max(140, toInt(ctx && ctx.spaceShooter && ctx.spaceShooter.cardEntryRevealMs) || 300);
        const baseOffset = Math.max(0, Number(card && card.entryOffsetFactor) || 0);
        return Math.min(maxRevealMs, 160 + Math.round(baseOffset * 120));
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
        const laneOrder = shuffle([0, 1, 2, 3]);
        const stagger = shuffle([0.1, 0.38, 0.72, 1.02]);
        return shuffledWords.map(function (word, index) {
            const card = createCard(
                run,
                word,
                laneOrder[index],
                toInt(word.id) === toInt(targetWord.id),
                stagger[index] || (index * 0.28),
                promptId
            );
            applyCardDimensions(ctx, run, card);
            return card;
        });
    }

    function findPlayableTargets(words, cardCount) {
        return (Array.isArray(words) ? words : []).filter(function (word) {
            return !!selectCompatiblePromptWords(word, words, cardCount);
        });
    }

    function buildPreparedEntry(ctx, rawEntry) {
        const entry = $.extend({}, rawEntry || {});
        const minimumCount = Math.max(1, toInt(entry.minimum_word_count) || ctx.minimumWordCount);
        const maxLoadedWords = Math.max(
            minimumCount,
            toInt(entry.launch_word_cap)
                || toInt(ctx.spaceShooter.maxLoadedWords)
                || minimumCount
        );
        const eligibleWords = (Array.isArray(entry.words) ? entry.words : [])
            .map(normalizeWord)
            .filter(function (word) {
                const audio = selectPromptAudio(word);
                return word.id > 0 && word.image !== '' && audio.url !== '';
            });
        const words = limitLaunchWords(eligibleWords, maxLoadedWords);
        const playableTargets = findPlayableTargets(words, ctx.spaceShooter.cardCount);
        const prepared = $.extend({}, entry, {
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

    function renderCatalogCard(ctx, entry, isLoading) {
        const buttonLabel = (entry && entry.launchable)
            ? String(ctx.i18n.gamesPlay || 'Play')
            : String(ctx.i18n.gamesLocked || 'Locked');

        ctx.$cardStatus.text(isLoading ? String(ctx.i18n.gamesLoading || 'Checking game availability...') : getCardStatusText(ctx, entry));
        ctx.$cardCount.text(entry ? String(entry.available_word_count || 0) : '\u2014');
        ctx.$launchButton.text(ctx.isLoggedIn ? buttonLabel : String(ctx.i18n.gamesLocked || 'Locked'));
        ctx.$launchButton.prop('disabled', isLoading || !ctx.isLoggedIn || !(entry && entry.launchable));
        ctx.$card.toggleClass('is-launchable', !!(entry && entry.launchable));
        ctx.$card.toggleClass('is-loading', !!isLoading);
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

    function cancelQueuedPromptPlayback(ctx) {
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
        return type === 'correct'
            ? normalizeUrlList(ctx && ctx.spaceShooter && ctx.spaceShooter.correctHitAudioSources)
            : normalizeUrlList(ctx && ctx.spaceShooter && ctx.spaceShooter.wrongHitAudioSources);
    }

    function getFeedbackAudioVolume(ctx, type) {
        const configured = type === 'correct'
            ? Number(ctx && ctx.spaceShooter && ctx.spaceShooter.correctHitVolume)
            : Number(ctx && ctx.spaceShooter && ctx.spaceShooter.wrongHitVolume);
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

    function playPromptAudio(ctx) {
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

            promptAudio.volume = clamp(Number(ctx.spaceShooter.promptAudioVolume), 0.05, 1);
            if (promptAudio.src !== source) {
                promptAudio.src = source;
            }

            updateReplayAudioUi(ctx, false);
            const playAttempt = promptAudio.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                return playAttempt.then(function () {
                    updateReplayAudioUi(ctx, true);
                    return true;
                }).catch(function () {
                    updateReplayAudioUi(ctx, false);
                    return false;
                });
            }

            updateReplayAudioUi(ctx, true);
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

    function setControlState(ctx, control, isActive) {
        const run = ctx.run;
        if (!run || run.paused || !run.controls || !Object.prototype.hasOwnProperty.call(run.controls, control)) {
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

        const targetCard = candidate.cards.find(function (card) {
            return !!card && card.isTarget;
        });
        const promptDurationMs = getLoadedAudioDurationMs(ctx, candidate.audioUrl);
        const safeLineRatio = clamp(Number(ctx.spaceShooter.audioSafeLineRatio) || 0.6, 0.35, 0.7);
        const safeLineBufferMs = Math.max(0, toInt(ctx.spaceShooter.audioSafeLineBufferMs) || 180);
        const safeLineY = run.height * safeLineRatio;
        let promptCardSpeed = run.cardSpeed;
        const targetRevealMs = targetCard ? getCardEntryRevealMs(ctx, targetCard) : 0;

        if (targetCard && promptDurationMs > 0) {
            const fullyVisibleY = targetCard.height / 2;
            const distanceToSafeLine = Math.max(48, safeLineY - fullyVisibleY);
            const minTravelSeconds = Math.max(0.1, ((promptDurationMs + safeLineBufferMs - targetRevealMs) / 1000));
            const maxSafeSpeed = distanceToSafeLine / minTravelSeconds;
            if (isFinite(maxSafeSpeed) && maxSafeSpeed > 0) {
                promptCardSpeed = Math.min(run.cardSpeed, maxSafeSpeed);
            }
        }

        candidate.cards.forEach(function (card) {
            const entryRevealMs = getCardEntryRevealMs(ctx, card);
            const hiddenDistance = promptCardSpeed * (entryRevealMs / 1000);
            card.entryRevealMs = entryRevealMs;
            card.y = (card.height / 2) - hiddenDistance;
            card.speed = promptCardSpeed;
        });

        run.promptIdCounter = candidate.promptId;
        run.prompt = {
            target: candidate.target,
            promptId: candidate.promptId,
            audioUrl: candidate.audioUrl,
            recordingType: candidate.recordingType,
            distractorMode: String(candidate.distractorMode || 'mixed'),
            cardSpeed: promptCardSpeed,
            audioDurationMs: promptDurationMs,
            hadWrongBefore: false,
            wrongCount: 0,
            exposureTracked: false,
            resolved: false
        };
        run.cards = run.cards.concat(candidate.cards);
        playPromptAudio(ctx);
        queueNextPreparedPrompt(ctx, run);
        return true;
    }

    function activePromptId(run) {
        return toInt(run && run.prompt && run.prompt.promptId);
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

    function removeResolvedObjects(run, now) {
        run.cards = run.cards.filter(function (card) {
            if (card.resolvedFalling && (card.y - (card.height / 2)) > (run.height + card.height)) {
                return false;
            }
            return !(card.exploding && now >= card.removeAt);
        });
        run.explosions = run.explosions.filter(function (explosion) {
            return now < (explosion.startedAt + explosion.duration);
        });
    }

    function fireBullet(run) {
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
        });
    }

    function handleCorrectHit(ctx, card) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, true, !!run.prompt.hadWrongBefore, { event_source: 'space_shooter' });

        run.coins += Math.max(1, ctx.spaceShooter.correctCoinReward);
        updateHud(ctx);
        card.exploding = true;
        card.explosionStyle = 'correct';
        card.explosionDuration = 220;
        card.removeAt = currentTimestamp() + 220;
        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: card.width * 0.72,
            primaryColor: 'rgba(16, 185, 129, 0.96)',
            secondaryColor: 'rgba(103, 232, 249, 0.78)',
            duration: 300,
            style: 'ring'
        });

        markPromptResolved(run);
        releaseResolvedPromptCards(run, card);
        playFeedbackSound(ctx, 'correct').finally(function () {
            if (!ctx.run || ctx.run !== run || run.ended) {
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

        queueExposureOnce(ctx, run.prompt);
        queueOutcome(ctx, run.prompt, false, false, { event_source: 'space_shooter', wrong_hit: true });
        run.prompt.hadWrongBefore = true;
        run.prompt.wrongCount += 1;

        run.lives = Math.max(0, run.lives - Math.max(1, ctx.spaceShooter.wrongHitLifePenalty));
        run.coins = Math.max(0, run.coins - Math.max(0, ctx.spaceShooter.wrongHitCoinPenalty));
        updateHud(ctx);

        card.exploding = true;
        card.explosionStyle = 'dramatic';
        card.explosionDuration = 420;
        card.removeAt = currentTimestamp() + 420;
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
        const untouchedTimeout = !run.prompt.hadWrongBefore;

        if (untouchedTimeout) {
            queueExposureOnce(ctx, run.prompt);
            queueOutcome(ctx, run.prompt, false, false, {
                event_source: 'space_shooter',
                timeout: true
            });
        }

        run.coins = Math.max(0, run.coins - ctx.spaceShooter.timeoutCoinPenalty);
        if (!untouchedTimeout) {
            run.lives = Math.max(0, run.lives - Math.max(0, ctx.spaceShooter.timeoutLifePenalty));
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

    function stepRun(ctx, now, dtMs) {
        const run = ctx.run;
        if (!run || !run.prompt) {
            return;
        }

        const dt = Math.min(40, Math.max(0, dtMs || 0)) / 1000;
        const direction = (run.controls.right ? 1 : 0) - (run.controls.left ? 1 : 0);
        if (direction !== 0) {
            run.shipX = clamp(
                run.shipX + (direction * run.metrics.shipSpeed * dt),
                run.metrics.shipWidth / 2,
                run.width - (run.metrics.shipWidth / 2)
            );
        }

        if (run.controls.fire && (now - run.lastFireAt) >= ctx.spaceShooter.fireIntervalMs) {
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
                card.y += card.speed * dt;
            }
        });

        if (!run.prompt.resolved) {
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
                    continue outerLoop;
                }
            }
        }

        const targetCard = findTargetCard(run);
        if (!run.prompt.resolved && !targetCard) {
            handlePromptTimeout(ctx);
        } else if (targetCard && !run.prompt.resolved && (targetCard.y - (targetCard.height / 2)) > run.height) {
            handlePromptTimeout(ctx);
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

    function pauseRun(ctx) {
        const run = ctx.run;
        if (!run || run.ended || run.paused) {
            return;
        }

        run.paused = true;
        clearPromptTimer(run, true);
        pausePromptAudio(ctx);
        stopFeedbackAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        updatePauseUi(ctx);
        showOverlay(ctx, String(ctx.i18n.gamesPaused || 'Paused'), '', {
            mode: 'paused',
            primaryLabel: String(ctx.i18n.gamesResumeRun || 'Resume'),
            secondaryLabel: String(ctx.i18n.gamesBackToCatalog || 'Back to games')
        });
    }

    function resumeRun(ctx) {
        const run = ctx.run;
        if (!run || !run.paused) {
            return;
        }

        run.paused = false;
        run.lastFrameAt = 0;
        hideOverlay(ctx);
        updatePauseUi(ctx);

        if (run.promptTimerRemainingMs > 0) {
            schedulePrompt(ctx, run.promptTimerRemainingMs);
            return;
        }

        if (run.prompt && !run.prompt.resolved) {
            playPromptAudio(ctx);
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
        ctx.$stage.prop('hidden', true);
        ctx.$catalog.prop('hidden', false);
        syncCanvasSize(ctx);
    }

    function startRun(ctx, entry) {
        showCatalog(ctx);
        ctx.$catalog.prop('hidden', true);
        ctx.$stage.prop('hidden', false);
        showOverlay(ctx, String(ctx.i18n.gamesPreparingRun || 'Preparing game...'), '', {
            mode: 'loading',
            primaryLabel: '',
            secondaryLabel: ''
        });

        ctx.run = {
            slug: DEFAULT_GAME_SLUG,
            words: entry.words.slice(),
            playableTargets: shuffle(entry.playableTargets.slice()),
            promptDeck: [],
            prompt: null,
            cards: [],
            bullets: [],
            explosions: [],
            controls: {
                left: false,
                right: false,
                fire: false
            },
            coins: 0,
            lives: ctx.spaceShooter.lives,
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
            cardCount: ctx.spaceShooter.cardCount,
            cardSpeed: 86,
            promptIdCounter: 0,
            promptTimer: 0,
            promptTimerReadyAt: 0,
            promptTimerRemainingMs: 0,
            speedRampTurns: ctx.spaceShooter.introRampTurns,
            speedRampStartFactor: ctx.spaceShooter.introRampStartFactor,
            useSameCategoryDistractorsNext: false,
            awaitingPrompt: false,
            nextPreparedPrompt: null,
            nextPromptPromise: null,
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
        renderCatalogCard(ctx, null, true);
        if (!ctx.isLoggedIn || !ctx.ajaxUrl || !ctx.wordsetId || !ctx.bootstrapAction) {
            renderCatalogCard(ctx, null, false);
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
                renderCatalogCard(ctx, null, false);
                return;
            }

            const entry = buildPreparedEntry(ctx, payload.games[DEFAULT_GAME_SLUG] || {});
            ctx.catalogEntry = entry;
            renderCatalogCard(ctx, entry, false);
        }).fail(function () {
            renderCatalogCard(ctx, null, false);
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
            if (!ctx.run || ctx.run.paused || ctx.$stage.prop('hidden')) {
                return;
            }
            if (matchesKey(event, ['arrowleft', 'a'], ['arrowleft', 'keya'])) {
                event.preventDefault();
                setControlState(ctx, 'left', true);
            } else if (matchesKey(event, ['arrowright', 'd'], ['arrowright', 'keyd'])) {
                event.preventDefault();
                setControlState(ctx, 'right', true);
            } else if (matchesKey(event, [' ', 'space', 'spacebar'], ['space'])) {
                event.preventDefault();
                setControlState(ctx, 'fire', true);
            }
        };
        ctx.onKeyUp = function (event) {
            if (!ctx.run || ctx.run.paused) {
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
            if (!ctx.catalogEntry || !ctx.catalogEntry.launchable) {
                return;
            }
            startRun(ctx, ctx.catalogEntry);
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
            if (!ctx.catalogEntry || !ctx.catalogEntry.launchable) {
                return;
            }
            stopRun(ctx, { flush: true });
            startRun(ctx, ctx.catalogEntry);
        });

        ctx.$page.on('click' + MODULE_NS, '[data-ll-wordset-game-replay-audio]', function (event) {
            event.preventDefault();
            playPromptAudio(ctx);
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

        return {
            rootEl: rootEl,
            $page: $page,
            $gamesRoot: $gamesRoot,
            $catalog: $gamesRoot.find('[data-ll-wordset-games-catalog]').first(),
            $card: $gamesRoot.find('[data-ll-wordset-game-card]').first(),
            $cardStatus: $gamesRoot.find('[data-ll-wordset-game-status]').first(),
            $cardCount: $gamesRoot.find('[data-ll-wordset-game-count]').first(),
            $launchButton: $gamesRoot.find('[data-ll-wordset-game-launch]').first(),
            $stage: $gamesRoot.find('[data-ll-wordset-game-stage]').first(),
            $canvasWrap: $gamesRoot.find('.ll-wordset-game-stage__canvas-wrap').first(),
            canvas: $gamesRoot.find('[data-ll-wordset-game-canvas]').get(0) || null,
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
            bootstrapRequest: null,
            run: null,
            overlayMode: '',
            boundLifecycle: false,
            spaceShooter: {
                lives: Math.max(1, toInt(spaceShooter.lives) || 3),
                cardCount: Math.max(2, toInt(spaceShooter.cardCount) || 4),
                maxLoadedWords: Math.max(5, toInt(spaceShooter.maxLoadedWords) || 60),
                fireIntervalMs: Math.max(80, toInt(spaceShooter.fireIntervalMs) || 165),
                introRampTurns: Math.max(1, toInt(spaceShooter.introRampTurns) || 10),
                introRampStartFactor: clamp(Number(spaceShooter.introRampStartFactor) || 0.5, 0.25, 0.95),
                audioSafeLineRatio: clamp(Number(spaceShooter.audioSafeLineRatio) || 0.6, 0.35, 0.7),
                audioSafeLineBufferMs: Math.max(0, toInt(spaceShooter.audioSafeLineBufferMs) || 180),
                correctCoinReward: Math.max(1, toInt(spaceShooter.correctCoinReward) || 1),
                wrongHitCoinPenalty: Math.max(0, toInt(spaceShooter.wrongHitCoinPenalty)),
                wrongHitLifePenalty: Math.max(1, toInt(spaceShooter.wrongHitLifePenalty) || 1),
                timeoutCoinPenalty: Math.max(0, toInt(spaceShooter.timeoutCoinPenalty) || 1),
                timeoutLifePenalty: Math.max(0, toInt(spaceShooter.timeoutLifePenalty) || 1),
                assetPreloadTimeoutMs: Math.max(1500, toInt(spaceShooter.assetPreloadTimeoutMs) || ASSET_PRELOAD_TIMEOUT_MS),
                cardEntryRevealMs: Math.max(140, toInt(spaceShooter.cardEntryRevealMs) || 300),
                promptAudioVolume: clamp(Number(spaceShooter.promptAudioVolume) || 1, 0.05, 1),
                correctHitVolume: clamp(Number(spaceShooter.correctHitVolume) || 0.28, 0.05, 1),
                wrongHitVolume: clamp(Number(spaceShooter.wrongHitVolume) || 0.2, 0.05, 1),
                correctHitAudioSources: normalizeUrlList(spaceShooter.correctHitAudioSources || spaceShooter.correctHitAudioUrl),
                wrongHitAudioSources: normalizeUrlList(spaceShooter.wrongHitAudioSources || spaceShooter.wrongHitAudioUrl)
            }
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
        renderCatalogCard(ctx, null, !!ctx.isLoggedIn);

        if (ctx.isLoggedIn) {
            bootstrapCatalog(ctx);
        } else {
            renderCatalogCard(ctx, null, false);
        }

        return ctx;
    };

    api.__debug = {
        getContext: function () {
            const ctx = api.__ctx;
            if (!ctx) {
                return null;
            }
            return {
                hasCatalogEntry: !!ctx.catalogEntry,
                launchable: !!(ctx.catalogEntry && ctx.catalogEntry.launchable),
                availableWordCount: ctx.catalogEntry ? toInt(ctx.catalogEntry.available_word_count) : 0,
                launchWordCount: ctx.catalogEntry ? toInt(ctx.catalogEntry.launch_word_count) : 0,
                launchWordCap: ctx.catalogEntry ? toInt(ctx.catalogEntry.launch_word_cap) : 0,
                stageHidden: !!ctx.$stage.prop('hidden'),
                gameRunning: !!ctx.run
            };
        },
        getRunState: function () {
            const ctx = api.__ctx;
            const run = ctx && ctx.run;
            if (!run) {
                return null;
            }
            return {
                coins: run.coins,
                lives: run.lives,
                promptsResolved: run.promptsResolved,
                paused: !!run.paused,
                awaitingPrompt: !!run.awaitingPrompt,
                cardSpeed: Math.round(Number(run.prompt && run.prompt.cardSpeed ? run.prompt.cardSpeed : run.cardSpeed) || 0),
                promptAudioDurationMs: Math.round(Number(run.prompt && run.prompt.audioDurationMs) || 0),
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
                cardSnapshot: run.cards.map(function (card) {
                    return {
                        wordId: toInt(card.word && card.word.id),
                        promptId: toInt(card.promptId),
                        categoryId: toInt(card.word && card.word.category_id),
                        y: Math.round(Number(card.y) || 0),
                        width: Math.round(Number(card.width) || 0),
                        height: Math.round(Number(card.height) || 0),
                        exploding: !!card.exploding,
                        resolvedFalling: !!card.resolvedFalling,
                        isTarget: !!card.isTarget
                    };
                })
            };
        },
        launch: function () {
            if (api.__ctx && api.__ctx.catalogEntry && api.__ctx.catalogEntry.launchable) {
                startRun(api.__ctx, api.__ctx.catalogEntry);
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
