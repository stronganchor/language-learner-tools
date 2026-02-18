(function ($) {
    'use strict';

    var commonCategories = [];
    var originalCheckedState = {};
    var bulkEditRow = null;

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

    function handleBulkEditShown() {
        var actionName = llBulkEditData && typeof llBulkEditData.actionName === 'string'
            ? llBulkEditData.actionName
            : '';
        var postIds = getSelectedPostIds();
        if (!actionName || postIds.length === 0) {
            return;
        }

        $.ajax({
            url: llBulkEditData.ajaxurl,
            type: 'POST',
            data: {
                action: actionName,
                nonce: llBulkEditData.nonce,
                post_ids: postIds
            },
            success: function (response) {
                if (!response || !response.success || !response.data || !Array.isArray(response.data.common)) {
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

                checkboxes.off('change.llbulk').on('change.llbulk', function () {
                    updateHiddenInputs();
                });
            }
        });
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
            inlineEditPost.setBulk = function () {
                wpBulkEdit.apply(this, arguments);
                handleBulkEditShown();
            };
        }

        $(document).on('click', '#bulk-edit .button.save', function () {
            updateHiddenInputs();
        });
    });
})(jQuery);
