(function (root, $) {
    'use strict';

    const namespace = (root.LLFlashcards = root.LLFlashcards || {});
    const State = namespace.State || {};

    // Centralized icon configuration
    const ICON_CONFIG = {
        size: 32,
        color: '#000000', // Change this single value to update all icons
        strokeWidth: 2
    };

    // Icon generator functions
    function createPlayIcon() {
        return `<span class="icon-container"><svg width="${ICON_CONFIG.size}" height="${ICON_CONFIG.size}" viewBox="0 0 32 32" fill="${ICON_CONFIG.color}"><path d="M10 6v20l16-10z" stroke="${ICON_CONFIG.color}" stroke-width="${ICON_CONFIG.strokeWidth}" stroke-linejoin="round"/></svg></span>`;
    }

    function createStopIcon() {
        return `<span class="icon-container"><svg width="${ICON_CONFIG.size}" height="${ICON_CONFIG.size}" viewBox="0 0 32 32" fill="${ICON_CONFIG.color}"><rect x="8" y="8" width="16" height="16" rx="2" stroke="${ICON_CONFIG.color}" stroke-width="${ICON_CONFIG.strokeWidth}"/></svg></span>`;
    }

    const playIconHTML = createPlayIcon();
    const stopIconHTML = createStopIcon();

    function updateStackForRepeat(showBtn) {
        const $stack = $('#ll-tools-category-stack');
        if (!$stack.length) return;
        if (showBtn) {
            $stack.removeClass('ll-no-repeat-btn');
        } else {
            $stack.addClass('ll-no-repeat-btn');
        }
    }

    const Dom = {
        setRepeatButton(state) {
            const $btn = $('#ll-tools-repeat-flashcard');
            if (!$btn.length) return;
            // In listening mode, hide the header repeat button entirely
            const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
            if (hideBtn) {
                $btn.hide();
                updateStackForRepeat(false);
                return;
            }
            updateStackForRepeat(true);
            if (state === 'stop') {
                $btn.html(stopIconHTML);
                $btn.removeClass('play-mode').addClass('stop-mode');
            } else {
                $btn.html(playIconHTML);
                $btn.removeClass('stop-mode').addClass('play-mode');
            }
            $btn.show();
        },
        disableRepeatButton() {
            const $btn = $('#ll-tools-repeat-flashcard');
            if (!$btn.length) return;
            const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
            if (hideBtn) { $btn.hide(); updateStackForRepeat(false); return; }
            $btn.addClass('disabled').prop('disabled', true);
        },
        enableRepeatButton() {
            const $btn = $('#ll-tools-repeat-flashcard');
            if (!$btn.length) return;
            const hideBtn = State && (State.isListeningMode || State.currentPromptType === 'image');
            if (hideBtn) { $btn.hide(); updateStackForRepeat(false); return; }
            updateStackForRepeat(true);
            $btn.removeClass('disabled').prop('disabled', false);
        },
        restoreHeaderUI() {
            $('#ll-tools-flashcard-header').show();
            $('#ll-tools-category-stack, #ll-tools-category-display').show();
            $('#ll-tools-learning-progress').hide();
            Dom.setRepeatButton('play');
        },
        updateCategoryNameDisplay(name) {
            if (!name) return;
            const $el = $('#ll-tools-category-display');
            if (!$el.length) return;

            // Look up translation if available
            let displayName = name;
            if (root.llToolsFlashcardsData && root.llToolsFlashcardsData.categories) {
                const category = root.llToolsFlashcardsData.categories.find(c => c.name === name);
                if (category && category.translation) {
                    displayName = category.translation;
                }
            }

            $el.text(String(displayName));
        },
        showLoading() {
            const viz = namespace.AudioVisualizer;
            // If we're in listening mode and a dedicated visualizer exists in the content area,
            // show that instead of the header spinner.
            if (State && State.isListeningMode) {
                const $listeningViz = $('#ll-tools-listening-visualizer');
                if ($listeningViz.length) {
                    if (viz && typeof viz.prepareForListening === 'function') viz.prepareForListening();
                    $listeningViz.css('display', 'flex');
                    $('#ll-tools-loading-animation').hide();
                    return;
                }
            }
            const $el = $('#ll-tools-loading-animation');
            if (viz && typeof viz.reset === 'function') viz.reset();
            $el.css('display', 'block');
        },
        hideLoading() {
            const viz = namespace.AudioVisualizer;
            if (viz && typeof viz.stop === 'function') {
                viz.stop();
            }
            $('#ll-tools-loading-animation').hide();
        },
        updateLearningProgress(introducedCount, totalCount, wordCorrectCounts, wordIntroductionProgress) {
            const $progress = $('#ll-tools-learning-progress');
            if (!$progress.length) return;

            // Calculate granular completion based on total correct answers (capped at 3 per word)
            const totalCorrectAnswers = Object.values(wordCorrectCounts).reduce((sum, count) => sum + Math.min(count, 3), 0);
            const maxPossibleAnswers = totalCount * 3;
            const completedPercentage = maxPossibleAnswers > 0 ? Math.round((totalCorrectAnswers / maxPossibleAnswers) * 100) : 0;

            // Calculate granular introduction progress (3 repetitions per word)
            const totalIntroductionRepetitions = Object.values(wordIntroductionProgress || {}).reduce((sum, count) => sum + Math.min(count, 3), 0);
            const maxPossibleIntroductions = totalCount * 3;
            const introducedPercentage = maxPossibleIntroductions > 0 ? Math.round((totalIntroductionRepetitions / maxPossibleIntroductions) * 100) : 0;

            $progress.html(`
                <div class="learning-progress-bar">
                    <div class="learning-progress-fill introduced-fill" style="width: ${introducedPercentage}%"></div>
                    <div class="learning-progress-fill completed-fill" style="width: ${completedPercentage}%"></div>
                </div>
            `).show();
        },
        showAutoplayBlockedOverlay() {
            // Check if overlay already exists
            let $overlay = $('#ll-tools-autoplay-overlay');
            if ($overlay.length) {
                $overlay.show();
                return;
            }

            // Create overlay
            $overlay = $('<div>', {
                id: 'll-tools-autoplay-overlay',
                class: 'll-tools-autoplay-overlay'
            });

            const $button = $('<button>', {
                class: 'll-tools-autoplay-button',
                'aria-label': 'Click to start quiz',
                html: `
                    <svg width="80" height="80" viewBox="0 0 80 80" fill="white">
                        <circle cx="40" cy="40" r="35" stroke="white" stroke-width="3" fill="rgba(255,255,255,0.1)"/>
                        <path d="M30 20 L30 60 L60 40 Z" fill="white"/>
                    </svg>
                    <span>Click to Start</span>
                `
            });

            const haltPropagation = function (event) {
                event.stopPropagation();
            };

            $overlay.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);
            $button.on('pointerdown.llAutoOverlay keydown.llAutoOverlay', haltPropagation);

            $button.on('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                // Clear the autoplay block flag
                if (root.FlashcardAudio && root.FlashcardAudio.clearAutoplayBlock) {
                    root.FlashcardAudio.clearAutoplayBlock();
                }

                // Hide overlay
                $overlay.fadeOut(300, function () {
                    $(this).remove();
                });
                $overlay.off('.llAutoOverlay');

                // Try to play the audio again only if the target audio hasn't succeeded yet
                const audioApi = root.FlashcardAudio;
                const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
                    ? audioApi.getCurrentTargetAudio()
                    : null;
                const alreadyPlayed = audioApi && typeof audioApi.getTargetAudioHasPlayed === 'function'
                    ? audioApi.getTargetAudioHasPlayed()
                    : false;
                if (audio && !alreadyPlayed) {
                    const playPromise = audio.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(e => console.error('Still cannot play:', e));
                    }
                }

                // Enable interactions
                $('#ll-tools-flashcard').css('pointer-events', 'auto');
                $('#ll-tools-flashcard-content').off('.llAutoplayKick');
            });

            $overlay.append($button);
            $('#ll-tools-flashcard-content').prepend($overlay);
            $overlay.fadeIn(300);
        },
        hideAutoplayBlockedOverlay() {
            const $overlay = $('#ll-tools-autoplay-overlay');
            $overlay.fadeOut(300, function () {
                $(this).remove();
            });
            $overlay.off('.llAutoOverlay');
            $('#ll-tools-flashcard-content').off('.llAutoplayKick');
            $('#ll-tools-flashcard').css('pointer-events', 'auto');
        },
        // Export icon generators for use in templates
        getPlayIconHTML() { return createPlayIcon(); },
        getStopIconHTML() { return createStopIcon(); }
    };

    // legacy alias some code expects:
    root.hideLoadingAnimation = Dom.hideLoading;

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Dom = Dom;
})(window, jQuery);
