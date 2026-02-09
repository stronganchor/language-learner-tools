(function (root) {
    'use strict';
    const { Util, State } = root.LLFlashcards;

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'only' || val === 'normal' || val === 'weighted') ? val : 'normal';
    }

    function getRawCategoryConfig(name) {
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

    function getCategoryConfig(name) {
        return getRawCategoryConfig(name);
    }

    function stripGenderVariation(value) {
        return (value === null || value === undefined)
            ? ''
            : String(value).replace(/[\uFE0E\uFE0F]/g, '');
    }

    function formatGenderDisplayLabel(value) {
        const cleaned = stripGenderVariation(value).trim();
        if (cleaned === '♂' || cleaned === '♀') {
            return cleaned + '\uFE0E';
        }
        return cleaned || String(value || '');
    }

    function getGenderOptions() {
        const data = root.llToolsFlashcardsData || {};
        const raw = Array.isArray(data.genderOptions) ? data.genderOptions : [];
        const options = [];
        const seen = {};
        raw.forEach(function (opt) {
            const val = stripGenderVariation((opt === null || opt === undefined) ? '' : String(opt)).trim();
            if (!val) return;
            const key = val.toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            options.push(val);
        });
        return options;
    }

    function isGenderModeEnabled() {
        const data = root.llToolsFlashcardsData || {};
        return !!data.genderEnabled && getGenderOptions().length >= 2;
    }

    function normalizeGenderValue(value, options) {
        const base = stripGenderVariation((value === null || value === undefined) ? '' : String(value)).trim();
        if (!base) return '';
        const lowered = base.toLowerCase();
        for (let i = 0; i < options.length; i++) {
            const opt = stripGenderVariation((options[i] === null || options[i] === undefined) ? '' : String(options[i])).trim();
            if (!opt) continue;
            if (opt.toLowerCase() === lowered) return opt;
        }
        if (lowered === 'masculine' || lowered === 'feminine') {
            const symbol = lowered === 'masculine' ? '♂' : '♀';
            for (let i = 0; i < options.length; i++) {
                const opt = stripGenderVariation((options[i] === null || options[i] === undefined) ? '' : String(options[i])).trim();
                if (!opt) continue;
                if (opt === symbol) return opt;
            }
        }
        return '';
    }

    function getGenderAssetRequirements(categoryName) {
        const cfg = getRawCategoryConfig(categoryName);
        const opt = cfg.option_type || cfg.mode || State.DEFAULT_DISPLAY_MODE;
        const promptType = cfg.prompt_type || 'audio';
        const requiresAudio = (promptType === 'audio') || opt === 'audio' || opt === 'text_audio';
        const requiresImage = (promptType === 'image') || opt === 'image';
        return { requiresAudio, requiresImage };
    }

    function isGenderEligibleWord(word, options, categoryName) {
        if (!word || !options || !options.length) return false;
        const posRaw = word.part_of_speech;
        const pos = Array.isArray(posRaw) ? posRaw : (posRaw ? [posRaw] : []);
        const isNoun = pos.some(function (p) { return String(p).toLowerCase() === 'noun'; });
        if (!isNoun) return false;

        const genderLabel = normalizeGenderValue(word.grammatical_gender, options);
        if (!genderLabel) return false;

        const requirements = getGenderAssetRequirements(categoryName || getTargetCategoryName(word));
        const hasImage = !!(word.image || word.has_image);
        const hasAudio = !!(word.audio || word.has_audio);
        if (requirements.requiresImage && !hasImage) return false;
        if (requirements.requiresAudio && !hasAudio) return false;

        word.__gender_label = genderLabel;
        return true;
    }

    function getGenderWordsByCategory() {
        const source = State.wordsByCategory || {};
        const options = getGenderOptions();
        const out = {};
        Object.keys(source).forEach(function (name) {
            const list = Array.isArray(source[name]) ? source[name] : [];
            out[name] = list.filter(function (word) { return isGenderEligibleWord(word, options, name); });
        });
        return out;
    }

    function getActiveWordsByCategory() {
        if (State && State.isGenderMode && isGenderModeEnabled()) {
            return getGenderWordsByCategory();
        }
        return State.wordsByCategory || {};
    }

    function getCategoryDisplayMode(name) {
        if (!name) return State.DEFAULT_DISPLAY_MODE;
        const cfg = getCategoryConfig(name);
        const opt = cfg.option_type || cfg.mode || State.DEFAULT_DISPLAY_MODE;
        if (opt === 'text_title' || opt === 'text_translation') return 'text';
        return opt;
    }
    function getCurrentDisplayMode() {
        if (State && State.isGenderMode && isGenderModeEnabled()) {
            return 'text';
        }
        return getCategoryDisplayMode(State.currentCategoryName);
    }
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

    function shouldCompleteCategoryBeforeSwitch() {
        if (!State) return false;
        if (State.isLearningMode || State.isListeningMode) return false;
        return true;
    }

    function getAvailableUnusedWords(name, starredLookup, starMode) {
        const list = getActiveWordsByCategory()[name] || [];
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

    function normalizeWordId(value) {
        const id = parseInt(value, 10);
        return id > 0 ? id : 0;
    }

    function extractMaskedImageAttachmentId(rawUrl) {
        if (!rawUrl || typeof rawUrl !== 'string') return 0;
        const url = rawUrl.trim();
        if (!url) return 0;

        const directMatch = url.match(/[?&]lltools-img=(\d+)/);
        if (directMatch && directMatch[1]) {
            return normalizeWordId(directMatch[1]);
        }

        if (typeof URL === 'function') {
            try {
                const baseHref = (root.location && root.location.href) ? root.location.href : 'http://localhost/';
                const parsed = new URL(url, baseHref);
                return normalizeWordId(parsed.searchParams.get('lltools-img'));
            } catch (_) {
                return 0;
            }
        }
        return 0;
    }

    function getWordImageIdentity(word) {
        if (!word || typeof word !== 'object' || !word.image) return '';
        const raw = String(word.image).trim();
        if (!raw) return '';

        const attachmentId = extractMaskedImageAttachmentId(raw);
        if (attachmentId > 0) {
            return 'attachment:' + String(attachmentId);
        }

        return 'url:' + raw.split('#')[0];
    }

    function wordHasBlockedId(word, otherId) {
        if (!word || !otherId || !Array.isArray(word.option_blocked_ids)) return false;
        return word.option_blocked_ids.some(function (id) {
            return normalizeWordId(id) === otherId;
        });
    }

    function wordsConflictForOptions(leftWord, rightWord) {
        const leftId = normalizeWordId(leftWord && leftWord.id);
        const rightId = normalizeWordId(rightWord && rightWord.id);
        if (!leftId || !rightId || leftId === rightId) return false;

        if (wordHasBlockedId(leftWord, rightId) || wordHasBlockedId(rightWord, leftId)) {
            return true;
        }

        const leftImage = getWordImageIdentity(leftWord);
        const rightImage = getWordImageIdentity(rightWord);
        return !!leftImage && leftImage === rightImage;
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

    function isGenderSupportedForCategories(categoryNames) {
        if (!isGenderModeEnabled()) return false;
        try {
            const names = Array.isArray(categoryNames) && categoryNames.length ? categoryNames : State.categoryNames;
            if (!Array.isArray(names) || !names.length) return false;
            const minCount = parseInt((root.llToolsFlashcardsData && root.llToolsFlashcardsData.genderMinCount) || '', 10) || 2;
            return names.some(function (name) {
                const cfg = getCategoryConfig(name);
                if (typeof cfg.gender_supported === 'boolean') {
                    return cfg.gender_supported;
                }
                const list = getGenderWordsByCategory()[name] || [];
                return list.length >= minCount;
            });
        } catch (_) {
            return false;
        }
    }

    function renderPrompt(targetWord, cfg) {
        const promptConfig = cfg || getCategoryConfig(State.currentCategoryName);
        const categoryName = getTargetCategoryName(targetWord) || State.currentCategoryName;
        const rawConfig = (State && State.isGenderMode && isGenderModeEnabled())
            ? getRawCategoryConfig(categoryName)
            : promptConfig;
        const promptType = promptConfig.prompt_type || 'audio';
        const optionType = rawConfig.option_type || rawConfig.mode || State.DEFAULT_DISPLAY_MODE;
        const isGender = (State && State.isGenderMode && isGenderModeEnabled());
        const isTextOption = (optionType === 'text' || optionType === 'text_title' || optionType === 'text_translation' || optionType === 'text_audio');
        const requirements = getGenderAssetRequirements(categoryName || State.currentCategoryName);
        const showImage = (promptType === 'image') || (isGender && optionType === 'image');
        const showText = isGender && isTextOption;
        const showAudio = isGender && requirements.requiresAudio && promptType !== 'audio';
        const $ = root.jQuery;
        if (!$) return;
        let $prompt = $('#ll-tools-prompt');
        if (!$prompt.length) {
            $prompt = $('<div>', { id: 'll-tools-prompt', class: 'll-tools-prompt', style: 'display:none;' });
            $('#ll-tools-flashcard-content').prepend($prompt);
        }
        if (!targetWord) {
            $prompt.hide().empty();
            return;
        }
        const labelText = showText ? (targetWord.label || targetWord.title || '') : '';
        const hasImage = showImage && !!targetWord.image;
        const hasText = showText && !!labelText;
        const hasAudio = showAudio && !!targetWord.audio;
        if (!hasImage && !hasText && !hasAudio) {
            $prompt.hide().empty();
            return;
        }
        $prompt.show().empty();
        const $stack = $('<div>', { class: 'll-prompt-stack' });
        if (hasImage) {
            const $wrap = $('<div>', { class: 'll-prompt-image-wrap' });
            $('<img>', { src: targetWord.image, alt: '', 'aria-hidden': 'true' }).appendTo($wrap);
            $stack.append($wrap);
        }
        if (hasText) {
            $('<div>', { class: 'll-prompt-text', text: labelText }).appendTo($stack);
        }
        if (hasAudio) {
            const $btn = $('<button>', {
                type: 'button',
                class: 'll-prompt-audio-button',
                'aria-label': 'Play word audio'
            });
            const $ui = $('<span>', { class: 'll-repeat-audio-ui' });
            const $iconWrap = $('<span>', { class: 'll-repeat-icon-wrap', 'aria-hidden': 'true' });
            $('<span>', { class: 'll-audio-play-icon', 'aria-hidden': 'true', text: '▶' }).appendTo($iconWrap);
            const $viz = $('<div>', { class: 'll-audio-mini-visualizer', 'aria-hidden': 'true' });
            for (let i = 0; i < 6; i++) {
                $('<span>', { class: 'bar', 'data-bar': i + 1 }).appendTo($viz);
            }
            $ui.append($iconWrap, $viz);
            $btn.append($ui);
            $btn.on('click', function (e) {
                e.stopPropagation();
                if (root.LLFlashcards && root.LLFlashcards.Cards && typeof root.LLFlashcards.Cards.playOptionAudio === 'function') {
                    root.LLFlashcards.Cards.playOptionAudio(targetWord, $btn);
                }
            });
            $stack.append($btn);
        }
        $prompt.append($stack);
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
        const stayInCategory = shouldCompleteCategoryBeforeSwitch();

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
                if (multipleCategories && !stayInCategory) {
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
            State.currentCategory = getActiveWordsByCategory()[candidateCategoryName];
            State.categoryRoundCount[candidateCategoryName] = (State.categoryRoundCount[candidateCategoryName] || 0) + 1;
            State.currentCategoryRoundCount++;
        }
        return target;
    }

    function selectWordFromNextCategory() {
        pruneCompletedCategories();
        let found = null;
        for (let name of State.categoryNames) {
            const w = selectTargetWord(getActiveWordsByCategory()[name], name);
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
        const stayInCategory = shouldCompleteCategoryBeforeSwitch();
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
            target = selectTargetWord(getActiveWordsByCategory()[State.firstCategoryName], State.firstCategoryName);
            State.currentCategoryName = State.firstCategoryName;
            State.currentCategory = getActiveWordsByCategory()[State.currentCategoryName];
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
            if (hasReadyFromQueue || !multipleCategories || stayInCategory || State.currentCategoryRoundCount <= State.ROUNDS_PER_CATEGORY) {
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
                target = selectTargetWord(getActiveWordsByCategory()[nextName], nextName);
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

    function fillGenderQuizOptions(targetWord, config, targetCategoryName) {
        const $ = root.jQuery;
        if (!$ || !targetWord) return false;
        if (!isGenderModeEnabled()) return false;

        const genderOptions = getGenderOptions();
        const genderLabel = normalizeGenderValue(targetWord.grammatical_gender, genderOptions) || targetWord.__gender_label || '';
        if (!genderLabel || genderOptions.length < 2) return false;

        const promptType = getCategoryPromptType(targetCategoryName);
        State.currentOptionType = 'text';
        State.currentPromptType = promptType;
        renderPrompt(targetWord, config);

        const $container = $('#ll-tools-flashcard');
        const $content = $('#ll-tools-flashcard-content');
        $container.removeClass('audio-line-layout');
        $content.removeClass('audio-line-mode');

        genderOptions.forEach(function (label, idx) {
            const normalized = normalizeGenderValue(label, genderOptions);
            const isCorrect = normalized && normalized === genderLabel;
            const optionId = isCorrect ? targetWord.id : (String(targetWord.id) + '-gender-' + idx);
            const optionWord = {
                id: optionId,
                title: label,
                label: formatGenderDisplayLabel(label)
            };
            const $card = root.LLFlashcards.Cards.appendWordToContainer(optionWord, 'text', promptType, true);
            $card.addClass('ll-gender-option');
            root.LLFlashcards.Cards.addClickEventToCard($card, idx, targetWord, 'text', promptType);
        });

        $(document).trigger('ll-tools-options-ready');
        return true;
    }

    function fillQuizOptions(targetWord) {
        let chosen = [];
        const targetCategoryName = getTargetCategoryName(targetWord) || State.currentCategoryName;
        if (targetCategoryName && targetCategoryName !== State.currentCategoryName) {
            State.currentCategoryName = targetCategoryName;
            State.currentCategory = getActiveWordsByCategory()[targetCategoryName] || State.currentCategory;
            try { root.LLFlashcards.Dom.updateCategoryNameDisplay(targetCategoryName); } catch (_) { /* no-op */ }
        }

        const config = getCategoryConfig(targetCategoryName);
        if (State.isGenderMode && fillGenderQuizOptions(targetWord, config, targetCategoryName)) {
            return;
        }
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
                const catWords = getActiveWordsByCategory()[catName] || [];
                catWords.forEach(word => {
                    if (State.introducedWordIDs.includes(word.id)) {
                        availableWords.push(word);
                    }
                });
            });
        } else {
            // Practice / listening: only use words from the current category
            const catWords = getActiveWordsByCategory()[targetCategoryName] || [];
            availableWords.push(...catWords);
        }

        const targetGroups = new Set();
        if (targetWord && Array.isArray(targetWord.option_groups)) {
            targetWord.option_groups.forEach(function (grp) {
                const val = (grp !== undefined && grp !== null) ? String(grp).trim() : '';
                if (val) {
                    targetGroups.add(val);
                }
            });
        }

        // Shuffle available words
        availableWords = Util.randomlySort(availableWords);

        if (targetGroups.size) {
            const grouped = [];
            const others = [];
            availableWords.forEach(function (w) {
                const groups = Array.isArray(w && w.option_groups) ? w.option_groups : [];
                let sharesGroup = false;
                for (let i = 0; i < groups.length; i++) {
                    const val = (groups[i] !== undefined && groups[i] !== null) ? String(groups[i]).trim() : '';
                    if (val && targetGroups.has(val)) {
                        sharesGroup = true;
                        break;
                    }
                }
                if (sharesGroup) {
                    grouped.push(w);
                } else {
                    others.push(w);
                }
            });
            if (grouped.length) {
                availableWords = Util.randomlySort(grouped).concat(Util.randomlySort(others));
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
            const normalizedText = isTextOptionMode ? getNormalizedOptionText(candidate) : '';
            const sameText = isTextOptionMode && seenOptionTexts.has(normalizedText);
            // Enforce pair safety across all cards already chosen for this round.
            const hasOptionConflict = chosen.some(function (existingWord) {
                return wordsConflictForOptions(existingWord, candidate);
            });

            if (!isDup && !isSim && !sameText && !hasOptionConflict) {
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
        getCategoryConfig, getCategoryDisplayMode, getCurrentDisplayMode, getCategoryPromptType, getTargetCategoryName, categoryRequiresAudio, isLearningSupportedForCategories, isGenderSupportedForCategories,
        selectTargetWordAndCategory, fillQuizOptions, wordsConflictForOptions,
        selectLearningModeWord, initializeLearningMode, renderPrompt
    };

    // legacy exports
    root.getCategoryDisplayMode = getCategoryDisplayMode;
    root.getCurrentDisplayMode = getCurrentDisplayMode;
    root.getCategoryConfig = getCategoryConfig;
    root.getCategoryPromptType = getCategoryPromptType;
})(window);
