(function (root, $) {
    'use strict';

    const api = root.LLWordsetGames = root.LLWordsetGames || {};
    const DEFAULT_GAME_SLUG = 'space-shooter';
    const MODULE_NS = '.llWordsetGames';
    const GAME_PROMPT_RECORDING_TYPES = ['question', 'isolation', 'introduction'];

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
        const cardHeight = cardWidth * 1.12;
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
            shipSpeed: clamp(width * 0.68, 280, 520)
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
            card.width = run.metrics.cardWidth;
            card.height = run.metrics.cardHeight;
            card.x = laneCenterX(run, card.laneIndex);
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

    function loadWordImage(ctx, word) {
        const url = String(word && word.image || '');
        if (!url) {
            return null;
        }
        if (ctx.imageCache[url]) {
            return ctx.imageCache[url];
        }
        const image = new Image();
        image.decoding = 'async';
        image.src = url;
        ctx.imageCache[url] = image;
        return image;
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
                const wobble = Math.sin(progress * 30) * (1 - progress) * 7;
                context.translate(card.x, card.y);
                context.rotate((Math.sin(progress * 26) * 0.05) + (progress * 0.18));
                context.scale(1 + (progress * 0.26), 1 + (progress * 0.22));
                context.translate(-card.x + wobble, -card.y);
            } else if (card.resolvedFalling) {
                context.translate(card.x, card.y);
                context.rotate((card.laneIndex % 2 === 0 ? -1 : 1) * 0.045);
                context.translate(-card.x, -card.y);
            }
            context.shadowColor = 'rgba(15, 23, 42, 0.22)';
            context.shadowBlur = 16;
            context.shadowOffsetY = 10;
            drawRoundedRect(context, left, top, card.width, card.height, 18);
            context.fillStyle = '#FFFFFF';
            context.fill();
            context.shadowBlur = 0;
            context.shadowOffsetY = 0;
            context.strokeStyle = 'rgba(15, 23, 42, 0.08)';
            context.lineWidth = 1.5;
            context.stroke();

            const image = loadWordImage(ctx, card.word);
            context.save();
            drawRoundedRect(context, left + 8, top + 8, card.width - 16, card.height - 16, 14);
            context.clip();
            if (image && image.complete && image.naturalWidth > 0) {
                const availableWidth = card.width - 16;
                const availableHeight = card.height - 16;
                const imageScale = Math.min(
                    availableWidth / Math.max(1, image.naturalWidth),
                    availableHeight / Math.max(1, image.naturalHeight)
                );
                const drawWidth = image.naturalWidth * imageScale;
                const drawHeight = image.naturalHeight * imageScale;
                const drawX = left + 8 + ((availableWidth - drawWidth) / 2);
                const drawY = top + 8 + ((availableHeight - drawHeight) / 2);

                context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
            } else {
                const placeholder = context.createLinearGradient(left + 8, top + 8, left + card.width - 8, top + card.height - 8);
                placeholder.addColorStop(0, '#DFF7FF');
                placeholder.addColorStop(1, '#C7F0EB');
                context.fillStyle = placeholder;
                context.fillRect(left + 8, top + 8, card.width - 16, card.height - 16);
                context.fillStyle = 'rgba(15, 23, 42, 0.42)';
                context.font = '700 28px Georgia, serif';
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.fillText('✦', card.x, card.y);
            }

            if (dramatic) {
                const flash = context.createRadialGradient(card.x, card.y, card.width * 0.08, card.x, card.y, card.width * 0.7);
                flash.addColorStop(0, 'rgba(255,255,255,0.92)');
                flash.addColorStop(0.38, 'rgba(251, 191, 36, 0.78)');
                flash.addColorStop(1, 'rgba(248, 113, 113, 0)');
                context.globalCompositeOperation = 'screen';
                context.fillStyle = flash;
                context.fillRect(left, top, card.width, card.height);
                context.globalCompositeOperation = 'source-over';
            }
            context.restore();

            if (exploding) {
                context.strokeStyle = card.isTarget ? 'rgba(16, 185, 129, 0.9)' : 'rgba(248, 113, 113, 0.92)';
                context.lineWidth = dramatic ? 4 : 3;
                context.beginPath();
                context.arc(card.x, card.y, (card.width * 0.2) + (progress * card.width * (dramatic ? 0.82 : 0.55)), 0, Math.PI * 2);
                context.stroke();
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
        return {
            word: word,
            promptId: toInt(promptId),
            laneIndex: laneIndex,
            x: laneCenterX(run, laneIndex),
            y: -run.metrics.cardHeight * (0.58 + baseOffset + (Math.random() * 0.14)),
            width: run.metrics.cardWidth,
            height: run.metrics.cardHeight,
            speed: run.cardSpeed,
            isTarget: !!isTarget,
            resolvedFalling: false,
            exploding: false,
            explosionStyle: '',
            removeAt: 0
        };
    }

    function selectCompatiblePromptWords(targetWord, words, requiredCount) {
        const pool = shuffle((Array.isArray(words) ? words : []).filter(function (word) {
            return toInt(word && word.id) !== toInt(targetWord && targetWord.id);
        }));
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

    function buildPromptCards(run, targetWord, words, promptId) {
        const selected = selectCompatiblePromptWords(targetWord, words, run.cardCount);
        if (!selected) {
            return null;
        }

        const shuffledWords = shuffle(selected);
        const laneOrder = shuffle([0, 1, 2, 3]);
        const stagger = shuffle([0.1, 0.38, 0.72, 1.02]);
        return shuffledWords.map(function (word, index) {
            return createCard(
                run,
                word,
                laneOrder[index],
                toInt(word.id) === toInt(targetWord.id),
                stagger[index] || (index * 0.28),
                promptId
            );
        });
    }

    function findPlayableTargets(words, cardCount) {
        return (Array.isArray(words) ? words : []).filter(function (word) {
            return !!selectCompatiblePromptWords(word, words, cardCount);
        });
    }

    function buildPreparedEntry(ctx, rawEntry) {
        const entry = $.extend({}, rawEntry || {});
        const words = (Array.isArray(entry.words) ? entry.words : [])
            .map(normalizeWord)
            .filter(function (word) {
                const audio = selectPromptAudio(word);
                return word.id > 0 && word.image !== '' && audio.url !== '';
            });
        const playableTargets = findPlayableTargets(words, ctx.spaceShooter.cardCount);
        const minimumCount = Math.max(1, toInt(entry.minimum_word_count) || ctx.minimumWordCount);
        const prepared = $.extend({}, entry, {
            words: words,
            playableTargets: playableTargets,
            available_word_count: toInt(entry.available_word_count) || words.length,
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

    function pausePromptAudio(ctx) {
        if (ctx.promptAudio && typeof ctx.promptAudio.pause === 'function') {
            try {
                ctx.promptAudio.pause();
            } catch (_) { /* no-op */ }
        }
    }

    function playPromptAudio(ctx) {
        const run = ctx.run;
        if (!run || !run.prompt || !run.prompt.target) {
            return;
        }

        const source = String(run.prompt.audioUrl || '');
        if (!source) {
            return;
        }

        if (!ctx.promptAudio) {
            ctx.promptAudio = new Audio();
            ctx.promptAudio.preload = 'auto';
        }

        try {
            ctx.promptAudio.pause();
        } catch (_) { /* no-op */ }

        try {
            ctx.promptAudio.currentTime = 0;
        } catch (_) { /* no-op */ }

        if (ctx.promptAudio.src !== source) {
            ctx.promptAudio.src = source;
        }

        const playAttempt = ctx.promptAudio.play();
        if (playAttempt && typeof playAttempt.catch === 'function') {
            playAttempt.catch(function () { return false; });
        }
    }

    function updateHud(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        ctx.$coins.text(String(run.coins));
        ctx.$lives.text(String(run.lives));
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
        if (!run || !run.controls || !Object.prototype.hasOwnProperty.call(run.controls, control)) {
            return;
        }
        run.controls[control] = !!isActive;
        ctx.$controls.filter('[data-ll-wordset-game-control="' + control + '"]').toggleClass('is-active', !!isActive);
    }

    function schedulePrompt(ctx, delayMs) {
        const run = ctx.run;
        if (!run) {
            return;
        }
        if (run.promptTimer) {
            root.clearTimeout(run.promptTimer);
            run.promptTimer = 0;
        }
        run.promptTimer = root.setTimeout(function () {
            run.promptTimer = 0;
            startNextPrompt(ctx);
        }, Math.max(0, delayMs || 0));
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

    function activePromptId(run) {
        return toInt(run && run.prompt && run.prompt.promptId);
    }

    function isActivePromptCard(run, card) {
        return !!card && activePromptId(run) > 0 && toInt(card.promptId) === activePromptId(run);
    }

    function startNextPrompt(ctx) {
        const run = ctx.run;
        if (!run || run.ended) {
            return;
        }

        removeResolvedObjects(run, currentTimestamp());
        const maxAttempts = Math.max(1, run.playableTargets.length);

        for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
            const targetWord = selectNextTarget(run);
            if (!targetWord) {
                break;
            }
            const promptAudio = selectPromptAudio(targetWord, shuffle(getGamePromptRecordingTypes(targetWord)));
            if (!promptAudio.url) {
                continue;
            }
            const promptId = run.promptIdCounter + 1;
            const cards = buildPromptCards(run, targetWord, run.words, promptId);
            if (!cards) {
                continue;
            }

            run.promptIdCounter = promptId;
            run.prompt = {
                target: targetWord,
                promptId: promptId,
                audioUrl: promptAudio.url,
                recordingType: promptAudio.recordingType,
                hadWrongBefore: false,
                wrongCount: 0,
                exposureTracked: false,
                resolved: false
            };
            run.cards = run.cards.concat(cards);
            playPromptAudio(ctx);
            return;
        }

        endRun(ctx);
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

    function markPromptResolved(run) {
        if (!run.prompt || run.prompt.resolved) {
            return false;
        }
        run.prompt.resolved = true;
        run.promptsResolved += 1;
        run.cardSpeed = clamp(86 + (Math.floor(run.promptsResolved / 3) * 10), 86, run.width < 480 ? 148 : 178);
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
        startNextPrompt(ctx);
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

        run.coins = Math.max(0, run.coins - Math.max(0, ctx.spaceShooter.wrongHitCoinPenalty));
        updateHud(ctx);

        card.exploding = true;
        card.explosionStyle = 'dramatic';
        card.explosionDuration = 320;
        card.removeAt = currentTimestamp() + 320;
        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: card.width * 0.94,
            primaryColor: 'rgba(248, 113, 113, 0.98)',
            secondaryColor: 'rgba(251, 191, 36, 0.92)',
            duration: 420,
            style: 'burst',
            rayCount: 12,
            spin: 0.18
        });
        spawnExplosion(run, {
            x: card.x + ((Math.random() * 18) - 9),
            y: card.y + ((Math.random() * 18) - 9),
            radius: card.width * 0.62,
            primaryColor: 'rgba(255, 255, 255, 0.94)',
            secondaryColor: 'rgba(249, 115, 22, 0.9)',
            duration: 300,
            style: 'burst',
            rayCount: 8,
            spin: -0.22
        });
        spawnExplosion(run, {
            x: card.x,
            y: card.y,
            radius: card.width * 1.14,
            primaryColor: 'rgba(255, 214, 10, 0.7)',
            secondaryColor: 'rgba(248, 113, 113, 0.55)',
            duration: 360,
            style: 'ring'
        });
    }

    function handlePromptTimeout(ctx) {
        const run = ctx.run;
        if (!run || !run.prompt || run.prompt.resolved) {
            return;
        }

        if (!run.prompt.hadWrongBefore) {
            queueExposureOnce(ctx, run.prompt);
            queueOutcome(ctx, run.prompt, false, false, {
                event_source: 'space_shooter',
                timeout: true
            });
        }

        run.coins = Math.max(0, run.coins - ctx.spaceShooter.timeoutCoinPenalty);
        run.lives = Math.max(0, run.lives - Math.max(0, ctx.spaceShooter.timeoutLifePenalty));
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
        if (targetCard && !run.prompt.resolved && (targetCard.y - (targetCard.height / 2)) > run.height) {
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

        stepRun(ctx, timestamp, timestamp - run.lastFrameAt);
        renderRun(ctx, timestamp);
        run.lastFrameAt = timestamp;
        run.rafId = root.requestAnimationFrame(function (nextFrameAt) {
            runLoop(ctx, nextFrameAt);
        });
    }

    function showOverlay(ctx, title, summary) {
        ctx.$overlayTitle.text(title);
        ctx.$overlaySummary.text(summary);
        ctx.$overlay.prop('hidden', false);
    }

    function hideOverlay(ctx) {
        ctx.$overlay.prop('hidden', true);
    }

    function endRun(ctx) {
        const run = ctx.run;
        if (!run) {
            return;
        }

        run.ended = true;
        if (run.rafId) {
            root.cancelAnimationFrame(run.rafId);
            run.rafId = 0;
        }
        if (run.promptTimer) {
            root.clearTimeout(run.promptTimer);
            run.promptTimer = 0;
        }
        resetRunControls(run);
        clearControlUi(ctx);
        pausePromptAudio(ctx);
        flushProgress(ctx);

        const summary = formatMessage(ctx.i18n.gamesSummary || 'Coins: %1$d · Prompts: %2$d', [
            run.coins,
            run.promptsResolved
        ]);
        showOverlay(
            ctx,
            String(ctx.i18n.gamesGameOver || 'Run Complete'),
            summary
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
        if (run.promptTimer) {
            root.clearTimeout(run.promptTimer);
            run.promptTimer = 0;
        }
        pausePromptAudio(ctx);
        resetRunControls(run);
        clearControlUi(ctx);
        if (opts.flush !== false) {
            flushProgress(ctx);
        }
        ctx.run = null;
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
        hideOverlay(ctx);

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
            ended: false,
            rafId: 0
        };

        syncCanvasSize(ctx);
        ctx.run.shipX = ctx.run.width / 2;
        ctx.run.shipY = ctx.run.metrics.shipY;
        ctx.run.stars = createStageStars(ctx.run);
        updateHud(ctx);
        setTrackerContext(ctx);
        scrollStageIntoView(ctx);
        schedulePrompt(ctx, 0);
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
            if (!ctx.run || ctx.$stage.prop('hidden')) {
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
            if (!ctx.run) {
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

        const pointerControls = 'pointerdown' + MODULE_NS + ' mousedown' + MODULE_NS + ' touchstart' + MODULE_NS;
        const pointerRelease = 'pointerup' + MODULE_NS + ' mouseup' + MODULE_NS + ' mouseleave' + MODULE_NS + ' touchend' + MODULE_NS + ' touchcancel' + MODULE_NS;

        ctx.$page.on(pointerControls, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
            const control = String($(this).attr('data-ll-wordset-game-control') || '');
            setControlState(ctx, control, true);
        });
        ctx.$page.on(pointerRelease, '[data-ll-wordset-game-control]', function (event) {
            event.preventDefault();
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
            $coins: $gamesRoot.find('[data-ll-wordset-game-coins]').first(),
            $lives: $gamesRoot.find('[data-ll-wordset-game-lives]').first(),
            $controls: $gamesRoot.find('[data-ll-wordset-game-control]'),
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
            promptAudio: null,
            bootstrapRequest: null,
            run: null,
            boundLifecycle: false,
            spaceShooter: {
                lives: Math.max(1, toInt(spaceShooter.lives) || 3),
                cardCount: Math.max(2, toInt(spaceShooter.cardCount) || 4),
                fireIntervalMs: Math.max(80, toInt(spaceShooter.fireIntervalMs) || 165),
                correctCoinReward: Math.max(1, toInt(spaceShooter.correctCoinReward) || 2),
                wrongHitCoinPenalty: Math.max(0, toInt(spaceShooter.wrongHitCoinPenalty)),
                timeoutCoinPenalty: Math.max(0, toInt(spaceShooter.timeoutCoinPenalty) || 1),
                timeoutLifePenalty: Math.max(0, toInt(spaceShooter.timeoutLifePenalty) || 1)
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
                cardWordIds: run.cards.map(function (card) { return toInt(card.word && card.word.id); }),
                targetWordId: run.prompt && run.prompt.target ? toInt(run.prompt.target.id) : 0,
                promptId: activePromptId(run),
                promptRecordingType: run.prompt ? String(run.prompt.recordingType || '') : '',
                activeCardCount: run.cards.filter(function (card) {
                    return isActivePromptCard(run, card) && !card.exploding;
                }).length,
                cardSnapshot: run.cards.map(function (card) {
                    return {
                        wordId: toInt(card.word && card.word.id),
                        promptId: toInt(card.promptId),
                        y: Math.round(Number(card.y) || 0),
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
