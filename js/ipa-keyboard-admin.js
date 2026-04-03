(function ($) {
    'use strict';

    const cfg = window.llIpaKeyboardAdmin || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const storageKey = 'llTranscriptionManagerLastWordsetId';

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
    const $searchBtn = $('#ll-ipa-search-btn');
    const $searchSummary = $('#ll-ipa-search-summary');
    const $searchResults = $('#ll-ipa-search-results');
    const $searchRules = $('#ll-ipa-search-rules');

    let currentWordsetId = 0;
    let currentTab = (cfg.initialTab || $admin.attr('data-ll-initial-tab') || 'map').toString();
    let currentTranscription = null;
    let currentAudio = null;
    let currentAudioButton = null;
    let currentCanEdit = false;
    let tabDirty = {
        map: true,
        symbols: true,
        search: true
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
                window.localStorage.setItem(storageKey, String(wordsetId));
            } else {
                window.localStorage.removeItem(storageKey);
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
            const stored = window.localStorage.getItem(storageKey) || '';
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

    function createAudioButton(rec, extraClass) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const recordingType = rec && rec.recording_type ? rec.recording_type : '';
        const recordingTypeSlug = rec && rec.recording_type_slug ? rec.recording_type_slug : '';
        const recordingIconType = rec && rec.recording_icon_type ? rec.recording_icon_type : 'isolation';
        const audioUrl = rec && rec.audio_url ? rec.audio_url : '';
        const audioLabel = rec && rec.audio_label ? rec.audio_label : t('playRecording', 'Play recording');
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

        const $wrap = $('<div>', { class: 'll-ipa-recording-cell' });
        $wrap.append($audioButton);
        if (recordingType) {
            $wrap.append($('<span>', { class: 'll-ipa-recording-type-label', text: recordingType }));
        }

        return $wrap;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function setTab(tabName) {
        const nextTab = ['map', 'symbols', 'search'].indexOf(tabName) >= 0 ? tabName : 'map';
        currentTab = nextTab;

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

        loadSearch(wordsetId, !!force || tabDirty.search);
    }

    function selectWordset(wordsetId, options) {
        const settings = $.extend({ forceLoad: false }, options || {});
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        currentWordsetId = safeWordsetId;
        if ($wordset.val() !== String(safeWordsetId)) {
            $wordset.val(String(safeWordsetId));
        }
        rememberWordset(safeWordsetId);
        markTabsDirty(['map', 'symbols', 'search']);
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
            issuesOnly: !!$searchIssuesOnly.prop('checked')
        };
    }

    function loadSearch(wordsetId, shouldLoad, options) {
        const settings = $.extend({ quietStatus: false, showLoading: null }, options || {});
        if (!shouldLoad) {
            return;
        }

        const searchState = getSearchState();
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
            issues_only: searchState.issuesOnly ? 1 : 0
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
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

    function buildIssuesCellData(activeIssues, ignoredIssues) {
        const active = Array.isArray(activeIssues) ? activeIssues : [];
        const ignored = Array.isArray(ignoredIssues) ? ignoredIssues : [];
        return {
            html: function () {
                const $wrap = $('<div>', { class: 'll-ipa-search-issues-wrap' });
                if (!active.length && !ignored.length) {
                    $wrap.append($('<span>', { class: 'll-ipa-search-issues-empty', text: t('searchNoIssues', 'No warnings') }));
                    return $wrap;
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
            const url = category && category.edit_url ? category.edit_url : '';
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

    function buildSearchRow(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const image = rec && rec.image ? rec.image : {};
        const categories = rec && rec.categories ? rec.categories : [];
        const issues = rec && rec.issues ? rec.issues : [];
        const ignoredIssues = rec && rec.ignored_issues ? rec.ignored_issues : [];
        const transcription = getTranscription();

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
        }).append(createAudioButton(rec, 'll-ipa-search-audio-btn')));

        $metaWrap.append($wordWrap, $mediaWrap);
        $metaCell.append($metaWrap);

        const $textInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-search-input-field ll-ipa-search-text-input',
            value: rec && rec.recording_text ? rec.recording_text : '',
            disabled: !currentCanEdit
        });
        const $ipaInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-search-input-field ll-ipa-search-ipa-input',
            value: rec && rec.recording_ipa ? rec.recording_ipa : '',
            disabled: !currentCanEdit,
            'aria-label': transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation')
        });
        const $saveBtn = $('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-search-save',
            text: t('save', 'Save'),
            'data-recording-id': recordingId,
            disabled: !currentCanEdit
        });

        const $issueCell = $('<td>', { class: 'll-ipa-search-issues-cell' }).append(
            buildIssuesCellData(issues, ignoredIssues).html()
        );

        return $('<tr>', { 'data-recording-id': recordingId })
            .append($metaCell)
            .append($('<td>', { class: 'll-ipa-search-text-cell' }).append($textInput))
            .append($('<td>', { class: 'll-ipa-search-ipa-cell' }).append($ipaInput))
            .append($('<td>', { class: 'll-ipa-search-categories-cell' }).append(buildCategoriesCell(categories)))
            .append($issueCell)
            .append($('<td>', { class: 'll-ipa-search-action-cell' }).append($saveBtn));
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
        const shownCount = parseInt(payload.shown_count, 10) || 0;
        const hasMore = !!payload.has_more;
        const issuesOnly = !!payload.issues_only;

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

        const summary = issuesOnly
            ? formatCount(totalMatches, 'searchFilteredSummary', 'searchFilteredSummaryPlural', 'Showing %1$d flagged recording', 'Showing %1$d flagged recordings')
            : formatCount(totalMatches, 'searchSummary', 'searchSummaryPlural', '%1$d result', '%1$d results');
        setSearchSummary(summary + (hasMore ? ' ' + formatText(t('searchTooMany', 'Showing the first %1$d results. Narrow the search to see more.'), [shownCount]) : ''));

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
                .append($('<th>', { text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { text: t('textColumnLabel', 'Text') }))
                .append($('<th>', { text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: t('searchCategoriesLabel', 'Categories') }))
                .append($('<th>', { text: t('searchIssuesLabel', 'Checks') }))
                .append($('<th>', { text: '' }))
        );
        const $tbody = $('<tbody>');
        results.forEach(function (rec) {
            $tbody.append(buildSearchRow(rec));
        });
        $table.append($colgroup, $thead, $tbody);
        $searchResults.append($table);
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
        const $cell = $row.find('.ll-ipa-search-issues-cell').first();
        if ($cell.length) {
            $cell.empty().append(buildIssuesCellData(active, ignored).html());
        }
    }

    function replaceSearchRow($row, rec) {
        const $newRow = buildSearchRow(rec);
        $row.replaceWith($newRow);
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
            markTabsDirty('search');
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
        markTabsDirty('search');
        loadSearch(currentWordsetId, true);
    });

    $searchQuery.on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $searchBtn.trigger('click');
        }
    });

    $searchResults.on('click', '.ll-ipa-search-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($btn.attr('data-recording-id'), 10) || 0;
        const recordingText = ($row.find('.ll-ipa-search-text-input').first().val() || '').toString();
        const recordingIpa = ($row.find('.ll-ipa-search-ipa-input').first().val() || '').toString();
        const originalText = $btn.text();

        $btn.prop('disabled', true).text(t('saving', 'Saving...'));
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_recording',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            recording_text: recordingText,
            recording_ipa: recordingIpa
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty(['map', 'symbols']);
            loadSearch(currentWordsetId, true, { quietStatus: true });
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit).text(originalText);
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
            if (getSearchState().issuesOnly) {
                loadSearch(currentWordsetId, true, { quietStatus: true });
            } else {
                updateSearchRowValidation($row, response.data ? response.data.validation : null);
            }
            setStatus(t('saved', 'Saved.'), false);
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

        if (initialWordsetId) {
            $wordset.val(String(initialWordsetId));
        }
        currentWordsetId = getSelectedWordsetId();
        rememberWordset(currentWordsetId);
        setTab(currentTab);
    }

    init();
})(jQuery);
