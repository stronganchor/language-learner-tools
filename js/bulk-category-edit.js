(function ($) {
    'use strict';

    var commonCategories = [];
    var originalCheckedState = {};
    var bulkEditRow = null;
    var quickEditStylesInjected = false;
    var quickEditWordsetLookup = null;
    var bulkCategoryStateRequest = null;
    var bulkCategoryStateRequestGeneration = 0;
    var bulkCategoryStateSelectionKey = '';

    function getCategoryCheckboxes() {
        var checkboxes = $('#bulk-edit .categorychecklist input[type="checkbox"]');
        if (checkboxes.length === 0) {
            checkboxes = $('#bulk-edit input[name="tax_input[word-category][]"]');
        }
        return checkboxes;
    }

    function getSelectedPostIds() {
        var postIds = [];
        $('tbody th.check-column input[type="checkbox"]:checked').each(function () {
            var id = parseInt($(this).val(), 10);
            if (id > 0) {
                postIds.push(id);
            }
        });
        return postIds;
    }

    function getBulkSelectionKey(postIds) {
        return (Array.isArray(postIds) ? postIds : []).join(',');
    }

    function ownsBulkCategoryStateRequest(requestGeneration, selectionKey) {
        return requestGeneration === bulkCategoryStateRequestGeneration
            && selectionKey === bulkCategoryStateSelectionKey
            && selectionKey === getBulkSelectionKey(getSelectedPostIds());
    }

    function cancelBulkCategoryStateRequest() {
        bulkCategoryStateRequestGeneration += 1;
        bulkCategoryStateSelectionKey = '';

        if (bulkCategoryStateRequest && typeof bulkCategoryStateRequest.abort === 'function') {
            bulkCategoryStateRequest.abort();
        }
        bulkCategoryStateRequest = null;
    }

    function getBulkCategoryStateNotice() {
        var notice = bulkEditRow.find('.ll-bulk-category-state-notice').first();
        if (notice.length === 0) {
            notice = $('<p class="description ll-bulk-category-state-notice" role="status" aria-live="polite" />');
            var checklist = bulkEditRow.find('.categorychecklist').first();
            if (checklist.length > 0) {
                notice.insertBefore(checklist);
            } else {
                bulkEditRow.prepend(notice);
            }
        }
        return notice;
    }

    function setBulkCategoryStateUnavailable(state) {
        var checkboxes = getCategoryCheckboxes();
        var i18n = llBulkEditData && llBulkEditData.i18n ? llBulkEditData.i18n : {};

        commonCategories = [];
        originalCheckedState = {};
        bulkEditRow.find('input[name="ll_bulk_categories_to_remove[]"]').remove();

        checkboxes.off('change.llbulk').each(function () {
            var checkbox = $(this);
            if (typeof checkbox.data('llBulkCategoryOriginallyDisabled') === 'undefined') {
                checkbox.data('llBulkCategoryOriginallyDisabled', checkbox.prop('disabled'));
            }
            checkbox.prop('checked', false).prop('disabled', true);
        });

        bulkEditRow.find('.categorychecklist').attr('aria-busy', state === 'loading' ? 'true' : 'false');
        getBulkCategoryStateNotice()
            .text(state === 'loading'
                ? (i18n.categoryStateLoading || '')
                : (i18n.categoryStateError || ''))
            .toggle(state === 'loading' || state === 'error');
    }

    function enableBulkCategoryState() {
        getCategoryCheckboxes().each(function () {
            var checkbox = $(this);
            checkbox.prop('disabled', !!checkbox.data('llBulkCategoryOriginallyDisabled'));
        });
        bulkEditRow.find('.categorychecklist').attr('aria-busy', 'false');
        getBulkCategoryStateNotice().hide().text('');
    }

    function updateHiddenInputs() {
        var categoriesToRemove = [];
        var checkboxes = getCategoryCheckboxes();

        checkboxes.each(function () {
            var termId = parseInt($(this).val(), 10);
            var isChecked = $(this).prop('checked');
            var wasOriginallyChecked = !!originalCheckedState[termId];

            if (wasOriginallyChecked && !isChecked) {
                categoriesToRemove.push(termId);
            }
        });

        bulkEditRow.find('input[name="ll_bulk_categories_to_remove[]"]').remove();

        categoriesToRemove.forEach(function (termId) {
            bulkEditRow.append(
                $('<input>').attr({
                    type: 'hidden',
                    name: 'll_bulk_categories_to_remove[]',
                    value: termId
                })
            );
        });
    }

    function getQuickEditData() {
        if (
            typeof window.llBulkEditData !== 'object' ||
            !window.llBulkEditData ||
            window.llBulkEditData.postType !== 'words' ||
            typeof window.llWordsQuickEditData !== 'object' ||
            !window.llWordsQuickEditData
        ) {
            return null;
        }

        return window.llWordsQuickEditData;
    }

    function normalizeSearchText(value) {
        var text = $.trim(String(value || '')).toLowerCase();
        if (!text) {
            return '';
        }
        try {
            text = text.normalize('NFD');
        } catch (_) {}
        text = text.replace(/[\u0300-\u036f]/g, '');
        return text.replace(/[\u0131\u0142\u0111\u00f0\u00fe\u00e6\u0153\u00df]/g, function (match) {
            return {
                '\u0131': 'i',
                '\u0142': 'l',
                '\u0111': 'd',
                '\u00f0': 'd',
                '\u00fe': 'th',
                '\u00e6': 'ae',
                '\u0153': 'oe',
                '\u00df': 'ss'
            }[match] || match;
        });
    }

    function normalizeLookupToken(value) {
        return normalizeSearchText(value).replace(/\s+/g, ' ');
    }

    function getQuickEditWordsetLookup() {
        var data = getQuickEditData();
        if (!data) {
            return {};
        }

        if (quickEditWordsetLookup) {
            return quickEditWordsetLookup;
        }

        quickEditWordsetLookup = {};

        if (!Array.isArray(data.wordsets)) {
            return quickEditWordsetLookup;
        }

        data.wordsets.forEach(function (wordset) {
            var wordsetId = parseInt(wordset && wordset.id, 10);
            if (wordsetId <= 0) {
                return;
            }

            [wordset.name, wordset.slug].forEach(function (candidate) {
                var token = normalizeLookupToken(candidate);
                if (token) {
                    quickEditWordsetLookup[token] = wordsetId;
                }
            });
        });

        return quickEditWordsetLookup;
    }

    function ensureQuickEditStyles() {
        if (quickEditStylesInjected || !getQuickEditData()) {
            return;
        }

        $('<style id="ll-words-quick-edit-scope-styles">')
            .text(
                '.ll-quick-edit-category-scope-disabled .cat-checklist,' +
                '.ll-quick-edit-category-scope-disabled .categorychecklist{' +
                    'opacity:.48;' +
                    'pointer-events:none;' +
                '}' +
                '.ll-quick-edit-category-scope-notice{' +
                    'margin:6px 0 8px;' +
                    'color:#646970;' +
                '}' +
                '.ll-quick-edit-category-hidden{' +
                    'display:none;' +
                '}' +
                '.ll-quick-edit-category-out-of-scope > label{' +
                    'font-style:italic;' +
                    'opacity:.8;' +
                '}'
            )
            .appendTo('head');

        quickEditStylesInjected = true;
    }

    function getVisibleQuickEditRow() {
        return $('tr.inline-editor:visible').not('#bulk-edit').first();
    }

    function getQuickEditCategoryField(row) {
        var field = row.find('.inline-edit-categories').filter(function () {
            return $(this).find('ul.word-category-checklist, ul.cat-checklist.word-category-checklist').length > 0;
        }).first();

        if (field.length === 0) {
            field = row.find('.taxonomy-word-category').first();
        }

        return field;
    }

    function getQuickEditCategoryCheckboxes(row) {
        var checkboxes = row.find('ul.word-category-checklist input[type="checkbox"]');
        if (checkboxes.length === 0) {
            checkboxes = row.find('ul.cat-checklist.word-category-checklist input[type="checkbox"]');
        }
        return checkboxes;
    }

    function getQuickEditWordsetInput(row) {
        var input = row.find('textarea.tax_input_wordset').first();
        if (input.length === 0) {
            input = row.find('input.tax_input_wordset').first();
        }
        return input;
    }

    function getSelectedQuickEditWordsetIds(row) {
        var input = getQuickEditWordsetInput(row);
        var lookup = getQuickEditWordsetLookup();
        var selectedIds = {};

        if (input.length === 0) {
            return [];
        }

        String(input.val() || '')
            .split(',')
            .forEach(function (candidate) {
                var token = normalizeLookupToken(candidate);
                var wordsetId = token ? parseInt(lookup[token], 10) : 0;
                if (wordsetId > 0) {
                    selectedIds[wordsetId] = true;
                }
            });

        return Object.keys(selectedIds).map(function (key) {
            return parseInt(key, 10);
        }).filter(function (value) {
            return value > 0;
        });
    }

    function getQuickEditAllowedCategoryLookup(row) {
        var data = getQuickEditData();
        var selectedWordsetIds = getSelectedQuickEditWordsetIds(row);
        var allowed = {};

        if (!data || !selectedWordsetIds.length || typeof data.categoryIdsByWordset !== 'object' || !data.categoryIdsByWordset) {
            return allowed;
        }

        selectedWordsetIds.forEach(function (wordsetId) {
            var scopedIds = data.categoryIdsByWordset[String(wordsetId)];
            if (!Array.isArray(scopedIds)) {
                return;
            }

            scopedIds.forEach(function (termId) {
                termId = parseInt(termId, 10);
                if (termId > 0) {
                    allowed[termId] = true;
                }
            });
        });

        return allowed;
    }

    function ensureQuickEditScopeNotice(field) {
        var notice = field.find('.ll-quick-edit-category-scope-notice').first();
        if (notice.length > 0) {
            return notice;
        }

        notice = $('<p class="description ll-quick-edit-category-scope-notice" />').prependTo(field);
        return notice;
    }

    function refreshQuickEditCategoryScope(row) {
        var data = getQuickEditData();
        var field;
        var checkboxes;
        var allowedLookup;
        var selectedWordsetIds;
        var disabled;
        var notice;
        var strings;

        if (!data || !row || row.length === 0) {
            return;
        }

        ensureQuickEditStyles();

        field = getQuickEditCategoryField(row);
        checkboxes = getQuickEditCategoryCheckboxes(row);
        if (field.length === 0 || checkboxes.length === 0) {
            return;
        }

        selectedWordsetIds = getSelectedQuickEditWordsetIds(row);
        allowedLookup = getQuickEditAllowedCategoryLookup(row);
        disabled = selectedWordsetIds.length === 0;
        strings = data.strings || {};

        field.toggleClass('ll-quick-edit-category-scope-disabled', disabled);
        field.attr('aria-disabled', disabled ? 'true' : 'false');

        notice = ensureQuickEditScopeNotice(field);
        if (disabled) {
            notice.text(strings.selectWordsetNotice || '');
            notice.show();
        } else {
            notice.hide();
        }

        checkboxes.each(function () {
            var checkbox = $(this);
            var termId = parseInt(checkbox.val(), 10);
            var item = checkbox.closest('li');
            var isChecked = checkbox.prop('checked');
            var isAllowed = !!allowedLookup[termId];
            var showItem = disabled || isAllowed || isChecked;
            var isOutOfScope = !disabled && !isAllowed && isChecked;

            item.toggleClass('ll-quick-edit-category-hidden', !showItem);
            item.toggleClass('ll-quick-edit-category-out-of-scope', isOutOfScope);

            if (isOutOfScope && strings.outOfScopeCategoryTitle) {
                item.attr('title', strings.outOfScopeCategoryTitle);
            } else {
                item.removeAttr('title');
            }
        });
    }

    function bindQuickEditScopeHandlers(row) {
        var wordsetInput;
        var categoryCheckboxes;

        if (!getQuickEditData() || !row || row.length === 0) {
            return;
        }

        wordsetInput = getQuickEditWordsetInput(row);
        categoryCheckboxes = getQuickEditCategoryCheckboxes(row);

        wordsetInput.off('.llquickscope').on('input.llquickscope change.llquickscope', function () {
            refreshQuickEditCategoryScope(row);
        });
        categoryCheckboxes.off('.llquickscope').on('change.llquickscope', function () {
            refreshQuickEditCategoryScope(row);
        });
    }

    function handleQuickEditShown() {
        var row = getVisibleQuickEditRow();
        if (row.length === 0) {
            return;
        }

        bindQuickEditScopeHandlers(row);
        refreshQuickEditCategoryScope(row);
    }

    function handleBulkEditShown() {
        var actionName = llBulkEditData && typeof llBulkEditData.actionName === 'string'
            ? llBulkEditData.actionName
            : '';
        var postIds = getSelectedPostIds();
        var selectionKey = getBulkSelectionKey(postIds);

        if (
            bulkCategoryStateRequest
            && selectionKey
            && selectionKey === bulkCategoryStateSelectionKey
        ) {
            return;
        }

        cancelBulkCategoryStateRequest();
        if (!actionName || postIds.length === 0) {
            setBulkCategoryStateUnavailable('error');
            return;
        }

        setBulkCategoryStateUnavailable('loading');

        bulkCategoryStateRequestGeneration += 1;
        var requestGeneration = bulkCategoryStateRequestGeneration;
        bulkCategoryStateSelectionKey = selectionKey;
        var request = $.ajax({
            url: llBulkEditData.ajaxurl,
            type: 'POST',
            data: {
                action: actionName,
                nonce: llBulkEditData.nonce,
                post_ids: postIds
            }
        }).done(function (response) {
            if (!ownsBulkCategoryStateRequest(requestGeneration, selectionKey)) {
                return;
            }
            if (!response || !response.success || !response.data || !Array.isArray(response.data.common)) {
                setBulkCategoryStateUnavailable('error');
                return;
            }

            commonCategories = response.data.common;
            originalCheckedState = {};

            var checkboxes = getCategoryCheckboxes();
            checkboxes.each(function () {
                var termId = parseInt($(this).val(), 10);
                var isCommon = commonCategories.indexOf(termId) !== -1;

                originalCheckedState[termId] = isCommon;
                $(this).prop('checked', isCommon);
            });

            enableBulkCategoryState();

            checkboxes.off('change.llbulk').on('change.llbulk', function () {
                updateHiddenInputs();
            });
        }).fail(function () {
            if (!ownsBulkCategoryStateRequest(requestGeneration, selectionKey)) {
                return;
            }
            setBulkCategoryStateUnavailable('error');
        }).always(function () {
            if (
                requestGeneration !== bulkCategoryStateRequestGeneration
                || request !== bulkCategoryStateRequest
            ) {
                return;
            }
            bulkCategoryStateRequest = null;
        });
        bulkCategoryStateRequest = request;
    }

    $(document).ready(function () {
        bulkEditRow = $('#bulk-edit');
        if (bulkEditRow.length === 0 || typeof llBulkEditData !== 'object') {
            return;
        }

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    var target = $(mutation.target);
                    if (target.is('#bulk-edit') && target.is(':visible')) {
                        handleBulkEditShown();
                    }
                }
            });
        });

        observer.observe(bulkEditRow[0], {
            attributes: true,
            attributeFilter: ['class', 'style']
        });

        if (typeof window.inlineEditPost !== 'undefined') {
            var wpBulkEdit = inlineEditPost.setBulk;
            var wpQuickEdit = inlineEditPost.edit;
            inlineEditPost.setBulk = function () {
                wpBulkEdit.apply(this, arguments);
                handleBulkEditShown();
            };
            inlineEditPost.edit = function () {
                wpQuickEdit.apply(this, arguments);
                window.setTimeout(handleQuickEditShown, 0);
            };
        }

        $(document).on('click', '#bulk-edit .button.save', function () {
            updateHiddenInputs();
        });

        $(document).on('click', '.editinline', function () {
            window.setTimeout(handleQuickEditShown, 0);
        });
    });
})(jQuery);
