(function ($) {
    'use strict';

    const cfg = window.llToolsVocabLessonData || {};
    const ajaxUrl = (cfg.ajaxUrl || '').toString();
    const gridCfg = (cfg.grid && typeof cfg.grid === 'object') ? cfg.grid : cfg;
    const gridAction = (gridCfg.action || cfg.action || 'll_tools_get_vocab_lesson_grid').toString();
    const gridI18n = (gridCfg.i18n && typeof gridCfg.i18n === 'object')
        ? gridCfg.i18n
        : ((cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {});
    const titleCfg = (cfg.titleEditor && typeof cfg.titleEditor === 'object') ? cfg.titleEditor : {};
    const titleI18n = (titleCfg.i18n && typeof titleCfg.i18n === 'object') ? titleCfg.i18n : {};
    const categorySettingsCfg = (cfg.categorySettings && typeof cfg.categorySettings === 'object') ? cfg.categorySettings : {};
    const categorySettingsAction = (categorySettingsCfg.action || 'll_tools_save_vocab_lesson_category_settings').toString();
    const categorySettingsI18n = (categorySettingsCfg.i18n && typeof categorySettingsCfg.i18n === 'object') ? categorySettingsCfg.i18n : {};

    function getGridMessage(key, fallback) {
        const value = gridI18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function getTitleMessage(key, fallback) {
        const value = titleI18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function getCategorySettingsMessage(key, fallback) {
        const value = categorySettingsI18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function setStatus($shell, message) {
        const $status = $shell.find('[data-ll-vocab-lesson-grid-status]').first();
        if (!$status.length) { return; }
        $status.text((message || '').toString());
    }

    function setLoading($shell, isLoading) {
        const loading = !!isLoading;
        $shell.toggleClass('is-loading', loading);
        $shell.attr('aria-busy', loading ? 'true' : 'false');
        if (loading) {
            $shell.data('llGridLoading', true);
        } else {
            $shell.removeData('llGridLoading');
        }
    }

    function hideFeedback($shell) {
        const $feedback = $shell.find('[data-ll-vocab-lesson-grid-feedback]').first();
        if (!$feedback.length) { return; }
        $feedback.empty().attr('hidden', 'hidden').removeClass('is-error');
    }

    function showFeedback($shell, message, includeRetry) {
        const $feedback = $shell.find('[data-ll-vocab-lesson-grid-feedback]').first();
        if (!$feedback.length) { return; }

        $feedback.empty().removeAttr('hidden').addClass('is-error');
        $('<p>', {
            class: 'll-vocab-lesson-grid-feedback__text',
            text: (message || '').toString()
        }).appendTo($feedback);

        if (includeRetry) {
            $('<button>', {
                type: 'button',
                class: 'll-study-btn tiny ll-vocab-lesson-grid-feedback__retry',
                'data-ll-vocab-lesson-grid-retry': '1',
                text: getGridMessage('retry', 'Retry')
            }).appendTo($feedback);
        }
    }

    function readAjaxMessage(response, fallback) {
        if (response && response.data) {
            if (typeof response.data === 'string' && response.data) {
                return response.data;
            }
            if (typeof response.data.message === 'string' && response.data.message) {
                return response.data.message;
            }
        }
        if (response && typeof response.message === 'string' && response.message) {
            return response.message;
        }
        return fallback;
    }

    function resetPanelPosition($panel) {
        if (!$panel || !$panel.length) { return; }
        $panel.each(function () {
            if (!this || !this.style) { return; }
            this.style.removeProperty('left');
            this.style.removeProperty('right');
        });
    }

    function clampPanelToViewport($panel) {
        if (!$panel || !$panel.length) { return; }

        const panel = $panel.get(0);
        if (!panel || !panel.style || typeof panel.getBoundingClientRect !== 'function') {
            return;
        }

        panel.style.setProperty('left', 'auto', 'important');
        panel.style.setProperty('right', '0px', 'important');

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        if (viewportWidth <= 0) {
            return;
        }

        const safePadding = 8;
        const rect = panel.getBoundingClientRect();
        if (!rect || rect.width <= 0) {
            return;
        }

        const maxRight = viewportWidth - safePadding;
        let rightOffset = 0;

        if (rect.left < safePadding) {
            rightOffset -= (safePadding - rect.left);
        }

        if (rect.right > maxRight) {
            rightOffset += (rect.right - maxRight);
        }

        if (Math.abs(rightOffset) > 0.5) {
            panel.style.setProperty('right', String(rightOffset) + 'px', 'important');
        }
    }

    function queuePanelViewportClamp($panel) {
        if (!$panel || !$panel.length) { return; }

        const run = function () {
            clampPanelToViewport($panel);
        };

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                run();
                window.requestAnimationFrame(run);
            });
            return;
        }

        window.setTimeout(run, 0);
    }

    function syncElementAttributes(source, target) {
        Array.from(target.attributes).forEach(function (attr) {
            target.removeAttribute(attr.name);
        });
        Array.from(source.attributes).forEach(function (attr) {
            target.setAttribute(attr.name, attr.value);
        });
    }

    function renderGridMarkup($shell, html) {
        const targetGrid = $shell.find('[data-ll-word-grid]').first().get(0);
        if (!targetGrid) { return false; }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = (html || '').toString();
        const sourceGrid = wrapper.querySelector('[data-ll-word-grid]');

        if (sourceGrid) {
            syncElementAttributes(sourceGrid, targetGrid);
            targetGrid.innerHTML = sourceGrid.innerHTML;
        } else {
            targetGrid.className = 'word-grid ll-word-grid ll-vocab-lesson-grid-empty';
            targetGrid.removeAttribute('style');
            targetGrid.innerHTML = (html || '').toString();
        }

        try {
            $(document).trigger('lltools:word-grid-rendered', [{ scope: $(targetGrid) }]);
        } catch (error) {
            console.error('LL Tools: lesson grid rendered event failed.', error);
        }
        return true;
    }

    function loadLessonGrid($shell) {
        if (!$shell.length || $shell.data('llGridLoading')) {
            return;
        }

        const lessonId = parseInt($shell.attr('data-lesson-id'), 10) || 0;
        const nonce = ($shell.attr('data-nonce') || '').toString();
        if (!ajaxUrl || !gridAction || !lessonId || !nonce) {
            showFeedback($shell, getGridMessage('error', 'Unable to load this lesson right now.'), true);
            setLoading($shell, false);
            return;
        }

        hideFeedback($shell);
        setLoading($shell, true);
        setStatus($shell, getGridMessage('loading', 'Loading lesson words...'));

        $.post(ajaxUrl, {
            action: gridAction,
            lesson_id: lessonId,
            nonce: nonce
        }).done(function (response) {
            if (!response || response.success !== true || !response.data || typeof response.data.html !== 'string') {
                showFeedback(
                    $shell,
                    readAjaxMessage(response, getGridMessage('error', 'Unable to load this lesson right now.')),
                    true
                );
                return;
            }

            renderGridMarkup($shell, response.data.html);
            hideFeedback($shell);
            setStatus($shell, getGridMessage('loaded', 'Lesson words loaded.'));
        }).fail(function (jqXHR) {
            const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            showFeedback(
                $shell,
                readAjaxMessage(response, getGridMessage('error', 'Unable to load this lesson right now.')),
                true
            );
        }).always(function () {
            setLoading($shell, false);
        });
    }

    function setPrintSettingsOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        const $panel = $wrap.find('.ll-vocab-lesson-print-panel').first();
        const $button = $wrap.find('.ll-vocab-lesson-print-trigger').first();
        if (!$panel.length || !$button.length) {
            return;
        }

        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);

        if (open) {
            queuePanelViewportClamp($panel);
        } else {
            resetPanelPosition($panel);
        }
    }

    function closePrintSettings(except) {
        const $settings = $('.ll-vocab-lesson-print-settings');
        if (!$settings.length) {
            return;
        }

        $settings.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) {
                return;
            }
            setPrintSettingsOpen($wrap, false);
        });
    }

    function closeBulkEditors() {
        $('[data-ll-word-grid-bulk]').each(function () {
            const $wrap = $(this);
            $wrap.removeClass('is-open');
            $wrap.find('.ll-vocab-lesson-bulk-panel').first().attr('aria-hidden', 'true');
            $wrap.find('.ll-vocab-lesson-bulk-button').first().attr('aria-expanded', 'false');
        });
        $('body').removeClass('ll-vocab-lesson-bulk-open');
    }

    function setCategorySettingsOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        const $panel = $wrap.find('.ll-vocab-lesson-category-settings-panel').first();
        const $button = $wrap.find('.ll-vocab-lesson-category-settings-trigger').first();
        if (!$panel.length || !$button.length) {
            return;
        }

        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);
        $('body').toggleClass('ll-vocab-lesson-category-settings-open', $('.ll-vocab-lesson-category-settings-panel[aria-hidden="false"]').length > 0);

        if (open) {
            closePrintSettings();
            closeBulkEditors();
        } else {
            resetPanelPosition($panel);
        }
    }

    function closeCategorySettings(except) {
        const $settings = $('.ll-vocab-lesson-category-settings');
        if (!$settings.length) {
            return;
        }

        $settings.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) {
                return;
            }
            setCategorySettingsOpen($wrap, false);
        });
    }

    function syncCategoryLineupInput($scope) {
        if (!$scope || !$scope.length) {
            return;
        }

        const $list = $scope.find('[data-ll-category-lineup-list]').first();
        const $input = $scope.find('[data-ll-category-lineup-order-input]').first();
        if (!$list.length || !$input.length) {
            return;
        }

        const ids = [];
        $list.children('[data-ll-category-lineup-item]').each(function () {
            const id = parseInt($(this).attr('data-word-id'), 10);
            if (id > 0 && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });

        $input.val(ids.join(','));
    }

    function moveCategoryLineupItem($item, direction) {
        if (!$item || !$item.length) {
            return;
        }

        if (direction === 'up') {
            const $prev = $item.prev('[data-ll-category-lineup-item]');
            if ($prev.length) {
                $item.insertBefore($prev);
            }
        } else if (direction === 'down') {
            const $next = $item.next('[data-ll-category-lineup-item]');
            if ($next.length) {
                $item.insertAfter($next);
            }
        }

        syncCategoryLineupInput($item.closest('.ll-vocab-lesson-category-settings-panel'));
    }

    function getCategorySettingsState($panel) {
        if (!$panel || !$panel.length) { return null; }

        let state = $panel.data('llCategorySettingsState');
        if (state && typeof state === 'object') {
            return state;
        }

        state = {
            inFlight: false,
            queued: false,
            saveTimerId: 0,
            resetTimerId: 0
        };
        $panel.data('llCategorySettingsState', state);
        return state;
    }

    function clearCategorySettingsSaveTimer($panel) {
        const state = getCategorySettingsState($panel);
        if (!state || !state.saveTimerId) { return; }
        window.clearTimeout(state.saveTimerId);
        state.saveTimerId = 0;
    }

    function clearCategorySettingsResetTimer($panel) {
        const state = getCategorySettingsState($panel);
        if (!state || !state.resetTimerId) { return; }
        window.clearTimeout(state.resetTimerId);
        state.resetTimerId = 0;
    }

    function setCategorySettingsStatus($panel, statusState, message) {
        const $status = $panel.find('[data-ll-category-settings-status]').first();
        const $actions = $panel.find('[data-ll-category-settings-actions]').first();
        if (!$status.length) { return; }

        const nextState = ['saving', 'saved', 'error'].indexOf((statusState || '').toString()) !== -1
            ? statusState.toString()
            : 'idle';
        const text = (message || '').toString();
        const $message = $status.find('[data-ll-category-settings-status-message]').first();

        $status.attr('data-state', nextState);
        if (text) {
            $status.attr('aria-label', text).attr('title', text);
        } else {
            $status.removeAttr('aria-label').removeAttr('title');
        }

        if (nextState === 'idle') {
            $status.attr('hidden', 'hidden');
            if ($actions.length) {
                $actions.attr('hidden', 'hidden');
            }
        } else {
            $status.removeAttr('hidden');
            if ($actions.length) {
                $actions.removeAttr('hidden');
            }
        }

        if ($message.length) {
            if (text) {
                $message.text(text).removeAttr('hidden');
            } else {
                $message.text('').attr('hidden', 'hidden');
            }
        }
    }

    function scheduleCategorySettingsStatusReset($panel, delayMs) {
        const state = getCategorySettingsState($panel);
        if (!state) { return; }

        clearCategorySettingsResetTimer($panel);
        state.resetTimerId = window.setTimeout(function () {
            state.resetTimerId = 0;
            setCategorySettingsStatus($panel, 'idle', '');
        }, Math.max(0, parseInt(delayMs, 10) || 0));
    }

    function setCategorySettingsBusy($panel, isBusy) {
        const busy = !!isBusy;
        $panel.toggleClass('is-autosaving', busy);
        $panel.attr('aria-busy', busy ? 'true' : 'false');
    }

    function performCategorySettingsSave($panel) {
        const state = getCategorySettingsState($panel);
        const form = $panel && $panel.length ? $panel.get(0) : null;
        if (!state || !form) { return; }

        if (state.inFlight) {
            state.queued = true;
            return;
        }

        if (!ajaxUrl || !categorySettingsAction || typeof window.FormData !== 'function') {
            setCategorySettingsStatus(
                $panel,
                'error',
                getCategorySettingsMessage('error', 'Unable to save category settings right now.')
            );
            return;
        }

        syncCategoryLineupInput($panel);
        const formData = new window.FormData(form);
        formData.set('action', categorySettingsAction);

        state.inFlight = true;
        state.queued = false;
        clearCategorySettingsResetTimer($panel);
        setCategorySettingsBusy($panel, true);
        setCategorySettingsStatus($panel, 'saving', getCategorySettingsMessage('saving', 'Saving changes...'));

        let saveSucceeded = false;
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (response) {
            if (!response || response.success !== true) {
                state.queued = false;
                setCategorySettingsStatus(
                    $panel,
                    'error',
                    readAjaxMessage(response, getCategorySettingsMessage('error', 'Unable to save category settings right now.'))
                );
                return;
            }

            saveSucceeded = true;
            setCategorySettingsStatus(
                $panel,
                'saved',
                readAjaxMessage(response, getCategorySettingsMessage('saved', 'Changes saved.'))
            );
        }).fail(function (jqXHR) {
            const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            state.queued = false;
            setCategorySettingsStatus(
                $panel,
                'error',
                readAjaxMessage(response, getCategorySettingsMessage('error', 'Unable to save category settings right now.'))
            );
        }).always(function () {
            state.inFlight = false;
            setCategorySettingsBusy($panel, false);

            if (state.queued) {
                performCategorySettingsSave($panel);
                return;
            }

            if (saveSucceeded) {
                scheduleCategorySettingsStatusReset($panel, 1800);
            }
        });
    }

    function scheduleCategorySettingsSave($panel, delayMs) {
        const state = getCategorySettingsState($panel);
        if (!state) { return; }

        clearCategorySettingsSaveTimer($panel);
        state.saveTimerId = window.setTimeout(function () {
            state.saveTimerId = 0;
            performCategorySettingsSave($panel);
        }, typeof delayMs === 'number' ? delayMs : 500);
    }

    function saveCategorySettingsNow($panel) {
        clearCategorySettingsSaveTimer($panel);
        performCategorySettingsSave($panel);
    }

    function isCategorySettingsAutosaveField(target) {
        if (!target || !target.matches || $(target).closest('[data-ll-prereq-editor]').length) {
            return false;
        }

        const name = (target.getAttribute('name') || '').toString();
        return [
            'll_vocab_lesson_quiz_prompt_type',
            'll_vocab_lesson_quiz_option_type',
            'll_vocab_lesson_grid_text_visibility',
            'll_vocab_lesson_category_enabled_games[]',
            'll_vocab_lesson_desired_recording_types[]',
            'll_vocab_lesson_category_lineup_direction'
        ].indexOf(name) !== -1;
    }

    function setTitleStatus($editor, message, state) {
        const $status = $editor.find('[data-ll-vocab-lesson-title-status]').first();
        if (!$status.length) { return; }

        const text = (message || '').toString();
        if (!text) {
            $status
                .text('')
                .attr('hidden', 'hidden')
                .removeClass('is-error is-success');
            return;
        }

        $status
            .text(text)
            .removeAttr('hidden')
            .removeClass('is-error is-success');

        if (state === 'error') {
            $status.addClass('is-error');
        } else if (state === 'success') {
            $status.addClass('is-success');
        }
    }

    function clearTitleTimer($editor) {
        const timer = $editor.data('llTitleTimer');
        if (timer) {
            window.clearTimeout(timer);
            $editor.removeData('llTitleTimer');
        }
    }

    function setTitleSaving($editor, isSaving) {
        const saving = !!isSaving;
        $editor.toggleClass('is-saving', saving);
        $editor.data('llTitleSaving', saving);
        $editor
            .find('[data-ll-vocab-lesson-title-input], [data-ll-vocab-lesson-title-save], [data-ll-vocab-lesson-title-cancel]')
            .prop('disabled', saving);
    }

    function openTitleEditor($editor) {
        if (!$editor.length) { return; }

        clearTitleTimer($editor);
        setTitleStatus($editor, '');
        $editor.addClass('is-editing');
        $editor.find('[data-ll-vocab-lesson-title-form]').first().prop('hidden', false);
        $editor.find('[data-ll-vocab-lesson-title-trigger]').first().attr('aria-expanded', 'true');

        const $input = $editor.find('[data-ll-vocab-lesson-title-input]').first();
        if ($input.length) {
            window.requestAnimationFrame(function () {
                $input.trigger('focus').trigger('select');
            });
        }
    }

    function closeTitleEditor($editor, restoreValue) {
        if (!$editor.length) { return; }

        clearTitleTimer($editor);
        setTitleStatus($editor, '');
        if (restoreValue) {
            const $input = $editor.find('[data-ll-vocab-lesson-title-input]').first();
            if ($input.length) {
                const currentValue = ($input.attr('value') || '').toString();
                $input.val(currentValue);
            }
        }

        $editor.removeClass('is-editing');
        $editor.find('[data-ll-vocab-lesson-title-form]').first().prop('hidden', true);
        $editor.find('[data-ll-vocab-lesson-title-trigger]').first().attr('aria-expanded', 'false');
    }

    function syncTitleEditor($editor, data) {
        const payload = (data && typeof data === 'object') ? data : {};
        const displayName = (payload.display_name || '').toString();
        const editValue = (payload.edit_value || displayName).toString();
        const categoryName = (payload.category_name || displayName).toString();
        const field = (payload.field || '').toString();

        if (displayName) {
            $editor.find('[data-ll-vocab-lesson-title-text]').text(displayName);
        }

        const $input = $editor.find('[data-ll-vocab-lesson-title-input]').first();
        if ($input.length) {
            $input.val(editValue);
            $input.attr('value', editValue);
            if ($input.get(0)) {
                $input.get(0).defaultValue = editValue;
            }
        }

        if (field) {
            $editor.attr('data-current-field', field);
        }

        if (categoryName) {
            const $scope = $editor.closest('[data-ll-vocab-lesson], .ll-vocab-lesson-page');
            $scope.find('.ll-vocab-lesson-mode-button').each(function () {
                $(this)
                    .attr('data-ll-open-cat', categoryName)
                    .attr('data-category', categoryName);
            });
        }
    }

    function submitTitleEditor($editor) {
        if (!$editor.length || $editor.data('llTitleSaving')) {
            return;
        }

        const lessonId = parseInt($editor.attr('data-lesson-id'), 10) || 0;
        const categoryId = parseInt($editor.attr('data-category-id'), 10) || 0;
        const action = ($editor.attr('data-action') || '').toString();
        const nonce = ($editor.attr('data-nonce') || '').toString();
        const $input = $editor.find('[data-ll-vocab-lesson-title-input]').first();
        const title = ($input.val() || '').toString().trim();

        clearTitleTimer($editor);

        if (!title) {
            setTitleStatus($editor, getTitleMessage('empty', 'Enter a category title.'), 'error');
            if ($input.length) {
                $input.trigger('focus');
            }
            return;
        }

        if (!ajaxUrl || !action || !lessonId || !nonce) {
            setTitleStatus($editor, getTitleMessage('error', 'Unable to save this category title right now.'), 'error');
            return;
        }

        setTitleSaving($editor, true);
        setTitleStatus($editor, getTitleMessage('saving', 'Saving...'));

        $.post(ajaxUrl, {
            action: action,
            lesson_id: lessonId,
            category_id: categoryId,
            title: title,
            nonce: nonce
        }).done(function (response) {
            if (!response || response.success !== true || !response.data || typeof response.data !== 'object') {
                setTitleStatus(
                    $editor,
                    readAjaxMessage(response, getTitleMessage('error', 'Unable to save this category title right now.')),
                    'error'
                );
                return;
            }

            syncTitleEditor($editor, response.data);
            setTitleStatus(
                $editor,
                readAjaxMessage(response, getTitleMessage('saved', 'Category title saved.')),
                'success'
            );

            $editor.data('llTitleTimer', window.setTimeout(function () {
                closeTitleEditor($editor, false);
            }, 700));
        }).fail(function (jqXHR) {
            const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            setTitleStatus(
                $editor,
                readAjaxMessage(response, getTitleMessage('error', 'Unable to save this category title right now.')),
                'error'
            );
        }).always(function () {
            setTitleSaving($editor, false);
        });
    }

    $(function () {
        const $printSettings = $('.ll-vocab-lesson-print-settings');
        const $categorySettings = $('.ll-vocab-lesson-category-settings');
        const $shells = $('[data-ll-vocab-lesson-grid-shell]');
        if ($shells.length) {
            $shells.each(function () {
                loadLessonGrid($(this));
            });

            $(document).on('click', '[data-ll-vocab-lesson-grid-retry]', function (event) {
                event.preventDefault();
                const $shell = $(this).closest('[data-ll-vocab-lesson-grid-shell]');
                if (!$shell.length) { return; }
                loadLessonGrid($shell);
            });
        }

        if ($printSettings.length) {
            $printSettings.on('click', '.ll-vocab-lesson-print-trigger', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $wrap = $(this).closest('.ll-vocab-lesson-print-settings');
                const $panel = $wrap.find('.ll-vocab-lesson-print-panel').first();
                const isOpen = $panel.attr('aria-hidden') === 'false';

                closeCategorySettings();
                closePrintSettings($wrap);
                setPrintSettingsOpen($wrap, !isOpen);
            });

            $printSettings.on('click', '.ll-vocab-lesson-print-panel', function (event) {
                event.stopPropagation();
            });

            $(document).on('pointerdown.llVocabLessonPrintSettings', function (event) {
                if ($(event.target).closest('.ll-vocab-lesson-print-settings').length) {
                    return;
                }
                closePrintSettings();
            });

            $(document).on('keydown.llVocabLessonPrintSettings', function (event) {
                if (event.key === 'Escape') {
                    closePrintSettings();
                }
            });

            $(window).on('resize.llVocabLessonPrintSettings orientationchange.llVocabLessonPrintSettings', function () {
                $('.ll-vocab-lesson-print-panel[aria-hidden="false"]').each(function () {
                    clampPanelToViewport($(this));
                });
            });
        }

        if ($categorySettings.length) {
            $categorySettings.on('click', '.ll-vocab-lesson-category-settings-trigger', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $wrap = $(this).closest('.ll-vocab-lesson-category-settings');
                const $panel = $wrap.find('.ll-vocab-lesson-category-settings-panel').first();
                const isOpen = $panel.attr('aria-hidden') === 'false';

                closeCategorySettings($wrap);
                setCategorySettingsOpen($wrap, !isOpen);
            });

            $categorySettings.on('click', '.ll-vocab-lesson-category-settings-panel', function (event) {
                event.stopPropagation();
            });

            $categorySettings.on('click', '[data-ll-category-settings-close]', function (event) {
                event.preventDefault();
                event.stopPropagation();
                closeCategorySettings();
            });

            $categorySettings.on('pointerdown', function (event) {
                if (event.target === this && $(this).hasClass('is-open')) {
                    event.preventDefault();
                    closeCategorySettings();
                }
            });

            $(document).on('pointerdown.llVocabLessonCategorySettings', function (event) {
                if ($(event.target).closest('.ll-vocab-lesson-category-settings').length) {
                    return;
                }
                closeCategorySettings();
            });

            $(document).on('keydown.llVocabLessonCategorySettings', function (event) {
                if (event.key === 'Escape') {
                    closeCategorySettings();
                }
            });

            $categorySettings.each(function () {
                syncCategoryLineupInput($(this));
            });

            $categorySettings.on('change', '.ll-vocab-lesson-category-settings-panel select, .ll-vocab-lesson-category-settings-panel input[type="checkbox"]', function (event) {
                if (!isCategorySettingsAutosaveField(event.target)) {
                    return;
                }

                scheduleCategorySettingsSave($(this).closest('.ll-vocab-lesson-category-settings-panel'));
            });

            $categorySettings.on('click', '[data-ll-category-lineup-move]', function (event) {
                event.preventDefault();
                const $panel = $(this).closest('.ll-vocab-lesson-category-settings-panel');
                moveCategoryLineupItem(
                    $(this).closest('[data-ll-category-lineup-item]'),
                    String($(this).attr('data-ll-category-lineup-move') || '')
                );
                scheduleCategorySettingsSave($panel, 350);
            });

            $categorySettings.on('submit', '.ll-vocab-lesson-category-settings-panel', function (event) {
                event.preventDefault();
                syncCategoryLineupInput($(this));
                saveCategorySettingsNow($(this));
            });
        }

        $(document).on('click', '[data-ll-vocab-lesson-title-trigger]', function (event) {
            event.preventDefault();
            openTitleEditor($(this).closest('[data-ll-vocab-lesson-title-editor]'));
        });

        $(document).on('click', '[data-ll-vocab-lesson-title-cancel]', function (event) {
            event.preventDefault();
            const $editor = $(this).closest('[data-ll-vocab-lesson-title-editor]');
            setTitleStatus($editor, '');
            closeTitleEditor($editor, true);
        });

        $(document).on('submit', '[data-ll-vocab-lesson-title-form]', function (event) {
            event.preventDefault();
            submitTitleEditor($(this).closest('[data-ll-vocab-lesson-title-editor]'));
        });

        $(document).on('keydown', '[data-ll-vocab-lesson-title-input]', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            const $editor = $(this).closest('[data-ll-vocab-lesson-title-editor]');
            setTitleStatus($editor, '');
            closeTitleEditor($editor, true);
        });
    });
})(jQuery);
