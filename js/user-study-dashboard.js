(function ($) {
    'use strict';

    const cfg = window.llToolsStudyData || {};
    const payload = cfg.payload || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};

    const $root = $('[data-ll-study-root]');
    if (!$root.length) { return; }

    let state = Object.assign({ wordset_id: 0, category_ids: [], starred_word_ids: [], star_mode: 'weighted' }, payload.state || {});
    let wordsets = payload.wordsets || [];
    let categories = payload.categories || [];
    let wordsByCategory = payload.words_by_category || {};
    let savingTimer = null;

    const $wordsetSelect = $root.find('[data-ll-study-wordset]');
    const $categoriesWrap = $root.find('[data-ll-study-categories]');
    const $wordsWrap = $root.find('[data-ll-study-words]');
    const $catEmpty = $root.find('[data-ll-cat-empty]');
    const $wordsEmpty = $root.find('[data-ll-words-empty]');
    const $starCount = $root.find('[data-ll-star-count]');
    const $starModeToggle = $root.find('[data-ll-star-mode]');

    let currentAudio = null;

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

    function setStudyPrefsGlobal() {
        window.llToolsStudyPrefs = {
            starredWordIds: state.starred_word_ids ? state.starred_word_ids.slice() : [],
            starMode: state.star_mode || 'weighted'
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
        const mode = state.star_mode === 'only' ? 'only' : 'weighted';
        $starModeToggle.find('.ll-study-btn').removeClass('active');
        $starModeToggle.find('[data-mode="' + mode + '"]').addClass('active');
    }

    function renderCategories() {
        $categoriesWrap.empty();
        const selectedLookup = {};
        state.category_ids.forEach(function (id) { selectedLookup[id] = true; });

        if (!categories.length) {
            $catEmpty.show();
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
                        class: 'll-word-row',
                        'data-word-id': w.id,
                        'data-audio-url': w.audio || ''
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
                    } else {
                        $('<div>', { class: 'll-word-thumb placeholder', text: '...' }).appendTo(row);
                    }

                    $('<span>', { class: 'll-word-text', text: w.label || w.title }).appendTo(row);

                    if (w.audio) {
                        $('<button>', {
                            type: 'button',
                            class: 'll-word-audio',
                            'aria-label': i18n.playAudio || 'Play audio',
                            title: i18n.playAudio || 'Play audio'
                        }).text('▶').appendTo(row);
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
                star_mode: state.star_mode || 'weighted'
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
            state = Object.assign({ wordset_id: wordsetId, category_ids: [], starred_word_ids: [], star_mode: 'weighted' }, data.state || {});
            wordsByCategory = data.words_by_category || {};
            renderWordsets();
            renderCategories();
            renderWords();
            setStudyPrefsGlobal();
            renderStarModeToggle();
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
        const selectedCats = categories.filter(function (c) {
            return state.category_ids.indexOf(c.id) !== -1;
        });
        const catNames = selectedCats.map(function (c) { return c.name; });

        // Sync global flashcard data
        const flashData = window.llToolsFlashcardsData || {};
        flashData.categories = selectedCats;
        flashData.categoriesPreselected = true;
        flashData.firstCategoryName = catNames[0] || '';
        const firstCat = selectedCats.length ? selectedCats[0] : null;
        const initialWordsRaw = (flashData.firstCategoryName && firstCat && wordsByCategory[firstCat.id])
            ? wordsByCategory[firstCat.id]
            : [];
        if ((state.star_mode || 'weighted') === 'only') {
            const starredLookup = {};
            state.starred_word_ids.forEach(function (id) { starredLookup[id] = true; });
            flashData.firstCategoryData = initialWordsRaw.filter(function (w) { return starredLookup[w.id]; });
        } else {
            flashData.firstCategoryData = initialWordsRaw;
        }
        flashData.wordset = findWordsetSlug(state.wordset_id);
        flashData.wordsetIds = state.wordset_id ? [state.wordset_id] : [];
        flashData.wordsetFallback = false;
        flashData.quiz_mode = mode || 'practice';
        flashData.starMode = state.star_mode || 'weighted';
        window.llToolsFlashcardsData = flashData;

        setStudyPrefsGlobal();

        const $popup = $root.find('#ll-tools-flashcard-popup');
        $popup.show();
        $popup.find('#ll-tools-flashcard-quiz-popup').show();
        $('body').addClass('ll-tools-flashcard-open');

        if (typeof window.initFlashcardWidget === 'function') {
            window.initFlashcardWidget(catNames, mode || 'practice');
        }
    }

    $wordsetSelect.on('change', function () {
        const newId = parseInt($(this).val(), 10) || 0;
        state.wordset_id = newId;
        state.category_ids = [];
        state.starred_word_ids = [];
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

    // Star mode toggle
    $starModeToggle.on('click', '.ll-study-btn', function () {
        const mode = $(this).data('mode') || 'weighted';
        state.star_mode = mode;
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

    $wordsWrap.on('click', '.ll-word-audio', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const url = $(this).closest('.ll-word-row').data('audio-url');
        if (!url) { return; }
        if (currentAudio) {
            currentAudio.pause();
        }
        currentAudio = new Audio(url);
        if (currentAudio && currentAudio.play) {
            currentAudio.play().catch(function () {});
        }
    });

    // Initial render
    renderWordsets();
    renderCategories();
    renderWords();
    renderStarModeToggle();
    setStudyPrefsGlobal();
})(jQuery);
