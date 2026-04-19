(function ($) {
    'use strict';

    function parseIdList(rawValue) {
        var ids = [];

        $.each((rawValue || '').toString().split(','), function (_, rawId) {
            var parsed = parseInt($.trim(rawId), 10);
            if (parsed > 0) {
                ids.push(parsed);
            }
        });

        return ids;
    }

    function toggleSection($element, show) {
        if (!$element.length) {
            return;
        }

        if (show) {
            $element.show().removeAttr('hidden');
        } else {
            $element.hide().attr('hidden', 'hidden');
        }
    }

    function getCategoryMode($form) {
        var $checked = $form.find('input[name="ll_category_mode"]:checked');
        if ($checked.length) {
            return ($checked.val() || 'existing').toString();
        }

        return 'existing';
    }

    function getScopeMode($form) {
        if ($form.find('[data-ll-wordset-scope-locked="1"]').length) {
            return 'single';
        }

        var $checked = $form.find('input[name="ll_wordset_scope_mode"]:checked');
        if ($checked.length) {
            return ($checked.val() || 'single').toString();
        }

        return 'single';
    }

    function getSelectedWordsetIds($form) {
        var ids = [];

        if ($form.find('[data-ll-wordset-scope-locked="1"]').length) {
            var lockedId = parseInt($form.find('input[name="ll_single_wordset_id"]').val(), 10);
            if (lockedId > 0) {
                ids.push(lockedId);
            }
            return ids;
        }

        if (getScopeMode($form) === 'multiple') {
            $form.find('[data-ll-multi-wordset]:checked').each(function () {
                var parsed = parseInt($(this).val(), 10);
                if (parsed > 0) {
                    ids.push(parsed);
                }
            });
            return ids;
        }

        var singleId = parseInt($form.find('[data-ll-single-wordset]').val(), 10);
        if (singleId > 0) {
            ids.push(singleId);
        }

        return ids;
    }

    function getSelectedWordsetNames($form) {
        var names = [];

        if ($form.find('[data-ll-wordset-scope-locked="1"]').length) {
            var lockedLabel = $.trim($form.find('[data-ll-wordset-scope-locked="1"] strong').first().text() || '');
            return lockedLabel ? [lockedLabel] : [];
        }

        if (getScopeMode($form) === 'multiple') {
            $form.find('[data-ll-multi-wordset]:checked').each(function () {
                var label = $.trim($(this).attr('data-ll-wordset-label') || '');
                if (label) {
                    names.push(label);
                }
            });
            return names;
        }

        var singleText = $.trim($form.find('[data-ll-single-wordset] option:selected').text() || '');
        if (parseInt($form.find('[data-ll-single-wordset]').val(), 10) > 0 && singleText) {
            names.push(singleText);
        }

        return names;
    }

    function optionMatchesScope($option, selectedWordsetIds) {
        var optionValue = parseInt($option.val(), 10);
        if (!(optionValue > 0)) {
            return true;
        }

        if (!selectedWordsetIds.length) {
            return true;
        }

        if (($option.attr('data-ll-category-shared') || '0').toString() === '1') {
            return true;
        }

        var optionWordsetIds = parseIdList($option.attr('data-ll-category-wordsets'));
        if (!optionWordsetIds.length) {
            return false;
        }

        var visible = false;
        $.each(optionWordsetIds, function (_, wordsetId) {
            if ($.inArray(wordsetId, selectedWordsetIds) !== -1) {
                visible = true;
                return false;
            }
            return true;
        });

        return visible;
    }

    function syncCategoryOptionVisibility($form) {
        var selectedWordsetIds = getSelectedWordsetIds($form);

        $.each([
            $form.find('[data-ll-existing-category]'),
            $form.find('[data-ll-new-category-parent]')
        ], function (_, $select) {
            if (!$select.length) {
                return;
            }

            var selectedValue = ($select.val() || '').toString();
            var selectedStillVisible = (selectedValue === '' || selectedValue === '0');

            $select.find('option').each(function () {
                var $option = $(this);
                var visible = optionMatchesScope($option, selectedWordsetIds);
                $option.prop('disabled', !visible);
                $option.prop('hidden', !visible);

                if (visible && selectedValue !== '' && selectedValue === ($option.val() || '').toString()) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible) {
                $select.val('0');
            }
        });
    }

    function syncTargetPreview($form) {
        var categoryMode = getCategoryMode($form);
        var categoryLabel = '';
        var wordsetNames = getSelectedWordsetNames($form);
        var $preview = $form.find('[data-ll-target-preview]');

        if (categoryMode === 'new') {
            categoryLabel = $.trim($form.find('[data-ll-new-category-title]').val() || '');
        } else {
            var $selectedOption = $form.find('[data-ll-existing-category] option:selected');
            if ($selectedOption.length && parseInt($selectedOption.val(), 10) > 0) {
                categoryLabel = $.trim($selectedOption.text() || '');
            }
        }

        if (!categoryLabel || !wordsetNames.length) {
            toggleSection($preview, false);
            return;
        }

        $preview.find('[data-ll-target-preview-category]').text(categoryLabel);
        $preview.find('[data-ll-target-preview-wordsets]').text(wordsetNames.join(', '));
        toggleSection($preview, true);
    }

    function syncPromptOptionRules($prompt, $option) {
        if (!$prompt.length || !$option.length) {
            return;
        }

        function promptHasAudio(value) {
            return value === 'audio' || value === 'audio_text_translation' || value === 'audio_text_title';
        }

        function promptHasImage(value) {
            return value === 'image' || value === 'image_text_translation' || value === 'image_text_title';
        }

        function getPromptTextType(value) {
            if (value === 'text_translation' || value === 'audio_text_translation' || value === 'image_text_translation') {
                return 'text_translation';
            }
            if (value === 'text_title' || value === 'audio_text_title' || value === 'image_text_title') {
                return 'text_title';
            }
            return '';
        }

        function getFallbackOption(value) {
            var promptTextType = getPromptTextType(value);
            if (promptTextType === 'text_title') {
                return 'text_translation';
            }
            if (promptTextType === 'text_translation') {
                return 'text_title';
            }
            return 'text_translation';
        }

        $option.find('option').prop('disabled', false);

        if (promptHasImage($prompt.val())) {
            $option.find('option[value="image"]').prop('disabled', true);
            if ($option.val() === 'image') {
                $option.val(getFallbackOption($prompt.val()));
            }
        }

        if (promptHasAudio($prompt.val())) {
            $option.find('option[value="audio"]').prop('disabled', true);
            if ($option.val() === 'audio') {
                $option.val(getFallbackOption($prompt.val()));
            }
        }

        var promptTextType = getPromptTextType($prompt.val());
        if (promptTextType) {
            var opposite = (promptTextType === 'text_title') ? 'text_translation' : 'text_title';
            $option.find('option[value="' + promptTextType + '"]').prop('disabled', true);
            if ($option.val() === promptTextType) {
                $option.val(opposite);
            }
        }
    }

    function syncFormState($form) {
        var matchMode = $form.find('[data-ll-match-existing]').is(':checked');
        var categoryMode = getCategoryMode($form);
        var scopeMode = getScopeMode($form);
        var scopeLocked = $form.find('[data-ll-wordset-scope-locked="1"]').length > 0;
        var $existingWrap = $form.find('[data-ll-category-existing-wrap]');
        var $newWrap = $form.find('[data-ll-new-category-wrap]');
        var $advancedWrap = $form.find('[data-ll-new-category-advanced]');
        var $singleWrap = $form.find('[data-ll-single-wordset-wrap]');
        var $multiWrap = $form.find('[data-ll-multi-wordset-wrap]');
        var $matchNote = $form.find('[data-ll-match-mode-note]');
        var $createOnly = $form.find('[data-ll-audio-create-only]');
        var $multipleMode = $form.find('input[name="ll_wordset_scope_mode"][value="multiple"]');
        var $prompt = $form.find('[data-ll-new-category-prompt]');
        var $option = $form.find('[data-ll-new-category-option]');

        syncPromptOptionRules($prompt, $option);

        if (matchMode && !scopeLocked) {
            if ($multipleMode.is(':checked')) {
                $form.find('input[name="ll_wordset_scope_mode"][value="single"]').prop('checked', true);
                scopeMode = 'single';
            }
            $multipleMode.prop('disabled', true);
        } else {
            $multipleMode.prop('disabled', false);
        }

        toggleSection($matchNote, matchMode);
        toggleSection($singleWrap, scopeLocked || scopeMode !== 'multiple');
        toggleSection($multiWrap, !scopeLocked && !matchMode && scopeMode === 'multiple');

        $createOnly.each(function () {
            toggleSection($(this), !matchMode);
        });

        toggleSection($existingWrap, !matchMode && categoryMode !== 'new');
        toggleSection($newWrap, !matchMode && categoryMode === 'new');
        toggleSection($advancedWrap, !matchMode && categoryMode === 'new');

        syncCategoryOptionVisibility($form);
        if (matchMode) {
            toggleSection($form.find('[data-ll-target-preview]'), false);
        } else {
            syncTargetPreview($form);
        }
    }

    $(function () {
        $('[data-ll-audio-upload-form]').each(function () {
            var $form = $(this);

            $form.on(
                'change input',
                '[data-ll-match-existing], input[name="ll_wordset_scope_mode"], [data-ll-single-wordset], [data-ll-multi-wordset], input[name="ll_category_mode"], [data-ll-existing-category], [data-ll-new-category-title], [data-ll-new-category-parent], [data-ll-new-category-prompt], [data-ll-new-category-option]',
                function () {
                    syncFormState($form);
                }
            );

            syncFormState($form);
        });
    });
})(jQuery);
