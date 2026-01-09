(function ($) {
    'use strict';

    const cfg = window.llIpaKeyboardAdmin || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};

    const $wordset = $('#ll-ipa-wordset');
    const $symbols = $('#ll-ipa-symbols');
    const $letterMap = $('#ll-ipa-letter-map');
    const $status = $('#ll-ipa-admin-status');
    const $addInput = $('#ll-ipa-add-input');
    const $addBtn = $('#ll-ipa-add-btn');

    let currentWordsetId = 0;

    function setStatus(message, isError) {
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
        if (!message) {
            $status.removeClass('is-error');
        }
    }

    function renderSymbols(payload) {
        const list = (payload && Array.isArray(payload.symbols)) ? payload.symbols : [];
        $symbols.empty();

        if (!list.length) {
            $symbols.append(
                $('<div>', { class: 'll-ipa-empty', text: i18n.empty || 'No IPA symbols found for this word set.' })
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

            const $details = $('<details>', { class: 'll-ipa-symbol', 'data-symbol': symbol });
            const $summary = $('<summary>');
            $summary.append($('<span>', { class: 'll-ipa-symbol-text', text: symbol }));

            const countLabel = recordingCount === 1
                ? recordingCount + ' recording'
                : recordingCount + ' recordings';
            const totalLabel = count ? (' - ' + count + ' occurrences') : '';
            $summary.append($('<span>', { class: 'll-ipa-symbol-count', text: countLabel + totalLabel }));

            $details.append($summary);

            const $body = $('<div>', { class: 'll-ipa-symbol-body' });
            if (recordings.length) {
                const $table = $('<table>', { class: 'widefat striped ll-ipa-recordings' });
                const $thead = $('<thead>').append(
                    $('<tr>')
                        .append($('<th>', { text: 'Word' }))
                        .append($('<th>', { text: 'Recording' }))
                        .append($('<th>', { text: 'Text' }))
                        .append($('<th>', { text: 'IPA' }))
                        .append($('<th>', { text: '' }))
                );
                $table.append($thead);

                const $tbody = $('<tbody>');
                recordings.forEach(function (rec) {
                    const recordingId = rec.recording_id || 0;
                    const wordText = rec.word_text || '';
                    const wordTranslation = rec.word_translation || '';
                    const recordingType = rec.recording_type || '';
                    const recordingText = rec.recording_text || '';
                    const recordingTranslation = rec.recording_translation || '';
                    const recordingIpa = rec.recording_ipa || '';
                    const editLink = rec.word_edit_link || '';

                    const $wordCell = $('<td>');
                    if (editLink) {
                        $wordCell.append(
                            $('<a>', { href: editLink, text: wordText || '(Untitled)', target: '_blank' })
                        );
                    } else {
                        $wordCell.text(wordText || '(Untitled)');
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
                        value: recordingIpa
                    });

                    const $saveBtn = $('<button>', {
                        type: 'button',
                        class: 'button button-primary ll-ipa-save',
                        text: i18n.save || 'Save',
                        'data-recording-id': recordingId
                    });

                    const $row = $('<tr>')
                        .append($wordCell)
                        .append($('<td>', { text: recordingType || '-' }))
                        .append($textCell)
                        .append($('<td>').append($ipaInput))
                        .append($('<td>').append($saveBtn));

                    $tbody.append($row);
                });

                $table.append($tbody);
                $body.append($table);
            } else {
                $body.append($('<div>', { class: 'll-ipa-empty', text: i18n.noRecordings || 'No recordings use this symbol yet.' }));
            }

            $details.append($body);
            $symbols.append($details);
        });
    }

    function renderLetterMap(payload) {
        const list = (payload && Array.isArray(payload.letter_map)) ? payload.letter_map : [];
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
            placeholder: i18n.mapAddSymbolsPlaceholder || 'IPA symbols (e.g. r)',
            'aria-label': i18n.mapAddSymbolsLabel || 'IPA symbols'
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
                    if (!symbol) {
                        return;
                    }
                    const $token = $('<span>', { class: 'll-ipa-map-token' });
                    $token.append($('<span>', { class: 'll-ipa-map-token-symbol', text: symbol }));
                    if (count) {
                        $token.append($('<span>', { class: 'll-ipa-map-token-count', text: '(' + count + ')' }));
                    }
                    $autoWrap.append($token);
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
                placeholder: i18n.mapPlaceholder || 'e.g. r'
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
    }

    function loadWordset(wordsetId) {
        currentWordsetId = wordsetId;
        $symbols.empty();
        $letterMap.empty();
        setStatus('');
        if (!wordsetId) {
            return;
        }

        setStatus(i18n.loading || 'Loading IPA symbols...', false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_data',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(i18n.error || 'Something went wrong. Please try again.', true);
                return;
            }
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
            setStatus(i18n.enterSymbols || 'Enter one or more symbols to add.', true);
            return;
        }
        $addBtn.prop('disabled', true);
        setStatus(i18n.loading || 'Loading IPA symbols...', false);
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
            setStatus(i18n.addSuccess || 'Symbols added.', false);
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
            if (typeof data.recording_ipa === 'string') {
                $input.val(data.recording_ipa);
            }
            setStatus(i18n.saved || 'Saved.', false);
            loadWordset(currentWordsetId);
        }).fail(function () {
            setStatus(i18n.error || 'Something went wrong. Please try again.', true);
        }).always(function () {
            $btn.prop('disabled', false).text(originalText);
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

    $letterMap.on('click', '.ll-ipa-map-add-btn', function () {
        if (!currentWordsetId) {
            setStatus(i18n.selectWordset || 'Select a word set first.', true);
            return;
        }
        const $btn = $(this);
        const $wrap = $btn.closest('.ll-ipa-map-add');
        const $letter = $wrap.find('.ll-ipa-map-add-letter').first();
        const $symbols = $wrap.find('.ll-ipa-map-add-symbols').first();
        const letterValue = ($letter.val() || '').toString();
        const symbolsValue = ($symbols.val() || '').toString();
        if (!letterValue.trim() || !symbolsValue.trim()) {
            setStatus(i18n.mapAddMissing || 'Enter letters and IPA symbols to add.', true);
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
})(jQuery);
