(function ($) {
    'use strict';

    var commonCategories = [];
    var originalCheckedState = {};
    var bulkEditRow = null;

    $(document).ready(function () {
        bulkEditRow = $('#bulk-edit');

        if (bulkEditRow.length === 0) {
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

        if (bulkEditRow[0]) {
            observer.observe(bulkEditRow[0], {
                attributes: true,
                attributeFilter: ['class', 'style']
            });
        }

        // Hook into WordPress's bulk edit function
        if (typeof window.inlineEditPost !== 'undefined') {
            var wpBulkEdit = inlineEditPost.setBulk;
            inlineEditPost.setBulk = function () {
                wpBulkEdit.apply(this, arguments);
                handleBulkEditShown();
            };
        }

        // Intercept the Update button click
        $(document).on('click', '#bulk-edit .button.save', function (e) {
            updateHiddenInputs();
        });
    });

    function handleBulkEditShown() {
        var postIds = [];
        $('tbody th.check-column input[type="checkbox"]:checked').each(function () {
            var id = parseInt($(this).val());
            if (id > 0) {
                postIds.push(id);
            }
        });

        if (postIds.length === 0) {
            return;
        }

        $.ajax({
            url: llWordsBulkEdit.ajaxurl,
            type: 'POST',
            data: {
                action: 'll_words_get_common_categories',
                nonce: llWordsBulkEdit.nonce,
                post_ids: postIds
            },
            success: function (response) {
                if (response.success && response.data.common) {
                    commonCategories = response.data.common;
                    originalCheckedState = {};

                    var checkboxes = $('#bulk-edit .categorychecklist input[type="checkbox"]');

                    if (checkboxes.length === 0) {
                        checkboxes = $('#bulk-edit input[name="tax_input[word-category][]"]');
                    }

                    checkboxes.each(function () {
                        var termId = parseInt($(this).val());
                        var isCommon = commonCategories.indexOf(termId) !== -1;

                        originalCheckedState[termId] = isCommon;
                        $(this).prop('checked', isCommon);
                    });

                    console.log('Pre-checked common categories:', commonCategories);

                    // Watch for checkbox changes and update hidden inputs
                    checkboxes.off('change.llbulk').on('change.llbulk', function () {
                        updateHiddenInputs();
                    });
                }
            }
        });
    }

    function updateHiddenInputs() {
        var categoriesToRemove = [];

        var checkboxes = $('#bulk-edit .categorychecklist input[type="checkbox"]');
        if (checkboxes.length === 0) {
            checkboxes = $('#bulk-edit input[name="tax_input[word-category][]"]');
        }

        checkboxes.each(function () {
            var termId = parseInt($(this).val());
            var isChecked = $(this).prop('checked');
            var wasOriginallyChecked = originalCheckedState[termId] || false;

            if (wasOriginallyChecked && !isChecked) {
                categoriesToRemove.push(termId);
            }
        });

        // Remove existing hidden inputs
        $('input[name="ll_bulk_categories_to_remove[]"]').remove();

        // Add new hidden inputs
        if (categoriesToRemove.length > 0) {
            console.log('Will remove categories:', categoriesToRemove);

            categoriesToRemove.forEach(function (termId) {
                $('#bulk-edit').append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'll_bulk_categories_to_remove[]',
                        value: termId
                    })
                );
            });
        } else {
            console.log('No categories to remove');
        }
    }

})(jQuery);