(function () {
    'use strict';

    function getConfig() {
        return window.llToolsContentLessonProgress
            && typeof window.llToolsContentLessonProgress === 'object'
            ? window.llToolsContentLessonProgress
            : {};
    }

    function getString(name, fallback) {
        var config = getConfig();
        var strings = config.i18n && typeof config.i18n === 'object' ? config.i18n : {};
        return String(strings[name] || fallback);
    }

    function setStatus(button, state, message) {
        var root = button.closest('.ll-content-lesson-progress');
        var status = root ? root.querySelector('[data-ll-content-lesson-progress-status]') : null;
        if (!status) {
            return;
        }
        status.setAttribute('data-state', state || 'idle');
        status.textContent = String(message || '');
    }

    function applyState(button, completed) {
        var label = button.querySelector('[data-ll-content-lesson-progress-label]');
        var icon = button.querySelector('.ll-content-lesson-progress-button__icon');
        button.classList.toggle('is-completed', completed);
        button.setAttribute('data-completed', completed ? '1' : '0');
        button.setAttribute('aria-pressed', completed ? 'true' : 'false');
        if (label) {
            label.textContent = completed
                ? getString('complete', 'Completed')
                : getString('incomplete', 'Mark complete');
        }
        if (icon) {
            icon.textContent = completed ? '\u2713' : '\u25CB';
        }
    }

    function setRetryState(button, enabled) {
        var label = button.querySelector('[data-ll-content-lesson-progress-label]');
        button.classList.toggle('has-save-error', enabled);
        if (enabled && label) {
            label.textContent = getString('retry', 'Retry');
        }
    }

    function requestTimeoutMs() {
        var configured = parseInt(getConfig().requestTimeoutMs, 10);
        return Math.max(25, Math.min(120000, Number.isFinite(configured) ? configured : 15000));
    }

    function saveState(button) {
        var config = getConfig();
        var lessonId = parseInt(button.getAttribute('data-lesson-id') || '0', 10) || 0;
        var currentState = button.getAttribute('data-completed') === '1';
        var nextState = !currentState;
        var body;
        var controller;
        var timeoutId;
        var timedOut = false;
        var attempt;

        if (!lessonId || !config.ajaxUrl || !config.nonce || button.disabled) {
            return;
        }

        body = new URLSearchParams();
        body.set('action', String(config.action || 'll_tools_content_lesson_completion'));
        body.set('nonce', String(config.nonce));
        body.set('lesson_id', String(lessonId));
        body.set('completed', nextState ? '1' : '0');

        attempt = (parseInt(button.__llContentLessonProgressAttempt, 10) || 0) + 1;
        button.__llContentLessonProgressAttempt = attempt;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        setRetryState(button, false);
        applyState(button, currentState);
        setStatus(button, 'saving', getString('saving', 'Saving...'));

        var request = fetch(String(config.ajaxUrl), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString(),
                signal: controller ? controller.signal : undefined
            });
        var deadline = new Promise(function (resolve, reject) {
            timeoutId = window.setTimeout(function () {
                timedOut = true;
                if (controller) {
                    controller.abort();
                }
                reject(new Error(getString('timeout', 'Saving took too long. Please retry.')));
            }, requestTimeoutMs());
        });

        Promise.race([request, deadline]).then(function (response) {
            return response.json();
        }).then(function (response) {
            if (button.__llContentLessonProgressAttempt !== attempt) {
                return;
            }
            if (!response || response.success !== true || !response.data) {
                var errorMessage = response && response.data && response.data.message
                    ? String(response.data.message)
                    : getString('error', 'Lesson progress could not be saved.');
                throw new Error(errorMessage);
            }
            applyState(button, response.data.completed === true);
            setRetryState(button, false);
            setStatus(button, 'saved', getString('saved', 'Progress saved.'));
        }).catch(function (error) {
            if (button.__llContentLessonProgressAttempt !== attempt) {
                return;
            }
            setRetryState(button, true);
            setStatus(
                button,
                timedOut ? 'timeout' : 'error',
                timedOut
                    ? getString('timeout', 'Saving took too long. Please retry.')
                    : error && error.message
                    ? String(error.message)
                    : getString('error', 'Lesson progress could not be saved.')
            );
        }).finally(function () {
            window.clearTimeout(timeoutId);
            if (button.__llContentLessonProgressAttempt !== attempt) {
                return;
            }
            button.disabled = false;
            button.removeAttribute('aria-busy');
        });
    }

    function init() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-ll-content-lesson-progress]'),
            function (button) {
                if (button.__llContentLessonProgressBound) {
                    return;
                }
                button.__llContentLessonProgressBound = true;
                button.addEventListener('click', function () {
                    saveState(button);
                });
            }
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
