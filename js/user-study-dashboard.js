(function ($) {
    'use strict';

    const cfg = window.llToolsStudyData || {};
    const payload = cfg.payload || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};

    const $root = $('[data-ll-study-root]');
    if (!$root.length) { return; }

    let state = Object.assign({ wordset_id: 0, category_ids: [], starred_word_ids: [], star_mode: 'normal', fast_transitions: false }, payload.state || {});
    let wordsets = payload.wordsets || [];
    let categories = payload.categories || [];
    let wordsByCategory = payload.words_by_category || {};
    let savingTimer = null;
    let genderConfig = normalizeGenderConfig(payload.gender || {});

    const $wordsetSelect = $root.find('[data-ll-study-wordset]');
    const $categoriesWrap = $root.find('[data-ll-study-categories]');
    const $wordsWrap = $root.find('[data-ll-study-words]');
    const $catEmpty = $root.find('[data-ll-cat-empty]');
    const $wordsEmpty = $root.find('[data-ll-words-empty]');
    const $starCount = $root.find('[data-ll-star-count]');
    const $starModeToggle = $root.find('[data-ll-star-mode]');
    const $transitionToggle = $root.find('[data-ll-transition-speed]');
    const $genderStart = $root.find('[data-ll-study-gender]');
    const $checkStart = $root.find('[data-ll-study-check-start]');
    const $checkPanel = $root.find('[data-ll-study-check]');
    const $checkPrompt = $root.find('[data-ll-study-check-prompt]');
    const $checkCategory = $root.find('[data-ll-study-check-category]');
    const $checkProgress = $root.find('[data-ll-study-check-progress]');
    const $checkCard = $root.find('[data-ll-study-check-card]');
    const $checkFlip = $root.find('[data-ll-study-check-flip]');
    const $checkAnswer = $root.find('[data-ll-study-check-answer]');
    const $checkActions = $root.find('[data-ll-study-check-actions]');
    const $checkKnow = $root.find('[data-ll-study-check-know]');
    const $checkUnknown = $root.find('[data-ll-study-check-unknown]');
    const $checkComplete = $root.find('[data-ll-study-check-complete]');
    const $checkSummary = $root.find('[data-ll-study-check-summary]');
    const $checkApply = $root.find('[data-ll-study-check-apply]');
    const $checkRestart = $root.find('[data-ll-study-check-restart]');
    const $checkExit = $root.find('[data-ll-study-check-exit]');

    let currentAudio = null;
    let currentAudioButton = null;
    const recordingTypeOrder = ['question', 'isolation', 'introduction'];
    const recordingIcons = {
        question: '❓',
        isolation: '🔍',
        introduction: '💬'
    };
    const recordingLabels = {
        question: i18n.recordingQuestion || 'Question',
        isolation: i18n.recordingIsolation || 'Isolation',
        introduction: i18n.recordingIntroduction || 'Introduction'
    };
    let vizContext = null;
    let vizAnalyser = null;
    let vizAnalyserData = null;
    let vizTimeData = null;
    let vizAnalyserConnected = false;
    let vizRafId = null;
    let vizBars = [];
    let vizBarLevels = [];
    let vizButton = null;
    let vizAudio = null;
    let vizSource = null;
    let checkSession = null;

    function formatRecordingLabel(typeLabel) {
        const template = i18n.playAudioType || '';
        if (template && template.indexOf('%s') !== -1) {
            return template.replace('%s', typeLabel);
        }
        if (template) {
            return template + ' ' + typeLabel;
        }
        return 'Play ' + typeLabel + ' recording';
    }

    function canUseVisualizerForUrl(url) {
        if (!url) { return false; }
        try {
            const target = new URL(url, window.location.href);
            return target.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function selectRecordingUrl(word, type) {
        const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
        const preferredSpeaker = parseInt(word.preferred_speaker_user_id, 10) || 0;
        let match = null;

        if (preferredSpeaker) {
            match = audioFiles.find(function (file) {
                return file && file.url && file.recording_type === type && parseInt(file.speaker_user_id, 10) === preferredSpeaker;
            });
        }

        if (!match) {
            match = audioFiles.find(function (file) {
                return file && file.url && file.recording_type === type;
            });
        }

        return match ? match.url : '';
    }

    function ensureVisualizerContext() {
        if (vizContext) { return vizContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            vizContext = new Ctor();
        } catch (_) {
            vizContext = null;
        }
        return vizContext;
    }

    function ensureVisualizerAnalyser() {
        const ctx = ensureVisualizerContext();
        if (!ctx) { return null; }
        if (!vizAnalyser) {
            vizAnalyser = ctx.createAnalyser();
            vizAnalyser.fftSize = 256;
            vizAnalyser.smoothingTimeConstant = 0.65;
            vizAnalyserData = new Uint8Array(vizAnalyser.frequencyBinCount);
            vizTimeData = new Uint8Array(vizAnalyser.fftSize);
        }
        if (!vizAnalyserConnected) {
            try {
                vizAnalyser.connect(ctx.destination);
                vizAnalyserConnected = true;
            } catch (_) {
                return null;
            }
        }
        return vizAnalyser;
    }

    function setVisualizerBars(button) {
        if (!button) { return false; }
        const bars = button.querySelectorAll('.ll-study-recording-visualizer .bar');
        if (!bars.length) { return false; }
        vizBars = Array.from(bars);
        vizBarLevels = vizBars.map(() => 0);
        vizButton = button;
        return true;
    }

    function resetVisualizerBars() {
        if (!vizBars.length) { return; }
        vizBars.forEach(function (bar) {
            bar.style.setProperty('--level', '0');
        });
        vizBarLevels = vizBars.map(() => 0);
    }

    function stopVisualizer() {
        if (vizRafId) {
            cancelAnimationFrame(vizRafId);
            vizRafId = null;
        }
        if (vizSource) {
            try { vizSource.disconnect(); } catch (_) { }
            vizSource = null;
        }
        if (vizButton) {
            $(vizButton).removeClass('ll-study-recording-btn--js');
        }
        resetVisualizerBars();
        vizBars = [];
        vizBarLevels = [];
        vizButton = null;
        vizAudio = null;
    }

    function updateVisualizer() {
        if (!vizAnalyser || !vizBars.length || !vizAnalyserData || !vizTimeData) {
            vizRafId = null;
            return;
        }
        if (!vizAudio) {
            stopVisualizer();
            return;
        }
        if (vizAudio.paused) {
            if (vizAudio.currentTime === 0 && !vizAudio.ended) {
                vizRafId = requestAnimationFrame(updateVisualizer);
                return;
            }
            stopVisualizer();
            return;
        }
        if (!vizContext || vizContext.state !== 'running') {
            vizRafId = requestAnimationFrame(updateVisualizer);
            return;
        }

        vizAnalyser.getByteFrequencyData(vizAnalyserData);
        vizAnalyser.getByteTimeDomainData(vizTimeData);

        const slice = Math.max(1, Math.floor(vizAnalyserData.length / vizBars.length));
        let sumSquares = 0;
        for (let i = 0; i < vizTimeData.length; i++) {
            const deviation = vizTimeData[i] - 128;
            sumSquares += deviation * deviation;
        }
        const rms = Math.min(1, Math.sqrt(sumSquares / vizTimeData.length) / 64);

        for (let i = 0; i < vizBars.length; i++) {
            let sum = 0;
            for (let j = 0; j < slice; j++) {
                sum += vizAnalyserData[(i * slice) + j] || 0;
            }
            const avg = sum / slice;
            const normalized = Math.max(0, (avg - 40) / 215);
            const combined = Math.min(1, (normalized * 0.7) + (rms * 0.9));
            const eased = Math.pow(combined, 1.35);

            const previous = vizBarLevels[i] || 0;
            const level = previous + (eased - previous) * 0.35;
            vizBarLevels[i] = level;
            vizBars[i].style.setProperty('--level', level.toFixed(3));
        }

        vizRafId = requestAnimationFrame(updateVisualizer);
    }

    function startVisualizer(audio, button) {
        if (!audio || !button) { return; }
        stopVisualizer();
        const ctx = ensureVisualizerContext();
        if (!ctx) { return; }
        const resumePromise = (ctx.state === 'suspended') ? ctx.resume() : Promise.resolve();
        const targetAudio = audio;
        const targetButton = button;

        resumePromise.then(function () {
            if (targetAudio !== currentAudio || targetButton !== currentAudioButton) { return; }
            const analyser = ensureVisualizerAnalyser();
            if (!analyser) { return; }
            if (!setVisualizerBars(button)) { return; }

            let source = audio.__llStudyVisualizerSource;
            if (!source) {
                try {
                    source = ctx.createMediaElementSource(audio);
                    audio.__llStudyVisualizerSource = source;
                } catch (_) {
                    return;
                }
            }

            try { source.disconnect(); } catch (_) { }
            try {
                source.connect(analyser);
            } catch (_) {
                try { source.connect(ctx.destination); } catch (_) { }
                return;
            }

            vizSource = source;
            vizAudio = audio;
            vizButton = button;
            $(button).addClass('ll-study-recording-btn--js');

            if (vizRafId) {
                cancelAnimationFrame(vizRafId);
            }
            updateVisualizer();
        }).catch(function () { });
    }

    function normalizeStarMode(mode) {
        const val = (mode || '').toString();
        return (val === 'normal' || val === 'only' || val === 'weighted') ? val : 'normal';
    }

    function normalizeGenderConfig(raw) {
        const cfg = (raw && typeof raw === 'object') ? raw : {};
        const optionsRaw = Array.isArray(cfg.options) ? cfg.options : [];
        const options = [];
        const seen = {};
        optionsRaw.forEach(function (opt) {
            if (opt === null || opt === undefined) { return; }
            const val = String(opt).trim();
            if (!val) { return; }
            const key = val.toLowerCase();
            if (seen[key]) { return; }
            seen[key] = true;
            options.push(val);
        });
        return {
            enabled: !!cfg.enabled,
            options: options,
            min_count: parseInt(cfg.min_count, 10) || 0
        };
    }

    function setGenderConfig(raw) {
        genderConfig = normalizeGenderConfig(raw);
    }

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; }).filter(function (v) { return v > 0; });
    }

    function findWordsetSlug(id) {
        const ws = wordsets.find(function (w) { return parseInt(w.id, 10) === parseInt(id, 10); });
        return ws ? ws.slug : '';
    }

    function isWordStarred(id) {
        return state.starred_word_ids.indexOf(id) !== -1;
    }

    function setStarredWordIds(ids) {
        const seen = {};
        state.starred_word_ids = toIntList(ids).filter(function (id) {
            if (seen[id]) { return false; }
            seen[id] = true;
            return true;
        });
    }

    function getCategoryWords(catId) {
        return wordsByCategory[catId] || [];
    }

    function categoryStarState(catId) {
        const words = getCategoryWords(catId);
        if (!words.length) {
            return { allStarred: false, hasWords: false };
        }
        const ids = words.map(function (w) { return parseInt(w.id, 10) || 0; }).filter(Boolean);
        if (!ids.length) {
            return { allStarred: false, hasWords: false };
        }
        const allStarred = ids.every(function (id) { return isWordStarred(id); });
        return { allStarred: allStarred, hasWords: true };
    }

    function getGenderOptions() {
        return Array.isArray(genderConfig.options) ? genderConfig.options : [];
    }

    function isGenderEnabledForWordset() {
        return !!genderConfig.enabled && getGenderOptions().length >= 2;
    }

    function isGenderSupportedForSelection() {
        if (!isGenderEnabledForWordset()) { return false; }
        const selectedIds = toIntList(state.category_ids);
        if (!selectedIds.length) { return false; }
        const selectedCats = categories.filter(function (cat) {
            return selectedIds.indexOf(parseInt(cat.id, 10)) !== -1;
        });
        if (!selectedCats.length) { return false; }
        return selectedCats.every(function (cat) {
            return !!(cat && cat.gender_supported);
        });
    }

    function updateGenderButtonVisibility() {
        if (!$genderStart.length) { return; }
        const allowed = isGenderSupportedForSelection();
        $genderStart.toggleClass('ll-study-btn--hidden', !allowed);
        $genderStart.attr('aria-hidden', allowed ? 'false' : 'true');
    }

    function applyGenderConfigToFlashcardsData(flashData) {
        const target = flashData || (window.llToolsFlashcardsData || {});
        const options = getGenderOptions();
        target.genderEnabled = !!genderConfig.enabled;
        target.genderOptions = options.slice();
        target.genderWordsetId = parseInt(state.wordset_id, 10) || 0;
        const fallbackMin = parseInt(target.genderMinCount, 10) || 0;
        const minCount = parseInt(genderConfig.min_count, 10) || fallbackMin || 2;
        target.genderMinCount = minCount;
    }

    function ensureWordsForCategory(catId) {
        const cid = parseInt(catId, 10);
        if (!cid) { return $.Deferred().resolve([]).promise(); }
        if (wordsByCategory[cid]) {
            return $.Deferred().resolve(wordsByCategory[cid]).promise();
        }
        return $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: [cid]
        }).then(function (res) {
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = Object.assign({}, wordsByCategory, res.data.words_by_category);
                return wordsByCategory[cid] || [];
            }
            return [];
        }, function () {
            return [];
        });
    }

    function getCategoryById(catId) {
        const cid = parseInt(catId, 10);
        if (!cid) { return null; }
        return categories.find(function (cat) {
            return parseInt(cat.id, 10) === cid;
        }) || null;
    }

    function getCategoryLabel(cat) {
        if (!cat) { return ''; }
        return cat.translation || cat.name || '';
    }

    function getCategoryOptionType(cat) {
        if (!cat) { return 'image'; }
        return cat.option_type || cat.mode || 'image';
    }

    function getCategoryPromptType(cat) {
        if (!cat) { return 'audio'; }
        return cat.prompt_type || 'audio';
    }

    function isTextOptionType(optionType) {
        return (optionType === 'text' || optionType === 'text_title' || optionType === 'text_translation' || optionType === 'text_audio');
    }

    function isAudioOptionType(optionType) {
        return (optionType === 'audio' || optionType === 'text_audio');
    }

    function getCheckWordLabel(word) {
        if (!word) { return ''; }
        return word.label || word.title || '';
    }

    function getWordAudioUrl(word) {
        if (!word) { return ''; }
        if (word.audio) { return word.audio; }
        const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
        if (!audioFiles.length) { return ''; }
        const preferred = parseInt(word.preferred_speaker_user_id, 10) || 0;
        if (preferred) {
            const match = audioFiles.find(function (file) {
                return file && file.url && parseInt(file.speaker_user_id, 10) === preferred;
            });
            if (match) { return match.url; }
        }
        const fallback = audioFiles.find(function (file) { return file && file.url; });
        return fallback ? fallback.url : '';
    }

    function formatCheckSummary(count) {
        const template = i18n.checkSummary || '';
        if (template.indexOf('%1$d') !== -1) {
            return template.replace('%1$d', count);
        }
        if (template.indexOf('%d') !== -1) {
            return template.replace('%d', count);
        }
        if (template.indexOf('%s') !== -1) {
            return template.replace('%s', count);
        }
        if (template) {
            return template + ' ' + count;
        }
        return 'You marked ' + count + ' as "I don\'t know".';
    }

    function ensureWordsForCategories(catIds) {
        const ids = toIntList(catIds);
        const requests = ids.map(function (id) { return ensureWordsForCategory(id); });
        if (!requests.length) {
            return $.Deferred().resolve().promise();
        }
        return $.when.apply($, requests).then(function () {
            return true;
        });
    }

    function shuffleItems(items) {
        const list = items.slice();
        for (let i = list.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const temp = list[i];
            list[i] = list[j];
            list[j] = temp;
        }
        return list;
    }

    function buildCheckQueue(categoryIds) {
        const ids = shuffleItems(toIntList(categoryIds));
        const items = [];
        const seen = {};
        ids.forEach(function (cid) {
            const cat = getCategoryById(cid);
            const optionType = getCategoryOptionType(cat);
            const promptType = getCategoryPromptType(cat);
            const catLabel = getCategoryLabel(cat);
            const words = shuffleItems(getCategoryWords(cid) || []);
            words.forEach(function (word) {
                const wordId = parseInt(word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                seen[wordId] = true;
                items.push({
                    word: word,
                    wordId: wordId,
                    categoryId: cid,
                    categoryLabel: catLabel,
                    optionType: optionType,
                    promptType: promptType
                });
            });
        });
        return items;
    }

    function buildCheckAudioButton(audioUrl) {
        const label = i18n.playAudio || 'Play audio';
        const $btn = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-study-recording-btn--prompt',
            'data-audio-url': audioUrl,
            'data-recording-type': 'prompt',
            'aria-label': label,
            title: label
        });
        $('<span>', {
            class: 'll-study-recording-icon',
            'aria-hidden': 'true',
            'data-emoji': '\u25B6'
        }).appendTo($btn);
        const viz = $('<span>', {
            class: 'll-study-recording-visualizer',
            'aria-hidden': 'true'
        });
        for (let i = 0; i < 6; i++) {
            $('<span>', { class: 'bar' }).appendTo(viz);
        }
        $btn.append(viz);
        return $btn;
    }

    function renderCheckDisplay($container, displayType, word) {
        if (!$container || !$container.length) { return false; }
        $container.empty();

        const mode = String(displayType || '');
        const showImage = mode === 'image';
        const showText = isTextOptionType(mode);
        const showAudio = isAudioOptionType(mode);
        const text = getCheckWordLabel(word);
        const audioUrl = showAudio ? getWordAudioUrl(word) : '';

        const $inner = $('<div>', { class: 'll-study-check-prompt-inner' });
        let hasContent = false;

        if (showImage && word && word.image) {
            const $imgWrap = $('<div>', { class: 'll-study-check-image' });
            $('<img>', {
                src: word.image,
                alt: text || word.title || '',
                loading: 'lazy'
            }).appendTo($imgWrap);
            $inner.append($imgWrap);
            hasContent = true;
        }

        if (showText && text) {
            $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
            hasContent = true;
        }

        if (showAudio && audioUrl) {
            $inner.append(buildCheckAudioButton(audioUrl));
            hasContent = true;
        }

        if (!$inner.children().length) {
            if (text) {
                $('<div>', { class: 'll-study-check-text', text: text }).appendTo($inner);
                hasContent = true;
            } else {
                $('<div>', { class: 'll-study-check-empty', text: i18n.checkEmpty || 'No words available for this check.' }).appendTo($inner);
            }
        }

        $container.append($inner);
        return hasContent;
    }

    function renderCheckPrompt(item) {
        const word = (item && item.word) ? item.word : null;
        const optionType = item ? String(item.optionType || '') : '';
        renderCheckDisplay($checkPrompt, optionType, word);
    }

    function renderCheckAnswer(item) {
        const word = (item && item.word) ? item.word : null;
        const promptType = item ? String(item.promptType || '') : '';
        return renderCheckDisplay($checkAnswer, promptType, word);
    }

    function setCheckFlipped(isFlipped) {
        if (!$checkCard.length) { return; }
        $checkCard.toggleClass('is-flipped', !!isFlipped);
    }

    function openCheckPanel() {
        if (!$checkPanel.length) { return; }
        $checkPanel.addClass('is-active').attr('aria-hidden', 'false');
        $checkSummary.text('');
        $checkPrompt.show().empty();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        if ($checkAnswer.length) {
            $checkAnswer.show().empty();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
    }

    function closeCheckPanel() {
        if (!$checkPanel.length) { return; }
        $checkPanel.removeClass('is-active').attr('aria-hidden', 'true');
        $checkCategory.text('');
        $checkProgress.text('');
        $checkPrompt.empty().show();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        if ($checkAnswer.length) {
            $checkAnswer.show().empty();
        }
        setCheckFlipped(false);
        $checkComplete.hide();
        checkSession = null;
        stopCurrentAudio();
    }

    function showCheckItem() {
        if (!checkSession || !Array.isArray(checkSession.items) || !checkSession.items.length) {
            return;
        }
        if (checkSession.index >= checkSession.items.length) {
            showCheckComplete();
            return;
        }

        const item = checkSession.items[checkSession.index];
        const total = checkSession.items.length;
        const progressText = (checkSession.index + 1) + ' / ' + total;
        $checkProgress.text(progressText);

        const catLabel = item.categoryLabel || '';
        if (catLabel) {
            $checkCategory.text(catLabel).show();
        } else {
            $checkCategory.text('').hide();
        }

        $checkPrompt.show();
        $checkActions.show();
        if ($checkCard.length) {
            $checkCard.show();
        }
        $checkComplete.hide();
        renderCheckPrompt(item);
        const hasAnswer = renderCheckAnswer(item);
        if ($checkFlip.length) {
            $checkFlip.toggle(!!hasAnswer);
        }
        setCheckFlipped(false);
    }

    function showCheckComplete() {
        if (!checkSession) { return; }
        const total = checkSession.items.length || 0;
        const unknownCount = (checkSession.unknownIds || []).length;
        $checkSummary.text(formatCheckSummary(unknownCount));
        if (total) {
            $checkProgress.text(total + ' / ' + total);
        }
        $checkCategory.text('').hide();
        $checkPrompt.hide().empty();
        $checkActions.hide();
        if ($checkCard.length) {
            $checkCard.hide();
        }
        if ($checkAnswer.length) {
            $checkAnswer.hide().empty();
        }
        setCheckFlipped(false);
        $checkComplete.show();
    }

    function recordCheckResult(known) {
        if (!checkSession || !checkSession.items || !checkSession.items.length) { return; }
        const item = checkSession.items[checkSession.index];
        if (!item || !item.wordId) { return; }
        if (!known) {
            checkSession.unknownLookup = checkSession.unknownLookup || {};
            if (!checkSession.unknownLookup[item.wordId]) {
                checkSession.unknownLookup[item.wordId] = true;
                checkSession.unknownIds.push(item.wordId);
            }
        }
        checkSession.index += 1;
        stopCurrentAudio();
        showCheckItem();
    }

    function getWordIdsForCategories(catIds) {
        const ids = [];
        const seen = {};
        toIntList(catIds).forEach(function (cid) {
            const words = getCategoryWords(cid) || [];
            words.forEach(function (word) {
                const wordId = parseInt(word.id, 10) || 0;
                if (!wordId || seen[wordId]) { return; }
                seen[wordId] = true;
                ids.push(wordId);
            });
        });
        return ids;
    }

    function startCheckFlow(categoryIds) {
        const ids = toIntList(categoryIds || state.category_ids);
        if (!ids.length) {
            ensureCategoriesSelected();
            return;
        }
        if ($checkStart.length) {
            $checkStart.prop('disabled', true).addClass('loading');
        }

        ensureWordsForCategories(ids).always(function () {
            const items = buildCheckQueue(ids);
            if (!items.length) {
                alert(i18n.checkEmpty || 'No words available for this check.');
                closeCheckPanel();
                return;
            }
            checkSession = {
                items: items,
                index: 0,
                unknownIds: [],
                unknownLookup: {},
                categoryIds: ids
            };
            openCheckPanel();
            showCheckItem();
        }).always(function () {
            if ($checkStart.length) {
                $checkStart.prop('disabled', false).removeClass('loading');
            }
        });
    }

    function applyCheckStars() {
        if (!checkSession) { return; }
        const categoryIds = toIntList(checkSession.categoryIds || state.category_ids);
        if (!categoryIds.length) {
            closeCheckPanel();
            return;
        }
        const selectedWordIds = getWordIdsForCategories(categoryIds);
        if (!selectedWordIds.length) {
            closeCheckPanel();
            return;
        }
        const selectedLookup = {};
        selectedWordIds.forEach(function (id) { selectedLookup[id] = true; });

        const keep = (state.starred_word_ids || []).filter(function (id) {
            return !selectedLookup[id];
        });
        const unknownIds = toIntList(checkSession.unknownIds || []);
        const next = keep.slice();
        unknownIds.forEach(function (id) {
            if (!selectedLookup[id]) { return; }
            if (next.indexOf(id) === -1) { next.push(id); }
        });
        setStarredWordIds(next);
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
        closeCheckPanel();
    }

    function setStudyPrefsGlobal() {
        state.star_mode = normalizeStarMode(state.star_mode);
        window.llToolsStudyPrefs = {
            starredWordIds: state.starred_word_ids ? state.starred_word_ids.slice() : [],
            starMode: state.star_mode || 'normal',
            star_mode: state.star_mode || 'normal',
            fastTransitions: !!state.fast_transitions,
            fast_transitions: !!state.fast_transitions
        };
    }

    function renderWordsets() {
        $wordsetSelect.empty();
        wordsets.forEach(function (ws) {
            $('<option>', {
                value: ws.id,
                text: ws.name,
                selected: parseInt(ws.id, 10) === parseInt(state.wordset_id, 10)
            }).appendTo($wordsetSelect);
        });
    }

    function renderStarModeToggle() {
        const mode = normalizeStarMode(state.star_mode);
        $starModeToggle.find('.ll-study-btn').removeClass('active');
        $starModeToggle.find('[data-mode="' + mode + '"]').addClass('active');
    }

    function renderTransitionToggle() {
        const fast = !!state.fast_transitions;
        $transitionToggle.find('.ll-study-btn').removeClass('active');
        $transitionToggle.find(fast ? '[data-speed="fast"]' : '[data-speed="slow"]').addClass('active');
    }

    function renderCategories() {
        $categoriesWrap.empty();
        const selectedLookup = {};
        state.category_ids.forEach(function (id) { selectedLookup[id] = true; });

        if (!categories.length) {
            $catEmpty.show();
            updateGenderButtonVisibility();
            return;
        }
        $catEmpty.hide();

        categories.forEach(function (cat) {
            const checked = !!selectedLookup[cat.id];
            const label = cat.translation || cat.name;
            const countLabel = typeof cat.word_count !== 'undefined' ? ' (' + cat.word_count + ')' : '';
            const row = $('<div>', { class: 'll-cat-row', 'data-cat-id': cat.id });

            const labelWrap = $('<label>', { class: 'll-cat-label' });
            $('<input>', { type: 'checkbox', value: cat.id, checked: checked }).appendTo(labelWrap);
            $('<span>', { class: 'll-cat-name', text: label + countLabel }).appendTo(labelWrap);
            row.append(labelWrap);

            $categoriesWrap.append(row);
        });
        updateGenderButtonVisibility();
    }

    function renderWords() {
        $wordsWrap.empty();
        const selected = toIntList(state.category_ids);
        if (!selected.length) {
            $wordsEmpty.show();
            $starCount.text(0);
            return;
        }
        $wordsEmpty.hide();

        const starredLookup = {};
        state.starred_word_ids.forEach(function (id) { starredLookup[id] = true; });

        let totalStarredInView = 0;

        selected.forEach(function (cid) {
            const cat = categories.find(function (c) { return parseInt(c.id, 10) === cid; });
            const catLabel = cat ? (cat.translation || cat.name) : '';
            const words = wordsByCategory[cid] || [];

            const group = $('<div>', { class: 'll-word-group' });
            const titleRow = $('<div>', { class: 'll-word-group__title' });
            $('<span>', { text: catLabel }).appendTo(titleRow);
            const starState = categoryStarState(cid);
            const starLabel = starState.allStarred ? (i18n.unstarAll || 'Unstar all') : (i18n.starAll || 'Star all');
            $('<button>', {
                type: 'button',
                class: 'll-study-btn tiny ghost ll-group-star' + (starState.allStarred ? ' active' : ''),
                'data-cat-id': cid,
                disabled: !starState.hasWords,
                text: (starState.allStarred ? '★ ' : '☆ ') + starLabel
            }).appendTo(titleRow);
            group.append(titleRow);

            if (!words.length) {
                $('<p>', { class: 'll-word-empty', text: i18n.noWords || 'No words yet.' }).appendTo(group);
            } else {
                const list = $('<div>', { class: 'll-word-list' });
                words.forEach(function (w) {
                    const isStarred = !!starredLookup[w.id];
                    if (isStarred) { totalStarredInView++; }
                    const row = $('<div>', {
                        class: 'll-word-row' + (w.image ? '' : ' ll-word-row--no-image'),
                        'data-word-id': w.id
                    });
                    $('<button>', {
                        type: 'button',
                        class: 'll-word-star' + (isStarred ? ' active' : ''),
                        'aria-pressed': isStarred ? 'true' : 'false',
                        text: isStarred ? '★' : '☆'
                    }).appendTo(row);

                    if (w.image) {
                        const thumb = $('<div>', { class: 'll-word-thumb' });
                        $('<img>', {
                            src: w.image,
                            alt: w.label || w.title || '',
                            loading: 'lazy'
                        }).appendTo(thumb);
                        row.append(thumb);
                    }

                    $('<span>', { class: 'll-word-text', text: w.label || w.title }).appendTo(row);

                    const recordingsWrap = $('<div>', {
                        class: 'll-word-recordings',
                        'aria-label': i18n.recordingsLabel || 'Recordings'
                    });
                    let hasRecordings = false;
                    recordingTypeOrder.forEach(function (type) {
                        const icon = recordingIcons[type];
                        if (!icon) { return; }
                        const url = selectRecordingUrl(w, type);
                        if (!url) { return; }
                        hasRecordings = true;
                        const label = recordingLabels[type] || type;
                        const playLabel = formatRecordingLabel(label);
                        const btn = $('<button>', {
                            type: 'button',
                            class: 'll-study-recording-btn ll-study-recording-btn--' + type,
                            'data-audio-url': url,
                            'data-recording-type': type,
                            'aria-label': playLabel,
                            title: playLabel
                        });
                        $('<span>', {
                            class: 'll-study-recording-icon',
                            'aria-hidden': 'true',
                            'data-emoji': icon
                        }).appendTo(btn);
                        const viz = $('<span>', {
                            class: 'll-study-recording-visualizer',
                            'aria-hidden': 'true'
                        });
                        for (let i = 0; i < 6; i++) {
                            $('<span>', { class: 'bar' }).appendTo(viz);
                        }
                        btn.append(viz);
                        recordingsWrap.append(btn);
                    });

                    if (hasRecordings) {
                        row.append(recordingsWrap);
                    }

                    list.append(row);
                });
                group.append(list);
            }
            $wordsWrap.append(group);
        });

        $starCount.text(totalStarredInView);
    }

    function saveStateDebounced() {
        clearTimeout(savingTimer);
        savingTimer = setTimeout(function () {
            $.post(ajaxUrl, {
                action: 'll_user_study_save',
                nonce: nonce,
                wordset_id: state.wordset_id,
                category_ids: state.category_ids,
                starred_word_ids: state.starred_word_ids,
                star_mode: normalizeStarMode(state.star_mode),
                fast_transitions: state.fast_transitions ? 1 : 0
            });
        }, 300);
    }

    function refreshWordsFromServer() {
        const ids = toIntList(state.category_ids);
        if (!ids.length) {
            wordsByCategory = {};
            renderWords();
            return;
        }
        $.post(ajaxUrl, {
            action: 'll_user_study_fetch_words',
            nonce: nonce,
            wordset_id: state.wordset_id,
            category_ids: ids
        }).done(function (res) {
            if (res && res.success && res.data && res.data.words_by_category) {
                wordsByCategory = res.data.words_by_category;
                renderWords();
            }
        });
    }

    function reloadForWordset(wordsetId) {
        $.post(ajaxUrl, {
            action: 'll_user_study_bootstrap',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (res) {
            if (!res || !res.success || !res.data) { return; }
            const data = res.data;
            wordsets = data.wordsets || wordsets;
            categories = data.categories || [];
            setGenderConfig(data.gender || {});
            state = Object.assign({ wordset_id: wordsetId, category_ids: [], starred_word_ids: [], star_mode: 'normal', fast_transitions: false }, data.state || {});
            state.star_mode = normalizeStarMode(state.star_mode);
            wordsByCategory = data.words_by_category || {};
            renderWordsets();
            renderCategories();
            renderWords();
            setStudyPrefsGlobal();
            renderStarModeToggle();
            renderTransitionToggle();
        });
    }

    function ensureCategoriesSelected() {
        if (state.category_ids && state.category_ids.length) {
            return true;
        }
        alert(i18n.noCategories || 'Pick at least one category.');
        return false;
    }

    function startFlashcards(mode) {
        if (!ensureCategoriesSelected()) { return; }
        const starMode = normalizeStarMode(state.star_mode);
        const selectedCats = categories.filter(function (c) {
            return state.category_ids.indexOf(c.id) !== -1;
        });
        const catNames = selectedCats.map(function (c) { return c.name; });
        const genderAllowed = isGenderSupportedForSelection();
        let quizMode = mode || 'practice';
        if (quizMode === 'gender' && !genderAllowed) {
            quizMode = 'practice';
        }

        // Sync global flashcard data
        const flashData = window.llToolsFlashcardsData || {};
        flashData.categories = selectedCats;
        flashData.categoriesPreselected = true;
        flashData.firstCategoryName = catNames[0] || '';
        const firstCat = selectedCats.length ? selectedCats[0] : null;
        const initialWordsRaw = (flashData.firstCategoryName && firstCat && wordsByCategory[firstCat.id])
            ? wordsByCategory[firstCat.id]
            : [];
        if (starMode === 'only') {
            const starredLookup = {};
            state.starred_word_ids.forEach(function (id) { starredLookup[id] = true; });
            flashData.firstCategoryData = initialWordsRaw.filter(function (w) { return starredLookup[w.id]; });
        } else {
            flashData.firstCategoryData = initialWordsRaw;
        }
        flashData.wordset = findWordsetSlug(state.wordset_id);
        flashData.wordsetIds = state.wordset_id ? [state.wordset_id] : [];
        flashData.wordsetFallback = false;
        applyGenderConfigToFlashcardsData(flashData);
        flashData.quiz_mode = quizMode;
        flashData.starMode = starMode;
        flashData.fastTransitions = !!state.fast_transitions;
        flashData.fast_transitions = !!state.fast_transitions;
        window.llToolsFlashcardsData = flashData;

        setStudyPrefsGlobal();

        $('body').addClass('ll-tools-flashcard-open');
        const $popup = $root.find('#ll-tools-flashcard-popup');
        $popup.show();
        $popup.find('#ll-tools-flashcard-quiz-popup').show();

        if (typeof window.initFlashcardWidget === 'function') {
            window.initFlashcardWidget(catNames, quizMode);
        }
    }

    $wordsetSelect.on('change', function () {
        const newId = parseInt($(this).val(), 10) || 0;
        state.wordset_id = newId;
        state.category_ids = [];
        state.starred_word_ids = [];
        state.star_mode = normalizeStarMode(state.star_mode);
        setStudyPrefsGlobal();
        reloadForWordset(newId);
        saveStateDebounced();
        renderStarModeToggle();
    });

    $categoriesWrap.on('change', 'input[type="checkbox"]', function () {
        const ids = [];
        $categoriesWrap.find('input[type="checkbox"]:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
        });
        state.category_ids = ids;
        updateGenderButtonVisibility();
        saveStateDebounced();
        refreshWordsFromServer();
    });

    $root.find('[data-ll-check-all]').on('click', function () {
        state.category_ids = categories.map(function (c) { return c.id; });
        renderCategories();
        saveStateDebounced();
        refreshWordsFromServer();
    });

    $root.find('[data-ll-uncheck-all]').on('click', function () {
        state.category_ids = [];
        renderCategories();
        renderWords();
        saveStateDebounced();
    });

    $wordsWrap.on('click', '.ll-word-star', function () {
        const $btn = $(this);
        const wordId = parseInt($btn.closest('.ll-word-row').data('word-id'), 10);
        if (!wordId) { return; }
        const idx = state.starred_word_ids.indexOf(wordId);
        if (idx === -1) {
            state.starred_word_ids.push(wordId);
        } else {
            state.starred_word_ids.splice(idx, 1);
        }
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
    });

    $root.find('[data-ll-study-start]').on('click', function () {
        const mode = $(this).data('mode') || 'practice';
        startFlashcards(mode);
    });

    if ($checkStart.length) {
        $checkStart.on('click', function () {
            startCheckFlow();
        });
    }

    if ($checkFlip.length) {
        $checkFlip.on('click', function () {
            if (!$checkCard.length) { return; }
            const flipped = $checkCard.hasClass('is-flipped');
            setCheckFlipped(!flipped);
        });
    }

    if ($checkKnow.length) {
        $checkKnow.on('click', function () {
            recordCheckResult(true);
        });
    }

    if ($checkUnknown.length) {
        $checkUnknown.on('click', function () {
            recordCheckResult(false);
        });
    }

    if ($checkApply.length) {
        $checkApply.on('click', function () {
            applyCheckStars();
        });
    }

    if ($checkRestart.length) {
        $checkRestart.on('click', function () {
            const ids = checkSession && Array.isArray(checkSession.categoryIds)
                ? checkSession.categoryIds
                : state.category_ids;
            startCheckFlow(ids);
        });
    }

    if ($checkExit.length) {
        $checkExit.on('click', function () {
            closeCheckPanel();
        });
    }

    // Star mode toggle
    $starModeToggle.on('click', '.ll-study-btn', function () {
        const mode = $(this).data('mode') || 'normal';
        state.star_mode = normalizeStarMode(mode);
        $(this).addClass('active').siblings().removeClass('active');
        setStudyPrefsGlobal();
        saveStateDebounced();
    });

    $transitionToggle.on('click', '.ll-study-btn', function () {
        const speed = $(this).data('speed') || 'slow';
        state.fast_transitions = speed === 'fast';
        $(this).addClass('active').siblings().removeClass('active');
        setStudyPrefsGlobal();
        saveStateDebounced();
    });

    $wordsWrap.on('click', '.ll-group-star', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const catId = parseInt($btn.data('cat-id'), 10);
        if (!catId) { return; }

        $btn.prop('disabled', true).addClass('loading');

        ensureWordsForCategory(catId).then(function (words) {
            const ids = (words || []).map(function (w) { return parseInt(w.id, 10) || 0; }).filter(Boolean);
            if (!ids.length) { return; }

            const allStarred = ids.every(function (id) { return isWordStarred(id); });
            if (allStarred) {
                const removeLookup = {};
                ids.forEach(function (id) { removeLookup[id] = true; });
                setStarredWordIds(state.starred_word_ids.filter(function (id) { return !removeLookup[id]; }));
            } else {
                const merged = state.starred_word_ids.slice();
                ids.forEach(function (id) {
                    if (merged.indexOf(id) === -1) { merged.push(id); }
                });
                setStarredWordIds(merged);
            }

            setStudyPrefsGlobal();
            saveStateDebounced();
            renderWords();
        }).always(function () {
            $btn.prop('disabled', false).removeClass('loading');
        });
    });

    function stopCurrentAudio() {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio.currentTime = 0;
        }
        if (currentAudioButton) {
            $(currentAudioButton).removeClass('is-playing');
        }
        stopVisualizer();
        currentAudio = null;
        currentAudioButton = null;
    }

    function handleRecordingButtonClick($btn, buttonEl) {
        const url = $btn.attr('data-audio-url') || '';
        if (!url) { return; }
        const useVisualizer = canUseVisualizerForUrl(url);

        if (currentAudio && currentAudioButton === buttonEl) {
            if (!currentAudio.paused) {
                currentAudio.pause();
                $btn.removeClass('is-playing');
                stopVisualizer();
                return;
            }
            if (useVisualizer) {
                startVisualizer(currentAudio, buttonEl);
            } else {
                stopVisualizer();
            }
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = buttonEl;
        currentAudio.addEventListener('play', function () {
            if (currentAudio !== this) { return; }
            $btn.addClass('is-playing');
        });
        currentAudio.addEventListener('pause', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        currentAudio.addEventListener('ended', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
            currentAudio = null;
            currentAudioButton = null;
        });
        currentAudio.addEventListener('error', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        if (useVisualizer) {
            startVisualizer(currentAudio, buttonEl);
        } else {
            stopVisualizer();
        }
        if (currentAudio.play) {
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
        }
    }

    $checkPanel.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleRecordingButtonClick($(this), this);
    });

    $wordsWrap.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleRecordingButtonClick($(this), this);
    });

    // Keep dashboard state in sync with in-quiz star changes
    $(document).on('lltools:star-changed', function (_evt, detail) {
        const info = detail || {};
        const wordId = parseInt(info.wordId || info.word_id, 10) || 0;
        if (!wordId) { return; }
        const shouldStar = info.starred !== false;
        const lookup = {};
        (state.starred_word_ids || []).forEach(function (id) { lookup[id] = true; });
        if (shouldStar) { lookup[wordId] = true; }
        else { delete lookup[wordId]; }
        setStarredWordIds(Object.keys(lookup).map(function (k) { return parseInt(k, 10); }));
        setStudyPrefsGlobal();
        saveStateDebounced();
        renderWords();
        renderCategories();
    });

    // Initial render
    renderWordsets();
    renderCategories();
    renderWords();
    renderStarModeToggle();
    renderTransitionToggle();
    updateGenderButtonVisibility();
    setStudyPrefsGlobal();
})(jQuery);
