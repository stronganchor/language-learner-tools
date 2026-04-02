(function ($) {
    'use strict';

    const cfg = window.llIpaKeyboardAdmin || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};

    const $admin = $('.ll-ipa-admin').first();
    const $wordset = $('#ll-ipa-wordset');
    const $symbols = $('#ll-ipa-symbols');
    const $letterMap = $('#ll-ipa-letter-map');
    const $status = $('#ll-ipa-admin-status');
    const $addInput = $('#ll-ipa-add-input');
    const $addBtn = $('#ll-ipa-add-btn');
    const $addLabel = $('#ll-ipa-add-label');
    const $symbolsHeading = $('#ll-ipa-symbols-heading');
    const $symbolsDescription = $('#ll-ipa-symbols-description');
    const $letterMapHeading = $('#ll-ipa-letter-map-heading');
    const $letterMapDescription = $('#ll-ipa-letter-map-description');

    let currentWordsetId = 0;
    let currentTranscription = null;
    let currentAudio = null;
    let currentAudioButton = null;
    let letterMapRefreshTimer = null;
    let letterMapRefreshRequestId = 0;

    function buildDefaultTranscription() {
        return {
            mode: 'transcription',
            uses_ipa_font: false,
            special_chars_heading: ($symbolsHeading.text() || '').toString().trim(),
            special_chars_description: ($symbolsDescription.text() || '').toString().trim(),
            special_chars_add_label: ($addLabel.text() || '').toString().trim(),
            special_chars_add_placeholder: ($addInput.attr('placeholder') || '').toString(),
            special_chars_empty: i18n.empty || 'No special characters found for this word set.',
            symbols_column_label: i18n.pronunciationLabel || 'Pronunciation',
            map_heading: ($letterMapHeading.text() || '').toString().trim(),
            map_description: ($letterMapDescription.text() || '').toString().trim(),
            map_sample_value_label: ((i18n.pronunciationLabel || 'Pronunciation') + ':'),
            map_add_symbols_label: i18n.pronunciationLabel || 'Pronunciation',
            map_add_symbols_placeholder: ($addInput.attr('placeholder') || '').toString(),
            map_add_missing: i18n.mapAddMissing || 'Enter letters and characters to add.'
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
        if ($admin.length) {
            $admin.attr('data-ll-secondary-text-mode', currentTranscription.mode || 'transcription');
        }
        if ($addLabel.length) {
            $addLabel.text(currentTranscription.special_chars_add_label || 'Add characters');
        }
        if ($addInput.length) {
            $addInput.attr('placeholder', currentTranscription.special_chars_add_placeholder || 'e.g. special characters');
        }
        if ($symbolsHeading.length) {
            $symbolsHeading.text(currentTranscription.special_chars_heading || 'Special Characters');
        }
        if ($symbolsDescription.length) {
            $symbolsDescription.text(currentTranscription.special_chars_description || '');
        }
        if ($letterMapHeading.length) {
            $letterMapHeading.text(currentTranscription.map_heading || 'Letter Map');
        }
        if ($letterMapDescription.length) {
            $letterMapDescription.text(currentTranscription.map_description || '');
        }
    }

    function formatCount(count, singularTemplate, pluralTemplate) {
        const template = count === 1 ? singularTemplate : pluralTemplate;
        return (template || '%1$d').replace('%1$d', String(count));
    }

    function setStatus(message, isError) {
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
        if (!message) {
            $status.removeClass('is-error');
        }
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
            renderLetterMap(response.data || {});
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
        }, 900);
    }

    function getSymbolSummaryText(recordingCount, occurrenceCount) {
        const countLabel = formatCount(
            recordingCount,
            i18n.recordingCountSingular || '%1$d recording',
            i18n.recordingCountPlural || '%1$d recordings'
        );
        const totalLabel = occurrenceCount
            ? (' - ' + formatCount(
                occurrenceCount,
                i18n.occurrenceCountSingular || '%1$d occurrence',
                i18n.occurrenceCountPlural || '%1$d occurrences'
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
        const recordingType = rec && rec.recording_type ? rec.recording_type : '';
        const recordingTypeSlug = rec && rec.recording_type_slug ? rec.recording_type_slug : '';
        const recordingIconType = rec && rec.recording_icon_type ? rec.recording_icon_type : 'isolation';
        const recordingText = rec && rec.recording_text ? rec.recording_text : '';
        const recordingTranslation = rec && rec.recording_translation ? rec.recording_translation : '';
        const recordingIpa = rec && rec.recording_ipa ? rec.recording_ipa : '';
        const audioUrl = rec && rec.audio_url ? rec.audio_url : '';
        const audioLabel = rec && rec.audio_label ? rec.audio_label : (i18n.playRecording || 'Play recording');
        const editLink = rec && rec.word_edit_link ? rec.word_edit_link : '';

        const $wordCell = $('<td>');
        if (editLink) {
            $wordCell.append(
                $('<a>', { href: editLink, text: wordText || (i18n.untitled || '(Untitled)'), target: '_blank' })
            );
        } else {
            $wordCell.text(wordText || (i18n.untitled || '(Untitled)'));
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

        const $recordingMeta = $('<div>', { class: 'll-ipa-recording-cell' });
        if (audioUrl) {
            const $audioButton = $('<button>', {
                type: 'button',
                class: 'll-study-recording-btn ll-ipa-recording-audio-btn ll-study-recording-btn--' + recordingIconType,
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
            $recordingMeta.append($audioButton);
        }
        $recordingMeta.append($('<span>', {
            class: 'll-ipa-recording-type-label',
            text: recordingType || '-'
        }));

        const $ipaInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-input',
            value: recordingIpa
        });

        const $saveBtn = $('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-save',
            text: i18n.save || 'Save',
            'data-recording-id': recordingId
        });

        return $('<tr>', { 'data-recording-id': recordingId })
            .append($wordCell)
            .append($('<td>').append($recordingMeta))
            .append($textCell)
            .append($('<td>').append($ipaInput))
            .append($('<td>').append($saveBtn));
    }

    function buildRecordingsTable() {
        const transcription = getTranscription();
        const $table = $('<table>', { class: 'widefat striped ll-ipa-recordings' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: i18n.wordColumnLabel || 'Word' }))
                .append($('<th>', { text: i18n.recordingColumnLabel || 'Recording' }))
                .append($('<th>', { text: i18n.textColumnLabel || 'Text' }))
                .append($('<th>', { text: transcription.symbols_column_label || 'Pronunciation' }))
                .append($('<th>', { text: '' }))
        );

        return $table.append($thead, $('<tbody>'));
    }

    function findSymbolDetails(symbol) {
        return $symbols.children('.ll-ipa-symbol').filter(function () {
            return ($(this).attr('data-symbol') || '') === symbol;
        }).first();
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

    function insertSymbolDetails($details) {
        const symbol = ($details.attr('data-symbol') || '').toString();
        let inserted = false;

        $symbols.children('.ll-ipa-empty').remove();
        $symbols.children('.ll-ipa-symbol').each(function () {
            const $existing = $(this);
            const existingSymbol = ($existing.attr('data-symbol') || '').toString();
            if (existingSymbol.localeCompare(symbol) > 0) {
                $existing.before($details);
                inserted = true;
                return false;
            }
            return undefined;
        });

        if (!inserted) {
            $symbols.append($details);
        }
    }

    function getOrCreateSymbolDetails(symbol) {
        let $details = findSymbolDetails(symbol);
        if ($details.length) {
            return $details;
        }

        $details = createSymbolDetails(symbol);
        insertSymbolDetails($details);
        return $details;
    }

    function ensureSymbolTableBody($details) {
        const $body = $details.children('.ll-ipa-symbol-body').first();
        let $table = $body.children('.ll-ipa-recordings').first();

        $body.children('.ll-ipa-empty').remove();
        if (!$table.length) {
            $table = buildRecordingsTable();
            $body.append($table);
        }

        return $table.children('tbody').first();
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
            text: i18n.noRecordings || 'No recordings use this character yet.'
        }));
    }

    function upsertRecordingRow(symbol, rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        if (!symbol || !recordingId) {
            return;
        }

        const $details = getOrCreateSymbolDetails(symbol);
        const $tbody = ensureSymbolTableBody($details);
        const $existing = $tbody.children('tr').filter(function () {
            return (parseInt($(this).attr('data-recording-id'), 10) || 0) === recordingId;
        }).first();
        const $row = buildRecordingRow(rec);

        if ($existing.length) {
            $existing.replaceWith($row);
        } else {
            $tbody.append($row);
        }
    }

    function removeRecordingRow(symbol, recordingId) {
        const safeRecordingId = parseInt(recordingId, 10) || 0;
        if (!symbol || !safeRecordingId) {
            return;
        }

        const $details = findSymbolDetails(symbol);
        if (!$details.length) {
            return;
        }

        const $tbody = $details.find('.ll-ipa-recordings tbody').first();
        if (!$tbody.length) {
            return;
        }

        $tbody.children('tr').filter(function () {
            return (parseInt($(this).attr('data-recording-id'), 10) || 0) === safeRecordingId;
        }).remove();

        if (!$tbody.children('tr').length) {
            ensureSymbolEmptyState($details);
        }
    }

    function normalizeSymbolCountMap(rawMap) {
        const clean = {};

        if (!rawMap || typeof rawMap !== 'object') {
            return clean;
        }

        Object.keys(rawMap).forEach(function (symbol) {
            const count = parseInt(rawMap[symbol], 10) || 0;
            if (symbol && count > 0) {
                clean[symbol] = count;
            }
        });

        return clean;
    }

    function syncSavedRecording(data) {
        const recording = data && data.recording ? data.recording : {};
        const recordingId = parseInt(recording.recording_id || data.recording_id, 10) || 0;
        const previousCounts = normalizeSymbolCountMap(data && data.previous_symbol_counts);
        const nextCounts = normalizeSymbolCountMap(data && data.symbol_counts);
        const symbolsToSync = {};

        if (!recordingId) {
            return;
        }

        if (currentAudioButton && (parseInt($(currentAudioButton).attr('data-recording-id'), 10) || 0) === recordingId) {
            stopCurrentAudio();
        }

        Object.keys(previousCounts).forEach(function (symbol) {
            symbolsToSync[symbol] = true;
        });
        Object.keys(nextCounts).forEach(function (symbol) {
            symbolsToSync[symbol] = true;
        });

        Object.keys(symbolsToSync).forEach(function (symbol) {
            const previousCount = previousCounts[symbol] || 0;
            const nextCount = nextCounts[symbol] || 0;
            const recordingDelta = (nextCount > 0 ? 1 : 0) - (previousCount > 0 ? 1 : 0);
            const occurrenceDelta = nextCount - previousCount;

            if (nextCount > 0) {
                upsertRecordingRow(symbol, recording);
            } else {
                removeRecordingRow(symbol, recordingId);
            }

            if (recordingDelta !== 0 || occurrenceDelta !== 0) {
                const $details = nextCount > 0 ? getOrCreateSymbolDetails(symbol) : findSymbolDetails(symbol);
                if ($details.length) {
                    const currentRecordingCount = parseInt($details.attr('data-recording-count'), 10) || 0;
                    const currentOccurrenceCount = parseInt($details.attr('data-occurrence-count'), 10) || 0;
                    setSymbolSummaryCounts($details, currentRecordingCount + recordingDelta, currentOccurrenceCount + occurrenceDelta);
                    if ((parseInt($details.attr('data-recording-count'), 10) || 0) === 0) {
                        ensureSymbolEmptyState($details);
                    }
                }
            }
        });

        if (typeof data.recording_ipa === 'string') {
            $symbols.find('tr[data-recording-id="' + recordingId + '"] .ll-ipa-input').val(data.recording_ipa);
        }
    }

    function renderSymbols(payload) {
        const list = (payload && Array.isArray(payload.symbols)) ? payload.symbols : [];
        $symbols.empty();

        if (!list.length) {
            $symbols.append(
                $('<div>', {
                    class: 'll-ipa-empty',
                    text: getTranscription().special_chars_empty || i18n.empty || 'No special characters found for this word set.'
                })
            );
            return;
        }

        list.forEach(function (entry) {
            const symbol = (entry && entry.symbol) ? entry.symbol : '';
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
                const $tbody = ensureSymbolTableBody($details);
                recordings.forEach(function (rec) {
                    $tbody.append(buildRecordingRow(rec));
                });
            } else {
                ensureSymbolEmptyState($details);
            }

            $symbols.append($details);
        });
    }

    function renderLetterMap(payload) {
        const list = (payload && Array.isArray(payload.letter_map)) ? payload.letter_map : [];
        const transcription = getTranscription();
        $letterMap.empty();

        const $add = $('<div>', { class: 'll-ipa-map-add' });
        $add.append(
            $('<div>', { class: 'll-ipa-map-add-title', text: i18n.mapAddLabel || 'Add manual mapping' })
        );
        const $addFields = $('<div>', { class: 'll-ipa-map-add-fields' });
        const $addLetter = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-letter',
            placeholder: i18n.mapAddLettersPlaceholder || 'Letters (e.g. ll)',
            'aria-label': i18n.mapAddLettersLabel || 'Letters'
        });
        const $addSymbols = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-symbols',
            placeholder: transcription.map_add_symbols_placeholder || 'Characters (e.g. special characters)',
            'aria-label': transcription.map_add_symbols_label || 'Characters'
        });
        const $addBtn = $('<button>', {
            type: 'button',
            class: 'll-ipa-map-button ll-ipa-map-add-btn',
            text: i18n.mapAdd || 'Add mapping'
        });
        $addFields.append($addLetter, $addSymbols, $addBtn);
        $add.append($addFields);
        if (i18n.mapAddHint) {
            $add.append($('<div>', { class: 'll-ipa-map-add-hint', text: i18n.mapAddHint }));
        }
        $letterMap.append($add);

        if (!list.length) {
            $letterMap.append(
                $('<div>', { class: 'll-ipa-empty', text: i18n.mapEmpty || 'No letter mappings found for this word set.' })
            );
            return;
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-letter-table' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: i18n.mapLetterLabel || 'Letter(s)' }))
                .append($('<th>', { text: i18n.mapAutoLabel || 'Auto map' }))
                .append($('<th>', { text: i18n.mapManualLabel || 'Manual override' }))
        );
        $table.append($thead);

        const $tbody = $('<tbody>');
        list.forEach(function (entry) {
            const letter = (entry && entry.letter) ? entry.letter : '';
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
                        'aria-label': i18n.mapBlockLabel || 'Block mapping',
                        title: i18n.mapBlockLabel || 'Block mapping'
                    });
                    $tokenRow.append($token, $blockBtn);
                    $tokenWrap.append($tokenRow);

                    if (samples.length) {
                        const samplesLabel = i18n.mapSamplesLabel || 'Examples';
                        const $details = $('<details>', { class: 'll-ipa-map-samples' });
                        $details.append(
                            $('<summary>', { text: samplesLabel + ' (' + samples.length + ')' })
                        );
                        const $list = $('<div>', { class: 'll-ipa-map-samples-list' });
                        samples.forEach(function (sample) {
                            const wordText = sample.word_text || '';
                            const wordTranslation = sample.word_translation || '';
                            const editLink = sample.word_edit_link || '';
                            const recordingText = sample.recording_text || '';
                            const recordingTranslation = sample.recording_translation || '';
                            const recordingIpa = sample.recording_ipa || '';

                            const $item = $('<div>', { class: 'll-ipa-map-sample' });
                            const $title = $('<div>', { class: 'll-ipa-map-sample-title' });
                            if (editLink) {
                                $title.append(
                                    $('<a>', {
                                        href: editLink,
                                        text: wordText || (i18n.untitled || '(Untitled)'),
                                        target: '_blank',
                                        class: 'll-ipa-map-sample-link'
                                    })
                                );
                            } else {
                                $title.text(wordText || (i18n.untitled || '(Untitled)'));
                            }
                            if (wordTranslation) {
                                $title.append(
                                    $('<span>', { class: 'll-ipa-translation', text: ' (' + wordTranslation + ')' })
                                );
                            }
                            $item.append($title);

                            if (recordingText || recordingTranslation) {
                                const $textRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: (i18n.mapSampleTextLabel || 'Text:') }));
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-value', text: recordingText || '-' }));
                                if (recordingTranslation) {
                                    $textRow.append(
                                        $('<span>', { class: 'll-ipa-translation', text: ' (' + recordingTranslation + ')' })
                                    );
                                }
                                $item.append($textRow);
                            }
                            if (recordingIpa) {
                                const $ipaRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: (transcription.map_sample_value_label || 'Pronunciation:') }));
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-value ll-ipa-map-sample-ipa', text: recordingIpa }));
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
                    $autoCell.append(
                        $('<span>', { class: 'll-ipa-map-empty', text: i18n.mapAutoEmpty || 'No mappings yet.' })
                    );
                }
            } else {
                $autoCell.append(
                    $('<span>', { class: 'll-ipa-map-empty', text: i18n.mapAutoEmpty || 'No mappings yet.' })
                );
            }

            const manualValue = manualList.filter(Boolean).join(' ');
            const $input = $('<input>', {
                type: 'text',
                class: 'll-ipa-map-input',
                value: manualValue,
                placeholder: transcription.map_add_symbols_placeholder || i18n.mapPlaceholder || 'e.g. r'
            });

            const $saveBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-save',
                text: i18n.save || 'Save',
                'data-letter': letter
            });

            const $clearBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-clear',
                text: i18n.mapClear || 'Clear',
                'data-letter': letter
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
            const letter = (entry && entry.letter) ? entry.letter : '';
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
            $blockedWrap.append(
                $('<div>', { class: 'll-ipa-map-blocked-title', text: i18n.mapBlockedTitle || 'Blocked mappings' })
            );
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
                    text: i18n.mapUnblockLabel || 'Undo',
                    'data-letter': item.letter,
                    'data-symbol': item.symbol
                });
                $row.append($label, $undo);
                $blockedList.append($row);
            });
            $blockedWrap.append($blockedList);
            $letterMap.append($blockedWrap);
        }
    }

    function loadWordset(wordsetId) {
        currentWordsetId = wordsetId;
        cancelScheduledLetterMapRefresh();
        $symbols.empty();
        $letterMap.empty();
        setStatus('');
        if (!wordsetId) {
            applyTranscriptionConfig();
            return;
        }

        setStatus(i18n.loading || 'Loading transcription data...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_data',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            applyTranscriptionConfig(response.data ? response.data.transcription : null);
            renderSymbols(response.data || {});
            renderLetterMap(response.data || {});
            setStatus('');
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        });
    }

    $wordset.on('change', function () {
        const wordsetId = parseInt($(this).val(), 10) || 0;
        loadWordset(wordsetId);
    });

    $addBtn.on('click', function () {
        if (!currentWordsetId) {
            setStatus(i18n.selectWordset || 'Select a word set first.', true);
            return;
        }
        const symbols = ($addInput.val() || '').toString();
        if (!symbols.trim()) {
            setStatus(i18n.enterSymbols || 'Enter one or more characters to add.', true);
            return;
        }
        $addBtn.prop('disabled', true);
        setStatus(i18n.loading || 'Loading transcription data...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_add_wordset_ipa_symbols',
            nonce: nonce,
            wordset_id: currentWordsetId,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            $addInput.val('');
            setStatus(i18n.addSuccess || 'Characters added.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $addBtn.prop('disabled', false);
        });
    });

    $symbols.on('click', '.ll-ipa-save', function () {
        const $btn = $(this);
        const recordingId = parseInt($btn.attr('data-recording-id'), 10) || 0;
        if (!recordingId || !currentWordsetId) {
            return;
        }
        const $row = $btn.closest('tr');
        const $input = $row.find('.ll-ipa-input').first();
        const ipaValue = ($input.val() || '').toString();
        const originalText = $btn.text();

        $btn.prop('disabled', true).text(i18n.saving || 'Saving...');
        $.post(ajaxUrl, {
            action: 'll_tools_update_recording_ipa',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            recording_ipa: ipaValue
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            const data = response.data || {};
            syncSavedRecording(data);
            if (data.letter_map_refresh_required) {
                scheduleLetterMapRefresh(currentWordsetId);
            }
            setStatus(i18n.saved || 'Saved.', false);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    $symbols.on('click', '.ll-study-recording-btn', function (event) {
        const button = event.currentTarget;
        const url = (button.getAttribute('data-audio-url') || '').trim();

        event.preventDefault();
        event.stopPropagation();

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
    });

    $letterMap.on('click', '.ll-ipa-map-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.data('letter') || '').toString();
        if (!letter || !currentWordsetId) {
            return;
        }
        const $input = $row.find('.ll-ipa-map-input').first();
        const symbols = ($input.val() || '').toString();
        const $clearBtn = $row.find('.ll-ipa-map-clear').first();

        const payload = {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter
        };
        if (symbols.trim()) {
            payload.symbols = symbols;
        } else {
            payload.clear = 1;
        }

        $btn.prop('disabled', true);
        $clearBtn.prop('disabled', true);
        setStatus(i18n.saving || 'Saving...', false);
        $.post(ajaxUrl, payload).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false);
            $clearBtn.prop('disabled', false);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-block', function () {
        const $btn = $(this);
        const letter = ($btn.attr('data-letter') || '').toString();
        const symbol = ($btn.attr('data-symbol') || '').toString();
        if (!letter || !symbol || !currentWordsetId) {
            return;
        }
        $btn.prop('disabled', true);
        setStatus(i18n.saving || 'Saving...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_block_wordset_ipa_letter_mapping',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbol: symbol
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-unblock', function () {
        const $btn = $(this);
        const letter = ($btn.attr('data-letter') || '').toString();
        const symbol = ($btn.attr('data-symbol') || '').toString();
        if (!letter || !symbol || !currentWordsetId) {
            return;
        }
        $btn.prop('disabled', true);
        setStatus(i18n.saving || 'Saving...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_unblock_wordset_ipa_letter_mapping',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbol: symbol
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-add-btn', function () {
        if (!currentWordsetId) {
            setStatus(i18n.selectWordset || 'Select a word set first.', true);
            return;
        }
        const $btn = $(this);
        const $wrap = $btn.closest('.ll-ipa-map-add');
        const $letter = $wrap.find('.ll-ipa-map-add-letter').first();
        const $symbols = $wrap.find('.ll-ipa-map-add-symbols').first();
        const transcription = getTranscription();
        const letterValue = ($letter.val() || '').toString();
        const symbolsValue = ($symbols.val() || '').toString();
        if (!letterValue.trim() || !symbolsValue.trim()) {
            setStatus(transcription.map_add_missing || i18n.mapAddMissing || 'Enter letters and characters to add.', true);
            return;
        }

        $btn.prop('disabled', true);
        setStatus(i18n.saving || 'Saving...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letterValue,
            symbols: symbolsValue
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            $letter.val('');
            $symbols.val('');
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-clear', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.data('letter') || '').toString();
        if (!letter || !currentWordsetId) {
            return;
        }
        const $saveBtn = $row.find('.ll-ipa-map-save').first();

        $btn.prop('disabled', true);
        $saveBtn.prop('disabled', true);
        setStatus(i18n.saving || 'Saving...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            clear: 1
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false);
            $saveBtn.prop('disabled', false);
        });
    });

    applyTranscriptionConfig();
})(jQuery);
