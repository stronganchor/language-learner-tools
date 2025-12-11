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

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; }).filter(function (v) { return v > 0; });
    }

    function findWordsetSlug(id) {
        const ws = wordsets.find(function (w) { return parseInt(w.id, 10) === parseInt(id, 10); });
        return ws ? ws.slug : '';
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
            const row = $('<label>', { class: 'll-cat-row' });
            $('<input>', { type: 'checkbox', value: cat.id, checked: checked }).appendTo(row);
            $('<span>', { class: 'll-cat-name', text: label + countLabel }).appendTo(row);
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
            $('<div>', { class: 'll-word-group__title', text: catLabel }).appendTo(group);

            if (!words.length) {
                $('<p>', { class: 'll-word-empty', text: i18n.noWords || 'No words yet.' }).appendTo(group);
            } else {
                const list = $('<div>', { class: 'll-word-list' });
                words.forEach(function (w) {
                    const isStarred = !!starredLookup[w.id];
                    if (isStarred) { totalStarredInView++; }
                    const row = $('<div>', { class: 'll-word-row', 'data-word-id': w.id });
                    $('<button>', {
                        type: 'button',
                        class: 'll-word-star' + (isStarred ? ' active' : ''),
                        'aria-pressed': isStarred ? 'true' : 'false',
                        text: isStarred ? '★' : '☆'
                    }).appendTo(row);
                    $('<span>', { class: 'll-word-text', text: w.label || w.title }).appendTo(row);
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

    // Initial render
    renderWordsets();
    renderCategories();
    renderWords();
    renderStarModeToggle();
    setStudyPrefsGlobal();
})(jQuery);
