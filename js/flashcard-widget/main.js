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

    function newSession() {
        __LLSession++;
        __LLTimers.forEach(id => clearTimeout(id));
        __LLTimers.clear();

        // IMPORTANT: pause the *previous* audio session (snapshot the id now)
        try {
            var sid = (window.FlashcardAudio && typeof window.FlashcardAudio.getCurrentSessionId === 'function')
                ? window.FlashcardAudio.getCurrentSessionId()
                : undefined;
            window.FlashcardAudio.pauseAllAudio(sid);
        } catch (_) { /* no-op */ }
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

    // Init audio
    root.FlashcardAudio.initializeAudio();
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getCorrectAudioURL());
    root.FlashcardLoader.loadAudio(root.FlashcardAudio.getWrongAudioURL());

    function updateModeSwitcherButton() { updateModeSwitcherButtons(); }
    function updateModeSwitcherPanel() { updateModeSwitcherButtons(); }



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
            }
        };

    function getActiveModeModule() {
        const Modes = root.LLFlashcards && root.LLFlashcards.Modes;
        if (!Modes) return null;
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

    function setMenuOpen(isOpen) {
        const $wrap = $('#ll-tools-mode-switcher-wrap');
        const $toggle = $('#ll-tools-mode-switcher');
        if (!$wrap.length || !$toggle.length) return;
        $wrap.attr('aria-expanded', isOpen ? 'true' : 'false');
        $toggle.attr('aria-expanded', isOpen ? 'true' : 'false');
        $('#ll-tools-mode-menu').attr('aria-hidden', isOpen ? 'false' : 'true');
    }

    function refreshModeMenuOptions() {
        const current = State.isListeningMode ? 'listening' : (State.isLearningMode ? 'learning' : 'practice');
        const $wrap = $('#ll-tools-mode-switcher-wrap');
        const $menu = $('#ll-tools-mode-menu');
        if (!$wrap.length || !$menu.length) return;

        // Ensure wrapper is visible when widget active
        $wrap.css('display', 'block');

        // Update icons/labels and active state
        ['learning', 'practice', 'listening'].forEach(function(mode){
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
            // Active vs inactive
            const isActive = (mode === current);
            $btn.toggleClass('active', isActive);
            if (isActive) { $btn.attr({ 'disabled': 'disabled', 'aria-checked': 'true', 'aria-disabled': 'true' }); }
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
    }

    function switchMode(newMode) {
        if (!State.canSwitchMode()) {
            console.warn('Cannot switch mode in state:', State.getState());
            return;
        }

        const $btn = $('#ll-tools-mode-switcher');
        if ($btn.length) $btn.prop('disabled', true).attr('aria-busy', 'true');

        // Hide any listening-mode specific UI when switching away
        try {
            $('#ll-tools-listening-controls').remove();
            $('#ll-tools-listening-visualizer').remove();
        } catch (_) { /* no-op */ }

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

        callModeHook('onCorrectAnswer', {
            targetWord,
            $correctCard,
            hadWrongThisTurn: State.hadWrongAnswerThisTurn
        });

        root.FlashcardAudio.playFeedback(true, null, function () {
            if (!State.quizResults.incorrect.includes(targetWord.id)) {
                State.quizResults.correctOnFirstTry += 1;
            }
            $('.flashcard-container').not($correctCard).addClass('fade-out');
            setTimeout(function () {
                State.isFirstRound = false;
                State.userClickedCorrectAnswer = false;
                State.transitionTo(STATES.QUIZ_READY, 'Ready for next question');
                startQuizRound();
            }, 600);
        });
    }

    function onWrongAnswer(targetWord, index, $wrong) {
        if (!State.canProcessAnswer()) {
            console.warn('Cannot process answer in state:', State.getState());
            return;
        }
        if (State.userClickedCorrectAnswer) return;

        callModeHook('onWrongAnswer', {
            targetWord,
            index,
            $wrong
        });

        State.hadWrongAnswerThisTurn = true;
        root.FlashcardAudio.playFeedback(false, targetWord.audio, null);
        $wrong.addClass('fade-out').one('transitionend', function () { $wrong.remove(); });

        if (!State.quizResults.incorrect.includes(targetWord.id)) State.quizResults.incorrect.push(targetWord.id);
        State.wrongIndexes.push(index);

        const mode = Selection.getCurrentDisplayMode();
        if (State.wrongIndexes.length === 2) {
            $('.flashcard-container').not(function () {
                return (mode === 'image') ?
                    ($(this).find('img').attr('src') === targetWord.image) :
                    ($(this).find('.quiz-text').text() === (targetWord.label || ''));
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
        const $flashcardContainer = $('#ll-tools-flashcard');
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

        const displayMode = Selection.getCurrentDisplayMode();
        root.FlashcardLoader.loadResourcesForWord(target, displayMode).then(function () {
            if (modeModule && typeof modeModule.beforeOptionsFill === 'function') {
                modeModule.beforeOptionsFill(target);
            }
            Selection.fillQuizOptions(target);

            if (modeModule && typeof modeModule.configureTargetAudio === 'function') {
                modeModule.configureTargetAudio(target);
            }

            root.FlashcardAudio.setTargetWordAudio(target);
            Dom.hideLoading();
            Dom.enableRepeatButton();
            State.transitionTo(STATES.SHOWING_QUESTION, 'Question displayed');
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
                if (mode === 'learning') {
                    State.isLearningMode = true;
                } else if (mode === 'listening') {
                    State.isListeningMode = true;
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

                State.categoryNames = Util.randomlySort(selectedCategories || []);
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
                    if (!audio.paused) {
                        audio.pause(); audio.currentTime = 0; Dom.setRepeatButton('play');
                    } else {
                        audio.play().then(() => { Dom.setRepeatButton('stop'); }).catch(() => { });
                        audio.onended = function () { Dom.setRepeatButton('play'); };
                    }
                });

                // Initialize mode switcher UI (single toggle + expanding menu)
                $('#ll-tools-mode-switcher-wrap').show();
                updateModeSwitcherPanel();
                $('#restart-practice-mode').off('click').on('click', () => switchMode('practice'));
                $('#restart-learning-mode').off('click').on('click', () => switchMode('learning'));
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

    function closeFlashcard() {
        if (closingCleanupPromise) {
            return closingCleanupPromise;
        }

        if (!State.is(STATES.CLOSING)) {
            State.transitionTo(STATES.CLOSING, 'User closed widget');
        }

        State.abortAllOperations = true;
        State.clearActiveTimeouts();
        newSession();

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

        const cleanupPromise = root.FlashcardAudio.startNewSession()
            .catch(function (err) {
                console.error('Failed to start audio cleanup session:', err);
            })
            .then(function () {
                if (root.LLFlashcards?.Results?.hideResults) {
                    root.LLFlashcards.Results.hideResults();
                }

                State.reset();
                State.categoryNames = [];
                $('#ll-tools-flashcard').empty();
                $('#ll-tools-flashcard-header').hide();
                $('#ll-tools-flashcard-quiz-popup').hide();
                $('#ll-tools-flashcard-popup').hide();
                $('#ll-tools-listening-controls').remove();
                // Hide mode switcher and unbind menu handlers
                $('#ll-tools-mode-switcher-wrap').hide();
                $(document).off('.llModeMenu');
                $('#ll-tools-learning-progress').hide().empty();
                $('body').removeClass('ll-tools-flashcard-open');

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
        State.reset();
        State.isLearningMode = wasLearning;
        State.isListeningMode = wasListening;
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
    root.LLFlashcards.Main = { initFlashcardWidget, startQuizRound, runQuizRound, onCorrectAnswer, onWrongAnswer, closeFlashcard, restartQuiz, switchMode };
    root.initFlashcardWidget = initFlashcardWidget;
})(window, jQuery);









