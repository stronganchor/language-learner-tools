(function (root) {
    'use strict';
    const { Util, State } = root.LLFlashcards;

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function getCategoryConfig(name) {
        const base = {
            prompt_type: 'audio',
            option_type: State.DEFAULT_DISPLAY_MODE,
            learning_supported: true,
        };
        if (!name) return base;
        const cats = (root.llToolsFlashcardsData && Array.isArray(root.llToolsFlashcardsData.categories))
            ? root.llToolsFlashcardsData.categories
            : [];
        const found = cats.find(c => c && c.name === name);
        return Object.assign({}, base, found || {});
    }

    function getCategoryDisplayMode(name) {
        if (!name) return State.DEFAULT_DISPLAY_MODE;
        const cfg = getCategoryConfig(name);
        const opt = cfg.option_type || cfg.mode || State.DEFAULT_DISPLAY_MODE;
        if (opt === 'text_title' || opt === 'text_translation') return 'text';
        return opt;
    }
    function getCurrentDisplayMode() { return getCategoryDisplayMode(State.currentCategoryName); }
    function getCategoryPromptType(name) {
        const cfg = getCategoryConfig(name);
        return cfg.prompt_type || 'audio';
    }

    function weightedChoice(items, weightFn) {
        if (!Array.isArray(items) || !items.length) return null;
        let total = 0;
        const weights = items.map(function (item) {
            const w = Math.max(0, weightFn(item) || 0);
            total += w;
            return w;
        });
        if (total <= 0) return items[Math.floor(Math.random() * items.length)];
        let r = Math.random() * total;
        for (let i = 0; i < items.length; i++) {
            r -= weights[i];
            if (r <= 0) return items[i];
        }
        return items[items.length - 1];
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

    function getStarPlayCounts() {
        State.starPlayCounts = State.starPlayCounts || {};
        return State.starPlayCounts;
    }

    function canPlayWord(wordId, starredLookup, starMode) {
        if (!wordId) return false;
        const counts = getStarPlayCounts();
        const maxUses = (starMode === 'weighted' && starredLookup[wordId]) ? 2 : 1;
        const plays = counts[wordId] || 0;
        return plays < maxUses;
    }

    function recordPlay(wordId, starredLookup, starMode) {
        if (!wordId) return;
        const counts = getStarPlayCounts();
        const maxUses = (starMode === 'weighted' && starredLookup[wordId]) ? 2 : 1;
        counts[wordId] = (counts[wordId] || 0) + 1;
        if (counts[wordId] >= maxUses && !State.usedWordIDs.includes(wordId)) {
            State.usedWordIDs.push(wordId);
        }
    }

    function pruneCompletedCategories() {
        if (!Array.isArray(State.categoryNames)) return;
        State.completedCategories = State.completedCategories || {};
        const completed = State.completedCategories || {};
        State.categoryNames = State.categoryNames.filter(function (name) {
            return !completed[name];
        });
    }

    function getAvailableUnusedWords(name, starredLookup, starMode) {
        const list = State.wordsByCategory[name] || [];
        if (!Array.isArray(list) || !list.length) return [];
        const counts = getStarPlayCounts();
        const filtered = list.filter(function (w) {
            const usedCount = counts[w.id] || 0;
            const alreadyUsed = State.usedWordIDs.includes(w.id);
            const maxUses = (starMode === 'weighted' && starredLookup[w.id]) ? 2 : 1;
            const plays = Math.max(usedCount, alreadyUsed ? 1 : 0);
            if (plays >= maxUses) return false;
            if (starMode === 'only' && !starredLookup[w.id]) return false;
            return true;
        });
        if (filtered.length) return filtered;

        return [];
    }

    function getTargetCategoryName(word) {
        if (!word) return State.currentCategoryName;
        if (word.__categoryName) return word.__categoryName;
        if (Array.isArray(word.all_categories) && Array.isArray(State.categoryNames)) {
            const match = State.categoryNames.find(function (name) {
                return word.all_categories.includes(name);
            });
            if (match) return match;
        }
        return State.currentCategoryName;
    }
    function categoryRequiresAudio(nameOrConfig) {
        const cfg = typeof nameOrConfig === 'object' ? (nameOrConfig || {}) : getCategoryConfig(nameOrConfig);
        const opt = cfg.option_type || cfg.mode;
        return (cfg.prompt_type === 'audio') || opt === 'audio' || opt === 'text_audio';
    }

    function normalizeTextForComparison(text) {
        const base = (text === null || text === undefined) ? '' : String(text).trim();
        if (base === '') return '';
        const prepared = base.replace(/[I\u0130]/g, function (ch) { return ch === 'I' ? '\u0131' : 'i'; });
        let lowered = prepared;
        try { lowered = prepared.toLocaleLowerCase('tr'); } catch (_) { lowered = prepared.toLowerCase(); }
        return lowered.replace(/\u0307/g, '');
    }

    function getNormalizedOptionText(word) {
        if (!word || typeof word !== 'object') return '';
        const val = (typeof word.label === 'string' && word.label !== '') ? word.label : word.title;
        return normalizeTextForComparison(val);
    }

    function isLearningSupportedForCategories(categoryNames) {
        try {
            const names = Array.isArray(categoryNames) && categoryNames.length ? categoryNames : State.categoryNames;
            if (!Array.isArray(names) || !names.length) return true;
            return names.every(function (name) {
                const cfg = getCategoryConfig(name);
                return cfg.learning_supported !== false;
            });
        } catch (_) {
            return true;
        }
    }

    function renderPrompt(targetWord, cfg) {
        const promptConfig = cfg || getCategoryConfig(State.currentCategoryName);
        const promptType = promptConfig.prompt_type || 'audio';
        const $ = root.jQuery;
        if (!$) return;
        let $prompt = $('#ll-tools-prompt');
        if (!$prompt.length) {
            $prompt = $('<div>', { id: 'll-tools-prompt', class: 'll-tools-prompt', style: 'display:none;' });
            $('#ll-tools-flashcard-content').prepend($prompt);
        }
        if (promptType !== 'image' || !targetWord || !targetWord.image) {
            $prompt.hide().empty();
            return;
        }
        $prompt.show().empty();
        const $wrap = $('<div>', { class: 'll-prompt-image-wrap' });
        $('<img>', { src: targetWord.image, alt: '', 'aria-hidden': 'true' }).appendTo($wrap);
        $prompt.append($wrap);
    }

    function selectTargetWord(candidateCategory, candidateCategoryName) {
        if (!candidateCategory || !candidateCategory.length) {
            State.completedCategories = State.completedCategories || {};
            State.completedCategories[candidateCategoryName] = true;
            return null;
        }
        let target = null;
        let didRecordPlay = false;
        const queue = State.categoryRepetitionQueues[candidateCategoryName];
        const starredLookup = getStarredLookup();
        const starMode = getStarMode();

        // First, try to find a word from the repetition queue that's ready to reappear
        // and ISN'T the same as the last word shown
        if (queue && queue.length) {
            for (let i = 0; i < queue.length; i++) {
                const queuedItem = queue[i];
                const queuedWord = queuedItem.wordData;
                const allowOverflow = !!queuedItem.forceReplay; // forceReplay allows wrong answers to bypass max-play caps
                const playable = queuedWord && (allowOverflow || canPlayWord(queuedWord.id, starredLookup, starMode));

                if (!playable) {
                    queue.splice(i, 1);
                    i--;
                    continue;
                }
                if (queue[i].reappearRound <= (State.categoryRoundCount[candidateCategoryName] || 0)) {
                    // Skip if this is the same word we just showed
                    if (queue[i].wordData.id !== State.lastWordShownId) {
                        target = queue[i].wordData;
                        if (queuedItem.forceReplay && State.practiceForcedReplays) {
                            const key = String(queuedWord.id);
                            const val = State.practiceForcedReplays[key];
                            if (val) State.practiceForcedReplays[key] = Math.max(0, val - 1);
                        }
                        queue.splice(i, 1);
                        recordPlay(target.id, starredLookup, starMode);
                        didRecordPlay = true;
                        break;
                    }
                }
            }
        }

        // If no target from queue, try to find an unused word (and not the last shown)
        if (!target) {
            State.completedCategories = State.completedCategories || {};
            const unused = getAvailableUnusedWords(candidateCategoryName, starredLookup, starMode)
                .filter(function (w) { return w.id !== State.lastWordShownId; })
                .filter(function (w) { return canPlayWord(w.id, starredLookup, starMode); });

            // If no unused words and no queue, mark this category done
            if ((!unused || !unused.length) && (!queue || !queue.length)) {
                State.completedCategories[candidateCategoryName] = true;
                return null;
            }

            if (unused && unused.length) {
                if (starMode === 'weighted') {
                    const starredPool = unused.filter(function (w) { return !!starredLookup[w.id]; });
                    const regularPool = unused.filter(function (w) { return !starredLookup[w.id]; });
                    let pool = unused;
                    if (starredPool.length && regularPool.length) {
                        // 2:1 bias toward starred, but not front-loading
                        const pickStar = Math.random() < 0.66;
                        pool = pickStar ? starredPool : regularPool;
                    } else if (starredPool.length) {
                        pool = starredPool;
                    } else if (regularPool.length) {
                        pool = regularPool;
                    }
                    target = pool[Math.floor(Math.random() * pool.length)];
                } else {
                    target = unused[Math.floor(Math.random() * unused.length)];
                }
                if (target) {
                    recordPlay(target.id, starredLookup, starMode);
                    didRecordPlay = true;
                }
            } else {
                // Only queued items remain. If multiple categories are in rotation,
                // let another category run while this queue "matures". If this is the
                // only category, fall through to the queue fallback below to avoid
                // stalling on tiny word sets.
                const multipleCategories = Array.isArray(State.categoryNames) && State.categoryNames.length > 1;
                if (multipleCategories) {
                    return null;
                }
            }
        }

        // Fallback: if still no target and queue exists, pick from queue but avoid last shown
        if (!target && queue && queue.length) {
            // Try to find a word that isn't the last shown
            let queueCandidate = queue.find(item => {
                if (!item || !item.wordData) return false;
                if (item.wordData.id === State.lastWordShownId) return false;
                return item.forceReplay || canPlayWord(item.wordData.id, starredLookup, starMode);
            });

            if (!queueCandidate && queue.length > 0) {
                // All queue items are the last shown word, or only one word in queue
                // Try to find any other word from the category
                const others = candidateCategory.filter(w => w.id !== State.lastWordShownId && canPlayWord(w.id, starredLookup, starMode));
                if (others.length) {
                    target = others[Math.floor(Math.random() * others.length)];
                } else {
                    // No choice but to use the queue item (only happens with very small word sets)
                    queueCandidate = queue[0];
                }
            }

            if (queueCandidate) {
                target = queueCandidate.wordData;
                const qi = queue.findIndex(it => it.wordData.id === target.id);
                if (queueCandidate.forceReplay && State.practiceForcedReplays) {
                    const key = String(target.id);
                    const val = State.practiceForcedReplays[key];
                    if (val) State.practiceForcedReplays[key] = Math.max(0, val - 1);
                }
                if (qi !== -1) queue.splice(qi, 1);
            }
        }

        if (target) {
            if (!didRecordPlay) {
                recordPlay(target.id, starredLookup, starMode);
                didRecordPlay = true;
            }
            try { target.__categoryName = candidateCategoryName; } catch (_) { /* no-op */ }
            // Update last shown word ID to prevent consecutive duplicates
            State.lastWordShownId = target.id;

            if (State.currentCategoryName !== candidateCategoryName) {
                State.currentCategoryName = candidateCategoryName;
                State.currentCategoryRoundCount = 0;
                root.FlashcardLoader.preloadNextCategories && root.FlashcardLoader.preloadNextCategories();
                root.LLFlashcards.Dom.updateCategoryNameDisplay(State.currentCategoryName);
            }
            State.currentCategory = State.wordsByCategory[candidateCategoryName];
            State.categoryRoundCount[candidateCategoryName] = (State.categoryRoundCount[candidateCategoryName] || 0) + 1;
            State.currentCategoryRoundCount++;
        }
        return target;
    }

    function selectWordFromNextCategory() {
        pruneCompletedCategories();
        let found = null;
        for (let name of State.categoryNames) {
            const w = selectTargetWord(State.wordsByCategory[name], name);
            // Age the round count even when not selected, so queued items can mature
            State.categoryRoundCount[name] = (State.categoryRoundCount[name] || 0) + 1;
            if (w) { found = w; break; }
        }
        if (!found && Array.isArray(State.categoryNames) && State.categoryNames.length) {
            // Nothing left to serve; mark remaining as completed to allow results.
            State.completedCategories = State.completedCategories || {};
            State.categoryNames.forEach(function (name) { State.completedCategories[name] = true; });
            pruneCompletedCategories();
        } else {
            pruneCompletedCategories();
        }
        return found;
    }

    function selectTargetWordAndCategory() {
        let target = null;
        if (!Array.isArray(State.categoryNames) || State.categoryNames.length === 0) {
            return null;
        }
        pruneCompletedCategories();
        if (!Array.isArray(State.categoryNames) || State.categoryNames.length === 0) {
            return null;
        }
        if (State.isFirstRound) {
            if (!State.firstCategoryName) {
                State.firstCategoryName = State.categoryNames[Math.floor(Math.random() * State.categoryNames.length)];
            }
            target = selectTargetWord(State.wordsByCategory[State.firstCategoryName], State.firstCategoryName);
            State.currentCategoryName = State.firstCategoryName;
            State.currentCategory = State.wordsByCategory[State.currentCategoryName];
        } else {
            const queue = State.categoryRepetitionQueues[State.currentCategoryName];
            const hasReadyFromQueue = Array.isArray(queue) && queue.some(function (item) {
                return item.reappearRound <= (State.categoryRoundCount[State.currentCategoryName] || 0);
            });
            const hasPendingQueue = Array.isArray(queue) && queue.length > 0;
            const starredLookup = getStarredLookup();
            const starMode = getStarMode();
            const hasUnusedInCurrent = getAvailableUnusedWords(State.currentCategoryName, starredLookup, starMode).length > 0;
            const multipleCategories = Array.isArray(State.categoryNames) && State.categoryNames.length > 1;
            if (hasReadyFromQueue || !multipleCategories || State.currentCategoryRoundCount <= State.ROUNDS_PER_CATEGORY) {
                target = selectTargetWord(State.currentCategory, State.currentCategoryName);
            } else if (hasPendingQueue || hasUnusedInCurrent) {
                // Move this category to the end to let others run, but keep it in rotation for queued wrong answers or unused words.
                const i = State.categoryNames.indexOf(State.currentCategoryName);
                if (i > -1) {
                    State.categoryNames.splice(i, 1);
                    State.categoryNames.push(State.currentCategoryName);
                }
                State.categoryRoundCount[State.currentCategoryName] = (State.categoryRoundCount[State.currentCategoryName] || 0) + 1;
                State.currentCategoryRoundCount = (State.currentCategoryRoundCount || 0) + 1;
            } else {
                const i = State.categoryNames.indexOf(State.currentCategoryName);
                if (i > -1) {
                    State.categoryNames.splice(i, 1);
                }
                State.categoryRoundCount[State.currentCategoryName] = 0;
                State.currentCategoryRoundCount = 0;
                if (!State.categoryNames.length) {
                    return null;
                }
                const nextName = State.categoryNames[0];
                target = selectTargetWord(State.wordsByCategory[nextName], nextName);
            }
        }
        if (!target) {
            // Age the current category so queued items can become ready.
            const cname = State.currentCategoryName;
            if (cname) {
                State.categoryRoundCount[cname] = (State.categoryRoundCount[cname] || 0) + 1;
                State.currentCategoryRoundCount = (State.currentCategoryRoundCount || 0) + 1;
                const queue = State.categoryRepetitionQueues[cname];
                const starredLookup = getStarredLookup();
                const starMode = getStarMode();
                const hasUnused = getAvailableUnusedWords(cname, starredLookup, starMode).length > 0;
                const hasQueue = queue && queue.length;
                if (!hasUnused && !hasQueue) {
                    State.completedCategories[cname] = true;
                }
            }
            pruneCompletedCategories();
            target = selectWordFromNextCategory();
        }
        pruneCompletedCategories();
        if (!target) {
            // No target anywhere; mark all remaining categories as done so results can show.
            if (Array.isArray(State.categoryNames)) {
                State.completedCategories = State.completedCategories || {};
                State.categoryNames.forEach(function (name) { State.completedCategories[name] = true; });
            }
            pruneCompletedCategories();
            return null;
        }
        return target;
    }

    /**
     * Learning Mode: Select next word to introduce or quiz
     */
    function selectLearningModeWord() {
        // Delegates to module to avoid mixing mode-specific logic here
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.selectTargetWord()
            : null;
    }

    /**
     * Initialize learning mode word list
     */
    function initializeLearningMode() {
        return window.LLFlashcards && window.LLFlashcards.Modes && window.LLFlashcards.Modes.Learning
            ? window.LLFlashcards.Modes.Learning.initialize()
            : false;
    }

    function fillQuizOptions(targetWord) {
        let chosen = [];
        const targetCategoryName = getTargetCategoryName(targetWord) || State.currentCategoryName;
        if (targetCategoryName && targetCategoryName !== State.currentCategoryName) {
            State.currentCategoryName = targetCategoryName;
            State.currentCategory = State.wordsByCategory[targetCategoryName] || State.currentCategory;
            try { root.LLFlashcards.Dom.updateCategoryNameDisplay(targetCategoryName); } catch (_) { /* no-op */ }
        }

        const config = getCategoryConfig(targetCategoryName);
        const mode = config.option_type || getCategoryDisplayMode(targetCategoryName);
        const promptType = getCategoryPromptType(targetCategoryName);
        State.currentOptionType = mode;
        State.currentPromptType = promptType;
        const isTextOptionMode = (mode === 'text' || mode === 'text_title' || mode === 'text_translation' || mode === 'text_audio');
        const seenOptionTexts = isTextOptionMode ? new Set() : null;
        renderPrompt(targetWord, config);

        const isAudioLineLayout = (promptType === 'image') && (mode === 'audio' || mode === 'text_audio');
        const $container = jQuery('#ll-tools-flashcard');
        const $content = jQuery('#ll-tools-flashcard-content');
        $container.toggleClass('audio-line-layout', isAudioLineLayout);
        $content.toggleClass('audio-line-mode', isAudioLineLayout);

        // In learning mode, only select from introduced words
        let availableWords = [];
        if (State.isLearningMode) {
            // Collect all introduced words from all categories
            State.categoryNames.forEach(catName => {
                const catWords = State.wordsByCategory[catName] || [];
                catWords.forEach(word => {
                    if (State.introducedWordIDs.includes(word.id)) {
                        availableWords.push(word);
                    }
                });
            });
        } else {
            // Practice / listening: only use words from the current category
            const catWords = State.wordsByCategory[targetCategoryName] || [];
            availableWords.push(...catWords);
        }

        const targetId = targetWord ? parseInt(targetWord.id, 10) : 0;
        const targetGroup = (targetWord && targetWord.option_group !== undefined && targetWord.option_group !== null)
            ? String(targetWord.option_group).trim()
            : '';
        let blockedSet = null;
        if (targetWord && Array.isArray(targetWord.option_blocked_ids) && targetWord.option_blocked_ids.length) {
            blockedSet = new Set(targetWord.option_blocked_ids.map(function (id) {
                return parseInt(id, 10);
            }).filter(function (id) {
                return id > 0;
            }));
        }

        // Shuffle available words
        availableWords = Util.randomlySort(availableWords);

        if (targetGroup) {
            const groupMembers = [];
            const otherWords = [];
            availableWords.forEach(function (w) {
                const grp = (w && w.option_group !== undefined && w.option_group !== null)
                    ? String(w.option_group).trim()
                    : '';
                if (grp === targetGroup) {
                    groupMembers.push(w);
                } else {
                    otherWords.push(w);
                }
            });
            if (groupMembers.length) {
                availableWords = groupMembers.concat(otherWords);
            }
        }

        // Add target word first
        root.FlashcardLoader.loadResourcesForWord(targetWord, mode, targetCategoryName, config);
        chosen.push(targetWord);
        root.LLFlashcards.Cards.appendWordToContainer(targetWord, mode, promptType);
        if (isTextOptionMode && targetWord) {
            seenOptionTexts.add(getNormalizedOptionText(targetWord));
        }

        // Determine how many options to show
        let targetCount = State.isLearningMode ?
            root.LLFlashcards.LearningMode.getChoiceCount() :
            root.FlashcardOptions.categoryOptionsCount[targetCategoryName];
        if (!State.isLearningMode && (!targetCount || !isFinite(targetCount))) {
            const fallback = (root.FlashcardOptions && typeof root.FlashcardOptions.checkMinMax === 'function')
                ? root.FlashcardOptions.checkMinMax(2, targetCategoryName)
                : 2;
            targetCount = fallback;
            if (root.FlashcardOptions && root.FlashcardOptions.categoryOptionsCount) {
                root.FlashcardOptions.categoryOptionsCount[targetCategoryName] = fallback;
            }
        }

        // Fill remaining options from available words
        for (let candidate of availableWords) {
            if (chosen.length >= targetCount) break;
            if (!root.FlashcardOptions.canAddMoreCards()) break;

            const isDup = chosen.some(w => w.id === candidate.id);
            const isSim = chosen.some(w => w.similar_word_id === String(candidate.id) || candidate.similar_word_id === String(w.id));
            const sameImg = (mode === 'image') && chosen.some(w => w.image === candidate.image);
            const normalizedText = isTextOptionMode ? getNormalizedOptionText(candidate) : '';
            const sameText = isTextOptionMode && seenOptionTexts.has(normalizedText);
            const candidateId = parseInt(candidate.id, 10);
            const isBlockedByTarget = blockedSet && candidateId > 0 && blockedSet.has(candidateId);
            const isBlockedByCandidate = candidateId > 0 && targetId > 0 && Array.isArray(candidate.option_blocked_ids)
                && candidate.option_blocked_ids.some(function (id) { return parseInt(id, 10) === targetId; });

            if (!isDup && !isSim && !sameImg && !sameText && !isBlockedByTarget && !isBlockedByCandidate) {
                chosen.push(candidate);
                if (isTextOptionMode) {
                    seenOptionTexts.add(normalizedText);
                }
                root.FlashcardLoader.loadResourcesForWord(candidate, mode, targetCategoryName, config);
                root.LLFlashcards.Cards.appendWordToContainer(candidate, mode, promptType);
            }
        }

        jQuery('.flashcard-container').each(function (idx) {
            root.LLFlashcards.Cards.addClickEventToCard(jQuery(this), idx, targetWord, mode, promptType);
        });

        const publishOptionsReady = function () {
            jQuery(document).trigger('ll-tools-options-ready');
        };

        const alignAudioLineWidths = function () {
            const $cards = jQuery('.flashcard-container.audio-option.audio-line-option.text-audio-option');
            if (State.currentPromptType !== 'image' || State.currentOptionType !== 'text_audio' || !$cards.length) {
                $cards.css('width', '');
                return 0;
            }
            let maxWidth = 0;
            $cards.each(function () {
                const $card = jQuery(this);
                $card.css('width', '');
                const rect = this.getBoundingClientRect();
                maxWidth = Math.max(maxWidth, Math.ceil(rect.width));
            });
            const minWidth = 240;
            const vwCap = Math.max(minWidth, Math.floor((typeof window !== 'undefined' ? window.innerWidth : 0) * 0.95) || minWidth);
            const hardCap = Math.min(vwCap, 720);
            maxWidth = Math.max(minWidth, Math.min(maxWidth, hardCap));
            if (maxWidth > 0) {
                $cards.css('width', maxWidth + 'px');
            }
            return maxWidth;
        };

        const shrinkAudioLineText = function () {
            const $labels = jQuery('.flashcard-container.audio-option.audio-line-option.text-audio-option .ll-audio-option-label');
            if (State.currentPromptType !== 'image' || State.currentOptionType !== 'text_audio' || !$labels.length) {
                $labels.css('font-size', '');
                return;
            }
            const MIN_FS = 12;
            $labels.each(function () {
                const $label = jQuery(this);
                $label.css('font-size', '');
                const base = parseFloat((window.getComputedStyle && window.getComputedStyle(this).fontSize) || '') || 17;
                let fs = base;
                for (let i = 0; i < 8 && fs > MIN_FS && this.scrollWidth > this.clientWidth; i++) {
                    fs -= 1;
                    $label.css('font-size', fs + 'px');
                }
            });
        };

        const revealOptions = function () {
            const isAudioLineTextAudio = (State.currentPromptType === 'image' && State.currentOptionType === 'text_audio');
            const $all = jQuery('.flashcard-container');
            if (!isAudioLineTextAudio) {
                $all.hide().fadeIn(600, publishOptionsReady);
                return;
            }
            const $wrap = jQuery('#ll-tools-flashcard');
            $wrap.css({ visibility: 'hidden', opacity: 0 });
            $all.css({ display: '', opacity: 1, visibility: 'visible' });
            alignAudioLineWidths();
            shrinkAudioLineText();
            // Allow layout to settle before reveal
            const show = function () {
                $wrap.css('visibility', 'visible').fadeTo(200, 1, publishOptionsReady);
            };
            if (typeof requestAnimationFrame === 'function') requestAnimationFrame(show);
            else setTimeout(show, 0);
        };

        revealOptions();
    }

    window.LLFlashcards = window.LLFlashcards || {};
    root.LLFlashcards = root.LLFlashcards || {};
    root.LLFlashcards.Selection = {
        getCategoryConfig, getCategoryDisplayMode, getCurrentDisplayMode, getCategoryPromptType, getTargetCategoryName, categoryRequiresAudio, isLearningSupportedForCategories,
        selectTargetWordAndCategory, fillQuizOptions,
        selectLearningModeWord, initializeLearningMode, renderPrompt
    };

    // legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
    root.getCategoryConfig = getCategoryConfig;
    root.getCategoryPromptType = getCategoryPromptType;
})(window);
