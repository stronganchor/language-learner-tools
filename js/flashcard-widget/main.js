(function (root, $) {
    'use strict';
    // Prevent double-loading this file
    if (window.__LLFlashcardsMainLoaded) { return; }
    window.__LLFlashcardsMainLoaded = true;

    const { Util, State, Dom, Effects, Selection, Cards, Results, StateMachine, ModeConfig } = root.LLFlashcards;
    const { STATES } = State;

    // Timer & Session guards
    let __LLTimers = new Set();
    let __LLSession = 0;
    let closingCleanupPromise = null;
    let initInProgressPromise = null; // prevents concurrent initializations
    let __LastWordsetKey = null;
    let settingsHandlersBound = false;
    let savePrefsTimer = null;

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function setStarModeOverride(mode) {
        const normalized = mode ? normalizeStarMode(mode) : null;
        State.starModeOverride = normalized;
        if (root.llToolsFlashcardsData) {
            root.llToolsFlashcardsData.starModeOverride = normalized;
        }
        return normalized;
    }

    function getCurrentWordsetKey() {
        const ws = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.wordset !== 'undefined')
            ? root.llToolsFlashcardsData.wordset
            : '';
        const fallback = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.wordsetFallback !== 'undefined')
            ? !!root.llToolsFlashcardsData.wordsetFallback
            : true;
        return String(ws || '') + '|' + (fallback ? '1' : '0');
    }

    function resetWordsetScopedCachesIfNeeded() {
        const current = getCurrentWordsetKey();
        if (current === __LastWordsetKey) { return; }
        __LastWordsetKey = current;

        try {
            if (root.FlashcardLoader && typeof root.FlashcardLoader.resetCacheForNewWordset === 'function') {
                root.FlashcardLoader.resetCacheForNewWordset();
            }

            const state = root.LLFlashcards && root.LLFlashcards.State;
            if (state && state.wordsByCategory) {
                Object.keys(state.wordsByCategory).forEach(k => delete state.wordsByCategory[k]);
            }
            if (state && state.categoryRoundCount) {
                Object.keys(state.categoryRoundCount).forEach(k => delete state.categoryRoundCount[k]);
            }
            if (state && Array.isArray(state.initialCategoryNames)) {
                state.initialCategoryNames.length = 0;
            }
            if (state && Array.isArray(state.categoryNames)) {
                state.categoryNames.length = 0;
            }
            if (root.wordsByCategory && typeof root.wordsByCategory === 'object') {
                Object.keys(root.wordsByCategory).forEach(k => delete root.wordsByCategory[k]);
            }
            if (root.categoryRoundCount && typeof root.categoryRoundCount === 'object') {
                Object.keys(root.categoryRoundCount).forEach(k => delete root.categoryRoundCount[k]);
            }
            if (root.categoryNames && Array.isArray(root.categoryNames)) {
                root.categoryNames.length = 0;
            }
        } catch (e) {
            console.warn('Wordset cache reset failed', e);
        }
    }

    function clearPrompt() {
        try {
            $('#ll-tools-prompt').hide().empty();
        } catch (_) {
            try {
                const el = document.getElementById('ll-tools-prompt');
                if (el) {
                    el.style.display = 'none';
                    el.innerHTML = '';
                }
            } catch (_) { /* no-op */ }
        }
    }

    function newSession() {
        __LLSession++;
        __LLTimers.forEach(id => clearTimeout(id));
        __LLTimers.clear();
        clearPrompt();
        try { Dom.clearRepeatButtonBinding && Dom.clearRepeatButtonBinding(); } catch (_) { /* no-op */ }
        setStarModeOverride(null);

        // IMPORTANT: pause the *previous* audio session (snapshot the id now)
        try {
            var sid = (window.FlashcardAudio && typeof window.FlashcardAudio.getCurrentSessionId === 'function')
                ? window.FlashcardAudio.getCurrentSessionId()
                : undefined;
            window.FlashcardAudio.pauseAllAudio(sid);
        } catch (_) { /* no-op */ }
    }

    // Restore the full category list for a fresh session (e.g., after completion)
    function restoreCategorySelection() {
        const source = (Array.isArray(State.initialCategoryNames) && State.initialCategoryNames.length)
            ? State.initialCategoryNames
            : Object.keys(State.wordsByCategory || {});
        const categories = Array.from(new Set((source || []).filter(Boolean)));
        if (!categories.length) { return []; }
        State.categoryNames = categories.slice();
        root.categoryNames = State.categoryNames;
        State.firstCategoryName = State.categoryNames[0] || '';
        return State.categoryNames;
    }


    function setGuardedTimeout(fn, ms) {
        const sessionAtSchedule = __LLSession;
        const id = setTimeout(() => {
            __LLTimers.delete(id);
            if (sessionAtSchedule !== __LLSession) return;
            try { fn(); } catch (e) { console.error('Timeout error:', e); }
        }, ms);
        __LLTimers.add(id);
        return id;
    }

    function shouldAutoplayOptionAudio() {
        if (!State || State.isListeningMode) return false;
        const opt = State.currentOptionType;
        const prompt = State.currentPromptType;
        if (prompt !== 'image') return false;
        if (opt !== 'audio' && opt !== 'text_audio') return false;
        if (typeof State.is === 'function' && !State.is(State.STATES.SHOWING_QUESTION)) return false;
        return $('#ll-tools-flashcard .flashcard-container.audio-option').length > 0;
    }

    function autoplayOptionAudioSequence(initialDelayMs = 700, gapMs = 200) {
        if (!shouldAutoplayOptionAudio()) return;
        const $cards = $('#ll-tools-flashcard .flashcard-container.audio-option');
        const sequence = $cards.toArray().map(el => {
            const $el = $(el);
            return {
                $card: $el,
                url: $el.data('audioUrl') || $el.attr('data-audio-url') || ''
            };
        }).filter(item => !!item.url);
        if (!sequence.length) return;

        let idx = 0;
        const scheduleNext = function () {
            if (!shouldAutoplayOptionAudio()) return;
            if (idx >= sequence.length) return;
            const { $card, url } = sequence[idx++];
            const fauxWord = { audio: url };
            Cards.playOptionAudio(fauxWord, $card).then(function () {
                if (!shouldAutoplayOptionAudio()) return;
                const tid = setTimeout(scheduleNext, gapMs);
                State.addTimeout && State.addTimeout(tid);
            }).catch(function () {
                if (!shouldAutoplayOptionAudio()) return;
                const tid = setTimeout(scheduleNext, gapMs);
                State.addTimeout && State.addTimeout(tid);
            });
        };

        const first = setTimeout(function () {
            if (!shouldAutoplayOptionAudio()) return;
            scheduleNext();
        }, initialDelayMs);
        State.addTimeout && State.addTimeout(first);
    }

    function scheduleAutoplayAfterOptionsReady() {
        if (!shouldAutoplayOptionAudio()) return;
        let started = false;
        const start = function () {
            if (started) return;
            started = true;
            $(document).off('.llAutoplayReady');
            autoplayOptionAudioSequence();
        };
        $(document).off('.llAutoplayReady').on('ll-tools-options-ready.llAutoplayReady', start);
        const fallback = setTimeout(start, 1500);
        State.addTimeout && State.addTimeout(fallback);
    }

    function parseBool(val) {
        if (val === undefined || val === null) return undefined;
        if (typeof val === 'boolean') return val;
        if (typeof val === 'number') return val > 0;
        if (typeof val === 'string') {
            const lowered = val.toLowerCase();
            if (['1', 'true', 'yes', 'on'].includes(lowered)) return true;
            if (['0', 'false', 'no', 'off', ''].includes(lowered)) return false;
        }
        return !!val;
    }

    function isUserLoggedIn() {
        const data = root.llToolsFlashcardsData || {};
        return !!parseBool(data.isUserLoggedIn);
    }

    function ensureStudyPrefs() {
        const payloadState = (root.llToolsStudyData && root.llToolsStudyData.payload && root.llToolsStudyData.payload.state) || null;
        const flashState = root.llToolsFlashcardsData || {};

        if (!root.llToolsStudyPrefs && payloadState) {
            root.llToolsStudyPrefs = {
                starredWordIds: Array.isArray(payloadState.starred_word_ids) ? payloadState.starred_word_ids.slice() : [],
                starMode: payloadState.star_mode || 'normal',
                fastTransitions: parseBool(payloadState.fast_transitions),
                fast_transitions: parseBool(payloadState.fast_transitions)
            };
        }
        if (!root.llToolsStudyPrefs && !payloadState) {
            const starredFromFlash = Array.isArray(flashState.starredWordIds)
                ? flashState.starredWordIds.slice()
                : (Array.isArray(flashState.starred_word_ids) ? flashState.starred_word_ids.slice() : []);
            const starModeFromFlash = flashState.starMode || flashState.star_mode;
            const fastFromFlash = flashState.fastTransitions ?? flashState.fast_transitions;
            if (starredFromFlash.length || starModeFromFlash || typeof fastFromFlash !== 'undefined') {
                root.llToolsStudyPrefs = {
                    starredWordIds: starredFromFlash,
                    starMode: starModeFromFlash || 'normal',
                    fastTransitions: parseBool(fastFromFlash),
                    fast_transitions: parseBool(fastFromFlash)
                };
            }
        }

        root.llToolsStudyPrefs = root.llToolsStudyPrefs || {};
        const prefs = root.llToolsStudyPrefs;

        if (!Array.isArray(prefs.starredWordIds)) {
            prefs.starredWordIds = [];
        }
        if (typeof prefs.starMode === 'undefined' && payloadState) {
            prefs.starMode = payloadState.star_mode || 'normal';
        }

        if (typeof prefs.fastTransitions === 'undefined') {
            const flashDataFastRaw = root.llToolsFlashcardsData && (root.llToolsFlashcardsData.fastTransitions ?? root.llToolsFlashcardsData.fast_transitions);
            const payloadFast = payloadState ? parseBool(payloadState.fast_transitions) : undefined;
            const flashFast = parseBool(flashDataFastRaw);
            if (payloadFast !== undefined) {
                prefs.fastTransitions = payloadFast;
            } else if (flashFast !== undefined) {
                prefs.fastTransitions = flashFast;
            } else {
                prefs.fastTransitions = false;
            }
        }
        if (typeof prefs.fast_transitions === 'undefined') {
            prefs.fast_transitions = prefs.fastTransitions;
        }

        const normalizedStarMode = normalizeStarMode(prefs.starMode || prefs.star_mode || 'normal');
        prefs.starMode = normalizedStarMode;
        prefs.star_mode = normalizedStarMode;

        return prefs;
    }

    // If "starred only" is selected but no starred words exist in the current quiz
    // selection, temporarily fall back to normal mode for this quiz session only
    // without changing the saved user preference.
    function maybeFallbackStarModeForSingleCategoryQuiz(reason) {
        try {
            const prefs = ensureStudyPrefs();
            const flashState = root.llToolsFlashcardsData || {};

            const modeRaw = prefs.starMode || prefs.star_mode || flashState.starMode || flashState.star_mode || 'normal';
            const starMode = normalizeStarMode(modeRaw);
            if (starMode !== 'only') return;
            if (State.starModeOverride) return;

            const canUse = canUseStarOnlyForCurrentSelection();
            if (!canUse) {
                setStarModeOverride('normal');
                State.completedCategories = {};
                restoreCategorySelection();
                if (State.isLearningMode) {
                    const learning = root.LLFlashcards?.Modes?.Learning;
                    if (learning && typeof learning.initialize === 'function') {
                        State.wordsToIntroduce = [];
                        try { learning.initialize(); } catch (err) { console.warn('Learning mode reinit failed', err); }
                    }
                }
                console.warn('LL Tools: No starred words available for this quiz; using normal mode for this quiz only.', reason || '');
                return true;
            }
        } catch (e) {
            console.warn('LL Tools: Star-mode fallback failed', e);
        }
        return false;
    }

    // Determine whether "starred only" should be available for the current selection
    // (at least one starred word exists in the loaded categories).
    function canUseStarOnlyForCurrentSelection() {
        try {
            const prefs = ensureStudyPrefs();
            const starred = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];
            const starredSet = new Set(
                starred
                    .map(function (v) { return parseInt(v, 10) || 0; })
                    .filter(function (n) { return n > 0; })
            );
            if (!starredSet.size) return false; // no starred words at all

            const cats = Array.isArray(State.categoryNames) ? State.categoryNames.filter(Boolean) : [];
            const wordsByCat = State.wordsByCategory || {};
            let inspected = false;

            for (let i = 0; i < cats.length; i++) {
                const list = Array.isArray(wordsByCat[cats[i]]) ? wordsByCat[cats[i]] : [];
                if (list.length) inspected = true;
                for (let j = 0; j < list.length; j++) {
                    const id = parseInt((list[j] && list[j].id), 10) || 0;
                    if (id > 0 && starredSet.has(id)) {
                        return true;
                    }
                }
            }

            // If nothing was inspected (data not loaded yet), do not disable the option.
            if (!inspected) return true;
            return false;
        } catch (e) {
            return true;
        }
    }

    function prefersFastTransitions() {
        const prefs = ensureStudyPrefs();
        if (typeof prefs.fastTransitions !== 'undefined') {
            return !!prefs.fastTransitions;
        }
        if (typeof prefs.fast_transitions !== 'undefined') {
            return !!prefs.fast_transitions;
        }
        return false;
    }

    function ensureQuizResultsShape() {
        State.quizResults = State.quizResults || { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} };
        State.quizResults.wordAttempts = State.quizResults.wordAttempts || {};
        State.quizResults.incorrect = Array.isArray(State.quizResults.incorrect) ? State.quizResults.incorrect : [];
        return State.quizResults;
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
            if (seen <= 0) return;
            if (!hadWrong && clean === seen) {
                correctCount += 1;
            } else {
                const numId = parseInt(key, 10);
                incorrectSet.add(Number.isNaN(numId) ? key : numId);
            }
        });

        results.correctOnFirstTry = correctCount;
        results.incorrect = Array.from(incorrectSet);
        return results;
    }

    function recordWordResult(wordId, hadWrongThisTurn) {
        const idNum = parseInt(wordId, 10);
        if (!idNum) return;
        const key = String(idNum);
        const results = ensureQuizResultsShape();
        const stats = results.wordAttempts[key] || { seen: 0, clean: 0, hadWrong: false };
        stats.seen += 1;
        if (!hadWrongThisTurn) {
            stats.clean += 1;
        } else {
            stats.hadWrong = true;
        }
        results.wordAttempts[key] = stats;
        recomputeQuizResultTotals();
    }

    // --- Study star helpers (user study dashboard only) ---
    const StarManager = (function () {
        let currentWord = null;
        let $starRow = null;
        let $starButton = null;
        let $listeningSlot = null;
        let listeningSlotObserver = null;
        let delegatedBound = false;

        function ensurePrefs() {
            return ensureStudyPrefs();
        }

        function normalizeIds(list) {
            const seen = {};
            const ids = Array.isArray(list) ? list : [];
            return ids.map(id => parseInt(id, 10) || 0)
                .filter(id => id > 0 && !seen[id] && (seen[id] = true));
        }

        function getStarMode() {
            if (State && State.starModeOverride) {
                return normalizeStarMode(State.starModeOverride);
            }
            const prefs = ensurePrefs();
            const modeFromPrefs = prefs.starMode || prefs.star_mode;
            const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
            const mode = modeFromPrefs || modeFromFlash || 'normal';
            return normalizeStarMode(mode);
        }

        function isStarred(wordId) {
            if (!wordId) return false;
            const prefs = ensurePrefs();
            const ids = normalizeIds(prefs.starredWordIds);
            prefs.starredWordIds = ids;
            return ids.includes(parseInt(wordId, 10));
        }

        function setStarState(wordId, desiredState) {
            const prefs = ensurePrefs();
            const ids = normalizeIds(prefs.starredWordIds);
            const targetId = parseInt(wordId, 10);
            if (!targetId) { prefs.starredWordIds = ids; return false; }
            const has = ids.includes(targetId);
            const shouldStar = (typeof desiredState === 'boolean') ? desiredState : !has;
            let next = ids;
            if (shouldStar && !has) {
                next = ids.concat([targetId]);
            } else if (!shouldStar && has) {
                next = ids.filter(id => id !== targetId);
            }
            prefs.starredWordIds = normalizeIds(next);
            if (root.llToolsFlashcardsData) {
                const synced = prefs.starredWordIds.slice();
                root.llToolsFlashcardsData.starredWordIds = synced;
                root.llToolsFlashcardsData.starred_word_ids = synced;
                if (root.llToolsFlashcardsData.userStudyState) {
                    root.llToolsFlashcardsData.userStudyState.starred_word_ids = synced;
                }
            }
            return shouldStar;
        }

        function broadcastStarChange(wordId, starred) {
            try {
                $(document).trigger('lltools:star-changed', [{ wordId: wordId, starred: starred }]);
            } catch (_) { /* no-op */ }
            try { syncSettingsPanelSelections(); } catch (_) { /* no-op */ }
        }

        function getStarButton() {
            if ($starButton && $starButton.length) return $starButton;
            $starButton = $('<button>', {
                type: 'button',
                class: 'll-word-star ll-quiz-star-btn',
                'aria-pressed': 'false',
                'aria-label': 'Star word'
            }).text('☆');
            return $starButton;
        }

        function bindDelegatedClick() {
            if (delegatedBound) return;
            delegatedBound = true;
            $(document).off('click.llStarBtn').on('click.llStarBtn', '.ll-quiz-star-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const targetId = parseInt($(this).attr('data-word-id'), 10) || (currentWord && currentWord.id) || 0;
                if (!targetId) return;
                const found = findWordInState(targetId);
                const wordObj = found ? found.word : currentWord;
                applyStarChange(wordObj || { id: targetId }, !isStarred(targetId));
            });
        }

        function getStarRow() {
            if ($starRow && $starRow.length) return $starRow;
            const $content = $('#ll-tools-flashcard-content');
            if (!$content.length) return null;
            $starRow = $('<div>', { id: 'll-quiz-star-row', class: 'll-quiz-star-row', style: 'display:none;' });
            $content.prepend($starRow);
            return $starRow;
        }

        function getListeningSlot() {
            const $controls = $('#ll-tools-listening-controls');
            if (!$controls || !$controls.length) {
                $listeningSlot = null;
                return null;
            }

            // Prefer an existing slot already in the DOM
            let $slot = $controls.find('.ll-listening-star-slot').first();
            if (!$slot.length && $listeningSlot && $listeningSlot.length) {
                const attached = $listeningSlot.closest('#ll-tools-listening-controls').length;
                if (!attached) {
                    try { $controls.prepend($listeningSlot); } catch (_) { /* no-op */ }
                }
                $slot = $listeningSlot;
            }

            if (!$slot || !$slot.length) {
                $slot = $('<div>', { class: 'll-listening-star-slot', style: 'display:none;' });
                $controls.prepend($slot);
            }

            $listeningSlot = $slot;
            return $slot;
        }

        function stopListeningSlotObserver() {
            if (listeningSlotObserver && typeof listeningSlotObserver.disconnect === 'function') {
                try { listeningSlotObserver.disconnect(); } catch (_) { /* no-op */ }
            }
            listeningSlotObserver = null;
        }

        function watchForListeningSlot(word, attempt) {
            stopListeningSlotObserver();
            if (typeof MutationObserver === 'undefined' || !word || !word.id) return;
            const targetNode = document.getElementById('ll-tools-flashcard-content')
                || document.getElementById('ll-tools-flashcard')
                || document.body;
            if (!targetNode) return;
            const maxAttempts = 5;
            if (attempt >= maxAttempts) return;
            try {
                listeningSlotObserver = new MutationObserver(function () {
                    if (!State || !State.isListeningMode) { stopListeningSlotObserver(); return; }
                    const controls = document.getElementById('ll-tools-listening-controls');
                    if (controls) {
                        stopListeningSlotObserver();
                        try {
                            updateForWord(word, { variant: 'listening', retryAttempt: (attempt || 0) + 1 });
                        } catch (_) { /* no-op */ }
                    }
                });
                listeningSlotObserver.observe(targetNode, { childList: true, subtree: true });
            } catch (_) {
                stopListeningSlotObserver();
            }
        }

        function ensureAudioInlineRow() {
            const $stack = $('#ll-tools-category-stack');
            const $repeat = $('#ll-tools-repeat-flashcard');
            if (!$stack.length || !$repeat.length) return null;
            let $row = $stack.find('.ll-quiz-star-audio-row');
            if (!$row.length) {
                $row = $('<div>', { class: 'll-quiz-star-audio-row' });
                $repeat.detach();
                $row.append($repeat);
                $stack.append($row);
            }
            $row.css('display', 'flex');
            $repeat.show();
            return $row;
        }

        function ensureImageInlineRow() {
            const $prompt = $('#ll-tools-prompt');
            if (!$prompt.length) return null;
            const $stack = $prompt.find('.ll-prompt-stack').first();
            const $imgWrap = $prompt.find('.ll-prompt-image-wrap').first();
            const $promptContent = $stack.length ? $stack : $imgWrap;
            if (!$promptContent.length) return null;

            let $row = $prompt.find('.ll-prompt-star-row');
            if (!$row.length) {
                $row = $('<div>', { class: 'll-prompt-star-row' });
                $promptContent.detach();
                $row.append($('<div>', { class: 'll-quiz-star-inline image-inline', style: 'display:none;' }));
                $row.append($promptContent);
                $row.append($('<div>', { class: 'll-quiz-star-spacer' }));
                $prompt.empty().append($row);
            } else if (!$row.find($promptContent).length) {
                const $existing = $row.find('.ll-prompt-stack, .ll-prompt-image-wrap').first();
                if ($existing.length) {
                    $existing.detach();
                }
                $promptContent.detach();
                const $inline = $row.find('.ll-quiz-star-inline.image-inline').first();
                if ($inline.length) {
                    $inline.after($promptContent);
                } else {
                    $row.append($promptContent);
                }
            }

            let $wrap = $row.find('.ll-quiz-star-inline.image-inline').first();
            if (!$wrap.length) {
                $wrap = $('<div>', { class: 'll-quiz-star-inline image-inline', style: 'display:none;' });
                $row.prepend($wrap);
            }
            if (!$row.find('.ll-quiz-star-spacer').length) {
                $row.append($('<div>', { class: 'll-quiz-star-spacer' }));
            }
            return $wrap;
        }

        function mountButton($target, variant) {
            const $btn = getStarButton();
            if (!$btn) return;
            $btn.toggleClass('listening-variant', variant === 'listening');
            if ($target && $target.length && !$target.has($btn).length) {
                $btn.detach();
                if ($target.hasClass('ll-quiz-star-audio-row')) {
                    $target.prepend($btn);
                } else {
                    $target.append($btn);
                }
            }
        }

        function hide() {
            stopListeningSlotObserver();
            if ($starRow && $starRow.length) { $starRow.hide(); }
            if ($listeningSlot && $listeningSlot.length) { $listeningSlot.hide(); }
            try {
                const $audioRow = $('.ll-quiz-star-audio-row');
                if ($audioRow.length) { $audioRow.find('.ll-quiz-star-btn').hide(); }
                const $imageRow = $('.ll-quiz-star-inline.image-inline');
                if ($imageRow.length) { $imageRow.hide(); }
                const $spacers = $('.ll-quiz-star-spacer');
                if ($spacers.length) { $spacers.hide(); }
            } catch (_) { /* no-op */ }
        }

        function findWordInState(wordId) {
            const idStr = String(wordId);
            if (Array.isArray(State.categoryNames)) {
                for (const cat of State.categoryNames) {
                    const list = State.wordsByCategory && State.wordsByCategory[cat];
                    if (!Array.isArray(list)) continue;
                    const match = list.find(w => String(w.id) === idStr);
                    if (match) return { word: match, category: cat };
                }
            }
            return null;
        }

        function queueStarReplay(wordObj, categoryName) {
            const cname = categoryName || State.currentCategoryName;
            if (!cname || !wordObj) return;
            const queue = (State.categoryRepetitionQueues[cname] = State.categoryRepetitionQueues[cname] || []);
            const exists = queue.some(item => item && item.wordData && item.wordData.id === wordObj.id);
            if (exists) return;
            const base = State.categoryRoundCount[cname] || 0;
            const offset = (Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 3) : (Math.floor(Math.random() * 2) + 2);
            queue.push({ wordData: wordObj, reappearRound: base + offset, forceReplay: true });
        }

        function adjustPractice(wordId, isStarredFlag, starMode) {
            if (starMode === 'normal') return;
            if (!isStarredFlag) {
                const queues = State.categoryRepetitionQueues || {};
                Object.keys(queues).forEach(function (cat) {
                    const list = queues[cat];
                    if (Array.isArray(list)) {
                        queues[cat] = list.filter(item => item && item.wordData && item.wordData.id !== wordId);
                    }
                });
                if (State.practiceForcedReplays) {
                    delete State.practiceForcedReplays[String(wordId)];
                }
            }
            if (isStarredFlag) {
                const idx = State.usedWordIDs.indexOf(wordId);
                if (idx !== -1) State.usedWordIDs.splice(idx, 1);
                if (starMode === 'weighted') {
                    const found = findWordInState(wordId);
                    const wordObj = found ? found.word : currentWord;
                    const cat = found ? found.category : State.currentCategoryName;
                    queueStarReplay(wordObj, cat);
                }
            } else if (starMode === 'only' && !State.usedWordIDs.includes(wordId)) {
                State.usedWordIDs.push(wordId);
            }
        }

        function adjustLearning(wordId, isStarredFlag, starMode) {
            if (!State.isLearningMode) return;
            State.wordsToIntroduce = Array.isArray(State.wordsToIntroduce) ? State.wordsToIntroduce : [];
            State.introducedWordIDs = Array.isArray(State.introducedWordIDs) ? State.introducedWordIDs : [];
            State.wrongAnswerQueue = Array.isArray(State.wrongAnswerQueue) ? State.wrongAnswerQueue : [];
            State.wordCorrectCounts = State.wordCorrectCounts || {};
            State.wordIntroductionProgress = State.wordIntroductionProgress || {};
            if (starMode !== 'only') return;

            if (!isStarredFlag) {
                State.wordsToIntroduce = State.wordsToIntroduce.filter(id => id !== wordId);
                State.introducedWordIDs = State.introducedWordIDs.filter(id => id !== wordId);
                State.wrongAnswerQueue = State.wrongAnswerQueue.filter(item => item && item.id !== wordId);
                delete State.wordCorrectCounts[wordId];
                delete State.wordIntroductionProgress[wordId];
                if (State.wordsAnsweredSinceLastIntro && typeof State.wordsAnsweredSinceLastIntro.delete === 'function') {
                    State.wordsAnsweredSinceLastIntro.delete(wordId);
                }
            } else {
                if (!State.wordsToIntroduce.includes(wordId) && !State.introducedWordIDs.includes(wordId)) {
                    State.wordsToIntroduce.push(wordId);
                }
            }
            const total = new Set([...(State.wordsToIntroduce || []), ...(State.introducedWordIDs || [])]).size;
            State.totalWordCount = total;
        }

        function adjustListening(wordId, isStarredFlag, starMode) {
            const listening = root.LLFlashcards && root.LLFlashcards.Modes && root.LLFlashcards.Modes.Listening;
            if (listening && typeof listening.onStarChange === 'function') {
                try {
                    return !!listening.onStarChange(wordId, isStarredFlag, starMode);
                } catch (_) {
                    return false;
                }
            }
            return false;
        }

        function skipCurrentIfNeeded(wordId, starMode) {
            if (starMode !== 'only') return;
            const currentId = currentWord && currentWord.id ? parseInt(currentWord.id, 10) : null;
            if (!currentId || currentId !== parseInt(wordId, 10)) return;
            State.clearActiveTimeouts();
            try { root.FlashcardAudio && root.FlashcardAudio.pauseAllAudio(); } catch (_) { /* no-op */ }
            State.hadWrongAnswerThisTurn = false;
            State.forceTransitionTo(STATES.QUIZ_READY, 'Star removed during quiz');
            $('#ll-tools-flashcard').empty();
            runQuizRound();
        }

        function applyStarChange(word, desiredState) {
            if (!isUserLoggedIn()) return;
            const wordId = word && word.id ? parseInt(word.id, 10) : 0;
            if (!wordId) return;
            currentWord = word;
            const starredNow = setStarState(wordId, desiredState);
            let starMode = getStarMode();
            if (starMode === 'only' && !starredNow) {
                if (maybeFallbackStarModeForSingleCategoryQuiz('star-change')) {
                    starMode = getStarMode();
                }
            }
            State.starPlayCounts = State.starPlayCounts || {};
            if (State.starPlayCounts[wordId] === undefined) {
                State.starPlayCounts[wordId] = 0;
            }
            if (!starredNow && State.starPlayCounts[wordId] > 1) {
                State.starPlayCounts[wordId] = 1;
            }
            if (starredNow) {
                const foundCat = findWordInState(wordId);
                const catName = foundCat ? foundCat.category : State.currentCategoryName;
                State.completedCategories = State.completedCategories || {};
                if (catName) {
                    State.completedCategories[catName] = false;
                    if (Array.isArray(State.categoryNames) && !State.categoryNames.includes(catName)) {
                        State.categoryNames.push(catName);
                    }
                }
            }
            if (!State.isLearningMode && !State.isListeningMode) {
                adjustPractice(wordId, starredNow, starMode);
                skipCurrentIfNeeded(wordId, starMode);
            } else if (State.isListeningMode) {
                const shouldSkip = adjustListening(wordId, starredNow, starMode);
                if (shouldSkip && starMode === 'only') {
                    skipCurrentIfNeeded(wordId, starMode);
                }
            } else {
                adjustLearning(wordId, starredNow, starMode);
                skipCurrentIfNeeded(wordId, starMode);
            }
            broadcastStarChange(wordId, starredNow);
            updateForWord(currentWord, { variant: State.isListeningMode ? 'listening' : 'content' });
        }

        function updateForWord(word, options) {
            currentWord = word || null;
            if (!isUserLoggedIn()) {
                hide();
                return;
            }
            const prefs = ensurePrefs();
            if (!prefs) {
                hide();
                return;
            }
            const retryAttempt = parseInt((options && options.retryAttempt) || 0, 10) || 0;
            const variant = options && options.variant === 'listening' ? 'listening' : 'content';
            if (!word || !word.id || (!Array.isArray(prefs.starredWordIds) && !prefs.starMode)) {
                hide();
                return;
            }
            const isListening = variant === 'listening';
            let $container = null;
            if (isListening) {
                $container = getListeningSlot();
                if (!$container || !$container.length) {
                    hide();
                    watchForListeningSlot(word, retryAttempt);
                    if (retryAttempt < 4 && word && word.id) {
                        setTimeout(function () {
                            try {
                                if (!State || !State.isListeningMode) return;
                                if (!currentWord || String(currentWord.id) !== String(word.id)) return;
                                updateForWord(word, { variant: 'listening', retryAttempt: retryAttempt + 1 });
                            } catch (_) { /* no-op */ }
                        }, 40 * (retryAttempt + 1));
                    }
                    return;
                }
                stopListeningSlotObserver();
            } else {
                stopListeningSlotObserver();
                const promptType = State && State.currentPromptType ? State.currentPromptType : (root.LLFlashcards && root.LLFlashcards.Selection && typeof root.LLFlashcards.Selection.getCategoryPromptType === 'function'
                    ? root.LLFlashcards.Selection.getCategoryPromptType(State.currentCategoryName)
                    : 'audio');
                if (promptType === 'image') {
                    $container = ensureImageInlineRow() || getStarRow();
                    if ($container && !$container.hasClass('ll-quiz-star-inline')) {
                        $container = ensureImageInlineRow();
                    }
                } else {
                    $container = ensureAudioInlineRow() || getStarRow();
                }
            }
            if (!$container || !$container.length) {
                hide();
                return;
            }
            const $btn = getStarButton();
            if (!$btn) { hide(); return; }
            bindDelegatedClick();
            mountButton($container, variant);
            const active = isStarred(word.id);
            $btn.attr('aria-pressed', active ? 'true' : 'false')
                .text(active ? '★' : '☆')
                .toggleClass('active', active)
                .attr('data-word-id', word.id);
            $btn.show();
            if (isListening) {
                $container.show();
            } else if ($container.hasClass('ll-quiz-star-inline')) {
                $container.show();
                try { $container.closest('.ll-prompt-star-row').find('.ll-quiz-star-spacer').show(); } catch (_) {}
            } else {
                $container.show();
            }
        }

        return {
            updateForWord,
            hide,
            isStarred,
            applyStarChange,
            getStarMode,
            getCurrentWord: function () { return currentWord; }
        };
    })();
    root.LLFlashcards.StarManager = StarManager;

    // Init audio
    root.FlashcardAudio.initializeAudio();
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getCorrectAudioURL());
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getWrongAudioURL());

    function updateModeSwitcherButton() { updateModeSwitcherButtons(); }
    function updateModeSwitcherPanel() { updateModeSwitcherButtons(); }

    function isLearningSupportedForCurrentSelection() {
        try {
            if (Selection && typeof Selection.isLearningSupportedForCategories === 'function') {
                return Selection.isLearningSupportedForCategories(State.categoryNames);
            }
            if (!Selection || typeof Selection.getCategoryConfig !== 'function') return true;
            if (!Array.isArray(State.categoryNames) || State.categoryNames.length === 0) return true;
            return State.categoryNames.every(function (name) {
                const cfg = Selection.getCategoryConfig(name);
                return cfg.learning_supported !== false;
            });
        } catch (e) {
            return true;
        }
    }

    function isGenderSupportedForSelection(categoryNames) {
        try {
            if (Selection && typeof Selection.isGenderSupportedForCategories === 'function') {
                return Selection.isGenderSupportedForCategories(categoryNames);
            }
            if (!Selection || typeof Selection.getCategoryConfig !== 'function') return false;
            const names = Array.isArray(categoryNames) ? categoryNames : [];
            if (!names.length) return false;
            return names.every(function (name) {
                const cfg = Selection.getCategoryConfig(name);
                return cfg.gender_supported === true;
            });
        } catch (e) {
            return false;
        }
    }

    function isGenderSupportedForCurrentSelection() {
        return isGenderSupportedForSelection(State.categoryNames);
    }



    const MODE_SWITCH_CONFIG = (ModeConfig && typeof ModeConfig.getSwitchConfig === 'function') ?
        ModeConfig.getSwitchConfig() : {
            practice: {
                label: 'Switch to Practice Mode',
                icon: '❓',
                className: 'practice-mode'
            },
            learning: {
                label: 'Switch to Learning Mode',
                icon: '🎓',
                className: 'learning-mode'
            },
            listening: {
                label: 'Switch to Listening Mode',
                icon: '🎧',
                className: 'listening-mode'
            },
            gender: {
                label: 'Switch to Gender Mode',
                icon: '',
                className: 'gender-mode'
            }
        };

    function getActiveModeModule() {
        const Modes = root.LLFlashcards && root.LLFlashcards.Modes;
        if (!Modes) return null;
        if (State.isGenderMode && Modes.Gender) return Modes.Gender;
        if (State.isListeningMode && Modes.Listening) return Modes.Listening;
        if (State.isLearningMode && Modes.Learning) return Modes.Learning;
        return Modes.Practice || null;
    }

    function callModeHook(name, ...args) {
        const module = getActiveModeModule();
        if (module && typeof module[name] === 'function') {
            try {
                return module[name](...args);
            } catch (err) {
                console.error(`Mode hook ${name} failed:`, err);
            }
        }
        return undefined;
    }

    function getSettingsElements() {
        return {
            $wrap: $('#ll-tools-mode-switcher-wrap'),
            $panel: $('#ll-tools-settings-panel'),
            $button: $('#ll-tools-settings-button')
        };
    }

    function syncStarModeButtons($buttons, options) {
        if (!$buttons || !$buttons.length) return;
        const prefs = ensureStudyPrefs();
        const starMode = normalizeStarMode(prefs.starMode || prefs.star_mode || 'normal');
        const defaultCanUse = canUseStarOnlyForCurrentSelection();
        const hasOverride = options && Object.prototype.hasOwnProperty.call(options, 'canUseStarOnly');
        const override = hasOverride ? options.canUseStarOnly : null;

        $buttons.each(function () {
            const $btn = $(this);
            const val = String($btn.data('star-mode') || '');
            if (!val) { return; }
            let allowOnly = defaultCanUse;
            if (typeof override === 'function') {
                try {
                    allowOnly = !!override(this, $btn);
                } catch (_) {
                    allowOnly = defaultCanUse;
                }
            } else if (typeof override === 'boolean') {
                allowOnly = override;
            }

            const isActive = val === starMode;
            const shouldDisable = (val === 'only') && !allowOnly;

            $btn.toggleClass('active', isActive).attr('aria-pressed', isActive ? 'true' : 'false');

            if (shouldDisable) {
                $btn.prop('disabled', true)
                    .attr('aria-disabled', 'true')
                    .removeClass('active')
                    .attr('aria-pressed', 'false');
            } else {
                $btn.prop('disabled', false)
                    .attr('aria-disabled', 'false');
            }
        });
    }

    function syncSettingsPanelSelections() {
        const { $panel } = getSettingsElements();
        if (!$panel.length) return;
        const fast = !!prefersFastTransitions();
        syncStarModeButtons($panel.find('[data-star-mode]'));

        $panel.find('[data-speed]').each(function () {
            const val = String($(this).data('speed') || '');
            const isFast = val === 'fast';
            const isActive = isFast === fast || (!isFast && !fast && val !== 'fast');
            $(this).toggleClass('active', isActive).attr('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function setSettingsOpen(isOpen) {
        const { $panel, $button } = getSettingsElements();
        if (!$panel.length || !$button.length) return;
        const open = !!isOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        if (open) {
            syncSettingsPanelSelections();
        }
    }

    function updateStudyPrefs(updates) {
        const prefs = ensureStudyPrefs();
        if (updates && typeof updates === 'object') {
            if (updates.starMode) {
                const normalized = normalizeStarMode(updates.starMode);
                prefs.starMode = normalized;
                prefs.star_mode = normalized;
            }
            if (typeof updates.fastTransitions === 'boolean') {
                prefs.fastTransitions = updates.fastTransitions;
                prefs.fast_transitions = updates.fastTransitions;
            }
        }
        root.llToolsStudyPrefs = prefs;
        if (root.llToolsFlashcardsData) {
            if (prefs.starMode) {
                root.llToolsFlashcardsData.starMode = prefs.starMode;
                root.llToolsFlashcardsData.star_mode = prefs.starMode;
            }
            if (typeof prefs.fastTransitions !== 'undefined') {
                root.llToolsFlashcardsData.fastTransitions = !!prefs.fastTransitions;
                root.llToolsFlashcardsData.fast_transitions = !!prefs.fastTransitions;
            }
            if (root.llToolsFlashcardsData.userStudyState) {
                const state = root.llToolsFlashcardsData.userStudyState;
                state.star_mode = prefs.starMode || state.star_mode;
                state.fast_transitions = typeof prefs.fastTransitions !== 'undefined'
                    ? !!prefs.fastTransitions
                    : state.fast_transitions;
                if (Array.isArray(prefs.starredWordIds)) {
                    state.starred_word_ids = prefs.starredWordIds.slice();
                }
            }
        }
        return prefs;
    }

    function buildStudyPrefsPayload() {
        const prefs = ensureStudyPrefs();
        const data = root.llToolsFlashcardsData || {};
        const baseState = data.userStudyState || {};
        const normalizeIds = function (list) {
            const seen = {};
            return (Array.isArray(list) ? list : []).map(function (val) { return parseInt(val, 10) || 0; })
                .filter(function (id) { return id > 0 && !seen[id] && (seen[id] = true); });
        };

        const starMode = normalizeStarMode(prefs.starMode || prefs.star_mode || data.starMode || data.star_mode || 'normal');
        const fastTransitions = prefs.fastTransitions ?? prefs.fast_transitions ?? data.fastTransitions ?? data.fast_transitions ?? false;

        return {
            wordset_id: parseInt(baseState.wordset_id, 10) || 0,
            category_ids: normalizeIds(baseState.category_ids || []),
            starred_word_ids: normalizeIds(prefs.starredWordIds || prefs.starred_word_ids || []),
            star_mode: starMode,
            fast_transitions: !!fastTransitions
        };
    }

    function persistStudyPrefs() {
        if (!isUserLoggedIn()) return;
        const ajaxUrl = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.ajaxurl) || '';
        const nonce = root.llToolsFlashcardsData && root.llToolsFlashcardsData.userStudyNonce;
        if (!ajaxUrl || !nonce) return;

        const payload = buildStudyPrefsPayload();
        $.post(ajaxUrl, Object.assign({
            action: 'll_user_study_save',
            nonce: nonce
        }, payload)).done(function (res) {
            if (res && res.success && res.data && res.data.state && root.llToolsFlashcardsData) {
                root.llToolsFlashcardsData.userStudyState = res.data.state;
            }
        }).fail(function (err) {
            console.warn('LL Tools: failed to save study prefs', err);
        });
    }

    function persistStudyPrefsDebounced() {
        if (savePrefsTimer) {
            clearTimeout(savePrefsTimer);
        }
        savePrefsTimer = setTimeout(persistStudyPrefs, 250);
    }

    function applyStudyPrefsFromUI(updates) {
        const prefs = updateStudyPrefs(updates);
        syncSettingsPanelSelections();
        if (updates && updates.starMode) {
            maybeFallbackStarModeForSingleCategoryQuiz();
        }
        setSettingsOpen($('#ll-tools-settings-panel').attr('aria-hidden') === 'false');
        persistStudyPrefsDebounced();
        return prefs;
    }

    function bindSettingsPanel() {
        const { $wrap, $panel, $button } = getSettingsElements();
        if (!$wrap.length || !$button.length || !$panel.length) return;

        if (!isUserLoggedIn()) {
            $button.hide();
            $panel.hide();
            $(document).off('.llSettings');
            settingsHandlersBound = false;
            return;
        }

        $wrap.addClass('ll-has-settings');
        $button.show();
        $panel.show().attr('aria-hidden', 'true');
        syncSettingsPanelSelections();

        if (settingsHandlersBound) return;
        settingsHandlersBound = true;

        $button.off('.llSettings').on('click.llSettings', function (e) {
            e.preventDefault();
            const open = $panel.attr('aria-hidden') === 'false';
            setSettingsOpen(!open);
            setMenuOpen(false);
        });

        $panel.off('.llSettings')
            .on('click.llSettings', '[data-star-mode]', function (e) {
                e.preventDefault();
                if ($(this).is(':disabled') || $(this).attr('aria-disabled') === 'true') return;
                const mode = String($(this).data('star-mode') || '');
                if (!mode) return;
                applyStudyPrefsFromUI({ starMode: mode });
            })
            .on('click.llSettings', '[data-speed]', function (e) {
                e.preventDefault();
                const speed = String($(this).data('speed') || '');
                applyStudyPrefsFromUI({ fastTransitions: speed === 'fast' });
            });

        $(document).off('.llSettings').on('pointerdown.llSettings', function (e) {
            if ($panel.attr('aria-hidden') === 'true') return;
            if ($(e.target).closest('#ll-tools-settings-control').length) return;
            setSettingsOpen(false);
        }).on('keydown.llSettings', function (e) {
            if (e.key === 'Escape') {
                setSettingsOpen(false);
            }
        });
    }

    function setMenuOpen(isOpen) {
        const $wrap = $('#ll-tools-mode-switcher-wrap');
        const $toggle = $('#ll-tools-mode-switcher');
        if (!$wrap.length || !$toggle.length) return;
        $wrap.attr('aria-expanded', isOpen ? 'true' : 'false');
        $wrap.attr('data-menu-open', isOpen ? 'true' : 'false');
        $toggle.attr('aria-expanded', isOpen ? 'true' : 'false');
        $('#ll-tools-mode-menu').attr('aria-hidden', isOpen ? 'false' : 'true');
        if (isOpen) {
            setSettingsOpen(false);
        }
    }

    function refreshModeMenuOptions() {
        const current = State.isGenderMode ? 'gender'
            : (State.isListeningMode ? 'listening' : (State.isLearningMode ? 'learning' : 'practice'));
        const $wrap = $('#ll-tools-mode-switcher-wrap');
        const $menu = $('#ll-tools-mode-menu');
        if (!$wrap.length || !$menu.length) return;
        const learningAllowed = isLearningSupportedForCurrentSelection();
        const genderAllowed = isGenderSupportedForCurrentSelection();

        // Ensure wrapper is visible when widget active
        $wrap.css('display', 'block');

        // Update icons/labels and active state
        ['learning', 'practice', 'gender', 'listening'].forEach(function (mode) {
            const cfg = MODE_SWITCH_CONFIG[mode] || {};
            const $btn = $menu.find('.ll-tools-mode-option.' + mode);
            if (!$btn.length) return;
            // Update icon emoji
            const $icon = $btn.find('.mode-icon');
            if ($icon.length) {
                if (cfg.svg) {
                    $icon.html(cfg.svg);
                    $icon.removeAttr('data-emoji');
                } else if (cfg.icon) {
                    $icon.empty();
                    $icon.attr('data-emoji', cfg.icon);
                } else {
                    $icon.empty();
                    $icon.removeAttr('data-emoji');
                }
            }
            const shouldHide = (mode === 'gender' && !genderAllowed);
            $btn.toggleClass('hidden', shouldHide);
            if (shouldHide) {
                $btn.attr('aria-hidden', 'true');
                $btn.removeClass('active disabled');
                $btn.removeAttr('disabled').removeAttr('aria-checked').removeAttr('aria-disabled');
                return;
            }
            $btn.removeAttr('aria-hidden');
            // Active vs inactive
            const isActive = (mode === current);
            const isDisabled = (mode === 'learning' && !learningAllowed)
                || (mode === 'gender' && !genderAllowed);
            $btn.toggleClass('active', isActive);
            $btn.toggleClass('disabled', isDisabled);
            if (isActive || isDisabled) { $btn.attr({ 'disabled': 'disabled', 'aria-checked': isActive ? 'true' : 'false', 'aria-disabled': 'true' }); }
            else { $btn.removeAttr('disabled').attr({ 'aria-checked': 'false' }).removeAttr('aria-disabled'); }
        });
    }

    function bindModeMenuHandlers() {
        const $wrap = $('#ll-tools-mode-switcher-wrap');
        const $toggle = $('#ll-tools-mode-switcher');
        const $menu = $('#ll-tools-mode-menu');
        if (!$wrap.length || !$toggle.length || !$menu.length) return;

        // Toggle open/close
        $toggle.off('.llModeMenu').on('click.llModeMenu', function (e) {
            e.preventDefault();
            const open = $wrap.attr('aria-expanded') === 'true';
            setMenuOpen(!open);
        });

        // Select a mode from menu
        $menu.off('.llModeMenu').on('click.llModeMenu', '.ll-tools-mode-option', function (e) {
            e.preventDefault();
            const mode = String($(this).data('mode') || '');
            if (!mode) return;
            if ($(this).hasClass('disabled')) { return; }
            // Keep menu open even after switching; ignore clicks on active option
            if ($(this).hasClass('active')) { return; }
            switchMode(mode);
        });

        // Outside click / Escape closes
        $(document).off('.llModeMenu').on('pointerdown.llModeMenu', function (e) {
            const open = $wrap.attr('aria-expanded') === 'true';
            if (!open) return;
            if ($(e.target).closest('#ll-tools-mode-switcher-wrap').length) return;
            setMenuOpen(false);
        }).on('keydown.llModeMenu', function (e) {
            if (e.key === 'Escape') { setMenuOpen(false); }
        });
    }

    function updateModeSwitcherButtons() {
        refreshModeMenuOptions();
        bindModeMenuHandlers();
        bindSettingsPanel();
        syncSettingsPanelSelections();
    }

    function switchMode(newMode) {
        if (!State.canSwitchMode()) {
            console.warn('Cannot switch mode in state:', State.getState());
            return;
        }

        if (newMode === 'learning' && !isLearningSupportedForCurrentSelection()) {
            console.warn('Learning mode is disabled for the selected categories. Falling back to practice.');
            newMode = 'practice';
        }
        if (newMode === 'gender' && !isGenderSupportedForCurrentSelection()) {
            console.warn('Gender mode is disabled for the selected categories. Falling back to practice.');
            newMode = 'practice';
        }

        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) $btn.prop('disabled', true).attr('aria-busy', 'true');

        // Hide any listening-mode specific UI when switching away
        try {
            $('#ll-tools-listening-controls').remove();
            $('#ll-tools-listening-visualizer').remove();
        } catch (_) { /* no-op */ }
        clearPrompt();

        State.transitionTo(STATES.SWITCHING_MODE, 'User requested mode switch');

        // Stop anything in-flight right now.
        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        $('#ll-tools-learning-progress').hide().empty();

        root.FlashcardAudio.startNewSession().then(function () {
            const targetMode = newMode || (State.isLearningMode ? 'practice' : 'learning');

            // Full reset for a clean session
            State.reset();

            // IMPORTANT: allow operations again before we start the next round
            State.abortAllOperations = false;

            State.isLearningMode = (targetMode === 'learning');
            State.isListeningMode = (targetMode === 'listening');
            State.isGenderMode = (targetMode === 'gender');
            restoreCategorySelection();

            const activeModule = getActiveModeModule();
            if (activeModule && typeof activeModule.initialize === 'function') {
                try { activeModule.initialize(); } catch (err) { console.error('Mode initialization failed:', err); }
            }

            if (root.LLFlashcards?.Results?.hideResults) {
                root.LLFlashcards.Results.hideResults();
            }

            $('#ll-tools-flashcard').empty();
            Dom.restoreHeaderUI();
            updateModeSwitcherPanel();

            // Kick off fresh load
            State.transitionTo(STATES.LOADING, 'Mode switch complete, reloading');
            startQuizRound();

            setTimeout(function () {
                if ($btn.length) {
                    $btn.prop('disabled', false).removeAttr('aria-busy');
                }
            }, 1500);
        }).catch(function (err) {
            console.error('Error during mode switch:', err);
            State.forceTransitionTo(STATES.IDLE, 'Mode switch error');
            if ($btn.length) $btn.prop('disabled', false).removeAttr('aria-busy');
        });
    }

    function onCorrectAnswer(targetWord, $correctCard) {
        if (!State.canProcessAnswer()) {
            console.warn('Cannot process answer in state:', State.getState());
            return;
        }
        if (State.userClickedCorrectAnswer) return;

        State.transitionTo(STATES.PROCESSING_ANSWER, 'Correct answer');
        $correctCard.addClass('correct-answer');

        const rect = $correctCard[0].getBoundingClientRect();
        Effects.startConfetti({
            particleCount: 20,
            angle: 90,
            spread: 60,
            origin: {
                x: (rect.left + rect.width / 2) / window.innerWidth,
                y: (rect.top + rect.height / 2) / window.innerHeight
            },
            duration: 50
        });

        State.userClickedCorrectAnswer = true;
        const isPracticeMode = !State.isLearningMode && !State.isListeningMode;
        const useFastTransitions = prefersFastTransitions() && (isPracticeMode || State.isLearningMode);
        const recordResultForWord = function () {
            recordWordResult(targetWord.id, State.hadWrongAnswerThisTurn);
        };

        callModeHook('onCorrectAnswer', {
            targetWord,
            $correctCard,
            hadWrongThisTurn: State.hadWrongAnswerThisTurn
        });

        const goToNextRound = function () {
            State.isFirstRound = false;
            State.userClickedCorrectAnswer = false;
            State.transitionTo(STATES.QUIZ_READY, 'Ready for next question');
            startQuizRound();
        };

        const fadeOtherCards = function () {
            $('.flashcard-container').not($correctCard).addClass('fade-out');
        };

        if (useFastTransitions) {
            recordResultForWord();
            try {
                if (root.FlashcardAudio) {
                    if (typeof root.FlashcardAudio.fadeOutAllAudio === 'function') {
                        root.FlashcardAudio.fadeOutAllAudio(180);
                    } else if (typeof root.FlashcardAudio.pauseAllAudio === 'function') {
                        root.FlashcardAudio.pauseAllAudio();
                    }
                    if (typeof root.FlashcardAudio.playFeedback === 'function') {
                        root.FlashcardAudio.playFeedback(true, null, null);
                    }
                    if (typeof root.FlashcardAudio.fadeOutFeedbackAudio === 'function') {
                        // Let the ding play a bit longer, then gently fade so the next round starts clean
                        setGuardedTimeout(function () {
                            root.FlashcardAudio.fadeOutFeedbackAudio(140, 'correct');
                        }, 210);
                    }
                }
            } catch (_) { /* no-op */ }

            fadeOtherCards();
            setGuardedTimeout(function () {
                $correctCard.addClass('fade-out');
            }, 120);
            setGuardedTimeout(function () {
                goToNextRound();
            }, 360);
            return;
        }

        const advanceOnce = (function () {
            let done = false;
            return function () {
                if (done) return;
                done = true;
                recordResultForWord();
                fadeOtherCards();
                setGuardedTimeout(goToNextRound, 600);
            };
        })();

        const audioApi = root.FlashcardAudio;
        if (audioApi && typeof audioApi.playFeedback === 'function') {
            try {
                audioApi.playFeedback(true, null, advanceOnce);
                setGuardedTimeout(advanceOnce, 1000);
                return;
            } catch (_) {
                // continue to fallback below
            }
        }
        advanceOnce();
    }

    function onWrongAnswer(targetWord, index, $wrong) {
        if (!State.canProcessAnswer()) {
            console.warn('Cannot process answer in state:', State.getState());
            return;
        }
        if (State.userClickedCorrectAnswer) return;
        ensureQuizResultsShape();

        callModeHook('onWrongAnswer', {
            targetWord,
            index,
            $wrong
        });

        State.hadWrongAnswerThisTurn = true;
        root.FlashcardAudio.playFeedback(false, targetWord.audio, null);
        const isAudioLineLayout = (State.currentPromptType === 'image') &&
            (State.currentOptionType === 'audio' || State.currentOptionType === 'text_audio');

        if (isAudioLineLayout) {
            $wrong.addClass('ll-option-disabled').attr('aria-disabled', 'true');
        } else {
            $wrong.addClass('fade-out').one('transitionend', function () { $wrong.remove(); });
        }

        const wrongId = parseInt(targetWord.id, 10) || targetWord.id;
        if (!State.quizResults.incorrect.includes(wrongId)) State.quizResults.incorrect.push(wrongId);
        State.wrongIndexes.push(index);

        if (!isAudioLineLayout && State.wrongIndexes.length === 2) {
            $('.flashcard-container').not(function () {
                const id = String($(this).data('wordId') || $(this).attr('data-word-id') || '');
                return id === String(targetWord.id);
            }).remove();
        }
    }

    function startQuizRound(number_of_options) {
        if (State.isFirstRound) {
            if (!State.is(STATES.LOADING)) {
                const transitioned = State.transitionTo(STATES.LOADING, 'First round initialization');
                if (!transitioned) {
                    State.forceTransitionTo(STATES.LOADING, 'Forced first round initialization');
                }
            }

            const firstThree = State.categoryNames.slice(0, 3).filter(Boolean);
            if (!firstThree.length) {
                console.error('Flashcards: No categories available to start quiz round');
                showLoadingError();
                return;
            }

            const bootstrapFirstRound = function () {
                // Ensure "starred only" can't accidentally produce a no-words error for single-category popups.
                maybeFallbackStarModeForSingleCategoryQuiz();

                root.FlashcardOptions.initializeOptionsCount(number_of_options);

                callModeHook('onFirstRoundStart', {
                    numberOfOptions: number_of_options
                });

                updateModeSwitcherPanel();
                const ready = State.transitionTo(STATES.QUIZ_READY, 'Resources loaded');
                if (!ready) {
                    State.forceTransitionTo(STATES.QUIZ_READY, 'Forced ready state after load');
                }
                runQuizRound();
            };

            root.FlashcardLoader.loadResourcesForCategory(firstThree[0], bootstrapFirstRound);

            for (let i = 1; i < firstThree.length; i++) {
                root.FlashcardLoader.loadResourcesForCategory(firstThree[i]);
            }
        } else {
            if (!State.is(STATES.QUIZ_READY)) {
                const ready = State.transitionTo(STATES.QUIZ_READY, 'Continuing quiz round');
                if (!ready) {
                    State.forceTransitionTo(STATES.QUIZ_READY, 'Forced continuation state');
                }
            }
            runQuizRound();
        }
    }

    function runQuizRound() {
        if (!State.canStartQuizRound()) {
            console.warn('Cannot start quiz round in state:', State.getState());
            return;
        }

        State.clearActiveTimeouts();
        clearPrompt();
        try { StarManager && StarManager.hide(); } catch (_) { /* no-op */ }
        const $flashcardContainer = $('#ll-tools-flashcard');

        // Preserve listening-mode footprint between skips to prevent control/button jumps
        if (State.isListeningMode) {
            const measuredHeight = Math.max(0, Math.round($flashcardContainer.outerHeight()));
            const fallbackHeight = measuredHeight || Math.max(0, Math.round(State.listeningLastHeight || 0));
            if (fallbackHeight > 0) {
                State.listeningLastHeight = fallbackHeight;
                $flashcardContainer.css('min-height', fallbackHeight + 'px');
            }
        } else {
            $flashcardContainer.css('min-height', '');
        }

        $flashcardContainer.show();
        $flashcardContainer.empty();
        Dom.restoreHeaderUI();

        root.FlashcardAudio.pauseAllAudio();
        Dom.showLoading();
        root.FlashcardAudio.setTargetAudioHasPlayed(false);
        State.hadWrongAnswerThisTurn = false;

        const modeModule = getActiveModeModule();

        if (modeModule && typeof modeModule.runRound === 'function') {
            modeModule.runRound({
                setGuardedTimeout,
                startQuizRound,
                runQuizRound,
                showLoadingError,
                Dom,
                Cards,
                Results,
                FlashcardLoader: root.FlashcardLoader,
                FlashcardAudio: root.FlashcardAudio,
                flashcardContainer: $flashcardContainer
            });
            return;
        }

        let target = null;
        if (modeModule && typeof modeModule.selectTargetWord === 'function') {
            target = modeModule.selectTargetWord();
        } else {
            target = Selection.selectTargetWordAndCategory();
        }

        if (!target) {
            const handled = modeModule && typeof modeModule.handleNoTarget === 'function'
                ? Boolean(modeModule.handleNoTarget({
                    showLoadingError,
                    Results,
                    State,
                    runQuizRound,
                    startQuizRound
                }))
                : false;

            if (handled) {
                return;
            }

            if (State.isFirstRound) {
                let hasWords = false;
                for (let catName of State.categoryNames) {
                    if (State.wordsByCategory[catName] && State.wordsByCategory[catName].length > 0) {
                        hasWords = true;
                        break;
                    }
                }
                if (!hasWords) {
                    showLoadingError();
                    return;
                }
            }
            State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
            Results.showResults();
            return;
        }

        if (modeModule && typeof modeModule.handlePostSelection === 'function') {
            const handled = modeModule.handlePostSelection(target, {
                setGuardedTimeout,
                startQuizRound,
                runQuizRound,
                showLoadingError,
                Dom,
                Cards,
                FlashcardLoader: root.FlashcardLoader,
                FlashcardAudio: root.FlashcardAudio
            });
            if (handled) {
                return;
            }
        }

        const targetCategoryName = (Selection && typeof Selection.getTargetCategoryName === 'function')
            ? Selection.getTargetCategoryName(target)
            : ((target && target.__categoryName) || State.currentCategoryName);
        const categoryNameForRound = targetCategoryName || State.currentCategoryName;
        if (categoryNameForRound && categoryNameForRound !== State.currentCategoryName) {
            State.currentCategoryName = categoryNameForRound;
            State.currentCategory = State.wordsByCategory[categoryNameForRound] || State.currentCategory;
            try { Dom.updateCategoryNameDisplay(categoryNameForRound); } catch (_) { /* no-op */ }
        }

        const categoryConfig = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(categoryNameForRound)
            : {};
        const displayMode = categoryConfig.option_type ||
            (Selection && typeof Selection.getCategoryDisplayMode === 'function'
                ? Selection.getCategoryDisplayMode(categoryNameForRound)
                : Selection.getCurrentDisplayMode());
        const promptType = categoryConfig.prompt_type || 'audio';
        State.currentOptionType = displayMode;
        State.currentPromptType = promptType;

        root.FlashcardLoader.loadResourcesForWord(target, displayMode, categoryNameForRound, categoryConfig).then(function () {
            if (modeModule && typeof modeModule.beforeOptionsFill === 'function') {
                modeModule.beforeOptionsFill(target);
            }
            Selection.fillQuizOptions(target);
            try {
                StarManager.updateForWord(target, { variant: State.isListeningMode ? 'listening' : 'content' });
            } catch (_) { /* no-op */ }

            if (modeModule && typeof modeModule.configureTargetAudio === 'function') {
                modeModule.configureTargetAudio(target);
            }

            if (promptType === 'audio') {
                root.FlashcardAudio.setTargetAudioHasPlayed(false);
                root.FlashcardAudio.setTargetWordAudio(target);
                Dom.enableRepeatButton();
                try {
                    const targetAudioEl = root.FlashcardAudio.getCurrentTargetAudio
                        ? root.FlashcardAudio.getCurrentTargetAudio()
                        : null;
                    if (Dom.bindRepeatButtonAudio) Dom.bindRepeatButtonAudio(targetAudioEl);
                } catch (_) { /* no-op */ }
            } else {
                root.FlashcardAudio.setTargetAudioHasPlayed(true);
                Dom.disableRepeatButton();
                try { Dom.bindRepeatButtonAudio && Dom.bindRepeatButtonAudio(null); } catch (_) { /* no-op */ }
            }
            Dom.hideLoading();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Question displayed');
            scheduleAutoplayAfterOptionsReady();
        }).catch(function (err) {
            console.error('Error in runQuizRound:', err);
            State.forceTransitionTo(STATES.QUIZ_READY, 'Error recovery');
        });
    }

    function showLoadingError() {
        const msgs = root.llToolsFlashcardsMessages || {};
        $('#quiz-results-title').text(msgs.loadingError || 'Loading Error');

        const errorBullets = [
            msgs.checkCategoryExists || 'The category exists and has words',
            msgs.checkWordsAssigned || 'Words are properly assigned to the category',
            msgs.checkWordsetFilter || 'If using wordsets, the wordset contains words for this category'
        ];

        const errorMessage = (msgs.noWordsFound || 'No words could be loaded for this quiz. Please check that:') +
            '<br>• ' + errorBullets.join('<br>• ');

        $('#quiz-results-message').html(errorMessage).show();
        $('#quiz-results').show();
        $('#correct-count').parent().hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();
        State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Error state');
    }

    function initFlashcardWidget(selectedCategories, mode) {
        // Deduplicate concurrent init calls; reuse in-flight promise
        if (initInProgressPromise) {
            return initInProgressPromise;
        }

        const proceed = () => {
            resetWordsetScopedCachesIfNeeded();
            newSession();

            // Clear any leftover overlays/flags from a previous popup session
            try {
                if (root.LLFlashcards?.Dom?.hideAutoplayBlockedOverlay) {
                    root.LLFlashcards.Dom.hideAutoplayBlockedOverlay();
                }
                if (root.FlashcardAudio?.clearAutoplayBlock) {
                    root.FlashcardAudio.clearAutoplayBlock();
                }
            } catch (_) { }
            $('#ll-tools-flashcard').css('pointer-events', 'auto');

            $('#ll-tools-learning-progress').hide().empty();

            if (State.is(STATES.CLOSING)) {
                State.forceTransitionTo(STATES.IDLE, 'Reopening during close');
            } else if (!State.canTransitionTo(STATES.LOADING)) {
                State.forceTransitionTo(STATES.IDLE, 'Resetting before initialization');
            }

            // Ensure new sessions always begin from a clean first-round state
            State.isFirstRound = true;
            State.hadWrongAnswerThisTurn = false;

            if (!State.is(STATES.LOADING)) {
                const movedToLoading = State.transitionTo(STATES.LOADING, 'Widget initialization');
                if (!movedToLoading) {
                    State.forceTransitionTo(STATES.LOADING, 'Forcing loading state during init');
                }
            }

            return root.FlashcardAudio.startNewSession().then(function () {
                const requestedCategories = Array.isArray(selectedCategories)
                    ? selectedCategories.filter(Boolean)
                    : (selectedCategories ? [selectedCategories] : []);
                let requestedMode = mode;
                if (requestedMode === 'learning' && !isLearningSupportedForCurrentSelection()) {
                    console.warn('Learning mode is disabled for the selected categories. Using practice mode instead.');
                    requestedMode = 'practice';
                }
                if (requestedMode === 'gender' && !isGenderSupportedForSelection(requestedCategories)) {
                    console.warn('Gender mode is disabled for the selected categories. Using practice mode instead.');
                    requestedMode = 'practice';
                }

                State.isLearningMode = false;
                State.isListeningMode = false;
                State.isGenderMode = false;
                if (requestedMode === 'learning') {
                    State.isLearningMode = true;
                } else if (requestedMode === 'listening') {
                    State.isListeningMode = true;
                } else if (requestedMode === 'gender') {
                    State.isGenderMode = true;
                }

                const activeModule = getActiveModeModule();
                if (activeModule && typeof activeModule.initialize === 'function') {
                    try { activeModule.initialize(); } catch (err) { console.error('Mode initialization failed:', err); }
                }

                if (State.widgetActive) return;
                State.widgetActive = true;

                if (root.LLFlashcards?.Results?.hideResults) {
                    root.LLFlashcards.Results.hideResults();
                }

                const cleanedCategories = Array.isArray(selectedCategories)
                    ? selectedCategories.filter(Boolean)
                    : (selectedCategories ? [selectedCategories] : []);
                State.initialCategoryNames = Util.randomlySort(cleanedCategories);
                State.categoryNames = State.initialCategoryNames.slice();
                root.categoryNames = State.categoryNames;
                State.firstCategoryName = State.categoryNames[0] || State.firstCategoryName;
                root.FlashcardLoader.loadResourcesForCategory(State.firstCategoryName);
                Dom.updateCategoryNameDisplay(State.firstCategoryName);

                $('body').addClass('ll-tools-flashcard-open');
                $('#ll-tools-close-flashcard').off('click').on('click', closeFlashcard);
                $('#ll-tools-flashcard-header').show();

                $('#ll-tools-repeat-flashcard').off('click').on('click', function () {
                    const audio = root.FlashcardAudio.getCurrentTargetAudio();
                    if (!audio) return;
                    if (!audio.paused && !audio.ended) {
                        try { audio.pause(); audio.currentTime = 0; } catch (_) { /* ignore */ }
                        Dom.setRepeatButton && Dom.setRepeatButton('play');
                    } else {
                        try { audio.currentTime = 0; } catch (_) { /* no-op */ }
                        const playPromise = audio.play();
                        if (playPromise && typeof playPromise.catch === 'function') {
                            playPromise.catch(() => { });
                        }
                        Dom.setRepeatButton && Dom.setRepeatButton('stop');
                    }
                });

                // Initialize mode switcher UI (single toggle + expanding menu)
                $('#ll-tools-mode-switcher-wrap').show();
                updateModeSwitcherPanel();
                $('#restart-practice-mode').off('click').on('click', () => switchMode('practice'));
                $('#restart-learning-mode').off('click').on('click', () => switchMode('learning'));
                $('#restart-gender-mode').off('click').on('click', () => switchMode('gender'));
                $('#restart-listening-mode').off('click').on('click', () => switchMode('listening'));
                $('#restart-quiz').off('click').on('click', restartQuiz);

                Dom.showLoading();
                updateModeSwitcherPanel();
                startQuizRound();

                // One-time "kick" to start audio on first user gesture if autoplay was blocked
                $('#ll-tools-flashcard-content')
                    .off('.llAutoplayKick')
                    .on('pointerdown.llAutoplayKick keydown.llAutoplayKick', function () {
                        const $content = $('#ll-tools-flashcard-content');
                        try {
                            const audioApi = root.FlashcardAudio;
                            const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
                                ? audioApi.getCurrentTargetAudio()
                                : null;
                            const alreadyPlayed = audioApi && typeof audioApi.getTargetAudioHasPlayed === 'function'
                                ? audioApi.getTargetAudioHasPlayed()
                                : false;

                            if (!audio || alreadyPlayed) {
                                $content.off('.llAutoplayKick');
                                return;
                            }

                            if (!audio.paused && !audio.ended) {
                                $content.off('.llAutoplayKick');
                                return;
                            }

                            const done = () => $content.off('.llAutoplayKick');
                            const playPromise = audio.play();
                            if (playPromise && typeof playPromise.finally === 'function') {
                                playPromise.catch(() => { }).finally(done);
                            } else if (playPromise && typeof playPromise.then === 'function') {
                                playPromise.then(done).catch(done);
                            } else {
                                done();
                            }
                        } catch (_) {
                            $content.off('.llAutoplayKick');
                        }
                    });
            }).catch(function (err) {
                console.error('Failed to start audio session:', err);
                State.forceTransitionTo(STATES.IDLE, 'Initialization error');
            });
        };

        // If a close is still cleaning up, wait, then proceed once
        const kickoff = (closingCleanupPromise
            ? closingCleanupPromise.catch(err => {
                console.warn('Waiting for previous flashcard cleanup before reopening', err);
            }).then(proceed)
            : Promise.resolve().then(proceed)
        );

        // Track in-flight init; clear when finished (success or failure)
        initInProgressPromise = kickoff.finally(() => {
            initInProgressPromise = null;
        });

        return initInProgressPromise;
    }

    function restorePageScroll() {
        // Remove class and clear overflow inline styles on both body/html
        const clear = function (el) {
            if (!el) return;
            try {
                if (el.classList) {
                    el.classList.remove('ll-tools-flashcard-open', 'll-qpg-popup-active');
                }
                el.style && (el.style.overflow = '');
            } catch (_) { /* ignore */ }
        };
        try { clear(document.body); clear(document.documentElement); } catch (_) { /* ignore */ }
        try {
            $('body').removeClass('ll-tools-flashcard-open ll-qpg-popup-active').css('overflow', '');
            $('html').css('overflow', '');
        } catch (_) { /* ignore */ }
    }

    function closeFlashcard() {
        if (closingCleanupPromise) {
            return closingCleanupPromise;
        }

        // Immediately restore scrolling (belt-and-suspenders)
        restorePageScroll();

        if (!State.is(STATES.CLOSING)) {
            State.transitionTo(STATES.CLOSING, 'User closed widget');
        }

        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        newSession();
        try { StarManager && StarManager.hide(); } catch (_) { /* no-op */ }

        // Clean up any overlay/gesture hooks immediately
        try {
            if (root.LLFlashcards?.Dom?.hideAutoplayBlockedOverlay) {
                root.LLFlashcards.Dom.hideAutoplayBlockedOverlay();
            }
            if (root.FlashcardAudio?.clearAutoplayBlock) {
                root.FlashcardAudio.clearAutoplayBlock();
            }
        } catch (_) { }
        $('#ll-tools-flashcard-content').off('.llAutoplayKick');
        $('#ll-tools-flashcard').css('pointer-events', 'auto');

        // Ensure any confetti canvas is removed when closing the widget
        try {
            const confettiCanvas = document.getElementById('confetti-canvas');
            if (confettiCanvas && confettiCanvas.parentNode) {
                confettiCanvas.parentNode.removeChild(confettiCanvas);
            }
            if (root.confetti && typeof root.confetti.reset === 'function') {
                root.confetti.reset();
            }
        } catch (_) { }

        try {
            if (root.FlashcardAudio?.setTargetWordAudio) {
                // Clear any current target audio element immediately to avoid late playback
                root.FlashcardAudio.setTargetWordAudio(null);
            }
        } catch (_) { }

        const stopAudioPromise = (() => {
            if (!root.FlashcardAudio) return Promise.resolve();

            const audioApi = root.FlashcardAudio;
            const tasks = [];

            try {
                if (typeof audioApi.flushAllAudioSessions === 'function') {
                    tasks.push(audioApi.flushAllAudioSessions());
                } else {
                    if (typeof audioApi.pauseAllAudio === 'function') {
                        tasks.push(audioApi.pauseAllAudio());
                        tasks.push(audioApi.pauseAllAudio(-1));
                    }
                }

                if (typeof audioApi.setTargetAudioHasPlayed === 'function') {
                    audioApi.setTargetAudioHasPlayed(true);
                }
            } catch (_) { }

            if (!tasks.length) return Promise.resolve();
            return Promise.all(tasks.map(p => Promise.resolve(p).catch(() => undefined))).then(() => undefined);
        })();

        const cleanupPromise = Promise.resolve(stopAudioPromise)
            .catch(function (err) {
                console.warn('Failed to pause audio before cleanup:', err);
            })
            .then(function () {
                if (root.FlashcardAudio && typeof root.FlashcardAudio.startNewSession === 'function') {
                    return root.FlashcardAudio.startNewSession();
                }
            })
            .catch(function (err) {
                console.error('Failed to start audio cleanup session:', err);
            })
            .then(function () {
                if (root.LLFlashcards?.Results?.hideResults) {
                    root.LLFlashcards.Results.hideResults();
                }

                if (State && typeof State.reset === 'function') {
                State.reset();
                State.categoryNames = [];
            } else {
                console.warn('Flashcard State unavailable during cleanup');
            }
            $('#ll-tools-flashcard').empty();
            $('#ll-tools-flashcard-header').hide();
            $('#ll-tools-flashcard-quiz-popup').hide();
            $('#ll-tools-flashcard-popup').hide();
            $('#ll-tools-listening-controls').remove();
            // Hide mode switcher and unbind menu handlers
            $('#ll-tools-mode-switcher-wrap').hide();
            setSettingsOpen(false);
            $(document).off('.llModeMenu');
            $('#ll-tools-learning-progress').hide().empty();
            restorePageScroll();

            State.transitionTo(STATES.IDLE, 'Cleanup complete');
        })
            .catch(function (err) {
                console.error('Flashcard cleanup encountered an error:', err);
                State.forceTransitionTo(STATES.IDLE, 'Cleanup error');
            });

        closingCleanupPromise = cleanupPromise.finally(function () {
            closingCleanupPromise = null;
        });

        return closingCleanupPromise;
    }

    function restartQuiz() {
        newSession();
        $('#ll-tools-learning-progress').hide().empty();
        // Remove any listening-mode specific UI
        $('#ll-tools-listening-controls').remove();
        const wasLearning = State.isLearningMode;
        const wasListening = State.isListeningMode;
        const wasGender = State.isGenderMode;
        State.reset();
        State.isLearningMode = wasLearning;
        State.isListeningMode = wasListening;
        State.isGenderMode = wasGender;
        restoreCategorySelection();
        const module = getActiveModeModule();
        if (module && typeof module.initialize === 'function') {
            try { module.initialize(); } catch (err) { console.error('Mode initialization failed during restart:', err); }
        }
        root.LLFlashcards.Results.hideResults();
        $('#ll-tools-flashcard').empty();
        Dom.restoreHeaderUI();
        updateModeSwitcherPanel();
        State.transitionTo(STATES.QUIZ_READY, 'Quiz restarted');
        startQuizRound();
    }

    if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.categoriesPreselected) {
        root.FlashcardLoader.processFetchedWordData(root.llToolsFlashcardsData.firstCategoryData, State.firstCategoryName);
        root.FlashcardLoader.preloadCategoryResources(State.firstCategoryName);
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.StudySettings = {
        normalizeStarMode: normalizeStarMode,
        syncStarModeButtons: syncStarModeButtons,
        getStarMode: function () {
            const prefs = ensureStudyPrefs();
            const fallback = root.llToolsFlashcardsData || {};
            return normalizeStarMode(prefs.starMode || prefs.star_mode || fallback.starMode || fallback.star_mode || 'normal');
        },
        applyStarMode: function (mode) {
            const normalized = normalizeStarMode(mode);
            if (!normalized) return;
            applyStudyPrefsFromUI({ starMode: normalized });
        },
        canUseStarOnlyForCurrentSelection: canUseStarOnlyForCurrentSelection
    };
    root.LLFlashcards.Main = { initFlashcardWidget, startQuizRound, runQuizRound, onCorrectAnswer, onWrongAnswer, closeFlashcard, restartQuiz, switchMode };
    root.initFlashcardWidget = initFlashcardWidget;
})(window, jQuery);
