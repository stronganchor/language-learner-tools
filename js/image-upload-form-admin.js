(function ($) {
    'use strict';

    function toIdSet(ids) {
        var set = {};
        $.each(ids || [], function (_, rawId) {
            var parsed = parseInt(rawId, 10);
            if (parsed > 0) {
                set[parsed] = true;
            }
        });
        return set;
    }

    function syncPromptOptionRules($prompt, $option) {
        if (!$prompt.length || !$option.length) {
            return;
        }

        $option.find('option').prop('disabled', false);

        if ($prompt.val() === 'image') {
            $option.find('option[value="image"]').prop('disabled', true);
            if ($option.val() === 'image') {
                $option.val('text_translation');
            }
        }

        if ($prompt.val() === 'audio') {
            $option.find('option[value="audio"]').prop('disabled', true);
            if ($option.val() === 'audio') {
                $option.val('text_translation');
            }
        }
    }

    function isNewCategoryAutocreateEligible($form) {
        var title = $.trim($form.find('[data-ll-new-category-title]').val() || '');
        var prompt = ($form.find('[data-ll-new-category-prompt]').val() || 'audio').toString();
        var option = ($form.find('[data-ll-new-category-option]').val() || 'image').toString();

        if (!title) {
            return false;
        }

        return prompt === 'image' && (option === 'text_translation' || option === 'text_title');
    }

    function hasEligibleExistingCategory($form, autoCreateCategoryIds) {
        var found = false;
        $form.find('input[name="ll_word_categories[]"]:checked').each(function () {
            var categoryId = parseInt($(this).val(), 10);
            if (categoryId > 0 && autoCreateCategoryIds[categoryId]) {
                found = true;
                return false;
            }
            return true;
        });
        return found;
    }

    function syncFormState($form, autoCreateCategoryIds) {
        var hasNewTitle = $.trim($form.find('[data-ll-new-category-title]').val() || '') !== '';
        var creatingNew = hasNewTitle;

        var $existingWrap = $form.find('[data-ll-category-existing-wrap]');
        var $advancedWrap = $form.find('[data-ll-new-category-advanced]');
        var $wordsetWrap = $form.find('[data-ll-wordset-wrap]');
        var $prompt = $form.find('[data-ll-new-category-prompt]');
        var $option = $form.find('[data-ll-new-category-option]');

        syncPromptOptionRules($prompt, $option);

        $existingWrap.toggle(!creatingNew);
        $advancedWrap.toggle(creatingNew);

        var shouldShowWordset = creatingNew
            ? isNewCategoryAutocreateEligible($form)
            : hasEligibleExistingCategory($form, autoCreateCategoryIds);
        $wordsetWrap.toggle(shouldShowWordset);
    }

    $(function () {
        var cfg = window.llImageUploadFormData || {};
        var autoCreateCategoryIds = toIdSet(cfg.autoCreateCategoryIds);

        $('[data-ll-image-upload-form]').each(function () {
            var $form = $(this);

            $form.on('change input', 'input[name="ll_word_categories[]"], [data-ll-new-category-title], [data-ll-new-category-prompt], [data-ll-new-category-option]', function () {
                syncFormState($form, autoCreateCategoryIds);
            });

            syncFormState($form, autoCreateCategoryIds);
        });
    });
})(jQuery);
