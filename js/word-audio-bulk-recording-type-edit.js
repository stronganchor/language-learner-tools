(function ($) {
    'use strict';

    var bulkEditRow = null;
    var originalCheckedState = {};
    var replacementEnabled = false;

    function getConfig() {
        if (typeof llWordAudioBulkRecordingTypeEditData !== 'object' || !llWordAudioBulkRecordingTypeEditData) {
            return null;
        }
        return llWordAudioBulkRecordingTypeEditData;
    }

    function getStrings() {
        var cfg = getConfig();
        return (cfg && typeof cfg.strings === 'object' && cfg.strings) ? cfg.strings : {};
    }

    function getStatusEl() {
        return $('#bulk-edit [data-ll-word-audio-bulk-recording-type-status]').first();
    }

    function setStatus(message) {
        getStatusEl().text(message || '');
    }

    function getCheckboxes() {
        return $('#bulk-edit .ll-word-audio-bulk-recording-type-option');
    }

    function setCheckboxesEnabled(enabled) {
        getCheckboxes().prop('disabled', !enabled);
        replacementEnabled = !!enabled;
    }

    function clearHiddenInputs() {
        if (!bulkEditRow || bulkEditRow.length === 0) {
            return;
        }

        bulkEditRow.find('input[name="ll_bulk_recording_types_replace"]').remove();
        bulkEditRow.find('input[name="ll_bulk_recording_types_selected[]"]').remove();
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

    function resetChecklist() {
        originalCheckedState = {};
        getCheckboxes().each(function () {
            var termId = parseInt($(this).val(), 10);
            if (termId > 0) {
                originalCheckedState[termId] = false;
            }
            $(this).prop('checked', false);
        });
        setCheckboxesEnabled(false);
        clearHiddenInputs();
    }

    function hasSelectionChanged() {
        var changed = false;

        getCheckboxes().each(function () {
            var termId = parseInt($(this).val(), 10);
            if (termId <= 0) {
                return;
            }

            var isChecked = $(this).prop('checked');
            var wasChecked = !!originalCheckedState[termId];

            if (isChecked !== wasChecked) {
                changed = true;
                return false;
            }
        });

        return changed;
    }

    function updateHiddenInputs() {
        clearHiddenInputs();

        if (!replacementEnabled || !hasSelectionChanged()) {
            return;
        }

        bulkEditRow.append(
            $('<input>').attr({
                type: 'hidden',
                name: 'll_bulk_recording_types_replace',
                value: '1'
            })
        );

        getCheckboxes().each(function () {
            if (!$(this).prop('checked')) {
                return;
            }

            var termId = parseInt($(this).val(), 10);
            if (termId <= 0) {
                return;
            }

            bulkEditRow.append(
                $('<input>').attr({
                    type: 'hidden',
                    name: 'll_bulk_recording_types_selected[]',
                    value: termId
                })
            );
        });
    }

    function applyLoadedState(response) {
        var strings = getStrings();
        var data = response && response.data ? response.data : {};
        var common = Array.isArray(data.common) ? data.common : [];
        var commonSet = {};
        var allSame = !!data.allSame;

        common.forEach(function (termId) {
            var parsedId = parseInt(termId, 10);
            if (parsedId > 0) {
                commonSet[parsedId] = true;
            }
        });

        originalCheckedState = {};

        getCheckboxes().each(function () {
            var termId = parseInt($(this).val(), 10);
            var shouldCheck = allSame && !!commonSet[termId];
            originalCheckedState[termId] = shouldCheck;
            $(this).prop('checked', shouldCheck);
        });

        setCheckboxesEnabled(allSame);
        clearHiddenInputs();

        if (allSame) {
            setStatus(strings.ready || '');
            return;
        }

        setStatus(strings.notUniform || '');
    }

    function loadBulkRecordingTypeState() {
        var cfg = getConfig();
        var strings = getStrings();
        var postIds = getSelectedPostIds();

        resetChecklist();

        if (!cfg || !cfg.ajaxurl || !cfg.actionName || !cfg.nonce) {
            return;
        }

        if (postIds.length === 0) {
            setStatus(strings.idle || '');
            return;
        }

        setStatus(strings.loading || '');

        $.ajax({
            url: cfg.ajaxurl,
            type: 'POST',
            data: {
                action: cfg.actionName,
                nonce: cfg.nonce,
                post_ids: postIds
            }
        }).done(function (response) {
            if (!response || !response.success) {
                setStatus(strings.loadError || '');
                return;
            }

            applyLoadedState(response);
        }).fail(function () {
            setStatus(strings.loadError || '');
        });
    }

    $(document).ready(function () {
        var cfg = getConfig();

        bulkEditRow = $('#bulk-edit');
        if (!cfg || bulkEditRow.length === 0) {
            return;
        }

        setStatus(getStrings().idle || '');
        resetChecklist();

        getCheckboxes().off('change.llWordAudioBulkRecordingType').on('change.llWordAudioBulkRecordingType', function () {
            updateHiddenInputs();
        });

        if (typeof window.inlineEditPost !== 'undefined') {
            var wpBulkEdit = inlineEditPost.setBulk;
            inlineEditPost.setBulk = function () {
                wpBulkEdit.apply(this, arguments);
                loadBulkRecordingTypeState();
            };
        }

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    var target = $(mutation.target);
                    if (target.is('#bulk-edit') && target.is(':visible')) {
                        loadBulkRecordingTypeState();
                    }
                }
            });
        });

        observer.observe(bulkEditRow[0], {
            attributes: true,
            attributeFilter: ['class', 'style']
        });

        $(document).on('click', '#bulk-edit .button.save', function () {
            updateHiddenInputs();
        });
    });
})(jQuery);
