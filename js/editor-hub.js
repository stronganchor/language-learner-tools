(function ($) {
    'use strict';

    const data = window.ll_editor_hub_data || {};
    const ajaxUrl = data.ajax_url || '';
    const nonce = data.nonce || '';

    if (!ajaxUrl || !nonce) {
        return;
    }

    const $root = $('[data-ll-editor-hub]').first();
    if (!$root.length) {
        return;
    }

    const $status = $root.find('[data-ll-status]').first();
    const $card = $root.find('[data-ll-card]').first();
    const $categorySelect = $root.find('[data-ll-category-select]').first();
    const $saveBtn = $root.find('[data-ll-save]').first();
    const $skipBtn = $root.find('[data-ll-skip]').first();
    const $reloadBtn = $root.find('[data-ll-reload]').first();
    const $currentNum = $root.find('[data-ll-current-num]').first();
    const $totalNum = $root.find('[data-ll-total-num]').first();

    const i18n = data.i18n || {};
    const uiOptions = data.ui_options || {};

    let wordsetId = parseInt(data.wordset_id, 10) || 0;
    let categories = Array.isArray(data.categories) ? data.categories.slice() : [];
    let selectedCategory = (data.selected_category || '').toString();
    let items = Array.isArray(data.items) ? data.items.slice() : [];
    let currentIndex = 0;
    let isBusy = false;

    function normalizeHostContainers() {
        const hostSelector = [
            '.entry-content',
            '.post-content',
            '.wp-block-post-content',
            '.inside-article',
            '.site-main',
            '.content-area',
            '.content',
            '.main-content',
            '.ast-container',
            '.container',
            'main',
            'article'
        ].join(',');

        const $hosts = $root.parents(hostSelector);
        if ($hosts.length) {
            $hosts.addClass('ll-editor-hub-host');
        } else {
            $root.parent().addClass('ll-editor-hub-host');
        }

        $('body').addClass('ll-editor-hub-page');
    }

    function t(key, fallback) {
        const value = i18n[key];
        if (typeof value === 'string' && value !== '') {
            return value;
        }
        return fallback;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setBusy(nextBusy) {
        isBusy = !!nextBusy;
        $saveBtn.prop('disabled', isBusy);
        $skipBtn.prop('disabled', isBusy);
        $reloadBtn.prop('disabled', isBusy);
        $categorySelect.prop('disabled', isBusy);
    }

    function setStatus(message, kind) {
        const text = (message || '').toString();
        $status.text(text);
        $status.removeClass('is-error is-success is-info');
        if (kind === 'error') {
            $status.addClass('is-error');
        } else if (kind === 'success') {
            $status.addClass('is-success');
        } else if (kind === 'info') {
            $status.addClass('is-info');
        }
    }

    function syncCategoryOptions() {
        const rows = Array.isArray(categories) ? categories : [];
        if (!rows.length) {
            $categorySelect.empty();
            return;
        }

        const html = rows.map(function (row) {
            const slug = (row && row.slug ? row.slug : '').toString();
            if (!slug) {
                return '';
            }
            const name = (row && row.name ? row.name : slug).toString();
            const count = parseInt(row && row.count ? row.count : 0, 10) || 0;
            const selected = (slug === selectedCategory) ? ' selected' : '';
            return '<option value="' + escapeHtml(slug) + '"' + selected + '>'
                + escapeHtml(name + ' (' + count + ')')
                + '</option>';
        }).join('');

        $categorySelect.html(html);

        if (!$categorySelect.val() && rows.length) {
            selectedCategory = (rows[0].slug || '').toString();
            $categorySelect.val(selectedCategory);
        }
    }

    function getCurrentItem() {
        if (!Array.isArray(items) || !items.length) {
            return null;
        }
        if (currentIndex < 0) {
            currentIndex = 0;
        }
        if (currentIndex >= items.length) {
            currentIndex = items.length - 1;
        }
        return items[currentIndex] || null;
    }

    function updateProgress() {
        const total = Array.isArray(items) ? items.length : 0;
        const current = total > 0 ? (currentIndex + 1) : 0;
        $currentNum.text(String(current));
        $totalNum.text(String(total));
    }

    function buildSelectOptions(rows, selectedValue) {
        const selected = (selectedValue || '').toString();
        let html = '<option value="">' + escapeHtml(t('none', 'None')) + '</option>';
        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            const value = (row && row.value ? row.value : '').toString();
            if (!value) {
                return;
            }
            const label = (row && row.label ? row.label : value).toString();
            const isSelected = selected === value ? ' selected' : '';
            html += '<option value="' + escapeHtml(value) + '"' + isSelected + '>' + escapeHtml(label) + '</option>';
        });
        return html;
    }

    function itemFieldValue(item, keyPath, fallback) {
        const segments = keyPath.split('.');
        let value = item;
        segments.forEach(function (segment) {
            if (value && typeof value === 'object') {
                value = value[segment];
            } else {
                value = undefined;
            }
        });

        if (typeof value === 'string') {
            return value;
        }
        if (typeof value === 'number') {
            return String(value);
        }
        return fallback || '';
    }

    function renderMissingBadges(item) {
        const labels = Array.isArray(item && item.missing_labels) ? item.missing_labels : [];
        if (!labels.length) {
            return '';
        }

        const chips = labels.map(function (label) {
            return '<span class="ll-editor-hub-chip">' + escapeHtml(label) + '</span>';
        }).join('');

        return '<div class="ll-editor-hub-missing">'
            + '<div class="ll-editor-hub-missing-title">' + escapeHtml(t('missing_fields', 'Missing fields')) + '</div>'
            + '<div class="ll-editor-hub-chip-list">' + chips + '</div>'
            + '</div>';
    }

    function renderRecordings(item) {
        const recordings = Array.isArray(item && item.recordings) ? item.recordings : [];
        if (!recordings.length) {
            return '';
        }

        let html = '<section class="ll-editor-hub-section">';
        html += '<h3 class="ll-editor-hub-section-title">' + escapeHtml(t('recordings', 'Recordings')) + '</h3>';

        recordings.forEach(function (recording) {
            const recId = parseInt(recording && recording.id ? recording.id : 0, 10) || 0;
            if (!recId) {
                return;
            }

            const typeLabel = (recording && recording.label ? recording.label : '').toString();
            const textValue = (recording && recording.text ? recording.text : '').toString();
            const translationValue = (recording && recording.translation ? recording.translation : '').toString();
            const ipaValue = (recording && recording.ipa ? recording.ipa : '').toString();
            const audioUrl = (recording && recording.audio_url ? recording.audio_url : '').toString();
            const missing = (recording && typeof recording.missing === 'object' && recording.missing !== null)
                ? recording.missing
                : {};

            const textMissingClass = missing.text ? ' is-missing' : '';
            const translationMissingClass = missing.translation ? ' is-missing' : '';
            const ipaMissingClass = missing.ipa ? ' is-missing' : '';

            html += '<div class="ll-editor-hub-recording" data-recording-id="' + recId + '">';
            html += '<div class="ll-editor-hub-recording-head">';
            html += '<strong>' + escapeHtml(typeLabel || t('recordings', 'Recordings')) + '</strong>';
            if (audioUrl) {
                html += '<audio controls preload="none" src="' + escapeHtml(audioUrl) + '"></audio>';
            }
            html += '</div>';

            html += '<div class="ll-editor-hub-field' + textMissingClass + '">';
            html += '<label>' + escapeHtml(t('recording_text', 'Text')) + '</label>';
            html += '<input type="text" data-recording-field="text" value="' + escapeHtml(textValue) + '" />';
            html += '</div>';

            html += '<div class="ll-editor-hub-field' + translationMissingClass + '">';
            html += '<label>' + escapeHtml(t('recording_translation', 'Translation')) + '</label>';
            html += '<input type="text" data-recording-field="translation" value="' + escapeHtml(translationValue) + '" />';
            html += '</div>';

            html += '<div class="ll-editor-hub-field' + ipaMissingClass + '">';
            html += '<label>' + escapeHtml(t('recording_ipa', 'IPA')) + '</label>';
            html += '<input type="text" data-recording-field="ipa" value="' + escapeHtml(ipaValue) + '" />';
            html += '</div>';

            html += '</div>';
        });

        html += '</section>';
        return html;
    }

    function renderImagePanel(item, titleText) {
        const image = (item && typeof item.image === 'object' && item.image !== null)
            ? item.image
            : {};
        const imageUrl = (image && image.url ? image.url : '').toString();
        const altText = (image && image.alt ? image.alt : titleText || '').toString();

        let html = '<aside class="ll-editor-hub-media">';
        html += '<div class="ll-editor-hub-media-label">' + escapeHtml(t('image', 'Image')) + '</div>';
        html += '<figure class="ll-editor-hub-image-frame">';

        if (imageUrl) {
            html += '<img class="ll-editor-hub-image" src="' + escapeHtml(imageUrl) + '" alt="' + escapeHtml(altText) + '" loading="lazy" />';
        } else {
            html += '<div class="ll-editor-hub-image-empty">' + escapeHtml(t('no_image', 'No image available')) + '</div>';
        }

        html += '</figure>';
        html += '</aside>';

        return html;
    }

    function renderCurrentItem() {
        updateProgress();

        const item = getCurrentItem();
        if (!item) {
            $card.html('<div class="ll-editor-hub-empty">' + escapeHtml(t('no_items', 'No missing items in this category.')) + '</div>');
            return;
        }

        const wordText = itemFieldValue(item, 'word_text');
        const wordTranslation = itemFieldValue(item, 'word_translation');
        const wordNote = itemFieldValue(item, 'word_note');
        const dictTitle = itemFieldValue(item, 'dictionary_entry.title');
        const dictId = itemFieldValue(item, 'dictionary_entry.id');
        const posSlug = itemFieldValue(item, 'part_of_speech.slug');
        const genderValue = itemFieldValue(item, 'grammatical_gender.value');
        const pluralityValue = itemFieldValue(item, 'grammatical_plurality.value');
        const verbTenseValue = itemFieldValue(item, 'verb_tense.value');
        const verbMoodValue = itemFieldValue(item, 'verb_mood.value');

        const flags = (item && typeof item.missing_flags === 'object' && item.missing_flags !== null)
            ? item.missing_flags
            : {};

        const wordMissing = flags.word_text ? ' is-missing' : '';
        const translationMissing = flags.word_translation ? ' is-missing' : '';
        const noteMissing = flags.word_note ? ' is-missing' : '';
        const dictMissing = flags.dictionary_entry ? ' is-missing' : '';
        const posMissing = flags.part_of_speech ? ' is-missing' : '';
        const genderMissing = flags.grammatical_gender ? ' is-missing' : '';
        const pluralityMissing = flags.grammatical_plurality ? ' is-missing' : '';
        const verbTenseMissing = flags.verb_tense ? ' is-missing' : '';
        const verbMoodMissing = flags.verb_mood ? ' is-missing' : '';

        const titleText = wordText || itemFieldValue(item, 'title') || wordTranslation || '—';
        const categoryText = itemFieldValue(item, 'category.name');

        let html = '';
        html += '<article class="ll-editor-hub-item" data-word-id="' + (parseInt(item.word_id, 10) || 0) + '">';
        html += '<header class="ll-editor-hub-item-head">';
        html += '<h2 class="ll-editor-hub-item-title">' + escapeHtml(titleText) + '</h2>';
        html += '<div class="ll-editor-hub-item-meta">' + escapeHtml(categoryText) + '</div>';
        html += '</header>';

        const imageUrl = itemFieldValue(item, 'image.url');
        const hasImage = imageUrl !== '';
        html += '<div class="ll-editor-hub-layout' + (hasImage ? '' : ' ll-editor-hub-layout--no-image') + '">';
        html += renderImagePanel(item, titleText);
        html += '<div class="ll-editor-hub-main">';

        html += renderMissingBadges(item);

        html += '<section class="ll-editor-hub-section">';

        html += '<div class="ll-editor-hub-field' + wordMissing + '">';
        html += '<label>' + escapeHtml(t('word', 'Word')) + '</label>';
        html += '<input type="text" data-word-field="word_text" value="' + escapeHtml(wordText) + '" />';
        html += '</div>';

        html += '<div class="ll-editor-hub-field' + translationMissing + '">';
        html += '<label>' + escapeHtml(t('translation', 'Translation')) + '</label>';
        html += '<input type="text" data-word-field="word_translation" value="' + escapeHtml(wordTranslation) + '" />';
        html += '</div>';

        html += '<div class="ll-editor-hub-field' + noteMissing + '">';
        html += '<label>' + escapeHtml(t('note', 'Note')) + '</label>';
        html += '<textarea rows="3" data-word-field="word_note">' + escapeHtml(wordNote) + '</textarea>';
        html += '</div>';

        html += '<div class="ll-editor-hub-field' + dictMissing + '">';
        html += '<label>' + escapeHtml(t('dictionary_entry', 'Dictionary entry')) + '</label>';
        html += '<input type="text" data-word-field="dictionary_entry_lookup" value="' + escapeHtml(dictTitle) + '" placeholder="' + escapeHtml(t('dictionary_placeholder', 'Type to select or create dictionary entry')) + '" autocomplete="off" />';
        html += '<input type="hidden" data-word-field="dictionary_entry_id" value="' + escapeHtml(dictId) + '" />';
        html += '</div>';

        html += '<div class="ll-editor-hub-field' + posMissing + '">';
        html += '<label>' + escapeHtml(t('part_of_speech', 'Part of speech')) + '</label>';
        html += '<select data-word-field="part_of_speech">' + buildSelectOptions(uiOptions.part_of_speech || [], posSlug) + '</select>';
        html += '</div>';

        if (uiOptions.flags && uiOptions.flags.gender) {
            html += '<div class="ll-editor-hub-field ll-editor-hub-field--noun' + genderMissing + '" data-field-wrap="grammatical_gender">';
            html += '<label>' + escapeHtml(t('gender', 'Gender')) + '</label>';
            html += '<select data-word-field="grammatical_gender">' + buildSelectOptions(uiOptions.gender || [], genderValue) + '</select>';
            html += '</div>';
        }

        if (uiOptions.flags && uiOptions.flags.plurality) {
            html += '<div class="ll-editor-hub-field ll-editor-hub-field--noun' + pluralityMissing + '" data-field-wrap="grammatical_plurality">';
            html += '<label>' + escapeHtml(t('plurality', 'Plurality')) + '</label>';
            html += '<select data-word-field="grammatical_plurality">' + buildSelectOptions(uiOptions.plurality || [], pluralityValue) + '</select>';
            html += '</div>';
        }

        if (uiOptions.flags && uiOptions.flags.verb_tense) {
            html += '<div class="ll-editor-hub-field ll-editor-hub-field--verb' + verbTenseMissing + '" data-field-wrap="verb_tense">';
            html += '<label>' + escapeHtml(t('verb_tense', 'Verb tense')) + '</label>';
            html += '<select data-word-field="verb_tense">' + buildSelectOptions(uiOptions.verb_tense || [], verbTenseValue) + '</select>';
            html += '</div>';
        }

        if (uiOptions.flags && uiOptions.flags.verb_mood) {
            html += '<div class="ll-editor-hub-field ll-editor-hub-field--verb' + verbMoodMissing + '" data-field-wrap="verb_mood">';
            html += '<label>' + escapeHtml(t('verb_mood', 'Verb mood')) + '</label>';
            html += '<select data-word-field="verb_mood">' + buildSelectOptions(uiOptions.verb_mood || [], verbMoodValue) + '</select>';
            html += '</div>';
        }

        html += '</section>';

        html += renderRecordings(item);
        html += '</div>';
        html += '</div>';
        html += '</article>';

        $card.html(html);

        applyPosFieldVisibility();
        initDictionaryLookup();
    }

    function applyPosFieldVisibility() {
        const pos = ($card.find('[data-word-field="part_of_speech"]').val() || '').toString();
        const isNoun = (pos === 'noun');
        const isVerb = (pos === 'verb');

        $card.find('[data-field-wrap="grammatical_gender"], [data-field-wrap="grammatical_plurality"]').each(function () {
            const $wrap = $(this);
            const $select = $wrap.find('select').first();
            if (isNoun) {
                $wrap.removeClass('is-hidden');
                $select.prop('disabled', false);
            } else {
                $wrap.addClass('is-hidden');
                $select.val('');
                $select.prop('disabled', true);
            }
        });

        $card.find('[data-field-wrap="verb_tense"], [data-field-wrap="verb_mood"]').each(function () {
            const $wrap = $(this);
            const $select = $wrap.find('select').first();
            if (isVerb) {
                $wrap.removeClass('is-hidden');
                $select.prop('disabled', false);
            } else {
                $wrap.addClass('is-hidden');
                $select.val('');
                $select.prop('disabled', true);
            }
        });
    }

    function initDictionaryLookup() {
        const $lookup = $card.find('[data-word-field="dictionary_entry_lookup"]').first();
        const $idInput = $card.find('[data-word-field="dictionary_entry_id"]').first();
        const item = getCurrentItem();
        if (!$lookup.length || !$idInput.length || !item) {
            return;
        }

        $lookup.on('input', function () {
            const typed = ($(this).val() || '').toString().trim();
            if (!typed) {
                $idInput.val('');
                return;
            }
            const selectedTitle = (item.dictionary_entry && item.dictionary_entry.title ? item.dictionary_entry.title : '').toString();
            if (typed !== selectedTitle) {
                $idInput.val('');
            }
        });

        if (!$.fn || typeof $.fn.autocomplete !== 'function') {
            return;
        }

        $lookup.autocomplete({
            minLength: 1,
            delay: 150,
            source: function (request, response) {
                $.post(ajaxUrl, {
                    action: 'll_tools_search_dictionary_entries',
                    nonce: nonce,
                    q: request.term || '',
                    limit: 20,
                    wordset_id: wordsetId,
                    word_id: parseInt(item.word_id, 10) || 0
                }).done(function (res) {
                    if (!res || res.success !== true) {
                        response([]);
                        return;
                    }

                    const entries = Array.isArray(res.data && res.data.entries)
                        ? res.data.entries
                        : [];

                    response(entries.map(function (entry) {
                        const title = (entry && entry.title ? entry.title : '').toString();
                        const subtitle = (entry && entry.subtitle ? entry.subtitle : '').toString();
                        const label = subtitle ? (title + ' - ' + subtitle) : title;
                        return {
                            label: label,
                            value: title,
                            entryId: parseInt(entry && entry.id ? entry.id : 0, 10) || 0
                        };
                    }));
                }).fail(function () {
                    response([]);
                });
            },
            select: function (event, ui) {
                if (!ui || !ui.item) {
                    $idInput.val('');
                    return;
                }
                $lookup.val(ui.item.value || '');
                $idInput.val(String(ui.item.entryId || 0));
                event.preventDefault();
            },
            focus: function (event, ui) {
                if (ui && ui.item) {
                    $lookup.val(ui.item.value || '');
                }
                event.preventDefault();
            }
        });
    }

    function collectPayload(item) {
        const wordId = parseInt(item && item.word_id ? item.word_id : 0, 10) || 0;

        const dictionaryEntryId = parseInt(($card.find('[data-word-field="dictionary_entry_id"]').val() || ''), 10) || 0;
        const dictionaryEntryTitle = ($card.find('[data-word-field="dictionary_entry_lookup"]').val() || '').toString();

        const recordings = [];
        $card.find('.ll-editor-hub-recording[data-recording-id]').each(function () {
            const $recording = $(this);
            const recordingId = parseInt($recording.attr('data-recording-id') || '0', 10) || 0;
            if (!recordingId) {
                return;
            }

            recordings.push({
                id: recordingId,
                text: ($recording.find('[data-recording-field="text"]').val() || '').toString(),
                translation: ($recording.find('[data-recording-field="translation"]').val() || '').toString(),
                ipa: ($recording.find('[data-recording-field="ipa"]').val() || '').toString()
            });
        });

        return {
            action: 'll_tools_word_grid_update_word',
            nonce: nonce,
            word_id: wordId,
            wordset_id: wordsetId,
            word_text: ($card.find('[data-word-field="word_text"]').val() || '').toString(),
            word_translation: ($card.find('[data-word-field="word_translation"]').val() || '').toString(),
            word_note: ($card.find('[data-word-field="word_note"]').val() || '').toString(),
            dictionary_entry_id: dictionaryEntryId,
            dictionary_entry_title: dictionaryEntryTitle,
            part_of_speech: ($card.find('[data-word-field="part_of_speech"]').val() || '').toString(),
            grammatical_gender: ($card.find('[data-word-field="grammatical_gender"]').val() || '').toString(),
            grammatical_plurality: ($card.find('[data-word-field="grammatical_plurality"]').val() || '').toString(),
            verb_tense: ($card.find('[data-word-field="verb_tense"]').val() || '').toString(),
            verb_mood: ($card.find('[data-word-field="verb_mood"]').val() || '').toString(),
            recordings: JSON.stringify(recordings)
        };
    }

    function applyDataset(payload, preferredIndex) {
        wordsetId = parseInt(payload && payload.wordset_id ? payload.wordset_id : wordsetId, 10) || wordsetId;
        categories = Array.isArray(payload && payload.categories) ? payload.categories.slice() : [];

        if (!categories.length) {
            selectedCategory = '';
            items = [];
            currentIndex = 0;
            syncCategoryOptions();
            renderCurrentItem();
            setStatus(t('all_done', 'All missing items are complete.'), 'success');
            return;
        }

        const availableSlugs = categories.map(function (row) {
            return (row && row.slug ? row.slug : '').toString();
        }).filter(Boolean);

        if (!selectedCategory || availableSlugs.indexOf(selectedCategory) === -1) {
            selectedCategory = (payload && payload.selected_category ? payload.selected_category : '').toString();
        }
        if (!selectedCategory || availableSlugs.indexOf(selectedCategory) === -1) {
            selectedCategory = availableSlugs[0] || '';
        }

        items = Array.isArray(payload && payload.items) ? payload.items.slice() : [];
        currentIndex = Math.max(0, Math.min(parseInt(preferredIndex, 10) || 0, Math.max(0, items.length - 1)));

        syncCategoryOptions();
        renderCurrentItem();
    }

    function resolvePreferredIndexAfterSave(nextItems, savedWordId, previousIndex) {
        const rows = Array.isArray(nextItems) ? nextItems : [];
        if (!rows.length) {
            return 0;
        }

        const parsedPrevious = parseInt(previousIndex, 10);
        let normalizedPrevious = Number.isFinite(parsedPrevious) ? parsedPrevious : 0;
        if (normalizedPrevious < 0) {
            normalizedPrevious = 0;
        }
        if (normalizedPrevious >= rows.length) {
            normalizedPrevious = 0;
        }

        const targetWordId = parseInt(savedWordId, 10) || 0;
        if (!targetWordId) {
            return normalizedPrevious;
        }

        const savedIndex = rows.findIndex(function (row) {
            const rowWordId = parseInt(row && row.word_id ? row.word_id : 0, 10) || 0;
            return rowWordId === targetWordId;
        });

        if (savedIndex === -1) {
            return normalizedPrevious;
        }

        if (savedIndex < rows.length - 1) {
            return savedIndex + 1;
        }

        return 0;
    }

    function loadCategory(categorySlug, preferredIndex, afterSaveState) {
        const slug = (categorySlug || '').toString();
        setBusy(true);
        setStatus(t('loading', 'Loading…'), 'info');

        $.post(ajaxUrl, {
            action: 'll_get_editor_hub_items',
            nonce: nonce,
            wordset_id: wordsetId,
            category: slug
        }).done(function (res) {
            if (!res || res.success !== true || !res.data) {
                setStatus(t('load_error', 'Unable to load missing items.'), 'error');
                return;
            }

            selectedCategory = (res.data.selected_category || slug || '').toString();
            let resolvedPreferredIndex = preferredIndex;
            if (afterSaveState && typeof afterSaveState === 'object') {
                resolvedPreferredIndex = resolvePreferredIndexAfterSave(
                    res.data.items,
                    afterSaveState.savedWordId,
                    afterSaveState.previousIndex
                );
            }
            applyDataset(res.data, resolvedPreferredIndex);

            if (!Array.isArray(items) || !items.length) {
                setStatus(t('no_items', 'No missing items in this category.'), 'info');
            } else {
                setStatus('', '');
            }
        }).fail(function () {
            setStatus(t('load_error', 'Unable to load missing items.'), 'error');
        }).always(function () {
            setBusy(false);
        });
    }

    function saveCurrentAndNext() {
        const item = getCurrentItem();
        if (!item) {
            setStatus(t('no_items', 'No missing items in this category.'), 'info');
            return;
        }

        const payload = collectPayload(item);
        const previousIndex = currentIndex;
        const savedWordId = parseInt(item && item.word_id ? item.word_id : 0, 10) || 0;

        setBusy(true);
        setStatus(t('saving', 'Saving…'), 'info');

        $.post(ajaxUrl, payload).done(function (res) {
            if (!res || res.success !== true) {
                setStatus(t('save_error', 'Unable to save changes.'), 'error');
                setBusy(false);
                return;
            }

            setStatus(t('saved', 'Saved.'), 'success');
            loadCategory(selectedCategory, previousIndex, {
                savedWordId: savedWordId,
                previousIndex: previousIndex
            });
        }).fail(function () {
            setStatus(t('save_error', 'Unable to save changes.'), 'error');
            setBusy(false);
        });
    }

    function skipToNext() {
        if (!Array.isArray(items) || !items.length) {
            setStatus(t('no_items', 'No missing items in this category.'), 'info');
            return;
        }

        if (currentIndex < items.length - 1) {
            currentIndex += 1;
        } else {
            currentIndex = 0;
        }

        renderCurrentItem();
        setStatus('', '');
    }

    $categorySelect.on('change', function () {
        selectedCategory = ($(this).val() || '').toString();
        currentIndex = 0;
        loadCategory(selectedCategory, currentIndex);
    });

    $card.on('change', '[data-word-field="part_of_speech"]', function () {
        applyPosFieldVisibility();
    });

    $saveBtn.on('click', function (e) {
        e.preventDefault();
        if (isBusy) {
            return;
        }
        saveCurrentAndNext();
    });

    $skipBtn.on('click', function (e) {
        e.preventDefault();
        if (isBusy) {
            return;
        }
        skipToNext();
    });

    $reloadBtn.on('click', function (e) {
        e.preventDefault();
        if (isBusy) {
            return;
        }
        loadCategory(selectedCategory, currentIndex);
    });

    normalizeHostContainers();
    syncCategoryOptions();
    renderCurrentItem();

    if (!items.length && selectedCategory) {
        loadCategory(selectedCategory, 0);
    } else if (!items.length) {
        setStatus(t('all_done', 'All missing items are complete.'), 'success');
    }
})(jQuery);
