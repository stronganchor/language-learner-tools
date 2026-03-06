(function ($) {
    'use strict';

    const cfg = window.llToolsVocabLessonData || {};
    const ajaxUrl = (cfg.ajaxUrl || '').toString();
    const action = (cfg.action || 'll_tools_get_vocab_lesson_grid').toString();
    const i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};

    function getMessage(key, fallback) {
        const value = i18n[key];
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
                text: getMessage('retry', 'Retry')
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

        $(document).trigger('lltools:word-grid-rendered', [{ scope: $(targetGrid) }]);
        return true;
    }

    function loadLessonGrid($shell) {
        if (!$shell.length || $shell.data('llGridLoading')) {
            return;
        }

        const lessonId = parseInt($shell.attr('data-lesson-id'), 10) || 0;
        const nonce = ($shell.attr('data-nonce') || '').toString();
        if (!ajaxUrl || !action || !lessonId || !nonce) {
            showFeedback($shell, getMessage('error', 'Unable to load this lesson right now.'), true);
            setLoading($shell, false);
            return;
        }

        hideFeedback($shell);
        setLoading($shell, true);
        setStatus($shell, getMessage('loading', 'Loading lesson words...'));

        $.post(ajaxUrl, {
            action: action,
            lesson_id: lessonId,
            nonce: nonce
        }).done(function (response) {
            if (!response || response.success !== true || !response.data || typeof response.data.html !== 'string') {
                showFeedback(
                    $shell,
                    readAjaxMessage(response, getMessage('error', 'Unable to load this lesson right now.')),
                    true
                );
                return;
            }

            renderGridMarkup($shell, response.data.html);
            hideFeedback($shell);
            setStatus($shell, getMessage('loaded', 'Lesson words loaded.'));
        }).fail(function (jqXHR) {
            const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            showFeedback(
                $shell,
                readAjaxMessage(response, getMessage('error', 'Unable to load this lesson right now.')),
                true
            );
        }).always(function () {
            setLoading($shell, false);
        });
    }

    $(function () {
        const $shells = $('[data-ll-vocab-lesson-grid-shell]');
        if (!$shells.length) { return; }

        $shells.each(function () {
            loadLessonGrid($(this));
        });

        $(document).on('click', '[data-ll-vocab-lesson-grid-retry]', function (event) {
            event.preventDefault();
            const $shell = $(this).closest('[data-ll-vocab-lesson-grid-shell]');
            if (!$shell.length) { return; }
            loadLessonGrid($shell);
        });
    });
})(jQuery);
