(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};

    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const STATES = State.STATES || {};
    const SelfCheckShared = root.LLToolsSelfCheckShared || {};
    const $ = root.jQuery;
    let currentPromptAudio = null;
    let currentPromptAudioButton = null;
    const SELF_CHECK_AUTO_ADVANCE_DELAY_MS = 700;
    const SELF_CHECK_AUDIO_FAIL_DELAY_MS = 900;
    const SELF_CHECK_AUDIO_TIMEOUT_MS = 10000;
    const SELF_CHECK_ACTIONS_CONFIDENCE = [
        { value: 'idk', labelKey: 'selfCheckDontKnow', fallback: "I don't know it", className: 'll-study-check-btn--idk' },
        { value: 'think', labelKey: 'selfCheckThinkKnow', fallback: 'I think I know it', className: 'll-study-check-btn--think' },
        { value: 'know', labelKey: 'selfCheckKnow', fallback: 'I know it', className: 'll-study-check-btn--know' }
    ];
    const SELF_CHECK_ACTIONS_RESULT = [
        { value: 'wrong', labelKey: 'selfCheckGotWrong', fallback: 'I got it wrong', className: 'll-study-check-btn--wrong' },
        { value: 'close', labelKey: 'selfCheckGotClose', fallback: 'I got close', className: 'll-study-check-btn--close' },
        { value: 'right', labelKey: 'selfCheckGotRight', fallback: 'I got it right', className: 'll-study-check-btn--right' }
    ];

    function getProgressTracker() {
        return root.LLFlashcards && root.LLFlashcards.ProgressTracker
            ? root.LLFlashcards.ProgressTracker
            : null;
    }

    function resolveWordsetIdForProgress() {
        const data = root.llToolsFlashcardsData || {};
        const userState = data.userStudyState || {};
        const fromState = parseInt(userState.wordset_id, 10) || 0;
        if (fromState > 0) {
            return fromState;
        }
        if (Array.isArray(data.wordsetIds) && data.wordsetIds.length) {
            const first = parseInt(data.wordsetIds[0], 10) || 0;
            if (first > 0) {
                return first;
            }
        }
        return 0;
    }

    function ensureQuizResultsShape() {
        State.quizResults = State.quizResults || { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} };
        State.quizResults.wordAttempts = State.quizResults.wordAttempts || {};
        State.quizResults.incorrect = Array.isArray(State.quizResults.incorrect) ? State.quizResults.incorrect : [];
        return State.quizResults;
    }

    function normalizeStarMode(mode) {
        const val = String(mode || '').trim().toLowerCase();
        return (val === 'normal' || val === 'weighted' || val === 'only') ? val : 'normal';
    }

    function getStarredLookup() {
        const prefs = root.llToolsStudyPrefs || {};
        const ids = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];
        const lookup = {};
        ids.forEach(function (id) {
            const num = parseInt(id, 10);
            if (num > 0) {
                lookup[num] = true;
            }
        });
        return lookup;
    }

    function getEffectiveStarMode() {
        if (State && State.starModeOverride) {
            return normalizeStarMode(State.starModeOverride);
        }
        const prefs = root.llToolsStudyPrefs || {};
        const data = root.llToolsFlashcardsData || {};
        return normalizeStarMode(
            prefs.starMode ||
            prefs.star_mode ||
            data.starMode ||
            data.star_mode ||
            'normal'
        );
    }

    function getCategoryDisplayLabel(categoryName) {
        const fallback = String(categoryName || '').trim();
        const data = root.llToolsFlashcardsData || {};
        const categories = Array.isArray(data.categories) ? data.categories : [];
        const match = categories.find(function (cat) {
            return cat && cat.name === categoryName;
        });
        if (match) {
            const translated = String(match.translation || '').trim();
            if (translated) {
                return translated;
            }
            const name = String(match.name || '').trim();
            if (name) {
                return name;
            }
        }
        return fallback;
    }

    function getEstimatedRoundTotal() {
        const sourceNames = (Array.isArray(State.initialCategoryNames) && State.initialCategoryNames.length)
            ? State.initialCategoryNames
            : (Array.isArray(State.categoryNames) ? State.categoryNames : []);
        const names = sourceNames.filter(Boolean);
        if (!names.length) { return 0; }

        const starredLookup = getStarredLookup();
        const starMode = getEffectiveStarMode();
        const seenWords = {};
        let total = 0;

        names.forEach(function (name) {
            const words = (State.wordsByCategory && Array.isArray(State.wordsByCategory[name]))
                ? State.wordsByCategory[name]
                : [];
            words.forEach(function (word) {
                const wordId = parseInt(word && word.id, 10) || 0;
                if (!wordId || seenWords[wordId]) { return; }
                seenWords[wordId] = true;

                if (starMode === 'only' && !starredLookup[wordId]) {
                    return;
                }
                total += (starMode === 'weighted' && starredLookup[wordId]) ? 2 : 1;
            });
        });

        return total;
    }

    function getAnsweredRoundCount() {
        const results = ensureQuizResultsShape();
        const attempts = results.wordAttempts || {};
        let count = 0;
        Object.keys(attempts).forEach(function (key) {
            const info = attempts[key] || {};
            const seen = parseInt(info.seen, 10) || 0;
            count += Math.max(0, seen);
        });
        return count;
    }

    function buildRoundMeta(categoryName) {
        const msgs = root.llToolsFlashcardsMessages || {};
        const total = getEstimatedRoundTotal();
        const answered = getAnsweredRoundCount();
        const current = total > 0 ? Math.min(total, answered + 1) : 0;
        return {
            title: msgs.selfCheckTitle || 'Self check',
            categoryLabel: getCategoryDisplayLabel(categoryName),
            progressText: (total > 0) ? (String(current) + ' / ' + String(total)) : '',
            progressCurrent: current,
            progressTotal: total
        };
    }

    function recomputeQuizResultTotals() {
        const results = ensureQuizResultsShape();
        const stats = results.wordAttempts || {};
        let correctCount = 0;
        const incorrectSet = new Set();

        Object.keys(stats).forEach(function (key) {
            const info = stats[key] || {};
            const seen = info.seen || 0;
            const clean = info.clean || 0;
            const hadWrong = !!info.hadWrong;
            if (seen <= 0) { return; }
            if (!hadWrong && clean === seen) {
                correctCount += 1;
            } else {
                const numId = parseInt(key, 10);
                incorrectSet.add(Number.isNaN(numId) ? key : numId);
            }
        });

        results.correctOnFirstTry = correctCount;
        results.incorrect = Array.from(incorrectSet);
    }

    function recordSelfCheckResult(wordId, confidence, bucket) {
        const idNum = parseInt(wordId, 10);
        if (!idNum) { return; }
        const key = String(idNum);
        const results = ensureQuizResultsShape();
        const stats = results.wordAttempts[key] || { seen: 0, clean: 0, hadWrong: false };
        const confidenceKey = String(confidence || '').toLowerCase();
        const bucketKey = String(bucket || '').toLowerCase();

        let knowsWord = false;
        let hadWrongBefore = true;
        if (bucketKey === 'right') {
            knowsWord = true;
            hadWrongBefore = false;
        } else if (bucketKey === 'close') {
            knowsWord = true;
            hadWrongBefore = true;
        } else if (bucketKey === 'idk') {
            knowsWord = false;
            hadWrongBefore = true;
        } else if (confidenceKey === 'idk') {
            knowsWord = false;
            hadWrongBefore = true;
        }

        stats.seen += 1;
        if (knowsWord) {
            stats.clean += 1;
        }
        if (hadWrongBefore) {
            stats.hadWrong = true;
        }
        results.wordAttempts[key] = stats;
        recomputeQuizResultTotals();
    }

    function getWordText(word) {
        if (SelfCheckShared && typeof SelfCheckShared.getWordText === 'function') {
            return SelfCheckShared.getWordText(word);
        }
        if (!word) { return ''; }
        const label = (typeof word.label === 'string') ? word.label.trim() : '';
        if (label) { return label; }
        const title = (typeof word.title === 'string') ? word.title.trim() : '';
        if (title) { return title; }
        return '';
    }

    function isTextMode(mode) {
        if (SelfCheckShared && typeof SelfCheckShared.isTextOptionType === 'function') {
            return SelfCheckShared.isTextOptionType(mode);
        }
        return mode === 'text' || mode === 'text_title' || mode === 'text_translation' || mode === 'text_audio';
    }

    function isAudioMode(mode) {
        if (SelfCheckShared && typeof SelfCheckShared.isAudioOptionType === 'function') {
            return SelfCheckShared.isAudioOptionType(mode);
        }
        return mode === 'audio' || mode === 'text_audio';
    }

    function getAudioUrl(word) {
        if (SelfCheckShared && typeof SelfCheckShared.getWordAudioUrl === 'function') {
            return SelfCheckShared.getWordAudioUrl(word);
        }
        if (!word) { return ''; }
        if (word.audio) { return String(word.audio); }
        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (file && file.url) {
                return String(file.url);
            }
        }
        return '';
    }

    function getIsolationAudioUrl(word) {
        if (SelfCheckShared && typeof SelfCheckShared.getIsolationAudioUrl === 'function') {
            return SelfCheckShared.getIsolationAudioUrl(word, { fallbackToAnyAudio: false });
        }
        return '';
    }

    function getSelfCheckRenderOptions(msgs, ctx) {
        const messages = msgs || {};
        return {
            emptyLabel: messages.noWordsFound || 'No content available.',
            playAudioLabel: messages.selfCheckPlayAudio || 'Play audio',
            playAudioType: messages.playAudioType || 'Play %s recording',
            recordingIsolation: messages.recordingIsolation || 'Isolation',
            recordingIntroduction: messages.recordingIntroduction || 'Introduction',
            recordingsLabel: messages.recordingsLabel || 'Recordings',
            selfCheckPlayAudio: messages.selfCheckPlayAudio || 'Play audio',
            onPlayAudio: function (audioUrl, buttonEl) {
                playAudioUrl(audioUrl, ctx, buttonEl);
            }
        };
    }

    function stopPromptAudio() {
        if (currentPromptAudio) {
            try {
                currentPromptAudio.pause();
                currentPromptAudio.currentTime = 0;
            } catch (_) { /* no-op */ }
        }
        if (currentPromptAudioButton && $) {
            $(currentPromptAudioButton).removeClass('is-playing');
        }
        currentPromptAudio = null;
        currentPromptAudioButton = null;
    }

    function playAudioUrl(audioUrl, ctx, buttonEl) {
        if (!audioUrl) { return; }

        if (currentPromptAudio && buttonEl && currentPromptAudioButton === buttonEl) {
            if (!currentPromptAudio.paused) {
                currentPromptAudio.pause();
                try { currentPromptAudio.currentTime = 0; } catch (_) { /* no-op */ }
                if ($) { $(buttonEl).removeClass('is-playing'); }
                return;
            }
        } else {
            stopPromptAudio();
        }

        try {
            if (ctx.FlashcardAudio && typeof ctx.FlashcardAudio.pauseAllAudio === 'function') {
                ctx.FlashcardAudio.pauseAllAudio();
            }
        } catch (_) { /* no-op */ }

        const promptAudio = new Audio(audioUrl);
        currentPromptAudio = promptAudio;
        currentPromptAudioButton = buttonEl || null;

        if (buttonEl && $) {
            $(buttonEl).addClass('is-playing');
        }
        promptAudio.addEventListener('play', function () {
            if (currentPromptAudio !== promptAudio) { return; }
            if (currentPromptAudioButton && $) {
                $(currentPromptAudioButton).addClass('is-playing');
            }
        });
        promptAudio.addEventListener('pause', function () {
            if (currentPromptAudio !== promptAudio) { return; }
            if (currentPromptAudioButton && $) {
                $(currentPromptAudioButton).removeClass('is-playing');
            }
        });
        promptAudio.addEventListener('ended', function () {
            if (currentPromptAudio !== promptAudio) { return; }
            stopPromptAudio();
        });
        promptAudio.addEventListener('error', function () {
            if (currentPromptAudio !== promptAudio) { return; }
            stopPromptAudio();
        });

        promptAudio.play().catch(function () {
            stopPromptAudio();
        });
    }

    function playIsolationAudio(word, ctx) {
        const audioUrl = getIsolationAudioUrl(word);
        if (!audioUrl) {
            return Promise.resolve(false);
        }
        stopPromptAudio();
        try {
            if (ctx && ctx.FlashcardAudio && typeof ctx.FlashcardAudio.pauseAllAudio === 'function') {
                ctx.FlashcardAudio.pauseAllAudio();
            }
        } catch (_) { /* no-op */ }

        return new Promise(function (resolve) {
            let done = false;
            const promptAudio = new Audio(audioUrl);
            currentPromptAudio = promptAudio;
            currentPromptAudioButton = null;
            const timeoutId = setTimeout(function () {
                finish(true);
            }, SELF_CHECK_AUDIO_TIMEOUT_MS);

            const finish = function (played) {
                if (done) { return; }
                done = true;
                clearTimeout(timeoutId);
                promptAudio.removeEventListener('ended', onEnded);
                promptAudio.removeEventListener('error', onError);
                try {
                    if (!promptAudio.paused) {
                        promptAudio.pause();
                    }
                } catch (_) { /* no-op */ }
                if (currentPromptAudio === promptAudio) {
                    currentPromptAudio = null;
                    currentPromptAudioButton = null;
                }
                resolve(!!played);
            };

            const onEnded = function () { finish(true); };
            const onError = function () { finish(false); };
            promptAudio.addEventListener('ended', onEnded);
            promptAudio.addEventListener('error', onError);
            if (promptAudio.play) {
                promptAudio.play().catch(function () {
                    finish(false);
                });
            } else {
                finish(false);
            }
        });
    }

    function appendDisplayContentFallback($host, displayMode, word, ctx) {
        if (!$host || !$host.length || !word) { return; }
        $host.empty();
        const mode = String(displayMode || '');
        const text = getWordText(word);
        const audioUrl = getAudioUrl(word);
        const $inner = $('<div>', { class: 'll-study-check-prompt-inner' });

        let hasContent = false;
        if (mode === 'image' && word.image) {
            const $imgWrap = $('<div>', { class: 'll-study-check-image' });
            $('<img>', {
                src: word.image,
                alt: text || '',
                draggable: false
            }).appendTo($imgWrap);
            $inner.append($imgWrap);
            hasContent = true;
        }

        if (isTextMode(mode) && text) {
            $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
            hasContent = true;
        }

        if (isAudioMode(mode) && audioUrl) {
            const label = (root.llToolsFlashcardsMessages && root.llToolsFlashcardsMessages.selfCheckPlayAudio) || 'Play audio';
            const $audioBtn = $('<button>', {
                type: 'button',
                class: 'll-study-check-audio-btn',
                text: label
            });
            $audioBtn.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                playAudioUrl(audioUrl, ctx);
            });
            $inner.append($audioBtn);
            hasContent = true;
        }

        if (!hasContent && text) {
            $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
            hasContent = true;
        }

        if (!hasContent) {
            const fallback = (root.llToolsFlashcardsMessages && root.llToolsFlashcardsMessages.noWordsFound) || 'No content available.';
            $('<div>', { class: 'll-study-check-empty', text: fallback }).appendTo($inner);
        }

        $host.append($inner);
    }

    function appendDisplayContent($host, displayMode, word, ctx) {
        if (!$host || !$host.length || !word) { return; }
        if (SelfCheckShared && typeof SelfCheckShared.renderPromptDisplay === 'function') {
            const msgs = root.llToolsFlashcardsMessages || {};
            SelfCheckShared.renderPromptDisplay($host, displayMode, word, {
                emptyLabel: msgs.noWordsFound || 'No content available.',
                playAudioLabel: msgs.selfCheckPlayAudio || 'Play audio',
                onPlayAudio: function (audioUrl, buttonEl) {
                    playAudioUrl(audioUrl, ctx, buttonEl);
                }
            });
            return;
        }
        appendDisplayContentFallback($host, displayMode, word, ctx);
    }

    function buildActionIcon(choiceValue) {
        const key = String(choiceValue || '').toLowerCase();
        const isCross = (key === 'idk' || key === 'wrong');
        const isCheck = (key === 'know' || key === 'right');
        const isThink = (key === 'think');
        const isClose = (key === 'close');
        if (!isCross && !isCheck && !isThink && !isClose) { return null; }

        const $icon = $('<span>', { class: 'll-study-check-icon', 'aria-hidden': 'true' });
        if (isCross) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <line x1="16" y1="16" x2="48" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>
                    <line x1="48" y1="16" x2="16" y2="48" stroke="currentColor" stroke-width="6" stroke-linecap="round"></line>
                </svg>
            `));
            return $icon;
        }

        if (isThink) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <g fill="currentColor">
                        <circle cx="22" cy="18" r="7.5"></circle>
                        <circle cx="33" cy="14" r="8.5"></circle>
                        <circle cx="44" cy="18" r="7.5"></circle>
                        <circle cx="17" cy="27" r="7"></circle>
                        <circle cx="49" cy="27" r="7"></circle>
                        <circle cx="23" cy="35" r="7.5"></circle>
                        <circle cx="33" cy="36" r="8.5"></circle>
                        <circle cx="43" cy="34" r="7.5"></circle>
                        <circle cx="33" cy="26" r="11"></circle>
                        <circle cx="44.6" cy="51.2" r="4.3"></circle>
                        <circle cx="54.8" cy="56.6" r="3.4"></circle>
                    </g>
                </svg>
            `));
            return $icon;
        }

        if (isClose) {
            $icon.append($(`
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="6"></circle>
                    <path fill="currentColor" fill-rule="evenodd" d="M32 8 A24 24 0 1 1 31.999 8 Z M32 32 L32 8 A24 24 0 0 0 8 32 Z"></path>
                </svg>
            `));
            return $icon;
        }

        $icon.append($(`
            <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                <polyline points="14,34 28,46 50,18" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></polyline>
            </svg>
        `));
        return $icon;
    }

    function setActionsDisabled($actions, disabled) {
        if (!$actions || !$actions.length) { return; }
        $actions.find('button').prop('disabled', !!disabled).toggleClass('disabled', !!disabled);
    }

    function renderPromptAndAnswerDisplays($front, $back, targetWord, ctx, msgs) {
        if (!$front || !$front.length || !$back || !$back.length) {
            return;
        }
        const renderOptions = getSelfCheckRenderOptions(msgs, ctx);

        if (SelfCheckShared && typeof SelfCheckShared.renderSelfCheckPromptDisplay === 'function' && typeof SelfCheckShared.renderSelfCheckAnswerDisplay === 'function') {
            SelfCheckShared.renderSelfCheckPromptDisplay($front, targetWord, Object.assign({}, renderOptions, { displayType: 'image' }));
            SelfCheckShared.renderSelfCheckAnswerDisplay($back, targetWord, Object.assign({}, renderOptions, {
                displayType: 'image',
                recordingTypes: ['isolation', 'introduction'],
                isolationFallbackToAnyAudio: false,
                introductionFallbackToAnyAudio: false,
                messages: renderOptions
            }));
            return;
        }

        appendDisplayContent($front, 'image', targetWord, ctx);
        appendDisplayContent($back, 'image', targetWord, ctx);
    }

    function buildRound(targetWord, promptType, optionType, ctx, meta) {
        const msgs = root.llToolsFlashcardsMessages || {};
        const $flashcard = (ctx && ctx.flashcardContainer && ctx.flashcardContainer.length)
            ? ctx.flashcardContainer
            : $('#ll-tools-flashcard');

        if (!$flashcard || !$flashcard.length) { return; }
        stopPromptAudio();
        $flashcard.empty();

        const roundMeta = meta || {};
        const $shell = $('<div>', { class: 'll-study-check-card ll-study-check-card--flashcard' });
        const $header = $('<div>', { class: 'll-study-check-header' });
        const $headerTitleWrap = $('<div>');
        const $headerMeta = $('<div>', { class: 'll-study-check-meta' });
        const $round = $('<div>', { class: 'll-study-check-round' });
        const $card = $('<div>', { class: 'll-study-check-flip-card' });
        const $inner = $('<div>', { class: 'll-study-check-card-inner' });
        const $front = $('<div>', { class: 'll-study-check-prompt ll-study-check-face ll-study-check-face--front' });
        const $back = $('<div>', { class: 'll-study-check-prompt ll-study-check-face ll-study-check-face--back' });
        const $actions = $('<div>', { class: 'll-study-check-actions' });

        $('<span>', {
            class: 'll-study-check-title',
            text: roundMeta.title || (msgs.selfCheckTitle || 'Self check')
        }).appendTo($headerTitleWrap);
        if (roundMeta.categoryLabel) {
            $('<span>', {
                class: 'll-study-check-category',
                text: roundMeta.categoryLabel
            }).appendTo($headerTitleWrap);
        }
        if (roundMeta.progressText) {
            $('<span>', {
                class: 'll-study-check-progress',
                text: roundMeta.progressText
            }).appendTo($headerMeta);
        }
        $header.append($headerTitleWrap, $headerMeta);

        // Match dashboard self-check behavior: image prompt first, then image + recordings on answer side.
        renderPromptAndAnswerDisplays($front, $back, targetWord, ctx, msgs);

        let answered = false;
        let phase = 'confidence';
        let pendingConfidence = '';
        let advanceTimer = null;
        let actionToken = 0;

        const clearAdvanceTimer = function () {
            if (advanceTimer) {
                clearTimeout(advanceTimer);
                advanceTimer = null;
            }
        };
        const bumpActionToken = function () {
            actionToken += 1;
            return actionToken;
        };
        const isActionTokenCurrent = function (token) {
            return !answered && token === actionToken;
        };
        const setFlipped = function (isFlipped) {
            $card.toggleClass('is-flipped', !!isFlipped);
        };

        const renderActions = function (options, onSelect) {
            $actions.empty();
            (Array.isArray(options) ? options : []).forEach(function (opt) {
                const label = msgs[opt.labelKey] || opt.fallback || '';
                const $btn = $('<button>', {
                    type: 'button',
                    class: 'll-study-btn ll-study-check-btn ' + opt.className,
                    'data-ll-check-choice': opt.value
                });
                const $icon = buildActionIcon(opt.value);
                if ($icon && $icon.length) {
                    $btn.append($icon);
                }
                $btn.append($('<span>', { class: 'll-study-check-btn-text', text: label }));
                $btn.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    onSelect(String(opt.value || '').toLowerCase());
                });
                $btn.appendTo($actions);
            });
        };

        const finalizeRound = function (confidence, result) {
            if (answered) { return; }
            answered = true;
            clearAdvanceTimer();
            stopPromptAudio();

            const confidenceKey = String(confidence || '').toLowerCase();
            const resultKey = String(result || '').toLowerCase();
            let bucket = 'wrong';
            let isCorrect = false;
            let hadWrongBefore = true;

            if (confidenceKey === 'idk') {
                bucket = 'idk';
                isCorrect = false;
                hadWrongBefore = true;
            } else if (resultKey === 'right') {
                bucket = 'right';
                isCorrect = true;
                hadWrongBefore = false;
            } else if (resultKey === 'close') {
                bucket = 'close';
                isCorrect = true;
                hadWrongBefore = true;
            } else {
                bucket = 'wrong';
                isCorrect = false;
                hadWrongBefore = true;
            }

            try {
                const tracker = getProgressTracker();
                if (tracker && typeof tracker.trackWordExposure === 'function') {
                    tracker.trackWordExposure({
                        mode: 'self-check',
                        wordId: targetWord.id,
                        categoryName: State.currentCategoryName || '',
                        wordsetId: resolveWordsetIdForProgress()
                    });
                }
                if (tracker && typeof tracker.trackWordOutcome === 'function') {
                    tracker.trackWordOutcome({
                        mode: 'self-check',
                        wordId: targetWord.id,
                        categoryName: State.currentCategoryName || '',
                        wordsetId: resolveWordsetIdForProgress(),
                        isCorrect: isCorrect,
                        hadWrongBefore: hadWrongBefore,
                        payload: {
                            self_check_confidence: confidenceKey,
                            self_check_result: resultKey || bucket,
                            self_check_bucket: bucket,
                            forced_prompt: 'image'
                        }
                    });
                }
            } catch (_) { /* no-op */ }

            recordSelfCheckResult(targetWord.id, confidenceKey, bucket);
            State.isFirstRound = false;
            State.hadWrongAnswerThisTurn = hadWrongBefore;

            try {
                if (ctx.FlashcardAudio && typeof ctx.FlashcardAudio.pauseAllAudio === 'function') {
                    ctx.FlashcardAudio.pauseAllAudio();
                }
            } catch (_) { /* no-op */ }

            if (!State || !State.widgetActive) {
                return;
            }

            if (State.forceTransitionTo) {
                State.forceTransitionTo(STATES.QUIZ_READY, 'Self check next word');
            } else if (State.transitionTo) {
                State.transitionTo(STATES.QUIZ_READY, 'Self check next word');
            }
            if (ctx && typeof ctx.startQuizRound === 'function') {
                ctx.startQuizRound();
            }
        };

        const openResultPhase = function (confidence) {
            if (answered) { return; }
            pendingConfidence = String(confidence || '').toLowerCase();
            if (!pendingConfidence) { return; }
            phase = 'result';
            renderActions(SELF_CHECK_ACTIONS_RESULT, function (choice) {
                if (answered) { return; }
                if (choice !== 'wrong' && choice !== 'close' && choice !== 'right') { return; }
                const conf = pendingConfidence === 'idk' ? 'idk' : (pendingConfidence || 'think');
                finalizeRound(conf, choice);
            });
            setActionsDisabled($actions, true);
            const token = bumpActionToken();

            if (pendingConfidence === 'idk') {
                setFlipped(false);
                playIsolationAudio(targetWord, ctx).then(function (played) {
                    if (!isActionTokenCurrent(token)) { return; }
                    clearAdvanceTimer();
                    advanceTimer = setTimeout(function () {
                        if (!isActionTokenCurrent(token)) { return; }
                        finalizeRound('idk', 'idk');
                    }, played ? SELF_CHECK_AUTO_ADVANCE_DELAY_MS : SELF_CHECK_AUDIO_FAIL_DELAY_MS);
                });
                scheduleLayoutFitCheck($shell);
                return;
            }

            setFlipped(true);
            playIsolationAudio(targetWord, ctx).then(function (played) {
                if (!isActionTokenCurrent(token)) { return; }
                const enableResultButtons = function () {
                    if (!isActionTokenCurrent(token)) { return; }
                    setActionsDisabled($actions, false);
                };
                if (played) {
                    enableResultButtons();
                } else {
                    setTimeout(enableResultButtons, SELF_CHECK_AUDIO_FAIL_DELAY_MS);
                }
            });
            scheduleLayoutFitCheck($shell);
        };

        const openConfidencePhase = function () {
            phase = 'confidence';
            pendingConfidence = '';
            setFlipped(false);
            renderActions(SELF_CHECK_ACTIONS_CONFIDENCE, function (choice) {
                if (answered) { return; }
                if (choice !== 'idk' && choice !== 'think' && choice !== 'know') { return; }
                openResultPhase(choice);
            });
            setActionsDisabled($actions, false);
            scheduleLayoutFitCheck($shell);
        };

        $card.append($inner.append($front, $back));
        $round.append($card, $actions);
        $shell.append($header, $round);
        $flashcard.append($shell);
        openConfidencePhase();
    }

    function syncLayoutFit() {
        const doc = root.document;
        if (!doc) { return; }
        const popup = doc.getElementById('ll-tools-flashcard-quiz-popup');
        const content = doc.getElementById('ll-tools-flashcard-content');
        const actions = popup ? popup.querySelector('.ll-study-check-actions') : null;
        if (!popup || !content || !actions) { return; }
        const contentRect = content.getBoundingClientRect();
        const actionsRect = actions.getBoundingClientRect();
        const needsScroll = actionsRect.bottom > (contentRect.bottom - 6);
        popup.classList.toggle('ll-self-check-needs-scroll', needsScroll);
    }

    function scheduleLayoutFitCheck($scope) {
        const run = function () {
            try { syncLayoutFit(); } catch (_) { /* no-op */ }
        };
        if (typeof root.requestAnimationFrame === 'function') {
            root.requestAnimationFrame(run);
            root.requestAnimationFrame(run);
        } else {
            setTimeout(run, 0);
        }
        setTimeout(run, 120);
        setTimeout(run, 320);
        if ($ && $scope && $scope.length) {
            $scope.find('img').off('.llSelfCheckFit').on('load.llSelfCheckFit error.llSelfCheckFit', run);
        }
    }

    function ensureSelfCheckLayout(ctx) {
        const domApi = (ctx && ctx.Dom) || (root.LLFlashcards && root.LLFlashcards.Dom);
        if (domApi && typeof domApi.setSelfCheckLayout === 'function') {
            try {
                domApi.setSelfCheckLayout(true);
            } catch (_) { /* no-op */ }
        }
        if (!$) { return; }
        $('#ll-tools-flashcard-quiz-popup, #ll-tools-flashcard-content, #ll-tools-flashcard-header').addClass('ll-self-check-active');
        $('#ll-tools-flashcard-quiz-popup').removeClass('ll-self-check-needs-scroll');
        $('#ll-tools-category-stack, #ll-tools-category-display, #ll-tools-repeat-flashcard').hide();
        $('#ll-tools-flashcard, #ll-tools-flashcard-content').removeClass('audio-line-layout audio-line-mode');
    }

    function hasAnyWords() {
        const names = Array.isArray(State.categoryNames) ? State.categoryNames : [];
        for (let i = 0; i < names.length; i++) {
            const list = State.wordsByCategory && State.wordsByCategory[names[i]];
            if (Array.isArray(list) && list.length > 0) {
                return true;
            }
        }
        return false;
    }

    function handleNoTarget(ctx) {
        stopPromptAudio();
        if (State.isFirstRound && !hasAnyWords()) {
            if (ctx && typeof ctx.showLoadingError === 'function') {
                ctx.showLoadingError();
            }
            return true;
        }
        if (State.transitionTo) {
            State.transitionTo(STATES.SHOWING_RESULTS, 'Self check complete');
        }
        if (Results && typeof Results.showResults === 'function') {
            Results.showResults();
        }
        return true;
    }

    function initialize() {
        stopPromptAudio();
        ensureSelfCheckLayout();
        // Self-check always runs "all words once" regardless of starred preferences.
        State.starModeOverride = 'normal';
        if (root.llToolsFlashcardsData) {
            root.llToolsFlashcardsData.starModeOverride = 'normal';
        }
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.isGenderMode = false;
        State.isSelfCheckMode = true;
        State.completedCategories = {};
        State.categoryRepetitionQueues = {};
        State.practiceForcedReplays = {};
        return true;
    }

    function onFirstRoundStart() {
        return true;
    }

    function runRound(ctx) {
        ensureSelfCheckLayout(ctx);
        if ($) {
            try { $('#ll-tools-prompt').hide().empty(); } catch (_) { /* no-op */ }
            try { $('#ll-tools-flashcard, #ll-tools-flashcard-content').removeClass('audio-line-layout audio-line-mode'); } catch (_) { /* no-op */ }
        }

        const targetWord = Selection.selectTargetWordAndCategory ? Selection.selectTargetWordAndCategory() : null;
        if (!targetWord) {
            handleNoTarget(ctx);
            return;
        }

        const targetCategoryName = (Selection && typeof Selection.getTargetCategoryName === 'function')
            ? Selection.getTargetCategoryName(targetWord)
            : ((targetWord && targetWord.__categoryName) || State.currentCategoryName);
        const categoryNameForRound = targetCategoryName || State.currentCategoryName;

        if (categoryNameForRound && categoryNameForRound !== State.currentCategoryName) {
            State.currentCategoryName = categoryNameForRound;
            State.currentCategory = (State.wordsByCategory && State.wordsByCategory[categoryNameForRound]) || State.currentCategory;
            if (ctx.Dom && typeof ctx.Dom.updateCategoryNameDisplay === 'function') {
                try { ctx.Dom.updateCategoryNameDisplay(categoryNameForRound); } catch (_) { /* no-op */ }
            }
        }

        const categoryConfig = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(categoryNameForRound)
            : {};
        const optionType = categoryConfig.option_type ||
            (Selection && typeof Selection.getCategoryDisplayMode === 'function'
                ? Selection.getCategoryDisplayMode(categoryNameForRound)
                : (State.currentOptionType || State.DEFAULT_DISPLAY_MODE || 'image'));
        const promptType = categoryConfig.prompt_type || 'audio';

        State.currentOptionType = optionType;
        State.currentPromptType = promptType;

        try {
            const tracker = getProgressTracker();
            if (tracker && typeof tracker.setContext === 'function') {
                tracker.setContext({
                    mode: 'self-check',
                    wordsetId: resolveWordsetIdForProgress()
                });
            }
        } catch (_) { /* no-op */ }

        if (ctx.FlashcardAudio) {
            try {
                if (ctx.Dom && typeof ctx.Dom.disableRepeatButton === 'function') {
                    ctx.Dom.disableRepeatButton();
                    if (ctx.Dom && typeof ctx.Dom.bindRepeatButtonAudio === 'function') {
                        ctx.Dom.bindRepeatButtonAudio(null);
                    }
                }
                if (typeof ctx.FlashcardAudio.setTargetAudioHasPlayed === 'function') {
                    ctx.FlashcardAudio.setTargetAudioHasPlayed(true);
                }
            } catch (_) { /* no-op */ }
        }

        const roundMeta = buildRoundMeta(categoryNameForRound);
        if (ctx.Dom && typeof ctx.Dom.updateSimpleProgress === 'function') {
            try {
                ctx.Dom.updateSimpleProgress(roundMeta.progressCurrent, roundMeta.progressTotal);
            } catch (_) { /* no-op */ }
        }

        const loader = ctx.FlashcardLoader;
        if (!loader || typeof loader.loadResourcesForWord !== 'function') {
            buildRound(targetWord, promptType, optionType, ctx, roundMeta);
            if (ctx.Dom && typeof ctx.Dom.hideLoading === 'function') {
                ctx.Dom.hideLoading();
            }
            if (State.transitionTo) {
                State.transitionTo(STATES.SHOWING_QUESTION, 'Self check question displayed');
            }
            return;
        }

        loader.loadResourcesForWord(targetWord, optionType, categoryNameForRound, categoryConfig).then(function () {
            buildRound(targetWord, promptType, optionType, ctx, roundMeta);
            if (ctx.Dom && typeof ctx.Dom.hideLoading === 'function') {
                ctx.Dom.hideLoading();
            }
            if (State.transitionTo) {
                State.transitionTo(STATES.SHOWING_QUESTION, 'Self check question displayed');
            }
        }).catch(function (err) {
            console.error('Self check round failed:', err);
            // Degrade gracefully instead of leaving a spinner-only screen when a
            // specific resource (image/audio) cannot be loaded for the target word.
            buildRound(targetWord, promptType, optionType, ctx, roundMeta);
            if (ctx.Dom && typeof ctx.Dom.hideLoading === 'function') {
                ctx.Dom.hideLoading();
            }
            if (State.forceTransitionTo) {
                State.forceTransitionTo(STATES.SHOWING_QUESTION, 'Self check fallback question displayed');
            } else if (State.transitionTo) {
                State.transitionTo(STATES.SHOWING_QUESTION, 'Self check fallback question displayed');
            }
        });
    }

    root.LLFlashcards.Modes.SelfCheck = {
        initialize: initialize,
        onFirstRoundStart: onFirstRoundStart,
        runRound: runRound,
        handleNoTarget: handleNoTarget
    };
})(window);
