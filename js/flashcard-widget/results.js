(function (root, $) {
    'use strict';
    const { State, Dom, Effects } = root.LLFlashcards;
    const DEFAULT_RESULTS_CATEGORY_PREVIEW_LIMIT = 3;

    const CHECKMARK_SVG = `
        <svg class="ll-learning-checkmark" width="80" height="80" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
            <circle class="ll-checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
            <path class="ll-checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
        </svg>
    `;

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function insertCompletionCheckmark() {
        $('.ll-learning-checkmark').remove();
        $('#quiz-results-title').before(CHECKMARK_SVG);
    }

    function removeCompletionCheckmark() {
        $('.ll-learning-checkmark').remove();
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case '\'': return '&#39;';
                default: return char;
            }
        });
    }

    function getCategoryNamesForDisplay(preferredNames) {
        const preferred = Array.isArray(preferredNames) ? preferredNames.filter(function (name) {
            return String(name || '').trim() !== '';
        }) : [];
        const lists = [
            preferred,
            Array.isArray(State.initialCategoryNames) ? State.initialCategoryNames : [],
            Array.isArray(State.categoryNames) ? State.categoryNames : [],
            Array.isArray(root.categoryNames) ? root.categoryNames : []
        ];
        const source = lists.find(list => Array.isArray(list) && list.length) || [];
        if (!source.length) return [];

        const translationLookup = {};
        try {
            const cats = (root.llToolsFlashcardsData && Array.isArray(root.llToolsFlashcardsData.categories))
                ? root.llToolsFlashcardsData.categories
                : [];
            cats.forEach(cat => {
                if (cat && cat.name) {
                    translationLookup[cat.name] = cat.translation || cat.name;
                }
            });
        } catch (_) { /* no-op */ }

        const seen = new Set();
        const display = [];
        source.forEach(name => {
            const key = (name === null || name === undefined) ? '' : String(name).trim();
            if (!key || seen.has(key)) return;
            seen.add(key);
            display.push(translationLookup[key] || key);
        });
        return display;
    }

    function isLearningSupportedForResults() {
        try {
            const Selection = root.LLFlashcards && root.LLFlashcards.Selection;
            const categories = State && State.categoryNames;
            if (Selection && typeof Selection.isLearningSupportedForCategories === 'function') {
                return Selection.isLearningSupportedForCategories(categories);
            }
            if (Selection && typeof Selection.getCategoryConfig === 'function' &&
                Array.isArray(categories) && categories.length) {
                return categories.every(function (name) {
                    const cfg = Selection.getCategoryConfig(name);
                    return cfg.learning_supported !== false;
                });
            }
        } catch (_) { /* no-op */ }
        return true;
    }

    function isGenderSupportedForResults() {
        try {
            const Selection = root.LLFlashcards && root.LLFlashcards.Selection;
            const categories = State && State.categoryNames;
            if (Selection && typeof Selection.isGenderSupportedForCategories === 'function') {
                return Selection.isGenderSupportedForCategories(categories);
            }
        } catch (_) { /* no-op */ }
        return false;
    }

    function formatTemplate(message, values) {
        let out = String(message || '');
        (Array.isArray(values) ? values : []).forEach(function (value, idx) {
            const token = '%' + String(idx + 1) + '$d';
            out = out.split(token).join(String(value));
        });
        return out;
    }

    function getDefaultLearningButtonLabel() {
        const modeUi = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.modeUi) || {};
        const learningUi = modeUi.learning || {};
        return learningUi.resultsButtonText || 'Learning Mode';
    }

    function getDefaultGenderButtonLabel() {
        const modeUi = (root.llToolsFlashcardsData && root.llToolsFlashcardsData.modeUi) || {};
        const genderUi = modeUi.gender || {};
        return genderUi.resultsButtonText || 'Gender Mode';
    }

    function getLearningResultsLabelElement() {
        const $btn = $('#restart-learning-mode');
        if (!$btn.length) return $();

        let $label = $btn.find('.ll-learning-results-label');
        if ($label.length) return $label;

        // Backward compatibility for markup without a label span.
        const fallbackText = $btn.clone().children().remove().end().text().trim();
        $btn.contents().filter(function () { return this.nodeType === 3; }).remove();
        $label = $('<span>', { class: 'll-learning-results-label' })
            .text(fallbackText || getDefaultLearningButtonLabel())
            .appendTo($btn);

        return $label;
    }

    function resetLearningResultsButtonLabel() {
        const $label = getLearningResultsLabelElement();
        if (!$label.length) return;
        const savedDefault = ($label.attr('data-default-label') || '').trim();
        const fallback = savedDefault || $label.text().trim() || getDefaultLearningButtonLabel();
        $label.attr('data-default-label', fallback);
        $label.text(fallback);
    }

    function setLearningResultsButtonLabel(labelText) {
        const $label = getLearningResultsLabelElement();
        if (!$label.length) return;
        if (!$label.attr('data-default-label')) {
            const current = $label.text().trim() || getDefaultLearningButtonLabel();
            $label.attr('data-default-label', current);
        }
        const next = String(labelText || '').trim();
        $label.text(next || ($label.attr('data-default-label') || getDefaultLearningButtonLabel()));
    }

    function getGenderResultsLabelElement() {
        const $btn = $('#restart-gender-mode');
        if (!$btn.length) return $();

        let $label = $btn.find('.ll-gender-results-label');
        if ($label.length) return $label;

        const fallbackText = $btn.clone().children().remove().end().text().trim();
        $btn.contents().filter(function () { return this.nodeType === 3; }).remove();
        $label = $('<span>', { class: 'll-gender-results-label' })
            .text(fallbackText || getDefaultGenderButtonLabel())
            .appendTo($btn);
        return $label;
    }

    function resetGenderResultsButtonLabel() {
        const $label = getGenderResultsLabelElement();
        if (!$label.length) return;
        const savedDefault = ($label.attr('data-default-label') || '').trim();
        const fallback = savedDefault || $label.text().trim() || getDefaultGenderButtonLabel();
        $label.attr('data-default-label', fallback);
        $label.text(fallback);
    }

    function setGenderResultsButtonLabel(labelText) {
        const $label = getGenderResultsLabelElement();
        if (!$label.length) return;
        if (!$label.attr('data-default-label')) {
            $label.attr('data-default-label', $label.text().trim() || getDefaultGenderButtonLabel());
        }
        const next = String(labelText || '').trim();
        $label.text(next || ($label.attr('data-default-label') || getDefaultGenderButtonLabel()));
    }

    function getCurrentResultsMode() {
        if (State.isGenderMode) { return 'gender'; }
        if (State.isListeningMode) { return 'listening'; }
        if (State.isLearningMode) { return 'learning'; }
        if (State.isSelfCheckMode) { return 'self-check'; }
        return 'practice';
    }

    function emitResultsShown(extra) {
        try {
            const payload = Object.assign(
                { mode: getCurrentResultsMode() },
                (extra && typeof extra === 'object') ? extra : {}
            );
            $(document).trigger('lltools:flashcard-results-shown', [payload]);
        } catch (_) { /* no-op */ }
    }

    function hideResults() {
        $('#quiz-results').hide();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').hide();
        $('#restart-practice-mode, #restart-learning-mode, #restart-self-check-mode, #restart-gender-mode').show();
        $('#restart-listening-mode').hide();
        $('#ll-gender-results-actions').hide();
        $('#ll-gender-next-activity, #ll-gender-next-chunk').hide();
        $('#ll-study-results-actions').hide();
        $('#ll-study-results-suggestion').hide().empty();
        $('#ll-study-results-same-chunk, #ll-study-results-different-chunk, #ll-study-results-next-chunk').hide();
        removeCompletionCheckmark();
        $('#ll-tools-flashcard').show();
        $('#quiz-results-categories').hide().empty();
        resetLearningResultsButtonLabel();
        resetGenderResultsButtonLabel();
    }

    function showResults() {
        const msgs = root.llToolsFlashcardsMessages || {};
        const State = root.LLFlashcards.State;
        const learningAllowed = isLearningSupportedForResults();
        const genderAllowed = isGenderSupportedForResults();
        let categoriesForDisplay = getCategoryNamesForDisplay();
        const previewLimitRaw = Number(root.llToolsFlashcardsData && root.llToolsFlashcardsData.resultsCategoryPreviewLimit);
        const previewLimit = Number.isFinite(previewLimitRaw) && previewLimitRaw > 0
            ? Math.floor(previewLimitRaw)
            : DEFAULT_RESULTS_CATEGORY_PREVIEW_LIMIT;
        const renderCategories = function (shouldShow) {
            const $el = $('#quiz-results-categories');
            if (!$el.length) return;
            if (!shouldShow || !categoriesForDisplay.length) {
                $el.hide().empty();
                return;
            }

            const visible = categoriesForDisplay.slice(0, previewLimit);
            const hidden = categoriesForDisplay.slice(previewLimit);
            const pillsHtml = visible.map(function (name) {
                const safeName = escapeHtml(name);
                return '<span class="ll-results-category-pill" title="' + safeName + '">' +
                    '<span class="ll-results-category-pill-text">' + safeName + '</span>' +
                    '</span>';
            });

            if (hidden.length) {
                const hiddenNamesTitle = escapeHtml(hidden.join(', '));
                pillsHtml.push(
                    '<span class="ll-results-category-pill ll-results-category-pill--more" title="' + hiddenNamesTitle + '">+' + hidden.length + '</span>'
                );
            }

            $el.attr('aria-label', categoriesForDisplay.join(', ')).html(pillsHtml.join('')).show();
        };

        $('#ll-tools-prompt').hide().empty();
        $('#ll-tools-flashcard').empty().hide();
        $('#ll-quiz-star-row').hide();
        // Hide listening mode controls if present
        $('#ll-tools-listening-controls').hide();
        removeCompletionCheckmark();
        resetLearningResultsButtonLabel();
        resetGenderResultsButtonLabel();
        $('#ll-gender-results-actions').hide();
        $('#ll-gender-next-activity, #ll-gender-next-chunk').hide();
        $('#ll-study-results-actions').hide();
        $('#ll-study-results-suggestion').hide().empty();
        $('#ll-study-results-same-chunk, #ll-study-results-different-chunk, #ll-study-results-next-chunk').hide();

        if (root.FlashcardAudio) {
            try {
                const pausePromise = typeof root.FlashcardAudio.pauseAllAudio === 'function'
                    ? root.FlashcardAudio.pauseAllAudio()
                    : null;
                if (pausePromise && typeof pausePromise.catch === 'function') {
                    pausePromise.catch(err => console.warn('Audio pause failed during results display:', err));
                }
            } catch (err) {
                console.warn('Error pausing audio during results display:', err);
            }

            if (typeof root.FlashcardAudio.startNewSession === 'function') {
                root.FlashcardAudio.startNewSession().catch(err => {
                    console.warn('Audio cleanup session failed during results display:', err);
                });
            }
        }

        // Keep mode switch buttons available during results

        if (State.isLearningMode) {
            // Insert checkmark BEFORE the title
            $('#quiz-results-title').text(msgs.learningComplete || 'Learning Complete!');

            insertCompletionCheckmark();

            const learning = root.LLFlashcards && root.LLFlashcards.Modes && root.LLFlashcards.Modes.Learning;
            const progress = (learning && typeof learning.getSetProgress === 'function')
                ? learning.getSetProgress()
                : { current: 1, total: 1, hasNext: false };
            const hasNextSet = !!(progress && progress.hasNext);
            if (hasNextSet) {
                const nextSetNumber = (progress.current || 0) + 1;
                const totalSets = progress.total || nextSetNumber;
                const promptTemplate = msgs.learningContinuePrompt || 'Great work. Continue with set %1$d of %2$d?';
                const prompt = formatTemplate(promptTemplate, [nextSetNumber, totalSets]);
                $('#quiz-results-message').text(prompt).show();
                setLearningResultsButtonLabel(msgs.learningContinueButton || 'Continue Learning');
            } else {
                $('#quiz-results-message').hide();
                resetLearningResultsButtonLabel();
            }
            $('#correct-count').parent().hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            $('#quiz-mode-buttons').show();
            $('#restart-practice-mode, #restart-learning-mode, #restart-self-check-mode').show();
            if (genderAllowed) {
                $('#restart-gender-mode').show();
            } else {
                $('#restart-gender-mode').hide();
            }
            $('#restart-listening-mode').hide();
            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-learning-progress').hide();
            renderCategories(true);

            const totalWords = Object.keys(State.wordCorrectCounts).length;
            const completedWords = Object.values(State.wordCorrectCounts).filter(count => count >= State.MIN_CORRECT_COUNT).length;

            if (completedWords === totalWords) {
                Effects.startConfetti();
            }
            emitResultsShown({ total: totalWords, correct: completedWords });
            return;
        }

        // Listening mode: simple results (no confetti)
        if (State.isListeningMode) {
            const msgs2 = root.llToolsFlashcardsMessages || {};
            $('#quiz-results-title').text(msgs2.listeningComplete || 'Listening Complete');
            insertCompletionCheckmark();
            $('#quiz-results-message').hide();
            $('#correct-count').parent().hide();
            $('#quiz-results-categories').show();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            // Only show the replay listening button; hide the cross-mode buttons here
            $('#quiz-mode-buttons').show();
            $('#restart-practice-mode').hide();
            $('#restart-learning-mode').hide();
            $('#restart-self-check-mode').hide();
            $('#restart-gender-mode').hide();
            $('#restart-listening-mode').show();
            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-learning-progress').hide();
            renderCategories(true);
            emitResultsShown({ total: 0, correct: 0 });
            return;
        }

        if (State.isGenderMode) {
            const genderMode = root.LLFlashcards && root.LLFlashcards.Modes && root.LLFlashcards.Modes.Gender;
            const actions = (genderMode && typeof genderMode.getResultsActions === 'function')
                ? genderMode.getResultsActions()
                : null;
            if (genderMode && typeof genderMode.getResultsCategoryNames === 'function') {
                try {
                    const chunkCategories = genderMode.getResultsCategoryNames();
                    if (Array.isArray(chunkCategories) && chunkCategories.length) {
                        categoriesForDisplay = getCategoryNamesForDisplay(chunkCategories);
                    }
                } catch (_) { /* no-op */ }
            }
            const title = (actions && actions.title) ? actions.title : (msgs.genderResultsTitle || 'Gender Round Complete');
            const message = (actions && actions.message) ? actions.message : (msgs.genderResultsMessage || '');
            const primaryLabel = (actions && actions.primary && actions.primary.label)
                ? actions.primary.label
                : (msgs.genderNextActivity || 'Next Gender Activity');
            const secondaryLabel = (actions && actions.secondary && actions.secondary.label)
                ? actions.secondary.label
                : (msgs.genderNextChunk || 'Next Recommended Set');
            const hasSecondary = !!(actions && actions.secondary);

            $('#quiz-results-title').text(title);
            if (message) {
                $('#quiz-results-message').text(message).show();
            } else {
                $('#quiz-results-message').hide();
            }
            $('#correct-count').parent().hide();
            $('#quiz-results').show();
            $('#restart-quiz').hide();
            $('#quiz-mode-buttons').hide();
            $('#restart-practice-mode, #restart-learning-mode, #restart-self-check-mode, #restart-gender-mode, #restart-listening-mode').hide();
            setGenderResultsButtonLabel(primaryLabel);

            const $genderActions = $('#ll-gender-results-actions');
            if ($genderActions.length) {
                $('#ll-gender-next-activity').text(primaryLabel).show();
                if (hasSecondary) {
                    $('#ll-gender-next-chunk').text(secondaryLabel).show();
                } else {
                    $('#ll-gender-next-chunk').hide();
                }
                $genderActions.show();
            } else {
                // Fallback for legacy templates that do not render gender action buttons.
                $('#quiz-mode-buttons').show();
                $('#restart-practice-mode, #restart-learning-mode, #restart-self-check-mode, #restart-listening-mode').hide();
                $('#restart-gender-mode').show();
            }

            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();
            $('#ll-tools-learning-progress').hide();
            renderCategories(true);
            emitResultsShown({ total: 0, correct: 0 });
            return;
        }

        // Practice mode: hide learning progress bar
        $('#ll-tools-learning-progress').hide();

        const total = State.quizResults.correctOnFirstTry + State.quizResults.incorrect.length;

        if (total === 0) {
            // This usually means no questions were shown (e.g., "starred only" but nothing is starred in this selection).
            const prefs = root.llToolsStudyPrefs || {};
            const modeRaw = (prefs.starMode || prefs.star_mode || (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || 'normal');
            const starMode = normalizeStarMode(modeRaw);
            const starredIds = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];

            $('#quiz-results-title').text(msgs.somethingWentWrong || 'Something went wrong');
            const fallbackMsg = (starMode === 'only')
                ? (
                    starredIds.length
                        ? "No questions were shown. You're using “Starred only”, but none of your starred words are in this quiz selection."
                        : "No questions were shown. You're using “Starred only”, but you don't have any starred words yet."
                )
                : 'No questions were shown for this quiz selection.';
            $('#quiz-results-message').text(fallbackMsg).show();
            $('#correct-count').parent().hide();
            $('#quiz-results').show();

            Dom.hideLoading();
            $('#ll-tools-repeat-flashcard').hide();
            $('#ll-tools-category-stack, #ll-tools-category-display').hide();

            // Keep the mode buttons available so the user can retry without being stuck.
            $('#restart-quiz').show();
            $('#quiz-mode-buttons').show();
            $('#restart-practice-mode').show();
            if (learningAllowed) {
                $('#restart-learning-mode').show();
            } else {
                $('#restart-learning-mode').hide();
            }
            $('#restart-self-check-mode').show();
            if (genderAllowed) {
                $('#restart-gender-mode').show();
            } else {
                $('#restart-gender-mode').hide();
            }
            $('#restart-listening-mode').hide();
            renderCategories(true);

            // Avoid playing feedback if the widget is closing/idle
            try {
                var isClosing = false;
                try { isClosing = !!(State && typeof State.is === 'function' && State.is(State.STATES.CLOSING)); } catch (_) { isClosing = false; }
                if (!isClosing && State && State.widgetActive && root.FlashcardAudio && typeof root.FlashcardAudio.playFeedback === 'function') {
                    root.FlashcardAudio.playFeedback(false, null, null);
                }
            } catch (_) { }
            emitResultsShown({ total: 0, correct: 0 });
            return;
        }

        $('#quiz-results').show();
        Dom.hideLoading();
        $('#ll-tools-repeat-flashcard').hide();
        $('#ll-tools-category-stack, #ll-tools-category-display').hide();

        $('#correct-count').text(State.quizResults.correctOnFirstTry);
        $('#total-questions').text(total);
        $('#correct-count').parent().show();
        $('#restart-quiz').hide();
        $('#quiz-mode-buttons').show();
        $('#restart-practice-mode').show();
        if (learningAllowed) {
            $('#restart-learning-mode').show();
        } else {
            $('#restart-learning-mode').hide();
        }
        $('#restart-self-check-mode').show();
        if (genderAllowed) {
            $('#restart-gender-mode').show();
        } else {
            $('#restart-gender-mode').hide();
        }
        $('#restart-listening-mode').hide();
        removeCompletionCheckmark();
        renderCategories(true);

        const ratio = total > 0 ? (State.quizResults.correctOnFirstTry / total) : 0;
        const $title = $('#quiz-results-title'), $msg = $('#quiz-results-message');

        if (ratio === 1) { $title.text(msgs.perfect || 'Perfect!'); $msg.hide(); }
        else if (ratio >= 0.7) { $title.text(msgs.goodJob || 'Good job!'); $msg.hide(); }
        else {
            $title.text(msgs.keepPracticingTitle || 'Keep practicing!');
            $msg.text(msgs.keepPracticingMessage || "You're on the right track...").css({ fontSize: '14px', marginTop: '10px', color: '#555' }).show();
        }

        if (ratio >= 0.7) Effects.startConfetti();
        emitResultsShown({ total: total, correct: State.quizResults.correctOnFirstTry });
    }

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Results = { showResults, hideResults };
})(window, jQuery);
