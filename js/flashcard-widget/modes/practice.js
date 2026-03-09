(function (root) {
    'use strict';

    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Modes = root.LLFlashcards.Modes || {};

    const State = (root.LLFlashcards.State = root.LLFlashcards.State || {});
    const Selection = (root.LLFlashcards.Selection = root.LLFlashcards.Selection || {});
    const FlashcardOptions = root.FlashcardOptions || {};
    const Results = (root.LLFlashcards.Results = root.LLFlashcards.Results || {});
    const Util = (root.LLFlashcards.Util = root.LLFlashcards.Util || {});
    const STATES = State.STATES || {};
    const PRACTICE_PROMPT_ORDER = ['question', 'isolation', 'introduction', 'sentence', 'in-sentence'];

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function getStarredLookup() {
        const prefs = root.llToolsStudyPrefs || {};
        const ids = Array.isArray(prefs.starredWordIds) ? prefs.starredWordIds : [];
        const map = {};
        ids.forEach(function (id) {
            const n = parseInt(id, 10);
            if (n > 0) { map[n] = true; }
        });
        return map;
    }

    function getStarMode() {
        if (State && State.starModeOverride) {
            return normalizeStarMode(State.starModeOverride);
        }
        const prefs = root.llToolsStudyPrefs || {};
        const modeFromPrefs = prefs.starMode || prefs.star_mode;
        const modeFromFlash = (root.llToolsFlashcardsData && (root.llToolsFlashcardsData.starMode || root.llToolsFlashcardsData.star_mode)) || null;
        const mode = modeFromPrefs || modeFromFlash || 'normal';
        return normalizeStarMode(mode);
    }

    function isStarred(wordId) {
        if (!wordId) { return false; }
        const lookup = getStarredLookup();
        return !!lookup[wordId];
    }

    function incrementForcedReplayCount(wordId) {
        if (!wordId) return;
        const map = (State.practiceForcedReplays = State.practiceForcedReplays || {});
        const key = String(wordId);
        map[key] = (map[key] || 0) + 1;
    }

    function findWordAndCategoryById(wordId) {
        if (!wordId || !State || !State.wordsByCategory) return null;
        const idStr = String(wordId);
        for (const catName in State.wordsByCategory) {
            if (!Object.prototype.hasOwnProperty.call(State.wordsByCategory, catName)) continue;
            const list = State.wordsByCategory[catName];
            if (!Array.isArray(list)) continue;
            const word = list.find(function (w) { return String(w.id) === idStr; });
            if (word) {
                return { word, categoryName: catName };
            }
        }
        return null;
    }

    function normalizeRecordingType(type) {
        return String(type || '')
            .trim()
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
    }

    function isUserLoggedIn() {
        const data = root.llToolsFlashcardsData || {};
        return !!data.isUserLoggedIn;
    }

    function sortRecordingTypes(types) {
        const seen = {};
        const extras = [];
        PRACTICE_PROMPT_ORDER.forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (key) {
                seen[key] = false;
            }
        });

        (Array.isArray(types) ? types : []).forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (!key || Object.prototype.hasOwnProperty.call(seen, key) && seen[key] === true) {
                return;
            }
            if (Object.prototype.hasOwnProperty.call(seen, key)) {
                seen[key] = true;
                return;
            }
            if (extras.indexOf(key) === -1) {
                extras.push(key);
            }
        });

        const ordered = [];
        PRACTICE_PROMPT_ORDER.forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (key && seen[key] === true) {
                ordered.push(key);
            }
        });
        extras.sort();
        return ordered.concat(extras);
    }

    function getAvailableRecordingTypes(word) {
        if (!word || typeof word !== 'object') {
            return [];
        }

        const explicit = Array.isArray(word.practice_recording_types)
            ? word.practice_recording_types
            : [];
        if (explicit.length) {
            return sortRecordingTypes(explicit);
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        const collected = files.map(function (entry) {
            return entry && entry.recording_type;
        });
        return sortRecordingTypes(collected);
    }

    function getCorrectRecordingTypes(word) {
        if (!word || typeof word !== 'object' || !Array.isArray(word.practice_correct_recording_types)) {
            return [];
        }
        return sortRecordingTypes(word.practice_correct_recording_types);
    }

    function setCorrectRecordingTypes(word, types) {
        if (!word || typeof word !== 'object') {
            return;
        }
        word.practice_correct_recording_types = sortRecordingTypes(types);
    }

    function getPracticeExposureCount(word) {
        if (!word || typeof word !== 'object') {
            return 0;
        }

        const raw = parseInt(word.practice_exposure_count, 10);
        return Number.isFinite(raw) && raw > 0 ? raw : 0;
    }

    function normalizeRecordingTypesInOrder(types) {
        const seen = {};
        const ordered = [];

        (Array.isArray(types) ? types : []).forEach(function (type) {
            const key = normalizeRecordingType(type);
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;
            ordered.push(key);
        });

        return ordered;
    }

    function getRecordingTextForType(word, recordingType) {
        const key = normalizeRecordingType(recordingType);
        if (!word || typeof word !== 'object' || !key) {
            return '';
        }

        const textMap = (word.recording_texts_by_type && typeof word.recording_texts_by_type === 'object')
            ? word.recording_texts_by_type
            : null;
        if (textMap) {
            const entries = Object.keys(textMap);
            for (let i = 0; i < entries.length; i += 1) {
                const entryKey = normalizeRecordingType(entries[i]);
                if (entryKey === key) {
                    return String(textMap[entries[i]] || '').trim();
                }
            }
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        for (let i = 0; i < files.length; i += 1) {
            const entry = files[i] || {};
            if (normalizeRecordingType(entry.recording_type) !== key) {
                continue;
            }
            return String(entry.recording_text || '').trim();
        }

        return '';
    }

    function selectAudioEntryForTypes(word, preferredTypes) {
        if (!word || typeof word !== 'object') {
            return null;
        }

        const files = Array.isArray(word.audio_files) ? word.audio_files : [];
        const orderedTypes = normalizeRecordingTypesInOrder(preferredTypes);
        const preferredSpeaker = parseInt(word.preferred_speaker_user_id, 10) || 0;
        const hasUrl = function (entry) {
            return !!(entry && typeof entry.url === 'string' && entry.url.trim() !== '');
        };

        for (let i = 0; i < orderedTypes.length; i += 1) {
            const key = orderedTypes[i];

            if (preferredSpeaker > 0) {
                const sameSpeaker = files.find(function (entry) {
                    return hasUrl(entry)
                        && normalizeRecordingType(entry.recording_type) === key
                        && (parseInt(entry.speaker_user_id, 10) || 0) === preferredSpeaker;
                });
                if (sameSpeaker) {
                    return {
                        type: key,
                        url: String(sameSpeaker.url).trim(),
                        recordingText: String(sameSpeaker.recording_text || '').trim()
                    };
                }
            }

            const anySpeaker = files.find(function (entry) {
                return hasUrl(entry) && normalizeRecordingType(entry.recording_type) === key;
            });
            if (anySpeaker) {
                return {
                    type: key,
                    url: String(anySpeaker.url).trim(),
                    recordingText: String(anySpeaker.recording_text || '').trim()
                };
            }
        }

        const fallback = files.find(hasUrl);
        if (fallback) {
            return {
                type: normalizeRecordingType(fallback.recording_type) || (orderedTypes[0] || ''),
                url: String(fallback.url).trim(),
                recordingText: String(fallback.recording_text || '').trim()
            };
        }

        const directAudio = typeof word.audio === 'string' ? word.audio.trim() : '';
        if (!directAudio) {
            return null;
        }

        const fallbackType = orderedTypes[0] || '';
        return {
            type: fallbackType,
            url: directAudio,
            recordingText: getRecordingTextForType(word, fallbackType)
        };
    }

    function resolvePracticePromptAudio(word) {
        const availableTypes = getAvailableRecordingTypes(word);
        const correctTypes = isUserLoggedIn() ? getCorrectRecordingTypes(word) : [];
        let selectedType = '';

        if (isUserLoggedIn() && availableTypes.length) {
            const progressIndex = Math.max(getPracticeExposureCount(word), correctTypes.length);
            selectedType = availableTypes[progressIndex % availableTypes.length] || '';
        }

        if (!selectedType && isUserLoggedIn()) {
            selectedType = availableTypes.find(function (type) {
                return correctTypes.indexOf(type) === -1;
            }) || '';
        }

        if (!selectedType) {
            selectedType = PRACTICE_PROMPT_ORDER.find(function (type) {
                return availableTypes.indexOf(type) !== -1;
            }) || availableTypes[0] || '';
        }

        const preferredOrder = [];
        if (selectedType) {
            preferredOrder.push(selectedType);
        }
        availableTypes.forEach(function (type) {
            if (preferredOrder.indexOf(type) === -1) {
                preferredOrder.push(type);
            }
        });

        const entry = selectAudioEntryForTypes(word, preferredOrder);
        if (entry && entry.type) {
            selectedType = entry.type;
        }

        return {
            selectedType: selectedType,
            entry: entry
        };
    }

    function markRecordingTypeCorrect(word) {
        const type = normalizeRecordingType(word && word.__practiceRecordingType);
        if (!word || !type) {
            return;
        }

        const correctTypes = getCorrectRecordingTypes(word);
        if (correctTypes.indexOf(type) === -1) {
            correctTypes.push(type);
            setCorrectRecordingTypes(word, correctTypes);
        }
    }

    function initialize() {
        State.isLearningMode = false;
        State.isListeningMode = false;
        State.completedCategories = {};
        State.categoryRepetitionQueues = {};
        State.practiceForcedReplays = {};
        return true;
    }

    function queueForRepetition(targetWord, options) {
        const categoryName = State.currentCategoryName;
        if (!categoryName || !targetWord) return;
        const queue = (State.categoryRepetitionQueues[categoryName] = State.categoryRepetitionQueues[categoryName] || []);
        const force = !!(options && options.force);

        const starMode = getStarMode();
        // If it's already queued, upgrade the entry to a forced replay (used for wrong answers)
        const existingIndex = queue.findIndex(item => item.wordData.id === targetWord.id);
        if (existingIndex !== -1) {
            if (force) {
                queue[existingIndex].forceReplay = true;
            }
            return;
        }

        const starredLookup = getStarredLookup();
        const applyStarBias = starMode !== 'normal';
        const isStarredWord = applyStarBias && !!starredLookup[targetWord.id];

        // Avoid endlessly re-queuing starred words once they've hit their allowed plays
        State.starPlayCounts = State.starPlayCounts || {};
        const plays = State.starPlayCounts[targetWord.id] || 0;
        const maxUses = (applyStarBias && isStarredWord) ? 2 : 1;
        if (!force && applyStarBias && isStarredWord && plays >= maxUses) {
            return;
        }

        const base = State.categoryRoundCount[categoryName] || 0;
        const offset = (applyStarBias && isStarred(targetWord.id))
            ? ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 3) : (Math.floor(Math.random() * 2) + 2)) // delay slightly to avoid immediate repeat
            : ((Util && typeof Util.randomInt === 'function') ? Util.randomInt(2, 4) : (Math.floor(Math.random() * 3) + 2));
        queue.push({
            wordData: targetWord,
            reappearRound: base + offset,
            forceReplay: force
        });
    }

    function onCorrectAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        markRecordingTypeCorrect(ctx.targetWord);
        if (State.wrongIndexes.length === 0) return;
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onWrongAnswer(ctx) {
        if (!ctx || !ctx.targetWord) return;
        incrementForcedReplayCount(ctx.targetWord.id);
        queueForRepetition(ctx.targetWord, { force: true });
    }

    function onFirstRoundStart() {
        return true;
    }

    function selectTargetWord() {
        FlashcardOptions.calculateNumberOfOptions(State.wrongIndexes, State.isFirstRound, State.currentCategoryName);
        const picked = Selection.selectTargetWordAndCategory();
        const starMode = getStarMode();
        if (picked && starMode === 'weighted' && isStarred(picked.id)) {
            queueForRepetition(picked);
        }
        return picked;
    }

    function handleNoTarget(ctx) {
        if (State.isFirstRound) {
            const hasWords = (State.categoryNames || []).some(name => {
                const words = State.wordsByCategory && State.wordsByCategory[name];
                return Array.isArray(words) && words.length > 0;
            });
            if (!hasWords) {
                if (ctx && typeof ctx.showLoadingError === 'function') {
                    ctx.showLoadingError();
                }
                return true;
            }
        }

        const queues = State.categoryRepetitionQueues || {};
        const queuedCategories = [];
        const queuedIds = new Set();
        Object.keys(queues).forEach(function (cat) {
            const list = queues[cat];
            if (Array.isArray(list) && list.length) {
                queuedCategories.push(cat);
                list.forEach(function (item) {
                    const id = item && item.wordData && item.wordData.id;
                    if (id) queuedIds.add(String(id));
                });
            }
        });

        const outstanding = (State.practiceForcedReplays = State.practiceForcedReplays || {});
        Object.keys(outstanding).forEach(function (id) {
            if ((outstanding[id] || 0) <= 0) return;
            const key = String(id);
            if (queuedIds.has(key)) return;
            const found = findWordAndCategoryById(id);
            if (!found) return;
            const prevCategory = State.currentCategoryName;
            State.currentCategoryName = found.categoryName;
            queueForRepetition(found.word, { force: true });
            State.currentCategoryName = prevCategory;
            queuedIds.add(key);
            if (!queuedCategories.includes(found.categoryName)) {
                queuedCategories.push(found.categoryName);
            }
        });

        if (queuedCategories.length) {
            const lastShownKey = String(State.lastWordShownId || '');
            const queuedOnlyLastShown = !!lastShownKey && queuedIds.size === 1 && queuedIds.has(lastShownKey);
            const hasBridgeWord = !!(
                queuedOnlyLastShown &&
                Selection &&
                typeof Selection.hasPracticeBridgeWordAvailable === 'function' &&
                Selection.hasPracticeBridgeWordAvailable(State.lastWordShownId, State.currentCategoryName)
            );

            if (queuedOnlyLastShown && !hasBridgeWord) {
                State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Practice replay deadlock avoided');
                Results.showResults && Results.showResults();
                return true;
            }

            State.completedCategories = State.completedCategories || {};
            queuedCategories.forEach(function (cat) {
                State.completedCategories[cat] = false;
                if (!State.categoryNames.includes(cat)) {
                    State.categoryNames.push(cat);
                }
            });
            State.isFirstRound = false;
            if (ctx && typeof ctx.startQuizRound === 'function') {
                setTimeout(function () { ctx.startQuizRound(); }, 0);
            }
            return true;
        }

        State.transitionTo && State.transitionTo(STATES.SHOWING_RESULTS, 'Quiz complete');
        Results.showResults && Results.showResults();
        return true;
    }

    function beforeOptionsFill() {
        return true;
    }

    function configureTargetAudio(target) {
        if (!target) return true;
        const promptType = State.currentPromptType || 'audio';
        if (promptType !== 'audio') {
            delete target.__practiceRecordingType;
            delete target.__practiceRecordingText;
            return true;
        }

        const resolved = resolvePracticePromptAudio(target);
        if (resolved.entry && resolved.entry.url) {
            target.audio = resolved.entry.url;
        }

        if (resolved.selectedType) {
            target.__practiceRecordingType = resolved.selectedType;
            target.__practiceRecordingText = resolved.entry && resolved.entry.recordingText
                ? resolved.entry.recordingText
                : getRecordingTextForType(target, resolved.selectedType);
        } else {
            delete target.__practiceRecordingType;
            delete target.__practiceRecordingText;
        }

        return true;
    }

    root.LLFlashcards.Modes.Practice = {
        initialize,
        getChoiceCount: function () { return null; },
        recordAnswerResult: function () { },
        onCorrectAnswer,
        onWrongAnswer,
        onFirstRoundStart,
        selectTargetWord,
        handleNoTarget,
        beforeOptionsFill,
        configureTargetAudio
    };
})(window);
