(function ($) {
    'use strict';

    const cfg = window.llIpaKeyboardAdmin || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const wordsetStorageKey = 'llTranscriptionManagerLastWordsetId';
    const tabStorageKey = 'llTranscriptionManagerLastTab';

    const $admin = $('.ll-ipa-admin').first();
    if (!$admin.length) {
        return;
    }

    const $wordset = $('#ll-ipa-wordset');
    const $status = $('#ll-ipa-admin-status');
    const $tabButtons = $('[data-ll-tab-trigger]');
    const $tabPanels = $('[data-ll-tab-panel]');

    const $symbols = $('#ll-ipa-symbols');
    const $letterMap = $('#ll-ipa-letter-map');
    const $addInput = $('#ll-ipa-add-input');
    const $addBtn = $('#ll-ipa-add-btn');
    const $addLabel = $('#ll-ipa-add-label');
    const $symbolsHeading = $('#ll-ipa-symbols-heading');
    const $symbolsDescription = $('#ll-ipa-symbols-description');
    const $letterMapHeading = $('#ll-ipa-letter-map-heading');
    const $letterMapDescription = $('#ll-ipa-letter-map-description');

    const $searchQuery = $('#ll-ipa-search-query');
    const $searchScope = $('#ll-ipa-search-scope');
    const $searchIssuesOnly = $('#ll-ipa-search-issues-only');
    const $searchReviewOnly = $('#ll-ipa-search-review-only');
    const $searchExactTranscription = $('#ll-ipa-search-exact-transcription');
    const $searchBtn = $('#ll-ipa-search-btn');
    const $searchSummary = $('#ll-ipa-search-summary');
    const $searchResults = $('#ll-ipa-search-results');
    const $searchRules = $('#ll-ipa-search-rules');
    const $orthographySummary = $('#ll-ipa-orthography-summary');
    const $orthographyRules = $('#ll-ipa-orthography-rules');
    const $orthographyIssues = $('#ll-ipa-orthography-issues');
    const $orthographyConvert = $('#ll-ipa-orthography-convert');

    let currentWordsetId = 0;
    let currentTab = 'map';
    let currentTranscription = null;
    let currentAudio = null;
    let currentAudioButton = null;
    let currentCanEdit = false;
    let currentSearchPage = 1;
    let tabDirty = {
        map: true,
        symbols: true,
        search: true,
        orthography: true
    };
    let searchRulesExpanded = false;
    let letterMapRefreshTimer = null;
    let letterMapRefreshRequestId = 0;

    function t(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(i18n, key) && typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

    function formatText(template, values) {
        let output = String(template || '');
        const list = Array.isArray(values) ? values : [];
        let nextIndex = 0;

        output = output.replace(/%(\d+)\$d/g, function (match, index) {
            const mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof list[mappedIndex] === 'undefined') {
                return '';
            }
            return String(list[mappedIndex]);
        });

        output = output.replace(/%(\d+)\$s/g, function (match, index) {
            const mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof list[mappedIndex] === 'undefined') {
                return '';
            }
            return String(list[mappedIndex]);
        });

        output = output.replace(/%1\$d/g, function () {
            if (typeof list[0] === 'undefined') {
                return '';
            }
            return String(list[0]);
        });

        output = output.replace(/%s/g, function () {
            if (typeof list[nextIndex] === 'undefined') {
                nextIndex += 1;
                return '';
            }
            const value = list[nextIndex];
            nextIndex += 1;
            return String(value);
        });

        return output;
    }

    function formatCount(count, singularKey, pluralKey, singularFallback, pluralFallback) {
        const template = count === 1 ? t(singularKey, singularFallback) : t(pluralKey, pluralFallback);
        return formatText(template, [count]);
    }

    function normalizeSearchPage(page) {
        const parsed = parseInt(page, 10) || 0;
        return parsed > 0 ? parsed : 1;
    }

    function buildDefaultTranscription() {
        return {
            mode: 'transcription',
            uses_ipa_font: false,
            special_chars_heading: ($symbolsHeading.text() || '').toString().trim(),
            special_chars_description: ($symbolsDescription.text() || '').toString().trim(),
            special_chars_add_label: ($addLabel.text() || '').toString().trim(),
            special_chars_add_placeholder: ($addInput.attr('placeholder') || '').toString(),
            special_chars_empty: t('empty', 'No special characters found for this word set.'),
            symbols_column_label: t('pronunciationLabel', 'Pronunciation'),
            map_heading: ($letterMapHeading.text() || '').toString().trim(),
            map_description: ($letterMapDescription.text() || '').toString().trim(),
            map_sample_value_label: (t('pronunciationLabel', 'Pronunciation') + ':'),
            map_add_symbols_label: t('pronunciationLabel', 'Pronunciation'),
            map_add_symbols_placeholder: ($addInput.attr('placeholder') || '').toString(),
            map_add_missing: t('mapAddMissing', 'Enter letters and characters to add.')
        };
    }

    function getTranscription() {
        if (!currentTranscription) {
            currentTranscription = buildDefaultTranscription();
        }
        return currentTranscription;
    }

    function applyTranscriptionConfig(config) {
        currentTranscription = $.extend({}, buildDefaultTranscription(), config || {});
        $admin.attr('data-ll-secondary-text-mode', currentTranscription.mode || 'transcription');
        $addLabel.text(currentTranscription.special_chars_add_label || 'Add characters');
        $addInput.attr('placeholder', currentTranscription.special_chars_add_placeholder || 'e.g. IPA symbols');
        $symbolsHeading.text(currentTranscription.special_chars_heading || 'IPA Special Characters');
        $symbolsDescription.text(currentTranscription.special_chars_description || '');
        $letterMapHeading.text(currentTranscription.map_heading || 'Letter to IPA Map');
        $letterMapDescription.text(currentTranscription.map_description || '');
    }

    function setStatus(message, isError) {
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
        if (!message) {
            $status.removeClass('is-error');
        }
    }

    function setSearchSummary(message) {
        $searchSummary.text(message || '');
    }

    function rememberWordset(wordsetId) {
        if (!window.localStorage) {
            return;
        }

        try {
            if (wordsetId) {
                window.localStorage.setItem(wordsetStorageKey, String(wordsetId));
            } else {
                window.localStorage.removeItem(wordsetStorageKey);
            }
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function getStoredWordset() {
        if (!$wordset.length || !window.localStorage) {
            return 0;
        }

        try {
            const stored = window.localStorage.getItem(wordsetStorageKey) || '';
            if (!stored) {
                return 0;
            }
            const hasOption = Array.prototype.some.call($wordset[0].options || [], function (option) {
                return option && option.value === stored;
            });
            return hasOption ? (parseInt(stored, 10) || 0) : 0;
        } catch (err) {
            return 0;
        }
    }

    function normalizeTabName(tabName) {
        const safeTab = (tabName || '').toString();
        return ['map', 'symbols', 'search', 'orthography'].indexOf(safeTab) >= 0 ? safeTab : 'map';
    }

    function getRequestedTabFromUrl() {
        if (!window.URLSearchParams) {
            return '';
        }

        const params = new window.URLSearchParams(window.location.search || '');
        if (!params.has('tab')) {
            return '';
        }

        return normalizeTabName(params.get('tab'));
    }

    function rememberTab(tabName) {
        if (!window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem(tabStorageKey, normalizeTabName(tabName));
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function getStoredTab() {
        if (!window.localStorage) {
            return '';
        }

        try {
            const stored = window.localStorage.getItem(tabStorageKey) || '';
            if (!stored) {
                return '';
            }
            return normalizeTabName(stored);
        } catch (err) {
            return '';
        }
    }

    function markTabsDirty(tabs) {
        const list = Array.isArray(tabs) ? tabs : [tabs];
        list.forEach(function (tab) {
            if (tabDirty.hasOwnProperty(tab)) {
                tabDirty[tab] = true;
            }
        });
    }

    function stopCurrentAudio() {
        if (currentAudioButton) {
            $(currentAudioButton).removeClass('is-playing');
        }
        currentAudioButton = null;

        if (!currentAudio) {
            return;
        }

        currentAudio.pause();
        currentAudio.currentTime = 0;
        currentAudio = null;
    }

    function playAudio(button) {
        const url = (button.getAttribute('data-audio-url') || '').trim();
        if (!url || button.disabled) {
            return;
        }

        if (currentAudio && currentAudioButton === button && !currentAudio.paused) {
            stopCurrentAudio();
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = button;

        currentAudio.addEventListener('play', function () {
            if (currentAudioButton === button) {
                $(button).addClass('is-playing');
            }
        });
        currentAudio.addEventListener('pause', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
        });
        currentAudio.addEventListener('ended', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });
        currentAudio.addEventListener('error', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });

        currentAudio.play().catch(function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });
    }

    function createAudioButton(rec, extraClass, options) {
        const settings = $.extend({ showDownload: false }, options || {});
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const recordingType = rec && rec.recording_type ? rec.recording_type : '';
        const recordingTypeSlug = rec && rec.recording_type_slug ? rec.recording_type_slug : '';
        const recordingIconType = rec && rec.recording_icon_type ? rec.recording_icon_type : 'isolation';
        const audioUrl = rec && rec.audio_url ? rec.audio_url : '';
        const audioLabel = rec && rec.audio_label ? rec.audio_label : t('playRecording', 'Play recording');
        const downloadLabel = t('downloadRecording', 'Download recording');
        if (!audioUrl) {
            return $('<span>', { class: 'll-ipa-search-audio-empty', text: '—' });
        }

        const $audioButton = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-ipa-recording-audio-btn ll-study-recording-btn--' + recordingIconType + (extraClass ? (' ' + extraClass) : ''),
            'data-audio-url': audioUrl,
            'data-recording-id': recordingId,
            'data-recording-type': recordingTypeSlug,
            'aria-label': audioLabel,
            title: audioLabel
        });
        $audioButton.append($('<span>', { class: 'll-study-recording-icon', 'aria-hidden': 'true' }));
        const $visualizer = $('<span>', { class: 'll-study-recording-visualizer', 'aria-hidden': 'true' });
        for (let index = 0; index < 4; index += 1) {
            $visualizer.append($('<span>', { class: 'bar' }));
        }
        $audioButton.append($visualizer);

        const $buttons = $('<div>', { class: 'll-ipa-recording-buttons' }).append($audioButton);
        if (settings.showDownload) {
            $buttons.append(
                $('<a>', {
                    class: 'll-ipa-recording-download-btn',
                    href: audioUrl,
                    download: '',
                    target: '_blank',
                    rel: 'noopener',
                    'aria-label': downloadLabel,
                    title: downloadLabel
                }).append($('<span>', { class: 'll-ipa-recording-download-icon', 'aria-hidden': 'true' }))
            );
        }

        const $wrap = $('<div>', { class: 'll-ipa-recording-cell' });
        $wrap.append($buttons);
        if (recordingType) {
            $wrap.append($('<span>', { class: 'll-ipa-recording-type-label', text: recordingType }));
        }

        return $wrap;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function setTab(tabName) {
        const nextTab = normalizeTabName(tabName);
        currentTab = nextTab;
        rememberTab(nextTab);

        $tabButtons.each(function () {
            const $button = $(this);
            const isActive = ($button.attr('data-ll-tab-trigger') || '') === nextTab;
            $button.toggleClass('is-active', isActive);
            $button.attr('aria-selected', isActive ? 'true' : 'false');
        });

        $tabPanels.each(function () {
            const $panel = $(this);
            const isActive = ($panel.attr('data-ll-tab-panel') || '') === nextTab;
            $panel.toggleClass('is-active', isActive);
            if (isActive) {
                $panel.removeAttr('hidden');
            } else {
                $panel.attr('hidden', 'hidden');
            }
        });

        loadActiveTab();
    }

    function setCanEdit(canEdit) {
        currentCanEdit = !!canEdit;
    }

    function handleWordsetResponse(response) {
        const data = response && response.data ? response.data : {};
        applyTranscriptionConfig(data.transcription || null);
        setCanEdit(!!data.can_edit);
    }

    function getSelectedWordsetId() {
        return parseInt($wordset.val(), 10) || 0;
    }

    function clearCurrentTab() {
        if (currentTab === 'map') {
            $letterMap.empty();
        } else if (currentTab === 'symbols') {
            $symbols.empty();
        } else if (currentTab === 'search') {
            $searchRules.empty();
            $searchResults.empty();
            setSearchSummary('');
        } else if (currentTab === 'orthography') {
            $orthographySummary.empty();
            $orthographyRules.empty();
            $orthographyIssues.empty();
            $orthographyConvert.empty();
        }
    }

    function loadActiveTab(force) {
        const wordsetId = currentWordsetId;
        clearCurrentTab();

        if (!wordsetId) {
            setStatus(t('noWordsets', 'No word sets are available for this page.'), true);
            return;
        }

        if (currentTab === 'map') {
            loadLetterMap(wordsetId, !!force || tabDirty.map);
            return;
        }

        if (currentTab === 'symbols') {
            loadSymbols(wordsetId, !!force || tabDirty.symbols);
            return;
        }

        if (currentTab === 'orthography') {
            loadOrthography(wordsetId, !!force || tabDirty.orthography);
            return;
        }

        loadSearch(wordsetId, !!force || tabDirty.search);
    }

    function selectWordset(wordsetId, options) {
        const settings = $.extend({ forceLoad: false }, options || {});
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        currentWordsetId = safeWordsetId;
        currentSearchPage = 1;
        if ($wordset.val() !== String(safeWordsetId)) {
            $wordset.val(String(safeWordsetId));
        }
        rememberWordset(safeWordsetId);
        markTabsDirty(['map', 'symbols', 'search', 'orthography']);
        loadActiveTab(settings.forceLoad);
    }

    function loadLetterMap(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('loading', 'Loading transcription data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_letter_map',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderLetterMap(response.data || {});
            tabDirty.map = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function loadSymbols(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('loading', 'Loading transcription data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_symbols',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderSymbols(response.data || {});
            tabDirty.symbols = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function getSearchState() {
        return {
            query: ($searchQuery.val() || '').toString(),
            scope: ($searchScope.val() || 'both').toString(),
            issuesOnly: !!$searchIssuesOnly.prop('checked'),
            reviewOnly: !!$searchReviewOnly.prop('checked'),
            exactTranscription: !!$searchExactTranscription.prop('checked')
        };
    }

    function loadSearch(wordsetId, shouldLoad, options) {
        const settings = $.extend({ quietStatus: false, showLoading: null }, options || {});
        if (!shouldLoad) {
            return;
        }

        const searchState = getSearchState();
        const requestedPage = normalizeSearchPage(settings.page || currentSearchPage);
        currentSearchPage = requestedPage;
        if (settings.showLoading === null) {
            settings.showLoading = !settings.quietStatus;
        }
        if (settings.showLoading) {
            renderSearchLoading();
        }
        if (!settings.quietStatus) {
            setStatus(t('searchLoading', 'Searching recordings...'), false);
        }
        return $.post(ajaxUrl, {
            action: 'll_tools_search_ipa_keyboard_recordings',
            nonce: nonce,
            wordset_id: wordsetId,
            query: searchState.query,
            scope: searchState.scope,
            issues_only: searchState.issuesOnly ? 1 : 0,
            review_only: searchState.reviewOnly ? 1 : 0,
            exact_transcription: searchState.exactTranscription ? 1 : 0,
            search_page: requestedPage
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            currentSearchPage = normalizeSearchPage(response.data && response.data.current_page ? response.data.current_page : requestedPage);
            renderSearch(response.data || {});
            tabDirty.search = false;
            if (!settings.quietStatus) {
                setStatus('');
            }
        }).fail(function () {
            if (settings.showLoading) {
                $searchResults.empty();
                setSearchSummary('');
            }
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function renderSearchLoading() {
        setSearchSummary('');
        $searchResults.empty().append(
            $('<div>', { class: 'll-ipa-search-loading', 'aria-live': 'polite' })
                .append($('<div>', { class: 'll-ipa-search-loading-bar', 'aria-hidden': 'true' }).append($('<span>')))
                .append($('<div>', { class: 'll-ipa-search-loading-title', text: t('searchLoading', 'Searching recordings...') }))
                .append($('<div>', { class: 'll-ipa-search-loading-hint', text: t('searchLoadingHint', 'This can take a moment for larger word sets.') }))
        );
    }

    function buildSearchSummaryText(totalMatches, pageStart, pageEnd, issuesOnly, reviewOnly) {
        if (totalMatches <= 0) {
            return '';
        }

        if (totalMatches > 1 && pageStart > 0 && pageEnd >= pageStart) {
            const rangeTemplate = issuesOnly && !reviewOnly
                ? t('searchFilteredSummaryRange', 'Showing %1$d-%2$d of %3$d flagged recordings')
                : (reviewOnly && !issuesOnly
                    ? t('searchReviewSummaryRange', 'Showing %1$d-%2$d of %3$d transcriptions needing review')
                    : t('searchSummaryRange', 'Showing %1$d-%2$d of %3$d results'));
            return formatText(rangeTemplate, [pageStart, pageEnd, totalMatches]);
        }

        return issuesOnly && !reviewOnly
            ? formatCount(totalMatches, 'searchFilteredSummary', 'searchFilteredSummaryPlural', 'Showing %1$d flagged recording', 'Showing %1$d flagged recordings')
            : (reviewOnly && !issuesOnly
                ? formatCount(totalMatches, 'searchReviewSummary', 'searchReviewSummaryPlural', 'Showing %1$d transcription needing review', 'Showing %1$d transcriptions needing review')
                : formatCount(totalMatches, 'searchSummary', 'searchSummaryPlural', '%1$d result', '%1$d results'));
    }

    function getSearchPaginationItems(currentPage, totalPages) {
        const pages = [1, totalPages, currentPage - 1, currentPage, currentPage + 1];

        if (currentPage <= 4) {
            pages.push(2, 3, 4);
        }
        if (currentPage >= (totalPages - 3)) {
            pages.push(totalPages - 1, totalPages - 2, totalPages - 3);
        }

        const numbers = pages
            .filter(function (page) {
                return page >= 1 && page <= totalPages;
            })
            .sort(function (left, right) {
                return left - right;
            })
            .filter(function (page, index, list) {
                return index === 0 || list[index - 1] !== page;
            });

        const items = [];
        numbers.forEach(function (page, index) {
            if (index > 0 && (page - numbers[index - 1]) > 1) {
                items.push('ellipsis');
            }
            items.push(page);
        });

        return items;
    }

    function buildSearchPagination(payload) {
        const totalPages = normalizeSearchPage(payload && payload.total_pages ? payload.total_pages : 1);
        const currentPage = normalizeSearchPage(payload && payload.current_page ? payload.current_page : currentSearchPage);
        if (totalPages <= 1) {
            return null;
        }

        const $nav = $('<nav>', {
            class: 'll-ipa-search-pagination',
            'aria-label': t('searchPaginationLabel', 'Search result pages')
        });
        $nav.append($('<div>', {
            class: 'll-ipa-search-pagination-status',
            text: formatText(t('searchPaginationPage', 'Page %1$d of %2$d'), [currentPage, totalPages])
        }));

        const $controls = $('<div>', { class: 'll-ipa-search-pagination-controls' });
        $controls.append($('<button>', {
            type: 'button',
            class: 'button ll-ipa-search-page-button ll-ipa-search-page-button--nav',
            text: t('searchPaginationPrevious', 'Previous'),
            disabled: currentPage <= 1,
            'data-page': currentPage > 1 ? String(currentPage - 1) : '',
            'aria-label': t('searchPaginationPrevious', 'Previous')
        }));

        getSearchPaginationItems(currentPage, totalPages).forEach(function (item) {
            if (item === 'ellipsis') {
                $controls.append($('<span>', {
                    class: 'll-ipa-search-page-gap',
                    text: '...'
                }));
                return;
            }

            if (item === currentPage) {
                $controls.append($('<span>', {
                    class: 'll-ipa-search-page-current',
                    text: String(item),
                    'aria-current': 'page',
                    'aria-label': formatText(t('searchPaginationCurrentPage', 'Current page %1$d'), [item])
                }));
                return;
            }

            $controls.append($('<button>', {
                type: 'button',
                class: 'button ll-ipa-search-page-button',
                text: String(item),
                'data-page': String(item),
                'aria-label': formatText(t('searchPaginationGoToPage', 'Go to page %1$d'), [item])
            }));
        });

        $controls.append($('<button>', {
            type: 'button',
            class: 'button ll-ipa-search-page-button ll-ipa-search-page-button--nav',
            text: t('searchPaginationNext', 'Next'),
            disabled: currentPage >= totalPages,
            'data-page': currentPage < totalPages ? String(currentPage + 1) : '',
            'aria-label': t('searchPaginationNext', 'Next')
        }));

        $nav.append($controls);
        return $nav;
    }

    function getSymbolSummaryText(recordingCount, occurrenceCount) {
        const countLabel = formatCount(
            recordingCount,
            'recordingCountSingular',
            'recordingCountPlural',
            '%1$d recording',
            '%1$d recordings'
        );
        const totalLabel = occurrenceCount
            ? (' - ' + formatCount(
                occurrenceCount,
                'occurrenceCountSingular',
                'occurrenceCountPlural',
                '%1$d occurrence',
                '%1$d occurrences'
            ))
            : '';
        return countLabel + totalLabel;
    }

    function setSymbolSummaryCounts($details, recordingCount, occurrenceCount) {
        const safeRecordingCount = Math.max(0, parseInt(recordingCount, 10) || 0);
        const safeOccurrenceCount = Math.max(0, parseInt(occurrenceCount, 10) || 0);
        let $count = $details.children('summary').find('.ll-ipa-symbol-count').first();

        if (!$count.length) {
            $count = $('<span>', { class: 'll-ipa-symbol-count' });
            $details.children('summary').append($count);
        }

        $details.attr('data-recording-count', safeRecordingCount);
        $details.attr('data-occurrence-count', safeOccurrenceCount);
        $count.text(getSymbolSummaryText(safeRecordingCount, safeOccurrenceCount));
    }

    function buildRecordingRow(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const wordText = rec && rec.word_text ? rec.word_text : '';
        const wordTranslation = rec && rec.word_translation ? rec.word_translation : '';
        const recordingText = rec && rec.recording_text ? rec.recording_text : '';
        const recordingTranslation = rec && rec.recording_translation ? rec.recording_translation : '';
        const recordingIpa = rec && rec.recording_ipa ? rec.recording_ipa : '';
        const editLink = rec && rec.word_edit_link ? rec.word_edit_link : '';

        const $wordCell = $('<td>');
        if (editLink) {
            $wordCell.append(
                $('<a>', { href: editLink, text: wordText || t('untitled', '(Untitled)'), target: '_blank' })
            );
        } else {
            $wordCell.text(wordText || t('untitled', '(Untitled)'));
        }
        if (wordTranslation) {
            $wordCell.append(
                $('<span>', { class: 'll-ipa-translation', text: ' (' + wordTranslation + ')' })
            );
        }

        const $textCell = $('<td>');
        if (recordingText) {
            $textCell.text(recordingText);
            if (recordingTranslation) {
                $textCell.append(
                    $('<span>', { class: 'll-ipa-translation', text: ' (' + recordingTranslation + ')' })
                );
            }
        } else {
            $textCell.text('-');
        }

        const $ipaInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-input',
            value: recordingIpa,
            disabled: !currentCanEdit
        });

        const $saveBtn = $('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-save',
            text: t('save', 'Save'),
            'data-recording-id': recordingId,
            disabled: !currentCanEdit
        });

        return $('<tr>', { 'data-recording-id': recordingId })
            .append($wordCell)
            .append($('<td>').append(createAudioButton(rec)))
            .append($textCell)
            .append($('<td>').append($ipaInput))
            .append($('<td>').append($saveBtn));
    }

    function buildRecordingsTable() {
        const transcription = getTranscription();
        const $table = $('<table>', { class: 'widefat striped ll-ipa-recordings' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('wordColumnLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: t('textColumnLabel', 'Text') }))
                .append($('<th>', { text: transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: '' }))
        );

        return $table.append($thead, $('<tbody>'));
    }

    function createSymbolDetails(symbol) {
        const $details = $('<details>', {
            class: 'll-ipa-symbol',
            'data-symbol': symbol,
            'data-recording-count': 0,
            'data-occurrence-count': 0
        });
        const $summary = $('<summary>');
        $summary.append($('<span>', { class: 'll-ipa-symbol-text', text: symbol }));
        $details.append($summary, $('<div>', { class: 'll-ipa-symbol-body' }));
        setSymbolSummaryCounts($details, 0, 0);
        return $details;
    }

    function buildSymbolsTableBody($details) {
        const $body = $details.children('.ll-ipa-symbol-body').first();
        let $table = $body.children('.ll-ipa-recordings').first();

        $body.children('.ll-ipa-empty').remove();
        if (!$table.length) {
            $table = buildRecordingsTable();
            $body.append($table);
        }

        return $table.children('tbody').first();
    }

    function findSymbolDetails(symbol) {
        return $symbols.children('.ll-ipa-symbol').filter(function () {
            return ($(this).attr('data-symbol') || '') === symbol;
        }).first();
    }

    function ensureSymbolEmptyState($details) {
        const $body = $details.children('.ll-ipa-symbol-body').first();
        const hasRows = $body.find('.ll-ipa-recordings tbody tr').length > 0;

        $body.children('.ll-ipa-empty').remove();
        if (hasRows) {
            return;
        }

        $body.children('.ll-ipa-recordings').remove();
        $body.append($('<div>', {
            class: 'll-ipa-empty',
            text: t('noRecordings', 'No recordings use this character yet.')
        }));
    }

    function renderSymbols(payload) {
        const list = Array.isArray(payload.symbols) ? payload.symbols : [];
        $symbols.empty();
        $addInput.prop('disabled', !currentCanEdit);
        $addBtn.prop('disabled', !currentCanEdit);

        if (!list.length) {
            $symbols.append(
                $('<div>', {
                    class: 'll-ipa-empty',
                    text: getTranscription().special_chars_empty || t('empty', 'No special characters found for this word set.')
                })
            );
            return;
        }

        list.forEach(function (entry) {
            const symbol = entry && entry.symbol ? entry.symbol : '';
            const count = entry && typeof entry.count === 'number' ? entry.count : 0;
            const recordings = entry && Array.isArray(entry.recordings) ? entry.recordings : [];
            const recordingCount = entry && typeof entry.recording_count === 'number'
                ? entry.recording_count
                : recordings.length;

            if (!symbol) {
                return;
            }

            const $details = createSymbolDetails(symbol);
            setSymbolSummaryCounts($details, recordingCount, count);

            if (recordings.length) {
                const $tbody = buildSymbolsTableBody($details);
                recordings.forEach(function (rec) {
                    $tbody.append(buildRecordingRow(rec));
                });
            } else {
                ensureSymbolEmptyState($details);
            }

            $symbols.append($details);
        });
    }

    function cancelScheduledLetterMapRefresh() {
        if (letterMapRefreshTimer) {
            window.clearTimeout(letterMapRefreshTimer);
            letterMapRefreshTimer = null;
        }
        letterMapRefreshRequestId += 1;
    }

    function refreshLetterMap(wordsetId) {
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        const requestId = ++letterMapRefreshRequestId;
        if (!safeWordsetId) {
            return;
        }

        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_letter_map',
            nonce: nonce,
            wordset_id: safeWordsetId
        }).done(function (response) {
            if (requestId !== letterMapRefreshRequestId || safeWordsetId !== currentWordsetId) {
                return;
            }
            if (!response || response.success !== true) {
                return;
            }
            handleWordsetResponse(response);
            renderLetterMap(response.data || {});
            tabDirty.map = false;
        });
    }

    function scheduleLetterMapRefresh(wordsetId) {
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        if (!safeWordsetId) {
            return;
        }

        if (letterMapRefreshTimer) {
            window.clearTimeout(letterMapRefreshTimer);
        }

        letterMapRefreshTimer = window.setTimeout(function () {
            letterMapRefreshTimer = null;
            refreshLetterMap(safeWordsetId);
        }, 700);
    }

    function renderLetterMap(payload) {
        const list = Array.isArray(payload.letter_map) ? payload.letter_map : [];
        const transcription = getTranscription();
        $letterMap.empty();

        const $add = $('<div>', { class: 'll-ipa-map-add' });
        $add.append($('<div>', { class: 'll-ipa-map-add-title', text: t('mapAddLabel', 'Add manual mapping') }));
        const $addFields = $('<div>', { class: 'll-ipa-map-add-fields' });
        const $addLetter = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-letter',
            placeholder: t('mapAddLettersPlaceholder', 'Letters (e.g. ll)'),
            'aria-label': t('mapAddLettersLabel', 'Letters'),
            disabled: !currentCanEdit
        });
        const $addSymbols = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-symbols',
            placeholder: transcription.map_add_symbols_placeholder || 'IPA symbols (e.g. r)',
            'aria-label': transcription.map_add_symbols_label || t('pronunciationLabel', 'Pronunciation'),
            disabled: !currentCanEdit
        });
        const $addMappingBtn = $('<button>', {
            type: 'button',
            class: 'll-ipa-map-button ll-ipa-map-add-btn',
            text: t('mapAdd', 'Add mapping'),
            disabled: !currentCanEdit
        });
        $addFields.append($addLetter, $addSymbols, $addMappingBtn);
        $add.append($addFields);
        $add.append($('<div>', { class: 'll-ipa-map-add-hint', text: t('mapAddHint', 'Use multiple letters to map digraphs like ll.') }));
        $letterMap.append($add);

        if (!list.length) {
            $letterMap.append(
                $('<div>', { class: 'll-ipa-empty', text: t('mapEmpty', 'No letter mappings found for this word set.') })
            );
            return;
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-letter-table' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('mapLetterLabel', 'Letter(s)') }))
                .append($('<th>', { text: t('mapAutoLabel', 'Auto map') }))
                .append($('<th>', { text: t('mapManualLabel', 'Manual override') }))
        );
        $table.append($thead);

        const $tbody = $('<tbody>');
        list.forEach(function (entry) {
            const letter = entry && entry.letter ? entry.letter : '';
            const autoList = entry && Array.isArray(entry.auto) ? entry.auto : [];
            const manualList = entry && Array.isArray(entry.manual) ? entry.manual : [];

            const $autoCell = $('<td>');
            if (autoList.length) {
                const $autoWrap = $('<div>', { class: 'll-ipa-map-auto' });
                autoList.forEach(function (autoEntry) {
                    const symbol = autoEntry && autoEntry.symbol ? autoEntry.symbol : '';
                    const count = autoEntry && typeof autoEntry.count === 'number' ? autoEntry.count : 0;
                    const samples = autoEntry && Array.isArray(autoEntry.samples) ? autoEntry.samples : [];
                    if (!symbol) {
                        return;
                    }
                    const $token = $('<span>', { class: 'll-ipa-map-token' });
                    $token.append($('<span>', { class: 'll-ipa-map-token-symbol', text: symbol }));
                    if (count) {
                        $token.append($('<span>', { class: 'll-ipa-map-token-count', text: '(' + count + ')' }));
                    }
                    const $tokenWrap = $('<div>', { class: 'll-ipa-map-token-wrap' });
                    const $tokenRow = $('<div>', { class: 'll-ipa-map-token-row' });
                    const $blockBtn = $('<button>', {
                        type: 'button',
                        class: 'll-ipa-map-block',
                        text: 'x',
                        'data-letter': letter,
                        'data-symbol': symbol,
                        'aria-label': t('mapBlockLabel', 'Block mapping'),
                        title: t('mapBlockLabel', 'Block mapping'),
                        disabled: !currentCanEdit
                    });
                    $tokenRow.append($token, $blockBtn);
                    $tokenWrap.append($tokenRow);

                    if (samples.length) {
                        const $details = $('<details>', { class: 'll-ipa-map-samples' });
                        $details.append($('<summary>', { text: t('mapSamplesLabel', 'Examples') + ' (' + samples.length + ')' }));
                        const $list = $('<div>', { class: 'll-ipa-map-samples-list' });
                        samples.forEach(function (sample) {
                            const $item = $('<div>', { class: 'll-ipa-map-sample' });
                            const $title = $('<div>', { class: 'll-ipa-map-sample-title' });
                            if (sample.word_edit_link) {
                                $title.append(
                                    $('<a>', {
                                        href: sample.word_edit_link,
                                        text: sample.word_text || t('untitled', '(Untitled)'),
                                        target: '_blank',
                                        class: 'll-ipa-map-sample-link'
                                    })
                                );
                            } else {
                                $title.text(sample.word_text || t('untitled', '(Untitled)'));
                            }
                            if (sample.word_translation) {
                                $title.append($('<span>', { class: 'll-ipa-translation', text: ' (' + sample.word_translation + ')' }));
                            }
                            $item.append($title);

                            if (sample.recording_text || sample.recording_translation) {
                                const $textRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: t('mapSampleTextLabel', 'Text:') }));
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-value', text: sample.recording_text || '-' }));
                                if (sample.recording_translation) {
                                    $textRow.append($('<span>', { class: 'll-ipa-translation', text: ' (' + sample.recording_translation + ')' }));
                                }
                                $item.append($textRow);
                            }
                            if (sample.recording_ipa) {
                                const $ipaRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: transcription.map_sample_value_label || 'IPA:' }));
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-value ll-ipa-map-sample-ipa', text: sample.recording_ipa }));
                                $item.append($ipaRow);
                            }

                            $list.append($item);
                        });
                        $details.append($list);
                        $tokenWrap.append($details);
                    }

                    $autoWrap.append($tokenWrap);
                });

                if ($autoWrap.children().length) {
                    $autoCell.append($autoWrap);
                } else {
                    $autoCell.append($('<span>', { class: 'll-ipa-map-empty', text: t('mapAutoEmpty', 'No mappings yet.') }));
                }
            } else {
                $autoCell.append($('<span>', { class: 'll-ipa-map-empty', text: t('mapAutoEmpty', 'No mappings yet.') }));
            }

            const manualValue = manualList.filter(Boolean).join(' ');
            const $input = $('<input>', {
                type: 'text',
                class: 'll-ipa-map-input',
                value: manualValue,
                placeholder: transcription.map_add_symbols_placeholder || t('mapPlaceholder', 'e.g. r'),
                disabled: !currentCanEdit
            });
            const $saveBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-save',
                text: t('save', 'Save'),
                'data-letter': letter,
                disabled: !currentCanEdit
            });
            const $clearBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-clear',
                text: t('mapClear', 'Clear'),
                'data-letter': letter,
                disabled: !currentCanEdit
            });

            const $manualCell = $('<td>');
            const $controls = $('<div>', { class: 'll-ipa-map-controls' });
            $controls.append($input, $saveBtn, $clearBtn);
            $manualCell.append($controls);

            const $row = $('<tr>', { 'data-letter': letter });
            $row.append($('<td>', { class: 'll-ipa-map-letter', text: letter }));
            $row.append($autoCell);
            $row.append($manualCell);
            $tbody.append($row);
        });

        $table.append($tbody);
        $letterMap.append($table);

        const blockedItems = [];
        list.forEach(function (entry) {
            const letter = entry && entry.letter ? entry.letter : '';
            const blockedList = entry && Array.isArray(entry.blocked) ? entry.blocked : [];
            if (!letter || !blockedList.length) {
                return;
            }
            blockedList.forEach(function (blockedEntry) {
                const symbol = blockedEntry && blockedEntry.symbol ? blockedEntry.symbol : '';
                if (!symbol) {
                    return;
                }
                blockedItems.push({
                    letter: letter,
                    symbol: symbol,
                    count: blockedEntry && typeof blockedEntry.count === 'number' ? blockedEntry.count : 0
                });
            });
        });

        if (blockedItems.length) {
            const $blockedWrap = $('<div>', { class: 'll-ipa-map-blocked' });
            $blockedWrap.append($('<div>', { class: 'll-ipa-map-blocked-title', text: t('mapBlockedTitle', 'Blocked mappings') }));
            const $blockedList = $('<div>', { class: 'll-ipa-map-blocked-list' });
            blockedItems.forEach(function (item) {
                const $row = $('<div>', { class: 'll-ipa-map-blocked-item' });
                const $label = $('<div>', { class: 'll-ipa-map-blocked-label' });
                $label.append($('<span>', { class: 'll-ipa-map-blocked-letter', text: item.letter }));
                $label.append($('<span>', { class: 'll-ipa-map-blocked-arrow', text: '->' }));
                $label.append($('<span>', { class: 'll-ipa-map-blocked-symbol', text: item.symbol }));
                if (item.count) {
                    $label.append($('<span>', { class: 'll-ipa-map-blocked-count', text: '(' + item.count + ')' }));
                }
                const $undo = $('<button>', {
                    type: 'button',
                    class: 'll-ipa-map-unblock',
                    text: t('mapUnblockLabel', 'Undo'),
                    'data-letter': item.letter,
                    'data-symbol': item.symbol,
                    disabled: !currentCanEdit
                });
                $row.append($label, $undo);
                $blockedList.append($row);
            });
            $blockedWrap.append($blockedList);
            $letterMap.append($blockedWrap);
        }
    }

    function buildSearchIssueItem(issue, ignored) {
        const $item = $('<div>', {
            class: 'll-ipa-search-issue' + (ignored ? ' is-ignored' : ''),
            'data-rule-key': issue.rule_key || ''
        });
        const $header = $('<div>', { class: 'll-ipa-search-issue-header' });
        $header.append($('<span>', { class: 'll-ipa-search-issue-title', text: issue.label || issue.message || t('searchReviewIssues', 'Review warnings') }));
        if (issue.count && parseInt(issue.count, 10) > 1) {
            $header.append($('<span>', { class: 'll-ipa-search-issue-count', text: 'x' + String(issue.count) }));
        }
        $item.append($header);
        if (issue.message) {
            $item.append($('<div>', { class: 'll-ipa-search-issue-message', text: issue.message }));
        }
        if (Array.isArray(issue.samples) && issue.samples.length) {
            $item.append($('<div>', {
                class: 'll-ipa-search-issue-samples',
                html: issue.samples.map(function (sample) {
                    return '<code>' + escapeHtml(sample) + '</code>';
                }).join(' ')
            }));
        }
        if (currentCanEdit && issue.rule_key) {
            $item.append($('<button>', {
                type: 'button',
                class: 'button-link ll-ipa-issue-toggle',
                text: ignored ? t('searchExceptionRestore', 'Undo exception') : t('searchExceptionIgnore', 'Ignore for this transcription'),
                'data-rule-key': issue.rule_key,
                'data-enabled': ignored ? '0' : '1'
            }));
        }
        return $item;
    }

    function buildSearchReviewItem(needsReview) {
        if (!needsReview) {
            return null;
        }

        const $item = $('<div>', { class: 'll-ipa-search-review' });
        const $header = $('<div>', { class: 'll-ipa-search-review-header' });
        $header.append($('<span>', {
            class: 'll-ipa-search-review-title',
            text: t('searchReviewPendingTitle', 'Needs review')
        }));
        if (currentCanEdit) {
            $header.append($('<button>', {
                type: 'button',
                class: 'button-link ll-ipa-review-confirm',
                text: t('searchReviewConfirm', 'Mark correct')
            }));
        }
        $item.append($header);
        $item.append($('<div>', {
            class: 'll-ipa-search-review-message',
            text: t('searchReviewPendingMessage', 'This transcription was generated automatically.')
        }));
        return $item;
    }

    function buildIssuesCellData(activeIssues, ignoredIssues, needsReview) {
        const active = Array.isArray(activeIssues) ? activeIssues : [];
        const ignored = Array.isArray(ignoredIssues) ? ignoredIssues : [];
        const reviewPending = !!needsReview;
        return {
            html: function () {
                const $wrap = $('<div>', { class: 'll-ipa-search-issues-wrap' });
                if (!active.length && !ignored.length && !reviewPending) {
                    $wrap.append($('<span>', { class: 'll-ipa-search-issues-empty', text: t('searchNoIssues', 'No warnings') }));
                    return $wrap;
                }
                if (reviewPending) {
                    $wrap.append(buildSearchReviewItem(true));
                }
                active.forEach(function (issue) {
                    $wrap.append(buildSearchIssueItem(issue, false));
                });
                if (ignored.length) {
                    const $ignoredSection = $('<div>', { class: 'll-ipa-search-ignored' });
                    $ignoredSection.append($('<div>', { class: 'll-ipa-search-ignored-title', text: t('searchIgnoredLabel', 'Ignored') }));
                    ignored.forEach(function (issue) {
                        $ignoredSection.append(buildSearchIssueItem(issue, true));
                    });
                    $wrap.append($ignoredSection);
                }
                return $wrap;
            }
        };
    }

    function buildCategoriesCell(categories) {
        const list = Array.isArray(categories) ? categories : [];
        if (!list.length) {
            return $('<span>', { class: 'll-ipa-search-empty', text: t('searchNoCategories', 'No categories') });
        }
        const $wrap = $('<div>', { class: 'll-ipa-search-categories' });
        list.forEach(function (category) {
            const name = category && category.name ? category.name : t('searchUnknownCategory', 'Unknown category');
            const url = category && (category.url || category.edit_url) ? (category.url || category.edit_url) : '';
            if (url) {
                $wrap.append($('<a>', {
                    class: 'll-ipa-search-category-link',
                    href: url,
                    target: '_blank',
                    text: name,
                    title: t('searchOpenCategory', 'Open category')
                }));
            } else {
                $wrap.append($('<span>', { class: 'll-ipa-search-category-link', text: name }));
            }
        });
        return $wrap;
    }

    function buildMetaCategoriesCell(categories) {
        const list = Array.isArray(categories) ? categories : [];
        if (!list.length) {
            return null;
        }

        return buildCategoriesCell(list).addClass('ll-ipa-search-meta-categories');
    }

    function buildSearchRow(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const image = rec && rec.image ? rec.image : {};
        const categories = rec && rec.categories ? rec.categories : [];
        const issues = rec && rec.issues ? rec.issues : [];
        const ignoredIssues = rec && rec.ignored_issues ? rec.ignored_issues : [];
        const needsReview = !!(rec && rec.needs_review);
        const transcription = getTranscription();
        const textValue = rec && rec.recording_text ? rec.recording_text : '';
        const ipaValue = rec && rec.recording_ipa ? rec.recording_ipa : '';

        const $metaCell = $('<td>', { class: 'll-ipa-search-meta-cell' });
        const $metaWrap = $('<div>', { class: 'll-ipa-search-meta' });
        const $wordWrap = $('<div>', { class: 'll-ipa-search-meta-word' });
        if (rec && rec.word_edit_link) {
            $wordWrap.append($('<a>', {
                class: 'll-ipa-search-word-link',
                href: rec.word_edit_link,
                target: '_blank',
                text: rec.word_text || t('untitled', '(Untitled)')
            }));
        } else {
            $wordWrap.append($('<span>', {
                class: 'll-ipa-search-word-link',
                text: rec.word_text || t('untitled', '(Untitled)')
            }));
        }
        if (rec && rec.word_translation) {
            $wordWrap.append($('<span>', { class: 'll-ipa-translation', text: rec.word_translation }));
        }

        const $mediaWrap = $('<div>', { class: 'll-ipa-search-meta-media' });
        if (image && image.url) {
            $mediaWrap.append($('<img>', {
                class: 'll-ipa-search-thumb',
                src: image.url,
                alt: image.alt || ''
            }));
        } else {
            $mediaWrap.append($('<span>', { class: 'll-ipa-search-empty', text: t('searchNoImage', 'No image') }));
        }
        $mediaWrap.append($('<div>', {
            class: 'll-ipa-search-meta-recording'
        }).append(createAudioButton(rec, 'll-ipa-search-audio-btn', { showDownload: true })));

        $metaWrap.append($wordWrap, $mediaWrap);
        const $metaCategories = buildMetaCategoriesCell(categories);
        if ($metaCategories) {
            $metaWrap.append($metaCategories);
        }
        $metaCell.append($metaWrap);

        const $textInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-search-input-field ll-ipa-search-text-input',
            value: textValue,
            'data-saved-value': textValue,
            disabled: !currentCanEdit
        });
        const $ipaInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-search-input-field ll-ipa-search-ipa-input',
            value: ipaValue,
            'data-saved-value': ipaValue,
            disabled: !currentCanEdit,
            'aria-label': transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation')
        });
        const $saveState = $('<div>', {
            class: 'll-ipa-search-save-state is-idle',
            'aria-live': 'polite'
        })
            .append($('<span>', { class: 'll-ipa-search-save-indicator', 'aria-hidden': 'true' }))
            .append($('<span>', { class: 'll-ipa-search-save-label' }));

        const $issueCell = $('<td>', { class: 'll-ipa-search-issues-cell' }).append(
            buildIssuesCellData(issues, ignoredIssues, needsReview).html()
        );

        return $('<tr>', {
            'data-recording-id': recordingId,
            'data-needs-review': needsReview ? '1' : '0'
        })
            .append($metaCell)
            .append($('<td>', { class: 'll-ipa-search-text-cell' }).append($textInput))
            .append($('<td>', { class: 'll-ipa-search-ipa-cell' }).append($ipaInput))
            .append($('<td>', { class: 'll-ipa-search-categories-cell' }).append(buildCategoriesCell(categories)))
            .append($issueCell)
            .append($('<td>', { class: 'll-ipa-search-action-cell' }).append($saveState));
    }

    function collectCustomRules() {
        const rules = [];
        $searchRules.find('.ll-ipa-rule-row').each(function () {
            const $row = $(this);
            const target = ($row.find('.ll-ipa-rule-target').val() || '').toString().trim();
            const label = ($row.find('.ll-ipa-rule-label').val() || '').toString().trim();
            const previous = ($row.find('.ll-ipa-rule-previous').val() || '').toString().trim();
            const next = ($row.find('.ll-ipa-rule-next').val() || '').toString().trim();
            const id = ($row.attr('data-rule-id') || '').toString();

            if (!target) {
                return;
            }

            rules.push({
                id: id,
                label: label,
                target: target,
                previous: previous,
                next: next
            });
        });
        return rules;
    }

    function renderValidationRules(payload) {
        const validationConfig = payload && payload.validation_config ? payload.validation_config : {};
        const builtinRules = Array.isArray(validationConfig.builtin_rules) ? validationConfig.builtin_rules : [];
        const customRules = Array.isArray(validationConfig.custom_rules) ? validationConfig.custom_rules : [];
        const supportsRules = !!validationConfig.supports_rules;

        $searchRules.empty();

        const $details = $('<details>', { class: 'll-ipa-rules-disclosure' });
        if (searchRulesExpanded) {
            $details.prop('open', true);
        }

        const $summary = $('<summary>', { class: 'll-ipa-rules-summary' });
        $summary.append($('<span>', {
            class: 'll-ipa-rules-summary-title',
            text: t('searchRulesSummary', 'IPA checks and typo rules')
        }));
        $summary.append($('<span>', {
            class: 'll-ipa-rules-summary-hint',
            text: t('searchRulesSummaryHint', 'Expand to review built-in checks and wordset-specific IPA rules.')
        }));
        $details.append($summary);

        const $panel = $('<div>', { class: 'll-ipa-rules-panel' });
        $panel.append($('<h3>', { text: t('searchRulesTitle', 'Wordset-specific IPA checks') }));
        $panel.append($('<p>', { class: 'description', text: t('searchRulesDescription', 'Add sounds that should never appear in this word set, or ban sounds in specific immediate environments.') }));

        if (!supportsRules) {
            $panel.append($('<div>', { class: 'll-ipa-empty', text: t('searchRulesUnavailable', 'Custom IPA checks are only available when this word set uses IPA transcription mode.') }));
            $details.append($panel);
            $searchRules.append($details);
            return;
        }

        const $builtinWrap = $('<div>', { class: 'll-ipa-rules-builtins' });
        $builtinWrap.append($('<div>', { class: 'll-ipa-rules-subtitle', text: t('searchBuiltinsTitle', 'Standard IPA checks') }));
        builtinRules.forEach(function (rule) {
            const $row = $('<label>', { class: 'll-ipa-builtin-rule' });
            const $checkbox = $('<input>', {
                type: 'checkbox',
                class: 'll-ipa-builtin-checkbox',
                'data-rule-code': rule.code || '',
                checked: !!rule.enabled,
                disabled: !currentCanEdit
            });
            const $copy = $('<span>', { class: 'll-ipa-builtin-copy' });
            $copy.append($('<span>', { class: 'll-ipa-builtin-title', text: rule.label || '' }));
            if (rule.description) {
                $copy.append($('<span>', { class: 'll-ipa-builtin-description', text: rule.description }));
            }
            $row.append($checkbox, $copy);
            $builtinWrap.append($row);
        });
        $panel.append($builtinWrap);

        const $customWrap = $('<div>', { class: 'll-ipa-rules-custom' });
        $customWrap.append($('<div>', { class: 'll-ipa-rules-subtitle', text: t('searchRulesTitle', 'Wordset-specific IPA checks') }));
        const $list = $('<div>', { class: 'll-ipa-rule-list' });

        function appendRuleRow(rule) {
            const $row = $('<div>', {
                class: 'll-ipa-rule-row',
                'data-rule-id': (rule && rule.id) ? rule.id : ''
            });
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-label',
                value: rule && rule.label ? rule.label : '',
                placeholder: t('searchRuleLabelPlaceholder', 'Optional note'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-target',
                value: rule && rule.target ? rule.target : '',
                placeholder: t('searchRuleTargetPlaceholder', 'e.g. t'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-previous',
                value: rule && rule.previous ? rule.previous : '',
                placeholder: t('searchRulePreviousPlaceholder', 'Previous sound(s)'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-next',
                value: rule && rule.next ? rule.next : '',
                placeholder: t('searchRuleNextPlaceholder', 'Next sound(s)'),
                disabled: !currentCanEdit
            }));
            $row.append($('<button>', {
                type: 'button',
                class: 'button button-secondary ll-ipa-rule-remove',
                text: t('searchRuleRemove', 'Remove'),
                disabled: !currentCanEdit
            }));
            $list.append($row);
        }

        if (customRules.length) {
            customRules.forEach(appendRuleRow);
        } else {
            appendRuleRow(null);
        }

        $customWrap.append($list);
        $customWrap.append($('<div>', { class: 'll-ipa-rule-hint', text: t('searchRuleHint', 'Leave Previous and Next empty to ban a sound everywhere. Separate multiple sounds with spaces.') }));

        const $actions = $('<div>', { class: 'll-ipa-rule-actions' });
        $actions.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-rule-add',
            text: t('searchRuleAdd', 'Add check'),
            disabled: !currentCanEdit
        }));
        $actions.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-rule-save',
            text: t('searchRuleSave', 'Save checks'),
            disabled: !currentCanEdit
        }));
        $customWrap.append($actions);
        $panel.append($customWrap);

        $details.append($panel);
        $searchRules.append($details);
    }

    function renderSearch(payload) {
        const results = Array.isArray(payload.results) ? payload.results : [];
        const totalMatches = parseInt(payload.total_matches, 10) || 0;
        const pageStart = parseInt(payload.page_start, 10) || 0;
        const pageEnd = parseInt(payload.page_end, 10) || 0;
        const issuesOnly = !!payload.issues_only;
        const reviewOnly = !!payload.review_only;

        renderValidationRules(payload);
        $searchResults.empty();

        if (!results.length) {
            setSearchSummary('');
            $searchResults.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('searchResultsEmpty', 'No recordings matched this search.')
            }));
            return;
        }

        setSearchSummary(buildSearchSummaryText(totalMatches, pageStart, pageEnd, issuesOnly, reviewOnly));

        const $topPagination = buildSearchPagination(payload);
        if ($topPagination) {
            $searchResults.append($topPagination);
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-search-table' });
        const $colgroup = $('<colgroup>')
            .append($('<col>', { class: 'll-ipa-search-col-meta' }))
            .append($('<col>', { class: 'll-ipa-search-col-text' }))
            .append($('<col>', { class: 'll-ipa-search-col-ipa' }))
            .append($('<col>', { class: 'll-ipa-search-col-categories' }))
            .append($('<col>', { class: 'll-ipa-search-col-checks' }))
            .append($('<col>', { class: 'll-ipa-search-col-actions' }));
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { class: 'll-ipa-search-meta-heading', text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { class: 'll-ipa-search-text-heading', text: t('textColumnLabel', 'Text') }))
                .append($('<th>', { class: 'll-ipa-search-ipa-heading', text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { class: 'll-ipa-search-categories-heading', text: t('searchCategoriesLabel', 'Categories') }))
                .append($('<th>', { class: 'll-ipa-search-checks-heading', text: t('searchIssuesLabel', 'Checks') }))
                .append($('<th>', { class: 'll-ipa-search-actions-heading', text: '' }))
        );
        const $tbody = $('<tbody>');
        results.forEach(function (rec) {
            $tbody.append(buildSearchRow(rec));
        });
        $table.append($colgroup, $thead, $tbody);
        $searchResults.append($table);

        const $bottomPagination = buildSearchPagination(payload);
        if ($bottomPagination) {
            $bottomPagination.addClass('is-bottom');
            $searchResults.append($bottomPagination);
        }
    }

    function loadOrthography(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('orthographyLoading', 'Loading orthography conversion data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_orthography',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderOrthography(response.data || {});
            tabDirty.orthography = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function buildOrthographyWordMeta(item) {
        const $wrap = $('<div>', { class: 'll-ipa-orthography-word-meta' });
        const title = item && item.word_text ? item.word_text : t('untitled', '(Untitled)');
        if (item && item.word_edit_link) {
            $wrap.append($('<a>', {
                class: 'll-ipa-orthography-word-link',
                href: item.word_edit_link,
                target: '_blank',
                text: title
            }));
        } else {
            $wrap.append($('<span>', {
                class: 'll-ipa-orthography-word-link',
                text: title
            }));
        }
        if (item && item.word_translation) {
            $wrap.append($('<span>', {
                class: 'll-ipa-translation',
                text: item.word_translation
            }));
        }
        return $wrap;
    }

    function buildOrthographyExamples(samples) {
        const list = Array.isArray(samples) ? samples : [];
        if (!list.length) {
            return null;
        }

        const $details = $('<details>', { class: 'll-ipa-orthography-examples' });
        $details.append($('<summary>', { text: t('orthographyRuleExamples', 'Examples') + ' (' + list.length + ')' }));
        const $list = $('<div>', { class: 'll-ipa-orthography-examples-list' });
        list.forEach(function (sample) {
            const $item = $('<div>', { class: 'll-ipa-orthography-example' });
            const title = sample && sample.word_text ? sample.word_text : t('untitled', '(Untitled)');
            if (sample && sample.word_edit_link) {
                $item.append($('<a>', {
                    class: 'll-ipa-orthography-example-link',
                    href: sample.word_edit_link,
                    target: '_blank',
                    text: title
                }));
            } else {
                $item.append($('<span>', {
                    class: 'll-ipa-orthography-example-link',
                    text: title
                }));
            }
            if (sample && sample.word_translation) {
                $item.append($('<span>', { class: 'll-ipa-translation', text: sample.word_translation }));
            }
            if (sample && sample.recording_text) {
                $item.append($('<div>', {
                    class: 'll-ipa-orthography-example-line',
                    text: t('orthographyIssueActual', 'Saved text') + ': ' + sample.recording_text
                }));
            }
            if (sample && sample.recording_ipa) {
                $item.append($('<div>', {
                    class: 'll-ipa-orthography-example-line ll-ipa-orthography-example-ipa',
                    text: sample.recording_ipa
                }));
            }
            $list.append($item);
        });
        $details.append($list);
        return $details;
    }

    function renderOrthographySummary(orthography) {
        const stats = orthography && orthography.stats ? orthography.stats : {};
        const $grid = $('<div>', { class: 'll-ipa-orthography-stat-grid' });
        [
            {
                label: t('orthographySummaryRules', 'Rules'),
                value: parseInt(stats.rule_count, 10) || 0
            },
            {
                label: t('orthographySummaryIssues', 'Contradictions'),
                value: parseInt(stats.active_contradiction_count, 10) || 0
            },
            {
                label: t('orthographySummaryQueue', 'Missing text'),
                value: parseInt(stats.candidate_count, 10) || 0
            }
        ].forEach(function (card) {
            const $item = $('<div>', { class: 'll-ipa-orthography-stat' });
            $item.append($('<span>', { class: 'll-ipa-orthography-stat-label', text: card.label }));
            $item.append($('<strong>', { class: 'll-ipa-orthography-stat-value', text: String(card.value) }));
            $grid.append($item);
        });

        $orthographySummary.empty().append($grid);
    }

    function buildOrthographyContextOptions(selectedValue) {
        const selected = (selectedValue || 'any').toString();
        return [
            { value: 'any', label: t('orthographyRuleAny', 'Anywhere') },
            { value: 'final', label: t('orthographyRuleFinal', 'Word-final') },
            { value: 'nonfinal', label: t('orthographyRuleNonfinal', 'Elsewhere') }
        ].map(function (item) {
            return $('<option>', {
                value: item.value,
                text: item.label,
                selected: item.value === selected
            });
        });
    }

    function renderOrthographyRules(orthography) {
        const rows = orthography && Array.isArray(orthography.rules) ? orthography.rules : [];
        $orthographyRules.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyRulesTitle', 'Detected conversion rules') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyRulesDescription', 'Review the inferred IPA-to-orthography rules, block bad guesses, and add manual overrides.')
        }));

        const $add = $('<div>', { class: 'll-ipa-orthography-add' });
        $add.append($('<div>', { class: 'll-ipa-orthography-add-title', text: t('orthographyRuleAddTitle', 'Add manual rule') }));
        const $addFields = $('<div>', { class: 'll-ipa-orthography-add-fields' });
        $addFields.append($('<input>', {
            type: 'text',
            class: 'll-ipa-orthography-add-segment',
            placeholder: t('orthographyRuleAddSegmentPlaceholder', 'e.g. ʃ or t͡ʃ'),
            'aria-label': t('orthographyRuleAddSegment', 'IPA segment'),
            disabled: !currentCanEdit
        }));
        const $context = $('<select>', {
            class: 'll-ipa-orthography-add-context',
            'aria-label': t('orthographyRuleAddContext', 'Position'),
            disabled: !currentCanEdit
        });
        buildOrthographyContextOptions('any').forEach(function ($option) {
            $context.append($option);
        });
        $addFields.append($context);
        $addFields.append($('<input>', {
            type: 'text',
            class: 'll-ipa-orthography-add-output',
            placeholder: t('orthographyRuleAddOutputPlaceholder', 'e.g. sh'),
            'aria-label': t('orthographyRuleAddOutput', 'Orthography'),
            disabled: !currentCanEdit
        }));
        $addFields.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-orthography-add-btn',
            text: t('orthographyRuleAddButton', 'Add rule'),
            disabled: !currentCanEdit
        }));
        $add.append($addFields);
        $section.append($add);

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyRulesEmpty', 'No usable IPA/text pairings were found yet for this word set.')
            }));
            $orthographyRules.append($section);
            return;
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-rules-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('orthographyRuleSegment', 'IPA segment') }))
                .append($('<th>', { text: t('orthographyRuleAuto', 'Auto rules') }))
                .append($('<th>', { text: t('orthographyRuleManual', 'Manual overrides') }))
        ));
        const $tbody = $('<tbody>');

        rows.forEach(function (row) {
            const segment = row && row.segment ? row.segment : '';
            const autoRules = row && Array.isArray(row.auto) ? row.auto : [];
            const manual = row && row.manual ? row.manual : {};
            const blocked = row && Array.isArray(row.blocked) ? row.blocked : [];
            const $tr = $('<tr>', { 'data-segment': segment });
            $tr.append($('<td>', { class: 'll-ipa-orthography-segment', text: segment }));

            const $autoCell = $('<td>');
            if (autoRules.length) {
                const $autoWrap = $('<div>', { class: 'll-ipa-orthography-auto-list' });
                autoRules.forEach(function (rule) {
                    const $rule = $('<div>', { class: 'll-ipa-orthography-auto-rule' });
                    const contextLabel = rule && rule.context && rule.context !== 'any'
                        ? (t('orthographyRule' + rule.context.charAt(0).toUpperCase() + rule.context.slice(1), '') || '')
                        : '';
                    const prefix = contextLabel ? (contextLabel + ': ') : '';
                    $rule.append($('<div>', {
                        class: 'll-ipa-orthography-auto-rule-main',
                        text: prefix + (rule && rule.output ? rule.output : '')
                    }));
                    if (rule && parseInt(rule.count, 10)) {
                        $rule.append($('<div>', {
                            class: 'll-ipa-orthography-auto-rule-count',
                            text: '(' + String(parseInt(rule.count, 10) || 0) + ')'
                        }));
                    }
                    if (currentCanEdit) {
                        $rule.append($('<button>', {
                            type: 'button',
                            class: 'button-link ll-ipa-orthography-block',
                            text: t('orthographyRuleBlock', 'Hide auto rule'),
                            'data-segment': segment,
                            'data-context': rule && rule.context ? rule.context : 'any',
                            'data-output': rule && rule.output ? rule.output : ''
                        }));
                    }
                    const $examples = buildOrthographyExamples(rule && rule.samples ? rule.samples : []);
                    if ($examples) {
                        $rule.append($examples);
                    }
                    $autoWrap.append($rule);
                });
                $autoCell.append($autoWrap);
            } else {
                $autoCell.append($('<span>', {
                    class: 'll-ipa-map-empty',
                    text: t('mapAutoEmpty', 'No mappings yet.')
                }));
            }

            if (blocked.length) {
                const $blocked = $('<div>', { class: 'll-ipa-orthography-blocked' });
                $blocked.append($('<div>', {
                    class: 'll-ipa-orthography-blocked-title',
                    text: t('orthographyRuleBlockedTitle', 'Hidden auto rules')
                }));
                blocked.forEach(function (rule) {
                    const contextLabel = rule && rule.context && rule.context !== 'any'
                        ? (rule.context === 'final' ? t('orthographyRuleFinal', 'Word-final') : t('orthographyRuleNonfinal', 'Elsewhere')) + ': '
                        : '';
                    const $item = $('<div>', { class: 'll-ipa-orthography-blocked-item' });
                    $item.append($('<span>', {
                        class: 'll-ipa-orthography-blocked-value',
                        text: contextLabel + (rule && rule.output ? rule.output : '')
                    }));
                    if (currentCanEdit) {
                        $item.append($('<button>', {
                            type: 'button',
                            class: 'button-link ll-ipa-orthography-unblock',
                            text: t('orthographyRuleUnblock', 'Restore'),
                            'data-segment': segment,
                            'data-context': rule && rule.context ? rule.context : 'any',
                            'data-output': rule && rule.output ? rule.output : ''
                        }));
                    }
                    $blocked.append($item);
                });
                $autoCell.append($blocked);
            }

            $tr.append($autoCell);

            const $manualCell = $('<td>');
            ['any', 'final', 'nonfinal'].forEach(function (contextKey) {
                const label = contextKey === 'final'
                    ? t('orthographyRuleFinal', 'Word-final')
                    : (contextKey === 'nonfinal' ? t('orthographyRuleNonfinal', 'Elsewhere') : t('orthographyRuleAny', 'Anywhere'));
                const $rowWrap = $('<div>', {
                    class: 'll-ipa-orthography-manual-row',
                    'data-context': contextKey
                });
                $rowWrap.append($('<label>', {
                    class: 'll-ipa-orthography-manual-label',
                    text: label
                }));
                $rowWrap.append($('<input>', {
                    type: 'text',
                    class: 'll-ipa-orthography-manual-input',
                    value: manual && manual[contextKey] ? manual[contextKey] : '',
                    disabled: !currentCanEdit
                }));
                $rowWrap.append($('<button>', {
                    type: 'button',
                    class: 'button button-secondary ll-ipa-orthography-manual-save',
                    text: t('orthographyRuleSave', 'Save rules'),
                    'data-segment': segment,
                    'data-context': contextKey,
                    disabled: !currentCanEdit
                }));
                $rowWrap.append($('<button>', {
                    type: 'button',
                    class: 'button-link ll-ipa-orthography-manual-clear',
                    text: t('orthographyRuleClear', 'Clear'),
                    'data-segment': segment,
                    'data-context': contextKey,
                    disabled: !currentCanEdit
                }));
                $manualCell.append($rowWrap);
            });
            $tr.append($manualCell);
            $tbody.append($tr);
        });

        $table.append($tbody);
        $section.append($table);
        $orthographyRules.append($section);
    }

    function renderOrthographyIssues(orthography) {
        const rows = orthography && Array.isArray(orthography.contradictions) ? orthography.contradictions : [];
        const stats = orthography && orthography.stats ? orthography.stats : {};
        $orthographyIssues.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyIssuesTitle', 'Contradicting words') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyIssuesDescription', 'These saved IPA/text pairings do not match the current rules. You can approve a word as an exception or keep adjusting the rules.')
        }));

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyIssuesEmpty', 'No contradictions found with the current rules.')
            }));
            $orthographyIssues.append($section);
            return;
        }

        const activeCount = parseInt(stats.active_contradiction_count, 10) || 0;
        $section.append($('<div>', {
            class: 'll-ipa-orthography-section-summary',
            text: formatCount(
                activeCount,
                'orthographyIssuesSummary',
                'orthographyIssuesSummaryPlural',
                '%1$d contradicting word',
                '%1$d contradicting words'
            )
        }));

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-issues-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: t('orthographyIssueActual', 'Saved text') }))
                .append($('<th>', { text: t('orthographyIssuePredicted', 'Predicted text') }))
                .append($('<th>', { text: '' }))
        ));
        const $tbody = $('<tbody>');
        rows.forEach(function (row) {
            const approved = !!(row && row.approved_exception);
            const $tr = $('<tr>', {
                'data-word-id': row && row.word_id ? row.word_id : 0,
                class: approved ? 'is-approved' : ''
            });
            $tr.append($('<td>').append(buildOrthographyWordMeta(row)));
            $tr.append($('<td>').append(createAudioButton(row, 'll-ipa-search-audio-btn', { showDownload: true })));
            $tr.append($('<td>', {
                class: 'll-ipa-orthography-ipa-cell',
                text: row && row.recording_ipa ? row.recording_ipa : ''
            }));
            $tr.append($('<td>', {
                text: row && row.recording_text ? row.recording_text : '—'
            }));
            $tr.append($('<td>', {
                text: row && row.predicted_text ? row.predicted_text : t('orthographyConvertCannot', 'Needs more rules')
            }));
            const $actions = $('<td>', { class: 'll-ipa-orthography-issue-actions' });
            if (approved) {
                $actions.append($('<span>', {
                    class: 'll-ipa-orthography-approved-label',
                    text: t('orthographyIssueApproved', 'Approved exception')
                }));
            }
            if (currentCanEdit) {
                $actions.append($('<button>', {
                    type: 'button',
                    class: 'button button-secondary ll-ipa-orthography-exception-toggle',
                    text: approved ? t('orthographyIssueRestore', 'Undo exception') : t('orthographyIssueApprove', 'Approve exception'),
                    'data-word-id': row && row.word_id ? row.word_id : 0,
                    'data-enabled': approved ? '0' : '1'
                }));
            }
            $tr.append($actions);
            $tbody.append($tr);
        });
        $table.append($tbody);
        $section.append($table);
        $orthographyIssues.append($section);
    }

    function renderOrthographyConvert(orthography) {
        const rows = orthography && Array.isArray(orthography.conversion_candidates) ? orthography.conversion_candidates : [];
        const stats = orthography && orthography.stats ? orthography.stats : {};
        $orthographyConvert.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyConvertTitle', 'Words missing written text') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyConvertDescription', 'Apply the current rules to words that have IPA saved but still need written text.')
        }));

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyConvertEmpty', 'No words are waiting for IPA-to-orthography conversion.')
            }));
            $orthographyConvert.append($section);
            return;
        }

        $section.append($('<div>', {
            class: 'll-ipa-orthography-section-summary',
            text: formatCount(
                parseInt(stats.candidate_count, 10) || rows.length,
                'orthographyConvertSummary',
                'orthographyConvertSummaryPlural',
                '%1$d word ready to convert',
                '%1$d words ready to convert'
            )
        }));

        const $controls = $('<div>', { class: 'll-ipa-orthography-convert-controls' });
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-orthography-select-all',
            text: t('orthographyConvertSelectAll', 'Select all'),
            disabled: !currentCanEdit
        }));
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-orthography-clear-selection',
            text: t('orthographyConvertClearSelection', 'Clear selection'),
            disabled: !currentCanEdit
        }));
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-orthography-convert-selected',
            text: t('orthographyConvertSelected', 'Convert selected'),
            disabled: !currentCanEdit
        }));
        $section.append($controls);

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-convert-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: '' }))
                .append($('<th>', { text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: t('orthographyConvertPreview', 'Predicted text') }))
                .append($('<th>', { text: t('orthographyConvertReason', 'Status') }))
                .append($('<th>', { text: '' }))
        ));
        const $tbody = $('<tbody>');
        rows.forEach(function (row) {
            const canConvert = !!(row && row.can_convert);
            const wordId = row && row.word_id ? row.word_id : 0;
            const $tr = $('<tr>', {
                'data-word-id': wordId,
                'data-can-convert': canConvert ? '1' : '0'
            });
            $tr.append($('<td>').append($('<input>', {
                type: 'checkbox',
                class: 'll-ipa-orthography-select',
                value: wordId,
                disabled: !currentCanEdit || !canConvert
            })));
            $tr.append($('<td>').append(buildOrthographyWordMeta(row)));
            $tr.append($('<td>').append(createAudioButton(row, 'll-ipa-search-audio-btn', { showDownload: true })));
            $tr.append($('<td>', {
                class: 'll-ipa-orthography-ipa-cell',
                text: row && row.recording_ipa ? row.recording_ipa : ''
            }));
            $tr.append($('<td>', {
                text: row && row.predicted_text ? row.predicted_text : '—'
            }));
            $tr.append($('<td>', {
                text: canConvert ? '' : t('orthographyConvertCannot', 'Needs more rules')
            }));
            $tr.append($('<td>').append($('<button>', {
                type: 'button',
                class: 'button button-primary ll-ipa-orthography-convert-one',
                text: t('orthographyConvertOne', 'Convert'),
                'data-word-id': wordId,
                disabled: !currentCanEdit || !canConvert
            })));
            $tbody.append($tr);
        });
        $table.append($tbody);
        $section.append($table);
        $orthographyConvert.append($section);
    }

    function renderOrthography(payload) {
        const orthography = payload && payload.orthography ? payload.orthography : {};
        renderOrthographySummary(orthography);
        if (!orthography || orthography.supported === false) {
            $orthographyRules.empty().append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyUnsupported', 'IPA-to-orthography conversion is only available when this word set uses IPA transcription mode.')
            }));
            $orthographyIssues.empty();
            $orthographyConvert.empty();
            return;
        }

        renderOrthographyRules(orthography);
        renderOrthographyIssues(orthography);
        renderOrthographyConvert(orthography);
    }

    function syncSavedRecording(data) {
        const recording = data && data.recording ? data.recording : {};
        const recordingId = parseInt(recording.recording_id || data.recording_id, 10) || 0;
        const previousCounts = data && data.previous_symbol_counts ? data.previous_symbol_counts : {};
        const nextCounts = data && data.symbol_counts ? data.symbol_counts : {};

        if (!recordingId) {
            return;
        }

        if (currentAudioButton && (parseInt($(currentAudioButton).attr('data-recording-id'), 10) || 0) === recordingId) {
            stopCurrentAudio();
        }

        Object.keys($.extend({}, previousCounts, nextCounts)).forEach(function (symbol) {
            const previousCount = parseInt(previousCounts[symbol], 10) || 0;
            const nextCount = parseInt(nextCounts[symbol], 10) || 0;
            let $details = findSymbolDetails(symbol);
            if (nextCount > 0 && !$details.length) {
                $details = createSymbolDetails(symbol);
                $symbols.append($details);
            }
            if (!$details.length) {
                return;
            }

            if (nextCount > 0) {
                const $tbody = buildSymbolsTableBody($details);
                const $existing = $tbody.children('tr').filter(function () {
                    return (parseInt($(this).attr('data-recording-id'), 10) || 0) === recordingId;
                }).first();
                const $row = buildRecordingRow(recording);
                if ($existing.length) {
                    $existing.replaceWith($row);
                } else {
                    $tbody.append($row);
                }
            } else {
                $details.find('tr[data-recording-id="' + recordingId + '"]').remove();
                ensureSymbolEmptyState($details);
            }

            const currentRecordingCount = parseInt($details.attr('data-recording-count'), 10) || 0;
            const currentOccurrenceCount = parseInt($details.attr('data-occurrence-count'), 10) || 0;
            const recordingDelta = (nextCount > 0 ? 1 : 0) - (previousCount > 0 ? 1 : 0);
            const occurrenceDelta = nextCount - previousCount;
            setSymbolSummaryCounts($details, currentRecordingCount + recordingDelta, currentOccurrenceCount + occurrenceDelta);
        });
    }

    function updateSearchRowValidation($row, validation) {
        const active = validation && Array.isArray(validation.active) ? validation.active : [];
        const ignored = validation && Array.isArray(validation.ignored) ? validation.ignored : [];
        const needsReview = ($row.attr('data-needs-review') || '0') === '1';
        const $cell = $row.find('.ll-ipa-search-issues-cell').first();
        if ($cell.length) {
            $cell.empty().append(buildIssuesCellData(active, ignored, needsReview).html());
        }
    }

    function replaceSearchRow($row, rec) {
        const $newRow = buildSearchRow(rec);
        $row.replaceWith($newRow);
        return $newRow;
    }

    function getSearchRowValues($row) {
        const $textInput = $row.find('.ll-ipa-search-text-input').first();
        const $ipaInput = $row.find('.ll-ipa-search-ipa-input').first();
        return {
            recordingId: parseInt($row.attr('data-recording-id'), 10) || 0,
            recordingText: ($textInput.val() || '').toString(),
            recordingIpa: ($ipaInput.val() || '').toString(),
            savedText: ($textInput.attr('data-saved-value') || '').toString(),
            savedIpa: ($ipaInput.attr('data-saved-value') || '').toString()
        };
    }

    function searchRowHasUnsavedChanges($row) {
        const values = getSearchRowValues($row);
        return values.recordingText !== values.savedText || values.recordingIpa !== values.savedIpa;
    }

    function updateSearchRowSavedValues($row) {
        $row.find('.ll-ipa-search-text-input').first().attr('data-saved-value', ($row.find('.ll-ipa-search-text-input').first().val() || '').toString());
        $row.find('.ll-ipa-search-ipa-input').first().attr('data-saved-value', ($row.find('.ll-ipa-search-ipa-input').first().val() || '').toString());
    }

    function setSearchRowInputsDisabled($row, disabled) {
        $row.find('.ll-ipa-search-text-input, .ll-ipa-search-ipa-input').prop('disabled', !!disabled || !currentCanEdit);
    }

    function setSearchRowSaveState($row, state, label) {
        const $state = $row.find('.ll-ipa-search-save-state').first();
        if (!$state.length) {
            return;
        }

        $state.removeClass('is-idle is-saving is-saved is-error');
        $state.addClass('is-' + state);
        $state.find('.ll-ipa-search-save-label').text(label || '');
    }

    function reloadCurrentSearchPage(options) {
        if (!currentWordsetId) {
            return;
        }

        markTabsDirty('search');
        loadSearch(currentWordsetId, true, $.extend({
            quietStatus: true,
            showLoading: false,
            page: currentSearchPage
        }, options || {}));
    }

    function autosaveSearchRow($row) {
        if (!$row.length || !currentCanEdit) {
            return;
        }

        if ($row.data('llSearchRowSaving')) {
            $row.data('llSearchRowPending', true);
            return;
        }

        if (!searchRowHasUnsavedChanges($row)) {
            return;
        }

        const values = getSearchRowValues($row);
        if (!values.recordingId) {
            return;
        }

        $row.data('llSearchRowSaving', true);
        $row.data('llSearchRowPending', false);
        setSearchRowInputsDisabled($row, true);
        setSearchRowSaveState($row, 'saving', t('saving', 'Saving...'));

        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_recording',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: values.recordingId,
            recording_text: values.recordingText,
            recording_ipa: values.recordingIpa
        }).done(function (response) {
            if (!response || response.success !== true) {
                setSearchRowSaveState($row, 'error', t('searchSaveFailed', 'Save failed'));
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            const data = response.data || {};
            markTabsDirty(['map', 'symbols', 'orthography']);
            if (data.recording) {
                const $newRow = replaceSearchRow($row, data.recording);
                setSearchRowSaveState($newRow, 'saved', t('saved', 'Saved.'));
            } else {
                updateSearchRowSavedValues($row);
                updateSearchRowValidation($row, data.validation || null);
                setSearchRowSaveState($row, 'saved', t('saved', 'Saved.'));
                setSearchRowInputsDisabled($row, false);
            }

            setStatus(t('saved', 'Saved.'), false);
            if (getSearchState().query.toString().trim() || getSearchState().issuesOnly || getSearchState().reviewOnly) {
                reloadCurrentSearchPage();
            }
        }).fail(function () {
            setSearchRowSaveState($row, 'error', t('searchSaveFailed', 'Save failed'));
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $row.data('llSearchRowSaving', false);
            if ($row.closest('html').length) {
                setSearchRowInputsDisabled($row, false);
                if ($row.data('llSearchRowPending')) {
                    $row.data('llSearchRowPending', false);
                    autosaveSearchRow($row);
                }
            }
        });
    }

    function collectDisabledBuiltinRules() {
        const disabled = [];
        $searchRules.find('.ll-ipa-builtin-checkbox').each(function () {
            const $checkbox = $(this);
            if (!$checkbox.prop('checked')) {
                const ruleCode = ($checkbox.attr('data-rule-code') || '').toString();
                if (ruleCode) {
                    disabled.push(ruleCode);
                }
            }
        });
        return disabled;
    }

    $tabButtons.on('click', function () {
        const tab = ($(this).attr('data-ll-tab-trigger') || '').toString();
        setTab(tab);
    });

    $wordset.on('change', function () {
        const wordsetId = getSelectedWordsetId();
        selectWordset(wordsetId, { forceLoad: true });
    });

    $addBtn.on('click', function () {
        if (!currentWordsetId) {
            setStatus(t('selectWordset', 'Select a word set first.'), true);
            return;
        }
        const symbols = ($addInput.val() || '').toString();
        if (!symbols.trim()) {
            setStatus(t('enterSymbols', 'Enter one or more characters to add.'), true);
            return;
        }
        $addBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_add_wordset_ipa_symbols',
            nonce: nonce,
            wordset_id: currentWordsetId,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            $addInput.val('');
            setStatus(t('addSuccess', 'Characters added.'), false);
            markTabsDirty('symbols');
            loadSymbols(currentWordsetId, true);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $addBtn.prop('disabled', !currentCanEdit);
        });
    });

    $symbols.on('click', '.ll-ipa-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($btn.attr('data-recording-id'), 10) || 0;
        const ipaValue = ($row.find('.ll-ipa-input').first().val() || '').toString();
        const originalText = $btn.text();

        $btn.prop('disabled', true).text(t('saving', 'Saving...'));
        $.post(ajaxUrl, {
            action: 'll_tools_update_recording_ipa',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            recording_ipa: ipaValue
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            syncSavedRecording(response.data || {});
            markTabsDirty(['search', 'orthography']);
            if (response.data && response.data.letter_map_refresh_required) {
                scheduleLetterMapRefresh(currentWordsetId);
            }
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit).text(originalText);
        });
    });

    $admin.on('click', '.ll-study-recording-btn', function (event) {
        event.preventDefault();
        event.stopPropagation();
        playAudio(event.currentTarget);
    });

    $letterMap.on('click', '.ll-ipa-map-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.attr('data-letter') || '').toString();
        const symbols = ($row.find('.ll-ipa-map-input').first().val() || '').toString();
        const $clearBtn = $row.find('.ll-ipa-map-clear').first();

        $btn.prop('disabled', true);
        $clearBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
            $clearBtn.prop('disabled', !currentCanEdit);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-clear', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.attr('data-letter') || '').toString();
        const $saveBtn = $row.find('.ll-ipa-map-save').first();

        $btn.prop('disabled', true);
        $saveBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            clear: 1
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
            $saveBtn.prop('disabled', !currentCanEdit);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-add-btn', function () {
        const $wrap = $(this).closest('.ll-ipa-map-add');
        const letter = ($wrap.find('.ll-ipa-map-add-letter').first().val() || '').toString();
        const symbols = ($wrap.find('.ll-ipa-map-add-symbols').first().val() || '').toString();

        if (!letter.trim() || !symbols.trim()) {
            setStatus(getTranscription().map_add_missing || t('mapAddMissing', 'Enter letters and characters to add.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-block, .ll-ipa-map-unblock', function () {
        const $btn = $(this);
        const isUndo = $btn.hasClass('ll-ipa-map-unblock');
        const letter = ($btn.attr('data-letter') || '').toString();
        const symbol = ($btn.attr('data-symbol') || '').toString();
        const action = isUndo ? 'll_tools_unblock_wordset_ipa_letter_mapping' : 'll_tools_block_wordset_ipa_letter_mapping';

        $btn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: action,
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbol: symbol
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
        });
    });

    $searchBtn.on('click', function () {
        currentSearchPage = 1;
        markTabsDirty('search');
        loadSearch(currentWordsetId, true, { page: 1 });
    });

    function handleOrthographyRefreshResponse(response, successMessage) {
        if (!response || response.success !== true) {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
            return false;
        }

        handleWordsetResponse(response);
        renderOrthography(response.data || {});
        tabDirty.orthography = false;
        if (successMessage) {
            setStatus(successMessage, false);
        } else {
            setStatus('');
        }
        return true;
    }

    $orthographyRules.on('click', '.ll-ipa-orthography-add-btn', function () {
        const $wrap = $(this).closest('.ll-ipa-orthography-add');
        const segment = ($wrap.find('.ll-ipa-orthography-add-segment').first().val() || '').toString().trim();
        const context = ($wrap.find('.ll-ipa-orthography-add-context').first().val() || 'any').toString();
        const output = ($wrap.find('.ll-ipa-orthography-add-output').first().val() || '').toString().trim();

        if (!segment || !output) {
            setStatus(t('orthographyRuleAddMissing', 'Enter both an IPA segment and an orthography output.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: segment,
            context: context,
            output: output
        }).done(function (response) {
            if (handleOrthographyRefreshResponse(response, t('saved', 'Saved.'))) {
                $wrap.find('.ll-ipa-orthography-add-segment').first().val('');
                $wrap.find('.ll-ipa-orthography-add-output').first().val('');
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-manual-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('.ll-ipa-orthography-manual-row');
        const segment = ($btn.attr('data-segment') || '').toString();
        const context = ($btn.attr('data-context') || 'any').toString();
        const output = ($row.find('.ll-ipa-orthography-manual-input').first().val() || '').toString();

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: segment,
            context: context,
            output: output
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-manual-clear', function () {
        const $btn = $(this);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: ($btn.attr('data-segment') || '').toString(),
            context: ($btn.attr('data-context') || 'any').toString(),
            clear: 1
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-block, .ll-ipa-orthography-unblock', function () {
        const $btn = $(this);
        const isUnblock = $btn.hasClass('ll-ipa-orthography-unblock');
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: isUnblock ? 'll_tools_unblock_ipa_keyboard_orthography_rule' : 'll_tools_block_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: ($btn.attr('data-segment') || '').toString(),
            context: ($btn.attr('data-context') || 'any').toString(),
            output: ($btn.attr('data-output') || '').toString()
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyIssues.on('click', '.ll-ipa-orthography-exception-toggle', function () {
        const $btn = $(this);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_toggle_ipa_keyboard_orthography_exception',
            nonce: nonce,
            wordset_id: currentWordsetId,
            word_id: ($btn.attr('data-word-id') || '').toString(),
            enabled: ($btn.attr('data-enabled') || '1') === '1' ? 1 : 0
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-select-all', function () {
        $orthographyConvert.find('.ll-ipa-orthography-select').prop('checked', true);
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-clear-selection', function () {
        $orthographyConvert.find('.ll-ipa-orthography-select').prop('checked', false);
    });

    function submitOrthographyConversion(wordIds) {
        const ids = Array.isArray(wordIds) ? wordIds : [];
        if (!ids.length) {
            setStatus(t('orthographyConvertNoSelection', 'Select at least one word to convert.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_convert_ipa_keyboard_orthography_words',
            nonce: nonce,
            wordset_id: currentWordsetId,
            word_ids: ids
        }).done(function (response) {
            if (!handleOrthographyRefreshResponse(response, t('orthographyConvertSaved', 'Conversion saved.'))) {
                return;
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    $orthographyConvert.on('click', '.ll-ipa-orthography-convert-selected', function () {
        const ids = $orthographyConvert.find('.ll-ipa-orthography-select:checked').map(function () {
            return parseInt($(this).val(), 10) || 0;
        }).get().filter(Boolean);
        submitOrthographyConversion(ids);
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-convert-one', function () {
        const wordId = parseInt($(this).attr('data-word-id'), 10) || 0;
        if (!wordId) {
            return;
        }
        submitOrthographyConversion([wordId]);
    });

    $searchQuery.on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $searchBtn.trigger('click');
        }
    });

    $searchResults.on('click', '.ll-ipa-search-page-button', function () {
        const $btn = $(this);
        const targetPage = normalizeSearchPage($btn.attr('data-page') || 0);
        if ($btn.prop('disabled') || !targetPage || targetPage === currentSearchPage) {
            return;
        }

        markTabsDirty('search');
        loadSearch(currentWordsetId, true, { page: targetPage });
    });

    $searchResults.on('input', '.ll-ipa-search-text-input, .ll-ipa-search-ipa-input', function () {
        const $row = $(this).closest('tr');
        if (!$row.length || $row.data('llSearchRowSaving')) {
            return;
        }

        if (searchRowHasUnsavedChanges($row)) {
            setSearchRowSaveState($row, 'idle', '');
        }
    });

    $searchResults.on('focusout', '.ll-ipa-search-text-input, .ll-ipa-search-ipa-input', function () {
        const $row = $(this).closest('tr');
        if (!$row.length) {
            return;
        }

        window.setTimeout(function () {
            const activeElement = document.activeElement;
            if (activeElement && $row.has(activeElement).length) {
                return;
            }
            autosaveSearchRow($row);
        }, 0);
    });

    $searchResults.on('click', '.ll-ipa-review-confirm', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;

        if (!recordingId || !currentWordsetId) {
            return;
        }

        if ($row.data('llSearchRowSaving') || searchRowHasUnsavedChanges($row)) {
            autosaveSearchRow($row);
            return;
        }

        $btn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_confirm_ipa_keyboard_transcription_review',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            const data = response.data || {};
            if (data.recording) {
                const $newRow = replaceSearchRow($row, data.recording);
                setSearchRowSaveState($newRow, 'saved', t('searchReviewed', 'Reviewed.'));
            }
            setStatus(t('searchReviewed', 'Reviewed.'), false);
            if (getSearchState().reviewOnly || getSearchState().query.toString().trim()) {
                reloadCurrentSearchPage();
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $searchResults.on('click', '.ll-ipa-issue-toggle', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const ruleKey = ($btn.attr('data-rule-key') || '').toString();
        const enabled = ($btn.attr('data-enabled') || '1') === '1';

        $btn.prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'll_tools_toggle_ipa_keyboard_validation_exception',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            rule_key: ruleKey,
            enabled: enabled ? 1 : 0
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            updateSearchRowValidation($row, response.data ? response.data.validation : null);
            setStatus(t('saved', 'Saved.'), false);
            if (getSearchState().issuesOnly) {
                reloadCurrentSearchPage();
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $searchRules.on('click', '.ll-ipa-rule-add', function () {
        const $list = $searchRules.find('.ll-ipa-rule-list').first();
        const $row = $('<div>', { class: 'll-ipa-rule-row', 'data-rule-id': '' });
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-label',
            placeholder: t('searchRuleLabelPlaceholder', 'Optional note')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-target',
            placeholder: t('searchRuleTargetPlaceholder', 'e.g. t')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-previous',
            placeholder: t('searchRulePreviousPlaceholder', 'Previous sound(s)')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-next',
            placeholder: t('searchRuleNextPlaceholder', 'Next sound(s)')
        }));
        $row.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-rule-remove',
            text: t('searchRuleRemove', 'Remove')
        }));
        $list.append($row);
    });

    $searchRules.on('click', '.ll-ipa-rule-remove', function () {
        const $row = $(this).closest('.ll-ipa-rule-row');
        const $list = $searchRules.find('.ll-ipa-rule-list').first();
        $row.remove();
        if (!$list.children().length) {
            $searchRules.find('.ll-ipa-rule-add').trigger('click');
        }
    });

    $searchRules.on('click', '.ll-ipa-rule-save', function () {
        const customRules = collectCustomRules();
        const hasInvalidRule = $searchRules.find('.ll-ipa-rule-row').toArray().some(function (row) {
            const $row = $(row);
            const anyValue = ($row.find('.ll-ipa-rule-label').val() || '').toString().trim()
                || ($row.find('.ll-ipa-rule-previous').val() || '').toString().trim()
                || ($row.find('.ll-ipa-rule-next').val() || '').toString().trim();
            const target = ($row.find('.ll-ipa-rule-target').val() || '').toString().trim();
            return anyValue && !target;
        });

        if (hasInvalidRule) {
            setStatus(t('searchRuleMissingTarget', 'Enter a sound to check.'), true);
            return;
        }

        const disabledBuiltinRules = collectDisabledBuiltinRules();
        const $btn = $(this);
        $btn.prop('disabled', true);
        setStatus(t('searchRuleRescanning', 'Saving checks and rescanning this word set...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_save_ipa_keyboard_validation_config',
            nonce: nonce,
            wordset_id: currentWordsetId,
            disabled_builtin_rules: disabledBuiltinRules,
            custom_rules: customRules
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty(['map', 'symbols', 'search']);
            loadSearch(currentWordsetId, true);
            setStatus(t('searchRuleSaved', 'Checks saved and rescanned.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
        });
    });

    $searchRules.on('toggle', '.ll-ipa-rules-disclosure', function () {
        searchRulesExpanded = !!this.open;
    });

    function init() {
        if (!$wordset.length || !$wordset.find('option').length) {
            setStatus(t('noWordsets', 'No word sets are available for this page.'), true);
            return;
        }

        const requestedTab = getRequestedTabFromUrl();
        const storedTab = getStoredTab();
        currentTab = requestedTab || storedTab || normalizeTabName(cfg.initialTab || $admin.attr('data-ll-initial-tab') || 'map');

        const serverWordsetId = parseInt(cfg.selectedWordsetId, 10) || getSelectedWordsetId();
        const storedWordsetId = getStoredWordset();
        const initialWordsetId = serverWordsetId || storedWordsetId;
        const initialSearch = cfg.initialSearch || {};

        if (typeof initialSearch.query === 'string') {
            $searchQuery.val(initialSearch.query);
        }
        if (typeof initialSearch.scope === 'string' && initialSearch.scope) {
            $searchScope.val(initialSearch.scope);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'issues_only')) {
            $searchIssuesOnly.prop('checked', !!initialSearch.issues_only);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'review_only')) {
            $searchReviewOnly.prop('checked', !!initialSearch.review_only);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'exact_transcription')) {
            $searchExactTranscription.prop('checked', !!initialSearch.exact_transcription);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'page')) {
            currentSearchPage = normalizeSearchPage(initialSearch.page);
        }

        if (initialWordsetId) {
            $wordset.val(String(initialWordsetId));
        }
        currentWordsetId = getSelectedWordsetId();
        rememberWordset(currentWordsetId);
        setTab(currentTab);
    }

    init();
})(jQuery);
