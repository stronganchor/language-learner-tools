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

        if ($prompt.val() === 'text_title' || $prompt.val() === 'text_translation') {
            var opposite = ($prompt.val() === 'text_title') ? 'text_translation' : 'text_title';
            $option.find('option[value="' + $prompt.val() + '"]').prop('disabled', true);
            if ($option.val() === $prompt.val()) {
                $option.val(opposite);
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

        var promptIsImage = (prompt === 'image');
        var promptIsText = (prompt === 'text_translation' || prompt === 'text_title');
        var optionIsImage = (option === 'image');
        var optionIsText = (option === 'text' || option === 'text_translation' || option === 'text_title');

        return (promptIsImage && optionIsText) || (promptIsText && optionIsImage);
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

    function collectLargeImageNames(fileList, thresholdBytes) {
        var names = [];

        if (!fileList || !fileList.length || !(thresholdBytes > 0)) {
            return names;
        }

        $.each(fileList, function (_, file) {
            if (!file || typeof file.size !== 'number' || file.size <= thresholdBytes) {
                return;
            }

            if (typeof file.name === 'string' && $.trim(file.name) !== '') {
                names.push($.trim(file.name));
            }
        });

        return names;
    }

    function syncLargeImageWarning($form, cfg) {
        var thresholdBytes = parseInt(cfg.largeImageWarningBytes, 10);
        var $warning = $form.find('[data-ll-image-size-warning]');
        var $message = $form.find('[data-ll-image-size-warning-message]');
        var $files = $form.find('[data-ll-image-size-warning-files]');
        var input = $form.find('[data-ll-image-file-input]').get(0);

        if (!$warning.length || !$message.length || !$files.length || !input || !(thresholdBytes > 0)) {
            return;
        }

        var largeImageNames = collectLargeImageNames(input.files, thresholdBytes);
        if (!largeImageNames.length) {
            $message.text('');
            $files.text('');
            $warning.hide().attr('hidden', 'hidden');
            return;
        }

        var messageText = (cfg.largeImageWarningMessage || '').toString();
        var filesLabel = (cfg.largeImageWarningFilesLabel || '').toString();
        var filesText = largeImageNames.join(', ');

        $message.text(messageText);
        $files.text(filesLabel ? (filesLabel + ' ' + filesText) : filesText);
        $warning.show().removeAttr('hidden');
    }

    $(function () {
        var cfg = window.llImageUploadFormData || {};
        var autoCreateCategoryIds = toIdSet(cfg.autoCreateCategoryIds);

        $('[data-ll-image-upload-form]').each(function () {
            var $form = $(this);

            $form.on('change input', 'input[name="ll_word_categories[]"], [data-ll-new-category-title], [data-ll-new-category-prompt], [data-ll-new-category-option]', function () {
                syncFormState($form, autoCreateCategoryIds);
            });
            $form.on('change', '[data-ll-image-file-input]', function () {
                syncLargeImageWarning($form, cfg);
            });

            syncFormState($form, autoCreateCategoryIds);
            syncLargeImageWarning($form, cfg);
        });
    });
})(jQuery);
