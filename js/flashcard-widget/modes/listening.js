(function (root) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};
    const Dom = namespace.Dom || {};
    const Cards = namespace.Cards || {};
    const Results = namespace.Results || {};
    const FlashcardAudio = root.FlashcardAudio;
    const FlashcardLoader = root.FlashcardLoader;
    const STATES = State.STATES || {};

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

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = true;
        // Build a linear list of words across the selected categories in current order
        const all = [];
        if (State.categoryNames && State.wordsByCategory) {
            for (const name of State.categoryNames) {
                const list = State.wordsByCategory[name] || [];
                for (const w of list) all.push(w);
            }
        }
        State.wordsLinear = all;
        State.listenIndex = 0;
        return true;
    }

    function getChoiceCount() {
        // No choices in listening mode
        return 0;
    }

    function recordAnswerResult() {
        // No scoring in passive listening (for now)
        return {};
    }

    function selectTargetWord() {
        // Cycle deterministically for now
        if (!Array.isArray(State.wordsLinear)) {
            const all = [];
            if (State.categoryNames && State.wordsByCategory) {
                for (const name of State.categoryNames) {
                    const list = State.wordsByCategory[name] || [];
                    for (const w of list) all.push(w);
                }
            }
            State.wordsLinear = all;
            State.listenIndex = 0;
        }
        if (!State.wordsLinear.length) return null;
        const word = State.wordsLinear[State.listenIndex % State.wordsLinear.length];
        State.listenIndex++;
        return word;
    }

    function onFirstRoundStart() {
        initialize();
        return true;
    }

    function onCorrectAnswer() { return true; }
    function onWrongAnswer() { return true; }

    function runRound(context) {
        const utils = context || {};
        const loader = (utils.FlashcardLoader && typeof utils.FlashcardLoader.loadResourcesForWord === 'function')
            ? utils.FlashcardLoader
            : FlashcardLoader;
        const audioApi = utils.FlashcardAudio || FlashcardAudio || {};
        const audioVisualizer = namespace.AudioVisualizer;
        const resultsApi = utils.Results || Results;
        const $container = utils.flashcardContainer;
        const $jq = getJQuery();

        if (!loader || typeof loader.loadResourcesForWord !== 'function') {
            console.warn('Listening mode loader unavailable');
            return false;
        }

        const target = selectTargetWord();
        if (!target) {
            if (State.isFirstRound) {
                if (typeof utils.showLoadingError === 'function') {
                    utils.showLoadingError();
                } else {
                    State.transitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                    resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                }
                return true;
            }
            State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
            resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
            return true;
        }

        const audioUrl = audioApi && typeof audioApi.selectBestAudio === 'function'
            ? audioApi.selectBestAudio(target, ['isolation', 'question', 'introduction'])
            : null;
        if (audioUrl) target.audio = audioUrl;

        State.isFirstRound = false;

        loader.loadResourcesForWord(target, 'image').then(function () {
            const setAudioPromise = audioApi && typeof audioApi.setTargetWordAudio === 'function'
                ? audioApi.setTargetWordAudio(target)
                : Promise.resolve();

            Promise.resolve(setAudioPromise).catch(function (e) {
                console.warn('No target audio to set:', e);
                if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                    audioVisualizer.stop();
                }
            });

            Dom.disableRepeatButton && Dom.disableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Listening: playing audio');

            const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
                ? audioApi.getCurrentTargetAudio()
                : null;

            if (audio) {
                if (audioVisualizer && typeof audioVisualizer.followAudio === 'function') {
                    audioVisualizer.followAudio(audio);
                }
                try {
                    if (!audio.paused && Dom.setRepeatButton) {
                        Dom.setRepeatButton('stop');
                    }
                } catch (_) { /* noop */ }

                audio.onended = function () {
                    const revealTimeoutId = scheduleTimeout(utils, function () {
                        if ($container && typeof $container.empty === 'function') {
                            $container.empty();
                        } else if ($jq) {
                            $jq('#ll-tools-flashcard').empty();
                        }

                        const $card = Cards.appendWordToContainer(target);
                        if ($card && typeof $card.fadeIn === 'function') {
                            $card.fadeIn(250);
                        } else if ($jq) {
                            $jq('#ll-tools-flashcard .flashcard-container').show();
                        }

                        Dom.hideLoading && Dom.hideLoading();
                        Dom.setRepeatButton && Dom.setRepeatButton('play');

                        const total = Array.isArray(State.wordsLinear) ? State.wordsLinear.length : 0;
                        const isLast = total > 0 ? ((State.listenIndex || 0) >= total) : false;

                        const advanceTimeoutId = scheduleTimeout(utils, function () {
                            if (isLast) {
                                State.forceTransitionTo(STATES.SHOWING_RESULTS, 'Listening complete');
                                resultsApi && typeof resultsApi.showResults === 'function' && resultsApi.showResults();
                            } else {
                                State.forceTransitionTo(STATES.QUIZ_READY, 'Next listening item');
                                if (typeof utils.runQuizRound === 'function') {
                                    utils.runQuizRound();
                                } else if (typeof utils.startQuizRound === 'function') {
                                    utils.startQuizRound();
                                }
                            }
                        }, 1200);
                        State.addTimeout(advanceTimeoutId);
                    }, 600);
                    State.addTimeout(revealTimeoutId);
                };
                audio.addEventListener('error', function () {
                    if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                        audioVisualizer.stop();
                    }
                }, { once: true });
            } else {
                if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                    audioVisualizer.stop();
                }
                Dom.hideLoading && Dom.hideLoading();
                const $card = Cards.appendWordToContainer(target);
                if ($card && typeof $card.fadeIn === 'function') {
                    $card.fadeIn(250);
                }
                const timeoutId = scheduleTimeout(utils, function () {
                    State.forceTransitionTo(STATES.QUIZ_READY, 'Advance listening (no audio)');
                    if (typeof utils.runQuizRound === 'function') {
                        utils.runQuizRound();
                    } else if (typeof utils.startQuizRound === 'function') {
                        utils.startQuizRound();
                    }
                }, 1200);
                State.addTimeout(timeoutId);
            }
        }).catch(function (err) {
            console.error('Error in listening run:', err);
            if (audioVisualizer && typeof audioVisualizer.stop === 'function') {
                audioVisualizer.stop();
            }
            State.forceTransitionTo(STATES.QUIZ_READY, 'Listening error recovery');
        });
        return true;
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};
    root.LLFlashcards.Modes.Listening = {
        initialize,
        getChoiceCount,
        recordAnswerResult,
        selectTargetWord,
        onFirstRoundStart,
        onCorrectAnswer,
        onWrongAnswer,
        runRound,
        getTotalCount: function () { return (State.wordsLinear || []).length; }
    };

})(window);
