(function (root) {
    'use strict';

    // Namespaces
    root.LLFlashcards = root.LLFlashcards || {};
    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const Util = (root.LLFlashcards.Util = root.LLFlashcards.Util || {});
    const Dom = (root.LLFlashcards.Dom = root.LLFlashcards.Dom || {});
    const Cards = (root.LLFlashcards.Cards = root.LLFlashcards.Cards || {});
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

    const INTRO_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introSilenceMs : 800;
    const INTRO_WORD_GAP_MS = (root.llToolsFlashcardsData && typeof root.llToolsFlashcardsData.introWordSilenceMs === 'number')
        ? root.llToolsFlashcardsData.introWordSilenceMs : 800;

    // ---- helpers (learning-specific) ----
    function ensureDefaults() {
        if (!State.wordsAnsweredSinceLastIntro) State.wordsAnsweredSinceLastIntro = new Set();
        if (!State.wordCorrectCounts) State.wordCorrectCounts = {};
        if (!Array.isArray(State.wrongAnswerQueue)) State.wrongAnswerQueue = [];
        if (typeof State.turnIndex !== 'number') State.turnIndex = 0;
        if (typeof State.learningCorrectStreak !== 'number') State.learningCorrectStreak = 0;
        if (typeof State.learningChoiceCount !== 'number') State.learningChoiceCount = 2;
        if (typeof State.MIN_CHOICE_COUNT !== 'number') State.MIN_CHOICE_COUNT = 2;
        if (typeof State.MAX_CHOICE_COUNT !== 'number') State.MAX_CHOICE_COUNT = 6;
        if (typeof State.MIN_CORRECT_COUNT !== 'number') State.MIN_CORRECT_COUNT = 3;
        if (!Array.isArray(State.introducedWordIDs)) State.introducedWordIDs = [];
        if (!State.wordIntroductionProgress) State.wordIntroductionProgress = {};

        // Back-compat: if wrongAnswerQueue is an array of IDs, convert to objects with randomized dueTurn
        if (State.wrongAnswerQueue.length && typeof State.wrongAnswerQueue[0] !== 'object') {
            State.wrongAnswerQueue = State.wrongAnswerQueue.map(id => ({
                id,
                dueTurn: State.turnIndex + randomDelay()
            }));
        }
    }

    function syncProgressUI() {
        if (!Dom || typeof Dom.updateLearningProgress !== 'function') return;
        Dom.updateLearningProgress(
            State.introducedWordIDs.length,
            State.totalWordCount,
            State.wordCorrectCounts,
            State.wordIntroductionProgress
        );
    }

    function getCurrentDisplayMode() {
        return (Selection && Selection.getCurrentDisplayMode)
            ? Selection.getCurrentDisplayMode()
            : 'image';
    }

    function applyConstraints(desiredCount) {
        const mode = getCurrentDisplayMode();
        let maxCount = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.maxOptionsOverride)
            ? parseInt(root.llToolsFlashcardsData.maxOptionsOverride, 10)
            : 9;

        if (mode === 'text' || mode === 'text_audio') maxCount = Math.min(maxCount, 4);

        const cfg = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(State.currentCategoryName)
            : null;
        const promptType = cfg ? cfg.prompt_type : null;
        const optionType = cfg ? (cfg.option_type || cfg.mode) : mode;
        const isImagePromptAudio = (promptType === 'image') && (optionType === 'audio' || optionType === 'text_audio');
        if (isImagePromptAudio) {
            maxCount = Math.min(maxCount, 4);
        }

        const introducedCount = State.introducedWordIDs.length;
        maxCount = Math.min(maxCount, introducedCount);
        maxCount = Math.max(2, maxCount);

        return Math.min(desiredCount, maxCount);
    }

    // ----- WRONG-ANSWER SCHEDULING (randomized, like original behavior) -----
    function randomDelay() {
        return (Util && typeof Util.randomInt === 'function')
            ? Util.randomInt(1, 3)
            : (1 + Math.floor(Math.random() * 3)); // 1..3
    }

    function findWrongObj(id) {
        for (let i = 0; i < State.wrongAnswerQueue.length; i++) {
            const it = State.wrongAnswerQueue[i];
            if (it && it.id === id) return it;
        }
        return null;
    }

    function scheduleWrong(id, baseTurn) {
        const obj = findWrongObj(id);
        const due = (typeof baseTurn === 'number' ? baseTurn : State.turnIndex) + randomDelay();
        if (obj) {
            obj.dueTurn = due; // reschedule
        } else {
            State.wrongAnswerQueue.push({ id, dueTurn: due });
        }
    }

    function removeFromWrongQueue(id) {
        State.wrongAnswerQueue = State.wrongAnswerQueue.filter(x => x.id !== id);
    }

    function readyWrongs() {
        const now = State.turnIndex;
        return State.wrongAnswerQueue.filter(x => x.dueTurn <= now);
    }

    function pickReadyWrongWithSpacing() {
        const ready = readyWrongs();
        if (!ready.length) return null;

        // Prefer not to repeat last shown
        const notRepeat = ready.filter(x => x.id !== State.lastWordShownId);
        const pool = notRepeat.length ? notRepeat : ready;

        // Random pick among ready pool
        const pick = pool[Math.floor(Math.random() * pool.length)];
        return pick ? pick.id : null;
    }

    function wordObjectById(wordId) {
        for (let name of (State.categoryNames || [])) {
            const words = (State.wordsByCategory && State.wordsByCategory[name]) || [];
            const word = words.find(w => w.id === wordId);
            if (word) {
                State.currentCategoryName = name;
                State.currentCategory = words;
                return word;
            }
        }
        return null;
    }

    // ---- initialization (called from Selection.initializeLearningMode) ----
    function initialize() {
        ensureDefaults();

        // Build wordsToIntroduce deterministically (single pass) if empty
        if (!Array.isArray(State.wordsToIntroduce) || !State.wordsToIntroduce.length) {
            const allIds = [];
            for (const name of (State.categoryNames || [])) {
                const list = (State.wordsByCategory && State.wordsByCategory[name]) || [];
                for (const w of list) allIds.push(w.id);
            }
            State.wordsToIntroduce = allIds;
            State.totalWordCount = allIds.length;
        }

        State.isLearningMode = true;
        State.isListeningMode = false;
        return true;
    }

    // ---- choice count API (used by main.js) ----
    function getChoiceCount() {
        ensureDefaults();
        return applyConstraints(State.learningChoiceCount);
    }

    // ---- answer bookkeeping (used by main.js on click) ----
    function recordAnswerResult(wordId, isCorrect, hadWrongThisTurn = false) {
        ensureDefaults();

        if (isCorrect) {
            // Only count toward mastery on first try
            if (!hadWrongThisTurn) {
                State.wordCorrectCounts[wordId] = (State.wordCorrectCounts[wordId] || 0) + 1;
                removeFromWrongQueue(wordId);
            }
            State.wordsAnsweredSinceLastIntro.add(wordId);

            // streak up => maybe increase choices
            State.learningCorrectStreak += 1;
            const t = State.learningCorrectStreak;
            const target =
                (t >= 13) ? 6 :
                    (t >= 10) ? 5 :
                        (t >= 6) ? 4 :
                            (t >= 3) ? 3 : 2;

            State.learningChoiceCount = applyConstraints(Math.min(target, State.MAX_CHOICE_COUNT));
        } else {
            // Wrong: schedule with randomized reappear turn (1–3 turns later)
            scheduleWrong(wordId, State.turnIndex);
            State.learningCorrectStreak = 0;
            State.learningChoiceCount = applyConstraints(Math.max(State.MIN_CHOICE_COUNT, State.learningChoiceCount - 1));
        }

        State.currentChoiceCount = State.learningChoiceCount;
    }

    // --- helpers to avoid immediate repeats when only one hard word remains ---
    function pickSprinkleFiller(excludeId) {
        const mastered = State.introducedWordIDs.filter(id => id !== excludeId && (State.wordCorrectCounts[id] || 0) >= State.MIN_CORRECT_COUNT);
        const others = State.introducedWordIDs.filter(id => id !== excludeId);

        const pool = mastered.length ? mastered : others;
        if (!pool.length) return null;

        const pickId = pool[Math.floor(Math.random() * pool.length)];
        return wordObjectById(pickId);
    }

    // ---- selection (introduce or pick next quiz target) ----
    function selectTargetWord() {
        ensureDefaults();
        State.turnIndex++;

        const hasReadyWrongs = readyWrongs().length > 0;
        const everyoneAnsweredThisCycle =
            State.introducedWordIDs.length > 0 &&
            State.introducedWordIDs.every(id => State.wordsAnsweredSinceLastIntro.has(id));

        const notYetIntroduced = (State.wordsToIntroduce || []).filter(id => !State.introducedWordIDs.includes(id));
        const nothingLeftToIntroduce = notYetIntroduced.length === 0;

        // FINISH condition
        const allIntroducedMastered =
            State.introducedWordIDs.length > 0 &&
            State.introducedWordIDs.every(id => (State.wordCorrectCounts[id] || 0) >= State.MIN_CORRECT_COUNT);

        if (nothingLeftToIntroduce && !hasReadyWrongs && allIntroducedMastered) {
            State.isIntroducingWord = false;
            return null; // main.js will show results
        }

        // Bootstrap: introduce TWO words initially
        if (State.introducedWordIDs.length < 2 && notYetIntroduced.length > 0) {
            const take = Math.min(2 - State.introducedWordIDs.length, notYetIntroduced.length);
            const ids = notYetIntroduced.slice(0, take);
            const words = ids.map(id => wordObjectById(id)).filter(Boolean);
            if (words.length) {
                State.isIntroducingWord = true;
                State.wordsAnsweredSinceLastIntro.clear();
                return words; // main.js handleWordIntroduction([...])
            }
        }

        // May we introduce a NEW word this turn?
        const canIntroduceMore = (State.introducedWordIDs.length < 12);
        const mayIntroduce = !hasReadyWrongs && everyoneAnsweredThisCycle && !nothingLeftToIntroduce && canIntroduceMore;

        if (mayIntroduce) {
            const nextId = notYetIntroduced[0];
            const word = wordObjectById(nextId);
            if (word) {
                State.isIntroducingWord = true;
                State.wordsAnsweredSinceLastIntro.clear();
                return [word]; // introduce exactly one after the bootstrap
            }
        }

        // Otherwise, choose a PRACTICE target within the introduced set
        State.isIntroducingWord = false;

        // Priority 1: a READY wrong (randomized schedule, avoid immediate repeat)
        const readyWrongId = pickReadyWrongWithSpacing();
        if (readyWrongId) {
            const wrongWord = wordObjectById(readyWrongId);
            if (wrongWord) {
                // After showing a ready wrong, reschedule it again if answered wrong later (handled in recordAnswerResult)
                State.lastWordShownId = wrongWord.id;
                return wrongWord;
            }
        }

        // Priority 2: those not yet answered in this cycle
        const poolNotAnswered = State.introducedWordIDs.filter(id => !State.wordsAnsweredSinceLastIntro.has(id));
        if (poolNotAnswered.length) {
            // avoid repeat if possible
            const nonRepeat = poolNotAnswered.filter(id => id !== State.lastWordShownId);
            const pick = (nonRepeat.length ? nonRepeat : poolNotAnswered)[Math.floor(Math.random() * (nonRepeat.length ? nonRepeat.length : poolNotAnswered.length))];
            const word = wordObjectById(pick);
            if (word) {
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // Priority 3: least-known (below mastery) — avoid immediate repeat via sprinkle
        const needPractice = State.introducedWordIDs.filter(id => (State.wordCorrectCounts[id] || 0) < State.MIN_CORRECT_COUNT);
        if (needPractice.length) {
            // if exactly one hard word remains and was just shown, sprinkle a filler
            if (needPractice.length === 1 && State.lastWordShownId === needPractice[0]) {
                const filler = pickSprinkleFiller(needPractice[0]);
                if (filler) {
                    State.lastWordShownId = filler.id;
                    return filler;
                }
            }
            const min = Math.min(...needPractice.map(id => State.wordCorrectCounts[id] || 0));
            const pool = needPractice.filter(id => (State.wordCorrectCounts[id] || 0) === min);
            const pick = pool[Math.floor(Math.random() * pool.length)];
            const word = wordObjectById(pick);
            if (word) {
                // try to avoid immediate repeat with a quick sprinkle
                if (word.id === State.lastWordShownId) {
                    const filler = pickSprinkleFiller(word.id);
                    if (filler) {
                        State.lastWordShownId = filler.id;
                        return filler;
                    }
                }
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // Fallback: any introduced (try to avoid repeat)
        if (State.introducedWordIDs.length) {
            const pool = State.introducedWordIDs.filter(id => id !== State.lastWordShownId);
            const id = (pool.length ? pool : State.introducedWordIDs)[Math.floor(Math.random() * (pool.length ? pool.length : State.introducedWordIDs.length))];
            const word = wordObjectById(id);
            if (word) {
                State.lastWordShownId = word.id;
                return word;
            }
        }

        // Nothing viable
        return null;
    }

    function getJQuery() {
        if (root.jQuery) return root.jQuery;
        if (typeof window !== 'undefined' && window.jQuery) return window.jQuery;
        return null;
    }

    function scheduleTimeout(context, fn, delay) {
        if (context && typeof context.setGuardedTimeout === 'function') {
            return context.setGuardedTimeout(fn, delay);
        }
        return setTimeout(fn, delay);
    }

    function introduceWords(words, context) {
        if (!State.isIntroducing()) {
            console.warn('introduceWords called but not in INTRODUCING_WORDS state');
            return;
        }

        const wordsArray = Array.isArray(words) ? words : [words];
        const $jq = getJQuery();

        if ($jq) {
            $jq('#ll-tools-flashcard').removeClass('audio-line-layout').empty();
            $jq('#ll-tools-flashcard-content').removeClass('audio-line-mode');
        } else if (typeof document !== 'undefined') {
            const container = document.getElementById('ll-tools-flashcard');
            if (container) {
                container.classList && container.classList.remove('audio-line-layout');
                container.innerHTML = '';
            }
            const content = document.getElementById('ll-tools-flashcard-content');
            if (content && content.classList) {
                content.classList.remove('audio-line-mode');
            }
        }

        Dom.restoreHeaderUI && Dom.restoreHeaderUI();
        Dom.disableRepeatButton && Dom.disableRepeatButton();
        syncProgressUI();

        const mode = getCurrentDisplayMode();
        const cfg = (Selection && typeof Selection.getCategoryConfig === 'function')
            ? Selection.getCategoryConfig(State.currentCategoryName)
            : {};
        const introMode = (cfg && cfg.prompt_type === 'image' && (mode === 'audio' || mode === 'text_audio'))
            ? 'image'
            : mode;

        Promise.all(wordsArray.map(word => FlashcardLoader.loadResourcesForWord(word, introMode, State.currentCategoryName, cfg))).then(function () {
            if (!State.isIntroducing()) {
                console.warn('State changed during word loading, aborting introduction');
                return;
            }

            wordsArray.forEach((word, index) => {
                const $card = Cards.appendWordToContainer(word, introMode, cfg.prompt_type || 'audio');
                if ($card && typeof $card.attr === 'function') {
                    $card.attr('data-word-index', index);
                }

                const introAudio = FlashcardAudio.selectBestAudio(word, ['introduction']);
                const isoAudio = FlashcardAudio.selectBestAudio(word, ['isolation']);

                let audioPattern;
                if (introAudio && isoAudio && introAudio !== isoAudio) {
                    const useIntroFirst = Math.random() < 0.5;
                    audioPattern = useIntroFirst ? [introAudio, isoAudio, introAudio] : [isoAudio, introAudio, isoAudio];
                } else {
                    const singleAudio = introAudio || isoAudio || word.audio;
                    audioPattern = [singleAudio, singleAudio, singleAudio];
                }

                if ($card && typeof $card.attr === 'function') {
                    $card.attr('data-audio-pattern', JSON.stringify(audioPattern));
                }
            });

            if ($jq) {
                $jq('.flashcard-container').addClass('introducing').css('pointer-events', 'none');
                Dom.hideLoading && Dom.hideLoading();
                $jq('.flashcard-container').fadeIn(600);
            } else {
                Dom.hideLoading && Dom.hideLoading();
            }

            const timeoutId = scheduleTimeout(context, function () {
                playIntroductionSequence(wordsArray, 0, 0, context);
            }, 650);
            State.addTimeout(timeoutId);
        }).catch(function (err) {
            console.error('Error preparing introductions:', err);
            State.forceTransitionTo(STATES.QUIZ_READY, 'Introduction error');
        });
    }

    function playIntroductionSequence(words, wordIndex, repetition, context) {
        const $jq = getJQuery();

        if (State.abortAllOperations || !State.isIntroducing()) {
            return;
        }

        if (wordIndex >= words.length) {
            Dom.enableRepeatButton && Dom.enableRepeatButton();
            if ($jq) {
                $jq('.flashcard-container').removeClass('introducing introducing-active').addClass('fade-out');
            }
            State.isIntroducingWord = false;

            const timeoutId = scheduleTimeout(context, function () {
                if (!State.abortAllOperations) {
                    State.transitionTo(STATES.QUIZ_READY, 'Introduction complete');
                    if (context && typeof context.startQuizRound === 'function') {
                        context.startQuizRound();
                    }
                }
            }, 300);
            State.addTimeout(timeoutId);
            return;
        }

        const currentWord = words[wordIndex];
        let $currentCard = null;
        if ($jq) {
            $currentCard = $jq('.flashcard-container[data-word-index="' + wordIndex + '"]');
            if (!$currentCard.length) {
                console.warn('Card disappeared during introduction');
                return;
            }

            $jq('.flashcard-container').removeClass('introducing-active');
            $currentCard.addClass('introducing-active');
        }

        const timeoutId = scheduleTimeout(context, function () {
            if (State.abortAllOperations || !State.isIntroducing()) return;

            let audioPattern = [];
            if ($currentCard && typeof $currentCard.attr === 'function') {
                const raw = $currentCard.attr('data-audio-pattern') || '[]';
                try { audioPattern = JSON.parse(raw); } catch (_) { audioPattern = []; }
            }
            const audioUrl = audioPattern[repetition] || audioPattern[0];
            const managedAudio = FlashcardAudio.createIntroductionAudio
                ? FlashcardAudio.createIntroductionAudio(audioUrl)
                : null;

            if (!managedAudio) {
                console.error('Failed to create introduction audio');
                return;
            }

            managedAudio.playUntilEnd()
                .then(() => {
                    if (State.abortAllOperations || !managedAudio.isValid() || !State.isIntroducing()) {
                        managedAudio.cleanup();
                        return;
                    }

                    State.wordIntroductionProgress[currentWord.id] =
                        (State.wordIntroductionProgress[currentWord.id] || 0) + 1;

                    syncProgressUI();

                    managedAudio.cleanup();

                    if (repetition < State.AUDIO_REPETITIONS - 1) {
                        if ($currentCard) $currentCard.removeClass('introducing-active');
                        const nextTimeoutId = scheduleTimeout(context, function () {
                            if (!State.abortAllOperations) {
                                playIntroductionSequence(words, wordIndex, repetition + 1, context);
                            }
                        }, INTRO_GAP_MS);
                        State.addTimeout(nextTimeoutId);
                    } else {
                        if (!State.introducedWordIDs.includes(currentWord.id)) {
                            State.introducedWordIDs.push(currentWord.id);
                        }
                        const nextTimeoutId = scheduleTimeout(context, function () {
                            if (!State.abortAllOperations) {
                                playIntroductionSequence(words, wordIndex + 1, 0, context);
                            }
                        }, INTRO_WORD_GAP_MS);
                        State.addTimeout(nextTimeoutId);
                    }
                })
                .catch(err => {
                    console.error('Audio play failed:', err);
                    managedAudio.cleanup();
                });
        }, 100);
        State.addTimeout(timeoutId);
    }

    function onFirstRoundStart() {
        initialize();
        syncProgressUI();
        return true;
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        recordAnswerResult(ctx.targetWord.id, true, ctx.hadWrongThisTurn);
        State.isIntroducingWord = false;
        return true;
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        recordAnswerResult(ctx.targetWord.id, false);
        return true;
    }

    function handleNoTarget(utils) {
        ensureDefaults();
        if (State.isFirstRound && State.totalWordCount === 0) {
            if (utils && typeof utils.showLoadingError === 'function') {
                utils.showLoadingError();
            }
            return true;
        }
        State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        if (Results && typeof Results.showResults === 'function') {
            Results.showResults();
        }
        return true;
    }

    function handlePostSelection(selection, context) {
        ensureDefaults();
        syncProgressUI();

        if (State.isIntroducingWord) {
            if (!State.canIntroduceWords()) {
                console.warn('Cannot introduce words in current state:', State.getState ? State.getState() : '');
                State.isIntroducingWord = false;
                return false;
            }
            State.transitionTo(STATES.INTRODUCING_WORDS, 'Starting word introduction');
            introduceWords(selection, context);
            return true;
        }
        return false;
    }

    function beforeOptionsFill() {
        ensureDefaults();
        const choiceCount = getChoiceCount();
        State.currentChoiceCount = choiceCount;
        if (root.FlashcardOptions && typeof root.FlashcardOptions.initializeOptionsCount === 'function') {
            root.FlashcardOptions.initializeOptionsCount(choiceCount);
        }
        syncProgressUI();
        return true;
    }

    function configureTargetAudio(target) {
        if (State.isIntroducingWord || !target) return;
        const questionAudio = FlashcardAudio.selectBestAudio(target, ['question', 'isolation', 'introduction']);
        if (questionAudio) target.audio = questionAudio;
    }

    // Public API
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    root.LLFlashcards.Modes.Learning = {
        initialize,
        getChoiceCount,
        recordAnswerResult,
        selectTargetWord,
        onCorrectAnswer,
        onWrongAnswer,
        onFirstRoundStart,
        handleNoTarget,
        handlePostSelection,
        beforeOptionsFill,
        configureTargetAudio
    };

    // Back-compat alias for existing calls in main.js
    root.LLFlashcards.LearningMode = root.LLFlashcards.Modes.Learning;

})(window);
