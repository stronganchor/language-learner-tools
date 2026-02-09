(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    const SVG_NS = 'http://www.w3.org/2000/svg';

    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const STATES = State.STATES || {};
    const SelfCheckShared = root.LLToolsSelfCheckShared || {};
    const $ = root.jQuery;
    let currentPromptAudio = null;
    let currentPromptAudioButton = null;

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
            progressText: (total > 0) ? (String(current) + ' / ' + String(total)) : ''
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

    function recordSelfCheckResult(wordId, knowsWord) {
        const idNum = parseInt(wordId, 10);
        if (!idNum) { return; }
        const key = String(idNum);
        const results = ensureQuizResultsShape();
        const stats = results.wordAttempts[key] || { seen: 0, clean: 0, hadWrong: false };
        stats.seen += 1;
        if (knowsWord) {
            stats.clean += 1;
        } else {
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

    function createSvgIcon(viewBox, paths, ariaHidden) {
        const doc = root.document;
        if (!doc || typeof doc.createElementNS !== 'function') {
            return null;
        }

        const svg = doc.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', viewBox);
        svg.setAttribute('fill', 'none');
        if (ariaHidden) {
            svg.setAttribute('aria-hidden', 'true');
        }

        (Array.isArray(paths) ? paths : []).forEach(function (spec) {
            const path = doc.createElementNS(SVG_NS, 'path');
            Object.keys(spec || {}).forEach(function (key) {
                path.setAttribute(key, String(spec[key]));
            });
            svg.appendChild(path);
        });

        return svg;
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
        const $flip = $('<button>', {
            type: 'button',
            class: 'll-study-check-flip',
            'aria-label': msgs.selfCheckFlip || 'Show answer'
        });
        const flipIcon = createSvgIcon(
            '0 0 24 24',
            [
                {
                    d: 'M20 7h-9a6 6 0 1 0 0 12h4',
                    stroke: 'currentColor',
                    'stroke-width': '2',
                    'stroke-linecap': 'round',
                    'stroke-linejoin': 'round'
                },
                {
                    d: 'M20 7l-3-3M20 7l-3 3',
                    stroke: 'currentColor',
                    'stroke-width': '2',
                    'stroke-linecap': 'round',
                    'stroke-linejoin': 'round'
                }
            ],
            true
        );
        if (flipIcon) {
            $flip.append(flipIcon);
        } else {
            $flip.text('↻');
        }
        const $know = $('<button>', {
            type: 'button',
            class: 'll-study-btn ll-study-check-btn ll-study-check-btn--know'
        });
        const $knowIcon = $('<span>', { class: 'll-study-check-icon', 'aria-hidden': 'true' });
        const knowIconSvg = createSvgIcon(
            '0 0 24 24',
            [
                {
                    d: 'M5 13l4 4L19 7',
                    stroke: 'currentColor',
                    'stroke-width': '2.5',
                    'stroke-linecap': 'round',
                    'stroke-linejoin': 'round'
                }
            ],
            false
        );
        if (knowIconSvg) {
            $knowIcon.append(knowIconSvg);
        } else {
            $knowIcon.text('✓');
        }
        $know.append(
            $knowIcon,
            $('<span>', { class: 'll-study-check-btn-text', text: msgs.selfCheckKnow || 'I know it' })
        );
        const $dontKnow = $('<button>', {
            type: 'button',
            class: 'll-study-btn ll-study-check-btn ll-study-check-btn--unknown'
        });
        const $dontKnowIcon = $('<span>', { class: 'll-study-check-icon', 'aria-hidden': 'true' });
        const dontKnowIconSvg = createSvgIcon(
            '0 0 24 24',
            [
                {
                    d: 'M6 6l12 12M18 6l-12 12',
                    stroke: 'currentColor',
                    'stroke-width': '2.5',
                    'stroke-linecap': 'round',
                    'stroke-linejoin': 'round'
                }
            ],
            false
        );
        if (dontKnowIconSvg) {
            $dontKnowIcon.append(dontKnowIconSvg);
        } else {
            $dontKnowIcon.text('✕');
        }
        $dontKnow.append(
            $dontKnowIcon,
            $('<span>', { class: 'll-study-check-btn-text', text: msgs.selfCheckDontKnow || "I don't know it" })
        );

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

        // Match dashboard self-check behavior: show option content first, reveal prompt on answer side.
        appendDisplayContent($front, optionType, targetWord, ctx);
        appendDisplayContent($back, promptType, targetWord, ctx);

        let answered = false;
        $flip.on('click', function () {
            $card.toggleClass('is-flipped', !$card.hasClass('is-flipped'));
        });

        const advanceRound = function (knowsWord) {
            if (answered) { return; }
            answered = true;
            stopPromptAudio();

            recordSelfCheckResult(targetWord.id, !!knowsWord);
            State.isFirstRound = false;
            State.hadWrongAnswerThisTurn = !knowsWord;

            try {
                if (ctx.FlashcardAudio && typeof ctx.FlashcardAudio.pauseAllAudio === 'function') {
                    ctx.FlashcardAudio.pauseAllAudio();
                }
            } catch (_) { /* no-op */ }

            if (State.forceTransitionTo) {
                State.forceTransitionTo(STATES.QUIZ_READY, 'Self check next word');
            } else if (State.transitionTo) {
                State.transitionTo(STATES.QUIZ_READY, 'Self check next word');
            }
            if (ctx && typeof ctx.startQuizRound === 'function') {
                ctx.startQuizRound();
            }
        };

        $know.on('click', function () { advanceRound(true); });
        $dontKnow.on('click', function () { advanceRound(false); });

        $actions.append($know, $dontKnow);
        $card.append($inner.append($front, $back), $flip);
        $round.append($card, $actions);
        $shell.append($header, $round);
        $flashcard.append($shell);
        scheduleLayoutFitCheck($shell);
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
        $('#ll-tools-learning-progress').hide().empty();
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

        const loader = ctx.FlashcardLoader;
        if (!loader || typeof loader.loadResourcesForWord !== 'function') {
            buildRound(targetWord, promptType, optionType, ctx, buildRoundMeta(categoryNameForRound));
            if (ctx.Dom && typeof ctx.Dom.hideLoading === 'function') {
                ctx.Dom.hideLoading();
            }
            if (State.transitionTo) {
                State.transitionTo(STATES.SHOWING_QUESTION, 'Self check question displayed');
            }
            return;
        }

        loader.loadResourcesForWord(targetWord, optionType, categoryNameForRound, categoryConfig).then(function () {
            buildRound(targetWord, promptType, optionType, ctx, buildRoundMeta(categoryNameForRound));
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
            buildRound(targetWord, promptType, optionType, ctx, buildRoundMeta(categoryNameForRound));
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
